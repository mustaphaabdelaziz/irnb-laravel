<?php

// Help Center topics in display order. `module` gates the topic's articles
// (null = every signed-in user; 'superadmin' = superadmins only); an article's own `module:` overrides it.
// `articles` orders slugs inside the topic; unlisted ones follow A→Z.
// Topic titles are UI strings: help.topic.<slug with _ for ->.
return [
    'topics' => [
        'getting-started' => ['icon' => 'home', 'module' => null, 'articles' => ['login', 'find-your-way', 'profile-password']],
        'players' => ['icon' => 'players', 'module' => 'players', 'articles' => ['add-player', 'find-player', 'player-page', 'edit-player', 'documents', 'branch-registrations', 'status-history', 'merge-duplicates']],
        'subscriptions' => ['icon' => 'subscriptions', 'module' => 'subscriptions', 'articles' => ['create-annual', 'create-exceptional', 'assign-players', 'take-payment', 'fix-payment', 'previous-debt', 'receipts']],
        'attendance' => ['icon' => 'calendar', 'module' => 'attendance', 'articles' => ['take-attendance', 'add-session', 'month-grid', 'cancel-delete-session', 'timetable', 'training-groups', 'codes-rules', 'stats-ranking', 'alerts-injuries']],
        'finance' => ['icon' => 'money', 'module' => 'transactions', 'articles' => ['record-transaction', 'find-export-import', 'cancel-transaction', 'transaction-history', 'move-money', 'caisses', 'season', 'dashboard-report']],
        'settings' => ['icon' => 'settings', 'module' => null, 'articles' => ['club-identity', 'add-branch', 'add-category', 'branch-documents', 'create-user', 'roles-rights', 'manage-users', 'log-in-as-activity']],
        'equipment' => ['icon' => 'equipment', 'module' => 'equipment', 'articles' => ['equipment-lists', 'add-catalog', 'receive-stock', 'lend-assign', 'return-equipment', 'damage-repair-lost', 'import-export', 'stock-take', 'inventory-report']],
        'board' => ['icon' => 'board', 'module' => 'board', 'articles' => ['roles-committees', 'terms', 'add-member', 'committee-seats', 'former-remove-member', 'schedule-meeting', 'meeting-minutes', 'cancel-delete-meeting']],
        'competitions' => ['icon' => 'flag', 'module' => 'games', 'articles' => ['opponents-venues', 'create-team', 'roster-coaches', 'create-competition', 'schedule-match', 'call-up', 'lineup', 'match-report', 'edit-cancel-match', 'suspensions', 'statistics']],
        'social' => ['icon' => 'heart', 'module' => 'activities', 'articles' => ['activity-types', 'record-activity', 'photos-files', 'find-edit-activity', 'create-literary-report', 'export-literary-report']],
    ],
    'start_here' => [
        'getting-started/login',
        'getting-started/find-your-way',
        'players/add-player',
        'players/find-player',
    ],
];
