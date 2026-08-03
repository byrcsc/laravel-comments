<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelComments\Exceptions\InvalidReactionException;

/**
 * Which reaction strings a comment accepts. The allowlist is enforced at the
 * engine boundary rather than in a form request, so a reaction that reached
 * the package another way - a console command, a queued import, a second
 * interface - is held to the same set.
 *
 * Boot-time validation and the write path both come through here, so a
 * malformed list cannot be caught in one and quietly tolerated in the other.
 */
final class AllowedReactions
{
    /**
     * What the `reaction` column holds; change this and the column width in
     * the reactions migration together. Enforced even with the allowlist
     * disabled: a truncated reaction would silently become a different one.
     */
    public const MAX_LENGTH = 64;

    /**
     * Validate a configured list without reading config, so boot-time checks
     * and the write path agree on what a valid list is.
     *
     * @return list<string>|null
     */
    public static function read(mixed $configured): ?array
    {
        if ($configured === null) {
            return null;
        }

        if (! is_array($configured)) {
            throw InvalidConfigurationException::invalidAllowedReactions($configured);
        }

        $allowed = [];

        foreach ($configured as $reaction) {
            if (! is_string($reaction) || trim($reaction) === '') {
                throw InvalidConfigurationException::invalidAllowedReactions($configured);
            }

            $allowed[] = $reaction;
        }

        return $allowed;
    }

    public static function assert(string $reaction): void
    {
        if (trim($reaction) === '') {
            throw InvalidReactionException::blank();
        }

        $length = mb_strlen($reaction);

        if ($length > self::MAX_LENGTH) {
            throw InvalidReactionException::tooLong($length, self::MAX_LENGTH);
        }

        $allowed = self::read(config('comments.allowed_reactions'));

        // Compared as given: two spellings of the same emoji are two
        // reactions, here and in the unique index alike.
        if ($allowed !== null && ! in_array($reaction, $allowed, true)) {
            throw InvalidReactionException::notAllowed($reaction, $allowed);
        }
    }
}
