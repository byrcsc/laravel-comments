<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Comments;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Events\ReactionAdded;
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use ByRcsc\LaravelComments\Exceptions\InvalidReactionException;
use ByRcsc\LaravelComments\Exceptions\NotFakeableException;
use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Testing\CommentsFake;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;

it('hands back the same recorder every time', function (): void {
    expect(Comments::fake())->toBeInstanceOf(CommentsFake::class)
        ->and(Comments::fake())->toBe(Comments::fake());
});

it('records nothing until asked to', function (): void {
    expect(Comments::faked())->toBeNull();
});

describe('faking writes', function (): void {
    beforeEach(fn () => Comments::fake());

    it('writes no rows', function (): void {
        post()->comment('Never stored', by: user());

        expect(Comment::query()->count())->toBe(0);
    });

    it('hands back a comment the caller can use', function (): void {
        $comment = post()->comment('Never stored', by: user());

        expect($comment)->toBeInstanceOf(Comment::class)
            ->and($comment->getKey())->not->toBeNull()
            ->and($comment->body)->toBe('Never stored');
    });

    it('records guest comments too', function (): void {
        post()->commentAsGuest('From a guest', name: 'Jane', email: 'jane@example.com');

        Comments::fake()->assertCommented(
            fn (Comment $comment): bool => $comment->guest_name === 'Jane',
        );
    });

    it('records replies, and can tell them from comments', function (): void {
        $author = user();
        $comment = post()->comment('The original', by: $author);
        $comment->reply('A reply', by: $author);

        $fake = Comments::fake();

        expect($fake->comments())->toHaveCount(2)
            ->and($fake->replies())->toHaveCount(1)
            ->and($fake->repliesTo($comment))->toHaveCount(1);

        $fake->assertReplied();
    });

    it('records reactions', function (): void {
        $reactor = user();
        $comment = post()->comment('Reacted to', by: $reactor);

        $comment->react('👍', by: $reactor);

        $fake = Comments::fake();

        $fake->assertReacted($reactor, '👍');

        expect($fake->reactionsOn($comment))->toBe(['👍']);
    });

    it('keeps a double tap a no-op, like the engine does', function (): void {
        $reactor = user();
        $comment = post()->comment('Reacted to', by: $reactor);

        $comment->react('👍', by: $reactor);
        $comment->react('👍', by: $reactor);

        expect(Comments::fake()->reactionsOn($comment))->toBe(['👍']);
    });

    it('takes a reaction back', function (): void {
        $reactor = user();
        $comment = post()->comment('Reacted to', by: $reactor);

        $comment->react('👍', by: $reactor);

        expect($comment->unreact('👍', by: $reactor))->toBeTrue()
            ->and($comment->unreact('👍', by: $reactor))->toBeFalse()
            ->and(Comments::fake()->reactionsOn($comment))->toBe([]);
    });

    it('still refuses a reaction the allowlist does not carry', function (): void {
        $reactor = user();
        $comment = post()->comment('Reacted to', by: $reactor);

        expect(fn () => $comment->react('🦆', by: $reactor))
            ->toThrow(InvalidReactionException::class);
    });

    it('fires none of the package\'s events, because nothing happened', function (): void {
        Event::fake([CommentCreated::class, ReactionAdded::class]);

        $author = user();
        $comment = post()->comment('Never stored', by: $author);
        $comment->react('👍', by: $author);

        Event::assertNotDispatched(CommentCreated::class);
        Event::assertNotDispatched(ReactionAdded::class);
    });

    it('is held to the depth limit a real write is', function (): void {
        $author = user();
        $comment = post()->comment('Depth 0', by: $author);

        for ($depth = 1; $depth <= 3; $depth++) {
            $comment = $comment->reply("Depth {$depth}", by: $author);
        }

        expect(fn () => $comment->reply('Too deep', by: $author))
            ->toThrow(ThreadTooDeepException::class);
    });

    it('hands back the same reaction row on a double tap, like the engine does', function (): void {
        $reactor = user();
        $comment = post()->comment('Reacted to', by: $reactor);

        expect($comment->react('👍', by: $reactor))->toBe($comment->react('👍', by: $reactor));
    });

    it('refuses the writes it does not record, rather than writing nothing', function (Closure $write): void {
        // Pending, so every transition below has somewhere to move to.
        $comment = post()->commentAsGuest('Recorded, not stored', name: 'Jane', email: 'jane@example.com');

        expect(fn () => $write($comment))->toThrow(NotFakeableException::class);
    })->with([
        'approve' => [fn (Comment $comment): bool => $comment->approve()],
        'reject' => [fn (Comment $comment): bool => $comment->reject()],
        'mark as spam' => [fn (Comment $comment): bool => $comment->markAsSpam()],
        'pin' => [fn (Comment $comment): bool => $comment->pin()],
        'edit' => [fn (Comment $comment): bool => $comment->edit('A second draft')],
        'attach' => [fn (Comment $comment) => $comment->attach(path: 'a.pdf')],
        'delete' => [fn (Comment $comment) => $comment->delete()],
        'force delete' => [fn (Comment $comment) => $comment->forceDelete()],
    ]);

    it('says what it does record when it refuses', function (): void {
        $comment = post()->commentAsGuest('Recorded, not stored', name: 'Jane', email: 'jane@example.com');

        expect(fn () => $comment->approve())
            ->toThrow(NotFakeableException::class, 'replying, and reacting');
    });

    it('leaves a no-op a no-op, with nothing to refuse', function (): void {
        $comment = post()->comment('Recorded, not stored', by: user());

        // Already approved, and never pinned: neither of these would write
        // anything even against a real row, so neither is refused.
        expect($comment->approve())->toBeFalse()
            ->and($comment->unpin())->toBeFalse();
    });

    it('is held to the body limit a real write is', function (): void {
        config()->set('comments.max_length', 10);

        expect(fn () => post()->comment('Far longer than ten characters', by: user()))
            ->toThrow(BodyTooLongException::class);
    });
});

