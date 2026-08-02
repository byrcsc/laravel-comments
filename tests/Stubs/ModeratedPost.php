<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use ByRcsc\LaravelComments\Concerns\HasComments;
use ByRcsc\LaravelComments\Contracts\DecidesCommentStatus;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * A commentable with an opinion about its own comments. It shares the `posts`
 * table with {@see Post}: the difference under test is the hook, not the
 * schema.
 */
final class ModeratedPost extends Model implements DecidesCommentStatus
{
    use HasComments;

    protected $table = 'posts';

    protected $guarded = [];

    /**
     * The shape of a real rule: link spam is caught outright, people we know
     * are trusted, and anyone else is left to the configured defaults.
     */
    public function initialCommentStatus(Comment $comment): ?CommentStatus
    {
        return match (true) {
            str_contains($comment->body, 'http') => CommentStatus::Spam,
            $comment->commentator_type !== null => CommentStatus::Approved,
            default => null,
        };
    }
}
