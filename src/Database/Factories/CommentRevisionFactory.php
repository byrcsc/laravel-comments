<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Database\Factories;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * A revision the way the engine writes one: what a comment said before an
 * edit, and who made it if anybody was named.
 *
 *     CommentRevision::factory()->forComment($comment)->by($editor)->create();
 *
 * The editor is null by default, which is the ordinary case: a console
 * command, a queued job, and a plain attribute save all change a body with no
 * actor to name.
 *
 * Seeding history directly is for tests about reading it. An edit through
 * `edit()` or a plain save files its own revision, and that is the path a test
 * about *recording* history should use.
 *
 * @extends Factory<CommentRevision>
 */
final class CommentRevisionFactory extends Factory
{
    protected $model = CommentRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->paragraph(),
            'editor_type' => null,
            'editor_id' => null,
        ];
    }

    public function forComment(Comment $comment): self
    {
        return $this->state(['comment_id' => $comment->getKey()]);
    }

    public function by(Model $editor): self
    {
        return $this->state([
            'editor_type' => $editor->getMorphClass(),
            'editor_id' => $editor->getKey(),
        ]);
    }
}
