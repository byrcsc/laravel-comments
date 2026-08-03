<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * A second commentator class over the `users` table, so a `User` and an
 * `Admin` can share a primary key. Ownership is matched through the whole
 * morph, not the key alone, and this is what proves it.
 */
final class Admin extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
