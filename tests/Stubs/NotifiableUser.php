<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A commentator that can receive notifications. It shares the `users` table
 * with {@see User}: the difference under test is the trait, not the schema,
 * and `User` sitting beside it without one is what proves a commentator that
 * never said it could receive mail is never sent any.
 */
final class NotifiableUser extends Model
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
