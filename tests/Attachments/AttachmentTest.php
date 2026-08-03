<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Events\AttachmentAdded;
use ByRcsc\LaravelComments\Events\AttachmentRemoved;
use ByRcsc\LaravelComments\Exceptions\CommentTrashedException;
use ByRcsc\LaravelComments\Exceptions\InvalidAttachmentException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

it('records metadata about a file the application stored', function (): void {
    $disk = Storage::fake('uploads');
    $disk->put('receipts/invoice.pdf', 'the bytes are the application\'s');

    $comment = post()->comment('The invoice is attached', by: user());

    $attachment = $comment->attach(
        path: 'receipts/invoice.pdf',
        disk: 'uploads',
        name: 'Invoice #12.pdf',
        mimeType: 'application/pdf',
        size: $disk->size('receipts/invoice.pdf'),
    );

    expect($attachment)->toBeInstanceOf(CommentAttachment::class)
        ->and($attachment->disk)->toBe('uploads')
        ->and($attachment->path)->toBe('receipts/invoice.pdf')
        ->and($attachment->name)->toBe('Invoice #12.pdf')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->size)->toBe($disk->size('receipts/invoice.pdf'))
        ->and($attachment->comment_id)->toBe($comment->id);
});

it('names an attachment after its path when the caller does not', function (): void {
    $comment = post()->comment('One file', by: user());

    expect($comment->attach(path: 'receipts/invoice.pdf')->name)->toBe('invoice.pdf');
});

it('falls back to the configured attachment disk', function (): void {
    config()->set('comments.attachments.disk', 'uploads');

    $comment = post()->comment('One file', by: user());

    expect($comment->attach(path: 'a.pdf')->disk)->toBe('uploads');
});

it('falls back to the application default disk when nothing is configured', function (): void {
    config()->set('comments.attachments.disk', null);
    config()->set('filesystems.default', 'somewhere');

    $comment = post()->comment('One file', by: user());

    expect($comment->attach(path: 'a.pdf')->disk)->toBe('somewhere');
});

it('leaves size and mime type null rather than guessing them', function (): void {
    $comment = post()->comment('One file', by: user());

    $attachment = $comment->attach(path: 'a.pdf');

    expect($attachment->size)->toBeNull()
        ->and($attachment->mime_type)->toBeNull();
});

it('never touches the file it is told about', function (): void {
    $disk = Storage::fake('uploads');
    $disk->put('receipts/invoice.pdf', 'still here');

    $comment = post()->comment('The invoice is attached', by: user());
    $attachment = $comment->attach(path: 'receipts/invoice.pdf', disk: 'uploads');

    $comment->detach($attachment);
    $comment->delete();
    $comment->forceDelete();

    $disk->assertExists('receipts/invoice.pdf');
    expect($disk->get('receipts/invoice.pdf'))->toBe('still here');
});

it('records a file that is not on the disk at all', function (): void {
    Storage::fake('uploads');

    $comment = post()->comment('Recorded, not verified', by: user());

    expect($comment->attach(path: 'never/written.pdf', disk: 'uploads')->exists)->toBeTrue();
});

describe('the attachments relation', function (): void {
    it('reads a comment\'s files oldest first', function (): void {
        $comment = post()->comment('Two files', by: user());

        $comment->attach(path: 'first.pdf');
        $comment->attach(path: 'second.pdf');

        expect($comment->attachments->pluck('name')->all())->toBe(['first.pdf', 'second.pdf']);
    });

    it('refreshes after an attach or a detach', function (): void {
        $comment = post()->comment('Two files', by: user());

        $first = $comment->attach(path: 'first.pdf');
        expect($comment->attachments)->toHaveCount(1);

        $comment->attach(path: 'second.pdf');
        expect($comment->attachments)->toHaveCount(2);

        $comment->detach($first);
        expect($comment->attachments)->toHaveCount(1);
    });

    it('eager loads across a thread', function (): void {
        $post = post();
        $comment = $post->comment('One file', by: user());
        $comment->attach(path: 'a.pdf');

        $loaded = $post->comments()->with('attachments')->get();

        expect($loaded->first()?->relationLoaded('attachments'))->toBeTrue()
            ->and($loaded->first()?->attachments)->toHaveCount(1);
    });
});

describe('detaching', function (): void {
    it('removes one row and reports that it did', function (): void {
        $comment = post()->comment('One file', by: user());
        $attachment = $comment->attach(path: 'a.pdf');

        expect($comment->detach($attachment))->toBeTrue()
            ->and(CommentAttachment::query()->count())->toBe(0);
    });

    it('reports that there was nothing to remove', function (): void {
        $comment = post()->comment('One file', by: user());
        $attachment = $comment->attach(path: 'a.pdf');

        $comment->detach($attachment);

        expect($comment->detach($attachment))->toBeFalse();
    });

    it('refuses an attachment belonging to another comment', function (): void {
        $post = post();
        $mine = $post->comment('Mine', by: user());
        $theirs = $post->comment('Theirs', by: user('Alan Turing'));

        $attachment = $theirs->attach(path: 'a.pdf');

        expect(fn () => $mine->detach($attachment))
            ->toThrow(InvalidAttachmentException::class);
    });
});

