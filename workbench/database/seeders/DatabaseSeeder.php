<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use ByRcsc\LaravelComments\Exceptions\InvalidReactionException;
use ByRcsc\LaravelComments\Models\Comment;
use Illuminate\Database\Seeder;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The README quick start, run for real: write a comment, reply to it, add a
 * guest comment, then read the thread back through the relation. `composer
 * build` runs this after publishing and migrating the package's own
 * migration, so a successful build is a successful install-and-use loop.
 *
 * The moderation loop follows: the guest comment arrives pending, a moderator
 * approves it by hand, and the approved scope is what decides the reading. Then
 * the reaction loop: react, react again to no effect, toggle back off, and be
 * refused a reaction the allowlist does not carry.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::factory()->create(['name' => 'Ada Lovelace']);
        $teammate = User::factory()->create(['name' => 'Alan Turing']);
        $moderator = User::factory()->create(['name' => 'Grace Hopper']);

        $post = Post::factory()->create(['title' => 'Announcing laravel-comments']);

        // Write.
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

        $this->moderate($post, $guest, $moderator);
        $this->reactTo($comment, $author, $teammate);

        $this->command?->info('Comments written, moderated, reacted to, and read back through the relation. The demo loop works.');
    }

    /**
     * Nothing here is the package deciding what is visible: it holds the guest
     * comment, records the approval, and leaves both readings to the scope.
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

        $this->command?->line('- visible now: '.$post->comments()->approved()->count().' of '.$post->comments()->count());
        $this->command?->line('- still waiting: '.$post->comments()->pending()->count());

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
}
