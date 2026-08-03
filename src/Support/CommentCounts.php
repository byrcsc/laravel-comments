<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

use ByRcsc\LaravelComments\Concerns\HasComments;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * The denormalized comments count on a commentable's own table.
 *
 * The column belongs to the application - it writes the migration and names
 * the column by overriding `commentsCountColumn()`. Opting in is that
 * override and nothing else: there is no schema sniffing here, because a
 * package that guessed at a column would be wrong exactly when it mattered.
 *
 * Countable means approved and not soft deleted, which is the number a visitor
 * would arrive at. Every step in or out of that set moves the column by that
 * many, atomically and in the database, so two comments approved in the same
 * request both land. Nothing on the maintenance path reads a value and writes
 * it back; only `recount()`, the repair tool, computes a total.
 *
 * Writes go through the query builder rather than Eloquent: bumping the
 * commentable's `updated_at` because somebody commented would be the package
 * making a decision about a table it does not own.
 */
final class CommentCounts
{
    public static function increment(Comment $comment, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }

        self::adjust($comment, function (Builder $target, string $column) use ($by): void {
            $target->increment($column, $by);
        });
    }

    /**
     * Clamped by the where clause rather than in PHP, so it stays one
     * statement: a count that has already drifted too low should not go
     * negative while `comments:recount` is still waiting to be run.
     */
    public static function decrement(Comment $comment, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }

        self::adjust($comment, function (Builder $target, string $column) use ($by): void {
            $target->where($column, '>=', $by)->decrement($column, $by);
        });
    }

    /**
     * Recompute one commentable's count from the comments table and write it,
     * returning the value now stored - or null when the model keeps none.
     *
     * This is the repair path, and the only one that reads a total. The
     * maintenance path never comes through here.
     */
    public static function recount(Model $commentable): ?int
    {
        $column = self::columnFor($commentable);

        if ($column === null) {
            return null;
        }

        $count = self::countable()
            ->where('commentable_type', $commentable->getMorphClass())
            ->where('commentable_id', $commentable->getKey())
            ->count();

        self::store($commentable, $count);

        return $count;
    }

    /**
     * Write a count somebody else already computed - the bulk repair path,
     * which reads every record's total in one grouped query and would only be
     * asking again per row if it came through `recount()`.
     *
     * The in-memory attribute is brought along so the model in hand does not
     * disagree with its own row.
     */
    public static function store(Model $commentable, int $count): void
    {
        $column = self::columnFor($commentable);

        if ($column === null) {
            return;
        }

        $commentable->newQueryWithoutScopes()
            ->getQuery()
            ->where($commentable->getKeyName(), $commentable->getKey())
            ->update([$column => $count]);

        $commentable->setAttribute($column, $count);
        $commentable->syncOriginalAttribute($column);
    }

    /**
     * The countable set, as a query anything can narrow: approved, and not
     * soft deleted - the comment model's own global scope sees to the second
     * half. One definition, so the maintenance path and the repair path cannot
     * come to disagree about what the column means.
     *
     * @return EloquentBuilder<Comment>
     */
    public static function countable(): EloquentBuilder
    {
        return Comment::query()->approved();
    }

    /**
     * Whether this comment is in the set the column counts. Read from the
     * model in hand rather than the table, because the caller is usually
     * holding it mid-transition.
     */
    public static function isCountable(Comment $comment): bool
    {
        return $comment->status === CommentStatus::Approved && ! $comment->trashed();
    }

    /**
     * The column a commentable keeps its count in, or null when the model
     * never opted in - which is every model by default.
     *
     * The trait is checked as well as the method: a model that happens to have
     * a method by that name but no `comments()` relation has not opted into
     * anything, and would break the moment a repair asked it for a total.
     */
    public static function columnFor(Model $commentable): ?string
    {
        if (! in_array(HasComments::class, class_uses_recursive($commentable), true)) {
            return null;
        }

        if (! method_exists($commentable, 'commentsCountColumn')) {
            return null;
        }

        $column = $commentable->commentsCountColumn();

        return is_string($column) && trim($column) !== '' ? $column : null;
    }

    /**
     * An unsaved instance of a morph type's class, which is all a count update
     * needs: the table, the key name, and the opt-in. Loading the row would be
     * a query per comment written to learn what the class already knows.
     */
    public static function instanceFor(mixed $type): ?Model
    {
        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = Model::getActualClassNameForMorph($type);

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return new $class;
    }

    /**
     * @param  callable(Builder, string): void  $write
     */
    private static function adjust(Comment $comment, callable $write): void
    {
        $commentable = self::instanceFor($comment->getAttribute('commentable_type'));

        if ($commentable === null) {
            return;
        }

        $column = self::columnFor($commentable);

        if ($column === null) {
            return;
        }

        $write(
            $commentable->newQueryWithoutScopes()
                ->getQuery()
                ->where($commentable->getKeyName(), $comment->getAttribute('commentable_id')),
            $column,
        );
    }
}
