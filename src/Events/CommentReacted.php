<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * A reaction that actually appeared or actually went away. Reacting twice with
 * the same reaction is a no-op and toggling back off is a removal, so one of
 * these means one real change to what the comment carries.
 *
 * The reactor is always a model: there is no guest path to react through. The
 * reaction is the string as stored, which is the string your interface sent.
 *
 * Like every other transition here, the two are dispatched under their own
 * class names - Laravel's dispatcher resolves listeners by interface but never
 * by parent class - and this base is what a handler treating them alike can
 * type-hint.
 */
abstract class CommentReacted extends CommentEvent
{
    public function __construct(
        Comment $comment,
        public readonly Model $reactor,
        public readonly string $reaction,
    ) {
        parent::__construct($comment);
    }
}
