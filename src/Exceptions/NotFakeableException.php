<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Exceptions;

/**
 * `Comments::fake()` records what an application asked the engine to write.
 * Writing a comment, replying to one, and reacting to one are the three things
 * it records - which is the whole of what an application's own test usually
 * asks the engine for.
 *
 * Everything else is refused rather than allowed through, because a faked
 * comment is not a row: moderating, editing, pinning, attaching to, or
 * deleting one would write against a key no table has, and would fail later,
 * further away, and less clearly than this.
 *
 * A test about those is a test about this package, and it wants a real
 * database. Drop the fake for that case.
 */
final class NotFakeableException extends CommentsException
{
    public static function for(string $operation): self
    {
        return new self(
            "Cannot {$operation} while Comments::fake() is recording: a faked comment is not a row. The fake records writing a comment, replying, and reacting; test anything else against a real database."
        );
    }
}
