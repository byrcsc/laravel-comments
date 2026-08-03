<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Models;

use ByRcsc\LaravelComments\Database\Factories\CommentFactory;
use ByRcsc\LaravelComments\Enums\CommentStatus;
use ByRcsc\LaravelComments\Events\CommentApproved;
use ByRcsc\LaravelComments\Events\CommentCreated;
use ByRcsc\LaravelComments\Events\CommentDeleted;
use ByRcsc\LaravelComments\Events\CommentForceDeleted;
use ByRcsc\LaravelComments\Events\CommentMarkedAsSpam;
use ByRcsc\LaravelComments\Events\CommentModerated;
use ByRcsc\LaravelComments\Events\CommentRejected;
use ByRcsc\LaravelComments\Events\CommentRestored;
use ByRcsc\LaravelComments\Events\CommentUpdated;
use ByRcsc\LaravelComments\Events\ReactionAdded;
use ByRcsc\LaravelComments\Events\ReactionRemoved;
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use ByRcsc\LaravelComments\Exceptions\CommentTrashedException;
use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
use ByRcsc\LaravelComments\Support\AllowedReactions;
use ByRcsc\LaravelComments\Support\InitialStatus;
use ByRcsc\LaravelComments\Support\TableNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One comment on one commentable record, written by a commentator model or by
 * a guest carrying only a name and an email - exactly one of the two, never
 * both. Replies point at their parent through `parent_id`, and the whole
 * subtree hangs off that key: soft deleting a comment leaves its replies
 * readable under a tombstone, force deleting removes them with it through the
 * database's cascade.
 *
 * The body is stored verbatim. Escaping, markdown, and everything else about
 * rendering belongs to the application - treat the body, the guest name, and
 * the guest email as untrusted input.
 *
 * A comment also carries a moderation status, which is package state rather
 * than visibility: `approve()`, `reject()`, and `markAsSpam()` move it and the
 * matching scopes read it, but what a visitor sees is decided by the
 * application's own queries.
 *
 * Editing the body stamps `edited_at` and files a revision holding what it
 * said before. That happens through Eloquent's model events, so it covers
 * `edit()`, `update()`, and a plain attribute save alike - and nothing that
 * goes around them.
 *
 * @property int $id
 * @property string $commentable_type
 * @property int|string $commentable_id
 * @property string|null $commentator_type
 * @property int|string|null $commentator_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property int|null $parent_id
 * @property string $body
 * @property CommentStatus $status
 * @property Carbon|null $edited_at
 * @property Carbon|null $pinned_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $commentable
 * @property-read Model|null $commentator
 * @property-read Comment|null $parent
 * @property-read Collection<int, Comment> $replies
 * @property-read Collection<int, CommentReaction> $reactions
 * @property-read Collection<int, CommentReaction> $reactionCounts
 * @property-read Collection<int, CommentRevision> $revisions
 */
