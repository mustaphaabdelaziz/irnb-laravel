<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Backfills activity_logs from the attribution columns that already exist
 * (recorded_by / created_by / conducted_by / uploaded_by / history user_id),
 * so work done before activity tracking still counts.
 *
 * Query builder only, with the action codes and subject classes as literals:
 * later changes to models, ActivityAction or ActivityRecorder must never
 * break this migration (the desktop app runs `migrate` on every boot).
 *
 * Idempotent: a source row is skipped when a log with the same action and
 * subject already exists (live-recorded or from an earlier run). For
 * equipment events and document uploads, one subject legitimately has many
 * events, so the occurred_at moment is part of the key there.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    private const EQUIPMENT_EVENTS = [
        'Received' => 'stock_received',
        'Checkout' => 'equipment_rented',
        'Assigned' => 'equipment_assigned',
        'Return' => 'equipment_returned',
    ];

    /** @var array<string, true> action|subject_type|subject_id[|occurred_at] keys already logged */
    private array $existing = [];

    private string $now;

    public function up(): void
    {
        $this->now = Carbon::now()->format('Y-m-d H:i:s');

        $this->transactions();
        $this->equipmentHistories();
        $this->boardMeetings();
        $this->boardTasks();
        $this->inventorySessions();
        $this->financeTransfers();
        $this->documentFiles();
    }

    public function down(): void
    {
        DB::table('activity_logs')->where('properties->backfilled', true)->delete();
    }

    private function transactions(): void
    {
        $type = 'App\Models\Transaction';
        $this->loadExisting(['payment_recorded', 'transaction_recorded'], $type, false);

        DB::table('transactions')
            ->select(['id', 'amount', 'transaction_type', 'recorded_by_user_id', 'related_entity_type', 'player_subscription_id', 'created_at', 'updated_at'])
            ->whereNotNull('recorded_by_user_id')
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $this->insert($rows->map(function ($row) use ($type) {
                    $isPayment = $row->player_subscription_id !== null || $row->related_entity_type === 'Player';
                    $properties = ['amount' => (float) $row->amount];
                    if (! $isPayment) {
                        $properties['type'] = $row->transaction_type;
                    }

                    return $this->entry(
                        $isPayment ? 'payment_recorded' : 'transaction_recorded',
                        $row->recorded_by_user_id, $type, $row->id, $properties,
                        $row->created_at ?? $row->updated_at,
                    );
                }));
            });
    }

    private function equipmentHistories(): void
    {
        $type = 'App\Models\EquipmentItem';
        $this->loadExisting(array_values(self::EQUIPMENT_EVENTS), $type, true);

        DB::table('equipment_histories')
            ->select(['id', 'item_id', 'user_id', 'event_type', 'details', 'event_timestamp', 'created_at'])
            ->whereNotNull('user_id')
            ->whereIn('event_type', array_keys(self::EQUIPMENT_EVENTS))
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $this->insert($rows->map(function ($row) use ($type) {
                    $quantity = $this->detail($row->details, 'quantity');

                    return $this->entry(
                        self::EQUIPMENT_EVENTS[$row->event_type],
                        $row->user_id, $type, $row->item_id,
                        is_numeric($quantity) ? ['quantity' => (int) $quantity] : [],
                        $row->event_timestamp ?? $row->created_at,
                        true,
                    );
                }));
            });
    }

    private function boardMeetings(): void
    {
        $type = 'App\Models\BoardMeeting';
        $this->loadExisting(['meeting_created', 'meeting_cancelled'], $type, false);

        DB::table('board_meetings')
            ->select(['id', 'created_by_user_id', 'created_at', 'updated_at', 'cancelled_at', 'cancelled_by_user_id'])
            ->where(fn ($q) => $q->whereNotNull('created_by_user_id')->orWhereNotNull('cancelled_by_user_id'))
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $entries = collect();
                foreach ($rows as $row) {
                    if ($row->created_by_user_id !== null) {
                        $entries->push($this->entry('meeting_created', $row->created_by_user_id, $type, $row->id, [], $row->created_at ?? $row->updated_at));
                    }
                    if ($row->cancelled_by_user_id !== null) {
                        $entries->push($this->entry('meeting_cancelled', $row->cancelled_by_user_id, $type, $row->id, [], $row->cancelled_at ?? $row->updated_at));
                    }
                }
                $this->insert($entries);
            });
    }

    private function boardTasks(): void
    {
        $type = 'App\Models\BoardTask';
        $this->loadExisting(['task_created'], $type, false);

        DB::table('board_tasks')
            ->select(['id', 'created_by_user_id', 'created_at', 'updated_at'])
            ->whereNotNull('created_by_user_id')
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $this->insert($rows->map(fn ($row) => $this->entry(
                    'task_created', $row->created_by_user_id, $type, $row->id, [], $row->created_at ?? $row->updated_at,
                )));
            });
    }

    /** The completer was never stored, so completion is credited to the conductor. */
    private function inventorySessions(): void
    {
        $type = 'App\Models\InventorySession';
        $this->loadExisting(['stocktake_started', 'stocktake_completed'], $type, false);

        DB::table('inventory_sessions')
            ->select(['id', 'conducted_by_user_id', 'created_at', 'updated_at', 'completed_at', 'total_found', 'total_missing'])
            ->whereNotNull('conducted_by_user_id')
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $entries = collect();
                foreach ($rows as $row) {
                    $entries->push($this->entry('stocktake_started', $row->conducted_by_user_id, $type, $row->id, [], $row->created_at ?? $row->updated_at));
                    if ($row->completed_at !== null) {
                        $entries->push($this->entry('stocktake_completed', $row->conducted_by_user_id, $type, $row->id, [
                            'found' => (int) $row->total_found,
                            'missing' => (int) $row->total_missing,
                        ], $row->completed_at));
                    }
                }
                $this->insert($entries);
            });
    }

    private function financeTransfers(): void
    {
        $type = 'App\Models\FinanceTransfer';
        $this->loadExisting(['transfer_recorded'], $type, false);

        DB::table('finance_transfers')
            ->select(['id', 'amount', 'created_by_user_id', 'created_at', 'updated_at'])
            ->whereNotNull('created_by_user_id')
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $this->insert($rows->map(fn ($row) => $this->entry(
                    'transfer_recorded', $row->created_by_user_id, $type, $row->id,
                    ['amount' => (float) $row->amount], $row->created_at ?? $row->updated_at,
                )));
            });
    }

    /** One event per stored file: the upload batches were never recorded. */
    private function documentFiles(): void
    {
        $type = 'App\Models\PlayerDocument';
        $this->loadExisting(['document_file_uploaded'], $type, true);

        DB::table('player_document_files')
            ->select(['id', 'player_document_id', 'uploaded_by_user_id', 'created_at'])
            ->whereNotNull('uploaded_by_user_id')
            ->chunkById(self::CHUNK, function ($rows) use ($type) {
                $this->insert($rows->map(fn ($row) => $this->entry(
                    'document_file_uploaded', $row->uploaded_by_user_id, $type, $row->player_document_id,
                    ['count' => 1], $row->created_at, true,
                )));
            });
    }

    /**
     * Loads the keys already logged for these actions before any insert, so
     * two legitimate same-moment source rows are both backfilled on the first
     * run, and neither is re-added on a later run.
     *
     * @param  list<string>  $actions
     */
    private function loadExisting(array $actions, string $subjectType, bool $withMoment): void
    {
        $this->existing = [];

        DB::table('activity_logs')
            ->select(['id', 'action', 'subject_id', 'occurred_at'])
            ->whereIn('action', $actions)
            ->where('subject_type', $subjectType)
            ->whereNotNull('subject_id')
            ->orderBy('id')
            ->each(function ($log) use ($subjectType, $withMoment) {
                $this->existing[$this->key($log->action, $subjectType, $log->subject_id, $withMoment ? $log->occurred_at : null)] = true;
            }, 1000);
    }

    /** @return array<string, mixed>|null the row to insert, or null when it is already logged */
    private function entry(string $action, int|string $userId, string $subjectType, int|string $subjectId, array $properties, ?string $at, bool $keyedByMoment = false): ?array
    {
        $occurredAt = $at === null ? $this->now : Carbon::parse($at)->format('Y-m-d H:i:s');

        if (isset($this->existing[$this->key($action, $subjectType, $subjectId, $keyedByMoment ? $occurredAt : null)])) {
            return null;
        }

        return [
            'user_id' => (int) $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => (int) $subjectId,
            'properties' => json_encode(['backfilled' => true] + $properties),
            'occurred_at' => $occurredAt,
            'created_at' => $this->now,
        ];
    }

    private function key(string $action, string $subjectType, int|string $subjectId, ?string $moment): string
    {
        $key = $action.'|'.$subjectType.'|'.(int) $subjectId;

        return $moment === null ? $key : $key.'|'.Carbon::parse($moment)->format('Y-m-d H:i:s');
    }

    private function insert($entries): void
    {
        $rows = $entries->filter()->values()->all();

        if ($rows !== []) {
            DB::table('activity_logs')->insert($rows);
        }
    }

    /** Reads one key from a history's details JSON (tolerating a double-encoded value). */
    private function detail(?string $json, string $key): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        $details = json_decode($json, true);
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        return is_array($details) ? ($details[$key] ?? null) : null;
    }
};
