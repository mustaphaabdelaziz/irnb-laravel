<?php

namespace App\Services\Activity;

/**
 * The closed set of action codes activity is recorded under. Every code
 * recorded via ActivityRecorder::record() must appear in ALL, belong to
 * exactly one area in AREAS, and — if it can later be cancelled/archived —
 * be listed in QUALITY.
 */
class ActivityAction
{
    // players
    const PLAYER_REGISTERED = 'player_registered';

    const PLAYER_IMPORTED = 'player_imported';

    const PLAYER_ARCHIVED = 'player_archived';

    const JOB_CREATED = 'job_created';

    const ACADEMIC_RECORD_ADDED = 'academic_record_added';

    // money
    const TRANSACTION_RECORDED = 'transaction_recorded';

    const TRANSACTION_IMPORTED = 'transaction_imported';

    const PAYMENT_RECORDED = 'payment_recorded';

    const PAYMENT_EDITED = 'payment_edited';

    const TRANSACTION_CANCELLED = 'transaction_cancelled';

    const TRANSFER_RECORDED = 'transfer_recorded';

    const SUBSCRIPTION_CREATED = 'subscription_created';

    const PLAYERS_ASSIGNED = 'players_assigned';

    // equipment
    const STOCK_RECEIVED = 'stock_received';

    const EQUIPMENT_IMPORTED = 'equipment_imported';

    const EQUIPMENT_RENTED = 'equipment_rented';

    const EQUIPMENT_ASSIGNED = 'equipment_assigned';

    const EQUIPMENT_RETURNED = 'equipment_returned';

    const STOCKTAKE_STARTED = 'stocktake_started';

    const STOCKTAKE_COMPLETED = 'stocktake_completed';

    // board
    const MEETING_CREATED = 'meeting_created';

    const MEETING_CANCELLED = 'meeting_cancelled';

    const TASK_CREATED = 'task_created';

    const TASK_COMPLETED = 'task_completed';

    // documents
    const DOCUMENT_RECEIVED = 'document_received';

    const DOCUMENT_RENEWED = 'document_renewed';

    const DOCUMENT_EXEMPTED = 'document_exempted';

    const DOCUMENT_FILE_UPLOADED = 'document_file_uploaded';

    // attendance
    const ATTENDANCE_MARKED = 'attendance_marked';

    const TRAINING_SESSION_CREATED = 'training_session_created';

    const TRAINING_SESSION_CANCELLED = 'training_session_cancelled';

    const TRAINING_SESSION_MOVED = 'training_session_moved';

    const TRAINING_SESSION_CATEGORIES_CHANGED = 'training_session_categories_changed';

    const TRAINING_SESSION_DELETED = 'training_session_deleted';

    const TRAINING_SESSION_RESET = 'training_session_reset';

    // Edits, deletions, sign-ins and settings (2026-10 users batch) — most are
    // recorded from the route map in config/activity.php.

    const PLAYER_UPDATED = 'player_updated';

    const PLAYER_RESTORED = 'player_restored';

    const PLAYER_DELETED = 'player_deleted';

    const ACADEMIC_RECORD_UPDATED = 'academic_record_updated';

    const ACADEMIC_RECORD_DELETED = 'academic_record_deleted';

    const JOB_UPDATED = 'job_updated';

    const JOB_DELETED = 'job_deleted';

    const TRANSACTION_UPDATED = 'transaction_updated';

    const SUBSCRIPTION_UPDATED = 'subscription_updated';

    const SUBSCRIPTION_DELETED = 'subscription_deleted';

    const BUDGET_UPDATED = 'budget_updated';

    const FINANCE_RESET = 'finance_reset';

    const EQUIPMENT_UPDATED = 'equipment_updated';

    const EQUIPMENT_DELETED = 'equipment_deleted';

    const EQUIPMENT_SENT_TO_REPAIR = 'equipment_sent_to_repair';