final class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /**
     * The body as it stood before the edit currently being saved, carried from
     * `updating` (where the original is still readable) to `updated` (where
     * the write is known to have landed). Null between saves.
     */
    private ?string $bodyBeforeEdit = null;

    /**
     * Who the caller said is making the current edit, for the same hop. Only
     * `edit()` sets it: a plain attribute save names nobody, and the package
     * will not guess at an authenticated user on its behalf.
     */
    private ?Model $pendingEditor = null;

    /**
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => CommentCreated::class,
        'deleted' => CommentDeleted::class,
        'restored' => CommentRestored::class,
        'forceDeleted' => CommentForceDeleted::class,
    ];

    public function getTable(): string
    {
        return TableNames::for('comments');
    }

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * The limits hold on `creating` so every write path - the trait, the
     * reply API, factories - passes through the same gate, and the initial
     * status is resolved there for the same reason.
     *
     * Edit tracking rides the update events for the same reason, and takes
     * two of them: `updating` is where the previous body is still readable and
     * where `edited_at` can join the same statement, and `updated` is where
     * the write is known to have landed and the revision is safe to write.
     * Anything that skips model events - `saveQuietly()`, the query builder,
     * raw SQL - skips this too, which the README states plainly.
     *
     * `CommentUpdated` is dispatched from here rather than through
     * `$dispatchesEvents` for two reasons, and both matter. Eloquent stops
     * firing the plain `updated` event as soon as a mapped event's listener
     * returns anything at all, which would take the revision with it; and a
     * re-moderation listener needs the revision already filed, because the
     * body it is judging against is what the revision holds. That first reason
     * is Eloquent internals, verified against Laravel 12 and 13 - recheck it
     * when raising the supported range.
     */
    protected static function booted(): void
    {
        self::creating(function (self $comment): void {
            $comment->guardBodyLength();
            $comment->guardDepth();
            $comment->resolveInitialStatus();
        });

        self::updating(function (self $comment): void {
            $comment->trackBodyEdit();
        });

        self::updated(function (self $comment): void {
            $comment->recordRevision();

            event(new CommentUpdated($comment));
        });
    }

    /**
     * The record this comment is on.
     *
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model that wrote this comment, or null for a guest comment - guests
     * live in `guest_name` and `guest_email` instead.
     *
     * @return MorphTo<Model, $this>
     */
    public function commentator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct replies only. Load a whole thread by nesting:
     * `with('replies.replies')` to the depth the UI renders.
     *
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Comments that start threads rather than continue them.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Comments waiting on a moderator. This is the moderation queue: the
     * package ships no queue model because a scope is one.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', CommentStatus::Pending);
    }

    /**
     * The comments a visitor may generally see - though that remains the
     * application's call, and this scope is only the tool for making it.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', CommentStatus::Approved);
    }

    /**
     * @param  Builder<Comment>  $query
     */
    public function scopeRejected(Builder $query): void
    {
        $query->where('status', CommentStatus::Rejected);
    }

    /**
     * Kept apart from rejected so spam stays feedable to spam tooling rather
     * than lost among ordinary moderator decisions.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeSpam(Builder $query): void
    {
        $query->where('status', CommentStatus::Spam);
    }

    /**
     * Approve this comment, optionally recording who did it.
     *
     * Returns whether the status actually moved: approving an approved
     * comment writes nothing and fires nothing, so a listener counting
     * approvals counts real ones. Replies are untouched - each comment in a
     * thread carries its own status.
     */
    public function approve(?Model $by = null): bool
    {
        return $this->transitionTo(
            CommentStatus::Approved,
            new CommentApproved($this, $by),
        );
    }

    /**
     * Reject this comment: it stays stored, and stays out of the approved set.
     *
     * @see approve() for the return value and the idempotency guarantee.
     */
    public function reject(?Model $by = null): bool
    {
        return $this->transitionTo(
            CommentStatus::Rejected,
            new CommentRejected($this, $by),
        );
    }

    /**
     * Mark this comment as spam. The package detects nothing; this is where
     * your own detection, or a moderator, records the verdict.
     *
     * @see approve() for the return value and the idempotency guarantee.
     */
    public function markAsSpam(?Model $by = null): bool
    {
        return $this->transitionTo(
            CommentStatus::Spam,
            new CommentMarkedAsSpam($this, $by),
        );
    }

    /**
     * What this comment said before each edit, oldest first. Every body change
     * leaves one, whether it went through `edit()` or through a plain
     * attribute save.
     *
     * @return HasMany<CommentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class)->oldest('id');
    }

    /**
     * Change the body, recording who did it.
     *
     * The revision is written either way; this is how the editor gets named.
     * A plain `$comment->update(['body' => ...])` records the same history
     * with a null editor, because the package will not invent an actor.
     *
     * Returns whether anything was saved: editing a comment to the body it
     * already has writes nothing and records nothing.
     */
    public function edit(string $body, ?Model $by = null): bool
    {
        if (! $this->exists) {
            throw new LogicException('Cannot edit an unsaved comment. Persist it first.');
        }

        if ($this->body === $body) {
            return false;
        }

        $this->pendingEditor = $by;
        $this->body = $body;

        try {
            return $this->save();
        } finally {
            $this->pendingEditor = null;
        }
    }

    /**
     * Every reaction row on this comment. `reactionSummary()` is what a
     * listing wants; this is for the times you need the reactors themselves.
     *
     * @return HasMany<CommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    /**
     * The counts, grouped in the database. Eager load it across a whole thread
     * - `with('reactionCounts')` - and rendering every comment's reactions
     * costs one query for the page rather than one per comment.
     *
     * The rows come back as partially hydrated reaction models carrying only
     * the group key and its total; read them through `reactionSummary()`.
     *
     * @return HasMany<CommentReaction, $this>
     */
    public function reactionCounts(): HasMany
    {
        return $this->reactions()
            ->select('comment_id', 'reaction')
            ->selectRaw('count(*) as total')
            ->groupBy('comment_id', 'reaction')
            ->orderBy('reaction');
    }

    /**
     * Reaction to count, in a stable order, for the reactions this comment
     * actually has. A reaction nobody used is absent rather than zero.
     *
     * @return array<string, int>
     */
    public function reactionSummary(): array
    {
        $summary = [];

        foreach ($this->reactionCounts as $row) {
            /** @var mixed $total */
            $total = $row->getAttribute('total');

            // Drivers disagree about whether an aggregate comes back an int or
            // a string, and neither is worth leaking to the caller.
            $summary[$row->reaction] = is_numeric($total) ? (int) $total : 0;
        }

        return $summary;
    }

    /**
     * React to this comment. Reacting again with the same reaction is a no-op
     * that returns the existing row, so a double tap cannot inflate a count
     * and callers need no guard of their own.
     *
     * There is no guest path here: deduplication needs an identity, and a name
     * and an email are not one.
     */
    public function react(string $reaction, Model $by): CommentReaction
    {
        $this->guardReactionsWritable();

        AllowedReactions::assert($reaction);

        $existing = $this->findReaction($reaction, $by);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $row = $this->reactions()->create([
                'reactor_type' => $by->getMorphClass(),
                'reactor_id' => $by->getKey(),
                'reaction' => $reaction,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Another request got there between the check and the write. The
            // index is what actually guarantees one row per reactor per
            // reaction; this turns its verdict back into the promised no-op.
            $this->forgetLoadedReactions();

            return $this->findReaction($reaction, $by) ?? throw $e;
        }

        $this->forgetLoadedReactions();

        event(new ReactionAdded($this, $by, $reaction));

        return $row;
    }

    /**
     * Take a reaction back. Returns whether there was one to take back, so a
     * caller can tell a removal from a request that had already happened.
     */
    public function unreact(string $reaction, Model $by): bool
    {
        $this->guardReactionsWritable();

        $existing = $this->findReaction($reaction, $by);

        if ($existing === null) {
            return false;
        }

        $existing->delete();

        $this->forgetLoadedReactions();

        event(new ReactionRemoved($this, $by, $reaction));

        return true;
    }

    /**
     * One tap in an interface, one call here. Returns whether the reactor now
     * holds the reaction: true when it was added, false when it was removed.
     */
    public function toggleReaction(string $reaction, Model $by): bool
    {
        if ($this->unreact($reaction, $by)) {
            return false;
        }

        $this->react($reaction, $by);

        return true;
    }

    /**
     * Whether this reactor has reacted at all, or with one reaction in
     * particular - which is what highlights the buttons they already pressed.
     */
    public function hasReactionFrom(Model $reactor, ?string $reaction = null): bool
    {
        if ($reaction !== null) {
            return $this->findReaction($reaction, $reactor) !== null;
        }

        return $this->reactionsFrom($reactor)->isNotEmpty();
    }

    /**
     * Which reactions this reactor left, as plain strings, in a stable order.
     *
     * @return list<string>
     */
    public function reactionsBy(Model $reactor): array
    {
        return array_values(
            $this->reactionsFrom($reactor)
                ->map(fn (CommentReaction $row): string => $row->reaction)
                ->sort()
                ->all()
        );
    }

    /**
     * Reply to this comment as a commentator model.
     */
    public function reply(string $body, Model $by): self
    {
        return $this->createReply([
            'commentator_type' => $by->getMorphClass(),
            'commentator_id' => $by->getKey(),
            'body' => $body,
        ]);
    }

    /**
     * Reply to this comment as a guest. The name and email are stored as
     * given and treated as untrusted input everywhere; nothing is verified
     * and nothing is mailed.
     */
    public function replyAsGuest(string $body, string $name, string $email): self
    {
        return $this->createReply([
            'guest_name' => $name,
            'guest_email' => $email,
            'body' => $body,
        ]);
    }

    /**
     * How far from the top of its thread this comment sits: 0 for a top-level
     * comment, one more for each level of reply above it.
     */
    public function depth(): int
    {
        $depth = 0;
        $current = $this->parent;

        while ($current !== null) {
            $depth++;
            $current = $current->parent;
        }

        return $depth;
    }

    /**
     * A transition is one write and one event, or neither. The event is
     * dispatched after the write so a listener that reloads the comment sees
     * the new status, and only once the write is known to have landed: a host
     * `saving` listener that halts the save must not leave counts and
     * notifications counting a state change the table never took.
     */
    private function transitionTo(CommentStatus $status, CommentModerated $event): bool
    {
        if ($this->status === $status) {
            return false;
        }

        $previous = $this->status;
        $this->status = $status;

        if ($this->save() === false) {
            $this->status = $previous;

            return false;
        }

        event($event);

        return true;
    }

    /**
     * A body change, and only a body change, is gated, stamps `edited_at`, and
     * earns a revision. Moving the status, pinning, restoring, and every other
     * update leave all three alone: they change what the package knows about
     * the comment, not what its author wrote.
     *
     * The length limit is re-checked here so an edit cannot do what a write
     * was refused, and a tombstone is refused outright: a comment kept as
     * history that can still be rewritten is not history.
     */
    private function trackBodyEdit(): void
    {
        if (! $this->isDirty('body')) {
            $this->bodyBeforeEdit = null;

            return;
        }

        if ($this->trashed()) {
            throw CommentTrashedException::cannotEdit($this);
        }

        $this->guardBodyLength();

        /** @var mixed $original */
        $original = $this->getOriginal('body');

        if (! is_string($original)) {
            // The body was never loaded, so there is nothing truthful to file.
            // Recording an empty prior body would be worse than refusing: the
            // one job of a revision is to say what the comment used to hold.
            throw new LogicException(
                'Cannot edit the body of a comment loaded without it. Select the body column, or reload the comment first.'
            );
        }

        $this->bodyBeforeEdit = $original;

        // Set here rather than in `updated` so it rides the same statement:
        // a second write would fire a second round of these events.
        $this->edited_at = $this->freshTimestamp();
    }

    /**
     * Written from `updated` rather than `updating`, so a halted or failed
     * save leaves no revision behind for an edit the table never took.
     */
    private function recordRevision(): void
    {
        if ($this->bodyBeforeEdit === null) {
            return;
        }

        $body = $this->bodyBeforeEdit;
        $editor = $this->pendingEditor;

        $this->bodyBeforeEdit = null;

        // Eloquent syncs the original attributes only once the whole save
        // finishes, so until then the body still looks dirty. A listener that
        // saves the comment again - the re-moderation pattern does exactly
        // that - would otherwise file this same edit a second time. Internals,
        // verified against Laravel 12 and 13; recheck when raising the range.
        $this->syncOriginalAttribute('body');

        $this->revisions()->create([
            'body' => $body,
            'editor_type' => $editor?->getMorphClass(),
            'editor_id' => $editor?->getKey(),
        ]);

        $this->unsetRelation('revisions');
    }

    /**
     * Both directions are gated, not only adding: a tombstone keeps the
     * reactions it already had, for the moderator who has to judge what
     * happened, and one that can be quietly emptied is not that. Reading is
     * never gated.
     */
    private function guardReactionsWritable(): void
    {
        if (! $this->exists) {
            throw new LogicException('Cannot change reactions on an unsaved comment. Persist it first.');
        }

        if ($this->trashed()) {
            throw CommentTrashedException::cannotChangeReactions($this);
        }
    }

    /**
     * This reactor's rows on this comment. The one place that decides between
     * a loaded relation and a query, so every lookup gets the same answer for
     * the same state.
     *
     * @return Collection<int, CommentReaction>
     */
    private function reactionsFrom(Model $reactor): Collection
    {
        if ($this->relationLoaded('reactions')) {
            return $this->reactions
                ->filter(fn (CommentReaction $row): bool => $row->isBy($reactor))
                ->values();
        }

        return $this->reactionsQueryFor($reactor)->get();
    }

    private function findReaction(string $reaction, Model $reactor): ?CommentReaction
    {
        return $this->reactionsFrom($reactor)
            ->first(fn (CommentReaction $row): bool => $row->reaction === $reaction);
    }

    /**
     * @return HasMany<CommentReaction, $this>
     */
    private function reactionsQueryFor(Model $reactor): HasMany
    {
        return $this->reactions()
            ->where('reactor_type', $reactor->getMorphClass())
            ->where('reactor_id', $reactor->getKey());
    }

    /**
     * A loaded relation is a snapshot, and this comment just changed what it
     * is a snapshot of. Dropping it keeps the next read honest rather than
     * leaving a stale count behind a write that succeeded.
     */
    private function forgetLoadedReactions(): void
    {
        $this->unsetRelation('reactions');
        $this->unsetRelation('reactionCounts');
    }

    /**
     * An explicitly supplied status wins over both the hook and the defaults:
     * a factory, a seeder, or an application restoring an export all know what
     * they meant, and resolution is only there for the writes that said
     * nothing.
     */
    private function resolveInitialStatus(): void
    {
        if ($this->getAttribute('status') !== null) {
            return;
        }

        $this->status = InitialStatus::for($this);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createReply(array $attributes): self
    {
        if (! $this->exists) {
            throw new LogicException('Cannot reply to an unsaved comment. Persist the parent first.');
        }

        $reply = new self(array_merge($attributes, [
            'commentable_type' => $this->commentable_type,
            'commentable_id' => $this->commentable_id,
            'parent_id' => $this->getKey(),
        ]));

        // Handing over what this comment already holds: the depth walk and the
        // initial-status hook both read up the chain, and neither should pay
        // for a row that is sitting in memory.
        $reply->setRelation('parent', $this);

        if ($this->relationLoaded('commentable')) {
            $reply->setRelation('commentable', $this->getRelation('commentable'));
        }

        $reply->save();

        return $reply;
    }

    private function guardBodyLength(): void
    {
        $max = config('comments.max_length');

        if (! is_int($max)) {
            return;
        }

        $length = mb_strlen((string) $this->body);

        if ($length > $max) {
            throw BodyTooLongException::forLength($length, $max);
        }
    }

    /**
     * Walks the parent chain rather than trusting a stored depth column: the
     * chain is the truth, and the walk is bounded by the limit it enforces.
     */
    private function guardDepth(): void
    {
        $max = config('comments.max_depth');

        if (! is_int($max) || $this->parent_id === null) {
            return;
        }

        $depth = 1 + ($this->parent?->depth() ?? 0);

        if ($depth > $max) {
            throw ThreadTooDeepException::atDepth($depth, $max);
        }
    }
}
