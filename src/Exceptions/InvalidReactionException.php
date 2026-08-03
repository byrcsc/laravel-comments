<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

/**
 * The reaction string itself is the problem: outside the configured allowlist,
 * blank, or longer than the column holds. Enforced at the engine boundary
 * rather than trusted from the request, because the allowlist is the whole
 * point of having one.
 */
final class InvalidReactionException extends CommentsException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function notAllowed(string $reaction, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            "The reaction \"{$reaction}\" is not in comments.allowed_reactions: {$list}. Add it there, or set the list to null to accept any reaction."
        );
    }

    public static function blank(): self
    {
        return new self(
            'A reaction cannot be blank. Pass the emoji or key your interface sends.'
        );
    }

    public static function tooLong(int $length, int $max): self
    {
        return new self(
            "The reaction is {$length} characters; the column holds {$max}. A reaction is a key or an emoji, not a body."
        );
    }
}
