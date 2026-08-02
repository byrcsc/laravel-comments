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
tag, migrates, and seeds. The seeder is the README quick start run for real:
an authenticated comment, a reply, a guest comment, and the
`topLevel()->with('replies')` read, printed to the console. It then runs the
moderation loop: the guest comment arrives pending, the hook on `Post` holds a
comment carrying a link, a moderator approves and marks spam by hand, and the
`approved()` scope decides both readings.

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
  link, which is what the per-model status hook looks like in practice.
- `app/Models/User.php` — the commentator. Any Eloquent model works; a user
  model is just the familiar case.
- `app/Providers/WorkbenchServiceProvider.php` — stands in for a real
  application's config edits. On shipped defaults today; later features add
  their settings here.
- `database/seeders/DatabaseSeeder.php` — the write-and-read loop.

The comments table is deliberately absent from `database/migrations/`; the
published copy is gitignored, because a real application's copy would be its
own file, not ours.
