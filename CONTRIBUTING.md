# Contributing

Thanks for helping. Clear, focused pull requests are easier to review and
maintain.

## Getting set up

```bash
git clone https://github.com/byrcsc/laravel-comments
cd laravel-comments
composer install
```

No database server and no `.env` are needed. The suite runs against an
in-memory SQLite database that Testbench sets up for you.

To reproduce a CI matrix failure locally, point the suite at a real engine with
`DB_DRIVER`:

```bash
DB_DRIVER=mysql composer test
DB_DRIVER=pgsql composer test
```

That path exists because SQLite does not tell the truth about cascade
behavior, and the force-delete subtree guarantee rides on it.

## The three checks

All three must be green before a pull request can be merged. CI runs them too,
but running them locally is faster than waiting.

```bash
composer test      # Pest, random order
composer analyse   # PHPStan, level max
composer format    # Pint, applies fixes
```

Two things worth knowing:

- **PHPStan runs at level max with no baseline.**
  If an error is genuinely a false positive, explain it in the pull request so
  we can find the right fix. Do not add `@phpstan-ignore`, a baseline entry, or
  a cast only to silence it.
- **Pint is the only style authority.** Run `composer format` before pushing.
  Avoid manual formatting that conflicts with its output.

The suite runs in random order and fails on warnings, risky tests, and an empty
suite. A test that passes only in a particular order is a bug in the test.

## The workbench

`workbench/` is a bootable demo application that installs the package the way a
real application would. It is where a change is driven by hand before it is
documented.

```bash
composer build    # set the demo up
composer clear    # tear it down again
```

See [workbench/README.md](workbench/README.md) for the demo loop. It is
excluded from the Composer dist archive and is not covered by CI, so it may
drift — if you change a public seam, run it and fix what broke.

## Where tests go

Tests are grouped by behavior, not by class: `Write/`, `Thread/`, `Deletion/`,
`Lifecycle/`, `Moderation/`, `Reactions/`, `Revisions/`, and `Attachments/`
today, with more groups arriving with their features. Extend the group that
owns the behavior rather than adding a parallel suite for a class.

`Attachments/ImageAttachmentTest.php` is the one suite that cannot be fully
green in a single install: `intervention/image` is a Composer suggestion, so
the default run covers the missing-dependency path and skips the pipeline,
while CI's `intervention/image` leg installs it and does the reverse. Run
`composer require --dev intervention/image:^4.0` locally to see that half.

The primary seam is the documented public API — the `HasComments` trait and
the `Comment` model — exercised against a real database through the migration
stub that ships. Do not mock package internals; use the framework fakes
(`Event::fake()` and friends) for what the framework owns.

`tests/ArchTest.php` enforces strict types, string-backed enums, a single
catchable exception root, and no leftover debugging calls. A `tests/Release/`
surface pin arrives before v1.0.0; once it exists, a failure there is asking
whether you meant to change the public API.

## What a good change looks like

**Adding a public method.** Decide first whether it belongs on the trait or on
`Comment`. The trait keeps commentable-centric members only; the comment model
answers questions about itself and its thread. If the README describes the
behavior, update it in the same pull request.

**Touching creation.** Every write path — the trait, the reply API, factories —
goes through the same `creating` gate that enforces the body length and depth
limits. Keep it that way; a second write path that skips the gate is a bug.

**Touching the body.** Editing has its own gate on `updating`, for the same
reason: it re-checks the length limit, refuses a tombstone, and is where the
revision and `edited_at` are decided. `edit()`, `update()`, and a plain
attribute save all pass through it, and a path that changes a body without it
is a bug too.

**Touching deletion.** Soft delete is a tombstone that keeps replies; force
delete removes the subtree through the database foreign key, not application
recursion. Both halves are load-bearing and both have tests on every CI
database driver.

**Fixing behavior.** Please include a test that fails before the change.

## Language

The package uses one canonical vocabulary: a **comment** on a **commentable**,
written by a **commentator** or a **guest**, forming **threads** through
**replies**, carrying a **status**. Documentation and docblocks state behavior,
constraints, and tradeoffs directly; the body is "stored verbatim", never
"sanitized for you".

## Package scope

The following are deliberate boundaries rather than gaps. Explain any proposed
change to them in the pull request:

- **No UI, rendering, or sanitization.** Bodies are stored verbatim; escaping
  belongs to the application that renders them.
- **No emailing guests.** A guest email is unverified input, not a mailbox the
  package writes to.
- **No roles, teams, tenancy, rate limiting, or spam detection.** The hooks
  exist; the mechanisms are the application's.
- **No compatibility aliases or deprecation shims.** A removed name is removed
  in the major release that removes it, and documented in the changelog.

## Commits and branches

Branch off `main` as `feat/…`, `fix/…`, `docs/…`, `refactor/…`, or `chore/…`,
and write [Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`). Mark breaking changes with
`!`, which is what decides whether a release is a major one.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
