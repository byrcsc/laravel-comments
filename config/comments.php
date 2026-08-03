<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Rename any of these to fit your schema conventions; the models read the
    | same values, so nothing else has to change. Set them before migrating.
    | Renaming a table that already holds comments requires your own migration.
    |
    | Every key must be present. A missing key fails at boot rather than on the
    | first comment.
    |
    */

    'table_names' => [
        'comments' => 'comments',
        'comment_reactions' => 'comment_reactions',
        'comment_revisions' => 'comment_revisions',
        'comment_attachments' => 'comment_attachments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Actor key type
    |--------------------------------------------------------------------------
    |
    | The key type of the polymorphic identity columns: the commentator on a
    | comment, and later the reactor on a reaction. Set it before running the
    | package migration when those models use non-integer keys. Valid values:
    | int, uuid, ulid, string.
    |
    | The commentable model's key is independent of this setting; adjust
    | `commentable_id` in the published migration when commentable models do
    | not use integer keys.
    |
    */

    'actor_key_type' => env('COMMENTS_ACTOR_KEY_TYPE', 'int'),

    /*
    |--------------------------------------------------------------------------
    | Maximum thread depth
    |--------------------------------------------------------------------------
    |
    | How deep replies may nest. A top-level comment sits at depth 0, a reply
    | to it at depth 1, and so on; creating a reply deeper than this throws.
    | Null means unlimited. The limit applies when a reply is created -
    | changing it never reshapes existing threads.
    |
    */

    'max_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Maximum body length
    |--------------------------------------------------------------------------
    |
    | The longest body, in characters, a comment may carry. Creating a comment
    | with a longer body throws; nothing is ever silently truncated. Null
    | means no limit beyond what the database column holds.
    |
    */

    'max_length' => null,

    /*
    |--------------------------------------------------------------------------
    | Initial moderation status
    |--------------------------------------------------------------------------
    |
    | Which status a new comment starts in: `default_status` for comments
    | written by a commentator model, `guest_status` for guest comments. Valid
    | values: pending, approved, rejected, spam.
    |
    | Guests default to pending on purpose. Approving anonymous content is a
    | decision the package will not make for you, so raising `default_status`
    | never drags guests along with it - set `guest_status` yourself when you
    | mean it.
    |
    | A commentable model implementing `DecidesCommentStatus` overrides both.
    |
    */

    'default_status' => 'approved',

    'guest_status' => 'pending',

    /*
    |--------------------------------------------------------------------------
    | Allowed reactions
    |--------------------------------------------------------------------------
    |
    | Which reactions a comment accepts. Reacting with anything outside this
    | list throws; set it to null to accept any non-empty string that fits the
    | `reaction` column - see AllowedReactions::MAX_LENGTH for that ceiling.
    |
    | These are stored and compared as given. Emoji that can be written more
    | than one way - a base character plus a variation selector, say - are two
    | different reactions to the database, so list the exact form your
    | interface sends.
    |
    */

    'allowed_reactions' => ['👍', '👎', '❤️', '🎉', '😄', '😢'],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Where `attachImage()` puts a file when the caller does not say. `disk` is
    | a filesystem disk name, or null for your application's default disk;
    | `directory` is the folder on it, and an empty string means the disk root.
    |
    | Only the image convenience reads these. `attach()` records a file your
    | application already stored, so it is told the disk and the path outright
    | and only falls back to `disk` for the name it writes down.
    |
    | The package never reads these files back and never deletes them. Removal
    | fires `AttachmentRemoved`, which is where deleting the bytes belongs.
    |
    */

    'attachments' => [
        'disk' => env('COMMENTS_ATTACHMENTS_DISK'),
        'directory' => 'comments/attachments',
    ],

];
