<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;

/**
 * Where `attachImage()` puts a file when the caller does not say.
 *
 * Only the image path consults these. `attach()` is handed a disk and a path
 * the application already wrote to, and inventing a default for a file that
 * already exists somewhere would only be a guess - so it falls back to the
 * configured disk for the name it records, and never to a directory.
 *
 * Boot-time validation and the write path both come through {@see read()}, so
 * a malformed section cannot be caught in one and quietly tolerated in the
 * other.
 */
final class AttachmentDefaults
{
    /**
     * Validate a configured section without reading config, so boot-time
     * checks and the write path agree on what a valid section is.
     *
     * @return array{disk: string|null, directory: string}
     */
    public static function read(mixed $configured): array
    {
        if (! is_array($configured)) {
            throw InvalidConfigurationException::invalidAttachments($configured);
        }

        $disk = $configured['disk'] ?? null;
        $directory = $configured['directory'] ?? null;

        if ($disk !== null && ! is_string($disk)) {
            throw InvalidConfigurationException::invalidAttachmentDisk($disk);
        }

        if ($directory !== null && ! is_string($directory)) {
            throw InvalidConfigurationException::invalidAttachmentDirectory($directory);
        }

        return [
            'disk' => $disk === null || trim($disk) === '' ? null : $disk,
            'directory' => $directory === null ? '' : trim($directory, '/'),
        ];
    }

    /**
     * An explicit disk wins; then `comments.attachments.disk`; then the
     * application's own default disk, which is what an installer who never
     * touched the config would expect.
     */
    public static function disk(?string $disk = null): string
    {
        if ($disk !== null && trim($disk) !== '') {
            return $disk;
        }

        $configured = self::read(config('comments.attachments'))['disk'];

        if ($configured !== null) {
            return $configured;
        }

        $default = config('filesystems.default');

        return is_string($default) && $default !== '' ? $default : 'local';
    }

    /**
     * The directory an image is stored under, without a trailing slash. An
     * empty string is a real answer: it means the disk's own root.
     */
    public static function directory(?string $directory = null): string
    {
        if ($directory !== null) {
            return trim($directory, '/');
        }

        return self::read(config('comments.attachments'))['directory'];
    }
}
