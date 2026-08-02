# Security Policy

## Reporting a vulnerability

Please report privately, not in a public issue. Use GitHub's
[private vulnerability reporting](https://github.com/byrcsc/laravel-comments/security/advisories/new)
on this repository; it opens a channel visible only to the maintainers.

Include the package version, the Laravel and PHP versions, the database driver,
and enough detail to reproduce. If it helps, a failing test is the clearest
possible report.

You can expect an acknowledgement within a week. Because this is a
single-maintainer package, please do not expect a same-day response; if the
issue is being actively exploited, say so in the title.

## Supported versions

Security fixes are released for the latest package version and are not
backported, and neither are ordinary bug fixes. Only the current major receives
either; when a new major is released, the previous one stops receiving fixes.
Keep your dependency constraint current.

## What this package does and does not protect

This package stores user-supplied content, so the boundary matters.

It **does**:

- store the comment body verbatim and never execute, render, or parse it;
- treat guest names and guest emails as untrusted input everywhere, with no
  verification and no outbound mail to them;
- enforce the configured body length and thread depth limits at the engine
  boundary, with exceptions rather than silent truncation;
- remove a comment's whole reply subtree through a database foreign key on
  force delete, so hard deletion cannot leave orphaned replies behind.

It **does not**:

- sanitize or escape anything. **Rendering a comment body, a guest name, or a
  guest email without escaping is an XSS vulnerability in the application, not
  in the package.** Escape on output, always;
- authorize anything. The engine never checks who may write, edit, delete, or
  moderate; that is the application's policy layer;
- rate limit, throttle, or detect spam. Guests defaulting to pending (with the
  moderation layer) and the spam status are hooks, not protection;
- intercept writes that bypass Eloquent model events. Raw SQL and
  query-builder writes skip the creation limits.

## Reporting something that is documented

The list above is the intended boundary, and the README's **Important
behavior** section states it in full. A report that a documented limitation
exists is not a vulnerability, but a report that the package does not actually
hold a line it claims to hold very much is. When in doubt, report it.
