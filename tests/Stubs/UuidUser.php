<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UuidUser extends Model
{
    use HasUuids;

    protected $table = 'uuid_users';

    protected $guarded = [];
}
