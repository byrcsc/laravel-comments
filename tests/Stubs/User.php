<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
