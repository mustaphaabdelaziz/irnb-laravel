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
        'equipment-categories' => 'categories',
        'storage-locations' => 'categories',
        'settings' => 'settings',
    ],

    // Exact route name => [module, action]. Wins over prefix derivation.
    'overrides' => [
        'players.transactions.store' => ['players', 'add'],
        'players.transactions.update' => ['players', 'edit'],
        'players.transactions.destroy' => ['players', 'delete'],
        'players.card' => ['players', 'view'],
        'transactions.receipt' => ['transactions', 'view'],
        'reports.financial' => ['reports', 'view'],
        'finance.index' => ['finance', 'view'],
        'finance.settings' => ['finance', 'edit'],
        'inventory.report' => ['inventory', 'view'],
        'board.meetings.minutes' => ['board', 'view'],
    ],

    // Route names that need no permission once authenticated.
    'unguarded' => [
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy',
        'lang.switch', 'account.pending',
    ],
];
