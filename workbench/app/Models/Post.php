<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelComments\Concerns\HasComments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\PostFactory;

/**
 * The demo app's commentable: one trait, no further wiring. This is the whole
 * integration a host application performs.
 */
final class Post extends Model
{
    use HasComments;

    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
