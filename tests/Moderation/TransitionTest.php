<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentApproved;
use ByRcsc\LaravelComments\Events\CommentMarkedAsSpam;
use ByRcsc\LaravelComments\Events\CommentModerated;
use ByRcsc\LaravelComments\Events\CommentRejected;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('approves a pending comment', function (): void {
    $comment = commentInStatus(CommentStatus::Pending);

    expect($comment->approve())->toBeTrue()
        ->and($comment->refresh()->status)->toBe(CommentStatus::Approved);
});

it('rejects a comment without deleting it', function (): void {
    $comment = commentInStatus(CommentStatus::Approved);

    expect($comment->reject())->toBeTrue()
        ->and($comment->refresh()->status)->toBe(CommentStatus::Rejected)
        ->and($comment->trashed())->toBeFalse()
        ->and($comment->body)->toBe('Hello');
});

it('marks a comment as spam distinguishably from rejecting it', function (): void {
    $comment = commentInStatus(CommentStatus::Approved);

    expect($comment->markAsSpam())->toBeTrue()
        ->and($comment->refresh()->status)->toBe(CommentStatus::Spam);
});

it('moves between every pair of statuses', function (CommentStatus $from, CommentStatus $to): void {
    $comment = commentInStatus($from);

    expect(moveCommentTo($comment, $to))->toBeTrue()
        ->and($comment->refresh()->status)->toBe($to);
})->with(function (): Generator {
    // Pending is only ever a starting point: nothing sends a comment back to
    // the queue, and an application that wants to can set the status itself.
    foreach (CommentStatus::cases() as $from) {
        foreach ([CommentStatus::Approved, CommentStatus::Rejected, CommentStatus::Spam] as $to) {
            if ($from !== $to) {
                yield "{$from->value} to {$to->value}" => [$from, $to];
            }
        }
    }
});

it('records the actor on the event when one is given', function (): void {
    $comment = commentInStatus(CommentStatus::Pending);
    $moderator = user('Grace Hopper');

    Event::fake([CommentApproved::class]);
    $comment->approve(by: $moderator);

    Event::assertDispatched(
        CommentApproved::class,
        fn (CommentApproved $event): bool => $event->comment->is($comment)
            && $event->actor instanceof User
            && $event->actor->is($moderator),
    );
});

it('leaves the actor null when nobody is named', function (): void {
    $comment = commentInStatus(CommentStatus::Pending);

    Event::fake([CommentApproved::class]);
    $comment->approve();

    Event::assertDispatched(
        CommentApproved::class,
        fn (CommentApproved $event): bool => $event->actor === null,
    );
});

it('fires the matching event for each transition', function (): void {
    $comment = commentInStatus(CommentStatus::Pending);

    Event::fake([CommentApproved::class, CommentRejected::class, CommentMarkedAsSpam::class]);

    $comment->approve();
    $comment->reject();
    $comment->markAsSpam();

    Event::assertDispatchedTimes(CommentApproved::class, 1);
    Event::assertDispatchedTimes(CommentRejected::class, 1);
    Event::assertDispatchedTimes(CommentMarkedAsSpam::class, 1);
});

it('lets one handler type-hint the base class for every transition', function (): void {
    $seen = [];
    Event::listen(
        [CommentApproved::class, CommentRejected::class, CommentMarkedAsSpam::class],
        function (CommentModerated $event) use (&$seen): void {
            $seen[] = $event->comment->status;
        },
    );

    $comment = commentInStatus(CommentStatus::Pending);
    $comment->approve();
    $comment->markAsSpam();

    expect($seen)->toBe([CommentStatus::Approved, CommentStatus::Spam]);
});

it('carries the new status on the comment the event hands over', function (): void {
    $comment = commentInStatus(CommentStatus::Pending);

    Event::fake([CommentApproved::class]);
    $comment->approve();

    Event::assertDispatched(
        CommentApproved::class,
        fn (CommentApproved $event): bool => $event->comment->status === CommentStatus::Approved,
    );
});

describe('idempotency', function (): void {
    it('reports that nothing moved when the status is already the target', function (CommentStatus $status): void {
        $comment = commentInStatus($status);

        expect(moveCommentTo($comment, $status))->toBeFalse()
            ->and($comment->refresh()->status)->toBe($status);
    })->with([
        'approved' => CommentStatus::Approved,
        'rejected' => CommentStatus::Rejected,
        'spam' => CommentStatus::Spam,
    ]);

    it('fires nothing when re-entering the current status', function (): void {
        $comment = commentInStatus(CommentStatus::Approved);

        Event::fake([CommentApproved::class, CommentUpdated::class]);
        $comment->approve();

        Event::assertNotDispatched(CommentApproved::class);
        Event::assertNotDispatched(CommentUpdated::class);
    });

    /*
     * Exactly-once is the guarantee counts and notifications are built on, so
     * a halted write must not leave an event behind claiming it happened.
     */
    it('fires nothing when a host listener halts the write', function (): void {
        $comment = commentInStatus(CommentStatus::Pending);

        Comment::saving(fn (): bool => false);
        Event::fake([CommentApproved::class]);

        expect($comment->approve())->toBeFalse()
            ->and($comment->status)->toBe(CommentStatus::Pending)
            ->and(Comment::findOrFail($comment->getKey())->status)->toBe(CommentStatus::Pending);

        Event::assertNotDispatched(CommentApproved::class);
    });

    it('writes nothing when re-entering the current status', function (): void {
        $comment = commentInStatus(CommentStatus::Approved);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $comment->approve();

        expect($queries)->toBe(0);
    });
});

it('leaves replies alone: each comment carries its own status', function (): void {
    $comment = post()->commentAsGuest('Root', name: 'Jane', email: 'jane@example.com');
    $reply = $comment->replyAsGuest('A reply', name: 'John', email: 'john@example.com');

    $comment->approve();

    expect($comment->refresh()->status)->toBe(CommentStatus::Approved)
        ->and($reply->refresh()->status)->toBe(CommentStatus::Pending);
});

it('persists the transition for anyone reading the table', function (): void {
    $comment = commentInStatus(CommentStatus::Pending);

    $comment->markAsSpam(by: user('Grace Hopper'));

    expect(Comment::findOrFail($comment->getKey())->status)->toBe(CommentStatus::Spam);
});
