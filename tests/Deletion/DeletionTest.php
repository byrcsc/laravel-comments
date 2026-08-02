<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Models\Comment;

it('keeps replies readable when their parent is soft deleted', function (): void {
    $post = post();
    $comment = $post->comment('I regret this take', by: user());
    $reply = $comment->reply('Too late, we saw it', by: user());

    $comment->delete();

    // The parent is a tombstone: gone from default queries, still there for
    // a UI that wants to render "comment deleted" above the surviving reply.
    expect($post->comments()->pluck('body')->all())->toBe(['Too late, we saw it'])
        ->and($post->comments()->withTrashed()->count())->toBe(2)
        ->and($reply->refresh()->trashed())->toBeFalse()
        ->and($reply->parent()->withTrashed()->first()?->trashed())->toBeTrue();
});

it('restores a soft deleted comment', function (): void {
    $comment = post()->comment('Actually, I stand by it', by: user());

    $comment->delete();
    $comment->restore();

    expect($comment->refresh()->trashed())->toBeFalse()
        ->and(Comment::query()->count())->toBe(1);
});

it('removes the whole subtree on force delete', function (): void {
    $post = post();
    $comment = $post->comment('Root', by: user());
    $reply = $comment->reply('Level 1', by: user());
    $reply->reply('Level 2', by: user());
    $comment->reply('Another level 1', by: user());
    $survivor = $post->comment('A separate thread', by: user());

    $comment->forceDelete();

    // The cascade runs in the database, so even soft-deleted descendants and
    // rows created outside this process go with the parent.
    expect(Comment::query()->withTrashed()->count())->toBe(1)
        ->and(Comment::query()->sole()->getKey())->toBe($survivor->getKey());
});

it('force deletes a mid-thread comment without touching its ancestors', function (): void {
    $comment = post()->comment('Root', by: user());
    $middle = $comment->reply('Middle', by: user());
    $middle->reply('Leaf', by: user());

    $middle->forceDelete();

    expect(Comment::query()->withTrashed()->pluck('body')->all())->toBe(['Root']);
});

it('removes soft deleted descendants with the force deleted subtree', function (): void {
    $comment = post()->comment('Root', by: user());
    $reply = $comment->reply('Reply', by: user());

    $reply->delete();
    $comment->forceDelete();

    expect(Comment::query()->withTrashed()->count())->toBe(0);
});
