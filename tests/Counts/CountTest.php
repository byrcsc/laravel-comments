<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentForceDeleted;
use ByRcsc\LaravelComments\Exceptions\CommentsCountNotEnabledException;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('keeps no count on a model that never opted in', function (): void {
    $post = post();

    $post->comment('Counted nowhere', by: user());

    expect((int) DB::table('posts')->where('id', $post->id)->value('comments_count'))->toBe(0);
});

it('counts a comment created straight into the approved set', function (): void {
    $post = countedPost();

    $post->comment('Approved on arrival', by: user());

    expect(storedCount($post))->toBe(1);
});

it('leaves a pending comment uncounted until it is approved', function (): void {
    $post = countedPost();

    $comment = $post->commentAsGuest('Waiting', name: 'Jane', email: 'jane@example.com');

    expect($comment->status)->toBe(CommentStatus::Pending)
        ->and(storedCount($post))->toBe(0);

    $comment->approve();

    expect(storedCount($post))->toBe(1);
});

it('walks the whole lifecycle', function (): void {
    $post = countedPost();

    $comment = $post->commentAsGuest('Waiting', name: 'Jane', email: 'jane@example.com');
    expect(storedCount($post))->toBe(0);

    $comment->approve();
    expect(storedCount($post))->toBe(1);

    $comment->reject();
    expect(storedCount($post))->toBe(0);

    $comment->approve();
    expect(storedCount($post))->toBe(1);

    $comment->delete();
    expect(storedCount($post))->toBe(0);

    $comment->restore();
    expect(storedCount($post))->toBe(1);

    $comment->forceDelete();
    expect(storedCount($post))->toBe(0);
});

it('counts approved comments only', function (): void {
    $post = countedPost();
    $author = user();

    $post->comment('Approved', by: $author);
    $post->comment('Spam', by: $author)->markAsSpam();
    $post->comment('Rejected', by: $author)->reject();
    $post->commentAsGuest('Pending', name: 'Jane', email: 'jane@example.com');

    expect(storedCount($post))->toBe(1);
});

it('counts replies alongside their parents', function (): void {
    $post = countedPost();
    $author = user();

    $comment = $post->comment('Parent', by: $author);
    $comment->reply('Child', by: $author);

    expect(storedCount($post))->toBe(2);
});

it('drops a whole subtree at once on force delete', function (): void {
    $post = countedPost();
    $author = user();

    $comment = $post->comment('Parent', by: $author);
    $reply = $comment->reply('Child', by: $author);
    $reply->reply('Grandchild', by: $author);
    $post->comment('Untouched', by: $author);

    expect(storedCount($post))->toBe(4);

    $comment->forceDelete();

    expect(storedCount($post))->toBe(1);
});

it('reports what a force delete removed from the approved set', function (): void {
    $post = countedPost();
    $author = user();

    $comment = $post->comment('Parent', by: $author);
    $reply = $comment->reply('Child', by: $author);
    $reply->reply('Grandchild', by: $author)->markAsSpam();
    $comment->reply('Another child', by: $author)->delete();

    Event::fake([CommentForceDeleted::class]);

    $comment->forceDelete();

    Event::assertDispatched(
        CommentForceDeleted::class,
        // Parent and one child; the spam one and the tombstone were never in
        // the set to begin with.
        fn (CommentForceDeleted $event): bool => $event->countableRemoved === 2,
    );
});

it('subtracts a force-deleted subtree without recomputing the total', function (): void {
    $post = countedPost();
    $author = user();

    $comment = $post->comment('Parent', by: $author);
    $comment->reply('Child', by: $author);
    $post->comment('Untouched', by: $author);

    // A count that already drifted high stays high by exactly the drift: the
    // handler steps the column rather than replacing it with a fresh total.
    DB::table('posts')->where('id', $post->id)->update(['comments_count' => 13]);

    $comment->forceDelete();

    expect(storedCount($post))->toBe(11);
});

