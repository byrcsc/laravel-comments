<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * A policy that refuses everything, registered so the engine has the harshest
 * possible thing to ignore. The package's own methods never consult a policy,
 * and this is what proves it rather than a comment saying so.
 */
final class DenyEverythingPolicy
{
    public function before(?Model $actor, string $ability): bool
    {
        return false;
    }
}
