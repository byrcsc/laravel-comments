<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

use ByRcsc\LaravelComments\Models\Comment;

/**
 * Every event this package fires carries the comment it happened to, so a
 * listener that only cares which record moved can type-hint the base class
 * and be done. Everything else - the thread, the commentable, the commentator
 * - is derivable from the comment itself.
 *
 * These are dispatched through Eloquent's own model events, so they fire
 * inside whatever transaction caused them. A listener that does anything slow
 * or external should be queued, and a queued listener should dispatch after
 * commit - the comment it was handed may still be rolled back.
 */
abstract class CommentEvent
{
    public function __construct(public readonly Comment $comment) {}
}
