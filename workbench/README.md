# Workbench

A bootable demo application that installs the package the way a real
application would: the package's migration is *published* into the Testbench
skeleton and migrated, not loaded from the package, so the build exercises the
same install path the README documents.

## The demo loop

```bash
composer build
```

The build creates the SQLite database, publishes the `comments-migrations`
tag, migrates, and seeds. The seeder walks every integration seam in order and
prints what it found, so a successful build is a successful install-and-use
loop for the whole package:

1. **The quick start**, run for real: an authenticated comment, a reply, a
   guest comment, and the `topLevel()->with('replies')` read.
2. **Threading** — replies down to `comments.max_depth`, and the exception the
   next one earns. The limit is checked when a reply is created; nothing
   stores a depth.
3. **Moderation** — the guest comment arrives pending, the hook on `Post`
   holds a comment carrying a link, and one comment walks every transition:
   rejected, approved, marked as spam. The `pending()`, `approved()`,
   `rejected()`, and `spam()` scopes decide every reading. Approving twice
   moves nothing.
4. **Reactions** — react, react again to no effect, toggle back off, and get
   refused a reaction the allowlist does not carry.
5. **Edits and revisions** — an edit files a revision, stamps `edited_at`, and
   through this app's own listener sends the approved comment back to pending.
   A status change files nothing.
6. **Attachments** — the app writes a file and hands the package its metadata;
   `attachImage()` optimizes, stores, and records in one call when
   `intervention/image` is installed, and skips itself when it is not. The
   detach deletes the file through this app's `AttachmentRemoved` listener,
   never through the package.
7. **Pinning** — pin, pin again to no effect, read `pinnedFirst()`, unpin. The
   status never moves.
8. **Counts** — the opted-in column, corrupted with a raw update and repaired
   with `recountComments()`.
9. **Authorization** — the policy this app registered in one line, above an
   engine that authorized none of the writes above it. The seeder has no
   authenticated user at all.
10. **The reply notification** — delivered because this app turned it on, once
   per reply, never to a guest-authored comment.

To see the image pipeline, install the suggestion first:

```bash
composer require --dev intervention/image:^4.0
composer build
```

To poke at the result by hand:

```bash
vendor/bin/testbench tinker
```

```php
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

$post = Post::first();
$post->comments()->topLevel()->with('replies')->get();

$user = User::first();
$comment = $post->comment('Written from tinker', by: $user);
$comment->reply('A reply from tinker too', by: $user);
$comment->depth();          // 0
$comment->replies;
```

Moderation by hand:

```php
$moderator = User::first();

// Guests are read from `comments.guest_status`, so raising
// `comments.default_status` never drags them along.
$guest = $post->commentAsGuest('Hi', name: 'Jane', email: 'jane@example.com');
$guest->status;                     // CommentStatus::Pending

// The hook on Post has the last word.
$post->comment('See http://example.test', by: $moderator)->status;  // Pending

$guest->approve(by: $moderator);    // true, and fires CommentApproved
$guest->approve(by: $moderator);    // false: nothing moved, nothing fired

$post->comments()->pending()->count();
$post->comments()->approved()->count();
```

Reactions by hand:

```php
$comment = $post->comments()->first();

$comment->react('👍', by: $moderator);
$comment->react('👍', by: $moderator);   // The same tap twice: one row.
$comment->reactionSummary();             // ['👍' => 1]

$comment->hasReactionFrom($moderator, '👍');   // true
$comment->reactionsBy($moderator);             // ['👍']

$comment->toggleReaction('👍', by: $moderator);  // false: taken back off
$comment->react('🦆', by: $moderator);           // InvalidReactionException

// One query for a whole thread's counts, not one per comment.
$post->comments()->with('reactionCounts')->get()
    ->map(fn ($c) => $c->reactionSummary());
```

Edits and revisions by hand:

```php
$comment->edit('Second draft', by: $moderator);
$comment->edited_at;                        // stamped
$comment->revisions->pluck('body');         // ['First draft']
$comment->revisions->first()->editor->name; // 'Grace Hopper'

// A plain save records the same revision with nobody named.
$comment->body = 'Third draft';
$comment->save();

// A status change is not an edit: no revision, edited_at stands.
$comment->reject(by: $moderator);
$comment->revisions()->count();             // still 2

// The demo app's own listener sent it back to pending on each edit.
// Nothing in the package does that; see WorkbenchServiceProvider.
```

