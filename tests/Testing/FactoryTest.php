<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;
use ByRcsc\LaravelComments\Models\CommentReaction;
use ByRcsc\LaravelComments\Models\CommentRevision;

describe('the comment factory', function (): void {
    it('writes a guest comment by default', function (): void {
        $comment = Comment::factory()->forCommentable(post())->create();

        expect($comment->commentator_type)->toBeNull()
            ->and($comment->guest_name)->not->toBeNull()
            ->and($comment->status)->toBe(CommentStatus::Pending);
    });

    it('writes an authenticated comment with by()', function (): void {
        $author = user();

        $comment = Comment::factory()->forCommentable(post())->by($author)->create();

        expect($comment->commentator_type)->toBe($author->getMorphClass())
            ->and($comment->guest_name)->toBeNull()
            ->and($comment->status)->toBe(CommentStatus::Approved);
    });

    it('writes a comment in each status', function (CommentStatus $status): void {
        $comment = Comment::factory()->forCommentable(post())->status($status)->create();

        expect($comment->fresh()?->status)->toBe($status);
    })->with(CommentStatus::cases());

    it('pins a comment', function (): void {
        $comment = Comment::factory()->forCommentable(post())->pinned()->create();

        expect($comment->pinned_at)->not->toBeNull()
            ->and(Comment::query()->pinned()->count())->toBe(1);
    });

    it('pins at a given moment, so ordering is testable', function (): void {
        $post = post();

        Comment::factory()->forCommentable($post)->pinned(now()->subDay())->create(['body' => 'Older']);
        Comment::factory()->forCommentable($post)->pinned(now())->create(['body' => 'Newer']);

        expect($post->comments()->pinnedFirst()->pluck('body')->all())->toBe(['Newer', 'Older']);
    });

    it('writes a tombstone', function (): void {
        $comment = Comment::factory()->forCommentable(post())->trashed()->create();

        expect($comment->trashed())->toBeTrue()
            ->and(Comment::query()->count())->toBe(0)
            ->and(Comment::withTrashed()->count())->toBe(1);
    });

    it('builds a thread to a given depth', function (): void {
        $comment = Comment::factory()->forCommentable(post())->threaded(3)->create();

        expect($comment->depth())->toBe(3)
            ->and(Comment::query()->count())->toBe(4);
    });

    it('puts the whole thread on the same commentable', function (): void {
        $post = post();

        Comment::factory()->forCommentable($post)->threaded(2)->create();

        expect($post->comments()->count())->toBe(3)
            ->and($post->comments()->topLevel()->count())->toBe(1);
    });

    it('is held to the same depth limit the engine enforces', function (): void {
        expect(fn () => Comment::factory()->forCommentable(post())->threaded(4)->create())
            ->toThrow(ThreadTooDeepException::class);
    });

    it('says so when it does not know what the thread is on', function (): void {
        expect(fn () => Comment::factory()->threaded(2)->create())
            ->toThrow(LogicException::class, 'forCommentable()');
    });

    it('combines states', function (): void {
        $author = user();

        $comment = Comment::factory()
            ->forCommentable(post())
            ->by($author)
            ->approved()
            ->pinned()
            ->threaded(1)
            ->create();

        expect($comment->status)->toBe(CommentStatus::Approved)
            ->and($comment->pinned_at)->not->toBeNull()
            ->and($comment->depth())->toBe(1)
            ->and($comment->commentator_type)->toBe($author->getMorphClass());
    });
});

describe('the reaction factory', function (): void {
    it('writes a reaction the allowlist would accept', function (): void {
        $comment = post()->comment('Reacted to', by: user());

        $reaction = CommentReaction::factory()->forComment($comment)->by(user('Alan Turing'))->create();

        expect($reaction->comment_id)->toBe($comment->id)
            ->and(config('comments.allowed_reactions'))->toContain($reaction->reaction)
            ->and($comment->reactionSummary())->toBe([$reaction->reaction => 1]);
    });

    it('takes a named reaction', function (): void {
        $comment = post()->comment('Reacted to', by: user());

        $reaction = CommentReaction::factory()
            ->forComment($comment)
            ->by(user('Alan Turing'))
            ->reaction('🎉')
            ->create();

        expect($reaction->reaction)->toBe('🎉');
    });
});

describe('the revision factory', function (): void {
    it('writes a revision with nobody named', function (): void {
        $comment = post()->comment('The current body', by: user());

        $revision = CommentRevision::factory()->forComment($comment)->create();

        expect($revision->editor_type)->toBeNull()
            ->and($comment->revisions)->toHaveCount(1);
    });

    it('names an editor', function (): void {
        $comment = post()->comment('The current body', by: user());
        $editor = user('Alan Turing');

        $revision = CommentRevision::factory()->forComment($comment)->by($editor)->create();

        expect($revision->editor_type)->toBe($editor->getMorphClass())
            ->and($revision->editor?->is($editor))->toBeTrue();
    });
});

describe('the attachment factory', function (): void {
    it('writes metadata about a file that does not exist', function (): void {
        $comment = post()->comment('With a file', by: user());

        $attachment = CommentAttachment::factory()->forComment($comment)->create();

        expect($attachment->comment_id)->toBe($comment->id)
            ->and($attachment->mime_type)->toBe('application/pdf')
            ->and($attachment->size)->toBeGreaterThan(0)
            ->and($comment->attachments)->toHaveCount(1);
    });

    it('writes an image', function (): void {
        $comment = post()->comment('With a screenshot', by: user());

        $attachment = CommentAttachment::factory()->forComment($comment)->image()->create();

        expect($attachment->mime_type)->toBe('image/webp')
            ->and($attachment->name)->toEndWith('.webp');
    });

    it('takes a disk', function (): void {
        $comment = post()->comment('With a file', by: user());

        expect(CommentAttachment::factory()->forComment($comment)->on('uploads')->create()->disk)
            ->toBe('uploads');
    });
});
