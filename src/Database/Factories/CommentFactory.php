<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Database\Factories;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The definition has no commentable of its own - this package ships no
 * commentable model to point one at. Supply yours:
 *
 *     Comment::factory()->forCommentable($post)->create();
 *
 * or the framework's `for($post, 'commentable')`. The default identity is a
 * guest, the one authorship the package can invent without a host model;
 * `by($user)` switches to an authenticated commentator.
 *
 * No status is set here, so factory-built comments resolve theirs exactly as
 * written ones do - a guest lands pending, which is the behavior a test of a
 * moderation queue should be seeing. Name one with `status()` or its shortcuts
 * when the test is about a particular status rather than about how comments
 * arrive.
 *
 * @extends Factory<Comment>
 */
final class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_name' => fake()->name(),
            'guest_email' => fake()->safeEmail(),
            'body' => fake()->paragraph(),
        ];
    }

    public function status(CommentStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    public function pending(): self
    {
        return $this->status(CommentStatus::Pending);
    }

    public function approved(): self
    {
        return $this->status(CommentStatus::Approved);
    }

    public function rejected(): self
    {
        return $this->status(CommentStatus::Rejected);
    }

    public function spam(): self
    {
        return $this->status(CommentStatus::Spam);
    }

    public function forCommentable(Model $commentable): self
    {
        return $this->state([
            'commentable_type' => $commentable->getMorphClass(),
            'commentable_id' => $commentable->getKey(),
        ]);
    }

    /**
     * An authenticated comment by the given model, clearing the definition's
     * guest identity - a comment carries exactly one of the two.
     */
    public function by(Model $commentator): self
    {
        return $this->state([
            'commentator_type' => $commentator->getMorphClass(),
            'commentator_id' => $commentator->getKey(),
            'guest_name' => null,
            'guest_email' => null,
        ]);
    }

    public function guest(?string $name = null, ?string $email = null): self
    {
        return $this->state(fn (): array => [
            'commentator_type' => null,
            'commentator_id' => null,
            'guest_name' => $name ?? fake()->name(),
            'guest_email' => $email ?? fake()->safeEmail(),
        ]);
    }

    /**
     * A reply in the given comment's thread, on the same commentable. The
     * depth limit applies here as everywhere: a factory cannot build a thread
     * deeper than the engine allows.
     */
    public function replyTo(Comment $parent): self
    {
        return $this->state([
            'commentable_type' => $parent->commentable_type,
            'commentable_id' => $parent->commentable_id,
            'parent_id' => $parent->getKey(),
        ]);
    }

    /**
     * Held at the top of its thread. Pass a timestamp when the order between
     * several pinned comments is what the test is about.
     */
    public function pinned(?DateTimeInterface $at = null): self
    {
        return $this->state(fn (): array => ['pinned_at' => $at ?? Carbon::now()]);
    }

    /**
     * A tombstone: the comment as a moderator finds it after a soft delete,
     * keeping its replies, revisions, reactions, and attachments.
     */
    public function trashed(?DateTimeInterface $at = null): self
    {
        return $this->state(fn (): array => ['deleted_at' => $at ?? Carbon::now()]);
    }

    /**
     * A comment sitting `$depth` levels down a freshly built thread: the
     * ancestors above it are created too, on the same commentable.
     *
     * Chain it after `forCommentable()`, which is what tells the ancestors
     * where to live. The depth limit applies as it does everywhere, so
     * `threaded()` past `comments.max_depth` throws exactly as a reply would.
     */
    public function threaded(int $depth): self
    {
        if ($depth < 1) {
            return $this;
        }

        return $this->state(function (array $attributes) use ($depth): array {
            $commentable = [
                'commentable_type' => $attributes['commentable_type'] ?? null,
                'commentable_id' => $attributes['commentable_id'] ?? null,
            ];

            if ($commentable['commentable_type'] === null) {
                throw new LogicException(
                    'threaded() builds the comments above this one, so it needs to know what they are on. Chain it after forCommentable().'
                );
            }

            $parentId = null;

            for ($level = 0; $level < $depth; $level++) {
                $parent = self::new()
                    ->state($commentable + ['parent_id' => $parentId])
                    ->createOne();

                $parentId = $parent->getKey();
            }

            return ['parent_id' => $parentId];
        });
    }
}
