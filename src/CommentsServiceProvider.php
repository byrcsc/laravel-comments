<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelComments\Support\AllowedReactions;
use ByRcsc\LaravelComments\Support\AttachmentDefaults;
use ByRcsc\LaravelComments\Support\TableNames;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CommentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-comments')
            ->hasConfigFile('comments')
            ->hasTranslations()
            ->hasViews()
            ->hasMigrations(
                'create_comments_table',
                'create_comment_reactions_table',
                'create_comment_revisions_table',
                'create_comment_attachments_table',
            );
    }

    /**
     * Config mistakes fail at boot, not on the first comment: a blank table
     * name or a typoed key type would otherwise surface as a confusing query
     * error long after the config edit that caused it.
     */
    public function packageBooted(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->app->make('config')->get('comments');

        $tableNames = $config['table_names'] ?? [];
        $this->validateTableNames(is_array($tableNames) ? $tableNames : []);

        $this->validateActorKeyType($config['actor_key_type'] ?? null);
        $this->validateMaxDepth($config['max_depth'] ?? null);
        $this->validateMaxLength($config['max_length'] ?? null);

        // There is no null here on purpose: every comment lands in some status,
        // so "unset" would only mean an undocumented fallback chosen elsewhere.
        CommentStatus::fromConfig('comments.default_status', $config['default_status'] ?? null);
        CommentStatus::fromConfig('comments.guest_status', $config['guest_status'] ?? null);

        $this->validateAllowedReactions($config);

        // Only the shape is checked. Whether the disk exists is the filesystem
        // config's business, and it would be the wrong thing to fail a boot
        // over in an application that never attaches anything.
        AttachmentDefaults::read($config['attachments'] ?? null);
    }

    /**
     * Absent is not the same as null here. Null means "allow anything", so a
     * published config that predates the key would quietly turn the allowlist
     * off rather than announce that it needs updating.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateAllowedReactions(array $config): void
    {
        if (! array_key_exists('allowed_reactions', $config)) {
            throw InvalidConfigurationException::missingAllowedReactions();
        }

        AllowedReactions::read($config['allowed_reactions']);
    }

    /**
     * @param  array<array-key, mixed>  $tableNames
     */
    private function validateTableNames(array $tableNames): void
    {
        $missing = [];

        foreach (TableNames::keys() as $key) {
            if (! array_key_exists($key, $tableNames)) {
                $missing[] = $key;

                continue;
            }

            $name = $tableNames[$key];

            if (! is_string($name) || trim($name) === '') {
                throw InvalidConfigurationException::blankTableName($key);
            }
        }

        if ($missing !== []) {
            throw InvalidConfigurationException::missingTableNames($missing);
        }
    }

    private function validateActorKeyType(mixed $type): void
    {
        if (! is_string($type) || ! in_array($type, ['int', 'uuid', 'ulid', 'string'], true)) {
            throw InvalidConfigurationException::invalidActorKeyType($type);
        }
    }

    private function validateMaxDepth(mixed $depth): void
    {
        if ($depth !== null && (! is_int($depth) || $depth < 0)) {
            throw InvalidConfigurationException::invalidMaxDepth($depth);
        }
    }

    private function validateMaxLength(mixed $length): void
    {
        if ($length !== null && (! is_int($length) || $length < 1)) {
            throw InvalidConfigurationException::invalidMaxLength($length);
        }
    }
}
