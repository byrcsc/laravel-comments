<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A comment belongs to an already-persisted record: an unsaved model has no
 * key for the polymorphic columns to point at, and failing here beats the
 * database error that would otherwise surface far from the cause.
 */
final class CommentableNotPersistedException extends CommentsException
{
    public static function for(Model $commentable): self
    {
        $class = $commentable::class;

        return new self(
            "Cannot comment on an unsaved {$class}. Persist the model before commenting on it."
        );
    }
}
