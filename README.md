# Laravel Comments

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-comments.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-comments)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-comments/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-comments/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-comments/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-comments/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-comments.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-comments)

Threaded comments for any Eloquent model.

Your posts need comments? A sale needs a remark? A ticket needs an internal
note? Add one trait to the model and it has them: threaded, moderated, and
ready for reactions, edit history, and attachments when you need those too.

The table is polymorphic, so posts, orders, tickets, and invoices all share it.

The package handles storing and moving comments. Your application keeps its UI,
its rendering, its users, and its moderation rules.

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
  `approve()`, `reject()`, and `markAsSpam()`, a scope per status, and a
  `DecidesCommentStatus` hook a commentable implements to choose the initial
  status.
- Reactions with a configurable allowlist, `react()`, `unreact()`, and
  `toggleReaction()`, one row per reactor per reaction enforced by the
  database, and a `reactionSummary()` that eager loads a whole thread's totals
  in one query.
- Soft deletes with tombstones for threads, subtree removal on force delete, an
  `edited_at` timestamp, and a `revisions()` relation recording the prior body
  and the editor on every body change.
- Attachments as metadata rows for files the application stored itself, plus
  `attachImage()` sugar built on Laravel's `Image` facade when
  `intervention/image` is installed.
- Optional denormalized comment counts on the commentable's own table,
  maintained in atomic increments and repairable with `recountComments()` or
  the `comments:recount` command.
- Pinning through `pin()` and `unpin()`, with `pinned()` and `pinnedFirst()`
  scopes and their own events.
- One opt-in notification, disabled by default: the author of a comment is
  notified when it receives a reply. Wording and mail views are publishable and
  locale-aware.
- Lifecycle events for every transition: created, updated, deleted, restored,
  approved, rejected, marked as spam, reactions, attachments, and pinning.
- A `CommentPolicy` you may register, factories for every model with states for
  guest, each status, pinned, soft-deleted, and threaded comments, and a
  `Comments::fake()` helper that records what your application asked the engine
  for instead of writing it.

## Important behavior

- Guest comments start `pending` regardless of the configured default status,
  unless your model's own hook says otherwise. Approving guest content is a
  decision the package will not make for you.
- Transitions are idempotent. Approving an approved comment writes nothing and
  fires nothing, so counts and notifications built on the events cannot double
  up. Each comment carries its own status; approving one never touches its
  replies.
- Reactions require an identified reactor. Guests cannot react, because
  deduplication is impossible without an identity, and the database enforces
  the same rule behind the engine.
- Only commentators that are Laravel `Notifiable` models receive the reply
  notification. Guests are never emailed: the package refuses to send mail to
  an unverified address it cannot offer an unsubscribe path for. Notification
  delivery is opt-in and disabled by default.
- The reply notification fires when a reply *enters the approved set*, at most
  once per reply for its whole life, proven by a `reply_notified_at` column
  rather than anything held in memory.
- Soft deleting a comment keeps its replies and works as the thread's
  tombstone; force deleting removes the whole subtree through the foreign key.
  A tombstone neither takes new reactions, attachments, or edits, nor gives up
  the ones it had.
- Maximum thread depth is enforced when the reply is created. Existing threads
  are never reshaped by a config change.
- Revisions, `edited_at`, and counts are maintained through Eloquent model
  events, so `edit()`, `update()`, and a plain attribute save all leave the
  same trace, while `saveQuietly()`, the query builder, and raw SQL leave none.
  `comments:recount` is the backstop for counts, and `--dry-run` shows what it
  would change first.
- Revision rows are append-only by convention rather than by proof: there is no
  hash chain and no tamper evidence here. Treat the history as a record for a
  moderator to read, not as evidence that would survive a hostile database.
- The package never re-moderates an edited comment. `CommentUpdated` plus
  `wasChanged('body')` is the hook for sending one back to `pending`, because
  only your application can tell a fixed typo from an approved comment edited
  into an advert. The event fires after the revision is filed, so the listener
  has the previous body to judge against.
