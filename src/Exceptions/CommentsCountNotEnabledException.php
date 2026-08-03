<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A count was asked for on a model that keeps none. Counting is opt-in: the
 * column lives on the application's table, so the package will neither invent
 * a name for it nor pretend the number exists.
 */
final class CommentsCountNotEnabledException extends CommentsException
{
    public static function for(Model $commentable): self
    {
        $class = $commentable::class;

        return new self(
            "{$class} keeps no denormalized comments count. Add the column to its table and return its name from commentsCountColumn(), or read the count with \$model->comments()->approved()->count()."
        );
    }
}
