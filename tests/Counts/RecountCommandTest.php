<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Tests\Stubs\CountedPost;
use ByRcsc\LaravelComments\Tests\Stubs\Post;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The command's whole job is repairing what model events could not see, so
 * every test here corrupts the column through the query builder first - the
 * same way an upsert or a restored dump would.
 */
function corruptCount(CountedPost $post, int $to): void
{
    DB::table('posts')->where('id', $post->id)->update(['comments_count' => $to]);
}

it('repairs a drifted count', function (): void {
    $post = countedPost();
    $post->comment('Approved on arrival', by: user());

    corruptCount($post, 99);

    $this->artisan('comments:recount')->assertSuccessful();

    expect(storedCount($post))->toBe(1);
});

it('reports what it changed', function (): void {
    $post = countedPost();
    $post->comment('Approved on arrival', by: user());

    corruptCount($post, 99);

    $this->artisan('comments:recount')
        ->expectsOutputToContain('99 → 1')
        ->expectsOutputToContain('Repaired 1 count(s).')
        ->assertSuccessful();
});

it('says so when everything is already correct', function (): void {
    $post = countedPost();
    $post->comment('Approved on arrival', by: user());

    $this->artisan('comments:recount')
        ->expectsOutputToContain('Every count was already correct.')
        ->assertSuccessful();
});

it('zeroes a count on a record whose comments all went away', function (): void {
    $stale = countedPost();
    $neighbour = countedPost();

    // The type still appears in the comments table, which is how a sweep with
    // no filter finds it; the record under test has nothing left to count.
    $neighbour->comment('Elsewhere on the same type', by: user());

    corruptCount($stale, 7);

    $this->artisan('comments:recount')->assertSuccessful();

    expect(storedCount($stale))->toBe(0);
});

it('zeroes a type the comments table no longer mentions, when named', function (): void {
    $post = countedPost();

    corruptCount($post, 7);

    $this->artisan('comments:recount', ['--model' => CountedPost::class])->assertSuccessful();

    expect(storedCount($post))->toBe(0);
});

it('finds a type through the morph map when no comment names it', function (): void {
    Relation::morphMap(['counted_post' => CountedPost::class]);

    $post = countedPost();

    corruptCount($post, 7);

    $this->artisan('comments:recount')->assertSuccessful();

    expect(storedCount($post))->toBe(0);
});

it('has nothing to do on an empty comments table', function (): void {
    $this->artisan('comments:recount')
        ->expectsOutputToContain('Every count was already correct.')
        ->assertSuccessful();
});

it('finds a type whose comments are all tombstones', function (): void {
    $post = countedPost();
    $post->comment('Soft deleted', by: user())->delete();

    corruptCount($post, 7);

    $this->artisan('comments:recount')->assertSuccessful();

    expect(storedCount($post))->toBe(0);
});

describe('filters', function (): void {
    it('recounts one model type', function (): void {
        $post = countedPost();
        $post->comment('Approved on arrival', by: user());

        corruptCount($post, 99);

        $this->artisan('comments:recount', ['--model' => CountedPost::class])->assertSuccessful();

        expect(storedCount($post))->toBe(1);
    });

    it('recounts a single record and leaves its neighbours alone', function (): void {
        $target = countedPost();
        $neighbour = countedPost();
        $author = user();

        $target->comment('One', by: $author);
        $neighbour->comment('One', by: $author);

        corruptCount($target, 99);
        corruptCount($neighbour, 99);

        $this->artisan('comments:recount', [
            '--model' => CountedPost::class,
            '--id' => (string) $target->id,
        ])->assertSuccessful();

        expect(storedCount($target))->toBe(1)
            ->and(storedCount($neighbour))->toBe(99);
    });

    it('refuses an id without a model', function (): void {
        $this->artisan('comments:recount', ['--id' => '1'])
            ->expectsOutputToContain('--id needs --model')
            ->assertFailed();
    });

    it('refuses a model it cannot resolve', function (): void {
        $this->artisan('comments:recount', ['--model' => 'App\\Models\\Nope'])
            ->expectsOutputToContain('Cannot resolve')
            ->assertFailed();
    });

    it('refuses a model that keeps no count', function (): void {
        $this->artisan('comments:recount', ['--model' => Post::class])
            ->expectsOutputToContain('keeps no comments count')
            ->assertFailed();
    });

    it('skips a type that keeps no count when sweeping everything', function (): void {
        $uncounted = post();
        $counted = countedPost();

        $uncounted->comment('Uncounted', by: user());
        $counted->comment('Counted', by: user());

        corruptCount($counted, 99);

        $this->artisan('comments:recount')->assertSuccessful();

        expect(storedCount($counted))->toBe(1);
    });
});

describe('--dry-run', function (): void {
    it('reports without writing', function (): void {
        $post = countedPost();
        $post->comment('Approved on arrival', by: user());

        corruptCount($post, 99);

        $this->artisan('comments:recount', ['--dry-run' => true])
            ->expectsOutputToContain('99 → 1')
            ->expectsOutputToContain('1 count(s) would change.')
            ->assertSuccessful();

        expect(storedCount($post))->toBe(99);
    });
});

it('counts only approved, non-deleted comments', function (): void {
    $post = countedPost();
    $author = user();

    $post->comment('Approved', by: $author);
    $post->comment('Spam', by: $author)->markAsSpam();
    $post->comment('Deleted', by: $author)->delete();

    corruptCount($post, 0);

    $this->artisan('comments:recount')->assertSuccessful();

    expect(storedCount($post))->toBe(1);
});
