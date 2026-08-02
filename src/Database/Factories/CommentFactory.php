<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Database\Factories;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

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
}
