<?php

use App\Services\Activity\ActivityAction;

/*
| Route-recorded activity: route name => action code, or
| [action, 'kind' => list key, 'count' => request array input].
|
| RecordRouteActivity records one event after a SUCCESSFUL write on these
| routes (status < 400, no validation errors, no `error` flash). The subject
| is the route's most specific (last) bound model. Routes whose controllers already record
| an explicit event (registration, payments, stock, meetings, sessions…) are
| deliberately absent, so nothing is counted twice.
*/

$item = fn (string $kind) => [
    'store' => [ActivityAction::SETTING_ITEM_CREATED, 'kind' => $kind],
    'update' => [ActivityAction::SETTING_ITEM_UPDATED, 'kind' => $kind],
    'destroy' => [ActivityAction::SETTING_ITEM_DELETED, 'kind' => $kind],
];

$settingLists = [
    'categories' => 'categories',
    'branches' => 'branches',
    'positions' => 'positions',
    'player-statuses' => 'player_statuses',
    'document-types' => 'document_types',
    'equipment-categories' => 'equipment_categories',
    'storage-locations' => 'storage_locations',
    'board-roles' => 'board_roles',
    'board.terms' => 'board_terms',
    'finance.accounts' => 'finance_accounts',
    'finance.categories' => 'finance_categories',
    'finance.years' => 'fiscal_years',
    'attendance.custom-statuses' => 'attendance_codes',
];

