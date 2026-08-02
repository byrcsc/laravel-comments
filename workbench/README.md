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
`topLevel()->with('replies')` read, printed to the console.

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

Tear it down again with:

```bash
composer clear
```

## What lives where

- `app/Models/Post.php` — the commentable. One trait, no further wiring; this
  file is the whole integration.
- `app/Models/User.php` — the commentator. Any Eloquent model works; a user
  model is just the familiar case.
- `app/Providers/WorkbenchServiceProvider.php` — stands in for a real
  application's config edits. On shipped defaults today; later features add
  their settings here.
- `database/seeders/DatabaseSeeder.php` — the write-and-read loop.

The comments table is deliberately absent from `database/migrations/`; the
published copy is gitignored, because a real application's copy would be its
own file, not ours.
