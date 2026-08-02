# Changelog

All notable changes to `byrcsc/laravel-comments` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Comment model factory, and a bootable workbench demo application.
- Moderation: `approve()`, `reject()`, and `markAsSpam()` with an optional
  actor, idempotent and firing exactly one event per real state change.
- `CommentApproved`, `CommentRejected`, and `CommentMarkedAsSpam` events, over
  the shared `CommentModerated` base.
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
