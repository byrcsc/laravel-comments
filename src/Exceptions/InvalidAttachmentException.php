<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;

/**
 * The metadata itself is the problem: a blank path or name, a negative size,
 * or an attachment handed to a comment that does not hold it. The package
 * validates that the row it is asked to write says
 * something - not that the file it describes exists. Whether the bytes are
 * really on that disk is the application's to know, because the application is
 * what put them there.
 */
final class InvalidAttachmentException extends CommentsException
{
    public static function blank(string $field): self
    {
        return new self(
            "An attachment's {$field} cannot be blank. Pass the value your application stored the file under."
        );
    }

    public static function negativeSize(int $size): self
    {
        return new self(
            "An attachment's size cannot be negative; got {$size}. Pass the byte count, or null when you did not measure it."
        );
    }

    public static function notOnComment(CommentAttachment $attachment, Comment $comment): self
    {
        return new self(
            "Attachment {$attachment->id} belongs to comment {$attachment->comment_id}, not to comment {$comment->id}. Detach it from the comment that holds it."
        );
    }
}
