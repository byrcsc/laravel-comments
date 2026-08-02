<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use ByRcsc\LaravelComments\Models\Comment;

/**
 * A soft-deleted comment is a tombstone: it keeps the history it already had
 * so moderators can still read it, and it takes nothing new. Restore it first
 * if the discussion is meant to continue.
 */
final class CommentTrashedException extends CommentsException
{
    /**
     * Covers removing a reaction as well as adding one. A tombstone that can
     * be quietly emptied is no longer the history it was kept for.
     */
    public static function cannotChangeReactions(Comment $comment): self
    {
        return new self(
            "Cannot add or remove reactions on comment {$comment->id}: it is soft deleted, and a tombstone keeps the reactions it already had. Restore it first."
        );
    }

    public static function cannotEdit(Comment $comment): self
    {
        return new self(
            "Cannot edit the body of comment {$comment->id}: it is soft deleted, and a tombstone that can be rewritten is not history. Restore it first."
        );
    }
}