    const EQUIPMENT_REPAIRED = 'equipment_repaired';

    const EQUIPMENT_MARKED_LOST = 'equipment_marked_lost';

    const EQUIPMENT_MARKED_FOUND = 'equipment_marked_found';

    const STOCKTAKE_DELETED = 'stocktake_deleted';

    const MEETING_UPDATED = 'meeting_updated';

    const TASK_UPDATED = 'task_updated';

    const TASK_DELETED = 'task_deleted';

    const BOARD_MEMBER_ADDED = 'board_member_added';

    const BOARD_MEMBER_UPDATED = 'board_member_updated';

    const BOARD_MEMBER_REMOVED = 'board_member_removed';

    const DOCUMENT_UPDATED = 'document_updated';

    const DOCUMENT_UNEXEMPTED = 'document_unexempted';

    const DOCUMENT_FILE_DELETED = 'document_file_deleted';

    const SCHEDULE_CREATED = 'schedule_created';

    const SCHEDULE_UPDATED = 'schedule_updated';

    const SCHEDULE_DELETED = 'schedule_deleted';

    const CLOSURE_ADDED = 'closure_added';

    const CLOSURE_REMOVED = 'closure_removed';

    const INJURY_NOTE_SAVED = 'injury_note_saved';

    const INJURY_NOTE_DELETED = 'injury_note_deleted';

    const USER_LOGGED_IN = 'user_logged_in';

    const USER_LOGGED_OUT = 'user_logged_out';

    const USER_CREATED = 'user_created';

    const USER_UPDATED = 'user_updated';

    const USER_DELETED = 'user_deleted';

    const USER_APPROVED = 'user_approved';

    const USER_PASSWORD_RESET = 'user_password_reset';

    const USER_STATUS_CHANGED = 'user_status_changed';

    const USER_IMPERSONATED = 'user_impersonated';

    const ROLE_CREATED = 'role_created';

    const ROLE_UPDATED = 'role_updated';

    const ROLE_DELETED = 'role_deleted';

    const PROFILE_UPDATED = 'profile_updated';

    const PASSWORD_CHANGED = 'password_changed';

    const SETTINGS_UPDATED = 'settings_updated';

    const SETTING_ITEM_CREATED = 'setting_item_created';

    const SETTING_ITEM_UPDATED = 'setting_item_updated';

    const SETTING_ITEM_DELETED = 'setting_item_deleted';

    const BACKUP_CREATED = 'backup_created';

    const BACKUP_RESTORED = 'backup_restored';

    const BACKUP_DELETED = 'backup_deleted';

