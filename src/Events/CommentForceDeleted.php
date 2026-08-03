<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

use ByRcsc\LaravelComments\Models\Comment;

/**
 * A comment removed for good, and with it every reply below it. Fires for the
 * comment `forceDelete()` was called on; the database takes the rest of the
 * subtree through the parent foreign key and fires nothing of its own, so a
 * listener that must see every removed row walks the subtree before deleting.
 *
 * `$countableRemoved` is how many comments in that subtree were approved and
 * not soft deleted at the moment it went, this one included. It is the number
 * an aggregate has to subtract, and it cannot be recovered afterwards: by the
 * time this event fires, the rows are gone. The package's own count
 * maintenance is built on it, and an application keeping its own totals can
 * read the same number rather than recounting.
 */
final class CommentForceDeleted extends CommentEvent
{
    public function __construct(Comment $comment, public readonly int $countableRemoved = 0)
    {
        parent::__construct($comment);
    }
}
