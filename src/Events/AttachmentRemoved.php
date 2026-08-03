<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Events;

/**
 * Fired when `detach()` removes a row, and once per attachment when a comment
 * is force deleted - dispatched before the database's cascade runs, so the
 * disk and path are still readable from the model the event carries.
 *
 * The force-delete sweep covers the whole subtree that cascade takes, replies
 * and their tombstones included, because a listener that never heard about a
 * reply's files would leave them on the disk forever. The comment the event
 * carries is the one that held the attachment, not necessarily the one the
 * caller deleted.
 */
final class AttachmentRemoved extends CommentAttachmentChanged {}
