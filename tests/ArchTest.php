<?php

declare(strict_types=1);

use ByRcsc\LaravelComments\Events\CommentEvent;
use ByRcsc\LaravelComments\Events\CommentModerated;
use ByRcsc\LaravelComments\Events\CommentReacted;
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

arch('every event is a CommentEvent')
    ->expect('ByRcsc\LaravelComments\Events')
    ->toExtend(CommentEvent::class)
    ->ignoring(CommentEvent::class);

arch('events other than the base classes are final')
    ->expect('ByRcsc\LaravelComments\Events')
    ->toBeFinal()
    ->ignoring([CommentEvent::class, CommentModerated::class, CommentReacted::class]);

arch('contracts are interfaces applications implement')
    ->expect('ByRcsc\LaravelComments\Contracts')
    ->toBeInterfaces();

// Models describe the schema and its invariants, and reach for the framework
// through the container rather than its facades: a transaction or a
// notification decided inside a model is a decision the application cannot
// take back. Transition events are the one thing a model dispatches itself,
// through the helper, because Eloquent's own events cannot carry an actor.
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
