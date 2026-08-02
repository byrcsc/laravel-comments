<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Workbench\Database\Factories\UserFactory;

/**
 * The demo app's commentator. Commentators are just Eloquent models to the
 * package - it stores a morph key and has no user model of its own, so
 * admins, bots, or anything else could stand here equally well.
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
