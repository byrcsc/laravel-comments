<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
use ByRcsc\LaravelComments\Models\Comment;

it('forms a thread through reply', function (): void {
    $post = post();
    $comment = $post->comment('Great write-up!', by: user());

    $reply = $comment->reply('Agreed, especially the last section.', by: user('Alan Turing'));

    expect($reply->parent_id)->toBe($comment->getKey())
        ->and($reply->parent?->getKey())->toBe($comment->getKey())
        ->and($reply->commentable_type)->toBe($comment->commentable_type)
        ->and($reply->commentable_id)->toBe($comment->commentable_id)
        ->and($comment->replies()->pluck('body')->all())->toBe(['Agreed, especially the last section.']);
});

it('lets a guest reply in a thread', function (): void {
    $comment = post()->comment('Anyone tried this?', by: user());

    $reply = $comment->replyAsGuest('Yes - works fine.', name: 'Jane', email: 'jane@example.com');

    expect($reply->parent_id)->toBe($comment->getKey())
        ->and($reply->guest_name)->toBe('Jane')
        ->and($reply->commentator_id)->toBeNull();
});

it('reads top-level comments with their replies', function (): void {
    $post = post();
    $first = $post->comment('First thread', by: user());
    $second = $post->comment('Second thread', by: user());
    $first->reply('Reply in the first', by: user('Alan Turing'));

    $threads = $post->comments()->topLevel()->with('replies')->orderBy('id')->get();

    expect($threads)->toHaveCount(2)
        ->and($threads->pluck('body')->all())->toBe(['First thread', 'Second thread'])
        ->and($threads->first()?->replies->pluck('body')->all())->toBe(['Reply in the first'])
        ->and($threads->last()?->replies)->toHaveCount(0)
        ->and($second->depth())->toBe(0);
});

it('reports depth from the top of the thread', function (): void {
    $comment = post()->comment('Depth 0', by: user());
    $reply = $comment->reply('Depth 1', by: user());
    $deeper = $reply->reply('Depth 2', by: user());

    expect($comment->depth())->toBe(0)
        ->and($reply->depth())->toBe(1)
        ->and($deeper->depth())->toBe(2);
});

describe('depth limit', function (): void {
    it('enforces the default of three reply levels', function (): void {
        $comment = post()->comment('Depth 0', by: user());
        $one = $comment->reply('Depth 1', by: user());
        $two = $one->reply('Depth 2', by: user());
        $three = $two->reply('Depth 3', by: user());

        expect(fn () => $three->reply('Depth 4 is over the line', by: user()))
            ->toThrow(ThreadTooDeepException::class);
    });

    it('treats null as unlimited', function (): void {
        config()->set('comments.max_depth', null);

        $comment = post()->comment('Depth 0', by: user());

        foreach (range(1, 10) as $depth) {
            $comment = $comment->reply("Depth {$depth}", by: user());
        }

        expect($comment->depth())->toBe(10);
    });

    it('never reshapes an existing thread when the limit tightens', function (): void {
        config()->set('comments.max_depth', null);

        $comment = post()->comment('Depth 0', by: user());
        $deep = $comment->reply('Depth 1', by: user())->reply('Depth 2', by: user());

        config()->set('comments.max_depth', 1);

        // The over-deep comment is untouched and still readable.
        expect(Comment::query()->count())->toBe(3)
            ->and($deep->refresh()->depth())->toBe(2)
            // Only new replies feel the tighter limit.
            ->and(fn () => $deep->reply('Depth 3', by: user()))->toThrow(ThreadTooDeepException::class);
    });

    it('stores nothing when the limit rejects a reply', function (): void {
        config()->set('comments.max_depth', 1);

        $comment = post()->comment('Depth 0', by: user());
        $reply = $comment->reply('Depth 1', by: user());

        try {
            $reply->reply('Depth 2', by: user());
        } catch (ThreadTooDeepException) {
            // Expected.
        }

        expect(Comment::query()->count())->toBe(2);
    });

    it('enforces the limit through the factory as well', function (): void {
        config()->set('comments.max_depth', 1);

        $post = post();
        $comment = Comment::factory()->forCommentable($post)->create();
        $reply = Comment::factory()->replyTo($comment)->create();

        expect(fn () => Comment::factory()->replyTo($reply)->create())
            ->toThrow(ThreadTooDeepException::class);
    });
});
