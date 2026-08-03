<?php

declare(strict_types=1);

// Wording for the shipped reply notification. Publish `comments-translations`
// to change it, and add a locale file per language you serve.
return [

    'reply' => [
        'subject' => 'Someone replied to your comment',
        'greeting' => 'Hello!',

        // Two lines rather than one with an optional name: a sentence built by
        // gluing a possibly-missing word into the middle reads badly in
        // English and worse once translated.
        'intro' => ':author replied to your comment.',
        'intro_anonymous' => 'Someone replied to your comment.',

        'yours' => 'What you wrote:',
        'theirs' => 'What they wrote:',
        'salutation' => 'Regards,',
    ],

];
