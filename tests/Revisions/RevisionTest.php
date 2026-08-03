<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use ByRcsc\LaravelComments\Exceptions\CommentTrashedException;
use ByRcsc\LaravelComments\Exceptions\RevisionIsAppendOnlyException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentRevision;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use Illuminate\Support\Facades\Event;

it('records the previous body when the body changes', function (): void {
    $comment = post()->comment('First draft', by: user());

    $comment->edit('Second draft');

    $revision = $comment->revisions()->sole();

    expect($revision->body)->toBe('First draft')
        ->and($comment->refresh()->body)->toBe('Second draft');
});

it('stamps edited_at on the comment', function (): void {
    $comment = post()->comment('First draft', by: user());

    expect($comment->edited_at)->toBeNull();

    $comment->edit('Second draft');

    expect($comment->refresh()->edited_at)->not->toBeNull();
});

it('records the editor when the edit names one', function (): void {
    $comment = post()->comment('First draft', by: user());
    $editor = user('Grace Hopper');

    $comment->edit('Second draft', by: $editor);

    $revision = $comment->revisions()->sole();

    expect($revision->editor)->toBeInstanceOf(User::class)
        ->and($revision->editor?->is($editor))->toBeTrue();
});

it('records a null editor when nobody is named', function (): void {
    $comment = post()->comment('First draft', by: user());

    $comment->edit('Second draft');

    expect($comment->revisions()->sole()->editor)->toBeNull();
});

it('records the same history for a plain attribute save', function (): void {
    $comment = post()->comment('First draft', by: user());

    $comment->body = 'Second draft';
    $comment->save();

    $revision = $comment->revisions()->sole();

    expect($revision->body)->toBe('First draft')
        ->and($revision->editor)->toBeNull()
        ->and($comment->refresh()->edited_at)->not->toBeNull();
});

it('records the same history for update()', function (): void {
    $comment = post()->comment('First draft', by: user());

    $comment->update(['body' => 'Second draft']);

    expect($comment->revisions()->sole()->body)->toBe('First draft');
});

it('keeps one revision per edit, oldest first', function (): void {
    $comment = post()->comment('One', by: user());

    $comment->edit('Two');
    $comment->edit('Three');
    $comment->edit('Four');

    expect($comment->revisions()->pluck('body')->all())->toBe(['One', 'Two', 'Three'])
        ->and($comment->refresh()->body)->toBe('Four');
});

it('stores the previous body verbatim, markup and all', function (): void {
    $body = "<script>alert('xss')</script>\n**not markdown**  ";
    $comment = post()->comment($body, by: user());

    $comment->edit('Cleaned up');

    expect($comment->revisions()->sole()->body)->toBe($body);
});

describe('updates that are not edits', function (): void {
    it('records nothing for a moderation transition', function (): void {
        $comment = post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com');

        $comment->approve(by: user('Grace Hopper'));

        expect($comment->revisions()->count())->toBe(0)
            ->and($comment->refresh()->edited_at)->toBeNull();
    });

    it('records nothing for a restore', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->delete();

        $comment->restore();

        expect($comment->revisions()->count())->toBe(0)
            ->and($comment->refresh()->edited_at)->toBeNull();
    });

    it('records nothing when the body is saved unchanged', function (): void {
        $comment = post()->comment('First draft', by: user());

        $comment->body = 'First draft';
        $comment->save();

        expect($comment->revisions()->count())->toBe(0)
            ->and($comment->refresh()->edited_at)->toBeNull();
    });

    it('reports that edit() did nothing when the body is the same', function (): void {
        $comment = post()->comment('First draft', by: user());

        expect($comment->edit('First draft'))->toBeFalse()
            ->and($comment->revisions()->count())->toBe(0);
    });

    it('leaves an earlier edited_at alone when a later update is not an edit', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');
        $editedAt = $comment->refresh()->edited_at;

        $comment->reject();

        expect($comment->refresh()->edited_at?->timestamp)->toBe($editedAt?->timestamp);
    });
});

/*
 * The documented trust boundary. Recording rides Eloquent's model events, so
 * anything that goes around them goes around this; the caveat is pinned here
 * so it stays true rather than becoming a stale sentence in the README.
 */
describe('writes that bypass model events', function (): void {
    it('records nothing for a quiet save', function (): void {
        $comment = post()->comment('First draft', by: user());

        $comment->body = 'Second draft';
        $comment->saveQuietly();

        expect($comment->revisions()->count())->toBe(0)
            ->and($comment->refresh()->body)->toBe('Second draft')
            ->and($comment->edited_at)->toBeNull();
    });

    it('records nothing for a query-builder update', function (): void {
        $comment = post()->comment('First draft', by: user());

        Comment::query()->whereKey($comment->getKey())->update(['body' => 'Second draft']);

        expect($comment->revisions()->count())->toBe(0)
            ->and($comment->refresh()->body)->toBe('Second draft')
            ->and($comment->edited_at)->toBeNull();
    });
});

/*
 * The re-moderation pattern the package documents instead of shipping. It is
 * pinned here because both halves of it are promises: the listener runs, and
 * the revision it wants to compare against is already there.
 */
