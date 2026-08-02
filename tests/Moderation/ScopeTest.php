<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;

it('returns exactly the rows in each status', function (CommentStatus $status): void {
    $post = post();
    $comments = commentsInEveryStatus($post);

    $found = match ($status) {
        CommentStatus::Pending => $post->comments()->pending()->get(),
        CommentStatus::Approved => $post->comments()->approved()->get(),
        CommentStatus::Rejected => $post->comments()->rejected()->get(),
        CommentStatus::Spam => $post->comments()->spam()->get(),
    };

    expect($found)->toHaveCount(1)
        ->and($found->first()?->getKey())->toBe($comments[$status->value]->getKey());
})->with([
    'pending' => CommentStatus::Pending,
    'approved' => CommentStatus::Approved,
    'rejected' => CommentStatus::Rejected,
    'spam' => CommentStatus::Spam,
]);

it('scopes the model globally as well as through the relation', function (): void {
    commentsInEveryStatus(post());

    expect(Comment::approved()->count())->toBe(1)
        ->and(Comment::pending()->count())->toBe(1)
        ->and(Comment::query()->count())->toBe(4);
});

it('composes with the thread scopes the way a comment section reads', function (): void {
    $post = post();

    $visible = $post->comment('Visible root', by: user());
    $visible->reply('Visible reply', by: user());

    $held = $post->commentAsGuest('Held root', name: 'Jane', email: 'jane@example.com');
    $held->replyAsGuest('Held reply', name: 'John', email: 'john@example.com');

    $threads = $post->comments()->approved()->topLevel()->with('replies')->get();

    expect($threads)->toHaveCount(1)
        ->and($threads->first()?->getKey())->toBe($visible->getKey())
        ->and($held->refresh()->status)->toBe(CommentStatus::Pending);
});

it('leaves soft-deleted comments out, status notwithstanding', function (): void {
    $post = post();
    $comment = $post->comment('Approved then removed', by: user());

    $comment->delete();

    expect($post->comments()->approved()->count())->toBe(0)
        ->and($post->comments()->withTrashed()->approved()->count())->toBe(1);
});
