<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class StringUser extends Model
{
    protected $table = 'string_users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
