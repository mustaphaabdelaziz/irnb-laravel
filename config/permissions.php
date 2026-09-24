<?php

return [
    // Route-name prefix => module key. Longest matching prefix wins.
    // A route name matches a prefix when it equals it or starts with "prefix.".
    'modules' => [
        'players' => 'players',
        // Longest prefix wins, so every players.documents.* route lands here and
        // never on the players module: documents are more sensitive than the
        // player record (medical certificates, ID copies).
        'players.documents' => 'documents',
        'subscriptions' => 'subscriptions',
        'transactions' => 'transactions',
        'finance' => 'finance',
        'reports' => 'reports',
        'equipment' => 'equipment',
        'inventory' => 'inventory',
        'board' => 'board',
        'board-roles' => 'board',
        'users' => 'users',
        'categories' => 'categories',
        'branches' => 'categories',
        'jobs' => 'categories',
        'positions' => 'categories',
        'player-statuses' => 'categories',
        'document-types' => 'categories',
        'equipment-categories' => 'categories',
        'storage-locations' => 'categories',
        'settings' => 'settings',
    ],

    // Exact route name => [module, action]. Wins over prefix derivation.
    'overrides' => [
        'players.transactions.store' => ['players', 'add'],

        // Permanent deletion. Without these the action name falls through
        // deriveAction()'s default and is gated as 'edit', so a user with only
        // edit rights could irreversibly delete players.
        'players.forceDelete' => ['players', 'delete'],
        'players.bulkForceDelete' => ['players', 'delete'],
        'players.bulkArchive' => ['players', 'delete'],
        // Merging a job deletes the duplicate, so it needs at least what
        // jobs.destroy needs — without this override, "merge" falls through
        // deriveAction()'s default and is only gated as 'edit'.
        'jobs.merge' => ['categories', 'delete'],
        'players.transactions.update' => ['players', 'edit'],
        'players.transactions.destroy' => ['players', 'delete'],
        'players.card' => ['players', 'view'],
        'players.label' => ['players', 'view'],
        'players.labels' => ['players', 'view'],
        'players.board-table' => ['players', 'view'],
        'transactions.receipt' => ['transactions', 'view'],
        'reports.financial' => ['reports', 'view'],
        'finance.index' => ['finance', 'view'],
        'finance.settings' => ['finance', 'edit'],
        'inventory.report' => ['inventory', 'view'],
        // "out" is not a view verb, so without this the list would need edit rights.
        'equipment.out' => ['equipment', 'view'],
        'board.meetings.minutes' => ['board', 'view'],
        // "download" is not a view verb: without this, fetching a file the user
        // may already open inline would need edit rights.
        'players.documents.files.download' => ['documents', 'view'],
    ],

    // Route names that need no permission once authenticated.
    'unguarded' => [
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy',
        'lang.switch', 'account.pending',
    ],
];
