<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Console;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Support\CommentCounts;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Repairs denormalized comment counts that drifted.
 *
 * Counts are maintained through Eloquent's model events, so anything that goes
 * around them - the query builder, an upsert, raw SQL, a restored database
 * dump - leaves the column behind. This is the backstop for that, and the only
 * thing in the package that reads a total rather than stepping one.
 *
 * It recomputes from one grouped query per model type and writes only the rows
 * that disagree, so running it against a correct table is cheap and says so.
 */
final class RecountCommentsCommand extends Command
{
    protected $signature = 'comments:recount
        {--model= : Only this commentable, by class name or morph alias}
        {--id= : Only this record, which needs --model too}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Recompute denormalized comment counts on commentable models that keep one';

    public function handle(): int
    {
        $model = $this->stringOption('model');
        $id = $this->stringOption('id');

        if ($id !== null && $model === null) {
            $this->components->error('--id needs --model: a key on its own does not say which table to look in.');

            return self::FAILURE;
        }

        if ($model !== null) {
            $commentable = $this->resolve($model);

            if ($commentable === null) {
                return self::FAILURE;
            }

            $column = CommentCounts::columnFor($commentable);

            if ($column === null) {
                $class = $commentable::class;

                $this->components->error("{$class} keeps no comments count. Return a column name from commentsCountColumn() to opt it in.");

                return self::FAILURE;
            }

            return $this->report($this->recount($commentable, $column, $id));
        }

        $repaired = 0;

        foreach ($this->typesWithComments() as $type) {
            // A type that merely turned up in the comments table is not
            // claiming a column, and one the morph map names may be a model
            // that has nothing to do with commenting. Both are skipped
            // quietly; only an explicit --model is worth an error.
            $commentable = CommentCounts::instanceFor($type);
            $column = $commentable === null ? null : CommentCounts::columnFor($commentable);

            if ($commentable === null || $column === null) {
                continue;
            }

            $repaired += $this->recount($commentable, $column, null);
        }

        return $this->report($repaired);
    }

    /**
     * Returns how many rows disagreed with the grouped query.
     */
    private function recount(Model $commentable, string $column, ?string $id): int
    {
        $counts = $this->countsFor($commentable->getMorphClass(), $id);

        $query = $commentable->newQueryWithoutScopes();

        if ($id !== null) {
            $query->whereKey($id);
        }

        $repaired = 0;
        $dryRun = $this->option('dry-run') === true;

        $query->select([$commentable->getKeyName(), $column])
            ->orderBy($commentable->getKeyName())
            ->chunk(500, function (mixed $rows) use (&$repaired, $column, $counts, $commentable, $dryRun): void {
                foreach ($rows as $row) {
                    /** @var Model $row */
                    $key = self::arrayKeyFor($row->getKey());
                    $expected = $counts[$key] ?? 0;
                    /** @var mixed $stored */
                    $stored = $row->getAttribute($column);

                    if (is_numeric($stored) && (int) $stored === $expected) {
                        continue;
                    }

                    $repaired++;

                    $this->components->twoColumnDetail(
                        $commentable->getMorphClass()." #{$key}",
                        (is_numeric($stored) ? (string) (int) $stored : 'null')." → {$expected}",
                    );

                    if (! $dryRun) {
                        // The total is already in hand from the grouped query;
                        // asking the database again per row would be an N+1
                        // built into the repair tool.
                        CommentCounts::store($row, $expected);
                    }
                }
            });

        return $repaired;
    }

    private function report(int $repaired): int
    {
        $this->components->info(match (true) {
            $repaired === 0 => 'Every count was already correct.',
            $this->option('dry-run') === true => "{$repaired} count(s) would change.",
            default => "Repaired {$repaired} count(s).",
        });

        return self::SUCCESS;
    }

    /**
     * The countable comments per record, in one grouped query. Keys are cast
     * to strings so integer and uuid keys index the same way.
     *
     * @return array<string, int>
     */
    private function countsFor(string $type, ?string $id): array
    {
        $query = CommentCounts::countable()
            ->where('commentable_type', $type)
            ->groupBy('commentable_id')
            ->select('commentable_id')
            ->selectRaw('count(*) as aggregate');

        if ($id !== null) {
            $query->where('commentable_id', $id);
        }

        $counts = [];

        foreach ($query->get() as $row) {
            /** @var mixed $aggregate */
            $aggregate = $row->getAttribute('aggregate');

            $counts[self::arrayKeyFor($row->getAttribute('commentable_id'))] = is_numeric($aggregate)
                ? (int) $aggregate
                : 0;
        }

        return $counts;
    }

    /**
     * Which types a sweep with no filter visits: everything the comments table
     * mentions, tombstones included, plus everything the application
     * registered in its morph map.
     *
     * Tombstones matter because a type whose comments are all soft deleted
     * still owes its records a zero, and the ordinary scope would hide it. The
     * morph map covers the case the table cannot describe at all - every
     * comment hard deleted, no row left to name the type. An application
     * without a morph map has `--model` for that; nothing here can guess at a
     * class it has never been shown.
     *
     * @return list<string>
     */
    private function typesWithComments(): array
    {
        $types = [];

        foreach (Comment::withTrashed()->distinct()->pluck('commentable_type') as $type) {
            if (is_string($type) && $type !== '') {
                $types[$type] = $type;
            }
        }

        foreach (array_keys(Relation::morphMap()) as $alias) {
            $types[(string) $alias] = (string) $alias;
        }

        return array_values($types);
    }

    private function resolve(string $type): ?Model
    {
        $commentable = CommentCounts::instanceFor($type);

        if ($commentable === null) {
            $this->components->error("Cannot resolve \"{$type}\" to a model. Pass a class name, or a morph alias your application has registered.");
        }

        return $commentable;
    }

    /**
     * A model key as an array key. Integer and string keys index the same way,
     * so an application on uuids gets the same lookup an integer one does.
     */
    private static function arrayKeyFor(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }

    private function stringOption(string $name): ?string
    {
        /** @var mixed $value */
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
