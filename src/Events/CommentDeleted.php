<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

/**
 * Fires on soft and force deletes alike, mirroring Eloquent's own `deleted`
 * event. A force delete additionally fires CommentForceDeleted; listen to
 * that one when only permanent removal matters.
 */
final class CommentDeleted extends CommentEvent {}