describe('reading back what was recorded', function (): void {
    beforeEach(fn () => Comments::fake());

    it('reads a commentable\'s comments', function (): void {
        $post = post();
        $other = post('Another post');
        $author = user();

        $post->comment('One', by: $author);
        $post->comment('Two', by: $author);
        $other->comment('Elsewhere', by: $author);

        $fake = Comments::fake();

        expect($fake->commentsOn($post)->pluck('body')->all())->toBe(['One', 'Two'])
            ->and($fake->commentsOn($other))->toHaveCount(1);
    });

    it('resolves the initial status the way a real write would', function (): void {
        $guest = post()->commentAsGuest('From a guest', name: 'Jane', email: 'jane@example.com');

        expect($guest->status)->toBe(CommentStatus::Pending);
    });
});

describe('the assertions', function (): void {
    beforeEach(fn () => Comments::fake());

    it('passes assertNothingCommented when nothing was written', function (): void {
        Comments::fake()->assertNothingCommented();
        Comments::fake()->assertNothingReacted();
    });

    it('fails assertNothingCommented when something was', function (): void {
        post()->comment('Written', by: user());

        expect(fn () => Comments::fake()->assertNothingCommented())
            ->toThrow(AssertionFailedError::class);
    });

    it('fails assertCommented when nothing was written', function (): void {
        expect(fn () => Comments::fake()->assertCommented())
            ->toThrow(AssertionFailedError::class);
    });

    it('fails assertCommentedOn for the wrong commentable', function (): void {
        post()->comment('Written', by: user());

        expect(fn () => Comments::fake()->assertCommentedOn(post('Another post')))
            ->toThrow(AssertionFailedError::class);
    });

    it('fails assertReplied when only top-level comments were written', function (): void {
        post()->comment('Written', by: user());

        expect(fn () => Comments::fake()->assertReplied())
            ->toThrow(AssertionFailedError::class);
    });

    it('fails assertReacted when the reactor does not match', function (): void {
        $reactor = user();
        post()->comment('Reacted to', by: $reactor)->react('👍', by: $reactor);

        expect(fn () => Comments::fake()->assertReacted(user('Alan Turing')))
            ->toThrow(AssertionFailedError::class);
    });

    it('narrows assertCommentedOn with a callback', function (): void {
        $post = post();
        $post->comment('The one under test', by: user());

        Comments::fake()->assertCommentedOn(
            $post,
            fn (Comment $comment): bool => $comment->body === 'The one under test',
        );
    });
});

it('goes back to the real engine when faking stops', function (): void {
    Comments::fake();

    post()->comment('Never stored', by: user());

    Comments::stopFaking();

    post()->comment('Stored for real', by: user());

    expect(Comment::query()->count())->toBe(1)
        ->and(Comment::query()->value('body'))->toBe('Stored for real')
        ->and(Comments::faked())->toBeNull();
});
