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
use ByRcsc\LaravelComments\Exceptions\BodyTooLongException;
use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
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
 */
final class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /**
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => CommentCreated::class,
        'updated' => CommentUpdated::class,
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
     */
    protected static function booted(): void
    {
        self::creating(function (self $comment): void {
            $comment->guardBodyLength();
            $comment->guardDepth();
            $comment->resolveInitialStatus();
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
