<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Concerns;

use ByRcsc\LaravelComments\Exceptions\CommentableNotPersistedException;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Makes a model commentable. Adding the trait is the whole integration: the
 * model gains a `comments()` relation and the two write methods, and needs no
 * further wiring.
 *
 * @phpstan-require-extends Model
 */
trait HasComments
{
    /**
     * Every comment on this record, whatever its status or thread position.
     * Combine with the scopes to narrow: `topLevel()` for thread starters,
     * `with('replies')` to bring each one's replies along.
     *
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Write a comment as a commentator model - a user, an admin, a bot, any
     * Eloquent model.
     */
    public function comment(string $body, Model $by): Comment
    {
        return $this->writeComment([
            'commentator_type' => $by->getMorphClass(),
            'commentator_id' => $by->getKey(),
            'body' => $body,
        ]);
    }

    /**
     * Write a comment as a guest. The name and email are stored as given and
     * treated as untrusted input everywhere; nothing is verified and nothing
     * is mailed.
     */
    public function commentAsGuest(string $body, string $name, string $email): Comment
    {
        return $this->writeComment([
            'guest_name' => $name,
            'guest_email' => $email,
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeComment(array $attributes): Comment
    {
        if (! $this->exists) {
            throw CommentableNotPersistedException::for($this);
        }

        return $this->comments()->create($attributes);
    }
}
