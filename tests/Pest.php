<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Tests\Stubs\Post;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use ByRcsc\LaravelComments\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

uses(TestCase::class)->in(__DIR__);

function user(string $name = 'Ada Lovelace'): User
{
    return User::create([
        'name' => $name,
        'email' => str_replace(' ', '.', strtolower($name)).'.'.uniqid().'@example.test',
    ]);
}

function post(string $title = 'A post worth discussing'): Post
{
    return Post::create(['title' => $title]);
}

/**
 * Move a comment to a status through the public transition methods, so a test
 * that only needs a starting point still gets there the way an application
 * would. Pending is nobody's destination: no method returns a comment to the
 * queue, and setting the column by hand would not be the seam under test.
 *
 * Returns whether the status moved, or null when there was nothing to call.
 */
function moveCommentTo(Comment $comment, CommentStatus $status, ?Model $by = null): ?bool
{
    return match ($status) {
        CommentStatus::Pending => null,
        CommentStatus::Approved => $comment->approve($by),
        CommentStatus::Rejected => $comment->reject($by),
        CommentStatus::Spam => $comment->markAsSpam($by),
    };
}

/**
 * A comment in a named status, written the way an application would and then
 * moved there, so the starting point of a moderation test is real state.
 */
function commentInStatus(CommentStatus $status): Comment
{
    $comment = post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com');

    moveCommentTo($comment, $status);

    return $comment;
}

/**
 * One comment per status on one commentable, keyed by status value, so a
 * scope under test has both a row to find and rows it must leave alone.
 *
 * @return array<string, Comment>
 */
function commentsInEveryStatus(Post $post): array
{
    $comments = [];

    foreach (CommentStatus::cases() as $status) {
        $comments[$status->value] = Comment::factory()
            ->forCommentable($post)
            ->status($status)
            ->create(['body' => "A {$status->value} comment"]);
    }

    return $comments;
}
