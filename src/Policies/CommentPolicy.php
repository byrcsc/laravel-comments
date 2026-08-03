<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Policies;

use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * The authorization boilerplate every installing application would otherwise
 * write: authors may edit and delete what they wrote, and nobody moderates
 * until you say who does.
 *
 * **This is never registered for you.** The service provider defines no gates
 * and registers no policies, because the engine's own methods never authorize
 * anything - `approve()`, `react()`, `pin()`, and the rest work in a queued
 * job, a seeder, and a console command, where there is no authenticated actor
 * to ask about. Enforcement belongs where your application calls the engine:
 *
 *     // A service provider
 *     Gate::policy(Comment::class, CommentPolicy::class);
 *
 *     // A controller
 *     $this->authorize('approve', $comment);
 *     $comment->approve(by: $request->user());
 *
 * Override one ability by extending this class and registering yours instead;
 * everything you do not override keeps the defaults below:
 *
 *     final class AppCommentPolicy extends CommentPolicy
 *     {
 *         public function approve(?Model $actor, Comment $comment): bool
 *         {
 *             return $actor?->getAttribute('is_moderator') === true;
 *         }
 *     }
 *
 * The actor is nullable throughout, so an unauthenticated visitor reaches
 * these methods rather than being refused before them - which is what lets
 * `create` be the one place that decides whether guests may write at all.
 *
 * There is no `view` or `viewAny` here. Visibility is a query, not an ability:
 * the `approved()` scope is what decides what a visitor reads.
 *
 * No roles, teams, permission packages, or tenancy. Those are your
 * application's, and this class never grows to know about them.
 */
class CommentPolicy
{
    /**
     * Writing a comment needs an identity. An application that accepts guest
     * comments overrides this to return true and does its own rate limiting -
     * which is the check that actually matters for anonymous writes, and one
     * this package deliberately does not ship.
     */
    public function create(?Model $actor): bool
    {
        return $actor !== null;
    }

    /**
     * Authors edit their own. A guest-authored comment matches no actor, so
     * this denies for every one of them: a later visitor claiming to be Jane
     * has nothing to prove it with.
     */
    public function update(?Model $actor, Comment $comment): bool
    {
        return $this->owns($actor, $comment);
    }

    public function delete(?Model $actor, Comment $comment): bool
    {
        return $this->owns($actor, $comment);
    }

    /**
     * Restoring is a moderator's undo, not an author's. A comment removed for
     * a reason should not come back because the person who wrote it wants it
     * to.
     */
    public function restore(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    /**
     * Force deleting destroys a comment, its replies, its reactions, its
     * revisions, and its attachment rows. Nothing about that should be
     * available by default.
     */
    public function forceDelete(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    public function approve(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    public function reject(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    public function markAsSpam(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    public function pin(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    public function unpin(?Model $actor, Comment $comment): bool
    {
        return false;
    }

    /**
     * Reacting needs an identity for the same reason the engine does: a
     * reaction is deduplicated by who left it, and a guest is not a who.
     */
    public function react(?Model $actor, Comment $comment): bool
    {
        return $actor !== null;
    }

    /**
     * Attaching to somebody else's comment is allowed by default, because a
     * moderator adding evidence to a reported comment is as ordinary as an
     * author adding a screenshot to their own. Narrow it to authors by
     * overriding with `$this->owns($actor, $comment)`.
     */
    public function attach(?Model $actor, Comment $comment): bool
    {
        return $actor !== null;
    }

    /**
     * Ownership through the commentator morph, which is the only identity a
     * comment carries - the comment answers that question about itself, and
     * this is only the ability that asks it.
     */
    protected function owns(?Model $actor, Comment $comment): bool
    {
        return $actor !== null && $comment->isBy($actor);
    }
}
