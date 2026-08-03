<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Events\ReactionAdded;
use ByRcsc\LaravelComments\Events\ReactionRemoved;
use ByRcsc\LaravelComments\Exceptions\CommentTrashedException;
use ByRcsc\LaravelComments\Exceptions\InvalidReactionException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentReaction;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

it('records a reaction against the comment and the reactor', function (): void {
    $comment = post()->comment('Great write-up!', by: user());
    $reactor = user('Alan Turing');

    $row = $comment->react('👍', by: $reactor);

    expect($row)->toBeInstanceOf(CommentReaction::class)
        ->and($comment->reactions()->count())->toBe(1);

    $read = $comment->reactions()->sole();

    expect($read->reaction)->toBe('👍')
        ->and($read->comment_id)->toBe($comment->getKey())
        ->and($read->reactor)->toBeInstanceOf(User::class)
        ->and($read->reactor?->is($reactor))->toBeTrue();
});

it('lets any model react, not only the commentator', function (): void {
    $comment = post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com');
    $bot = user('Release Bot');

    $comment->react('🎉', by: $bot);

    expect($comment->reactions()->sole()->reactor?->is($bot))->toBeTrue();
});

it('lets different reactors leave the same reaction', function (): void {
    $comment = post()->comment('Great write-up!', by: user());

    $comment->react('👍', by: user('Alan Turing'));
    $comment->react('👍', by: user('Grace Hopper'));

    expect($comment->reactions()->count())->toBe(2)
        ->and($comment->reactionSummary())->toBe(['👍' => 2]);
});

it('lets one reactor leave several different reactions', function (): void {
    $comment = post()->comment('Great write-up!', by: user());
    $reactor = user('Alan Turing');

    $comment->react('👍', by: $reactor);
    $comment->react('🎉', by: $reactor);

    expect($comment->reactionsBy($reactor))->toBe(['🎉', '👍']);
});

describe('reacting twice', function (): void {
    it('stores one row however many times the same reaction is sent', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        $first = $comment->react('👍', by: $reactor);
        $second = $comment->react('👍', by: $reactor);

        expect($comment->reactions()->count())->toBe(1)
            ->and($second->getKey())->toBe($first->getKey());
    });

    it('fires the added event only for the reaction that was actually added', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        Event::fake([ReactionAdded::class]);

        $comment->react('👍', by: $reactor);
        $comment->react('👍', by: $reactor);

        Event::assertDispatchedTimes(ReactionAdded::class, 1);
    });

    /*
     * The engine checks before it writes, so between the two another request
     * can land the same reaction. The index is what actually decides, and its
     * verdict has to come back as the promised no-op rather than an error. A
     * relation loaded before the other write stands in for the gap.
     */
    it('stays a no-op when the reaction lands between the check and the write', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        $comment->setRelation('reactions', new Collection);

        $other = CommentReaction::create([
            'comment_id' => $comment->getKey(),
            'reactor_type' => $reactor->getMorphClass(),
            'reactor_id' => $reactor->getKey(),
            'reaction' => '👍',
        ]);

        $row = $comment->react('👍', by: $reactor);

        expect($row->getKey())->toBe($other->getKey())
            ->and($comment->reactions()->count())->toBe(1);
    });

    it('is refused by the database even when the engine is bypassed', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        $comment->react('👍', by: $reactor);

        expect(fn () => CommentReaction::create([
            'comment_id' => $comment->getKey(),
            'reactor_type' => $reactor->getMorphClass(),
            'reactor_id' => $reactor->getKey(),
            'reaction' => '👍',
        ]))->toThrow(QueryException::class);
    });
});

describe('unreacting', function (): void {
    it('removes the row and says it did', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');
        $comment->react('👍', by: $reactor);

        expect($comment->unreact('👍', by: $reactor))->toBeTrue()
            ->and($comment->reactions()->count())->toBe(0);
    });

    it('says nothing happened when there was no reaction to remove', function (): void {
        $comment = post()->comment('Great write-up!', by: user());

        expect($comment->unreact('👍', by: user('Alan Turing')))->toBeFalse();
    });

    it('fires nothing when there was no reaction to remove', function (): void {
        $comment = post()->comment('Great write-up!', by: user());

        Event::fake([ReactionRemoved::class]);
        $comment->unreact('👍', by: user('Alan Turing'));

        Event::assertNotDispatched(ReactionRemoved::class);
    });

    it('leaves other reactors and other reactions alone', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $alan = user('Alan Turing');
        $grace = user('Grace Hopper');

        $comment->react('👍', by: $alan);
        $comment->react('🎉', by: $alan);
        $comment->react('👍', by: $grace);

        $comment->unreact('👍', by: $alan);

        expect($comment->reactionSummary())->toBe(['🎉' => 1, '👍' => 1])
            ->and($comment->reactionsBy($alan))->toBe(['🎉']);
    });
});

describe('toggling', function (): void {
    it('adds on the first call and removes on the second', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        expect($comment->toggleReaction('👍', by: $reactor))->toBeTrue()
            ->and($comment->reactions()->count())->toBe(1)
            ->and($comment->toggleReaction('👍', by: $reactor))->toBeFalse()
            ->and($comment->reactions()->count())->toBe(0);
    });

    it('fires one event per real change', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        Event::fake([ReactionAdded::class, ReactionRemoved::class]);

        $comment->toggleReaction('👍', by: $reactor);
        $comment->toggleReaction('👍', by: $reactor);

        Event::assertDispatchedTimes(ReactionAdded::class, 1);
        Event::assertDispatchedTimes(ReactionRemoved::class, 1);
    });
});

