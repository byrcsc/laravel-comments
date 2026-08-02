<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The README quick start, run for real: write a comment, reply to it, add a
 * guest comment, then read the thread back through the relation. `composer
 * build` runs this after publishing and migrating the package's own
 * migration, so a successful build is a successful install-and-use loop.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::factory()->create(['name' => 'Ada Lovelace']);
        $teammate = User::factory()->create(['name' => 'Alan Turing']);

        $post = Post::factory()->create(['title' => 'Announcing laravel-comments']);

        // Write.
        $comment = $post->comment('Great write-up!', by: $author);
        $comment->reply('Agreed, especially the last section.', by: $teammate);
        $post->commentAsGuest(
            'Where can I download the slides?',
            name: 'Jane',
            email: 'jane@example.com',
        );

        // Read, the way the README does.
        $threads = $post->comments()->topLevel()->with('replies')->get();

        $this->command?->info("Post: {$post->title}");

        foreach ($threads as $thread) {
            $who = $thread->commentator?->getAttribute('name') ?? "{$thread->guest_name} (guest)";
            $this->command?->line("- {$who}: {$thread->body}");

            foreach ($thread->replies as $reply) {
                $replier = $reply->commentator?->getAttribute('name') ?? "{$reply->guest_name} (guest)";
                $this->command?->line("    - {$replier}: {$reply->body}");
            }
        }

        $this->command?->info('Comments written and read back through the relation. The demo loop works.');
    }
}
