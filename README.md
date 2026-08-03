# Laravel Comments

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-comments.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-comments)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-comments/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-comments/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-comments/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-comments/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-comments.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-comments)

Threaded comments for Eloquent models: polymorphic commentators with guest
support, moderation statuses, reactions, edit revisions, attachments, pinning,
and a full set of lifecycle events.

The package provides the comment engine. Your application keeps ownership of
its UI, rendering, users, and moderation rules.

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

## Installation

Install the package and publish its migrations:

```bash
composer require byrcsc/laravel-comments
php artisan vendor:publish --tag="comments-migrations"
php artisan migrate
```

Publish the configuration before the migration when you need custom table names
or non-integer actor identities:

```bash
php artisan vendor:publish --tag="comments-config"
```

Set `COMMENTS_ACTOR_KEY_TYPE` to `uuid`, `ulid`, or `string` as needed. It
covers commentator and reactor identities. The commentable model key is
independent; adjust `commentable_id` in the published migration when those
models do not use integer keys.

Notification wording and its mail views publish separately, and only when you
want to change them:

```bash
php artisan vendor:publish --tag="comments-translations"
php artisan vendor:publish --tag="comments-views"
```

## What the package stores

A **comment** belongs to one already-persisted **commentable** record and is
written by a **commentator**: any Eloquent model, or a **guest** identified
only by a name and an email address. Comments form **threads** through replies,
carry a **moderation status**, and accumulate **reactions**, **revisions**, and
**attachments**.

The package controls comment state and history. It does not control what your
application shows:

- **Status is package state, not visibility.** A comment is `pending`,
  `approved`, `rejected`, or `spam`. The package records transitions and fires
  events; deciding what a visitor sees stays in your queries, and the
  `approved()` scope is the tool for it.
- **The body is stored verbatim.** No sanitization, no markdown, no rendering.
  Escape or render on output in your application. Treat every body, and every
  guest name, as untrusted input.

## Quick start

Add `HasComments` to the model that receives comments:

```php
use ByRcsc\LaravelComments\Concerns\HasComments;

class Post extends Model
{
    use HasComments;
}
```

Write a comment, reply to it, react to it:

```php
$comment = $post->comment('Great write-up!', by: $user);

$reply = $comment->reply('Agreed, especially the last section.', by: $teammate);

$comment->react('👍', by: $teammate);
```

Guest comments carry a name and an email instead of a model, and start out
`pending` by default:

```php
$guest = $post->commentAsGuest(
    'Where can I download the slides?',
    name: 'Jane',
    email: 'jane@example.com',
);

$guest->approve(by: $moderator);
```

Read a thread the way you would read any relation:

```php
$post->comments()->approved()->topLevel()->with('replies')->get();
```

