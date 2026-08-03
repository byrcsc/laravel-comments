<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Database\Factories;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentReaction;
use ByRcsc\LaravelComments\Support\AllowedReactions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * There is no default reactor here, and there cannot be: a reaction is
 * deduplicated by who left it, this package ships no actor model, and a guest
 * is not an identity. Supply one:
 *
 *     CommentReaction::factory()->forComment($comment)->by($user)->create();
 *
 * The reaction defaults to the first entry of the configured allowlist, so a
 * factory-built row is one the engine would also have accepted.
 *
 * @extends Factory<CommentReaction>
 */
final class CommentReactionFactory extends Factory
{
    protected $model = CommentReaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reaction' => $this->defaultReaction(),
        ];
    }

    public function forComment(Comment $comment): self
    {
        return $this->state(['comment_id' => $comment->getKey()]);
    }

    public function by(Model $reactor): self
    {
        return $this->state([
            'reactor_type' => $reactor->getMorphClass(),
            'reactor_id' => $reactor->getKey(),
        ]);
    }

    public function reaction(string $reaction): self
    {
        return $this->state(['reaction' => $reaction]);
    }

    /**
     * The allowlist's first entry, or a thumbs up when the list is off. A row
     * the allowlist would refuse is not a useful default, however convenient.
     */
    private function defaultReaction(): string
    {
        $allowed = AllowedReactions::read(config('comments.allowed_reactions'));

        return $allowed[0] ?? '👍';
    }
}
