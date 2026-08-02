<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Enums;

/**
 * Package state, not visibility. The package records what a comment is -
 * pending, approved, rejected, or spam - and fires events on transitions;
 * deciding what a visitor sees stays in the application's queries.
 *
 * Transition methods, per-status defaults, and the per-status scopes arrive
 * with the moderation layer; the foundation stores every new comment as
 * approved.
 */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';
}
