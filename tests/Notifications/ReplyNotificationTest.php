<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Notifications\CommentReplied;
use ByRcsc\LaravelComments\Tests\Stubs\CustomReplyNotification;
use ByRcsc\LaravelComments\Tests\Stubs\NotifiableUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;

function enableReplyNotifications(): void
{
    config()->set('comments.notifications.reply.enabled', true);
}

/**
 * A pending reply, so a test about approval has something to approve. Guest
 * comments start pending out of the box, which is the shortest honest way to
 * get one.
 */
function pendingReplyTo(Comment $parent): Comment
{
    return $parent->replyAsGuest('Pending reply', name: 'Jane', email: 'jane@example.com');
}

it('sends nothing when the config switch is off', function (): void {
    Notification::fake();

    $author = notifiableUser();
    $comment = post()->comment('The original', by: $author);
    $comment->reply('A reply', by: notifiableUser('Alan Turing'));

    Notification::assertNothingSent();
});

describe('when enabled', function (): void {
    beforeEach(fn () => enableReplyNotifications());

    it('notifies the parent comment\'s author', function (): void {
        Notification::fake();

        $author = notifiableUser();
        $comment = post()->comment('The original', by: $author);
        $reply = $comment->reply('A reply', by: notifiableUser('Alan Turing'));

        Notification::assertSentTo(
            $author,
            CommentReplied::class,
            fn (CommentReplied $notification): bool => $notification->reply->is($reply),
        );
    });

    it('notifies nobody else', function (): void {
        Notification::fake();

        $author = notifiableUser();
        $replier = notifiableUser('Alan Turing');
        $bystander = notifiableUser('Grace Hopper');

        $comment = post()->comment('The original', by: $author);
        $comment->reply('A reply', by: $replier);

        Notification::assertSentTimes(CommentReplied::class, 1);
        Notification::assertNotSentTo($replier, CommentReplied::class);
        Notification::assertNotSentTo($bystander, CommentReplied::class);
    });

    it('sends nothing for a top-level comment', function (): void {
        Notification::fake();

        post()->comment('Not a reply', by: notifiableUser());

        Notification::assertNothingSent();
    });

    describe('the approved set is the trigger', function (): void {
        it('notifies at once for a reply created approved', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $reply = post()->comment('The original', by: $author)
                ->reply('A reply', by: notifiableUser('Alan Turing'));

            expect($reply->status)->toBe(CommentStatus::Approved);

            Notification::assertSentTimes(CommentReplied::class, 1);
        });

        it('waits for approval when the reply arrives pending', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $reply = pendingReplyTo(post()->comment('The original', by: $author));

            Notification::assertNothingSent();

            $reply->approve();

            Notification::assertSentTo($author, CommentReplied::class);
        });

        it('never notifies for a rejected reply', function (): void {
            Notification::fake();

            $reply = pendingReplyTo(post()->comment('The original', by: notifiableUser()));

            $reply->reject();

            Notification::assertNothingSent();
        });

        it('never notifies for a reply marked as spam', function (): void {
            Notification::fake();

            $reply = pendingReplyTo(post()->comment('The original', by: notifiableUser()));

            $reply->markAsSpam();

            Notification::assertNothingSent();
        });

        it('sends nothing when a tombstone is approved', function (): void {
            Notification::fake();

            $reply = pendingReplyTo(post()->comment('The original', by: notifiableUser()));
            $reply->delete();

            $reply->approve();

            Notification::assertNothingSent();
        });
    });

    describe('at most once', function (): void {
        it('sends once across a full status round trip', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $reply = pendingReplyTo(post()->comment('The original', by: $author));

            $reply->approve();
            $reply->reject();
            $reply->approve();
            $reply->markAsSpam();
            $reply->approve();

            Notification::assertSentTimes(CommentReplied::class, 1);
        });

        it('remembers on the row rather than in memory', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $reply = pendingReplyTo(post()->comment('The original', by: $author));

            $reply->approve();
            $reply->reject();

            // A fresh instance knows nothing the column does not.
            $reloaded = Comment::query()->findOrFail($reply->getKey());
            $reloaded->approve();

            Notification::assertSentTimes(CommentReplied::class, 1);
        });

        it('stamps the marker only on the reply', function (): void {
            Notification::fake();

            $comment = post()->comment('The original', by: notifiableUser());
            $reply = $comment->reply('A reply', by: notifiableUser('Alan Turing'));

            expect($reply->fresh()?->reply_notified_at)->not->toBeNull()
                ->and($comment->fresh()?->reply_notified_at)->toBeNull();
        });

        it('records no revision and no edit for the marker', function (): void {
            Notification::fake();

            $comment = post()->comment('The original', by: notifiableUser());
            $reply = $comment->reply('A reply', by: notifiableUser('Alan Turing'));

            expect($reply->revisions()->count())->toBe(0)
                ->and($reply->fresh()?->edited_at)->toBeNull();
        });

        it('leaves the reply\'s own timestamps alone', function (): void {
            Notification::fake();

            $comment = post()->comment('The original', by: notifiableUser());
            $reply = $comment->reply('A reply', by: notifiableUser('Alan Turing'));

            $row = DB::table('comments')->where('id', $reply->id)->first();

            expect($row?->created_at)->toBe($row?->updated_at);
        });

        it('leaves the model in hand agreeing with its own row', function (): void {
            Notification::fake();

            $comment = post()->comment('The original', by: notifiableUser());
            $reply = $comment->reply('A reply', by: notifiableUser('Alan Turing'));

            expect($reply->reply_notified_at)->not->toBeNull()
                ->and($reply->isDirty())->toBeFalse();
        });

        it('rolls the marker back with the transaction that wrote the reply', function (): void {
            Notification::fake();

            $comment = post()->comment('The original', by: notifiableUser());
            $replier = notifiableUser('Alan Turing');

            try {
                DB::transaction(function () use ($comment, $replier): void {
                    $comment->reply('A reply', by: $replier);

                    throw new RuntimeException('The request failed after the write.');
                });
            } catch (RuntimeException) {
                // The assertions below are the point.
            }

            // The reply is gone and so is its marker, which is what makes the
            // notification's after-commit dispatch the whole guarantee: the
            // queue never hears about a reply that was rolled back.
            expect(Comment::query()->count())->toBe(1)
                ->and(Comment::query()->whereNotNull('reply_notified_at')->count())->toBe(0);
        });

        it('waits for the transaction to commit before queueing', function (): void {
            expect((new CommentReplied(new Comment))->afterCommit)->toBeTrue();
        });
    });

    describe('recipients', function (): void {
        it('never notifies a guest-authored parent', function (): void {
            Notification::fake();

            $post = post();
            $guestComment = $post->commentAsGuest('The original', name: 'Jane', email: 'jane@example.com');
            $guestComment->approve();

            $guestComment->reply('A reply', by: notifiableUser('Alan Turing'));

            Notification::assertNothingSent();
        });

        it('notifies a model author whatever the replier is', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $comment = post()->comment('The original', by: $author);

            $reply = pendingReplyTo($comment);
            $reply->approve();

            Notification::assertSentTo($author, CommentReplied::class);
        });

        it('never notifies a commentator that is not Notifiable', function (): void {
            Notification::fake();

            $author = user();
            $comment = post()->comment('The original', by: $author);
            $comment->reply('A reply', by: notifiableUser('Alan Turing'));

            Notification::assertNothingSent();
        });

        it('never notifies you about your own reply', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $comment = post()->comment('The original', by: $author);
            $comment->reply('Adding to my own point', by: $author);

            Notification::assertNothingSent();
        });

        it('notifies a different model of the same class', function (): void {
            Notification::fake();

            $author = notifiableUser();
            $other = notifiableUser('Alan Turing');
            $comment = post()->comment('The original', by: $author);

            $comment->reply('A reply', by: $other);

            Notification::assertSentTo($author, CommentReplied::class);
        });
    });

    describe('channels and delivery', function (): void {
        it('delivers over the configured channels', function (): void {
            config()->set('comments.notifications.reply.channels', ['database', 'mail']);

            Notification::fake();

            $author = notifiableUser();
            post()->comment('The original', by: $author)
                ->reply('A reply', by: notifiableUser('Alan Turing'));

            Notification::assertSentTo(
                $author,
                CommentReplied::class,
                fn (CommentReplied $notification, array $channels): bool => $channels === ['database', 'mail'],
            );
        });

        it('is queued, so writing a comment never waits on mail', function (): void {
            expect(new CommentReplied(new Comment))->toBeInstanceOf(ShouldQueue::class);
        });

        it('renders the mail body', function (): void {
            $author = notifiableUser();
            $reply = post()->comment('The original', by: $author)
                ->reply('A reply worth reading', by: notifiableUser('Alan Turing'));

            $mail = (new CommentReplied($reply))->toMail($author);

            $rendered = (string) $mail->render();

            expect($mail->subject)->toBe('Someone replied to your comment')
                ->and($rendered)->toContain('A reply worth reading')
                ->and($rendered)->toContain('The original')
                ->and($rendered)->toContain('Alan Turing');
        });

        it('names a guest replier by the name they gave', function (): void {
            $author = notifiableUser();
            $reply = pendingReplyTo(post()->comment('The original', by: $author));

            $rendered = (string) (new CommentReplied($reply))->toMail($author)->render();

            expect($rendered)->toContain('Jane replied to your comment.');
        });

        it('names nobody when the replier has no name to use', function (): void {
            $author = notifiableUser();
            $reply = post()->comment('The original', by: $author)
                ->replyAsGuest('A reply', name: '  ', email: 'jane@example.com');

            $rendered = (string) (new CommentReplied($reply))->toMail($author)->render();

            expect($rendered)->toContain('Someone replied to your comment.');
        });

        it('escapes what it renders', function (): void {
            $author = notifiableUser();
            $reply = post()->comment('The original', by: $author)
                ->replyAsGuest('<script>alert(1)</script>', name: 'Jane', email: 'jane@example.com');

            $rendered = (string) (new CommentReplied($reply))->toMail($author)->render();

            expect($rendered)->not->toContain('<script>alert(1)</script>');
        });

        it('reads its wording through the translator, so a locale changes it', function (): void {
            Lang::addLines([
                'comments.reply.subject' => 'Quelqu\'un a répondu à votre commentaire',
                'comments.reply.intro' => ':author a répondu à votre commentaire.',
            ], 'fr', 'comments');

            app()->setLocale('fr');

            $author = notifiableUser();
            $reply = post()->comment('The original', by: $author)
                ->reply('Une réponse', by: notifiableUser('Alan Turing'));

            $mail = (new CommentReplied($reply))->toMail($author);

            expect($mail->subject)->toBe('Quelqu\'un a répondu à votre commentaire')
                ->and((string) $mail->render())->toContain('Alan Turing a répondu à votre commentaire.');
        });

        it('carries keys rather than prose on the database channel', function (): void {
            $author = notifiableUser();
            $comment = post()->comment('The original', by: $author);
            $reply = $comment->reply('A reply', by: notifiableUser('Alan Turing'));

            expect((new CommentReplied($reply))->toArray($author))
                ->toBe([
                    'comment_id' => $comment->id,
                    'reply_id' => $reply->id,
                    'commentable_type' => $reply->commentable_type,
                    'commentable_id' => $reply->commentable_id,
                ]);
        });

        it('lets an application bind its own notification over the shipped one', function (): void {
            Notification::fake();

            app()->bind(
                CommentReplied::class,
                fn (mixed $app, array $params): CommentReplied => new CustomReplyNotification($params['reply']),
            );

            $author = notifiableUser();
            post()->comment('The original', by: $author)
                ->reply('A reply', by: notifiableUser('Alan Turing'));

            Notification::assertSentTo($author, CustomReplyNotification::class);
            Notification::assertSentTimes(CustomReplyNotification::class, 1);
        });
    });

    it('keeps firing the raw events regardless of the notification', function (): void {
        Notification::fake();
        Event::fake([CommentCreated::class]);

        post()->comment('The original', by: notifiableUser())
            ->reply('A reply', by: notifiableUser('Alan Turing'));

        Event::assertDispatchedTimes(CommentCreated::class, 2);
    });
});

it('keeps firing the raw events when the notification is off', function (): void {
    Event::fake([CommentCreated::class]);

    post()->comment('The original', by: notifiableUser())
        ->reply('A reply', by: notifiableUser('Alan Turing'));

    Event::assertDispatchedTimes(CommentCreated::class, 2);
});

it('leaves the marker null when nothing was sent', function (): void {
    $author = notifiableUser();
    $reply = post()->comment('The original', by: $author)
        ->reply('A reply', by: notifiableUser('Alan Turing'));

    expect($reply->fresh()?->reply_notified_at)->toBeNull();
});

it('never notifies a NotifiableUser that authored nothing here', function (): void {
    Notification::fake();
    enableReplyNotifications();

    $stranger = NotifiableUser::query()->create([
        'name' => 'Stranger',
        'email' => 'stranger@example.test',
    ]);

    $author = notifiableUser();
    post()->comment('The original', by: $author)
        ->reply('A reply', by: notifiableUser('Alan Turing'));

    Notification::assertNotSentTo($stranger, CommentReplied::class);
});
