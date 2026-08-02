<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Models;

use ByRcsc\LaravelComments\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One reactor's one reaction to one comment. The database enforces that
 * uniqueness, so a double tap on a slow connection cannot inflate a count.
 *
 * There is no guest reactor and no nullable identity here: deduplication needs
 * something to deduplicate by, and a name and an email are not that. Reactions
 * belong to comments and to nothing else - a general reactable system is
 * deliberately outside this package.
 *
 * @property int $id
 * @property int $comment_id
 * @property string $reactor_type
 * @property int|string $reactor_id
 * @property string $reaction
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Comment|null $comment
 * @property-read Model|null $reactor
 */
final class CommentReaction extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('comment_reactions');
    }

    /**
     * Read through the comment's own soft-delete scope, so this is null once
     * the comment is a tombstone. The reaction row stays either way, and
     * `withTrashed()` brings the comment back into view.
     *
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reactor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this row belongs to the given reactor. The key is compared
     * loosely because one read back from the database is a string where the
     * model in hand holds an int.
     */
    public function isBy(Model $reactor): bool
    {
        return $this->reactor_type === $reactor->getMorphClass()
            && $this->reactor_id == $reactor->getKey();
    }
}
