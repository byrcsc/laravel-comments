<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\AttachmentRemoved;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Policies\CommentPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Configures the demo app.
 *
 * This stands in for the `config/comments.php` edits and wiring a real
 * application would do when installing the package, so that every integration
 * point a reader needs is in one file they can scan: the one config key the
 * demo changes, the one-line policy registration, the re-moderation listener
 * the package deliberately refuses to ship, and the file cleanup that belongs
 * on the application's side of the storage boundary.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->enableReplyNotifications();
        $this->registerCommentPolicy();
        $this->reModerateEditedComments();
        $this->cleanUpRemovedAttachments();
    }

    /**
     * The one config edit the demo makes: notifications are off in the shipped
     * defaults, and a demo that never delivers one would not be exercising the
     * seam. The queue connection is `sync`, so the mail is rendered inline -
     * `MAIL_MAILER=log` keeps it out of anybody's inbox.
     */
    private function enableReplyNotifications(): void
    {
        config()->set('comments.notifications.reply.enabled', true);
    }

    /**
     * One line, exactly as the README documents it. The package registers
     * nothing; without this call every ability would fall through to the
     * framework's own default.
     */
    private function registerCommentPolicy(): void
    {
        Gate::policy(Comment::class, CommentPolicy::class);
    }

    /**
     * Where deleting the bytes belongs. The package records metadata and never
     * touches a disk, so an application that wants the file gone deletes it
     * from here, while the row is still readable.
     */
    private function cleanUpRemovedAttachments(): void
    {
        Event::listen(AttachmentRemoved::class, function (AttachmentRemoved $event): void {
            Storage::disk($event->attachment->disk)->delete($event->attachment->path);
        });
    }

    /**
     * The documented re-moderation pattern: an approved comment that gets
     * edited goes back into the queue. The package will not do this for you,
     * because a fixed typo and an approved comment edited into an advert look
     * identical from here - and the revision the edit just filed is what a
     * moderator compares against.
     */
    private function reModerateEditedComments(): void
    {
        Event::listen(CommentUpdated::class, function (CommentUpdated $event): void {
            $comment = $event->comment;

            if ($comment->wasChanged('body') && $comment->status === CommentStatus::Approved) {
                $comment->status = CommentStatus::Pending;
                $comment->save();
            }
        });
    }
}
