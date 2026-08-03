<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Models;

use ByRcsc\LaravelComments\Database\Factories\CommentRevisionFactory;
use ByRcsc\LaravelComments\Exceptions\RevisionIsAppendOnlyException;
use ByRcsc\LaravelComments\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * What a comment said before one edit, and who made it. One row per body
 * change, written by the comment itself; nothing else creates these.
 *
 * Append-only by convention: the model refuses updates, so history cannot be
 * quietly rewritten through the package. That is a convention and not a proof.
 * There is no hash chain here and no tamper evidence - the rows are ordinary
 * rows, and anything with database access can edit them. An application that
 * needs a verifiable history wants byrcsc/laravel-approval instead.
 *
 * @property int $id
 * @property int $comment_id
 * @property string|null $editor_type
 * @property int|string|null $editor_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read Comment|null $comment
 * @property-read Model|null $editor
 */
final class CommentRevision extends Model
{
    /** @use HasFactory<CommentRevisionFactory> */
    use HasFactory;

    /**
     * A row that can never be updated has no honest `updated_at` to keep, so
     * there is no column for one.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function newFactory(): CommentRevisionFactory
    {
        return CommentRevisionFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('comment_revisions');
    }

    /**
     * The refusal is a model event rather than a check inside a method, so
     * every path through Eloquent hits it: `save()`, `update()`, `fill()` and
     * save, a mass update on a loaded model.
     */
    protected static function booted(): void
    {
        self::updating(function (self $revision): void {
            throw RevisionIsAppendOnlyException::for($revision);
        });
    }

    /**
     * Read through the comment's own soft-delete scope, so this is null once
     * the comment is a tombstone. The revision stays either way, which is what
     * lets a moderator read what a removed comment used to say.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * Who made the edit, or null when nobody was named.
     *
     * @return MorphTo<Model, $this>
     */
    public function editor(): MorphTo
    {
        return $this->morphTo();
    }
}
