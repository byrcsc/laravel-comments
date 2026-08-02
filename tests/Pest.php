<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Tests\Stubs\Post;
use ByRcsc\LaravelComments\Tests\Stubs\User;
use ByRcsc\LaravelComments\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function user(string $name = 'Ada Lovelace'): User
{
    return User::create([
        'name' => $name,
        'email' => str_replace(' ', '.', strtolower($name)).'.'.uniqid().'@example.test',
    ]);
}

function post(string $title = 'A post worth discussing'): Post
{
    return Post::create(['title' => $title]);
}
