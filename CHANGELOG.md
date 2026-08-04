# Changelog

All notable changes to `byrcsc/laravel-comments` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-04

First release. The complete comment engine and its installation schema.

### Added

- Package skeleton: service provider, publishable config, migrations,
  translations, and views.
- `comments` table with a polymorphic commentable, a polymorphic commentator
  with guest support, self-referencing threads, soft deletes, and the status
  and pinning columns later features build on.
- `HasComments` trait with `comments()`, `comment()`, and `commentAsGuest()`.
- `Comment` model with `reply()`, `replyAsGuest()`, `parent()`, `replies()`,
  `depth()`, and the `topLevel()` scope.
- Configurable maximum thread depth and maximum body length, enforced at
  creation with package exceptions.
- Lifecycle events: created, updated, deleted, restored, force deleted.
  `CommentForceDeleted` carries `$countableRemoved`, how many approved,
  non-deleted comments the removed subtree held: the database's cascade fires
  no events for the replies it takes, and nothing can recover the number
  afterwards.
- Comment model factory, and a bootable workbench demo application.
- Moderation: `approve()`, `reject()`, and `markAsSpam()` with an optional
  actor, idempotent and firing exactly one event per real state change.
- `CommentApproved`, `CommentRejected`, and `CommentMarkedAsSpam` events, over
  the shared `CommentModerated` base. Each carries `$previousStatus`, the
  status the comment moved from, because counts and the reply notification are
  both built on "did it leave the approved set?", which the comment alone
  cannot answer after the write.
- `pending()`, `approved()`, `rejected()`, and `spam()` scopes.
- Initial status resolution: `comments.default_status` and
  `comments.guest_status` config keys, overridden by a commentable
  implementing `DecidesCommentStatus`. Guests start `pending`.
- Comment factory status states: `status()`, `pending()`, `approved()`,
  `rejected()`, and `spam()`.
- Reactions: `comment_reactions` table with cascade on delete and database
  uniqueness over comment, reactor, and reaction; `react()`, `unreact()`, and
  `toggleReaction()`; `reactions()`, `reactionCounts()`, `reactionSummary()`,
  `hasReactionFrom()`, and `reactionsBy()`; the `CommentReaction` model.
- `comments.allowed_reactions` config key, with a small emoji set by default
  and null to accept any reaction.
- `ReactionAdded` and `ReactionRemoved` events, over the shared
  `CommentReacted` base.
- `InvalidReactionException` and `CommentTrashedException`.
- Revisions: `comment_revisions` table with cascade on delete, automatic
  recording of the prior body and `edited_at` on every body change, `edit()`
  for naming the editor, a `revisions()` relation, and the `CommentRevision`
  model, which refuses updates.
- `RevisionIsAppendOnlyException`.
- Body writes share one gate: an edit re-checks `comments.max_length` and
  refuses a soft-deleted comment.
- `CommentUpdated` is dispatched after the revision is recorded, so a
  re-moderation listener has the previous body to compare against.
- Attachments: `comment_attachments` table with cascade on delete; `attach()`,
  `detach()`, and the `attachments()` relation; the `CommentAttachment` model.
  Metadata is recorded as given and files on disk are never read or deleted.
- `attachImage()`, which processes an `Illuminate\Image\Image` through the
  framework's own pipeline, stores it, and records the result. It needs
  Laravel 13's `Image` facade and `intervention/image`, listed under Composer
  `suggest` rather than required.
- `comments.attachments.disk` and `comments.attachments.directory` config
  keys, and `comments.table_names.comment_attachments`.
- `AttachmentAdded` and `AttachmentRemoved` events, over the shared
  `CommentAttachmentChanged` base. Force deleting a comment fires
  `AttachmentRemoved` for every attachment in the subtree the cascade takes,
  before it runs.
- `InvalidAttachmentException`, `ImageSupportMissingException`, and
  `AttachmentStorageFailedException`.
- Pinning: `pin()` and `unpin()` with an optional actor, idempotent and firing
  exactly one event per real change; `pinned()` and `pinnedFirst()` scopes;
  `CommentPinned` and `CommentUnpinned` events over the shared
  `CommentPinChanged` base. Pinning is independent of moderation status, and
  several comments may be pinned at once.
- Denormalized comment counts: opt a commentable in by returning a column name
  from `commentsCountColumn()`, and the count of its approved, non-deleted
  comments is maintained through the package's own events in atomic database
  increments. The count follows every status change rather than only the
  transition events, so `approve()` and a plain `$comment->status = ...;
  save()` are counted alike. `recountComments()` repairs one record, and the
  `comments:recount` artisan command repairs a model type, a single record, or
  everything, reporting what it changed. `--dry-run` reports without writing.
- `CommentsCountNotEnabledException`.
- The reply notification: `CommentReplied`, queued, off until
  `comments.notifications.reply.enabled` says otherwise. It fires when a reply
  enters the approved set, at most once per reply, to the parent comment's
  author when that commentator is a Laravel `Notifiable` model. Guest-authored
  parents and self-replies are never notified. Channels come from
  `comments.notifications.reply.channels`; binding a subclass over
  `CommentReplied` in the container replaces the message.
- `reply_notified_at` column on `comments`, the persisted at-most-once marker.
- Reply notification wording in `comments::comments` and a publishable
  `comments::mail.reply` markdown view.
- `CommentPolicy`, shipped but never registered: `create`, `update`, `delete`,
  `restore`, `forceDelete`, `approve`, `reject`, `markAsSpam`, `pin`, `unpin`,
  `react`, and `attach`. Authors update and delete their own comments;
  moderation denies until an application overrides it. The engine's own
  methods never consult it.
- `Comments::fake()`, which swaps the write path for a recorder so an
  application's own tests can assert what it asked the engine for without
  touching a table. `CommentsFake` records comments, replies, and reactions,
  and carries `assertCommented()`, `assertCommentedOn()`, `assertReplied()`,
  `assertReacted()`, `assertNothingCommented()`, and `assertNothingReacted()`,
  plus `comments()`, `commentsOn()`, `replies()`, `repliesTo()`, and
  `reactionsOn()` for reading back what it recorded. The writes it does not
  record, moderating, editing, pinning, attaching, and deleting, throw
  `NotFakeableException` while it is recording, rather than writing against a
  key no table has.
- Comment factory states: `pinned()`, `trashed()`, and `threaded()`.
- `CommentReactionFactory`, `CommentRevisionFactory`, and
  `CommentAttachmentFactory`.
- `Comment::isBy()`, which answers whether a comment was written by a given
  model through the whole commentator morph.
- `tests/Release/`, a suite that pins the entire documented public surface and
  the guarantees the README states in prose, and runs the quick start against
  the workbench's own models.
- Tested on PHP 8.3 and 8.4, Laravel 12 and 13, against SQLite, MySQL, and
  PostgreSQL, with PHPStan at maximum level and no baseline, and Pint.

[Unreleased]: https://github.com/byrcsc/laravel-comments/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/byrcsc/laravel-comments/releases/tag/v1.0.0