    /** @var list<string> */
    const ALL = [
        self::PLAYER_REGISTERED,
        self::PLAYER_IMPORTED,
        self::PLAYER_ARCHIVED,
        self::JOB_CREATED,
        self::ACADEMIC_RECORD_ADDED,
        self::TRANSACTION_RECORDED,
        self::TRANSACTION_IMPORTED,
        self::PAYMENT_RECORDED,
        self::PAYMENT_EDITED,
        self::TRANSACTION_CANCELLED,
        self::TRANSFER_RECORDED,
        self::SUBSCRIPTION_CREATED,
        self::PLAYERS_ASSIGNED,
        self::STOCK_RECEIVED,
        self::EQUIPMENT_IMPORTED,
        self::EQUIPMENT_RENTED,
        self::EQUIPMENT_ASSIGNED,
        self::EQUIPMENT_RETURNED,
        self::STOCKTAKE_STARTED,
        self::STOCKTAKE_COMPLETED,
        self::MEETING_CREATED,
        self::MEETING_CANCELLED,
        self::TASK_CREATED,
        self::TASK_COMPLETED,
        self::DOCUMENT_RECEIVED,
        self::DOCUMENT_RENEWED,
        self::DOCUMENT_EXEMPTED,
        self::DOCUMENT_FILE_UPLOADED,
        self::ATTENDANCE_MARKED,
        self::TRAINING_SESSION_CREATED,
        self::TRAINING_SESSION_CANCELLED,
        self::TRAINING_SESSION_MOVED,
        self::TRAINING_SESSION_CATEGORIES_CHANGED,
        self::TRAINING_SESSION_DELETED,
        self::TRAINING_SESSION_RESET,
        self::PLAYER_UPDATED,
        self::PLAYER_RESTORED,
        self::PLAYER_DELETED,
        self::ACADEMIC_RECORD_UPDATED,
        self::ACADEMIC_RECORD_DELETED,
        self::JOB_UPDATED,
        self::JOB_DELETED,
        self::TRANSACTION_UPDATED,
        self::SUBSCRIPTION_UPDATED,
        self::SUBSCRIPTION_DELETED,
        self::BUDGET_UPDATED,
        self::FINANCE_RESET,
        self::EQUIPMENT_UPDATED,
        self::EQUIPMENT_DELETED,
        self::EQUIPMENT_SENT_TO_REPAIR,
        self::EQUIPMENT_REPAIRED,
        self::EQUIPMENT_MARKED_LOST,
        self::EQUIPMENT_MARKED_FOUND,
        self::STOCKTAKE_DELETED,
        self::MEETING_UPDATED,
        self::TASK_UPDATED,
        self::TASK_DELETED,
        self::BOARD_MEMBER_ADDED,
        self::BOARD_MEMBER_UPDATED,
        self::BOARD_MEMBER_REMOVED,
        self::DOCUMENT_UPDATED,
        self::DOCUMENT_UNEXEMPTED,
        self::DOCUMENT_FILE_DELETED,
        self::SCHEDULE_CREATED,
        self::SCHEDULE_UPDATED,
        self::SCHEDULE_DELETED,
        self::CLOSURE_ADDED,
        self::CLOSURE_REMOVED,
        self::INJURY_NOTE_SAVED,
        self::INJURY_NOTE_DELETED,
        self::USER_LOGGED_IN,
        self::USER_LOGGED_OUT,
        self::USER_CREATED,
        self::USER_UPDATED,
        self::USER_DELETED,
        self::USER_APPROVED,
        self::USER_PASSWORD_RESET,
        self::USER_STATUS_CHANGED,
        self::USER_IMPERSONATED,
        self::ROLE_CREATED,
        self::ROLE_UPDATED,
        self::ROLE_DELETED,
        self::PROFILE_UPDATED,
        self::PASSWORD_CHANGED,
        self::SETTINGS_UPDATED,
        self::SETTING_ITEM_CREATED,
        self::SETTING_ITEM_UPDATED,
        self::SETTING_ITEM_DELETED,
        self::BACKUP_CREATED,
        self::BACKUP_RESTORED,
        self::BACKUP_DELETED,
    ];

