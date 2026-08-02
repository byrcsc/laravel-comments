<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Tests\Stubs\StringUser;
use ByRcsc\LaravelComments\Tests\Stubs\UlidUser;
use ByRcsc\LaravelComments\Tests\Stubs\UuidUser;

/*
 * The actor key type is read by the migration, so each case rebuilds the app
 * and the schema with the type under test before writing anything.
 */

it('supports uuid commentator keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'uuid']);

    $user = UuidUser::create(['name' => 'Grace Hopper']);
    $comment = post()->comment('From a uuid identity', by: $user);

    $read = $comment->refresh();

    expect($read->commentator_id)->toBe($user->getKey())
        ->and($read->commentator)->toBeInstanceOf(UuidUser::class);
});

it('supports ulid commentator keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'ulid']);

    $user = UlidUser::create(['name' => 'Annie Easley']);
    $comment = post()->comment('From a ulid identity', by: $user);

    $read = $comment->refresh();

    expect($read->commentator_id)->toBe($user->getKey())
        ->and($read->commentator)->toBeInstanceOf(UlidUser::class);
});

it('supports plain string commentator keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'string']);

    $user = StringUser::create(['id' => 'employee:7', 'name' => 'Mary Jackson']);
    $comment = post()->comment('From a string identity', by: $user);

    $read = $comment->refresh();

    expect($read->commentator_id)->toBe('employee:7')
        ->and($read->commentator)->toBeInstanceOf(StringUser::class);
});

/*
 * One setting covers both identities, so the reactor has to follow the
 * commentator rather than quietly stay an integer.
 */

it('supports uuid reactor keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'uuid']);

    $reactor = UuidUser::create(['name' => 'Grace Hopper']);
    $comment = post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com');

    $comment->react('👍', by: $reactor);

    expect($comment->reactions()->sole()->reactor_id)->toBe($reactor->getKey())
        ->and($comment->reactionsBy($reactor))->toBe(['👍'])
        ->and($comment->hasReactionFrom($reactor))->toBeTrue();
});

it('supports plain string reactor keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'string']);

    $reactor = StringUser::create(['id' => 'employee:7', 'name' => 'Mary Jackson']);
    $comment = post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com');

    $comment->react('👍', by: $reactor);

    expect($comment->reactions()->sole()->reactor_id)->toBe('employee:7')
        ->and($comment->hasReactionFrom($reactor, '👍'))->toBeTrue();
});

it('supports ulid reactor keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'ulid']);

    $reactor = UlidUser::create(['name' => 'Annie Easley']);
    $comment = post()->commentAsGuest('Hello', name: 'Jane', email: 'jane@example.com');

    $comment->react('👍', by: $reactor);

    expect($comment->reactions()->sole()->reactor_id)->toBe($reactor->getKey())
        ->and($comment->hasReactionFrom($reactor, '👍'))->toBeTrue();
});

/*
 * And the editor on a revision, the third identity the one setting covers.
 */

it('supports uuid editor keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'uuid']);

    $editor = UuidUser::create(['name' => 'Grace Hopper']);
    $comment = post()->commentAsGuest('First draft', name: 'Jane', email: 'jane@example.com');

    $comment->edit('Second draft', by: $editor);

    $revision = $comment->revisions()->sole();

    expect($revision->editor_id)->toBe($editor->getKey())
        ->and($revision->editor)->toBeInstanceOf(UuidUser::class);
});

it('supports plain string editor keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'string']);

    $editor = StringUser::create(['id' => 'employee:7', 'name' => 'Mary Jackson']);
    $comment = post()->commentAsGuest('First draft', name: 'Jane', email: 'jane@example.com');

    $comment->edit('Second draft', by: $editor);

    $revision = $comment->revisions()->sole();

    expect($revision->editor_id)->toBe('employee:7')
        ->and($revision->editor)->toBeInstanceOf(StringUser::class);
});

it('supports ulid editor keys', function (): void {
    $this->rebootWith(['comments.actor_key_type' => 'ulid']);

    $editor = UlidUser::create(['name' => 'Annie Easley']);
    $comment = post()->commentAsGuest('First draft', name: 'Jane', email: 'jane@example.com');

    $comment->edit('Second draft', by: $editor);

    expect($comment->revisions()->sole()->editor_id)->toBe($editor->getKey());
});
