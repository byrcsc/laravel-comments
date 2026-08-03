<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use ByRcsc\LaravelComments\Models\CommentRevision;

/**
 * A revision records what a comment said at a moment that has passed, so
 * there is nothing about it left to correct. Delete the row if it should not
 * be kept; do not rewrite what it says.
 *
 * This is a convention the package enforces on its own model, not a
 * guarantee about the table. Raw SQL and query-builder updates go straight
 * past it, and the README says so.
 */
final class RevisionIsAppendOnlyException extends CommentsException
{
    public static function for(CommentRevision $revision): self
    {
        return new self(
            "Comment revision {$revision->id} cannot be updated: revisions are append-only. Delete it if it should not be kept."
        );
    }
}
