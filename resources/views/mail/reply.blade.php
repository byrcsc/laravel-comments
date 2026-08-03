{{--
    The reply notification's mail body. Publish `comments-views` to change it.

    There is no link here because the package owns no routes and has no honest
    URL to build. Adding one is the first thing most applications will do:

        @component('mail::button', ['url' => route('posts.show', $reply->commentable_id)])
            {{ 'Read the reply' }}
        @endcomponent

    Every string interpolated below is untrusted input - the bodies and the
    guest name alike - and Blade escapes all of it. Keep it that way.
--}}
@component('mail::message')
# {{ __('comments::comments.reply.greeting') }}

@if ($author !== null)
{{ __('comments::comments.reply.intro', ['author' => $author]) }}
@else
{{ __('comments::comments.reply.intro_anonymous') }}
@endif

@if ($parent !== null)
**{{ __('comments::comments.reply.yours') }}**

> {{ $parent->body }}
@endif

**{{ __('comments::comments.reply.theirs') }}**

> {{ $reply->body }}

{{ __('comments::comments.reply.salutation') }}<br>
{{ config('app.name') }}
@endcomponent
