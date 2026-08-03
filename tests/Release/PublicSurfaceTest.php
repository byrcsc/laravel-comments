<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Comments;
use ByRcsc\LaravelComments\CommentsServiceProvider;
use ByRcsc\LaravelComments\Concerns\HasComments;
use ByRcsc\LaravelComments\Console\RecountCommentsCommand;
use ByRcsc\LaravelComments\Contracts\DecidesCommentStatus;
use ByRcsc\LaravelComments\Database\Factories\CommentAttachmentFactory;
use ByRcsc\LaravelComments\Database\Factories\CommentFactory;
use ByRcsc\LaravelComments\Database\Factories\CommentReactionFactory;
use ByRcsc\LaravelComments\Database\Factories\CommentRevisionFactory;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\AttachmentAdded;
use ByRcsc\LaravelComments\Events\AttachmentRemoved;
use ByRcsc\LaravelComments\Events\CommentApproved;
use ByRcsc\LaravelComments\Events\CommentAttachmentChanged;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Events\CommentDeleted;
use ByRcsc\LaravelComments\Events\CommentEvent;
use ByRcsc\LaravelComments\Events\CommentForceDeleted;
use ByRcsc\LaravelComments\Events\CommentMarkedAsSpam;
use ByRcsc\LaravelComments\Events\CommentModerated;
use ByRcsc\LaravelComments\Events\CommentPinChanged;
use ByRcsc\LaravelComments\Events\CommentPinned;
use ByRcsc\LaravelComments\Events\CommentReacted;
use ByRcsc\LaravelComments\Events\CommentRejected;
use ByRcsc\LaravelComments\Events\CommentRestored;
use ByRcsc\LaravelComments\Events\CommentUnpinned;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Events\ReactionAdded;
use ByRcsc\LaravelComments\Events\ReactionRemoved;
use ByRcsc\LaravelComments\Exceptions\AttachmentStorageFailedException;
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use ByRcsc\LaravelComments\Exceptions\CommentableNotPersistedException;
use ByRcsc\LaravelComments\Exceptions\CommentsCountNotEnabledException;
use ByRcsc\LaravelComments\Exceptions\CommentsException;
use ByRcsc\LaravelComments\Exceptions\CommentTrashedException;
use ByRcsc\LaravelComments\Exceptions\ImageSupportMissingException;
use ByRcsc\LaravelComments\Exceptions\InvalidAttachmentException;
use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelComments\Exceptions\InvalidReactionException;
use ByRcsc\LaravelComments\Exceptions\NotFakeableException;
use ByRcsc\LaravelComments\Exceptions\RevisionIsAppendOnlyException;
use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Models\CommentAttachment;
use ByRcsc\LaravelComments\Models\CommentReaction;
use ByRcsc\LaravelComments\Models\CommentRevision;
use ByRcsc\LaravelComments\Notifications\CommentReplied;
use ByRcsc\LaravelComments\Policies\CommentPolicy;
use ByRcsc\LaravelComments\Testing\CommentsFake;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/*
 * The public API, pinned.
 *
 * A failure here is not a bug report. It is this suite asking whether you
 * meant to change the surface the README and the documentation describe -
 * because upgrading within a major version is supposed to be safe, and
 * everything named below is something an application is allowed to build on.
 *
 * If a change here is deliberate: update the README and the documentation in
 * the same pull request, note it in the changelog, and if it removes or
 * renames anything, it is a major release.
 *
 * Anything not named here is internal and may change without notice.
 */

/**
 * A method's parameters and return type as two strings, for comparing against
 * what the documentation promises.
 *
 * `self` and `static` are resolved to the class that declares the method, so
 * spelling one of them differently is not a change worth failing over - only
 * the type an application would actually receive is.
 *
 * @return array{0: string, 1: string}
 */
