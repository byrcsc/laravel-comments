<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Notifications;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Support\ReplyNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody replied to a comment you wrote.
 *
 * The one notification this package ships, and it is off until the config says
 * otherwise. Replies are the single comment event with an unambiguous
 * recipient - the parent comment's author - which is why the package stops
 * here and leaves watchers, digests, and mentions to the events.
 *
 * Queued, so writing a comment never waits on mail. An application without
 * queues runs the sync driver and sends inline, which is the same behavior it
 * gets from every other queued notification it owns.
 *
 * The channel list comes from `comments.notifications.reply.channels`, so
 * database or Slack delivery needs no subclass. Extend this class and bind
 * yours over it in the container when you want to change the message itself:
 *
 *     $this->app->bind(CommentReplied::class, fn ($app, $params) => new MyReply(
 *         $params['reply'],
 *     ));
 *
 * There is no link in the mail. The package owns no routes, so it has no
 * honest URL to build; publish `comments-views` and add yours.
 */
class CommentReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Comment $reply)
    {
        // Dispatched after the caller's transaction commits, not inside it. A
        // comment written in a transaction that rolls back never happened, and
        // mail about a reply nobody can read is the one failure the
        // at-most-once marker cannot take back.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ReplyNotificationSettings::fromConfig()->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('comments::comments.reply.subject'))
            ->markdown('comments::mail.reply', [
                'reply' => $this->reply,
                'parent' => $this->reply->parent,
                'author' => $this->authorName(),
            ]);
    }

    /**
     * What the database channel stores. Keys rather than prose, so an
     * application rendering its own notification list is not parsing a
     * sentence back apart.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'comment_id' => $this->reply->parent_id,
            'reply_id' => $this->reply->getKey(),
            'commentable_type' => $this->reply->commentable_type,
            'commentable_id' => $this->reply->commentable_id,
        ];
    }

    /**
     * Who wrote the reply, as a name to put in a sentence, or null when there
     * is nothing trustworthy to use. A guest name is untrusted input like any
     * other body: the mail view escapes it, and nothing here pretends it was
     * verified.
     */
    protected function authorName(): ?string
    {
        $commentator = $this->reply->commentator;

        if ($commentator !== null) {
            /** @var mixed $name */
            $name = $commentator->getAttribute('name');

            return is_string($name) && trim($name) !== '' ? $name : null;
        }

        $guest = $this->reply->guest_name;

        return is_string($guest) && trim($guest) !== '' ? $guest : null;
    }
}
