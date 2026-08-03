<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Listeners;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentApproved;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Notifications\CommentReplied;
use ByRcsc\LaravelComments\Support\ReplyNotificationSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the author of a comment that somebody replied to it.
 *
 * The trigger is the reply entering the approved set, not its creation: a
 * reply that arrives approved notifies at once, one that arrives pending
 * notifies when a moderator approves it, and one that is rejected or marked as
 * spam never notifies at all. Nothing here decides what is visible - it reads
 * the same status the application's own queries do.
 *
 * At most once per reply, ever. The evidence is a column on the reply rather
 * than anything held in memory, so an approve, an edit that sends it back to
 * pending, and a second approval still make one notification.
 *
 * Nobody is notified unless `comments.notifications.reply.enabled` says so.
 * The events fire either way: an application's own listeners are never coupled
 * to this one's config.
 */
final class SendsReplyNotifications
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            CommentCreated::class => 'created',
            CommentApproved::class => 'approved',
        ];
    }

    public function created(CommentCreated $event): void
    {
        $this->notify($event->comment);
    }

    public function approved(CommentApproved $event): void
    {
        $this->notify($event->comment);
    }

    private function notify(Comment $reply): void
    {
        if (! ReplyNotificationSettings::fromConfig()->enabled) {
            return;
        }

        if ($reply->parent_id === null || $reply->reply_notified_at !== null) {
            return;
        }

        if ($reply->status !== CommentStatus::Approved || $reply->trashed()) {
            return;
        }

        $recipient = $this->recipientFor($reply);

        if ($recipient === null) {
            return;
        }

        $this->markNotified($reply);

        Notification::send($recipient, app(CommentReplied::class, ['reply' => $reply]));
    }

    /**
     * Written before the send, not after: the send is queued, so a failure
     * downstream is the queue's to retry, while a marker written afterwards
     * would be the one thing a crash could lose - and losing it means
     * notifying twice. Both roll back together if the caller's transaction
     * does, which is why the notification is dispatched after commit.
     *
     * Through the query builder rather than `saveQuietly()`, and for two
     * reasons. This runs inside the `created` event, where Eloquent has not
     * synced the original attributes yet, so a nested save would rewrite every
     * column and bump `updated_at` on a comment nobody edited. And one column
     * is all that is changing.
     */
    private function markNotified(Comment $reply): void
    {
        $stampedAt = $reply->freshTimestamp();

        $reply->newQueryWithoutScopes()
            ->getQuery()
            ->where($reply->getKeyName(), $reply->getKey())
            ->update(['reply_notified_at' => $stampedAt]);

        $reply->setAttribute('reply_notified_at', $stampedAt);
        $reply->syncOriginalAttribute('reply_notified_at');
    }

    /**
     * The parent comment's author, when there is one to notify.
     *
     * A guest-authored parent produces nobody: a guest email is unverified
     * input and not a mailbox this package will write to, whatever channel is
     * configured. A commentator model that is not `Notifiable` produces nobody
     * either - the package will not decide how to route mail for a model that
     * never said it could receive any. And replying to yourself notifies
     * nobody, because you already know.
     */
    private function recipientFor(Comment $reply): ?Model
    {
        $parent = $reply->parent;

        if ($parent === null || $parent->commentator_type === null) {
            return null;
        }

        $recipient = $parent->commentator;

        if ($recipient === null || ! in_array(Notifiable::class, class_uses_recursive($recipient), true)) {
            return null;
        }

        return $reply->isBy($recipient) ? null : $recipient;
    }
}
