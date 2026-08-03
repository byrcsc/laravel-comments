<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The README's quick start, run against the workbench's own models.
 *
 * Every line below is copied from the README, not paraphrased from it. It is
 * the first thing anybody pastes into their application, and a package whose
 * opening example does not run is out of ideas before it starts. When the
 * README changes, this changes with it - or the README was wrong.
 *
 * The workbench models are the point: they are what a host application's
 * models look like, one trait and no further wiring, rather than a stub built
 * to make a test pass.
 */
it('runs the README quick start end to end', function (): void {
    $post = Post::query()->create(['title' => 'Announcing laravel-comments']);
    $user = User::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'password' => 'password']);
    $teammate = User::query()->create(['name' => 'Alan Turing', 'email' => 'alan@example.test', 'password' => 'password']);
    $moderator = User::query()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'password' => 'password']);

    // Write a comment, reply to it, react to it:
    $comment = $post->comment('Great write-up!', by: $user);

    $reply = $comment->reply('Agreed, especially the last section.', by: $teammate);

    $comment->react('👍', by: $teammate);

    // Guest comments carry a name and an email instead of a model, and start
    // out `pending` by default:
    $guest = $post->commentAsGuest(
        'Where can I download the slides?',
        name: 'Jane',
        email: 'jane@example.com',
    );

    $guest->approve(by: $moderator);

    // Read a thread the way you would read any relation:
    $threads = $post->comments()->approved()->topLevel()->with('replies')->get();

    expect($comment->body)->toBe('Great write-up!')
        ->and($reply->parent_id)->toBe($comment->id)
        ->and($comment->reactionSummary())->toBe(['👍' => 1])
        ->and($guest->status)->toBe(CommentStatus::Approved)
        ->and($threads)->toHaveCount(2)
        ->and($threads->firstWhere('id', $comment->id)?->replies)->toHaveCount(1);
});

it('makes a model commentable with the trait alone', function (): void {
    expect(method_exists(Post::class, 'comments'))->toBeTrue()
        ->and(method_exists(Post::class, 'comment'))->toBeTrue()
        ->and(method_exists(Post::class, 'commentAsGuest'))->toBeTrue();
});
