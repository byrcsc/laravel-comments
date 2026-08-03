<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

/**
 * `attachImage()` is the one path where the package writes to a disk, and the
 * disk refused. Nothing is recorded when this throws: a metadata row pointing
 * at a file that was never written is worse than no row at all.
 */
final class AttachmentStorageFailedException extends CommentsException
{
    public static function forDisk(string $disk, string $path): self
    {
        $where = $path === '' ? 'its root' : "\"{$path}\"";

        return new self(
            "Storing the image on the \"{$disk}\" disk under {$where} failed. Check the disk's configuration and that it is writable."
        );
    }
}
