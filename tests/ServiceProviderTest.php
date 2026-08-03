<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\CommentsServiceProvider;
use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

it('loads the config with its documented defaults', function (): void {
    expect(config('comments.table_names.comments'))->toBe('comments')
        ->and(config('comments.actor_key_type'))->toBe('int')
        ->and(config('comments.max_depth'))->toBe(3)
        ->and(config('comments.max_length'))->toBeNull()
        ->and(config('comments.default_status'))->toBe('approved')
        ->and(config('comments.guest_status'))->toBe('pending')
        ->and(config('comments.table_names.comment_reactions'))->toBe('comment_reactions')
        ->and(config('comments.table_names.comment_revisions'))->toBe('comment_revisions')
        ->and(config('comments.table_names.comment_attachments'))->toBe('comment_attachments')
        ->and(config('comments.allowed_reactions'))->toBeArray()
        ->and(config('comments.attachments.disk'))->toBeNull()
        ->and(config('comments.attachments.directory'))->toBe('comments/attachments');
});

it('registers the four publish tags', function (): void {
    foreach (['comments-migrations', 'comments-config', 'comments-translations', 'comments-views'] as $tag) {
        expect(ServiceProvider::pathsToPublish(CommentsServiceProvider::class, $tag))
            ->not->toBeEmpty("The {$tag} publish tag is not registered.");
    }
});

describe('boot-time validation', function (): void {
    it('rejects a missing table name', function (): void {
        expect(fn () => $this->rebootWith(['comments.table_names' => []]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects a blank table name', function (): void {
        expect(fn () => $this->rebootWith(['comments.table_names' => ['comments' => '  ']]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an unknown actor key type', function (): void {
        expect(fn () => $this->rebootWith(['comments.actor_key_type' => 'guid']))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects a negative max depth', function (): void {
        expect(fn () => $this->rebootWith(['comments.max_depth' => -1]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects a non-integer max length', function (): void {
        expect(fn () => $this->rebootWith(['comments.max_length' => '280']))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an unknown default status', function (): void {
        expect(fn () => $this->rebootWith(['comments.default_status' => 'published']))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects a missing guest status rather than inventing one', function (): void {
        expect(fn () => $this->rebootWith(['comments.guest_status' => null]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an allowlist holding something that is not a reaction', function (): void {
        expect(fn () => $this->rebootWith(['comments.allowed_reactions' => ['👍', 42]]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an allowlist that is neither a list nor null', function (): void {
        expect(fn () => $this->rebootWith(['comments.allowed_reactions' => '👍']))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an attachments section that is not an array', function (): void {
        expect(fn () => $this->rebootWith(['comments.attachments' => 'uploads']))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an attachment disk that is not a name', function (): void {
        expect(fn () => $this->rebootWith(['comments.attachments' => ['disk' => 42, 'directory' => '']]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects an attachment directory that is not a string', function (): void {
        expect(fn () => $this->rebootWith(['comments.attachments' => ['disk' => null, 'directory' => 42]]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('accepts null for unlimited depth and length', function (): void {
        $this->rebootWith(['comments.max_depth' => null, 'comments.max_length' => null]);

        expect(config('comments.max_depth'))->toBeNull()
            ->and(config('comments.max_length'))->toBeNull();
    });
});

it('honors a renamed comments table', function (): void {
    $this->rebootWith(['comments.table_names' => [
        'comments' => 'discussion_entries',
        'comment_reactions' => 'discussion_entry_reactions',
        'comment_revisions' => 'discussion_entry_revisions',
        'comment_attachments' => 'discussion_entry_attachments',
    ]]);

    $comment = post()->comment('On a renamed table', by: user());

    expect($comment->getTable())->toBe('discussion_entries')
        ->and(DB::table('discussion_entries')->count())->toBe(1);
});