That is the whole loop. Threading depth, moderation hooks, reactions,
revisions, attachments, pinning, counts, notifications, and the rest are in the
[documentation](#documentation).

## What is included

- Polymorphic commentators, so users, admins, bots, or any other model can
  comment, plus guest comments identified by name and email.
- Threaded replies through a self-referencing parent, with a configurable
  maximum depth and scopes for top-level comments and whole threads.
- Moderation statuses (`pending`, `approved`, `rejected`, `spam`) with
  `approve()`, `reject()`, and `markAsSpam()`, a scope per status, a
  `DecidesCommentStatus` hook a commentable implements to choose the initial
  status, and configurable defaults. Guests start `pending` out of the box.
- Reactions on comments: an allowlist of permitted reactions, `react()`,
  `unreact()`, and `toggleReaction()`, one row per reactor per reaction
  enforced by the database, `hasReactionFrom()` and `reactionsBy()` for
  highlighting what an actor already pressed, a `reactions()` relation for the
  rows themselves, and a `reactionSummary()` of counts whose `reactionCounts`
  relation eager loads a whole thread's totals in one query.
- Soft deletes with tombstones for threads, subtree removal on force delete,
  an `edited_at` timestamp, and a `revisions()` relation of rows recording the
  prior body and the editor on every body change. `edit()` names the editor;
  any other body write records the same revision with a null one.
- Attachments as metadata rows (disk, path, name, MIME type, size) the
  application stored itself, plus `attachImage()` sugar built on Laravel's
  `Image` facade when `intervention/image` is installed.
- Optional denormalized comment counts on the commentable's own table,
  maintained by the trait and repairable with `comments:recount`.
- Pinning through `pinned_at`, with scopes for pinned comments and
  pinned-first ordering.
- One opt-in notification, disabled by default: the author of a comment is
  notified when it receives a reply. Wording and mail views are publishable and
  locale-aware.
- Lifecycle events for every transition: created, updated, deleted, restored,
  approved, rejected, marked as spam, reaction added and removed, attachment
  added and removed, pinned and unpinned.
- A `CommentPolicy` you may register, model factories, and a
  `Comments::fake()` test helper.

## Important behavior

- Guest comments start `pending` regardless of the configured default status,
  unless your model's own hook says otherwise. Approving guest content is a
  decision the package will not make for you.
- Transitions are idempotent. Approving an approved comment writes nothing and
  fires nothing, so counts and notifications built on the transition events
  cannot double up. Each comment carries its own status; approving a comment
  never touches its replies.
- Only commentators that are Laravel `Notifiable` models receive the reply
  notification. Guests are never emailed: the package refuses to send mail to
  an unverified address it cannot offer an unsubscribe path for.
- Reactions require an identified reactor. Guests cannot react, because
  deduplication is impossible without an identity. Reacting twice with the
  same reaction is a no-op rather than an error, and the database enforces the
  same rule behind the engine.
- A soft-deleted comment neither takes new reactions nor gives up the ones it
  had: both `react()` and `unreact()` refuse. The tombstone is history for a
  moderator to read, not a place to keep voting. Force deleting removes the
  reactions with the comment.
- Maximum thread depth is enforced when the reply is created. Existing threads
  are never reshaped by a config change.
- Soft deleting a comment keeps its replies and works as the thread's
  tombstone. Force deleting removes the whole subtree through the foreign key.
- Revisions and the `edited_at` timestamp are recorded through Eloquent model
  events, so `edit()`, `update()`, and a plain attribute save all leave the
  same trace, and `saveQuietly()`, the query builder, and raw SQL leave none.
  Only a body change counts: a status transition or a pin touches neither.
  A body change also goes through the same length limit a new comment does,
  and a soft-deleted comment refuses one: rewriting a tombstone would leave
  the moderator reading history that had been edited under them.
  Revision rows are ordinary rows, append-only by convention rather than by
  proof: there is no hash chain and no tamper evidence here. If you need a
  verifiable history, that is what
  [byrcsc/laravel-approval](https://github.com/byrcsc/laravel-approval) is for.
- The package never re-moderates an edited comment. `CommentUpdated` plus
  `wasChanged('body')` is the hook for sending one back to `pending`, because
  only your application can tell a fixed typo from an approved comment edited
  into an advert. The event fires after the revision is filed, so the listener
  has the previous body to judge against.
- Denormalized counts include approved, non-deleted comments only, and are
  maintained through model events. Query-builder updates, upserts, quiet
  saves, and raw SQL bypass model events; `comments:recount` is the backstop.
- `attach()` records metadata about a file your application already stored.
  The package never reads attachment bytes and never deletes files from disk;
  rows are removed with the comment, and the removal events are where file
  cleanup belongs. `attachImage()` requires `intervention/image`.
- The engine never authorizes its own methods. `CommentPolicy` ships with the
  package but is not registered for you; its defaults allow authors to update
  and delete their own comments and deny the moderation abilities until your
  application overrides them.
- Notification delivery is opt-in and disabled by default.
- The package provides no UI, role package, or tenancy layer. Your application
  owns those.

## Out of scope

The package draws its edges deliberately. What follows describes what it sets
out to do rather than what it might do later: treat none of it as planned work,
and none of it as ruled out forever.

- **A comment UI.** No Blade components, no Livewire, no endpoints. The
  package ships the queries a comment section is built from; the interface is
  yours.
- **Rendering and sanitization.** Bodies are stored verbatim and never parsed.
  Markdown, HTML filtering, and output escaping belong to the application that
  renders them.
- **Mentions.** Parsing `@names` out of a body is rendering-adjacent and
  app-specific. The created and updated events carry everything a mention
  scanner needs.
- **Subscriptions and broader notifications.** The reply notification is the
  one case with an unambiguous recipient. Watchers, digests, and
  notify-the-author-of-the-post flows need a subscription model only your
  application can define; build them on the events.
- **Emailing guests.** A guest email is unverified input, not a mailbox the
  package will write to.
- **Rate limiting and spam detection.** Guests defaulting to `pending` and the
  `spam` status are the hooks. Throttling and detection live in your
  middleware or your spam service integration.
- **Generic reactions.** Reactions attach to comments, not to arbitrary
  models.
- **Multi-stage moderation.** One status field, one transition at a time. For
  staged sign-off, workflows, and a verifiable action history, pair the
  package with [byrcsc/laravel-approval](https://github.com/byrcsc/laravel-approval);
  the documentation includes the recipe.
- **Roles, teams, org charts, or tenancy.** The policy and your resolvers
  decide who moderates. The package never expands a role into its members.
- **Blocking every write path.** Counts, revisions, and `edited_at` react to
  Eloquent model events. Raw SQL and query-builder writes are not intercepted.

## Documentation

- [Installation and setup](https://docs.rcsc.dev/laravel-comments/v1/installation)
- [Quick start](https://docs.rcsc.dev/laravel-comments/v1/quick-start)
- [Writing comments and threads](https://docs.rcsc.dev/laravel-comments/v1/comments-and-threads)
- [Guest comments](https://docs.rcsc.dev/laravel-comments/v1/guest-comments)
- [Moderation](https://docs.rcsc.dev/laravel-comments/v1/moderation)
- [Reactions](https://docs.rcsc.dev/laravel-comments/v1/reactions)
- [Revisions and deletion](https://docs.rcsc.dev/laravel-comments/v1/revisions-and-deletion)
- [Attachments](https://docs.rcsc.dev/laravel-comments/v1/attachments)
- [Counts, pinning, and scopes](https://docs.rcsc.dev/laravel-comments/v1/counts-pinning-and-scopes)
- [Notifications](https://docs.rcsc.dev/laravel-comments/v1/notifications)
- [Authorization](https://docs.rcsc.dev/laravel-comments/v1/authorization)
- [Testing](https://docs.rcsc.dev/laravel-comments/v1/testing)
- [Troubleshooting](https://docs.rcsc.dev/laravel-comments/v1/troubleshooting)

## Development

The local checks mirror CI:

```bash
composer install
composer test
composer analyse
vendor/bin/pint --test
```

PHPStan runs at `max` with no baseline. Tests use SQLite locally and run
against MySQL and PostgreSQL in CI. `tests/Release/` pins the public surface
and the documented guarantees. A failure there is asking whether you meant to
change the API. See [CONTRIBUTING.md](CONTRIBUTING.md).

`workbench/` is a bootable demo application that installs the package the way
a real application would, and exercises every integration seam: guest
comments, moderation hooks, threading depth, reactions, revisions,
attachments and the image pipeline, counts, pinning, the reply notification,
and a policy above this package's. `composer build` sets it up; see
[workbench/README.md](workbench/README.md) for the demo loop.

## Versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

- Upgrading within `1.x` is safe. Nothing you use will break.
- Only a new major version, like `2.0.0`, can break your code.
- If the README or the documentation describes it, it is safe to build on.
  If they don't, treat it as internal and expect it to change.

Bug fixes go into the newest version only. To get a fix, upgrade to it.

## Questions and issues

- **Stuck, or have an idea?** Start a
  [discussion](https://github.com/byrcsc/laravel-comments/discussions). Usage
  questions and feature ideas both live there.
- **Found a bug you can reproduce?**
  [Open an issue](https://github.com/byrcsc/laravel-comments/issues). A failing
  test is the fastest way to a fix, and a short reproduction is the next best
  thing.
- **Found a security problem?** Please don't open a public issue. See
  [SECURITY.md](SECURITY.md) for how to report it privately.
- **Planning a pull request?** [CONTRIBUTING.md](CONTRIBUTING.md) covers the
  setup and the three checks it needs to pass.

This package is maintained by one person, so replies can take a while.
Everything gets read.

## Credits

- [Ryan Catapang](https://github.com/byrcsc)
- [All contributors](https://github.com/byrcsc/laravel-comments/graphs/contributors)

## License

MIT. See [LICENSE.md](LICENSE.md). Changelog in [CHANGELOG.md](CHANGELOG.md).
