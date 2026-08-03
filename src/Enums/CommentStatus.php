<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Enums;

use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;

/**
 * Package state, not visibility. The package records what a comment is -
 * pending, approved, rejected, or spam - and fires events on transitions;
 * deciding what a visitor sees stays in the application's queries, where
 * `approved()` is the scope to reach for.
 *
 * There is no ordering here and no workflow: a comment sits in exactly one
 * status and any transition method may move it to any other. Staged sign-off
 * is byrcsc/laravel-approval's job, not this enum's.
 */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    /**
     * Every status as a string, for the validation rule a form request needs
     * and for error messages that should name what was allowed.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The one place a configured status becomes a status. Boot-time validation
     * and the write path both come through here, so a typo cannot be caught in
     * one and quietly tolerated in the other.
     */
    public static function fromConfig(string $key, mixed $configured): self
    {
        $status = is_string($configured) ? self::tryFrom($configured) : null;

        return $status ?? throw InvalidConfigurationException::invalidStatus($key, $configured);
    }
}