describe('the allowlist', function (): void {
    it('refuses a reaction outside the configured set', function (): void {
        $comment = post()->comment('Great write-up!', by: user());

        expect(fn () => $comment->react('🦆', by: user('Alan Turing')))
            ->toThrow(InvalidReactionException::class);
    });

    it('stores nothing when it refuses', function (): void {
        $comment = post()->comment('Great write-up!', by: user());

        try {
            $comment->react('🦆', by: user('Alan Turing'));
        } catch (InvalidReactionException) {
            // Expected.
        }

        expect($comment->reactions()->count())->toBe(0);
    });

    it('accepts any non-empty reaction when set to null', function (): void {
        config()->set('comments.allowed_reactions', null);
        $comment = post()->comment('Great write-up!', by: user());

        $comment->react('handclap', by: user('Alan Turing'));

        expect($comment->reactionSummary())->toBe(['handclap' => 1]);
    });

    it('honors a replaced set', function (): void {
        config()->set('comments.allowed_reactions', ['+1', '-1']);
        $comment = post()->comment('Great write-up!', by: user());

        $comment->react('+1', by: user('Alan Turing'));

        expect($comment->reactionSummary())->toBe(['+1' => 1])
            ->and(fn () => $comment->react('👍', by: user('Grace Hopper')))
            ->toThrow(InvalidReactionException::class);
    });

    it('refuses a blank reaction even with the allowlist off', function (): void {
        config()->set('comments.allowed_reactions', null);
        $comment = post()->comment('Great write-up!', by: user());

        expect(fn () => $comment->react('   ', by: user('Alan Turing')))
            ->toThrow(InvalidReactionException::class);
    });

    it('refuses a reaction longer than the column with the allowlist off', function (): void {
        config()->set('comments.allowed_reactions', null);
        $comment = post()->comment('Great write-up!', by: user());

        expect(fn () => $comment->react(str_repeat('a', 65), by: user('Alan Turing')))
            ->toThrow(InvalidReactionException::class);
    });
});

describe('events', function (): void {
    it('carries the comment, the reactor, and the reaction when one is added', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');

        Event::fake([ReactionAdded::class]);
        $comment->react('👍', by: $reactor);

        Event::assertDispatched(
            ReactionAdded::class,
            fn (ReactionAdded $event): bool => $event->comment->is($comment)
                && $event->reactor->is($reactor)
                && $event->reaction === '👍',
        );
    });

    it('carries the same three when one is removed', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');
        $comment->react('👍', by: $reactor);

        Event::fake([ReactionRemoved::class]);
        $comment->unreact('👍', by: $reactor);

        Event::assertDispatched(
            ReactionRemoved::class,
            fn (ReactionRemoved $event): bool => $event->comment->is($comment)
                && $event->reactor->is($reactor)
                && $event->reaction === '👍',
        );
    });
});

describe('deleted comments', function (): void {
    it('refuses a reaction on a soft-deleted comment', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $comment->delete();

        expect(fn () => $comment->react('👍', by: user('Alan Turing')))
            ->toThrow(CommentTrashedException::class);
    });

    it('keeps the reactions a tombstone already had', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $comment->react('👍', by: user('Alan Turing'));

        $comment->delete();

        expect($comment->reactions()->count())->toBe(1)
            ->and($comment->reactionSummary())->toBe(['👍' => 1]);
    });

    it('refuses to take a reaction back off a tombstone', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reactor = user('Alan Turing');
        $comment->react('👍', by: $reactor);

        $comment->delete();

        expect(fn () => $comment->unreact('👍', by: $reactor))
            ->toThrow(CommentTrashedException::class)
            ->and($comment->reactions()->count())->toBe(1);
    });

    it('refuses to toggle on a tombstone, whichever way it would go', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $reacted = user('Alan Turing');
        $never = user('Grace Hopper');
        $comment->react('👍', by: $reacted);

        $comment->delete();

        expect(fn () => $comment->toggleReaction('👍', by: $reacted))
            ->toThrow(CommentTrashedException::class)
            ->and(fn () => $comment->toggleReaction('👍', by: $never))
            ->toThrow(CommentTrashedException::class);
    });

    it('lets a restored comment be reacted to again', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $comment->delete();
        $comment->restore();

        $comment->react('👍', by: user('Alan Turing'));

        expect($comment->reactions()->count())->toBe(1);
    });

    it('removes reactions when the comment is force deleted', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $comment->react('👍', by: user('Alan Turing'));

        $comment->forceDelete();

        expect(CommentReaction::query()->count())->toBe(0);
    });

    it('removes reactions on a whole subtree when the root is force deleted', function (): void {
        $root = post()->comment('Root', by: user());
        $reply = $root->reply('A reply', by: user('Alan Turing'));

        $root->react('👍', by: user('Grace Hopper'));
        $reply->react('🎉', by: user('Katherine Johnson'));

        $root->forceDelete();

        expect(CommentReaction::query()->count())->toBe(0)
            ->and(Comment::withTrashed()->count())->toBe(0);
    });
});

it('refuses to react to an unsaved comment', function (): void {
    expect(fn () => (new Comment)->react('👍', by: user()))
        ->toThrow(LogicException::class);
});
