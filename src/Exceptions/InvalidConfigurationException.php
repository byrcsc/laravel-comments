<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use ByRcsc\LaravelComments\Enums\CommentStatus;

final class InvalidConfigurationException extends CommentsException
{
    /**
     * @param  list<string>  $keys
     */
    public static function missingTableNames(array $keys): self
    {
        $list = implode(', ', $keys);

        return new self(
            "The comments.table_names config is missing entries for: {$list}. Every table needs a name; restore the missing keys in config/comments.php."
        );
    }

    public static function blankTableName(string $key): self
    {
        return new self(
            "The comments.table_names.{$key} config value must be a non-empty string."
        );
    }

    public static function invalidActorKeyType(mixed $type): self
    {
        $given = is_string($type) ? "\"{$type}\"" : get_debug_type($type);

        return new self(
            "The comments.actor_key_type config value must be one of: int, uuid, ulid, string. Got {$given}."
        );
    }

    /**
     * Names the key rather than the setting, because the two status keys are
     * easy to mix up and the message is the only thing that disambiguates
     * them.
     */
    public static function invalidStatus(string $key, mixed $status): self
    {
        $allowed = implode(', ', CommentStatus::values());
        $given = is_string($status) ? "\"{$status}\"" : get_debug_type($status);

        return new self(
            "The {$key} config value must be one of: {$allowed}. Got {$given}."
        );
    }

    public static function invalidMaxDepth(mixed $depth): self
    {
        $given = is_scalar($depth) ? var_export($depth, true) : get_debug_type($depth);

        return new self(
            "The comments.max_depth config value must be a non-negative integer, or null for unlimited depth. Got {$given}."
        );
    }

    public static function invalidMaxLength(mixed $length): self
    {
        $given = is_scalar($length) ? var_export($length, true) : get_debug_type($length);

        return new self(
            "The comments.max_length config value must be a positive integer, or null for no limit. Got {$given}."
        );
    }
}
