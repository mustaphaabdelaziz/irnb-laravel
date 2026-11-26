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
        // Competitions & matches. Coaches are further limited to their own teams (TeamAccess).
        'teams' => 'teams',
        'competitions' => 'competitions',
        'games' => 'games',
        'subscriptions' => 'subscriptions',
        'transactions' => 'transactions',
        'payment-orders' => 'payment_orders',
        'purchase-orders' => 'purchase_orders',
        'finance' => 'finance',
        'reports' => 'reports',
        'equipment' => 'equipment',
        'inventory' => 'inventory',
        'board' => 'board',
        'board-roles' => 'board_roles',
        'board-committees' => 'board_committees',
        'activities' => 'activities',
        'literary-reports' => 'literary_reports',
        // Secretariat: the mail register (outgoing and incoming letters).
        'mail' => 'mail',
        'users' => 'users',
        'categories' => 'categories',
        // Each App Configuration list is its own module (own row in the role editor).
        'branches' => 'branches',
        'jobs' => 'jobs',
        'positions' => 'positions',
        'player-statuses' => 'player_statuses',
        'sporting-statuses' => 'player_statuses',
        'document-types' => 'document_types',
        'equipment-categories' => 'equipment_categories',
        'storage-locations' => 'storage_locations',
        'game-formats' => 'game_formats',
        'opponents' => 'opponents',
        'venues' => 'venues',
        'activity-types' => 'activity_types',
        'suppliers' => 'suppliers',
        'settings' => 'settings',
    ],

    // Exact route name => [module, action]. Wins over prefix derivation.
    'overrides' => [
        // Manual bans change who may play: both need competitions/edit, not add/delete.
        'competitions.suspensions.store' => ['competitions', 'edit'],
        'competitions.suspensions.destroy' => ['competitions', 'edit'],

        // "stats" is not a view verb: reading the club statistics needs games/view only.
        'games.stats' => ['games', 'view'],

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
        'jobs.merge' => ['jobs', 'delete'],
        'players.transactions.update' => ['players', 'edit'],
        // Status history lines are part of editing a player, whatever the verb.
        'players.status-changes.store' => ['players', 'edit'],
        'players.status-changes.destroy' => ['players', 'edit'],
        'players.transactions.destroy' => ['players', 'delete'],
        'players.card' => ['players', 'view'],
        // "academic-report" is not a view verb, so without this printing would need edit rights.
        'players.academic-report' => ['players', 'view'],
        // "print-list" is not a view verb: printing the filtered list only reads.
        'players.print-list' => ['players', 'view'],
        // Missing-document details are the documents right (the controller also asks players → view).
        'players.print-documents' => ['documents', 'view'],
        'subscriptions.print-list' => ['subscriptions', 'view'],
        'players.board-table' => ['players', 'view'],
        'players.academic-results' => ['players', 'view'],
        // "grid" is not a view verb: opening the month grid must not need edit rights.
        'attendance.grid' => ['attendance', 'view'],
        // "stats" is not a view verb: reading the statistics must not need edit rights.
        'attendance.stats' => ['attendance', 'view'],
        // "month" and "session" are not view verbs: printing a blank sheet must not need edit rights.
        'attendance.sheets.month' => ['attendance', 'view'],
        'attendance.sheets.period' => ['attendance', 'view'],
        'attendance.sheets.session' => ['attendance', 'view'],
        // Follow-up pages and PDFs only read; none of these last segments is a view verb.
        'attendance.alerts' => ['attendance', 'view'],
        'attendance.players.letter' => ['attendance', 'view'],
        'attendance.ranking' => ['attendance', 'view'],
        'attendance.certificates' => ['attendance', 'view'],
        'attendance.injuries' => ['attendance', 'view'],
        // Injury details are edited, never just added or deleted: every write needs edit.
        'attendance.injury-notes.store' => ['attendance', 'edit'],
        'attendance.injury-notes.destroy' => ['attendance', 'edit'],
        // Deleting a session is a correction made like cancelling one: edit, not a separate delete right.
        'attendance.sessions.destroy' => ['attendance', 'edit'],
        // Schedules, closures and pre-season targets reshape the whole season's
        // calendar, not just one row, so all their writes need edit — without
        // these, deriveAction() would gate the stores as 'add' and the
        // destroys as 'delete', letting an add-only user reshape the calendar.
        'attendance.schedules.store' => ['attendance', 'edit'],
        'attendance.schedules.destroy' => ['attendance', 'edit'],
        'attendance.closures.store' => ['attendance', 'edit'],
        'attendance.closures.destroy' => ['attendance', 'edit'],
        'attendance.preseason-targets.store' => ['attendance', 'edit'],
        // Training groups decide who each session lists: settings, like the schedule lines.
        'attendance.groups.store' => ['attendance', 'edit'],
        'attendance.groups.destroy' => ['attendance', 'edit'],
        'attendance.groups.members.update' => ['attendance', 'edit'],
        // Custom codes are settings too: adding or deleting one changes how every mark reads.
        'attendance.custom-statuses.store' => ['attendance', 'edit'],
        'attendance.custom-statuses.destroy' => ['attendance', 'edit'],
        // "convocation" is not a view verb: printing the call-up sheet must not need edit rights.
        'games.convocation' => ['games', 'view'],
        // Printable sheets only read: "lineup-sheet" / "report-sheet" are not view verbs.
        'games.lineup-sheet' => ['games', 'view'],
        'games.report-sheet' => ['games', 'view'],
        'transactions.receipt' => ['transactions', 'view'],
        // Printing a ticket or checking a paper receipt only reads (the print log is a side record).
        'transactions.ticket' => ['transactions', 'view'],
        'receipts.verify' => ['transactions', 'view'],
        // Approving is its own right (Role::EXTRA_ACTIONS); paying and cancelling are edits.
        'payment-orders.approve' => ['payment_orders', 'approve'],
        'payment-orders.pay' => ['payment_orders', 'edit'],
        'payment-orders.cancel' => ['payment_orders', 'edit'],
        // Printing an order only reads it.
        'payment-orders.print' => ['payment_orders', 'view'],
        // Made from the expense page, but it creates a payment order.
        'transactions.payment-order.store' => ['payment_orders', 'add'],
        // Purchase orders have no approve step (money is controlled at the payment order).
        // Sending, receiving, cancelling and making the payment order are edits; printing reads.
        'purchase-orders.send' => ['purchase_orders', 'edit'],
        'purchase-orders.receive' => ['purchase_orders', 'edit'],
        'purchase-orders.cancel' => ['purchase_orders', 'edit'],
        'purchase-orders.payment-order.store' => ['purchase_orders', 'edit'],
        'purchase-orders.print' => ['purchase_orders', 'view'],
        'reports.financial' => ['reports', 'view'],
        'finance.index' => ['finance', 'view'],
        'finance.settings' => ['finance', 'edit'],
        'inventory.report' => ['inventory', 'view'],
        // "out" is not a view verb, so without this the list would need edit rights.
        'equipment.out' => ['equipment', 'view'],
        // Deleting several at once is still deleting: without these, "bulk-destroy"
        // falls through deriveAction()'s default and is only gated as 'edit'.
        'equipment.catalogs.bulk-destroy' => ['equipment', 'delete'],
        'equipment.items.bulk-destroy' => ['equipment', 'delete'],
        'board.meetings.minutes' => ['board', 'view'],
        // "members" / "meetings" are not view verbs: without these, reading the
        // organigram or the meetings list would need edit rights.
        'board.members' => ['board', 'view'],
        'board.meetings' => ['board', 'view'],
        // Copying the previous term's members adds members.
        'board.terms.copy-previous' => ['board', 'add'],
        // The tasks board and its stats are reading; changing a task keeps its own add/edit/delete guard.
        'board.tasks' => ['board', 'view'],
        // "download" is not a view verb: without this, fetching a file the user
        // may already open inline would need edit rights.
        'players.documents.files.download' => ['documents', 'view'],
        // Photos and files are part of editing an activity: "store" / "destroy"
        // would otherwise be gated as add / delete (deleting a photo is not
        // deleting the activity).
        'activities.photos.store' => ['activities', 'edit'],
        'activities.photos.destroy' => ['activities', 'edit'],
        'activities.files.store' => ['activities', 'edit'],
        'activities.files.destroy' => ['activities', 'edit'],
        // Exporting the literary report is reading it.
        'literary-reports.pdf' => ['literary_reports', 'view'],
        // Mail entries are never deleted, so the delete right is cancelling one.
        // Printing a letter and opening a scan only read. The print tick box
        // (lookup + register) adds an entry; the controller also checks the
        // view right of the printed document's own module.
        'mail.cancel' => ['mail', 'delete'],
        'mail.print' => ['mail', 'view'],
        'mail.scan' => ['mail', 'view'],
        'mail.sources.show' => ['mail', 'add'],
        'mail.sources.store' => ['mail', 'add'],
    ],

    // Route names that need no permission once authenticated.
    'unguarded' => [
        'dashboard', 'profile.edit', 'profile.update', 'profile.destroy', 'profile.activity',
        'lang.switch', 'account.pending',
        // Reports center: each report checks its own module (ReportsCenterController).
        'reports.hub', 'reports.category', 'reports.view', 'reports.download',
    ],
];
