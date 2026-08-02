<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

/**
 * The single source of truth for what this package's tables are called: the
 * models resolve through here, and boot-time validation checks the published
 * config against the same map.
 */
final class TableNames
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'comments' => 'comments',
        'comment_reactions' => 'comment_reactions',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * The fallback covers a published config written against an older version
     * of the package, with no entry for a table added since.
     */
    public static function for(string $key): string
    {
        /** @var mixed $configured */
        $configured = function_exists('config')
            ? config("comments.table_names.{$key}")
            : null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : (self::DEFAULTS[$key] ?? $key);
    }
}
