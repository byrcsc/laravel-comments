<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class UlidUser extends Model
{
    use HasUlids;

    protected $table = 'ulid_users';

    protected $guarded = [];
}
