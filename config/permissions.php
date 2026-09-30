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
        'attendance' => 'attendance',
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
        'transactions.bulkDestroy' => ['transactions', 'delete'],
        // Merging a job deletes the duplicate, so it needs at least what
        // jobs.destroy needs — without this override, "merge" falls through
        // deriveAction()'s default and is only gated as 'edit'.
        'jobs.merge' => ['categories', 'delete'],
        'players.transactions.update' => ['players', 'edit'],
        'players.transactions.destroy' => ['players', 'delete'],
        'players.card' => ['players', 'view'],
        // "academic-report" is not a view verb, so without this printing would need edit rights.
        'players.academic-report' => ['players', 'view'],
        'players.label' => ['players', 'view'],
        'players.labels' => ['players', 'view'],
        'players.board-table' => ['players', 'view'],
        'players.academic-results' => ['players', 'view'],
        // "grid" is not a view verb: opening the month grid must not need edit rights.
        'attendance.grid' => ['attendance', 'view'],
        // "stats" is not a view verb: reading the statistics must not need edit rights.
        'attendance.stats' => ['attendance', 'view'],
        // "month" and "session" are not view verbs: printing a blank sheet must not need edit rights.
        'attendance.sheets.month' => ['attendance', 'view'],
        'attendance.sheets.session' => ['attendance', 'view'],
        // Follow-up pages and PDFs only read; none of these last segments is a view verb.
        'attendance.alerts' => ['attendance', 'view'],
        'attendance.players.letter' => ['attendance', 'view'],
        'attendance.ranking' => ['attendance', 'view'],
        'attendance.certificates' => ['attendance', 'view'],
        // Schedules, closures and pre-season targets reshape the whole season's
        // calendar, not just one row, so all their writes need edit — without
        // these, deriveAction() would gate the stores as 'add' and the
        // destroys as 'delete', letting an add-only user reshape the calendar.
        'attendance.schedules.store' => ['attendance', 'edit'],
        'attendance.schedules.destroy' => ['attendance', 'edit'],
        'attendance.closures.store' => ['attendance', 'edit'],
        'attendance.closures.destroy' => ['attendance', 'edit'],
        'attendance.preseason-targets.store' => ['attendance', 'edit'],
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
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy', 'profile.activity',
        'lang.switch', 'account.pending',
    ],
];
