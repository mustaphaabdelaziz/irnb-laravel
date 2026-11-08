<?php

// Help Center topics in display order. `module` gates the topic's articles
// (null = every signed-in user; 'superadmin' = superadmins only); an article's own `module:` overrides it.
// `articles` orders slugs inside the topic; unlisted ones follow A→Z.
// Topic titles are UI strings: help.topic.<slug with _ for ->.
return [
    'topics' => [
        'getting-started' => ['icon' => 'home', 'module' => null, 'articles' => ['login', 'find-your-way', 'profile-password']],
        'players' => ['icon' => 'players', 'module' => 'players', 'articles' => ['add-player', 'find-player', 'player-page', 'edit-player', 'documents', 'branch-registrations']],
        'subscriptions' => ['icon' => 'subscriptions', 'module' => 'subscriptions', 'articles' => ['create-annual', 'create-exceptional', 'assign-players', 'take-payment', 'fix-payment', 'previous-debt', 'receipts']],
    ],
    'start_here' => [
        'getting-started/login',
        'getting-started/find-your-way',
        'players/add-player',
        'players/find-player',
    ],
];
