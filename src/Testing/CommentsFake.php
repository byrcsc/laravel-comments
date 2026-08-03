<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Testing;

use ByRcsc\LaravelComments\Comments;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentReaction;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * What the engine wrote, had it written anything.
 *
 * Reached through {@see Comments::fake()}. Every comment, reply, and reaction
 * the application asks for is recorded here instead of the database, and the
 * assertions read that record.
 *
 * Recorded comments carry a key and report themselves as existing, so a
 * controller that replies to what it just wrote keeps working. They are not
 * rows, and the package knows it: moderating, editing, pinning, attaching to,
 * or deleting one is refused outright rather than allowed to write against a
 * key no table has. A test about those wants a real database.
 *
 * Reads are not faked either. Relations, scopes, and counts still speak to a
 * database that has nothing in it - ask the fake instead, through
 * `commentsOn()`, `repliesTo()`, and `reactionsOn()`.
 */
final class CommentsFake
{
    /**
     * @var list<Comment>
     */
    private array $comments = [];

    /**
     * @var list<array{comment: Comment, reactor: Model, reaction: string, row: CommentReaction}>
     */
    private array $reactions = [];

    /**
     * Keys are handed out here rather than by a database, so a reply has a
     * parent to point at. They are not row ids, and nothing may be written
     * against one - which is what {@see Comment::applyCreationRules()} and the
     * refusals around the unfaked writes are between them enforcing.
     */
    private int $nextId = 1;

    public function recordComment(Comment $comment): Comment
    {
        // The same three rules a real write passes on `creating`. A fake that
        // let a too-deep reply or a blank status through would be answering a
        // different question than the one the test asked.
        $comment->applyCreationRules();

        $comment->setAttribute($comment->getKeyName(), $this->nextId++);
        $comment->exists = true;
        $comment->syncOriginal();

        $this->comments[] = $comment;

        return $comment;
    }

    public function recordReaction(Comment $comment, Model $reactor, string $reaction): CommentReaction
    {
        // Reacting twice with the same reaction is a no-op in the engine, and
        // it hands back the row that was already there. A fake that returned a
        // different object each time would be lying about the thing callers
        // rely on most.
        $existing = $this->findReaction($comment, $reactor, $reaction);

        if ($existing !== null) {
            return $this->reactions[$existing]['row'];
        }

        $row = new CommentReaction([
            'comment_id' => $comment->getKey(),
            'reactor_type' => $reactor->getMorphClass(),
            'reactor_id' => $reactor->getKey(),
            'reaction' => $reaction,
        ]);

        $this->reactions[] = [
            'comment' => $comment,
            'reactor' => $reactor,
            'reaction' => $reaction,
            'row' => $row,
        ];

        return $row;
    }

    public function forgetReaction(Comment $comment, Model $reactor, string $reaction): bool
    {
        $index = $this->findReaction($comment, $reactor, $reaction);

        if ($index === null) {
            return false;
        }

        $remaining = $this->reactions;

        unset($remaining[$index]);

        $this->reactions = array_values($remaining);

        return true;
    }

    /**
     * Every comment written, replies included, oldest first.
     *
     * @return Collection<int, Comment>
     */
    public function comments(): Collection
    {
        return new Collection($this->comments);
    }

    /**
     * @return Collection<int, Comment>
     */
    public function commentsOn(Model $commentable): Collection
    {
        return $this->comments()->filter(
            fn (Comment $comment): bool => $comment->commentable_type === $commentable->getMorphClass()
                && $comment->commentable_id == $commentable->getKey()
        )->values();
    }

    /**
     * @return Collection<int, Comment>
     */
    public function replies(): Collection
    {
        return $this->comments()
            ->filter(fn (Comment $comment): bool => $comment->parent_id !== null)
            ->values();
    }

    /**
     * @return Collection<int, Comment>
     */
    public function repliesTo(Comment $parent): Collection
    {
        return $this->comments()
            ->filter(fn (Comment $comment): bool => $comment->parent_id === $parent->getKey())
            ->values();
    }

    /**
     * Reaction strings left on a comment, in the order they arrived.
     *
     * @return list<string>
     */
    public function reactionsOn(Comment $comment): array
    {
        $reactions = [];

        foreach ($this->reactions as $reaction) {
            if ($reaction['comment']->getKey() === $comment->getKey()) {
                $reactions[] = $reaction['reaction'];
            }
        }

        return $reactions;
    }

    /**
     * @param  (Closure(Comment): bool)|null  $callback
     */
    public function assertCommented(?Closure $callback = null): void
    {
        $matches = $this->comments()->filter($callback ?? fn (): bool => true);

        Assert::assertTrue(
            $matches->isNotEmpty(),
            $callback === null
                ? 'Expected a comment to be written, but none was.'
                : 'Expected a comment matching the callback to be written, but none did.',
        );
    }

    /**
     * @param  (Closure(Comment): bool)|null  $callback
     */
    public function assertCommentedOn(Model $commentable, ?Closure $callback = null): void
    {
        $matches = $this->commentsOn($commentable)->filter($callback ?? fn (): bool => true);

        Assert::assertTrue(
            $matches->isNotEmpty(),
            'Expected a comment on '.$commentable::class.' ['.$this->keyLabel($commentable).'], but none was written.',
        );
    }

    /**
     * @param  (Closure(Comment): bool)|null  $callback
     */
    public function assertReplied(?Closure $callback = null): void
    {
        $matches = $this->replies()->filter($callback ?? fn (): bool => true);

        Assert::assertTrue(
            $matches->isNotEmpty(),
            'Expected a reply to be written, but none was.',
        );
    }

    public function assertReacted(?Model $reactor = null, ?string $reaction = null): void
    {
        $matches = array_filter(
            $this->reactions,
            fn (array $row): bool => ($reactor === null || $this->isSameActor($row['reactor'], $reactor))
                && ($reaction === null || $row['reaction'] === $reaction),
        );

        Assert::assertNotEmpty($matches, 'Expected a reaction to be recorded, but none matched.');
    }

    public function assertNothingCommented(): void
    {
        Assert::assertEmpty(
            $this->comments,
            'Expected no comments to be written, but '.count($this->comments).' were.',
        );
    }

    public function assertNothingReacted(): void
    {
        Assert::assertEmpty(
            $this->reactions,
            'Expected no reactions to be recorded, but '.count($this->reactions).' were.',
        );
    }

    private function findReaction(Comment $comment, Model $reactor, string $reaction): ?int
    {
        foreach ($this->reactions as $index => $row) {
            if ($row['comment']->getKey() === $comment->getKey()
                && $row['reaction'] === $reaction
                && $this->isSameActor($row['reactor'], $reactor)) {
                return $index;
            }
        }

        return null;
    }

    private function keyLabel(Model $model): string
    {
        /** @var mixed $key */
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : 'unsaved';
    }

    private function isSameActor(Model $one, Model $other): bool
    {
        return $one->getMorphClass() === $other->getMorphClass()
            && $one->getKey() == $other->getKey();
    }
}
