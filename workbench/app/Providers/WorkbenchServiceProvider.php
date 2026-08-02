<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Configures the demo app.
 *
 * This stands in for the `config/comments.php` edits a real application would
 * make when installing the package. The demo runs on the shipped defaults, so
 * there is nothing to override yet; the moderation, reactions, and
 * notification issues will each add their settings here as their features
 * land, keeping every integration point in one file a reader can scan.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
