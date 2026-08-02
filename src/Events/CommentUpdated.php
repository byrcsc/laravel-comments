<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

/**
 * Any saved change to a comment: a body edit, a moderation transition, a pin,
 * a restore. Which one it was is on the comment itself, so a listener that
 * only cares about edits asks:
 *
 *     Event::listen(CommentUpdated::class, function (CommentUpdated $event) {
 *         if ($event->comment->wasChanged('body')) {
 *             $event->comment->status = CommentStatus::Pending;
 *             $event->comment->save();
 *         }
 *     });
 *
 * That is the re-moderation hook: an edited comment goes back into the queue.
 * Nothing returns a comment to `pending` for you - the transition methods only
 * move it out - because sending it back is the decision this listener is
 * making.
 *
 * The event fires after the revision the edit filed, so `revisions` already
 * holds the body to compare the new one against. The package never
 * re-moderates on its own, because "an approved comment was edited into an
 * advert" and "a typo was fixed" look identical from here, and only the
 * application can tell them apart.
 */
final class CommentUpdated extends CommentEvent {}