function signatureOf(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);

    $render = function (?ReflectionType $type) use ($class): string {
        if (! $type instanceof ReflectionNamedType) {
            return (string) $type;
        }

        $name = match ($type->getName()) {
            'self', 'static' => $class,
            default => $type->getName(),
        };

        return ($type->allowsNull() && $name !== 'mixed' ? '?' : '').$name;
    };

    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => trim(
            $render($parameter->getType()).' $'.$parameter->getName().($parameter->isOptional() ? ' = ...' : ''),
        ),
        $reflection->getParameters(),
    );

    return [implode(', ', $parameters), $render($reflection->getReturnType())];
}

describe('every documented class exists and keeps its shape', function (): void {
    it('ships the trait, the models, and the enum', function (string $class): void {
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))->toBeTrue(
            "{$class} is part of the public API and is missing.",
        );
    })->with([
        HasComments::class,
        Comment::class,
        CommentReaction::class,
        CommentRevision::class,
        CommentAttachment::class,
        CommentStatus::class,
        DecidesCommentStatus::class,
        CommentPolicy::class,
        CommentReplied::class,
        Comments::class,
        CommentsFake::class,
        CommentsServiceProvider::class,
        RecountCommentsCommand::class,
        CommentFactory::class,
        CommentReactionFactory::class,
        CommentRevisionFactory::class,
        CommentAttachmentFactory::class,
    ]);

    it('ships every event', function (string $class): void {
        expect(is_a($class, CommentEvent::class, true))->toBeTrue("{$class} is missing or no longer an event.");
    })->with([
        CommentCreated::class,
        CommentUpdated::class,
        CommentDeleted::class,
        CommentRestored::class,
        CommentForceDeleted::class,
        CommentModerated::class,
        CommentApproved::class,
        CommentRejected::class,
        CommentMarkedAsSpam::class,
        CommentReacted::class,
        ReactionAdded::class,
        ReactionRemoved::class,
        CommentAttachmentChanged::class,
        AttachmentAdded::class,
        AttachmentRemoved::class,
        CommentPinChanged::class,
        CommentPinned::class,
        CommentUnpinned::class,
    ]);

    it('ships every exception under one catchable root', function (string $class): void {
        expect(is_a($class, CommentsException::class, true))->toBeTrue("{$class} is missing or no longer a CommentsException.");
    })->with([
        BodyTooLongException::class,
        ThreadTooDeepException::class,
        CommentableNotPersistedException::class,
        CommentTrashedException::class,
        InvalidConfigurationException::class,
        InvalidReactionException::class,
        RevisionIsAppendOnlyException::class,
        InvalidAttachmentException::class,
        ImageSupportMissingException::class,
        AttachmentStorageFailedException::class,
        CommentsCountNotEnabledException::class,
        NotFakeableException::class,
    ]);
});

