<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Events\CommentDeleted;
use ByRcsc\LaravelComments\Events\CommentForceDeleted;
use ByRcsc\LaravelComments\Events\CommentRestored;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use Illuminate\Support\Facades\Event;

/*
 * Faking only the package's own events on purpose: Event::fake() with no
 * arguments would swallow the eloquent.* events the engine itself runs on.
 */

it('fires CommentCreated with the comment', function (): void {
    Event::fake([CommentCreated::class]);

    $comment = post()->comment('Hello', by: user());

    Event::assertDispatched(
        CommentCreated::class,
        fn (CommentCreated $event): bool => $event->comment->is($comment),
    );
});

it('fires CommentCreated for guest comments and replies alike', function (): void {
    Event::fake([CommentCreated::class]);

    $comment = post()->comment('Root', by: user());
    $comment->reply('A reply', by: user());
    $comment->replyAsGuest('A guest reply', name: 'Jane', email: 'jane@example.com');

    Event::assertDispatchedTimes(CommentCreated::class, 3);
});

it('fires CommentUpdated with the comment', function (): void {
    $comment = post()->comment('Hello', by: user());

    Event::fake([CommentUpdated::class]);
    $comment->update(['body' => 'Hello, edited']);

    Event::assertDispatched(
        CommentUpdated::class,
        fn (CommentUpdated $event): bool => $event->comment->is($comment),
    );
});

it('fires CommentDeleted on soft delete', function (): void {
    $comment = post()->comment('Hello', by: user());

    Event::fake([CommentDeleted::class, CommentForceDeleted::class]);
    $comment->delete();

    Event::assertDispatched(
        CommentDeleted::class,
        fn (CommentDeleted $event): bool => $event->comment->is($comment),
    );
    Event::assertNotDispatched(CommentForceDeleted::class);
});

it('fires CommentRestored with the comment', function (): void {
    $comment = post()->comment('Hello', by: user());
    $comment->delete();

    Event::fake([CommentRestored::class]);
    $comment->restore();

    Event::assertDispatched(
        CommentRestored::class,
        fn (CommentRestored $event): bool => $event->comment->is($comment),
    );
});

it('fires CommentForceDeleted alongside CommentDeleted on force delete', function (): void {
    $comment = post()->comment('Hello', by: user());

    Event::fake([CommentDeleted::class, CommentForceDeleted::class]);
    $comment->forceDelete();

    Event::assertDispatched(
        CommentForceDeleted::class,
        fn (CommentForceDeleted $event): bool => $event->comment->is($comment),
    );
    Event::assertDispatched(CommentDeleted::class);
});

it('fires no event for a rejected comment', function (): void {
    Event::fake([CommentCreated::class]);
    config()->set('comments.max_length', 5);

    try {
        post()->comment('Far too long for the limit', by: user());
    } catch (BodyTooLongException) {
        // Expected.
    }

    Event::assertNotDispatched(CommentCreated::class);
});
