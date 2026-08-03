<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

/**
 * `attachImage()` is sugar over the framework's own image pipeline, and that
 * pipeline processes nothing without a driver. `intervention/image` is a
 * Composer suggestion here rather than a requirement, because an application
 * that never attaches an image should not carry an image library to install a
 * comment engine.
 *
 * Every other attachment path works without it: `attach()` records metadata
 * about a file the application already stored, and reads no bytes at all.
 */
final class ImageSupportMissingException extends CommentsException
{
    public static function noInterventionImage(): self
    {
        return new self(
            'attachImage() needs the intervention/image package, which drives the framework\'s Image facade. Run "composer require intervention/image", or store the image yourself and record it with attach().'
        );
    }
}