$routes = [
    // players
    'players.update' => ActivityAction::PLAYER_UPDATED,
    'players.bulkUpdate' => [ActivityAction::PLAYER_UPDATED, 'count' => 'ids'],
    'branches.players.sync' => ActivityAction::PLAYER_UPDATED,
    'players.restore' => ActivityAction::PLAYER_RESTORED,
    'players.bulkRestore' => [ActivityAction::PLAYER_RESTORED, 'count' => 'ids'],
    'players.forceDelete' => ActivityAction::PLAYER_DELETED,
    'players.bulkForceDelete' => [ActivityAction::PLAYER_DELETED, 'count' => 'ids'],
    'players.academic-records.update' => ActivityAction::ACADEMIC_RECORD_UPDATED,
    'players.academic-years.update' => ActivityAction::ACADEMIC_RECORD_UPDATED,
    'players.academic-records.destroy' => ActivityAction::ACADEMIC_RECORD_DELETED,
    'players.academic-years.destroy' => ActivityAction::ACADEMIC_RECORD_DELETED,
    'jobs.update' => ActivityAction::JOB_UPDATED,
    'jobs.merge' => ActivityAction::JOB_UPDATED,
    'jobs.destroy' => ActivityAction::JOB_DELETED,

    // money
    'transactions.update' => ActivityAction::TRANSACTION_UPDATED,
    'subscriptions.update' => ActivityAction::SUBSCRIPTION_UPDATED,
    'players.subscriptions.update' => ActivityAction::SUBSCRIPTION_UPDATED,
    'subscriptions.destroy' => ActivityAction::SUBSCRIPTION_DELETED,
    'players.subscriptions.destroy' => ActivityAction::SUBSCRIPTION_DELETED,
    'players.subscriptions.store' => [ActivityAction::PLAYERS_ASSIGNED],
    'finance.budget.update' => ActivityAction::BUDGET_UPDATED,
    'finance.reset' => ActivityAction::FINANCE_RESET,
    'finance.years.close' => [ActivityAction::SETTING_ITEM_UPDATED, 'kind' => 'fiscal_years'],
    'finance.years.reopen' => [ActivityAction::SETTING_ITEM_UPDATED, 'kind' => 'fiscal_years'],

    // equipment
    'equipment.catalogs.update' => ActivityAction::EQUIPMENT_UPDATED,
    'equipment.items.update' => ActivityAction::EQUIPMENT_UPDATED,
    'equipment.stock.split' => ActivityAction::EQUIPMENT_UPDATED,
    'equipment.catalogs.destroy' => ActivityAction::EQUIPMENT_DELETED,
    'equipment.catalogs.bulk-destroy' => [ActivityAction::EQUIPMENT_DELETED, 'count' => 'ids'],
    'equipment.items.destroy' => ActivityAction::EQUIPMENT_DELETED,
    'equipment.items.bulk-destroy' => [ActivityAction::EQUIPMENT_DELETED, 'count' => 'ids'],
    'equipment.items.repair' => ActivityAction::EQUIPMENT_SENT_TO_REPAIR,
    'equipment.items.complete-repair' => ActivityAction::EQUIPMENT_REPAIRED,
    'equipment.items.mark-lost' => ActivityAction::EQUIPMENT_MARKED_LOST,
    'equipment.items.mark-found' => ActivityAction::EQUIPMENT_MARKED_FOUND,
    'inventory.destroy' => ActivityAction::STOCKTAKE_DELETED,

    // board
    'board.meetings.update' => ActivityAction::MEETING_UPDATED,
    'board.meetings.attendance' => ActivityAction::MEETING_UPDATED,
    'board.meetings.attachment' => ActivityAction::MEETING_UPDATED,
    'board.meetings.attachment.delete' => ActivityAction::MEETING_UPDATED,
    'board.tasks.update' => ActivityAction::TASK_UPDATED,
    'board.tasks.destroy' => ActivityAction::TASK_DELETED,
    'board.members.store' => ActivityAction::BOARD_MEMBER_ADDED,
    'board.members.update' => ActivityAction::BOARD_MEMBER_UPDATED,
    'board.members.destroy' => ActivityAction::BOARD_MEMBER_REMOVED,

    // documents
    'players.documents.update' => ActivityAction::DOCUMENT_UPDATED,
    'players.documents.unexempt' => ActivityAction::DOCUMENT_UNEXEMPTED,
    'players.documents.files.destroy' => ActivityAction::DOCUMENT_FILE_DELETED,

    // attendance
    'attendance.schedules.store' => ActivityAction::SCHEDULE_CREATED,
    'attendance.schedules.update' => ActivityAction::SCHEDULE_UPDATED,
    'attendance.schedules.destroy' => ActivityAction::SCHEDULE_DELETED,
    'attendance.closures.store' => ActivityAction::CLOSURE_ADDED,
    'attendance.closures.destroy' => ActivityAction::CLOSURE_REMOVED,
    'attendance.injury-notes.store' => ActivityAction::INJURY_NOTE_SAVED,
    'attendance.injury-notes.update' => ActivityAction::INJURY_NOTE_SAVED,
    'attendance.injury-notes.destroy' => ActivityAction::INJURY_NOTE_DELETED,

    // access (logins, logouts and "log in as" are recorded explicitly)
    'users.store' => ActivityAction::USER_CREATED,
    'users.update' => ActivityAction::USER_UPDATED,
    'users.destroy' => ActivityAction::USER_DELETED,
    'users.approve' => ActivityAction::USER_APPROVED,
    'users.password' => ActivityAction::USER_PASSWORD_RESET,
    'users.toggleActive' => ActivityAction::USER_STATUS_CHANGED,
    'roles.store' => ActivityAction::ROLE_CREATED,
    'roles.update' => ActivityAction::ROLE_UPDATED,
    'roles.destroy' => ActivityAction::ROLE_DELETED,
    'profile.update' => ActivityAction::PROFILE_UPDATED,
    'password.update' => ActivityAction::PASSWORD_CHANGED,

    // settings
    'settings.update' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'club_settings'],
    'settings.theme' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'theme'],
    'attendance.settings.update' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'attendance_settings'],
    'attendance.settings.letter' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'attendance_letter'],
    'attendance.preseason-targets.store' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'preseason_targets'],
    'backups.settings' => [ActivityAction::SETTINGS_UPDATED, 'kind' => 'backups'],
    'backups.store' => ActivityAction::BACKUP_CREATED,
    'backups.restore' => ActivityAction::BACKUP_RESTORED,
    'backups.destroy' => ActivityAction::BACKUP_DELETED,
];

foreach ($settingLists as $prefix => $kind) {
    foreach ($item($kind) as $verb => $entry) {
        $routes["{$prefix}.{$verb}"] ??= $entry;
    }
}

return ['routes' => $routes];
