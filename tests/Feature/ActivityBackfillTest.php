<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BoardMeeting;
use App\Models\BoardTask;
use App\Models\EquipmentItem;
use App\Models\FinanceTransfer;
use App\Models\InventorySession;
use App\Models\PlayerDocument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityBackfillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $playerId;

    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->user = User::factory()->create();
        $this->seedSources();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_26_100002_backfill_activity_logs.php');
    }

    private function backfill(): void
    {
        $this->migration()->up();
    }

    /** One attributed and one unattributed row per source; ids are fixed so assertions stay readable. */
    private function seedSources(): void
    {
        $uid = $this->user->id;

        $this->playerId = DB::table('players')->insertGetId([
            'membership_id' => '2026990001', 'firstname' => 'Back', 'lastname' => 'Fill',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $subscriptionId = DB::table('player_subscriptions')->insertGetId([
            'player_id' => $this->playerId, 'year' => 2026, 'amount_owed' => 2000, 'amount_paid' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tx = fn (array $a) => DB::table('transactions')->insert($a + [
            'transaction_type' => 'income', 'category' => 'misc', 'transaction_date' => now(), 'updated_at' => now(),
        ]);
        $tx(['id' => 101, 'amount' => 700, 'transaction_type' => 'expense', 'recorded_by_user_id' => $uid, 'created_at' => '2026-02-01 09:00:00']);
        $tx(['id' => 102, 'amount' => 1500, 'recorded_by_user_id' => $uid, 'player_subscription_id' => $subscriptionId, 'created_at' => '2026-02-02 09:00:00']);
        $tx(['id' => 103, 'amount' => 300, 'recorded_by_user_id' => $uid, 'related_entity_type' => 'Player', 'related_entity_id' => $this->playerId, 'created_at' => '2026-02-03 09:00:00']);
        $tx(['id' => 104, 'amount' => 999, 'recorded_by_user_id' => null, 'created_at' => '2026-02-04 09:00:00']);

        $catalogId = DB::table('equipment_catalogs')->insertGetId([
            'name' => 'Dossards', 'category' => 'Apparel', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->itemId = DB::table('equipment_items')->insertGetId([
            'catalog_id' => $catalogId, 'unique_identifier' => 'EQ-BACKFILL-1', 'purchase_date' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $history = fn (?int $user, string $type, ?array $details, string $at) => DB::table('equipment_histories')->insert([
            'item_id' => $this->itemId, 'user_id' => $user, 'event_type' => $type,
            'details' => $details === null ? null : json_encode($details),
            'event_timestamp' => $at, 'created_at' => $at,
        ]);
        $history($uid, 'Received', ['quantity' => 20, 'unit_price' => 100], '2026-03-01 10:00:00');
        $history($uid, 'Checkout', ['quantity' => 3], '2026-03-02 10:00:00');
        $history($uid, 'Checkout', ['quantity' => 2], '2026-03-03 10:00:00');
        $history($uid, 'Assigned', null, '2026-03-04 10:00:00');
        $history($uid, 'Return', ['quantity' => 1], '2026-03-05 10:00:00');
        $history($uid, 'Repair', ['notes' => 'x'], '2026-03-06 10:00:00');
        $history(null, 'Checkout', ['quantity' => 9], '2026-03-07 10:00:00');

        $meeting = fn (array $a) => DB::table('board_meetings')->insert($a + [
            'title' => 'Board', 'type' => 'ordinary', 'meeting_date' => '2026-05-01 18:00:00', 'updated_at' => now(),
        ]);
        $meeting(['id' => 201, 'created_by_user_id' => $uid, 'created_at' => '2026-04-01 08:00:00']);
        $meeting(['id' => 202, 'created_by_user_id' => $uid, 'created_at' => '2026-04-02 08:00:00',
            'status' => 'cancelled', 'cancelled_at' => '2026-04-10 08:00:00', 'cancelled_by_user_id' => $uid]);
        $meeting(['id' => 203, 'created_by_user_id' => null, 'created_at' => '2026-04-03 08:00:00',
            'status' => 'cancelled', 'cancelled_at' => '2026-04-11 08:00:00', 'cancelled_by_user_id' => null]);

        DB::table('board_tasks')->insert([
            ['id' => 301, 'title' => 'Hall', 'created_by_user_id' => $uid, 'created_at' => '2026-04-05 08:00:00', 'updated_at' => now()],
            ['id' => 302, 'title' => 'Bus', 'created_by_user_id' => null, 'created_at' => '2026-04-06 08:00:00', 'updated_at' => now()],
        ]);

        $session = fn (array $a) => DB::table('inventory_sessions')->insert($a + [
            'type' => 'ad_hoc', 'session_date' => '2026-06-01', 'updated_at' => now(),
        ]);
        $session(['id' => 401, 'reference' => 'INV-1', 'conducted_by_user_id' => $uid, 'created_at' => '2026-06-01 08:00:00']);
        $session(['id' => 402, 'reference' => 'INV-2', 'conducted_by_user_id' => $uid, 'created_at' => '2026-06-02 08:00:00',
            'status' => 'completed', 'completed_at' => '2026-06-02 11:00:00', 'total_found' => 18, 'total_missing' => 2]);
        $session(['id' => 403, 'reference' => 'INV-3', 'conducted_by_user_id' => null, 'created_at' => '2026-06-03 08:00:00',
            'status' => 'completed', 'completed_at' => '2026-06-03 11:00:00']);

        $account = fn (string $name) => DB::table('finance_accounts')->insertGetId([
            'name' => $name, 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0,
            'currency' => 'DZD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $from = $account('Backfill A');
        $to = $account('Backfill B');
        DB::table('finance_transfers')->insert([
            ['id' => 501, 'from_account_id' => $from, 'to_account_id' => $to, 'amount' => 2500, 'transfer_date' => '2026-07-01',
                'created_by_user_id' => $uid, 'created_at' => '2026-07-01 09:00:00', 'updated_at' => now()],
            ['id' => 502, 'from_account_id' => $from, 'to_account_id' => $to, 'amount' => 100, 'transfer_date' => '2026-07-02',
                'created_by_user_id' => null, 'created_at' => '2026-07-02 09:00:00', 'updated_at' => now()],
        ]);

        $typeId = DB::table('document_types')->value('id');
        DB::table('player_documents')->insert([
            'id' => 601, 'player_id' => $this->playerId, 'document_type_id' => $typeId, 'state' => 'received',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $file = fn (?int $user, string $at) => DB::table('player_document_files')->insert([
            'player_document_id' => 601, 'path' => 'docs/x.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf',
            'size' => 10, 'uploaded_by_user_id' => $user, 'created_at' => $at,
        ]);
        $file($uid, '2026-08-01 09:00:00');
        $file($uid, '2026-08-02 09:00:00');
        $file(null, '2026-08-03 09:00:00');
    }

    /** @return list<array{action: string, user_id: int|null, subject_type: string|null, subject_id: int|null, properties: array|null, occurred_at: string}> */
    private function logs(): array
    {
        return ActivityLog::query()->orderBy('action')->orderBy('occurred_at')->get()
            ->map(fn (ActivityLog $log) => [
                'action' => $log->action,
                'user_id' => $log->user_id,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id === null ? null : (int) $log->subject_id,
                'properties' => $log->properties,
                'occurred_at' => $log->occurred_at->format('Y-m-d H:i:s'),
            ])->all();
    }

    private function row(string $action, string $subjectType, int $subjectId, array $properties, string $at): array
    {
        return [
            'action' => $action,
            'user_id' => $this->user->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'properties' => ['backfilled' => true] + $properties,
            'occurred_at' => $at,
        ];
    }

    private function expected(): array
    {
        $item = EquipmentItem::class;
        $rows = [
            $this->row('document_file_uploaded', PlayerDocument::class, 601, ['count' => 1], '2026-08-01 09:00:00'),
            $this->row('document_file_uploaded', PlayerDocument::class, 601, ['count' => 1], '2026-08-02 09:00:00'),
            $this->row('equipment_assigned', $item, $this->itemId, [], '2026-03-04 10:00:00'),
            $this->row('equipment_rented', $item, $this->itemId, ['quantity' => 3], '2026-03-02 10:00:00'),
            $this->row('equipment_rented', $item, $this->itemId, ['quantity' => 2], '2026-03-03 10:00:00'),
            $this->row('equipment_returned', $item, $this->itemId, ['quantity' => 1], '2026-03-05 10:00:00'),
            $this->row('meeting_cancelled', BoardMeeting::class, 202, [], '2026-04-10 08:00:00'),
            $this->row('meeting_created', BoardMeeting::class, 201, [], '2026-04-01 08:00:00'),
            $this->row('meeting_created', BoardMeeting::class, 202, [], '2026-04-02 08:00:00'),
            $this->row('payment_recorded', Transaction::class, 102, ['amount' => 1500], '2026-02-02 09:00:00'),
            $this->row('payment_recorded', Transaction::class, 103, ['amount' => 300], '2026-02-03 09:00:00'),
            $this->row('stock_received', $item, $this->itemId, ['quantity' => 20], '2026-03-01 10:00:00'),
            $this->row('stocktake_completed', InventorySession::class, 402, ['found' => 18, 'missing' => 2], '2026-06-02 11:00:00'),
            $this->row('stocktake_started', InventorySession::class, 401, [], '2026-06-01 08:00:00'),
            $this->row('stocktake_started', InventorySession::class, 402, [], '2026-06-02 08:00:00'),
            $this->row('task_created', BoardTask::class, 301, [], '2026-04-05 08:00:00'),
            $this->row('transaction_recorded', Transaction::class, 101, ['amount' => 700, 'type' => 'expense'], '2026-02-01 09:00:00'),
            $this->row('transfer_recorded', FinanceTransfer::class, 501, ['amount' => 2500], '2026-07-01 09:00:00'),
        ];

        return $rows;
    }

    #[Test]
    public function it_backfills_one_event_per_attributed_source_row(): void
    {
        $this->backfill();

        $this->assertEquals($this->expected(), $this->logs());
        $this->assertSame(0, ActivityLog::whereNull('created_at')->count());
        $this->assertSame('2026-10-05 12:00:00', ActivityLog::first()->created_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function running_it_twice_adds_nothing(): void
    {
        $this->backfill();
        $this->backfill();

        $this->assertEquals($this->expected(), $this->logs());
    }

    #[Test]
    public function an_event_already_recorded_live_is_not_duplicated(): void
    {
        $live = ActivityLog::create([
            'user_id' => $this->user->id,
            'action' => 'transaction_recorded',
            'subject_type' => Transaction::class,
            'subject_id' => 101,
            'properties' => ['amount' => 700, 'type' => 'expense'],
            'occurred_at' => '2026-02-01 09:00:05',
        ]);

        $this->backfill();

        $rows = ActivityLog::where('action', 'transaction_recorded')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($live->id, $rows->first()->id);
    }

    #[Test]
    public function a_live_equipment_event_at_the_same_moment_is_not_duplicated_but_others_are_kept(): void
    {
        ActivityLog::create([
            'user_id' => $this->user->id,
            'action' => 'equipment_rented',
            'subject_type' => EquipmentItem::class,
            'subject_id' => $this->itemId,
            'properties' => ['quantity' => 3],
            'occurred_at' => '2026-03-02 10:00:00',
        ]);

        $this->backfill();

        $rented = ActivityLog::where('action', 'equipment_rented')->orderBy('occurred_at')->get();
        $this->assertCount(2, $rented);
        $this->assertNull($rented[0]->properties['backfilled'] ?? null);
        $this->assertTrue($rented[1]->properties['backfilled']);
    }

    #[Test]
    public function down_removes_only_backfilled_rows(): void
    {
        $live = ActivityLog::create([
            'user_id' => $this->user->id,
            'action' => 'player_registered',
            'occurred_at' => now(),
        ]);
        $liveWithProps = ActivityLog::create([
            'user_id' => $this->user->id,
            'action' => 'transfer_recorded',
            'properties' => ['amount' => 10],
            'occurred_at' => now(),
        ]);

        $this->backfill();
        $this->assertSame(count($this->expected()) + 2, ActivityLog::count());

        $this->migration()->down();

        $this->assertEqualsCanonicalizing([$live->id, $liveWithProps->id], ActivityLog::pluck('id')->all());
    }
}
