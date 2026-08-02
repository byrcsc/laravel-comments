<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Tests;

use ByRcsc\LaravelComments\CommentsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CommentsServiceProvider::class];
    }

    /**
     * Config applied before the app boots. Set this and call
     * `refreshApplication()` to test anything decided at boot time - a plain
     * `config()->set()` lands too late, and the rebuild discards it.
     *
     * @var array<string, mixed>
     */
    protected array $bootConfig = [];

    /**
     * Rebuild the application with extra config, and put the schema back.
     *
     * `refreshApplication()` alone hands back an empty database: the SQLite
     * connection is in memory, so rebuilding the container throws the tables
     * away with it. Anything that boots differently *and* touches the database
     * wants this rather than the two calls by hand - the actor key type is the
     * main customer, because the migration reads it.
     *
     * @param  array<string, mixed>  $config
     */
    public function rebootWith(array $config): void
    {
        $this->bootConfig = array_merge($this->bootConfig, $config);

        $this->refreshApplication();

        $this->defineDatabaseMigrations();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());

        foreach ($this->bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Which engine the suite runs against. SQLite in memory is the default;
     * CI's database matrix sets `DB_DRIVER` to prove the engine against the
     * thing SQLite will not tell the truth about - the cascade the
     * force-delete subtree guarantee rides on.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnection(): array
    {
        return match (env('DB_DRIVER', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'comments_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'comments_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // SQLite ignores these unless asked, and the cascade rule is
                // half of what the deletion tests are checking.
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        // A memory database is born empty; a server database has to be put
        // back to that state before each test builds its schema.
        if (env('DB_DRIVER', 'sqlite') !== 'sqlite') {
            Schema::dropAllTables();
        }

        $this->createStubTables();

        $this->runPackageMigrations();
    }

    /**
     * Loading the real stubs is the point: a suite that builds its own schema
     * proves nothing about the one that ships. Order matters - the reaction
     * table's foreign key needs the comments table to exist.
     */
    protected function runPackageMigrations(): void
    {
        $migrations = [
            'create_comments_table',
            'create_comment_reactions_table',
        ];

        foreach ($migrations as $name) {
            $migration = require __DIR__."/../database/migrations/{$name}.php.stub";

            $migration->up();
        }
    }

    /**
     * The host-app side of the polymorphic relations: something to comment
     * on, and commentators with every supported key type.
     */
    protected function createStubTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('uuid_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ulid_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('string_users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }
}
