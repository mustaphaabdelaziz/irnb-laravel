<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\EquipmentCatalog;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransfer;
use App\Models\FiscalYear;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityMoneyEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function makePlayer(array $extra = []): Player
    {
        return Player::create([
            'membership_id' => '81'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Money', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
        ] + $extra);
    }

    /** Same fixture as PlayerPaymentFlowTest. */
    private function makeSub(Player $player, float $owed = 2000): PlayerSubscription
    {
        $sub = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        return $sub;
    }

    private function catalogSubscription(bool $mandatory = true): Subscription
    {
        return Subscription::create([
            'name' => 'Annual '.++$this->seq, 'year' => (int) now()->year,
            'amount_student' => 2000, 'amount_worker' => 3000,
            'is_mandatory' => $mandatory, 'is_active' => true,
        ]);
    }

    /** Same fixture as TransactionBulkDeleteAndFiscalYearDeleteTest. */
    private function tx(float $amount, int $year, array $extra = []): Transaction
    {
        return Transaction::create([
            'amount' => $amount,
            'transaction_date' => $year.'-03-01',
            'transaction_type' => 'income',
            'category' => 'donation',
            'payment_method' => 'cash',
            'status' => 'Paid',
            'fiscal_year' => $year,
        ] + $extra);
    }

    private function csv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'act').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 't.csv', 'text/csv', null, true);
    }

    /** @return Collection<int, ActivityLog> */
    private function events(string $action): Collection
    {
        return ActivityLog::query()->where('action', $action)->orderBy('id')->get();
    }

    private function assertEvent(ActivityLog $event, User $actor, ?object $subject, array $properties): void
    {
        $this->assertSame($actor->id, $event->user_id);
        if ($subject) {
            $this->assertSame($subject->getMorphClass(), $event->subject_type);
            $this->assertSame($subject->getKey(), (int) $event->subject_id);
        } else {
            $this->assertNull($event->subject_type);
            $this->assertNull($event->subject_id);
        }
        $this->assertEqualsCanonicalizing(array_keys($properties), array_keys($event->properties ?? []));
        foreach ($properties as $key => $value) {
            $this->assertEquals($value, $event->properties[$key], $key);
        }
    }

    // ---- Transactions ----

    #[Test]
    public function recording_a_transaction_records_transaction_recorded(): void
    {
        $admin = $this->admin();
        $expense = FinanceCategory::where('type', 'expense')->firstOrFail();

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'Ballons',
            'amount' => 1500,
            'transaction_type' => 'expense',
            'finance_category_id' => $expense->id,
            'transaction_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tx = Transaction::query()->sole();
        $events = $this->events(ActivityAction::TRANSACTION_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $tx, ['amount' => 1500, 'type' => 'expense']);
    }

    #[Test]
    public function a_transaction_refused_for_a_closed_year_records_nothing(): void
    {
        $admin = $this->admin();
        $income = FinanceCategory::where('type', 'income')->firstOrFail();
        $year = (int) now()->year;
        FiscalYear::firstOrCreate(['year' => $year])->update(['status' => 'closed']);

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'X', 'amount' => 100, 'transaction_type' => 'income',
            'finance_category_id' => $income->id, 'fiscal_year' => $year,
        ]);

        $this->assertSame(0, ActivityLog::query()->count());
    }

    #[Test]
    public function importing_transactions_records_one_summary_event(): void
    {
        $admin = $this->admin();
        $file = $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n"
            ."2026-02-03,A,income,other,100,Paid,cash,,\r\n"
            ."2026-02-04,B,expense,other,250.5,Paid,cash,,\r\n"
            ."2026-02-05,C,income,other,abc,Paid,cash,,\r\n");

        $this->actingAs($admin)->post(route('transactions.import.store'), ['file' => $file])->assertRedirect();

        $this->assertSame(2, Transaction::query()->count());
        $this->assertCount(0, $this->events(ActivityAction::TRANSACTION_RECORDED));
        $events = $this->events(ActivityAction::TRANSACTION_IMPORTED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, null, ['count' => 2, 'amount' => 350.5]);
    }

    #[Test]
    public function an_import_with_no_valid_rows_records_nothing(): void
    {
        $file = $this->csv("\xEF\xBB\xBFDate,Title,Type,Category,Amount,Status,Payment method,Cash register,Description\r\n"
            ."2026-02-05,C,income,other,abc,Paid,cash,,\r\n");

        $this->actingAs($this->admin())->post(route('transactions.import.store'), ['file' => $file])->assertRedirect();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    #[Test]
    public function archiving_a_transaction_records_transaction_cancelled(): void
    {
        $admin = $this->admin();
        $tx = $this->tx(400, (int) now()->year);

        $this->actingAs($admin)->delete(route('transactions.destroy', $tx))->assertRedirect();

        $events = $this->events(ActivityAction::TRANSACTION_CANCELLED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $tx, ['amount' => 400]);
    }

    #[Test]
    public function archiving_in_a_closed_year_records_nothing(): void
    {
        $tx = $this->tx(400, 2024);
        FiscalYear::where('year', 2024)->update(['status' => 'closed']);

        $this->actingAs($this->admin())->delete(route('transactions.destroy', $tx));

        $this->assertFalse($tx->fresh()->archived);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    #[Test]
    public function bulk_archiving_records_one_cancellation_per_archived_row_only(): void
    {
        $admin = $this->admin();
        $a = $this->tx(100, 2026);
        $b = $this->tx(200, 2026);
        $closed = $this->tx(300, 2024);
        FiscalYear::where('year', 2024)->update(['status' => 'closed']);

        $this->actingAs($admin)
            ->post(route('transactions.bulkDestroy'), ['ids' => [$a->id, $b->id, $closed->id]])
            ->assertRedirect();

        $events = $this->events(ActivityAction::TRANSACTION_CANCELLED);
        $this->assertCount(2, $events);
        $bySubject = $events->keyBy(fn (ActivityLog $e) => (int) $e->subject_id);
        $this->assertEvent($bySubject[$a->id], $admin, $a, ['amount' => 100]);
        $this->assertEvent($bySubject[$b->id], $admin, $b, ['amount' => 200]);
        $this->assertArrayNotHasKey($closed->id, $bySubject);
    }

    // ---- Player payments ----

    #[Test]
    public function a_subscription_payment_records_payment_recorded(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id, 'category' => 'subscription', 'amount' => 1500, 'payment_method' => 'cash',
        ])->assertRedirect();

        $tx = Transaction::query()->sole();
        $events = $this->events(ActivityAction::PAYMENT_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $tx, ['amount' => 1500]);
        $this->assertCount(0, $this->events(ActivityAction::TRANSACTION_RECORDED));
    }

    #[Test]
    public function an_overpayment_records_one_event_for_the_total_on_the_subscription_payment(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id, 'category' => 'subscription', 'amount' => 2500, 'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(2, Transaction::query()->count());
        $subTx = Transaction::where('category', 'subscription')->firstOrFail();
        $events = $this->events(ActivityAction::PAYMENT_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $subTx, ['amount' => 2500]);
    }

    #[Test]
    public function a_player_level_donation_records_payment_recorded(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'category' => 'donation', 'amount' => 700, 'payment_method' => 'cash',
        ])->assertRedirect();

        $tx = Transaction::query()->sole();
        $events = $this->events(ActivityAction::PAYMENT_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $tx, ['amount' => 700]);
    }

    #[Test]
    public function an_exemption_records_nothing(): void
    {
        $player = $this->makePlayer();
        $sub = $this->makeSub($player);

        $this->actingAs($this->admin())->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id, 'category' => 'subscription', 'is_exempt' => true,
        ])->assertRedirect();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, ActivityLog::query()->count());
    }

    #[Test]
    public function paying_an_unassigned_subscription_records_the_payment_but_not_an_assignment(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();
        $catalog = $this->catalogSubscription(false);

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'subscription_id' => $catalog->id, 'category' => 'subscription', 'amount' => 500, 'payment_method' => 'cash',
        ])->assertRedirect();

        $this->assertSame(1, PlayerSubscription::query()->count());
        $this->assertCount(0, $this->events(ActivityAction::PLAYERS_ASSIGNED));
        $events = $this->events(ActivityAction::PAYMENT_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, Transaction::query()->sole(), ['amount' => 500]);
    }

    #[Test]
    public function editing_a_payment_records_only_payment_edited(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();
        $sub = $this->makeSub($player, 2000);
        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $sub->id, 'category' => 'subscription', 'amount' => 1000, 'payment_method' => 'cash',
        ]);
        $original = Transaction::query()->sole();
        ActivityLog::query()->delete();

        $this->actingAs($admin)->put(route('players.transactions.update', [$player, $original]), [
            'amount' => 1200, 'payment_method' => 'cash',
        ])->assertRedirect();

        $new = Transaction::where('archived', false)->sole();
        $this->assertNotSame($original->id, $new->id);
        $events = $this->events(ActivityAction::PAYMENT_EDITED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $new, ['amount' => 1200]);
        $this->assertSame(1, ActivityLog::query()->count(), 'no transaction_cancelled / payment_recorded for an edit');
    }

    #[Test]
    public function removing_a_payment_records_transaction_cancelled(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();
        $tx = $this->tx(300, (int) now()->year, ['related_entity_type' => 'Player', 'related_entity_id' => $player->id]);

        $this->actingAs($admin)->delete(route('players.transactions.destroy', [$player, $tx]))->assertRedirect();

        $events = $this->events(ActivityAction::TRANSACTION_CANCELLED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $tx, ['amount' => 300]);
    }

    #[Test]
    public function permanently_deleting_a_player_records_no_cancellation(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $player = $this->makePlayer(['archived' => true]);
        $tx = $this->tx(300, (int) now()->year, ['related_entity_type' => 'Player', 'related_entity_id' => $player->id]);

        $this->actingAs($this->admin())->delete(route('players.forceDelete', $player))->assertRedirect();

        $this->assertNull(Player::find($player->id));
        $this->assertTrue($tx->fresh()->archived);
        $this->assertCount(0, $this->events(ActivityAction::TRANSACTION_CANCELLED));
    }

    // ---- Transfers ----

    #[Test]
    public function a_transfer_records_transfer_recorded(): void
    {
        $admin = $this->admin();
        $branch = Branch::create(['name' => 'Football']);
        $treasury = $branch->treasury()->sole();
        $other = FinanceAccount::create([
            'name' => 'Petty cash', 'type' => 'cash', 'opening_balance' => 0, 'current_balance' => 0,
            'currency' => 'DZD', 'is_active' => true,
        ]);
        $income = FinanceCategory::where('type', 'income')->firstOrFail();
        Transaction::create([
            'amount' => 500, 'transaction_type' => 'income', 'category' => 'donation',
            'finance_category_id' => $income->id, 'finance_account_id' => $treasury->id,
            'status' => 'Paid', 'transaction_date' => now(),
        ]);

        $this->actingAs($admin)->post(route('finance.transfers.store'), [
            'from_account_id' => $treasury->id,
            'to_account_id' => $other->id,
            'amount' => 200,
            'transfer_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $transfer = FinanceTransfer::query()->sole();
        $events = $this->events(ActivityAction::TRANSFER_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $transfer, ['amount' => 200]);
    }

    // ---- Subscriptions ----

    #[Test]
    public function creating_a_subscription_records_subscription_created(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('subscriptions.store'), [
            'name' => 'Season', 'year' => 2026, 'amount_student' => 2000, 'amount_worker' => 3000, 'is_mandatory' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sub = Subscription::query()->sole();
        $events = $this->events(ActivityAction::SUBSCRIPTION_CREATED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $sub, ['kind' => Subscription::KIND_ANNUAL]);
    }

    #[Test]
    public function bulk_assigning_records_the_count_of_newly_assigned_players(): void
    {
        $admin = $this->admin();
        $sub = $this->catalogSubscription();
        $already = $this->makePlayer();
        $sub->assignTo($already);
        $a = $this->makePlayer();
        $b = $this->makePlayer();

        $this->actingAs($admin)->post(route('subscriptions.assign', $sub), [
            'player_ids' => [$already->id, $a->id, $b->id],
        ])->assertRedirect();

        $events = $this->events(ActivityAction::PLAYERS_ASSIGNED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $sub, ['count' => 2]);
    }

    #[Test]
    public function bulk_assigning_nobody_new_records_nothing(): void
    {
        $sub = $this->catalogSubscription();
        $already = $this->makePlayer();
        $sub->assignTo($already);

        $this->actingAs($this->admin())->post(route('subscriptions.assign', $sub), [
            'player_ids' => [$already->id],
        ])->assertRedirect();

        $this->assertCount(0, $this->events(ActivityAction::PLAYERS_ASSIGNED));
    }

    #[Test]
    public function assigning_one_player_records_a_count_of_one(): void
    {
        $admin = $this->admin();
        $sub = $this->catalogSubscription();
        $player = $this->makePlayer();

        $this->actingAs($admin)->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id])->assertRedirect();

        $events = $this->events(ActivityAction::PLAYERS_ASSIGNED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $sub, ['count' => 1]);

        // Assigning the same player again assigns nobody.
        $this->actingAs($admin)->post(route('subscriptions.assignOne', $sub), ['player_id' => $player->id])->assertRedirect();
        $this->assertCount(1, $this->events(ActivityAction::PLAYERS_ASSIGNED));
    }

    // ---- Stock receipt expense ----

    #[Test]
    public function receiving_stock_with_an_expense_records_transaction_recorded(): void
    {
        $admin = $this->admin();
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel', 'requires_serial' => false]);

        $this->actingAs($admin)->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id, 'quantity' => 10, 'unit_price' => 120,
            'purchase_date' => '2026-03-15', 'condition' => 'New', 'record_expense' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tx = Transaction::query()->sole();
        $events = $this->events(ActivityAction::TRANSACTION_RECORDED);
        $this->assertCount(1, $events);
        $this->assertEvent($events[0], $admin, $tx, ['amount' => 1200, 'type' => 'expense']);
    }

    #[Test]
    public function receiving_stock_without_an_expense_records_no_transaction(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel', 'requires_serial' => false]);

        $this->actingAs($this->admin())->post(route('equipment.stock.receive'), [
            'catalog_id' => $catalog->id, 'quantity' => 10, 'unit_price' => 120,
            'purchase_date' => '2026-03-15', 'condition' => 'New', 'record_expense' => false, 'received_via' => 'donation',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertCount(0, $this->events(ActivityAction::TRANSACTION_RECORDED));
    }
}
