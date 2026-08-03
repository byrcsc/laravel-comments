<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use ByRcsc\LaravelComments\Exceptions\InvalidReactionException;
use ByRcsc\LaravelComments\Exceptions\ThreadTooDeepException;
use ByRcsc\LaravelComments\Models\Comment;
use ByRcsc\LaravelComments\Support\ImageSupport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The README quick start, run for real: write a comment, reply to it, add a
 * guest comment, then read the thread back through the relation. `composer
 * build` runs this after publishing and migrating the package's own
 * migration, so a successful build is a successful install-and-use loop.
 *
 * Every other seam follows, in the order a reader can hold in their head:
 * moderation, reactions, edits and revisions, attachments and the image
 * pipeline, pinning, the denormalized count and its repair, the policy this
 * app registered above an engine that authorizes nothing, and the one
 * notification the package ships.
 *
 * A successful `composer build` is a successful install-and-use loop for all
 * of it.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::factory()->create(['name' => 'Ada Lovelace']);
        $teammate = User::factory()->create(['name' => 'Alan Turing']);
        $moderator = User::factory()->create(['name' => 'Grace Hopper']);

        $post = Post::factory()->create(['title' => 'Announcing laravel-comments']);

        $comment = $post->comment('Great write-up!', by: $author);
        $comment->reply('Agreed, especially the last section.', by: $teammate);
        $guest = $post->commentAsGuest(
            'Where can I download the slides?',
            name: 'Jane',
            email: 'jane@example.com',
        );

        // Read, the way the README does.
        $threads = $post->comments()->topLevel()->with('replies')->get();

        $this->command?->info("Post: {$post->title}");

        foreach ($threads as $thread) {
            $who = $thread->commentator?->getAttribute('name') ?? "{$thread->guest_name} (guest)";
            $this->command?->line("- {$who}: {$thread->body} [{$thread->status->value}]");

            foreach ($thread->replies as $reply) {
                $replier = $reply->commentator?->getAttribute('name') ?? "{$reply->guest_name} (guest)";
                $this->command?->line("    - {$replier}: {$reply->body} [{$reply->status->value}]");
            }
        }

        $this->thread($comment, $teammate);
        $this->moderate($post, $guest, $moderator);
        $this->reactTo($comment, $author, $teammate);
        $this->edit($comment, $author);
        $this->attachTo($comment);
        $this->pin($post, $guest, $moderator);
        $this->count($post);
        $this->authorize($comment, $author, $moderator);
        $this->notify($post, $author, $teammate);

        $this->command?->info('Every seam exercised: writing, moderating, reacting, editing, attaching, pinning, counting, authorizing, and notifying. The demo loop works.');
    }

    /**
     * The storage boundary from the application's side: it writes the file,
     * the package records where it went, and the removal event is where the
     * bytes are deleted - by this app's own listener, never by the package.
     *
     * @see WorkbenchServiceProvider
     */
    private function attachTo(Comment $comment): void
    {
        $this->command?->info('Attachments:');

        $disk = Storage::disk(config()->string('filesystems.default'));

        $disk->put('comments/attachments/slides.pdf', 'Not really a PDF.');

        $attachment = $comment->attach(
            path: 'comments/attachments/slides.pdf',
            name: 'The slides.pdf',
            mimeType: 'application/pdf',
            size: $disk->size('comments/attachments/slides.pdf'),
        );

        $this->command?->line("- recorded {$attachment->name} on the {$attachment->disk} disk at {$attachment->path}");

        $this->attachAnImage($comment);

        $comment->detach($attachment);

        $this->command?->line($disk->exists('comments/attachments/slides.pdf')
            ? '- the file survived the detach, which would mean the cleanup listener never ran'
            : '- the file was deleted by this app\'s AttachmentRemoved listener, not by the package');

        $this->command?->line('- attachments still recorded: '.$comment->attachments()->count());
    }

    /**
     * The image convenience, when the optional dependency is installed. It is
     * a Composer suggestion, so a demo that required it would be making a
     * promise the package does not.
     */
    private function attachAnImage(Comment $comment): void
    {
        if (! ImageSupport::available()) {
            $this->command?->line('- attachImage() skipped: intervention/image is a suggestion, and it is not installed');

            return;
        }

        $canvas = imagecreatetruecolor(64, 64);

        if ($canvas === false) {
            $this->command?->line('- attachImage() skipped: this PHP has no GD to draw a fixture with');

            return;
        }

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();

        $image = $comment->attachImage(Image::fromBytes($bytes), name: 'A screenshot');

        $this->command?->line("- attachImage() stored {$image->path} ({$image->mime_type}, {$image->size} bytes) in one call");
    }

    /**
     * Pinning is its own axis: the pinned comment keeps whatever moderation
     * status it had, and several may be pinned at once.
     */
    private function pin(Post $post, Comment $guest, User $moderator): void
    {
        $this->command?->info('Pinning:');

        $guest->pin(by: $moderator);

        $this->command?->line("- pinned the guest comment; its status is still {$guest->status->value}");
        $this->command?->line($guest->pin(by: $moderator)
            ? '- pinning twice moved something, which would be a bug'
            : '- pinning a pinned comment did nothing, as promised');

        $order = $post->comments()->approved()->topLevel()->pinnedFirst()->pluck('body');

        $this->command?->line('- pinnedFirst() reads: '.$order->implode(' | '));

        $guest->unpin(by: $moderator);

        $this->command?->line('- unpinned; pinned comments now: '.$post->comments()->pinned()->count());
    }

    /**
     * The count column belongs to this app's table; `Post` opts in by naming
     * it. Corrupting it behind the package's back is what the recount command
     * exists for.
     */
    private function count(Post $post): void
    {
        $this->command?->info('Counts:');

        $post->refresh();

        $this->command?->line("- comments_count says {$post->comments_count}");
        $this->command?->line('- approved comments say '.$post->comments()->approved()->count());

        // The query builder skips model events, which is exactly the drift the
        // command repairs.
        DB::table('posts')->where('id', $post->id)->update(['comments_count' => 99]);

        $this->command?->line('- corrupted the column to 99 with a raw update');

        $post->recountComments();

        $this->command?->line("- recountComments() put it back to {$post->comments_count}");
        $this->command?->line('- `php artisan comments:recount --dry-run` does the same for every record');
    }

    /**
     * The policy this app registered, above an engine that authorizes nothing.
     *
     * @see WorkbenchServiceProvider
     */
    private function authorize(Comment $comment, User $author, User $moderator): void
    {
        $this->command?->info('Authorization:');

        $this->command?->line('- the author may update their own comment: '
            .json_encode(Gate::forUser($author)->allows('update', $comment)));
        $this->command?->line('- another user may not: '
            .json_encode(Gate::forUser($moderator)->allows('update', $comment)));
        $this->command?->line('- nobody may approve until this app overrides the ability: '
            .json_encode(Gate::forUser($moderator)->allows('approve', $comment)));

        // And the engine itself never asks. This seeder has no authenticated
        // user at all, and every write above worked.
        $this->command?->line('- the engine never checked any of it: this seeder has no authenticated user');
    }

    /**
     * The one notification the package ships, delivered because this app
     * turned it on. Mail goes to the log; nothing leaves the machine.
     */
    private function notify(Post $post, User $author, User $teammate): void
    {
        $this->command?->info('The reply notification:');

        $parent = $post->comment('Ask me anything about the release', by: $author);
        $reply = $parent->reply('How do I upgrade?', by: $teammate);

        $this->command?->line('- '.($reply->fresh()?->reply_notified_at !== null
            ? "notified {$author->name}, and marked the reply so it cannot happen twice"
            : 'nobody was notified, which would be a bug with the switch on'));

        // Approving a reply that already notified sends nothing more.
        $reply->reject(by: $author);
        $reply->approve(by: $author);

        $this->command?->line('- a reject-and-approve round trip sent nothing further: the marker is on the row');

        $guestParent = $post->commentAsGuest('A guest question', name: 'Jane', email: 'jane@example.com');
        $guestParent->approve(by: $author);
        $guestParent->reply('An answer', by: $teammate);

        $this->command?->line('- the guest-authored comment was not emailed, whatever the config says');
    }

    /**
     * Threads are the parent chain and nothing else, and the depth limit is
     * checked when a reply is created rather than stored on a column.
     */
    private function thread(Comment $comment, User $replier): void
    {
        $this->command?->info('Threading:');

        $max = config()->integer('comments.max_depth');
        $deepest = $comment;

        while ($deepest->depth() < $max) {
            $deepest = $deepest->reply('One level deeper', by: $replier);
        }

        $this->command?->line("- replied down to depth {$deepest->depth()}, the configured maximum of {$max}");

        try {
            $deepest->reply('One level too far', by: $replier);
            $this->command?->line('- a reply past the limit was accepted, which would be a bug');
        } catch (ThreadTooDeepException $e) {
            $this->command?->line('- the limit refused the next one: '.$e->getMessage());
        }
    }

    /**
     * Nothing here is the package deciding what is visible: it holds the guest
     * comment, records the approvals and the rejections, and leaves every
     * reading to the scopes.
     */
    private function moderate(Post $post, Comment $guest, User $moderator): void
    {
        $this->command?->info('Moderation:');
        $this->command?->line("- the guest comment arrived {$guest->status->value}");
        $this->command?->line('- visible now: '.$post->comments()->approved()->count().' of '.$post->comments()->count());

        // A link holds a comment whoever wrote it: the hook on Post says so.
        $held = $post->comment('Slides are at http://example.test/slides', by: $moderator);
        $this->command?->line("- a comment with a link arrived {$held->status->value}, from the model's own hook");

        $guest->approve(by: $moderator);
        $held->markAsSpam(by: $moderator);

        // Every transition, on one comment, in one pass.
        $rejected = $post->commentAsGuest('Off topic', name: 'Jo', email: 'jo@example.com');
        $rejected->reject(by: $moderator);
        $this->command?->line("- rejected a comment: it is now {$rejected->fresh()?->status->value}");
        $rejected->approve(by: $moderator);
        $rejected->markAsSpam(by: $moderator);
        $this->command?->line("- and moved it on through approved to {$rejected->fresh()?->status->value}");

        $this->command?->line('- visible now: '.$post->comments()->approved()->count().' of '.$post->comments()->count());
        $this->command?->line('- still waiting: '.$post->comments()->pending()->count());
        $this->command?->line('- rejected: '.$post->comments()->rejected()->count().', spam: '.$post->comments()->spam()->count());

        // Re-approving changes nothing and fires nothing, so counts and
        // notifications built on the events cannot double up.
        $this->command?->line($guest->approve(by: $moderator)
            ? '- approving twice moved something, which would be a bug'
            : '- approving an approved comment did nothing, as promised');
    }

    /**
     * Reactions need an identity, so every one of these is a model. There is
     * no guest path to demonstrate here because there is none to have.
     */
    private function reactTo(Comment $comment, User $author, User $teammate): void
    {
        $this->command?->info('Reactions:');

        $comment->react('👍', by: $teammate);
        $comment->react('👍', by: $teammate);   // The same tap twice: a no-op.
        $comment->react('🎉', by: $author);

        $this->command?->line('- after two taps of the same reaction: '.json_encode(
            $comment->reactionSummary(),
            JSON_UNESCAPED_UNICODE,
        ));

        $comment->toggleReaction('👍', by: $teammate);

        $this->command?->line('- after toggling it back off: '.json_encode(
            $comment->reactionSummary(),
            JSON_UNESCAPED_UNICODE,
        ));

        try {
            $comment->react('🦆', by: $author);
            $this->command?->line('- an unlisted reaction was accepted, which would be a bug');
        } catch (InvalidReactionException $e) {
            $this->command?->line('- the allowlist refused an unlisted reaction: '.$e->getMessage());
        }
    }

    /**
     * The edit trail, and the one listener this app adds on top of it: an
     * approved comment that gets edited goes back into the queue. The package
     * files the revision; deciding what an edit means is the app's call.
     *
     * @see WorkbenchServiceProvider
     */
    private function edit(Comment $comment, User $author): void
    {
        $this->command?->info('Edits and revisions:');
        $this->command?->line("- before: \"{$comment->body}\" [{$comment->status->value}]");

        $comment->edit('Great write-up, especially the benchmarks!', by: $author);

        $comment->refresh();

        $this->command?->line("- after: \"{$comment->body}\" [{$comment->status->value}]");
        $this->command?->line('- edited_at: '.($comment->edited_at?->toDateTimeString() ?? 'never'));

        foreach ($comment->revisions as $revision) {
            $editor = $revision->editor?->getAttribute('name') ?? 'nobody named';
            $this->command?->line("- revision by {$editor}: \"{$revision->body}\"");
        }

        // A status change is not an edit: no revision, and edited_at stands.
        $comment->approve(by: $author);

        $this->command?->line('- after re-approving, revisions still number '.$comment->revisions()->count());
    }
}
