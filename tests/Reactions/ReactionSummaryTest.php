<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Support\Facades\DB;

it('counts each reaction and leaves out the ones nobody used', function (): void {
    $comment = post()->comment('Great write-up!', by: user());

    $comment->react('👍', by: user('Alan Turing'));
    $comment->react('👍', by: user('Grace Hopper'));
    $comment->react('🎉', by: user('Katherine Johnson'));

    expect($comment->reactionSummary())->toBe(['🎉' => 1, '👍' => 2]);
});

it('returns an empty summary for a comment nobody reacted to', function (): void {
    $comment = post()->comment('Great write-up!', by: user());

    expect($comment->reactionSummary())->toBe([]);
});

it('reads a whole thread in one query when the counts are eager loaded', function (): void {
    $post = post();
    $reactor = user('Alan Turing');

    foreach (range(1, 5) as $n) {
        $post->comment("Comment {$n}", by: user("Commenter {$n}"))->react('👍', by: $reactor);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $comments = $post->comments()->with('reactionCounts')->get();
    $summaries = $comments->map(fn (Comment $comment): array => $comment->reactionSummary());

    // One for the comments, one for every comment's counts together.
    expect($queries)->toBe(2)
        ->and($summaries->all())->toBe(array_fill(0, 5, ['👍' => 1]));
});

it('costs one query per comment when the counts are not eager loaded', function (): void {
    $post = post();
    $post->comment('One', by: user('Commenter one'))->react('👍', by: user('Alan Turing'));
    $post->comment('Two', by: user('Commenter two'))->react('👍', by: user('Grace Hopper'));

    $comments = $post->comments()->get();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $comments->each(fn (Comment $comment): array => $comment->reactionSummary());

    expect($queries)->toBe(2);
});

it('keeps each comment to its own counts', function (): void {
    $post = post();
    $first = $post->comment('First', by: user('Commenter one'));
    $second = $post->comment('Second', by: user('Commenter two'));

    $first->react('👍', by: user('Alan Turing'));
    $second->react('🎉', by: user('Grace Hopper'));
    $second->react('🎉', by: user('Katherine Johnson'));

    expect($first->reactionSummary())->toBe(['👍' => 1])
        ->and($second->reactionSummary())->toBe(['🎉' => 2]);
});

it('stays current after a reaction is added or removed', function (): void {
    $comment = post()->comment('Great write-up!', by: user());
    $reactor = user('Alan Turing');

    expect($comment->reactionSummary())->toBe([]);

    $comment->react('👍', by: $reactor);
    expect($comment->reactionSummary())->toBe(['👍' => 1]);

    $comment->unreact('👍', by: $reactor);
    expect($comment->reactionSummary())->toBe([]);
});

describe('the reacted-by lookup', function (): void {
    it('reports whether a reactor reacted at all', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $alan = user('Alan Turing');
        $grace = user('Grace Hopper');

        $comment->react('👍', by: $alan);

        expect($comment->hasReactionFrom($alan))->toBeTrue()
            ->and($comment->hasReactionFrom($grace))->toBeFalse();
    });

    it('reports whether a reactor left one reaction in particular', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $alan = user('Alan Turing');

        $comment->react('👍', by: $alan);

        expect($comment->hasReactionFrom($alan, '👍'))->toBeTrue()
            ->and($comment->hasReactionFrom($alan, '🎉'))->toBeFalse();
    });

    it('lists reactions in a stable order however they were loaded', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $alan = user('Alan Turing');

        $comment->react('👍', by: $alan);
        $comment->react('🎉', by: $alan);
        $comment->react('😢', by: $alan);

        $loaded = Comment::with('reactions')->findOrFail($comment->getKey());

        expect($loaded->reactionsBy($alan))->toBe($comment->reactionsBy($alan));
    });

    it('lists a reactor\'s reactions and nobody else\'s', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $alan = user('Alan Turing');
        $grace = user('Grace Hopper');

        $comment->react('👍', by: $alan);
        $comment->react('🎉', by: $alan);
        $comment->react('😢', by: $grace);

        expect($comment->reactionsBy($alan))->toBe(['🎉', '👍'])
            ->and($comment->reactionsBy($grace))->toBe(['😢']);
    });

    it('answers from the loaded relation without going back to the database', function (): void {
        $comment = post()->comment('Great write-up!', by: user());
        $alan = user('Alan Turing');
        $comment->react('👍', by: $alan);

        $loaded = Comment::with('reactions')->findOrFail($comment->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        expect($loaded->hasReactionFrom($alan))->toBeTrue()
            ->and($loaded->hasReactionFrom($alan, '👍'))->toBeTrue()
            ->and($loaded->hasReactionFrom($alan, '🎉'))->toBeFalse()
            ->and($loaded->reactionsBy($alan))->toBe(['👍'])
            ->and($queries)->toBe(0);
    });
});
