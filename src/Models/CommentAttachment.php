<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Models;

use ByRcsc\LaravelComments\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Metadata about one file your application stored, recorded against a comment.
 *
 * That is the whole of it: a disk name, a path on that disk, and what the
 * application said the file is called, is, and weighs. The package never opens
 * the file, never checks that it exists, and never deletes it. Serving it,
 * authorizing the download, and cleaning it up are the application's, which is
 * why the storage trust boundary stays on that side of the line.
 *
 * @property int $id
 * @property int $comment_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string|null $mime_type
 * @property int|null $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Comment|null $comment
 */
final class CommentAttachment extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('comment_attachments');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'comment_id' => 'integer',
            'size' => 'integer',
        ];
    }

    /**
     * Read through the comment's own soft-delete scope, so this is null once
     * the comment is a tombstone. The attachment row stays either way, and
     * `withTrashed()` brings the comment back into view.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
