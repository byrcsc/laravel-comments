<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Policies\CommentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * What overriding a single ability looks like: one method, no copied class.
 * Everything else keeps the shipped defaults.
 */
final class ModeratorCommentPolicy extends CommentPolicy
{
    public function approve(?Model $actor, Comment $comment): bool
    {
        return $actor?->getAttribute('is_moderator') === true;
    }
}