describe('the documented re-moderation listener', function (): void {
    it('still records the revision when a listener returns a value', function (): void {
        Event::listen(CommentUpdated::class, fn (CommentUpdated $event): bool => true);

        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');

        expect($comment->revisions()->count())->toBe(1)
            ->and($comment->refresh()->edited_at)->not->toBeNull();
    });

    it('hands the listener a comment whose revision is already filed', function (): void {
        $seen = null;
        Event::listen(CommentUpdated::class, function (CommentUpdated $event) use (&$seen): void {
            if ($event->comment->wasChanged('body')) {
                $seen = $event->comment->revisions()->pluck('body')->all();
            }
        });

        post()->comment('First draft', by: user())->edit('Second draft');

        expect($seen)->toBe(['First draft']);
    });

    it('sends an edited approved comment back to pending from the listener', function (): void {
        Event::listen(CommentUpdated::class, function (CommentUpdated $event): void {
            if ($event->comment->wasChanged('body') && $event->comment->status === CommentStatus::Approved) {
                $event->comment->status = CommentStatus::Pending;
                $event->comment->save();
            }
        });

        $comment = post()->comment('First draft', by: user());
        expect($comment->status)->toBe(CommentStatus::Approved);

        $comment->edit('Second draft', by: user('Grace Hopper'));

        expect($comment->refresh()->status)->toBe(CommentStatus::Pending)
            ->and($comment->revisions()->count())->toBe(1);
    });
});

describe('gates an edit shares with every other write', function (): void {
    it('refuses a body longer than comments.max_length', function (): void {
        $comment = post()->comment('Short', by: user());
        config()->set('comments.max_length', 10);

        expect(fn () => $comment->edit('Far too long for the limit'))
            ->toThrow(BodyTooLongException::class)
            ->and($comment->refresh()->body)->toBe('Short')
            ->and($comment->revisions()->count())->toBe(0);
    });

    it('refuses to rewrite a tombstone', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->delete();

        expect(fn () => $comment->edit('Second draft'))
            ->toThrow(CommentTrashedException::class)
            ->and($comment->refresh()->body)->toBe('First draft');
    });

    it('refuses a plain body save on a tombstone too', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->delete();

        $comment->body = 'Second draft';

        expect(fn () => $comment->save())->toThrow(CommentTrashedException::class);
    });

    it('refuses to edit an unsaved comment rather than quietly inserting one', function (): void {
        expect(fn () => (new Comment)->edit('Out of nowhere'))
            ->toThrow(LogicException::class)
            ->and(Comment::query()->count())->toBe(0);
    });

    it('refuses to edit a comment loaded without its body', function (): void {
        $comment = post()->comment('First draft', by: user());

        $partial = Comment::query()->select(['id', 'deleted_at'])->findOrFail($comment->getKey());
        $partial->body = 'Second draft';

        expect(fn () => $partial->save())->toThrow(LogicException::class)
            ->and($comment->refresh()->body)->toBe('First draft')
            ->and($comment->revisions()->count())->toBe(0);
    });
});

describe('append-only revisions', function (): void {
    it('refuses to update a revision', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');

        $revision = $comment->revisions()->sole();

        expect(fn () => $revision->update(['body' => 'Rewritten history']))
            ->toThrow(RevisionIsAppendOnlyException::class);
    });

    it('leaves the row untouched when it refuses', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');

        $revision = $comment->revisions()->sole();

        try {
            $revision->update(['body' => 'Rewritten history']);
        } catch (RevisionIsAppendOnlyException) {
            // Expected.
        }

        expect(CommentRevision::findOrFail($revision->getKey())->body)->toBe('First draft');
    });

    it('still allows a revision to be deleted', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');

        $comment->revisions()->sole()->delete();

        expect($comment->revisions()->count())->toBe(0);
    });
});

describe('deletion', function (): void {
    it('keeps revisions when the comment is soft deleted', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');

        $comment->delete();

        expect($comment->revisions()->count())->toBe(1)
            ->and($comment->revisions()->sole()->body)->toBe('First draft');
    });

    it('removes revisions when the comment is force deleted', function (): void {
        $comment = post()->comment('First draft', by: user());
        $comment->edit('Second draft');

        $comment->forceDelete();

        expect(CommentRevision::query()->count())->toBe(0);
    });

    it('removes a whole subtree\'s revisions when the root is force deleted', function (): void {
        $root = post()->comment('Root', by: user());
        $reply = $root->reply('A reply', by: user('Alan Turing'));

        $root->edit('Root, edited');
        $reply->edit('A reply, edited');

        $root->forceDelete();

        expect(CommentRevision::query()->count())->toBe(0);
    });
});

it('exposes the editor as an ordinary polymorphic relation', function (): void {
    $comment = post()->comment('First draft', by: user());
    $editor = user('Grace Hopper');

    $comment->edit('Second draft', by: $editor);

    $revisions = Comment::with('revisions.editor')->findOrFail($comment->getKey())->revisions;

    expect($revisions)->toHaveCount(1)
        ->and($revisions->first()?->editor?->is($editor))->toBeTrue();
});
