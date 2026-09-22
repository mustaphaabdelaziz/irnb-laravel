<?php

return [
    // Route-name prefix => module key. Longest matching prefix wins.
    // A route name matches a prefix when it equals it or starts with "prefix.".
    'modules' => [
        'players' => 'players',
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
        'players.transactions.update' => ['players', 'edit'],
        'players.transactions.destroy' => ['players', 'delete'],
        'players.card' => ['players', 'view'],
        'transactions.receipt' => ['transactions', 'view'],
        'reports.financial' => ['reports', 'view'],
        'finance.index' => ['finance', 'view'],
        'finance.settings' => ['finance', 'edit'],
        'inventory.report' => ['inventory', 'view'],
        // "out" is not a view verb, so without this the list would need edit rights.
        'equipment.out' => ['equipment', 'view'],
        'board.meetings.minutes' => ['board', 'view'],
    ],

    // Route names that need no permission once authenticated.
    'unguarded' => [
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy',
        'lang.switch', 'account.pending',
    ],
];
