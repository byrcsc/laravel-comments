<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Events\AttachmentAdded;
use ByRcsc\LaravelComments\Exceptions\CommentTrashedException;
use ByRcsc\LaravelComments\Exceptions\ImageSupportMissingException;
use ByRcsc\LaravelComments\Support\ImageSupport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;

/**
 * Two halves that cannot both run in one process: the whole point of the
 * missing-dependency path is that intervention/image is *not* installed. CI
 * runs the suite twice - once as the package installs by default, once with
 * the suggestion pulled in - and each half skips itself in the other's leg.
 *
 * Both halves need the framework's own `Image`, which arrived in Laravel 13.
 * On Laravel 12 there is no image instance to hand `attachImage()` at all, so
 * the whole file steps aside rather than fataling on a class that is not there.
 */
function fixtureImage(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/pixel.png');
}

beforeEach(function (): void {
    if (! ImageSupport::supportedByFramework()) {
        $this->markTestSkipped('This Laravel version ships no Image facade; attachImage() has nothing to accept.');
    }
});

describe('without intervention/image', function (): void {
    beforeEach(function (): void {
        if (ImageSupport::available()) {
            $this->markTestSkipped('intervention/image is installed; the installed-dependency suite covers this path.');
        }
    });

    it('throws a package exception naming the missing dependency', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        expect(fn () => $comment->attachImage(Image::fromBytes(fixtureImage())))
            ->toThrow(ImageSupportMissingException::class)
            ->and(fn () => $comment->attachImage(Image::fromBytes(fixtureImage())))
            ->toThrow(ImageSupportMissingException::class, 'intervention/image');
    });

    it('records nothing when the dependency is missing', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        try {
            $comment->attachImage(Image::fromBytes(fixtureImage()));
        } catch (ImageSupportMissingException) {
            // The assertion is the empty table below.
        }

        expect($comment->attachments()->count())->toBe(0);
    });

    it('leaves the plain attach path working without it', function (): void {
        $comment = post()->comment('A file', by: user());

        expect($comment->attach(path: 'a.pdf')->exists)->toBeTrue();
    });

    it('reports the tombstone before the missing dependency', function (): void {
        $comment = post()->comment('A screenshot', by: user());
        $comment->delete();

        expect(fn () => $comment->attachImage(Image::fromBytes(fixtureImage())))
            ->toThrow(CommentTrashedException::class);
    });
});

describe('with intervention/image', function (): void {
    beforeEach(function (): void {
        if (! ImageSupport::available()) {
            $this->markTestSkipped('intervention/image is not installed; it is a Composer suggestion, not a requirement.');
        }

        Storage::fake('uploads');

        config()->set('comments.attachments.disk', 'uploads');
        config()->set('comments.attachments.directory', 'comments/attachments');
    });

    it('stores the image and records what it stored', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        $attachment = $comment->attachImage(Image::fromBytes(fixtureImage()));

        $disk = Storage::disk('uploads');

        expect($attachment->disk)->toBe('uploads')
            ->and($attachment->path)->toStartWith('comments/attachments/')
            ->and($disk->exists($attachment->path))->toBeTrue()
            ->and($attachment->size)->toBe($disk->size($attachment->path))
            ->and($attachment->mime_type)->toBe('image/webp')
            ->and($attachment->name)->toBe(basename($attachment->path));
    });

    it('optimizes to webp by default', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        $attachment = $comment->attachImage(Image::fromBytes(fixtureImage()));

        expect($attachment->mime_type)->toBe('image/webp')
            ->and($attachment->path)->toEndWith('.webp');
    });

    it('leaves a pipeline the caller configured alone', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        $attachment = $comment->attachImage(
            Image::fromBytes(fixtureImage())->toPng(),
            optimize: false,
        );

        expect($attachment->mime_type)->toBe('image/png')
            ->and($attachment->path)->toEndWith('.png');
    });

    it('applies the transformations it was handed', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        $attachment = $comment->attachImage(Image::fromBytes(fixtureImage())->resize(4, 4));

        $bytes = Storage::disk('uploads')->get($attachment->path);
        $size = getimagesizefromstring((string) $bytes);

        expect($size[0])->toBe(4);
    });

    it('keeps an uploaded file\'s own name, carrying the stored extension', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        $upload = UploadedFile::fake()->createWithContent('screenshot.png', fixtureImage());

        // Optimized to WebP, so recording ".png" would be metadata that lies.
        expect($comment->attachImage(Image::fromUpload($upload))->name)->toBe('screenshot.webp');
    });

    it('keeps the uploaded extension when nothing rewrote the format', function (): void {
        $comment = post()->comment('A screenshot', by: user());

        $upload = UploadedFile::fake()->createWithContent('screenshot.png', fixtureImage());

        expect($comment->attachImage(Image::fromUpload($upload), optimize: false)->name)
            ->toBe('screenshot.png');
    });

    it('honors an explicit name, disk, and directory', function (): void {
        Storage::fake('elsewhere');

        $comment = post()->comment('A screenshot', by: user());

        $attachment = $comment->attachImage(
            Image::fromBytes(fixtureImage()),
            name: 'The screenshot',
            disk: 'elsewhere',
            directory: 'shots',
        );

        expect($attachment->name)->toBe('The screenshot')
            ->and($attachment->disk)->toBe('elsewhere')
            ->and($attachment->path)->toStartWith('shots/')
            ->and(Storage::disk('elsewhere')->exists($attachment->path))->toBeTrue();
    });

    it('fires AttachmentAdded like any other attachment', function (): void {
        Event::fake([AttachmentAdded::class]);

        $comment = post()->comment('A screenshot', by: user());
        $comment->attachImage(Image::fromBytes(fixtureImage()));

        Event::assertDispatched(AttachmentAdded::class);
    });

    it('refuses a tombstone', function (): void {
        $comment = post()->comment('A screenshot', by: user());
        $comment->delete();

        expect(fn () => $comment->attachImage(Image::fromBytes(fixtureImage())))
            ->toThrow(CommentTrashedException::class);
    });
});
