<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments;

use ByRcsc\LaravelComments\Testing\CommentsFake;
use Illuminate\Container\Container;

/**
 * The package's entry point for an application's own test suite.
 *
 * `Comments::fake()` swaps the write path for a recorder: comments, replies,
 * and reactions are held in memory and asserted against, and no table is
 * touched. It is for testing *your* code - a controller that comments on
 * something, a job that reacts to something - not for testing this package,
 * whose own suite runs against a real database on purpose.
 *
 *     Comments::fake();
 *
 *     $this->post("/posts/{$post->id}/comments", ['body' => 'Nice']);
 *
 *     Comments::fake()->assertCommentedOn($post);
 *
 * Those three writes are the whole of what is faked. Moderating, editing,
 * pinning, attaching, and deleting are refused while the fake is recording,
 * because a recorded comment is not a row. Reads are not faked either: a
 * relation or a scope still reads a database the fake left empty, so ask the
 * fake what it recorded instead.
 */
final class Comments
{
    /**
     * Start recording, and hand back the recorder. Calling it again returns
     * the same one, so a test can arrange and assert without holding a
     * variable.
     */
    public static function fake(): CommentsFake
    {
        $container = Container::getInstance();

        if (! $container->bound(CommentsFake::class)) {
            $container->instance(CommentsFake::class, new CommentsFake);
        }

        /** @var CommentsFake $fake */
        $fake = $container->make(CommentsFake::class);

        return $fake;
    }

    /**
     * The recorder, or null when nothing is being faked. This is what the
     * engine asks before every write, so it stays cheap: one container lookup
     * and no resolution at all in an application that never fakes.
     */
    public static function faked(): ?CommentsFake
    {
        $container = Container::getInstance();

        if (! $container->bound(CommentsFake::class)) {
            return null;
        }

        /** @var CommentsFake $fake */
        $fake = $container->make(CommentsFake::class);

        return $fake;
    }

    /**
     * Stop recording and forget everything recorded. Tests rebuild the
     * container between cases, so this is for the rare test that needs the
     * real engine back partway through.
     */
    public static function stopFaking(): void
    {
        Container::getInstance()->forgetInstance(CommentsFake::class);
    }
}
