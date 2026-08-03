<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Policies\CommentPolicy;
use ByRcsc\LaravelComments\Tests\Stubs\Admin;
use ByRcsc\LaravelComments\Tests\Stubs\DenyEverythingPolicy;
use ByRcsc\LaravelComments\Tests\Stubs\ModeratorCommentPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Everything here goes through the framework's authorization API rather than
 * calling the policy directly: registering it in one line is the documented
 * integration, and a policy that only works when called by hand would not be
 * one.
 */
function registerCommentPolicy(): void
{
    Gate::policy(Comment::class, CommentPolicy::class);
}

/**
 * The abilities that stay denied until an application says who moderates.
 *
 * @return list<string>
 */
function moderationAbilities(): array
{
    return ['approve', 'reject', 'markAsSpam', 'pin', 'unpin', 'restore', 'forceDelete'];
}

beforeEach(fn () => registerCommentPolicy());

describe('the author', function (): void {
    it('may update and delete their own comment', function (string $ability): void {
        $author = user();
        $comment = post()->comment('Mine', by: $author);

        expect(Gate::forUser($author)->allows($ability, $comment))->toBeTrue();
    })->with(['update', 'delete']);

    it('may not moderate their own comment', function (string $ability): void {
        $author = user();
        $comment = post()->comment('Mine', by: $author);

        expect(Gate::forUser($author)->allows($ability, $comment))->toBeFalse();
    })->with(moderationAbilities());
});

describe('another user', function (): void {
    it('may not update or delete somebody else\'s comment', function (string $ability): void {
        $comment = post()->comment('Mine', by: user());

        expect(Gate::forUser(user('Alan Turing'))->allows($ability, $comment))->toBeFalse();
    })->with(['update', 'delete']);

    it('may still react and attach', function (string $ability): void {
        $comment = post()->comment('Mine', by: user());

        expect(Gate::forUser(user('Alan Turing'))->allows($ability, $comment))->toBeTrue();
    })->with(['react', 'attach']);

    it('may not be mistaken for the author by a shared key', function (): void {
        $author = user();
        $comment = post()->comment('Mine', by: $author);

        // Same table, same key, different class: not the same author.
        $impostor = Admin::query()->findOrFail($author->id);

        expect($impostor->getKey())->toBe($author->getKey())
            ->and(Gate::forUser($impostor)->allows('update', $comment))->toBeFalse();
    });
});

describe('a guest-authored comment', function (): void {
    it('matches no actor, so ownership always denies', function (string $ability): void {
        $comment = post()->commentAsGuest('Theirs', name: 'Jane', email: 'jane@example.com');

        expect(Gate::forUser(user())->allows($ability, $comment))->toBeFalse();
    })->with(['update', 'delete']);
});

describe('an unauthenticated visitor', function (): void {
    it('may not create, react, or attach', function (): void {
        $comment = post()->comment('Mine', by: user());

        expect(Gate::allows('create', Comment::class))->toBeFalse()
            ->and(Gate::allows('react', $comment))->toBeFalse()
            ->and(Gate::allows('attach', $comment))->toBeFalse();
    });

    it('may not update or delete anything', function (string $ability): void {
        $comment = post()->comment('Mine', by: user());

        expect(Gate::allows($ability, $comment))->toBeFalse();
    })->with(['update', 'delete']);
});

describe('an authenticated actor', function (): void {
    it('may create a comment', function (): void {
        expect(Gate::forUser(user())->allows('create', Comment::class))->toBeTrue();
    });
});

describe('overriding', function (): void {
    it('takes a single ability without copying the class', function (): void {
        Gate::policy(Comment::class, ModeratorCommentPolicy::class);

        $moderator = user('Grace Hopper');
        $moderator->setAttribute('is_moderator', true);

        $comment = post()->comment('Theirs', by: user());

        expect(Gate::forUser($moderator)->allows('approve', $comment))->toBeTrue()
            // Everything not overridden keeps the shipped default.
            ->and(Gate::forUser($moderator)->allows('pin', $comment))->toBeFalse()
            ->and(Gate::forUser($moderator)->allows('update', $comment))->toBeFalse();
    });
});

describe('the engine never authorizes internally', function (): void {
    beforeEach(function (): void {
        // No policy at all, and nobody logged in: a queued job, a seeder, and
        // a console command all look like this.
        Gate::policy(Comment::class, DenyEverythingPolicy::class);

        expect(auth()->check())->toBeFalse();
    });

    it('writes, moderates, reacts, pins, attaches, and deletes anyway', function (): void {
        $post = post();
        $author = user();

        $comment = $post->comment('Written with nobody logged in', by: $author);
        $reply = $comment->reply('And replied to', by: $author);

        $comment->approve();
        $comment->reject();
        $comment->markAsSpam();
        $comment->pin();
        $comment->unpin();
        $comment->react('👍', by: $author);
        $comment->unreact('👍', by: $author);
        $comment->edit('Edited with nobody logged in', by: $author);
        $attachment = $comment->attach(path: 'a.pdf');
        $comment->detach($attachment);

        $reply->delete();
        $reply->restore();
        $reply->forceDelete();

        expect($comment->fresh()?->body)->toBe('Edited with nobody logged in')
            ->and(Gate::allows('update', $comment))->toBeFalse();
    });
});
