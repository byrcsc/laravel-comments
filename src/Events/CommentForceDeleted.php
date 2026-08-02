<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

/**
 * Fires for the comment `forceDelete()` was called on. Descendant replies are
 * removed by the database through the parent foreign key, so no event fires
 * for them - a listener that must see every removed row should walk the
 * subtree before deleting.
 */
final class CommentForceDeleted extends CommentEvent {}
