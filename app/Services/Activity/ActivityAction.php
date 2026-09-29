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
    ];

    /** @var array<string, list<string>> */
    const AREAS = [
        'players' => [
            self::PLAYER_REGISTERED,
            self::PLAYER_IMPORTED,
            self::PLAYER_ARCHIVED,
            self::JOB_CREATED,
            self::ACADEMIC_RECORD_ADDED,
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
        ],
        'equipment' => [
            self::STOCK_RECEIVED,
            self::EQUIPMENT_IMPORTED,
            self::EQUIPMENT_RENTED,
            self::EQUIPMENT_ASSIGNED,
            self::EQUIPMENT_RETURNED,
            self::STOCKTAKE_STARTED,
            self::STOCKTAKE_COMPLETED,
        ],
        'board' => [
            self::MEETING_CREATED,
            self::MEETING_CANCELLED,
            self::TASK_CREATED,
            self::TASK_COMPLETED,
        ],
        'documents' => [
            self::DOCUMENT_RECEIVED,
            self::DOCUMENT_RENEWED,
            self::DOCUMENT_EXEMPTED,
            self::DOCUMENT_FILE_UPLOADED,
        ],
        'attendance' => [
            self::ATTENDANCE_MARKED,
            self::TRAINING_SESSION_CREATED,
            self::TRAINING_SESSION_CANCELLED,
            self::TRAINING_SESSION_MOVED,
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