describe('documented signatures', function (): void {
    it('pins the trait', function (string $method, string $parameters, string $returns): void {
        expect(signatureOf(HasComments::class, $method))->toBe([$parameters, $returns]);
    })->with([
        ['comments', '', 'Illuminate\Database\Eloquent\Relations\MorphMany'],
        ['comment', 'string $body, Illuminate\Database\Eloquent\Model $by', Comment::class],
        ['commentAsGuest', 'string $body, string $name, string $email', Comment::class],
        ['commentsCountColumn', '', '?string'],
        ['recountComments', '', 'int'],
    ]);

    it('pins the comment model', function (string $method, string $parameters, string $returns): void {
        expect(signatureOf(Comment::class, $method))->toBe([$parameters, $returns]);
    })->with([
        ['reply', 'string $body, Illuminate\Database\Eloquent\Model $by', Comment::class],
        ['replyAsGuest', 'string $body, string $name, string $email', Comment::class],
        ['depth', '', 'int'],
        ['isBy', 'Illuminate\Database\Eloquent\Model $actor', 'bool'],
        ['approve', '?Illuminate\Database\Eloquent\Model $by = ...', 'bool'],
        ['reject', '?Illuminate\Database\Eloquent\Model $by = ...', 'bool'],
        ['markAsSpam', '?Illuminate\Database\Eloquent\Model $by = ...', 'bool'],
        ['pin', '?Illuminate\Database\Eloquent\Model $by = ...', 'bool'],
        ['unpin', '?Illuminate\Database\Eloquent\Model $by = ...', 'bool'],
        ['edit', 'string $body, ?Illuminate\Database\Eloquent\Model $by = ...', 'bool'],
        ['react', 'string $reaction, Illuminate\Database\Eloquent\Model $by', CommentReaction::class],
        ['unreact', 'string $reaction, Illuminate\Database\Eloquent\Model $by', 'bool'],
        ['toggleReaction', 'string $reaction, Illuminate\Database\Eloquent\Model $by', 'bool'],
        ['hasReactionFrom', 'Illuminate\Database\Eloquent\Model $reactor, ?string $reaction = ...', 'bool'],
        ['reactionsBy', 'Illuminate\Database\Eloquent\Model $reactor', 'array'],
        ['reactionSummary', '', 'array'],
        ['attach', 'string $path, ?string $disk = ..., ?string $name = ..., ?string $mimeType = ..., ?int $size = ...', CommentAttachment::class],
        ['detach', CommentAttachment::class.' $attachment', 'bool'],
        [
            'attachImage',
            'Illuminate\Image\Image $image, ?string $name = ..., ?string $disk = ..., ?string $directory = ..., bool $optimize = ...',
            CommentAttachment::class,
        ],
    ]);

    it('pins the relations and scopes', function (string $method): void {
        expect(method_exists(Comment::class, $method))->toBeTrue("Comment::{$method}() is documented and missing.");
    })->with([
        'commentable', 'commentator', 'parent', 'replies',
        'reactions', 'reactionCounts', 'revisions', 'attachments',
        'scopeTopLevel', 'scopePending', 'scopeApproved', 'scopeRejected', 'scopeSpam',
        'scopePinned', 'scopePinnedFirst',
    ]);

    it('pins the policy ability map', function (string $ability): void {
        expect(method_exists(CommentPolicy::class, $ability))->toBeTrue("CommentPolicy::{$ability}() is documented and missing.");
    })->with([
        'create', 'update', 'delete', 'restore', 'forceDelete',
        'approve', 'reject', 'markAsSpam', 'pin', 'unpin', 'react', 'attach',
    ]);

    it('pins the fake', function (string $method, string $parameters, string $returns): void {
        expect(signatureOf(CommentsFake::class, $method))->toBe([$parameters, $returns]);
    })->with([
        ['assertCommented', '?Closure $callback = ...', 'void'],
        ['assertCommentedOn', 'Illuminate\Database\Eloquent\Model $commentable, ?Closure $callback = ...', 'void'],
        ['assertReplied', '?Closure $callback = ...', 'void'],
        ['assertReacted', '?Illuminate\Database\Eloquent\Model $reactor = ..., ?string $reaction = ...', 'void'],
        ['assertNothingCommented', '', 'void'],
        ['assertNothingReacted', '', 'void'],
        ['comments', '', 'Illuminate\Support\Collection'],
        ['commentsOn', 'Illuminate\Database\Eloquent\Model $commentable', 'Illuminate\Support\Collection'],
        ['replies', '', 'Illuminate\Support\Collection'],
        ['repliesTo', Comment::class.' $parent', 'Illuminate\Support\Collection'],
        ['reactionsOn', Comment::class.' $comment', 'array'],
    ]);

    it('pins the way a fake is reached', function (string $method, string $parameters, string $returns): void {
        expect(signatureOf(Comments::class, $method))->toBe([$parameters, $returns]);
    })->with([
        ['fake', '', CommentsFake::class],
        ['faked', '', '?'.CommentsFake::class],
        ['stopFaking', '', 'void'],
    ]);

    it('pins the notification', function (string $method, string $parameters, string $returns): void {
        expect(signatureOf(CommentReplied::class, $method))->toBe([$parameters, $returns]);
    })->with([
        ['__construct', Comment::class.' $reply', ''],
        ['via', 'object $notifiable', 'array'],
        ['toMail', 'object $notifiable', 'Illuminate\Notifications\Messages\MailMessage'],
        ['toArray', 'object $notifiable', 'array'],
    ]);

    it('pins the other factories', function (string $factory, string $method): void {
        expect(method_exists($factory, $method))->toBeTrue("{$factory}::{$method}() is documented and missing.");
    })->with([
        [CommentReactionFactory::class, 'forComment'],
        [CommentReactionFactory::class, 'by'],
        [CommentReactionFactory::class, 'reaction'],
        [CommentRevisionFactory::class, 'forComment'],
        [CommentRevisionFactory::class, 'by'],
        [CommentAttachmentFactory::class, 'forComment'],
        [CommentAttachmentFactory::class, 'on'],
        [CommentAttachmentFactory::class, 'image'],
    ]);

    it('pins the factory states', function (string $method): void {
        expect(method_exists(CommentFactory::class, $method))->toBeTrue("CommentFactory::{$method}() is documented and missing.");
    })->with([
        'forCommentable', 'by', 'guest', 'replyTo', 'threaded',
        'status', 'pending', 'approved', 'rejected', 'spam', 'pinned', 'trashed',
    ]);

    it('pins the statuses', function (): void {
        expect(CommentStatus::values())->toBe(['pending', 'approved', 'rejected', 'spam']);
    });

    it('pins what the moderation events carry', function (): void {
        expect(property_exists(CommentApproved::class, 'comment'))->toBeTrue()
            ->and(property_exists(CommentApproved::class, 'actor'))->toBeTrue()
            ->and(property_exists(CommentApproved::class, 'previousStatus'))->toBeTrue()
            ->and(property_exists(CommentPinned::class, 'actor'))->toBeTrue()
            ->and(property_exists(ReactionAdded::class, 'reactor'))->toBeTrue()
            ->and(property_exists(ReactionAdded::class, 'reaction'))->toBeTrue()
            ->and(property_exists(AttachmentAdded::class, 'attachment'))->toBeTrue()
            ->and(property_exists(CommentForceDeleted::class, 'countableRemoved'))->toBeTrue();
    });
});

