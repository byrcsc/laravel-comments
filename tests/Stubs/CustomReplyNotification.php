<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use ByRcsc\LaravelComments\Notifications\CommentReplied;

/**
 * What replacing the shipped notification looks like: extend it, bind yours
 * over it in the container, and the package sends yours instead.
 */
final class CustomReplyNotification extends CommentReplied {}
