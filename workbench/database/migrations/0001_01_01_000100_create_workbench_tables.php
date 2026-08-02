<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The host application's own schema: one commentable model.
 *
 * `users`, `cache`, and `jobs` come from Testbench's default migrations,
 * which are timestamped `0001_01_01_00000{0,1,2}`; the demo's User model
 * rides on that users table. This file is `000100` so it lands after them.
 *
 * The comments table is deliberately absent. It is published from the package
 * by `composer build` and lands beside this file, gitignored - exactly the
 * copy a real application would own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
