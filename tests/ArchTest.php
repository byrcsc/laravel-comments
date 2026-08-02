<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Events\CommentEvent;
use ByRcsc\LaravelComments\Exceptions\CommentsException;
use Illuminate\Database\Eloquent\Model;

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('ByRcsc\LaravelComments')
    ->toUseStrictTypes();

arch('enums are backed by strings')
    ->expect('ByRcsc\LaravelComments\Enums')
    ->toBeStringBackedEnums();

arch('every exception is catchable as a CommentsException')
    ->expect('ByRcsc\LaravelComments\Exceptions')
    ->toExtend(CommentsException::class);

arch('only the base exception is extendable')
    ->expect('ByRcsc\LaravelComments\Exceptions')
    ->toBeFinal()
    ->ignoring(CommentsException::class);

arch('models are final Eloquent models')
    ->expect('ByRcsc\LaravelComments\Models')
    ->toExtend(Model::class)
    ->toBeFinal();

arch('support helpers are final')
    ->expect('ByRcsc\LaravelComments\Support')
    ->toBeFinal();

arch('concerns are traits')
    ->expect('ByRcsc\LaravelComments\Concerns')
    ->toBeTraits();

arch('events carry a comment and nothing else')
    ->expect('ByRcsc\LaravelComments\Events')
    ->toExtend(CommentEvent::class)
    ->ignoring(CommentEvent::class);

arch('events other than the base are final')
    ->expect('ByRcsc\LaravelComments\Events')
    ->toBeFinal()
    ->ignoring(CommentEvent::class);

// Models describe the schema and its invariants. Transactions and manual
// dispatching belong elsewhere, and this keeps it that way.
arch('models stay out of the transaction business')
    ->expect('ByRcsc\LaravelComments\Models')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Event',
        'Illuminate\Support\Facades\Notification',
    ]);

arch('enums carry no dependencies')
    ->expect('ByRcsc\LaravelComments\Enums')
    ->not->toUse([
        'ByRcsc\LaravelComments\Models',
        'ByRcsc\LaravelComments\Support',
        'Illuminate\Support\Facades\DB',
    ]);
