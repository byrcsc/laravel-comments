<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Tests\Stubs\ModeratedPost;
use Illuminate\Support\Facades\DB;

it('starts an authenticated comment at the configured default status', function (): void {
    $comment = post()->comment('Great write-up!', by: user());

    expect($comment->refresh()->status)->toBe(CommentStatus::Approved);
});

it('starts a guest comment pending out of the box', function (): void {
    $comment = post()->commentAsGuest('Where are the slides?', name: 'Jane', email: 'jane@example.com');

    expect($comment->refresh()->status)->toBe(CommentStatus::Pending);
});

it('keeps guests pending however high the default status is set', function (): void {
    config()->set('comments.default_status', 'approved');
    $post = post();

    $authenticated = $post->comment('Signed in', by: user());
    $guest = $post->commentAsGuest('Not signed in', name: 'Jane', email: 'jane@example.com');

    expect($authenticated->refresh()->status)->toBe(CommentStatus::Approved)
        ->and($guest->refresh()->status)->toBe(CommentStatus::Pending);
});

it('holds authenticated comments when the default status says so', function (): void {
    config()->set('comments.default_status', 'pending');

    $comment = post()->comment('Held for review', by: user());

    expect($comment->refresh()->status)->toBe(CommentStatus::Pending);
});

it('publishes guest comments when the guest status is explicitly raised', function (): void {
    config()->set('comments.guest_status', 'approved');

    $comment = post()->commentAsGuest('Trusted crowd', name: 'Jane', email: 'jane@example.com');

    expect($comment->refresh()->status)->toBe(CommentStatus::Approved);
});

it('resolves a reply the same way it resolves a top-level comment', function (): void {
    $comment = post()->comment('Root', by: user());

    $reply = $comment->reply('A reply', by: user());
    $guestReply = $comment->replyAsGuest('A guest reply', name: 'Jane', email: 'jane@example.com');

    expect($reply->refresh()->status)->toBe(CommentStatus::Approved)
        ->and($guestReply->refresh()->status)->toBe(CommentStatus::Pending);
});

it('keeps a status the caller set explicitly', function (): void {
    $comment = Comment::factory()->forCommentable(post())->rejected()->create();

    expect($comment->refresh()->status)->toBe(CommentStatus::Rejected);
});

it('resolves a factory-built comment exactly as a written one', function (): void {
    $post = post();

    $guest = Comment::factory()->forCommentable($post)->create();
    $authenticated = Comment::factory()->forCommentable($post)->by(user())->create();

    expect($guest->refresh()->status)->toBe(CommentStatus::Pending)
        ->and($authenticated->refresh()->status)->toBe(CommentStatus::Approved);
});

it('throws rather than guessing when the configured status is not a status', function (): void {
    config()->set('comments.guest_status', 'held');

    expect(fn () => post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com'))
        ->toThrow(InvalidConfigurationException::class);
});

describe('the commentable hook', function (): void {
    it('wins over the configured default for authenticated commentators', function (): void {
        config()->set('comments.default_status', 'pending');
        $post = ModeratedPost::create(['title' => 'Moderated']);

        $comment = $post->comment('Nothing suspicious here', by: user());

        expect($comment->refresh()->status)->toBe(CommentStatus::Approved);
    });

    it('wins over the guest default too', function (): void {
        $post = ModeratedPost::create(['title' => 'Moderated']);

        $comment = $post->commentAsGuest(
            'Cheap watches at http://example.test',
            name: 'Spammer',
            email: 'spam@example.test',
        );

        expect($comment->refresh()->status)->toBe(CommentStatus::Spam);
    });

    it('falls through to the configured status when it returns null', function (): void {
        config()->set('comments.guest_status', 'rejected');
        $post = ModeratedPost::create(['title' => 'Moderated']);

        $comment = $post->commentAsGuest('No opinion here', name: 'Jane', email: 'jane@example.com');

        expect($comment->refresh()->status)->toBe(CommentStatus::Rejected);
    });

    it('is asked about replies as well', function (): void {
        $post = ModeratedPost::create(['title' => 'Moderated']);
        $comment = $post->comment('Root', by: user());

        $reply = $comment->reply('Cheap watches at http://example.test', by: user());

        expect($reply->refresh()->status)->toBe(CommentStatus::Spam);
    });

    it('is not consulted when the caller named a status', function (): void {
        $post = ModeratedPost::create(['title' => 'Moderated']);

        $comment = Comment::factory()
            ->forCommentable($post)
            ->approved()
            ->create(['body' => 'Cheap watches at http://example.test']);

        expect($comment->refresh()->status)->toBe(CommentStatus::Approved);
    });

    /*
     * Looking the hook up must not turn every comment into a read of the row
     * we are already standing on, so the query count is the assertion.
     */
    it('costs no extra query on the ordinary write path', function (): void {
        $post = post();
        $user = user();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $post->comment('Great write-up!', by: $user);

        expect($queries)->toBe(1);
    });

    it('costs one read when the commentable is not already in hand', function (): void {
        $post = ModeratedPost::create(['title' => 'Moderated']);
        $user = user();
        $comment = $post->comment('Root', by: $user);

        // A comment loaded fresh knows only the morph columns, so replying
        // through it has to fetch the commentable the hook lives on.
        $reloaded = Comment::findOrFail($comment->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $reply = $reloaded->reply('A reply', by: $user);

        expect($queries)->toBe(2)
            ->and($reply->refresh()->status)->toBe(CommentStatus::Approved);
    });
});
