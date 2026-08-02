<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

/**
 * Thrown instead of truncating: an oversized body is the application's problem
 * to shorten, and silently storing less than the author wrote is worse than
 * refusing.
 */
final class BodyTooLongException extends CommentsException
{
    public static function forLength(int $length, int $max): self
    {
        return new self(
            "The comment body is {$length} characters; comments.max_length allows {$max}."
        );
    }
}