describe('the published surface', function (): void {
    it('pins every config key', function (string $key): void {
        expect(config()->has("comments.{$key}"))->toBeTrue("comments.{$key} is documented and missing.");
    })->with([
        'table_names.comments',
        'table_names.comment_reactions',
        'table_names.comment_revisions',
        'table_names.comment_attachments',
        'actor_key_type',
        'max_depth',
        'max_length',
        'default_status',
        'guest_status',
        'allowed_reactions',
        'attachments.disk',
        'attachments.directory',
        'notifications.reply.enabled',
        'notifications.reply.channels',
    ]);

    it('pins every publish tag', function (string $tag): void {
        expect(ServiceProvider::pathsToPublish(CommentsServiceProvider::class, $tag))
            ->not->toBeEmpty("The {$tag} publish tag is documented and missing.");
    })->with([
        'comments-migrations',
        'comments-config',
        'comments-translations',
        'comments-views',
    ]);

    it('pins the artisan command', function (): void {
        expect(array_keys(Artisan::all()))->toContain('comments:recount');
    });

    it('pins the migrations that publish', function (string $migration): void {
        expect(file_exists(__DIR__."/../../database/migrations/{$migration}.php.stub"))->toBeTrue();
    })->with([
        'create_comments_table',
        'create_comment_reactions_table',
        'create_comment_revisions_table',
        'create_comment_attachments_table',
    ]);
});
