<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\ActivityLog;
use App\Models\BoardMeeting;
use App\Models\BoardTask;
use App\Models\Category;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\InventorySession;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\PlayerAcademicYear;
use App\Models\PlayerDocument;
use App\Models\Subscription;
use App\Models\TrainingSession;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityPeriod;
use App\Services\Activity\ActivityReport;
use App\Services\Activity\ActivitySubjectLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityReportTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function month(): ActivityPeriod
    {
        return ActivityPeriod::fromRequest(Request::create('/activity'));
    }

    private function user(string $name, array $extra = []): User
    {
        return User::factory()->create(['name' => $name] + $extra);
    }

    private function log(?User $user, string $action, string $at = '2026-10-10 09:00:00', ?Model $subject = null, ?array $properties = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
            'occurred_at' => $at,
        ]);
    }

    private function player(array $extra = []): Player
    {
        return Player::create([
            'membership_id' => '77'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Yacine', 'lastname' => 'Brahimi',
        ] + $extra);
    }

    private function tx(float $amount, array $extra = []): Transaction
    {
        return Transaction::create($extra + [
            'title' => 'Cotisation '.++$this->seq,
            'amount' => $amount,
            'transaction_date' => '2026-10-10',
            'transaction_type' => 'income',
            'category' => 'donation',
            'payment_method' => 'cash',
            'status' => 'Paid',
            'fiscal_year' => 2026,
        ]);
    }

    /** @return array<int, array<string, mixed>> keyed by user id */
    private function byUser(array $rows): array
    {
        return collect($rows)->keyBy(fn (array $row) => $row['user']['id'])->all();
    }

    #[Test]
    public function the_comparison_ranks_by_payments_and_lists_idle_active_users(): void
    {
        $few = $this->user('Amine');
        $many = $this->user('Karim');
        $idle = $this->user('Zoubir');
        $inactiveIdle = $this->user('Hidden', ['is_active' => false]);
        $pending = $this->user('Pending', ['approved' => false]);
        $inactiveBusy = $this->user('Former', ['is_active' => false]);

        $this->log($few, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 1000]);
        $this->log($many, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 1500]);
        $this->log($many, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 250.5]);
        $this->log($many, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 100]);
        // Edits are not new payments.
        $this->log($many, ActivityAction::PAYMENT_EDITED, properties: ['amount' => 9999]);
        $this->log($inactiveBusy, ActivityAction::MEETING_CREATED);
        $this->log($inactiveBusy, ActivityAction::TASK_CREATED);
        $this->log($few, ActivityAction::PLAYER_REGISTERED);
        // Outside the month: ignored.
        $this->log($few, ActivityAction::PAYMENT_RECORDED, '2026-09-30 23:59:59', properties: ['amount' => 5000]);
        $this->log($few, ActivityAction::PAYMENT_RECORDED, '2026-11-01 00:00:00', properties: ['amount' => 5000]);
        $this->log($idle, ActivityAction::PAYMENT_RECORDED, '2026-09-01 10:00:00', properties: ['amount' => 5000]);
        // No author: not a user row.
        $this->log(null, ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 1]);

        $rows = ActivityReport::comparison($this->month());

        $this->assertSame([$many->id, $few->id, $inactiveBusy->id, $idle->id], array_map(fn ($r) => $r['user']['id'], $rows));
        $this->assertNotContains($inactiveIdle->id, array_map(fn ($r) => $r['user']['id'], $rows));
        $this->assertNotContains($pending->id, array_map(fn ($r) => $r['user']['id'], $rows));

        $rows = $this->byUser($rows);
        $this->assertSame(['id' => $many->id, 'name' => 'Karim'], $rows[$many->id]['user']);
        $this->assertSame(['count' => 3, 'amount' => 1850.5], $rows[$many->id]['payments']);
        $this->assertSame(['players' => 0, 'money' => 4, 'equipment' => 0, 'board' => 0, 'documents' => 0, 'attendance' => 0, 'access' => 0, 'settings' => 0], $rows[$many->id]['areas']);
        $this->assertSame(4, $rows[$many->id]['total']);

        $this->assertSame(['count' => 1, 'amount' => 1000.0], $rows[$few->id]['payments']);
        $this->assertSame(2, $rows[$few->id]['total']);
        $this->assertSame(1, $rows[$few->id]['areas']['players']);

        $this->assertSame(2, $rows[$inactiveBusy->id]['areas']['board']);
        $this->assertSame(['count' => 0, 'amount' => 0.0], $rows[$inactiveBusy->id]['payments']);

        $this->assertSame(['count' => 0, 'amount' => 0.0], $rows[$idle->id]['payments']);
        $this->assertSame(0, $rows[$idle->id]['total']);
        $this->assertSame(['players' => 0, 'money' => 0, 'equipment' => 0, 'board' => 0, 'documents' => 0, 'attendance' => 0, 'access' => 0, 'settings' => 0], $rows[$idle->id]['areas']);
    }

    #[Test]
    public function comparison_ties_fall_back_to_total_then_name(): void
    {
        $b = $this->user('Bilal');
        $a = $this->user('Adel');
        $c = $this->user('Chakib');

        $this->log($c, ActivityAction::TASK_CREATED);
        $this->log($c, ActivityAction::TASK_CREATED);
        $this->log($b, ActivityAction::TASK_CREATED);
        $this->log($a, ActivityAction::TASK_CREATED);

        $ids = array_map(fn ($r) => $r['user']['id'], ActivityReport::comparison($this->month()));

        $this->assertSame([$c->id, $a->id, $b->id], $ids);
    }

    #[Test]
    public function the_summary_counts_actions_sums_amounts_and_reports_quality(): void
    {
        $user = $this->user('Amine');
        $other = $this->user('Karim');

        $kept = $this->tx(1000);
        $cancelled = $this->tx(500);
        $gone = $this->tx(200);
        $this->log($user, ActivityAction::PAYMENT_RECORDED, subject: $kept, properties: ['amount' => 1000]);
        $this->log($user, ActivityAction::PAYMENT_RECORDED, subject: $cancelled, properties: ['amount' => 500.25]);
        $this->log($user, ActivityAction::PAYMENT_RECORDED, subject: $gone, properties: ['amount' => 200]);
        $cancelled->update(['archived' => true]);
        DB::table('transactions')->where('id', $gone->id)->delete();

        $active = $this->player();
        $archived = $this->player();
        $deleted = $this->player();
        $this->log($user, ActivityAction::PLAYER_REGISTERED, subject: $active);
        $this->log($user, ActivityAction::PLAYER_REGISTERED, subject: $archived);
        $this->log($user, ActivityAction::PLAYER_REGISTERED, subject: $deleted);
        $archived->update(['archived' => true]);
        DB::table('players')->where('id', $deleted->id)->delete();

        $this->log($user, ActivityAction::PLAYER_IMPORTED, properties: ['count' => 12]);
        $this->log($user, ActivityAction::TRANSACTION_IMPORTED, properties: ['count' => 3, 'amount' => 700]);
        // Another user's and another month's events stay out.
        $this->log($other, ActivityAction::PAYMENT_RECORDED, subject: $kept, properties: ['amount' => 1]);
        $this->log($user, ActivityAction::PAYMENT_RECORDED, '2026-09-10 10:00:00', subject: $cancelled, properties: ['amount' => 1]);

        $summary = collect(ActivityReport::summary($user->id, $this->month()))->keyBy('action');

        $this->assertEqualsCanonicalizing(
            [ActivityAction::PAYMENT_RECORDED, ActivityAction::PLAYER_REGISTERED, ActivityAction::PLAYER_IMPORTED, ActivityAction::TRANSACTION_IMPORTED],
            $summary->keys()->all(),
        );

        $this->assertSame([
            'action' => ActivityAction::PAYMENT_RECORDED,
            'area' => 'money',
            'count' => 3,
            'amount' => 1700.25,
            'quality' => ['kind' => 'cancelled', 'count' => 1],
        ], $summary[ActivityAction::PAYMENT_RECORDED]);

        $this->assertSame([
            'action' => ActivityAction::PLAYER_REGISTERED,
            'area' => 'players',
            'count' => 3,
            'amount' => null,
            'quality' => ['kind' => 'archived', 'count' => 1],
        ], $summary[ActivityAction::PLAYER_REGISTERED]);

        $this->assertNull($summary[ActivityAction::PLAYER_IMPORTED]['amount']);
        $this->assertNull($summary[ActivityAction::PLAYER_IMPORTED]['quality']);
        $this->assertSame(700.0, $summary[ActivityAction::TRANSACTION_IMPORTED]['amount']);
        $this->assertNull($summary[ActivityAction::TRANSACTION_IMPORTED]['quality']);
    }

    #[Test]
    public function quality_is_zero_when_nothing_was_later_cancelled(): void
    {
        $user = $this->user('Amine');
        $this->log($user, ActivityAction::TRANSACTION_RECORDED, subject: $this->tx(100), properties: ['amount' => 100, 'type' => 'income']);

        $row = ActivityReport::summary($user->id, $this->month())[0];

        $this->assertSame(['kind' => 'cancelled', 'count' => 0], $row['quality']);
    }

    #[Test]
    public function entries_are_paginated_newest_first_with_subject_links(): void
    {
        $user = $this->user('Amine');
        $player = $this->player();

        for ($i = 1; $i <= 27; $i++) {
            $this->log($user, ActivityAction::PLAYER_REGISTERED, sprintf('2026-10-%02d 09:00:00', min($i, 28)), $player);
        }
        $newest = $this->log($user, ActivityAction::PLAYER_REGISTERED, '2026-10-28 18:00:00', $player);
        $this->log($user, ActivityAction::PLAYER_ARCHIVED, '2026-10-28 19:00:00', $player);

        $page = ActivityReport::entries($user->id, ActivityAction::PLAYER_REGISTERED, $this->month());

        $this->assertSame(28, $page->total());
        $this->assertCount(25, $page->items());
        $first = $page->items()[0];
        $this->assertSame($newest->id, $first['id']);
        $this->assertSame($newest->occurred_at->toIso8601String(), $first['occurred_at']);
        $this->assertSame([], $first['properties']);
        $this->assertSame([
            'label' => $player->fullname,
            'url' => route('players.show', $player),
            'deleted' => false,
        ], $first['subject']);

        $dates = array_map(fn ($e) => $e['occurred_at'], $page->items());
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);

        $second = ActivityReport::entries($user->id, ActivityAction::PLAYER_REGISTERED, $this->month(), 25, 2);
        $this->assertCount(3, $second->items());
    }

    #[Test]
    public function entries_for_a_deleted_subject_say_so(): void
    {
        $user = $this->user('Amine');
        $tx = $this->tx(300);
        $this->log($user, ActivityAction::PAYMENT_RECORDED, subject: $tx, properties: ['amount' => 300]);
        DB::table('transactions')->where('id', $tx->id)->delete();

        $entry = ActivityReport::entries($user->id, ActivityAction::PAYMENT_RECORDED, $this->month())->items()[0];

        $this->assertTrue($entry['subject']['deleted']);
        $this->assertNull($entry['subject']['url']);
        $this->assertSame(['amount' => 300], $entry['properties']);
    }

    #[Test]
    public function an_import_without_subject_is_labelled_from_its_count(): void
    {
        $user = $this->user('Amine');
        $this->log($user, ActivityAction::PLAYER_IMPORTED, properties: ['count' => 12]);

        $entry = ActivityReport::entries($user->id, ActivityAction::PLAYER_IMPORTED, $this->month())->items()[0];

        $this->assertFalse($entry['subject']['deleted']);
        $this->assertNull($entry['subject']['url']);
        $this->assertStringContainsString('12', $entry['subject']['label']);
    }

    #[Test]
    public function every_subject_type_links_to_its_page(): void
    {
        $user = $this->user('Amine');
        $player = $this->player();
        $tx = $this->tx(450, ['title' => 'Achat ballons']);
        $meeting = BoardMeeting::create(['title' => 'AG ordinaire', 'type' => 'ordinary', 'meeting_date' => '2026-10-20 18:00:00']);
        $task = BoardTask::create(['title' => 'Réserver la salle']);
        $catalog = EquipmentCatalog::create(['name' => 'Ballons', 'category' => 'Balls']);
        $item = EquipmentItem::create(['catalog_id' => $catalog->id, 'purchase_date' => '2026-01-01', 'quantity' => 10, 'designation' => 'Ballon T5']);
        $rental = EquipmentRental::create([
            'equipment_item_id' => $item->id, 'rentable_type' => Player::class, 'rentable_id' => $player->id,
            'checkout_date' => now(), 'quantity' => 2,
        ]);
        $session = InventorySession::create(['reference' => 'INV-2026-01', 'type' => 'ad_hoc', 'session_date' => '2026-10-01']);
        $document = PlayerDocument::create([
            'player_id' => $player->id, 'document_type_id' => DB::table('document_types')->value('id'), 'state' => 'received',
        ]);
        $year = PlayerAcademicYear::create(['player_id' => $player->id, 'academic_year' => 2025, 'education_level' => 'middle']);
        $record = PlayerAcademicRecord::create(['player_academic_year_id' => $year->id, 'period' => 'T1', 'gpa' => 14]);
        $job = MemberJob::create(['name' => 'Menuisier']);
        $subscription = Subscription::create([
            'name' => 'Annuelle 2026', 'year' => 2026, 'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => true, 'is_active' => true,
        ]);
        $account = fn (string $name) => FinanceAccount::create([
            'name' => $name, 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0, 'currency' => 'DZD', 'is_active' => true,
        ]);
        $transfer = FinanceTransfer::create([
            'from_account_id' => $account('A')->id, 'to_account_id' => $account('B')->id, 'amount' => 2500, 'transfer_date' => '2026-10-10',
        ]);
        $category = Category::create(['name' => 'U15']);
        $trainingSession = TrainingSession::create([
            'category_id' => $category->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);

        $expected = [
            [ActivityAction::PLAYER_REGISTERED, $player, route('players.show', $player), $player->fullname],
            [ActivityAction::TRANSACTION_RECORDED, $tx, route('transactions.show', $tx), 'Achat ballons'],
            [ActivityAction::MEETING_CREATED, $meeting, route('board.meetings.show', $meeting), 'AG ordinaire'],
            [ActivityAction::TASK_CREATED, $task, route('board.tasks'), 'Réserver la salle'],
            [ActivityAction::EQUIPMENT_IMPORTED, $catalog, route('equipment.catalogs.show', $catalog), 'Ballons'],
            [ActivityAction::STOCK_RECEIVED, $item, route('equipment.items.history', $item), 'Ballon T5'],
            [ActivityAction::EQUIPMENT_RENTED, $rental, route('equipment.items.history', $item), 'Ballon T5'],
            [ActivityAction::STOCKTAKE_STARTED, $session, route('inventory.show', $session), 'INV-2026-01'],
            [ActivityAction::DOCUMENT_RECEIVED, $document, route('players.show', $player), null],
            [ActivityAction::ACADEMIC_RECORD_ADDED, $record, route('players.show', $player), null],
            [ActivityAction::JOB_CREATED, $job, route('jobs.index'), 'Menuisier'],
            [ActivityAction::SUBSCRIPTION_CREATED, $subscription, route('subscriptions.show', $subscription), 'Annuelle 2026'],
            [ActivityAction::TRANSFER_RECORDED, $transfer, route('finance.registers.index'), null],
            [ActivityAction::TRAINING_SESSION_CREATED, $trainingSession, route('attendance.sessions.show', $trainingSession), 'U15 · 2026-10-05'],
        ];

        foreach ($expected as [$action, $subject, $url, $label]) {
            $this->log($user, $action, subject: $subject);
        }

        $logs = ActivityLog::orderBy('id')->get();
        ActivitySubjectLink::preload($logs);

        foreach ($logs as $i => $log) {
            [$action, $subject, $url, $label] = $expected[$i];
            $link = ActivitySubjectLink::for($log);

            $this->assertSame($url, $link['url'], $action);
            $this->assertFalse($link['deleted'], $action);
            $this->assertNotSame('', $link['label'], $action);
            if ($label !== null) {
                $this->assertSame($label, $link['label'], $action);
            }
        }

        // Document and academic labels name the player; the rental never names its recipient.
        $this->assertStringContainsString($player->fullname, ActivitySubjectLink::for($logs[8])['label']);
        $this->assertStringContainsString($player->fullname, ActivitySubjectLink::for($logs[9])['label']);
    }

    #[Test]
    public function a_single_log_resolves_without_preloading(): void
    {
        $user = $this->user('Amine');
        $meeting = BoardMeeting::create(['title' => 'AG', 'type' => 'ordinary', 'meeting_date' => '2026-10-20 18:00:00']);
        $log = $this->log($user, ActivityAction::MEETING_CREATED, subject: $meeting);

        $this->assertSame(
            ['label' => 'AG', 'url' => route('board.meetings.show', $meeting), 'deleted' => false],
            ActivitySubjectLink::for(ActivityLog::find($log->id)),
        );
    }

    #[Test]
    public function report_queries_do_not_grow_with_the_number_of_events(): void
    {
        $users = collect(['Amine', 'Karim', 'Zoubir'])->map(fn ($n) => $this->user($n));

        $seed = function (int $count) use ($users) {
            for ($i = 0; $i < $count; $i++) {
                $user = $users[$i % 3];
                match ($i % 5) {
                    0 => $this->log($users[0], ActivityAction::PAYMENT_RECORDED, subject: $this->tx(100), properties: ['amount' => 100]),
                    1 => $this->log($users[0], ActivityAction::PAYMENT_RECORDED, subject: $this->player(), properties: ['amount' => 50]),
                    2 => $this->log($user, ActivityAction::PLAYER_REGISTERED, subject: $this->player()),
                    3 => $this->log($users[0], ActivityAction::PAYMENT_RECORDED, properties: ['amount' => 10]),
                    4 => $this->log($user, ActivityAction::MEETING_CREATED, subject: BoardMeeting::create([
                        'title' => 'M'.$i, 'type' => 'ordinary', 'meeting_date' => '2026-10-20 18:00:00',
                    ])),
                };
            }
        };

        $measure = function () use ($users): array {
            $counts = [];
            foreach ([
                'comparison' => fn () => ActivityReport::comparison($this->month()),
                'summary' => fn () => ActivityReport::summary($users[0]->id, $this->month()),
                'entries' => fn () => ActivityReport::entries($users[0]->id, ActivityAction::PAYMENT_RECORDED, $this->month())->items(),
            ] as $name => $run) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $run();
                $counts[$name] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            return $counts;
        };

        $seed(5);
        $few = $measure();
        $seed(45);
        $many = $measure();

        $this->assertSame($few, $many);
    }
}