Attachments by hand:

```php
Storage::disk('local')->put('comments/attachments/notes.txt', 'Your bytes.');

$attachment = $comment->attach(
    path: 'comments/attachments/notes.txt',
    name: 'Notes.txt',
    mimeType: 'text/plain',
    size: 11,
);

$comment->attachments;          // the relation, oldest first

// With intervention/image installed:
$comment->attachImage(Image::fromPath('/path/to/screenshot.png'));

// The row goes; the file goes too, through this app's listener.
$comment->detach($attachment);
```

Pinning, counts, and the policy by hand:

```php
$comment->pin(by: $moderator);          // true
$comment->pin(by: $moderator);          // false: already pinned
$post->comments()->pinnedFirst()->pluck('body');
$comment->unpin(by: $moderator);

$post->refresh()->comments_count;       // approved and not deleted
$post->recountComments();               // repair one record

Gate::forUser($author)->allows('update', $comment);      // true: their own
Gate::forUser($moderator)->allows('approve', $comment);  // false until overridden
```

```bash
php artisan comments:recount --dry-run
php artisan comments:recount --model="Workbench\App\Models\Post"
```

The reply notification by hand. Mail goes to the log, so nothing leaves the
machine:

```php
$parent = $post->comment('Ask me anything', by: $author);
$reply = $parent->reply('How do I upgrade?', by: $teammate);

$reply->fresh()->reply_notified_at;     // stamped: notified once, and only once

// A guest-authored parent is never mailed, whatever the config says.
$guest = $post->commentAsGuest('Hi', name: 'Jane', email: 'jane@example.com');
$guest->approve(by: $moderator);
$guest->reply('An answer', by: $teammate);   // nobody notified
```

Tear it down again with:

```bash
composer clear
```

## Staged sign-off: pairing with laravel-approval

This package has one status field and one transition at a time. When a comment
needs two sign-offs, a delegation chain, or a verifiable action history, that
is [byrcsc/laravel-approval](https://github.com/byrcsc/laravel-approval)'s job,
and the two compose without either one growing.

Keep the comment pending until approval says otherwise, and let this package
stay the record of what a comment *is*:

```php
// config/comments.php - nothing goes live until it has been signed off.
'default_status' => 'pending',
'guest_status' => 'pending',
```

Start the approval workflow from the created event:

```php
Event::listen(CommentCreated::class, function (CommentCreated $event): void {
    $event->comment->submitForApproval();   // laravel-approval
});
```

Then let the workflow's outcome drive the one transition this package owns:

```php
Event::listen(ApprovalGranted::class, fn ($event) => $event->approvable->approve(
    by: $event->approver,
));

Event::listen(ApprovalDenied::class, fn ($event) => $event->approvable->reject(
    by: $event->approver,
));
```

The division stays clean: laravel-approval owns who signs off, in what order,
and the tamper-evident history of it; laravel-comments owns the status the
comment carries and the scopes your queries read. Neither package learns about
the other, and `approve()` staying idempotent means a workflow that fires twice
still transitions once.

## What lives where

- `app/Models/Post.php` — the commentable. One trait is the whole integration;
  this one also implements `DecidesCommentStatus` to hold comments carrying a
  link, and names a `comments_count` column to opt into denormalized counts.
- `app/Models/User.php` — the commentator. Any Eloquent model works; a user
  model is just the familiar case. It carries `Notifiable`, which is what the
  reply notification needs and what the framework's base user class does not
  add for you.
- `app/Providers/WorkbenchServiceProvider.php` — stands in for a real
  application's config edits and wiring, and holds every integration point in
  one file: the one config key the demo changes, the one-line policy
  registration, the re-moderation listener the package refuses to ship, and
  the attachment cleanup that belongs on this side of the storage boundary.
- `database/migrations/` — the application's own schema, including the
  `comments_count` column. The comments tables are deliberately absent: they
  are published from the package by `composer build` and land beside this file,
  gitignored, exactly as a real application's copy would be its own file rather
  than ours.
- `database/seeders/DatabaseSeeder.php` — the demo loop above, in order.
