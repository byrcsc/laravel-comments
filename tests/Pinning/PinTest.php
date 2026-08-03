<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentPinned;
use ByRcsc\LaravelComments\Events\CommentUnpinned;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use Illuminate\Support\Facades\Event;

it('pins a comment and reports that it moved', function (): void {
    $comment = post()->comment('The announcement', by: user());

    expect($comment->pin())->toBeTrue()
        ->and($comment->pinned_at)->not->toBeNull();
});

it('persists the pin for anyone reading the table', function (): void {
    $comment = post()->comment('The announcement', by: user());

    $comment->pin();

    expect($comment->fresh()?->pinned_at)->not->toBeNull();
});

it('unpins a comment and reports that it moved', function (): void {
    $comment = post()->comment('The announcement', by: user());
    $comment->pin();

    expect($comment->unpin())->toBeTrue()
        ->and($comment->pinned_at)->toBeNull()
        ->and($comment->fresh()?->pinned_at)->toBeNull();
});

describe('idempotency', function (): void {
    it('reports that nothing moved when the comment is already pinned', function (): void {
        $comment = post()->comment('The announcement', by: user());
        $comment->pin();

        expect($comment->pin())->toBeFalse();
    });

    it('reports that nothing moved when the comment is not pinned', function (): void {
        $comment = post()->comment('The announcement', by: user());

        expect($comment->unpin())->toBeFalse();
    });

    it('leaves the original timestamp alone when pinning twice', function (): void {
        $comment = post()->comment('The announcement', by: user());
        $comment->pin();

        $first = $comment->pinned_at;

        $comment->pin();

        expect($comment->pinned_at?->equalTo($first))->toBeTrue();
    });

    it('fires nothing when nothing moved', function (): void {
        $comment = post()->comment('The announcement', by: user());
        $comment->pin();

        Event::fake([CommentPinned::class, CommentUnpinned::class]);

        $comment->pin();
        $comment->unpin();
        $comment->unpin();

        Event::assertNotDispatched(CommentPinned::class);
        Event::assertDispatchedTimes(CommentUnpinned::class, 1);
    });
});

describe('events', function (): void {
    it('fires CommentPinned with the comment and the actor', function (): void {
        Event::fake([CommentPinned::class]);

        $moderator = user('Grace Hopper');
        $comment = post()->comment('The announcement', by: user());

        $comment->pin(by: $moderator);

        Event::assertDispatched(
            CommentPinned::class,
            fn (CommentPinned $event): bool => $event->comment->is($comment)
                && $event->actor instanceof User
                && $event->actor->is($moderator),
        );
    });

    it('fires CommentUnpinned with the comment and the actor', function (): void {
        $moderator = user('Grace Hopper');
        $comment = post()->comment('The announcement', by: user());
        $comment->pin();

        Event::fake([CommentUnpinned::class]);

        $comment->unpin(by: $moderator);

        Event::assertDispatched(
            CommentUnpinned::class,
            fn (CommentUnpinned $event): bool => $event->actor?->is($moderator) === true,
        );
    });

    it('leaves the actor null when nobody is named', function (): void {
        Event::fake([CommentPinned::class]);

        post()->comment('The announcement', by: user())->pin();

        Event::assertDispatched(
            CommentPinned::class,
            fn (CommentPinned $event): bool => $event->actor === null,
        );
    });
});

describe('scopes', function (): void {
    it('reads only pinned comments', function (): void {
        $post = post();
        $pinned = $post->comment('Pinned', by: user());
        $post->comment('Not pinned', by: user('Alan Turing'));

        $pinned->pin();

        expect($post->comments()->pinned()->pluck('id')->all())->toBe([$pinned->id]);
    });

    it('orders pinned comments first, most recently pinned among them', function (): void {
        $post = post();
        $author = user();

        $first = $post->comment('First', by: $author);
        $second = $post->comment('Second', by: $author);
        $third = $post->comment('Third', by: $author);

        $first->pin();
        // A distinct timestamp, so the ordering under test is not a tie.
        $this->travel(1)->minutes();
        $third->pin();

        expect($post->comments()->pinnedFirst()->pluck('body')->all())
            ->toBe(['Third', 'First', 'Second']);
    });

    it('leaves unpinned comments in their own order', function (): void {
        $post = post();
        $author = user();

        $post->comment('First', by: $author);
        $post->comment('Second', by: $author);

        expect($post->comments()->pinnedFirst()->pluck('body')->all())
            ->toBe(['First', 'Second']);
    });

    it('composes with the other scopes', function (): void {
        $post = post();
        $author = user();

        $approved = $post->comment('Approved and pinned', by: $author);
        $spam = $post->comment('Spam but pinned', by: $author);

        $approved->pin();
        $spam->markAsSpam();
        $spam->pin();

        expect($post->comments()->approved()->pinned()->pluck('id')->all())
            ->toBe([$approved->id]);
    });
});

describe('independence from moderation', function (): void {
    it('leaves the status alone when pinning', function (): void {
        $comment = commentInStatus(CommentStatus::Pending);

        $comment->pin();

        expect($comment->status)->toBe(CommentStatus::Pending)
            ->and($comment->fresh()?->status)->toBe(CommentStatus::Pending);
    });

    it('leaves the pin alone when moderating', function (): void {
        $comment = post()->comment('The announcement', by: user());
        $comment->pin();

        $pinnedAt = $comment->pinned_at;

        $comment->markAsSpam();

        expect($comment->fresh()?->pinned_at?->equalTo($pinnedAt))->toBeTrue();
    });

    it('pins a comment in any status', function (CommentStatus $status): void {
        $comment = commentInStatus($status);

        expect($comment->pin())->toBeTrue();
    })->with(CommentStatus::cases());

    it('records no revision and no edit for a pin', function (): void {
        $comment = post()->comment('The announcement', by: user());

        $comment->pin();
        $comment->unpin();

        expect($comment->revisions()->count())->toBe(0)
            ->and($comment->edited_at)->toBeNull();
    });
});

it('allows several pins on one commentable', function (): void {
    $post = post();
    $author = user();

    $post->comment('First', by: $author)->pin();
    $post->comment('Second', by: $author)->pin();

    expect($post->comments()->pinned()->count())->toBe(2);
});

it('pins through the factory state as well', function (): void {
    $comment = Comment::factory()->forCommentable(post())->create();

    $comment->pin();

    expect(Comment::query()->pinned()->count())->toBe(1);
});
