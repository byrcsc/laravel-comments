<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Listeners;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentApproved;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Events\CommentDeleted;
use ByRcsc\LaravelComments\Events\CommentForceDeleted;
use ByRcsc\LaravelComments\Events\CommentMarkedAsSpam;
use ByRcsc\LaravelComments\Events\CommentModerated;
use ByRcsc\LaravelComments\Events\CommentRejected;
use ByRcsc\LaravelComments\Events\CommentRestored;
use ByRcsc\LaravelComments\Support\CommentCounts;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Keeps an opted-in commentable's comments count honest as comments arrive,
 * are moderated, and are removed.
 *
 * Every handler here is one atomic database step, and every step is tied to an
 * event that fires exactly once per real state change - which is the contract
 * the moderation transitions make. A transition event firing twice for one
 * change would corrupt the column, and that is the reason those transitions
 * are idempotent.
 *
 * A model that never opted in costs nothing: `CommentCounts` returns no column
 * and every handler stops there.
 */
final class MaintainsCommentCounts
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            CommentCreated::class => 'created',
            CommentDeleted::class => 'deleted',
            CommentRestored::class => 'restored',
            CommentForceDeleted::class => 'forceDeleted',
            CommentApproved::class => 'entersApprovedSet',
            CommentRejected::class => 'leavesApprovedSet',
            CommentMarkedAsSpam::class => 'leavesApprovedSet',
        ];
    }

    /**
     * A comment created straight into the approved set counts immediately; one
     * that arrives pending waits for the moderator.
     */
    public function created(CommentCreated $event): void
    {
        if (CommentCounts::isCountable($event->comment)) {
            CommentCounts::increment($event->comment);
        }
    }

    /**
     * Soft deleting takes an approved comment out of the set.
     *
     * `isCountable()` would say no here: the comment is already a tombstone by
     * the time this fires, and the question is what it was a moment ago.
     *
     * Eloquent fires this during a force delete too, where the whole subtree
     * is going and only the force-delete handler knows how much of it counted.
     * This one stands aside for that case rather than subtracting twice.
     */
    public function deleted(CommentDeleted $event): void
    {
        if ($event->comment->isForceDeleting()) {
            return;
        }

        if ($event->comment->status === CommentStatus::Approved) {
            CommentCounts::decrement($event->comment);
        }
    }

    public function restored(CommentRestored $event): void
    {
        if (CommentCounts::isCountable($event->comment)) {
            CommentCounts::increment($event->comment);
        }
    }

    /**
     * A force delete takes the comment's whole reply subtree with it through
     * the database's cascade, and those replies fire no events of their own -
     * so the event carries how many of them counted, read before the rows
     * went. Still one atomic step, just a larger one.
     */
    public function forceDeleted(CommentForceDeleted $event): void
    {
        CommentCounts::decrement($event->comment, $event->countableRemoved);
    }

    public function entersApprovedSet(CommentApproved $event): void
    {
        if (! $event->comment->trashed()) {
            CommentCounts::increment($event->comment);
        }
    }

    /**
     * Only a comment that was actually in the set leaves it: rejecting a
     * pending comment moves nothing, and a tombstone was already subtracted
     * when it was deleted.
     */
    public function leavesApprovedSet(CommentModerated $event): void
    {
        if ($event->previousStatus === CommentStatus::Approved && ! $event->comment->trashed()) {
            CommentCounts::decrement($event->comment);
        }
    }
}
