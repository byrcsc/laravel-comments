<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Contracts;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;

/**
 * Implement this on a commentable model to decide what status its new comments
 * start in. The hook beats both configured defaults, so a rule like "hold the
 * first comment from anyone new" lives in one method on the model that owns
 * the discussion:
 *
 *     public function initialCommentStatus(Comment $comment): ?CommentStatus
 *     {
 *         return $comment->commentator?->hasCommentedBefore()
 *             ? CommentStatus::Approved
 *             : CommentStatus::Pending;
 *     }
 *
 * The comment is unsaved and fully populated: its commentator, guest identity,
 * parent, and body are all readable, and nothing it says has been trusted yet.
 *
 * Returning null hands the decision back to `comments.default_status` and
 * `comments.guest_status`, so a hook only has to answer the cases it cares
 * about.
 *
 * The hook is not consulted when the caller named a status outright - a
 * factory, a seeder, or an import restoring an export already knows what it
 * meant. Resolution is for the writes that said nothing.
 */
interface DecidesCommentStatus
{
    public function initialCommentStatus(Comment $comment): ?CommentStatus;
}
