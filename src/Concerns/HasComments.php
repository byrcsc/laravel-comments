<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Concerns;

use ByRcsc\LaravelComments\Comments;
use ByRcsc\LaravelComments\Exceptions\CommentableNotPersistedException;
use ByRcsc\LaravelComments\Exceptions\CommentsCountNotEnabledException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Support\CommentCounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Makes a model commentable. Adding the trait is the whole integration: the
 * model gains a `comments()` relation and the two write methods, and needs no
 * further wiring.
 *
 * @phpstan-require-extends Model
 */
trait HasComments
{
    /**
     * Every comment on this record, whatever its status or thread position.
     * Combine with the scopes to narrow: `topLevel()` for thread starters,
     * `with('replies')` to bring each one's replies along.
     *
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * The column on this model's own table that holds a denormalized count of
     * its approved, non-deleted comments, or null to keep no count - which is
     * the default, and stays the default until you say otherwise.
     *
     * Opting in is this override plus the migration that adds the column; the
     * package writes neither, because the table is yours:
     *
     *     $table->unsignedInteger('comments_count')->default(0);
     *
     *     public function commentsCountColumn(): ?string
     *     {
     *         return 'comments_count';
     *     }
     *
     * From there the count is maintained through the package's own events, in
     * atomic database increments. Writes that go around Eloquent - the query
     * builder, raw SQL, an upsert - are not intercepted, and `comments:recount`
     * is the backstop for the drift they cause.
     */
    public function commentsCountColumn(): ?string
    {
        return null;
    }

    /**
     * Recompute this record's comment count from the comments table and write
     * it, returning the value now stored.
     *
     * This is the single-record repair `comments:recount` runs in bulk. It
     * writes through the query builder, so no timestamp moves and no model
     * event fires; the in-memory attribute is brought along so the model you
     * are holding does not disagree with the row.
     */
    public function recountComments(): int
    {
        return CommentCounts::recount($this) ?? throw CommentsCountNotEnabledException::for($this);
    }

    /**
     * Write a comment as a commentator model - a user, an admin, a bot, any
     * Eloquent model.
     */
    public function comment(string $body, Model $by): Comment
    {
        return $this->writeComment([
            'commentator_type' => $by->getMorphClass(),
            'commentator_id' => $by->getKey(),
            'body' => $body,
        ]);
    }

    /**
     * Write a comment as a guest. The name and email are stored as given and
     * treated as untrusted input everywhere; nothing is verified and nothing
     * is mailed.
     */
    public function commentAsGuest(string $body, string $name, string $email): Comment
    {
        return $this->writeComment([
            'guest_name' => $name,
            'guest_email' => $email,
            'body' => $body,
        ]);
    }

    /**
     * The comment is handed the commentable it already has rather than left to
     * load one: the initial-status hook lives on this model, and re-reading
     * the row we are standing on to ask it a question would be a query per
     * comment written.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeComment(array $attributes): Comment
    {
        if (! $this->exists) {
            throw CommentableNotPersistedException::for($this);
        }

        $comment = $this->comments()->make($attributes);
        $comment->setRelation('commentable', $this);

        // Under `Comments::fake()` the comment is recorded rather than saved,
        // so an application's own tests can assert what it asked for without a
        // table to clean up. Nothing else in the package knows the difference.
        $fake = Comments::faked();

        if ($fake !== null) {
            return $fake->recordComment($comment);
        }

        $comment->save();

        return $comment;
    }
}
