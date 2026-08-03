<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Configures the demo app.
 *
 * This stands in for the `config/comments.php` edits and event wiring a real
 * application would do when installing the package. The demo runs on the
 * shipped config defaults; what it does add is the one listener the package
 * deliberately refuses to ship, so that every integration point a reader
 * needs is in one file they can scan.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->reModerateEditedComments();
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
