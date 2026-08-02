<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

use Exception;

/**
 * Catch this to handle anything comments-related; catch a subclass to react to
 * a specific failure. Abstract on purpose: every throw site names what broke.
 */
abstract class CommentsException extends Exception {}
