<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;
use ByRcsc\LaravelComments\Notifications\CommentReplied;
use ByRcsc\LaravelComments\Tests\Stubs\DenyEverythingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The promises the README makes in prose, made as tests.
 *
 * The feature suites already cover these behaviors from their own angles. They
 * are repeated here on purpose: a guarantee stated in a README and proven only
 * incidentally is one refactor away from quietly becoming untrue, and this is
 * the file that has to fail before that reaches a release.
 */
it('starts guest comments pending, whatever the default status says', function (): void {
    config()->set('comments.default_status', 'approved');

    $post = post();

    $guest = $post->commentAsGuest('From a guest', name: 'Jane', email: 'jane@example.com');
    $member = $post->comment('From a member', by: user());

    expect($guest->status)->toBe(CommentStatus::Pending)
        ->and($member->status)->toBe(CommentStatus::Approved);
});

it('never notifies a guest, whatever the configuration says', function (): void {
    config()->set('comments.notifications.reply.enabled', true);
    config()->set('comments.notifications.reply.channels', ['mail', 'database']);

    Notification::fake();

    $guestComment = post()->commentAsGuest('From a guest', name: 'Jane', email: 'jane@example.com');
    $guestComment->approve();
    $guestComment->reply('A reply', by: notifiableUser());

    Notification::assertNothingSent();
});

it('never authorizes its own methods', function (): void {
    // The harshest policy the framework allows, and nobody logged in.
    Gate::policy(Comment::class, DenyEverythingPolicy::class);

    $author = user();
    $comment = post()->comment('Written with nobody logged in', by: $author);

    $comment->approve();
    $comment->pin();
    $comment->react('👍', by: $author);
    $comment->edit('Edited with nobody logged in', by: $author);
    $comment->attach(path: 'a.pdf');

    expect($comment->fresh()?->status)->toBe(CommentStatus::Approved)
        ->and(Gate::allows('update', $comment))->toBeFalse();
});

it('never reads an attachment\'s bytes and never deletes its file', function (): void {
    $disk = Storage::fake('uploads');
    $disk->put('receipts/invoice.pdf', 'bytes the package never looks at');

    $comment = post()->comment('With a file', by: user());

    // Recorded without the file being opened - the metadata is what the caller
    // said, not what the disk holds.
    $attachment = $comment->attach(
        path: 'receipts/invoice.pdf',
        disk: 'uploads',
        name: 'Something else entirely.pdf',
        mimeType: 'application/x-nonsense',
        size: 1,
    );

    expect($attachment->size)->toBe(1)
        ->and($attachment->mime_type)->toBe('application/x-nonsense');

    $comment->detach($attachment);
    $comment->delete();
    $comment->forceDelete();

    $disk->assertExists('receipts/invoice.pdf');

    expect($disk->get('receipts/invoice.pdf'))->toBe('bytes the package never looks at')
        ->and(CommentAttachment::query()->count())->toBe(0);
});

it('records a file that was never stored at all', function (): void {
    Storage::fake('uploads');

    $comment = post()->comment('With a file', by: user());

    expect($comment->attach(path: 'never/written.pdf', disk: 'uploads')->exists)->toBeTrue();
});

it('sends no notification until the configuration says so', function (): void {
    Notification::fake();

    $author = notifiableUser();
    post()->comment('The original', by: $author)->reply('A reply', by: notifiableUser('Alan Turing'));

    Notification::assertNothingSent();
    Notification::assertNotSentTo($author, CommentReplied::class);
});

it('registers no policy and defines no gate of its own', function (): void {
    expect(Gate::policies())->toBe([])
        ->and(Gate::abilities())->toBe([]);
});

it('stores the body verbatim, sanitizing nothing', function (): void {
    $body = '<script>alert(1)</script> **not markdown** & raw < > "';

    $comment = post()->comment($body, by: user());

    expect($comment->fresh()?->body)->toBe($body);
});

it('keeps a transition idempotent, so counts and notifications cannot double up', function (): void {
    $comment = post()->commentAsGuest('From a guest', name: 'Jane', email: 'jane@example.com');

    expect($comment->approve())->toBeTrue()
        ->and($comment->approve())->toBeFalse()
        ->and($comment->approve())->toBeFalse();
});

it('refuses a reaction without an identity to deduplicate by', function (): void {
    $comment = post()->commentAsGuest('From a guest', name: 'Jane', email: 'jane@example.com');

    // There is no guest path to react through: `react()` takes a model, and
    // that is the guarantee. This pins the signature rather than a behavior.
    $parameter = (new ReflectionMethod(Comment::class, 'react'))->getParameters()[1];

    expect($parameter->getName())->toBe('by')
        ->and($parameter->allowsNull())->toBeFalse()
        ->and($comment->reactions()->count())->toBe(0);
});