it('adds up two approvals in the same request', function (): void {
    $post = countedPost();

    $first = $post->commentAsGuest('One', name: 'Jane', email: 'jane@example.com');
    $second = $post->commentAsGuest('Two', name: 'Jo', email: 'jo@example.com');

    $first->approve();
    $second->approve();

    expect(storedCount($post))->toBe(2);
});

describe('idempotency', function (): void {
    it('counts nothing extra when a transition moves nothing', function (): void {
        $post = countedPost();
        $comment = $post->comment('Approved on arrival', by: user());

        $comment->approve();
        $comment->approve();

        expect(storedCount($post))->toBe(1);
    });

    it('subtracts nothing for a comment that was never in the set', function (): void {
        $post = countedPost();
        $comment = $post->commentAsGuest('Waiting', name: 'Jane', email: 'jane@example.com');

        $comment->reject();
        $comment->markAsSpam();

        expect(storedCount($post))->toBe(0);
    });

    it('never falls below zero', function (): void {
        $post = countedPost();
        $comment = $post->commentAsGuest('Waiting', name: 'Jane', email: 'jane@example.com');

        // The column starts honest at zero; a transition out of a set the
        // comment was never in must not push it negative.
        $comment->reject();

        expect(storedCount($post))->toBe(0);
    });
});

describe('tombstones', function (): void {
    it('counts nothing when a tombstone is approved', function (): void {
        $post = countedPost();
        $comment = $post->commentAsGuest('Waiting', name: 'Jane', email: 'jane@example.com');
        $comment->delete();

        $comment->approve();

        expect(storedCount($post))->toBe(0);
    });

    it('subtracts nothing when a tombstone leaves the approved set', function (): void {
        $post = countedPost();
        $comment = $post->comment('Approved on arrival', by: user());
        $comment->delete();

        expect(storedCount($post))->toBe(0);

        $comment->markAsSpam();

        expect(storedCount($post))->toBe(0);
    });

    it('restores a comment into the set it was in', function (): void {
        $post = countedPost();
        $comment = $post->commentAsGuest('Waiting', name: 'Jane', email: 'jane@example.com');
        $comment->delete();
        $comment->restore();

        expect(storedCount($post))->toBe(0);
    });
});

describe('recountComments()', function (): void {
    it('repairs a count corrupted behind the package\'s back', function (): void {
        $post = countedPost();
        $post->comment('Approved on arrival', by: user());

        DB::table('posts')->where('id', $post->id)->update(['comments_count' => 99]);

        expect($post->recountComments())->toBe(1)
            ->and(storedCount($post))->toBe(1);
    });

    it('brings the in-memory attribute along', function (): void {
        $post = countedPost();
        $post->comment('Approved on arrival', by: user());

        DB::table('posts')->where('id', $post->id)->update(['comments_count' => 99]);

        $post->recountComments();

        expect($post->getAttribute('comments_count'))->toBe(1)
            ->and($post->isDirty())->toBeFalse();
    });

    it('refuses a model that keeps no count', function (): void {
        expect(fn () => post()->recountComments())
            ->toThrow(CommentsCountNotEnabledException::class);
    });

    it('leaves the timestamps alone', function (): void {
        $post = countedPost();
        $updatedAt = DB::table('posts')->where('id', $post->id)->value('updated_at');

        $post->comment('Approved on arrival', by: user());
        $post->recountComments();

        expect(DB::table('posts')->where('id', $post->id)->value('updated_at'))->toBe($updatedAt);
    });
});

it('counts each commentable separately', function (): void {
    $first = countedPost();
    $second = countedPost();
    $author = user();

    $first->comment('One', by: $author);
    $second->comment('One', by: $author);
    $second->comment('Two', by: $author);

    expect(storedCount($first))->toBe(1)
        ->and(storedCount($second))->toBe(2);
});

it('counts comments the factory writes too', function (): void {
    $post = countedPost();

    Comment::factory()->forCommentable($post)->approved()->create();

    expect(storedCount($post))->toBe(1);
});
