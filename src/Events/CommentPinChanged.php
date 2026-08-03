<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * A pin that actually went up or actually came down. Pinning a pinned comment
 * changes nothing and fires nothing, so one of these means one real change -
 * which is what an activity feed or a notification downstream is built on.
 *
 * Pinning is independent of moderation: a pinned comment still carries
 * whatever status it had, and the two never move each other.
 *
 * Like every other transition here, each is dispatched under its own class
 * name - Laravel's dispatcher resolves listeners by interface but never by
 * parent class - and this base is what a handler treating them alike can
 * type-hint. See CommentModerated for the version that behavior was checked
 * against.
 *
 * The actor is whoever the caller passed, and null when nobody did: the
 * package never resolves an authenticated user for you.
 */
abstract class CommentPinChanged extends CommentEvent
{
    public function __construct(Comment $comment, public readonly ?Model $actor = null)
    {
        parent::__construct($comment);
    }
}
