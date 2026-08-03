<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Listeners;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Events\CommentDeleted;
use ByRcsc\LaravelComments\Events\CommentForceDeleted;
use ByRcsc\LaravelComments\Events\CommentRestored;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Support\CommentCounts;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Keeps an opted-in commentable's comments count honest as comments arrive,
 * are moderated, and are removed.
 *
 * Every handler here is one atomic database step, and every step is tied to an
 * event that fires exactly once per real state change. Status changes hang off
 * the update rather than off the transition events, so `approve()` and a plain
 * attribute save are counted the same way and counted once.
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
            CommentUpdated::class => 'updated',
            CommentDeleted::class => 'deleted',
            CommentRestored::class => 'restored',
            CommentForceDeleted::class => 'forceDeleted',
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

    /**
     * Every status change, however it was made.
     *
     * Hanging this on the update rather than on the transition events is
     * deliberate: `approve()` and a plain `$comment->status = ...; save()`
     * both land here, and an application that moves a status by hand - the
     * documented re-moderation pattern does exactly that - would otherwise
     * leave the column behind. One update, one event, one step.
     *
     * The status a comment moved from is read off the model rather than off an
     * event, because this fires from Eloquent's own `updated` hook, where the
     * original attributes have not been synced yet. A tombstone is skipped
     * both ways: it was already subtracted when it was deleted.
     *
     * A restore is skipped too, even though it is an update and even though it
     * may carry a status change: `restore()` writes both columns in one save
     * and then fires its own event, which counts the comment as it now stands.
     * Counting here as well would count it twice.
     */
    public function updated(CommentUpdated $event): void
    {
        $comment = $event->comment;

        if (! $comment->wasChanged('status') || $comment->trashed()) {
            return;
        }

        if ($comment->wasChanged($comment->getDeletedAtColumn())) {
            return;
        }

        /** @var mixed $previous */
        $previous = $comment->getOriginal('status');

        $wasCountable = $previous === CommentStatus::Approved;
        $isCountable = $comment->status === CommentStatus::Approved;

        if ($isCountable && ! $wasCountable) {
            CommentCounts::increment($comment);
        }

        if ($wasCountable && ! $isCountable) {
            CommentCounts::decrement($comment);
        }
    }
}