- Denormalized counts are off until a model returns a column name from
  `commentsCountColumn()`; the column and its migration are the application's.
  They include approved, non-deleted comments only, and every status change
  counts however it was made.
- Pinning is independent of moderation, and several comments may be pinned on
  the same record: a one-pin rule is a decision for your controller, not the
  engine.
- `attach()` records metadata about a file your application already stored. The
  package never opens the file, never checks that it is there, and never
  deletes it. `AttachmentRemoved` fires for every row a force delete takes,
  while the disk and path are still readable, and that is the file-cleanup
  hook.
- `attachImage()` is the only path where the package writes bytes to a disk. It
  needs the framework's `Image` facade, which arrived in Laravel 13, and
  `intervention/image`, a Composer suggestion rather than a requirement;
  without it the call throws `ImageSupportMissingException`.
- The engine never authorizes its own methods. `CommentPolicy` ships with the
  package but is not registered for you: the provider defines no gates and no
  policies. Register it with `Gate::policy(Comment::class,
  CommentPolicy::class)` and enforce it where your application calls the
  engine. Moderation abilities deny until you override them.
- `Comments::fake()` fakes writes, not reads. A faked comment carries a key and
  can be replied to, but it is not a row: relations and scopes still read a
  database that has nothing in it. Ask the fake what it recorded instead.
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
- **Multi-stage moderation.** One status field, one transition at a time. There
  are no approval stages, no sign-off order, and no verifiable action history.
  The moderation events are the hook if you need to drive one.
- **Roles, teams, org charts, or tenancy.** The policy and your resolvers
  decide who moderates. The package never expands a role into its members.
- **Blocking every write path.** Counts, revisions, and `edited_at` react to
  Eloquent model events. Raw SQL and query-builder writes are not intercepted.

## Documentation

- [Introduction](https://docs.rcsc.dev/laravel-comments/v1/introduction)
- [Installation and setup](https://docs.rcsc.dev/laravel-comments/v1/installation)
- [Configuration](https://docs.rcsc.dev/laravel-comments/v1/configuration)
- [Quick start](https://docs.rcsc.dev/laravel-comments/v1/quick-start)
- [Commentable models](https://docs.rcsc.dev/laravel-comments/v1/commentable-models)
- [Threads and replies](https://docs.rcsc.dev/laravel-comments/v1/threads-and-replies)
- [Initial status](https://docs.rcsc.dev/laravel-comments/v1/initial-status)
- [Moderation](https://docs.rcsc.dev/laravel-comments/v1/moderation)
- [Reactions](https://docs.rcsc.dev/laravel-comments/v1/reactions)
- [Revisions](https://docs.rcsc.dev/laravel-comments/v1/revisions)
- [Deletion](https://docs.rcsc.dev/laravel-comments/v1/deletion)
- [Attachments](https://docs.rcsc.dev/laravel-comments/v1/attachments)
- [Comment counts](https://docs.rcsc.dev/laravel-comments/v1/comment-counts)
- [Pinning](https://docs.rcsc.dev/laravel-comments/v1/pinning)
- [Notifications](https://docs.rcsc.dev/laravel-comments/v1/notifications)
- [Authorization](https://docs.rcsc.dev/laravel-comments/v1/authorization)
- [Events](https://docs.rcsc.dev/laravel-comments/v1/events)
- [Console commands](https://docs.rcsc.dev/laravel-comments/v1/console-commands)
- [Rendering and safety](https://docs.rcsc.dev/laravel-comments/v1/rendering-and-safety)
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
against MySQL and PostgreSQL in CI. `tests/Release/` pins the public surface,
the documented guarantees, and the quick start above, run against the
workbench's own models. A failure there is asking whether you meant to change
the API. See [CONTRIBUTING.md](CONTRIBUTING.md).

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