describe('validation', function (): void {
    it('refuses a blank path', function (string $path): void {
        $comment = post()->comment('One file', by: user());

        expect(fn () => $comment->attach(path: $path))
            ->toThrow(InvalidAttachmentException::class);
    })->with(['', '   ']);

    it('refuses a blank name', function (): void {
        $comment = post()->comment('One file', by: user());

        expect(fn () => $comment->attach(path: 'a.pdf', name: '  '))
            ->toThrow(InvalidAttachmentException::class);
    });

    it('refuses a negative size', function (): void {
        $comment = post()->comment('One file', by: user());

        expect(fn () => $comment->attach(path: 'a.pdf', size: -1))
            ->toThrow(InvalidAttachmentException::class);
    });

    it('accepts a zero-byte file', function (): void {
        $comment = post()->comment('One file', by: user());

        expect($comment->attach(path: 'empty.txt', size: 0)->size)->toBe(0);
    });

    it('refuses attaching to an unsaved comment', function (): void {
        expect(fn () => (new Comment)->attach(path: 'a.pdf'))
            ->toThrow(LogicException::class);
    });
});

describe('deletion', function (): void {
    it('keeps the rows through a soft delete, so a tombstone still lists them', function (): void {
        $comment = post()->comment('One file', by: user());
        $comment->attach(path: 'a.pdf');

        $comment->delete();

        expect(CommentAttachment::query()->count())->toBe(1);
    });

    it('removes the rows on force delete', function (): void {
        $comment = post()->comment('One file', by: user());
        $comment->attach(path: 'a.pdf');

        $comment->forceDelete();

        expect(CommentAttachment::query()->count())->toBe(0);
    });

    it('removes a reply\'s rows with the subtree', function (): void {
        $comment = post()->comment('Parent', by: user());
        $reply = $comment->reply('Child', by: user('Alan Turing'));
        $reply->attach(path: 'a.pdf');

        $comment->forceDelete();

        expect(CommentAttachment::query()->count())->toBe(0);
    });

    it('refuses to attach to a tombstone', function (): void {
        $comment = post()->comment('One file', by: user());
        $comment->delete();

        expect(fn () => $comment->attach(path: 'a.pdf'))
            ->toThrow(CommentTrashedException::class);
    });

    it('refuses to detach from a tombstone', function (): void {
        $comment = post()->comment('One file', by: user());
        $attachment = $comment->attach(path: 'a.pdf');
        $comment->delete();

        expect(fn () => $comment->detach($attachment))
            ->toThrow(CommentTrashedException::class);
    });

    it('takes attachments back once the comment is restored', function (): void {
        $comment = post()->comment('One file', by: user());
        $comment->delete();
        $comment->restore();

        expect($comment->attach(path: 'a.pdf')->exists)->toBeTrue();
    });
});

describe('events', function (): void {
    it('fires AttachmentAdded with the comment and the row', function (): void {
        Event::fake([AttachmentAdded::class]);

        $comment = post()->comment('One file', by: user());
        $attachment = $comment->attach(path: 'a.pdf', disk: 'uploads');

        Event::assertDispatched(
            AttachmentAdded::class,
            fn (AttachmentAdded $event): bool => $event->comment->is($comment)
                && $event->attachment->is($attachment)
                && $event->attachment->disk === 'uploads',
        );
    });

    it('fires AttachmentRemoved on detach, with the disk and path still readable', function (): void {
        $comment = post()->comment('One file', by: user());
        $attachment = $comment->attach(path: 'receipts/a.pdf', disk: 'uploads');

        Event::fake([AttachmentRemoved::class]);

        $comment->detach($attachment);

        Event::assertDispatched(
            AttachmentRemoved::class,
            fn (AttachmentRemoved $event): bool => $event->attachment->disk === 'uploads'
                && $event->attachment->path === 'receipts/a.pdf',
        );
    });

    it('fires nothing when there was nothing to detach', function (): void {
        $comment = post()->comment('One file', by: user());
        $attachment = $comment->attach(path: 'a.pdf');
        $comment->detach($attachment);

        Event::fake([AttachmentRemoved::class]);

        $comment->detach($attachment);

        Event::assertNotDispatched(AttachmentRemoved::class);
    });

    it('fires AttachmentRemoved once per file when the comment is force deleted', function (): void {
        $comment = post()->comment('Two files', by: user());
        $comment->attach(path: 'a.pdf');
        $comment->attach(path: 'b.pdf');

        Event::fake([AttachmentRemoved::class]);

        $comment->forceDelete();

        Event::assertDispatchedTimes(AttachmentRemoved::class, 2);
    });

    it('fires AttachmentRemoved for the whole subtree the cascade takes', function (): void {
        $author = user();
        $comment = post()->comment('Parent', by: $author);
        $reply = $comment->reply('Child', by: $author);
        $grandchild = $reply->reply('Grandchild', by: $author);

        $comment->attach(path: 'parent.pdf');
        $reply->attach(path: 'reply.pdf');
        $grandchild->attach(path: 'grandchild.pdf');

        Event::fake([AttachmentRemoved::class]);

        $comment->forceDelete();

        Event::assertDispatchedTimes(AttachmentRemoved::class, 3);
        Event::assertDispatched(
            AttachmentRemoved::class,
            fn (AttachmentRemoved $event): bool => $event->attachment->name === 'grandchild.pdf'
                && $event->comment->is($grandchild),
        );
    });

    it('sweeps a soft-deleted reply\'s attachments too, like the cascade does', function (): void {
        $author = user();
        $comment = post()->comment('Parent', by: $author);
        $reply = $comment->reply('Child', by: $author);
        $reply->attach(path: 'reply.pdf');
        $reply->delete();

        Event::fake([AttachmentRemoved::class]);

        $comment->forceDelete();

        Event::assertDispatchedTimes(AttachmentRemoved::class, 1);
    });

    it('fires nothing on a soft delete, which removes nothing', function (): void {
        $comment = post()->comment('One file', by: user());
        $comment->attach(path: 'a.pdf');

        Event::fake([AttachmentRemoved::class]);

        $comment->delete();

        Event::assertNotDispatched(AttachmentRemoved::class);
    });
});
