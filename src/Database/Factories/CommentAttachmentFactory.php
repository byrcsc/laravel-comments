<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Database\Factories;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;
use ByRcsc\LaravelComments\Support\AttachmentDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Metadata rows about files that do not exist, which is exactly what an
 * attachment row is to this package: it never opens the file, so a factory has
 * nothing to fake beyond the metadata.
 *
 *     CommentAttachment::factory()->forComment($comment)->create();
 *
 * Point it at a `Storage::fake()` disk with `on()` and write the file yourself
 * when the test is about what the application stored.
 *
 * @extends Factory<CommentAttachment>
 */
final class CommentAttachmentFactory extends Factory
{
    protected $model = CommentAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->slug(2).'.pdf';

        return [
            'disk' => AttachmentDefaults::disk(),
            'path' => self::pathFor($name),
            'name' => $name,
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1, 5_000_000),
        ];
    }

    public function forComment(Comment $comment): self
    {
        return $this->state(['comment_id' => $comment->getKey()]);
    }

    public function on(string $disk): self
    {
        return $this->state(['disk' => $disk]);
    }

    /**
     * An image rather than the default document, for a test about rendering a
     * comment's screenshots.
     */
    public function image(): self
    {
        return $this->state(function (): array {
            $name = fake()->slug(2).'.webp';

            return [
                'name' => $name,
                'path' => self::pathFor($name),
                'mime_type' => 'image/webp',
            ];
        });
    }

    /**
     * Under the configured directory, which may be the disk's own root.
     */
    private static function pathFor(string $name): string
    {
        $directory = AttachmentDefaults::directory();

        return $directory === '' ? $name : "{$directory}/{$name}";
    }
}
