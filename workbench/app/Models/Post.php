<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelComments\Concerns\HasComments;
use ByRcsc\LaravelComments\Contracts\DecidesCommentStatus;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\PostFactory;

/**
 * The demo app's commentable: one trait, and - because this app has an opinion
 * about links - the optional status hook. The trait alone is the whole
 * integration; the interface is there when a model wants the last word on how
 * its comments arrive.
 */
final class Post extends Model implements DecidesCommentStatus
{
    use HasComments;

    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Anything with a link waits for a human, whoever wrote it. Returning null
     * for everything else leaves the configured defaults in charge, which is
     * how guests still land pending without this method repeating that rule.
     */
    public function initialCommentStatus(Comment $comment): ?CommentStatus
    {
        return str_contains($comment->body, 'http')
            ? CommentStatus::Pending
            : null;
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
