<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use ByRcsc\LaravelComments\Concerns\HasComments;
use Illuminate\Database\Eloquent\Model;

/**
 * What a host application's commentable model looks like: one trait, no
 * further wiring.
 */
final class Post extends Model
{
    use HasComments;

    protected $table = 'posts';

    protected $guarded = [];
}
