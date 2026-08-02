<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * A moderation transition that actually happened. Re-entering the status a
 * comment already holds changes nothing and fires nothing, so one of these
 * means one real state change - which is what counts and notifications
 * downstream are built on.
 *
 * Each transition is dispatched under its own class name, because Laravel's
 * dispatcher resolves listeners by interface but never by parent class - true
 * of Laravel 12 and 13; recheck when raising the supported range. This base is
 * what a handler that treats them alike can type-hint:
 *
 *     Event::listen(
 *         [CommentApproved::class, CommentRejected::class, CommentMarkedAsSpam::class],
 *         fn (CommentModerated $event) => $this->reindex($event->comment),
 *     );
 *
 * The actor is whoever the caller passed to the transition method, and null
 * when nobody did: the package never resolves an authenticated user for you,
 * because a console command, a queued job, and a spam service are all valid
 * moderators with nothing in `auth()` to find.
 */
abstract class CommentModerated extends CommentEvent
{
    public function __construct(Comment $comment, public readonly ?Model $actor = null)
    {
        parent::__construct($comment);
    }
}
