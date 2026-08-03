<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests\Stubs;

use ByRcsc\LaravelComments\Concerns\HasComments;
use Illuminate\Database\Eloquent\Model;

/**
 * A commentable that keeps a denormalized count. It shares the `posts` table
 * with {@see Post}: the difference under test is the opt-in, not the schema,
 * and `Post` sitting on the same column while keeping no count is what proves
 * the default is off.
 */
final class CountedPost extends Model
{
    use HasComments;

    protected $table = 'posts';

    protected $guarded = [];

    /**
     * A narrower return type than the trait's, which is allowed and says
     * something true: this model always counts.
     */
    public function commentsCountColumn(): string
    {
        return 'comments_count';
    }
}
