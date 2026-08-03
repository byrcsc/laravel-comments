<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

use ByRcsc\LaravelComments\Exceptions\ImageSupportMissingException;
use Illuminate\Image\ImageManager as FrameworkImageManager;
use Intervention\Image\ImageManager;

/**
 * Whether `attachImage()` has anything to run on. Two halves have to be there,
 * and neither is this package's to require.
 *
 * The framework's `Image` facade arrived in Laravel 13, so on Laravel 12 there
 * is no image instance to hand `attachImage()` in the first place - which is
 * why {@see supportedByFramework()} is a question the test suite asks rather
 * than something the write path checks.
 *
 * Every one of that facade's drivers is `intervention/image` underneath. This
 * package suggests that library and never requires it, so the check happens
 * where the sugar is called rather than at boot: an application that never
 * attaches an image should never hear about it.
 */
final class ImageSupport
{
    /**
     * Whether the framework ships the image pipeline at all. Mirrors what the
     * framework's own driver checks for the other half, so the two answers
     * cannot drift apart.
     */
    public static function supportedByFramework(): bool
    {
        return class_exists(FrameworkImageManager::class);
    }

    public static function available(): bool
    {
        return self::supportedByFramework() && class_exists(ImageManager::class);
    }

    public static function assertAvailable(): void
    {
        if (! self::available()) {
            throw ImageSupportMissingException::noInterventionImage();
        }
    }
}
