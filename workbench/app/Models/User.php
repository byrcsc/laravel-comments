<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

/**
 * The demo app's commentator. Commentators are just Eloquent models to the
 * package - it stores a morph key and has no user model of its own, so
 * admins, bots, or anything else could stand here equally well.
 *
 * `Notifiable` is here for the same reason a real application's user model has
 * it, and the reply notification will not deliver without it: the package
 * refuses to guess at how to route mail for a model that never said it could
 * receive any. The framework's base user class does not add it.
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected $guarded = [];

    /** @var array<int, string> */
    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
