<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

/**
 * Enforced when the reply is created, never retroactively: changing
 * `comments.max_depth` reshapes no existing thread, it only decides what may
 * be created from now on.
 */
final class ThreadTooDeepException extends CommentsException
{
    public static function atDepth(int $depth, int $max): self
    {
        return new self(
            "Replying here would create a comment at depth {$depth}; comments.max_depth allows {$max}. Top-level comments sit at depth 0."
        );
    }
}
