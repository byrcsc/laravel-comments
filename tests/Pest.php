<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Tests\Stubs\CountedPost;
use ByRcsc\LaravelComments\Tests\Stubs\NotifiableUser;
use ByRcsc\LaravelComments\Tests\Stubs\Post;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use ByRcsc\LaravelComments\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

uses(TestCase::class)->in(__DIR__);

function user(string $name = 'Ada Lovelace'): User
{
    return User::create([
        'name' => $name,
        'email' => str_replace(' ', '.', strtolower($name)).'.'.uniqid().'@example.test',
    ]);
}

/**
 * The same table `user()` writes to, with Laravel's `Notifiable` on it. The
 * package only ever notifies a commentator that has it.
 */
function notifiableUser(string $name = 'Ada Lovelace'): NotifiableUser
{
    return NotifiableUser::create([
        'name' => $name,
        'email' => str_replace(' ', '.', strtolower($name)).'.'.uniqid().'@example.test',
    ]);
}

function post(string $title = 'A post worth discussing'): Post
{
    return Post::create(['title' => $title]);
}

/**
 * The same table `post()` writes to, opted into a denormalized count. Two
 * models over one table is what proves the opt-in is a decision rather than a
 * schema sniff.
 */
function countedPost(string $title = 'A post worth discussing'): CountedPost
{
    return CountedPost::create(['title' => $title]);
}

/**
 * The count as the database holds it. Reading it off the model in hand would
 * only prove that PHP can add up; the whole point of the column is what a
 * listing page selects.
 */
function storedCount(Model $commentable): int
{
    return (int) DB::table($commentable->getTable())
        ->where($commentable->getKeyName(), $commentable->getKey())
        ->value('comments_count');
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