    /** @var array<string, list<string>> */
    const AREAS = [
        'players' => [
            self::PLAYER_REGISTERED,
            self::PLAYER_IMPORTED,
            self::PLAYER_ARCHIVED,
            self::JOB_CREATED,
            self::ACADEMIC_RECORD_ADDED,
            self::PLAYER_UPDATED,
            self::PLAYER_RESTORED,
            self::PLAYER_DELETED,
            self::ACADEMIC_RECORD_UPDATED,
            self::ACADEMIC_RECORD_DELETED,
            self::JOB_UPDATED,
            self::JOB_DELETED,
        ],
        'money' => [
            self::TRANSACTION_RECORDED,
            self::TRANSACTION_IMPORTED,
            self::PAYMENT_RECORDED,
            self::PAYMENT_EDITED,
            self::TRANSACTION_CANCELLED,
            self::TRANSFER_RECORDED,
            self::SUBSCRIPTION_CREATED,
            self::PLAYERS_ASSIGNED,
            self::TRANSACTION_UPDATED,
            self::SUBSCRIPTION_UPDATED,
            self::SUBSCRIPTION_DELETED,
            self::BUDGET_UPDATED,
            self::FINANCE_RESET,
        ],
        'equipment' => [
            self::STOCK_RECEIVED,
            self::EQUIPMENT_IMPORTED,
            self::EQUIPMENT_RENTED,
            self::EQUIPMENT_ASSIGNED,
            self::EQUIPMENT_RETURNED,
            self::STOCKTAKE_STARTED,
            self::STOCKTAKE_COMPLETED,
            self::EQUIPMENT_UPDATED,
            self::EQUIPMENT_DELETED,
            self::EQUIPMENT_SENT_TO_REPAIR,
            self::EQUIPMENT_REPAIRED,
            self::EQUIPMENT_MARKED_LOST,
            self::EQUIPMENT_MARKED_FOUND,
            self::STOCKTAKE_DELETED,
        ],
        'board' => [
            self::MEETING_CREATED,
            self::MEETING_CANCELLED,
            self::TASK_CREATED,
            self::TASK_COMPLETED,
            self::MEETING_UPDATED,
            self::TASK_UPDATED,
            self::TASK_DELETED,
            self::BOARD_MEMBER_ADDED,
            self::BOARD_MEMBER_UPDATED,
            self::BOARD_MEMBER_REMOVED,
        ],
        'documents' => [
            self::DOCUMENT_RECEIVED,
            self::DOCUMENT_RENEWED,
            self::DOCUMENT_EXEMPTED,
            self::DOCUMENT_FILE_UPLOADED,
            self::DOCUMENT_UPDATED,
            self::DOCUMENT_UNEXEMPTED,
            self::DOCUMENT_FILE_DELETED,
        ],
        'attendance' => [
            self::ATTENDANCE_MARKED,
            self::TRAINING_SESSION_CREATED,
            self::TRAINING_SESSION_CANCELLED,
            self::TRAINING_SESSION_MOVED,
            self::TRAINING_SESSION_CATEGORIES_CHANGED,
            self::TRAINING_SESSION_DELETED,
            self::TRAINING_SESSION_RESET,
            self::SCHEDULE_CREATED,
            self::SCHEDULE_UPDATED,
            self::SCHEDULE_DELETED,
            self::CLOSURE_ADDED,
            self::CLOSURE_REMOVED,
            self::INJURY_NOTE_SAVED,
            self::INJURY_NOTE_DELETED,
        ],
        'access' => [
            self::USER_LOGGED_IN,
            self::USER_LOGGED_OUT,
            self::USER_CREATED,
            self::USER_UPDATED,
            self::USER_DELETED,
            self::USER_APPROVED,
            self::USER_PASSWORD_RESET,
            self::USER_STATUS_CHANGED,
            self::USER_IMPERSONATED,
            self::ROLE_CREATED,
            self::ROLE_UPDATED,
            self::ROLE_DELETED,
            self::PROFILE_UPDATED,
            self::PASSWORD_CHANGED,
        ],
        'settings' => [
            self::SETTINGS_UPDATED,
            self::SETTING_ITEM_CREATED,
            self::SETTING_ITEM_UPDATED,
            self::SETTING_ITEM_DELETED,
            self::BACKUP_CREATED,
            self::BACKUP_RESTORED,
            self::BACKUP_DELETED,
        ],
    ];

    /**
     * Codes whose subject can later be cancelled/archived, and which state
     * that later change is reported under (per action-code table's "Quality"
     * paragraph). A missing subject counts as neither.
     *
     * @var array<string, 'cancelled'|'archived'>
     */
    const QUALITY = [
        self::TRANSACTION_RECORDED => 'cancelled',
        self::PAYMENT_RECORDED => 'cancelled',
        self::PAYMENT_EDITED => 'cancelled',
        self::PLAYER_REGISTERED => 'archived',
    ];
}
