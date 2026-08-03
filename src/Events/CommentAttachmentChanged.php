<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;

/**
 * An attachment row that actually appeared or actually went away.
 *
 * These are the file-cleanup hook. The package never deletes bytes from a
 * disk, so an application that wants a removed attachment's file gone deletes
 * it from a listener on {@see AttachmentRemoved} - where the row is still in
 * hand and its disk and path can still be read.
 *
 * Like every other transition here, each is dispatched under its own class
 * name - Laravel's dispatcher resolves listeners by interface but never by
 * parent class - and this base is what a handler treating them alike can
 * type-hint. See CommentModerated for the version that behavior was checked
 * against.
 */
abstract class CommentAttachmentChanged extends CommentEvent
{
    public function __construct(
        Comment $comment,
        public readonly CommentAttachment $attachment,
    ) {
        parent::__construct($comment);
    }
}
