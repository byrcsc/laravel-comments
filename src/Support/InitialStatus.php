<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

use ByRcsc\LaravelComments\Contracts\DecidesCommentStatus;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * Which status a new comment starts in. This is policy rather than schema, and
 * it changes for its own reasons - a new resolution source, a new default -
 * which is why it sits beside the comment model instead of inside it.
 *
 * The order is fixed and short: the commentable's own hook, then the
 * configured default for whoever wrote the comment. Nothing consults the
 * commentator, the parent, or the thread; a hook that wants to is handed the
 * whole comment and can.
 */
final class InitialStatus
{
    public static function for(Comment $comment): CommentStatus
    {
        return self::fromHook($comment) ?? self::fromConfig($comment);
    }

    /**
     * The commentable is only loaded when its class implements the hook, so
     * the ordinary write path stays at the queries it already ran.
     */
    private static function fromHook(Comment $comment): ?CommentStatus
    {
        // Read through `getAttribute` rather than the typed property: a comment
        // on its way to a missing commentable has nothing here, and the
        // database's own error is the better one to surface.
        /** @var mixed $type */
        $type = $comment->getAttribute('commentable_type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        if (! is_a(Model::getActualClassNameForMorph($type), DecidesCommentStatus::class, true)) {
            return null;
        }

        $commentable = $comment->commentable;

        return $commentable instanceof DecidesCommentStatus
            ? $commentable->initialCommentStatus($comment)
            : null;
    }

    /**
     * Guests are read from their own key, so raising the default status for
     * authenticated commentators never quietly publishes anonymous content.
     */
    private static function fromConfig(Comment $comment): CommentStatus
    {
        $key = $comment->commentator_type === null
            ? 'comments.guest_status'
            : 'comments.default_status';

        return CommentStatus::fromConfig($key, config($key));
    }
}
