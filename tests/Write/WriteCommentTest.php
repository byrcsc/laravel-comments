<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use ByRcsc\LaravelComments\Exceptions\CommentableNotPersistedException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Tests\Stubs\Post;
use ByRcsc\LaravelComments\Tests\Stubs\User;

it('writes an authenticated comment readable through the relation', function (): void {
    $post = post();
    $user = user();

    $comment = $post->comment('Great write-up!', by: $user);

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->exists)->toBeTrue()
        ->and($post->comments()->count())->toBe(1);

    $read = $post->comments()->first();

    expect($read?->getKey())->toBe($comment->getKey())
        ->and($read?->body)->toBe('Great write-up!')
        ->and($read?->commentator)->toBeInstanceOf(User::class)
        ->and($read?->commentator?->getKey())->toBe($user->getKey())
        ->and($read?->commentable)->toBeInstanceOf(Post::class)
        ->and($read?->commentable?->getKey())->toBe($post->getKey());
});

it('writes a guest comment with a name and an email and no commentator', function (): void {
    $post = post();

    $comment = $post->commentAsGuest(
        'Where can I download the slides?',
        name: 'Jane',
        email: 'jane@example.com',
    );

    $read = $post->comments()->sole();

    expect($read->guest_name)->toBe('Jane')
        ->and($read->guest_email)->toBe('jane@example.com')
        ->and($read->commentator_type)->toBeNull()
        ->and($read->commentator_id)->toBeNull()
        ->and($read->commentator)->toBeNull();
});

// How a status is chosen is the moderation layer's story; this only pins that
// a written comment leaves here carrying one.
it('gives every written comment a status', function (): void {
    $post = post();

    $authenticated = $post->comment('First!', by: user());
    $guest = $post->commentAsGuest('Second!', name: 'Jane', email: 'jane@example.com');

    expect($authenticated->refresh()->status)->toBeInstanceOf(CommentStatus::class)
        ->and($guest->refresh()->status)->toBeInstanceOf(CommentStatus::class);
});

it('stores the body verbatim, markup and all', function (): void {
    $body = "<script>alert('xss')</script>\n**not markdown** & 'quotes'  \n\n  trailing spaces  ";

    $comment = post()->comment($body, by: user());

    expect($comment->refresh()->body)->toBe($body);
});

it('refuses to comment on an unsaved commentable', function (): void {
    $post = new Post(['title' => 'Never saved']);

    expect(fn () => $post->comment('Hello?', by: user()))
        ->toThrow(CommentableNotPersistedException::class);
});

it('keeps separate commentables separate', function (): void {
    $first = post('First post');
    $second = post('Second post');
    $user = user();

    $first->comment('On the first', by: $user);
    $second->comment('On the second', by: $user);

    expect($first->comments()->pluck('body')->all())->toBe(['On the first'])
        ->and($second->comments()->pluck('body')->all())->toBe(['On the second']);
});

describe('body length limit', function (): void {
    it('throws when the body exceeds comments.max_length', function (): void {
        config()->set('comments.max_length', 10);

        expect(fn () => post()->comment('Eleven chars', by: user()))
            ->toThrow(BodyTooLongException::class);
    });

    it('stores nothing when the limit rejects a body', function (): void {
        config()->set('comments.max_length', 10);
        $post = post();

        try {
            $post->comment('This body is far too long', by: user());
        } catch (BodyTooLongException) {
            // Expected.
        }

        expect($post->comments()->count())->toBe(0);
    });

    it('allows a body exactly at the limit, counting multibyte characters as one', function (): void {
        config()->set('comments.max_length', 4);

        // The é is one character but two bytes, so a limit that counted bytes
        // would reject a body that sits exactly on it.
        $comment = post()->comment('héll', by: user());

        expect($comment->refresh()->body)->toBe('héll');
    });

    it('applies no limit by default', function (): void {
        $body = str_repeat('long ', 2_000);

        $comment = post()->comment($body, by: user());

        expect($comment->refresh()->body)->toBe($body);
    });
});
