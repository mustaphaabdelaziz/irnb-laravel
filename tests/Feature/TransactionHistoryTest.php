<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Finance\RecalculatePlayerDebtService;
use App\Services\Finance\TransactionHistory;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email_verified_at' => now(), 'name' => 'Cashier One', 'preferred_lng' => 'en']);
    }

    private function tx(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'title' => 'Hall rent', 'amount' => 1000, 'transaction_type' => 'expense', 'category' => 'rent',
            'payment_method' => 'cash', 'status' => 'Paid', 'recorded_by_user_id' => $this->admin->id,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Donation'], ['is_active' => true]);

        return array_merge([
            'title' => 'Sponsor gift', 'transaction_type' => 'income', 'finance_category_id' => $category->id,
            'amount' => 3000, 'transaction_date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'Paid',
        ], $overrides);
    }

    /** @return list<string> */
    private function types(array $entries): array
    {
        return array_column($entries, 'type');
    }

    #[Test]
    public function a_transaction_with_no_log_still_says_who_recorded_it_and_when(): void
    {
        $tx = $this->tx();

        $entries = TransactionHistory::entries($tx);

        $this->assertCount(1, $entries);
        $this->assertSame('recorded', $entries[0]['type']);
        $this->assertSame('Cashier One', $entries[0]['user']);
        $this->assertNull($entries[0]['as_user']);
        $this->assertSame($tx->created_at->format('Y-m-d H:i'), $entries[0]['at']);
        $this->assertSame([], $entries[0]['changes']);
    }

    #[Test]
    public function an_old_edit_shows_without_detail(): void
    {
        $tx = $this->tx();
        ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_UPDATED, $tx);

        $entries = TransactionHistory::entries($tx);

        $this->assertSame(['recorded', 'edited'], $this->types($entries));
        $this->assertSame('Cashier One', $entries[1]['user']);
        $this->assertSame([], $entries[1]['changes']);
    }

    #[Test]
    public function an_edit_lists_old_and_new_values_with_category_names(): void
    {
        $this->actingAs($this->admin)->post(route('transactions.store'), $this->payload())->assertSessionHasNoErrors();
        $tx = Transaction::latest('id')->firstOrFail();
        $other = FinanceCategory::create(['type' => 'income', 'name' => 'Trace Sponsoring', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['amount' => 4500, 'finance_category_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $edited = collect(TransactionHistory::entries($tx->fresh()))->firstWhere('type', 'edited');
        $changes = collect($edited['changes'])->keyBy('field');

        $this->assertEquals(3000, $changes['amount']['old']);
        $this->assertEquals(4500, $changes['amount']['new']);
        $this->assertSame($other->fresh()->localized_name, $changes['finance_category_id']['new']);
        $this->assertIsString($changes['finance_category_id']['old']);
    }

    #[Test]
    public function a_deleted_category_falls_back_to_the_name_kept_at_edit_time(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_UPDATED, $tx);
        TransactionRevision::create([
            'transaction_id' => $tx->id,
            'activity_log_id' => $log->id,
            'diff' => ['finance_category_id' => ['old' => 99998, 'new' => 99999, 'old_label' => 'Old rent', 'new_label' => 'New rent']],
        ]);

        $change = TransactionHistory::entries($tx)[1]['changes'][0];

        $this->assertSame(['field' => 'finance_category_id', 'old' => 'Old rent', 'new' => 'New rent'], $change);
    }

    #[Test]
    public function work_done_logged_in_as_someone_names_the_real_person_first(): void
    {
        $member = User::factory()->admin()->create(['name' => 'Karim Desk']);

        $this->actingAs($member)
            ->withSession([Impersonation::SESSION_KEY => $this->admin->id])
            ->post(route('transactions.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $entry = TransactionHistory::entries(Transaction::latest('id')->firstOrFail())[0];

        $this->assertSame('Cashier One', $entry['user']);
        $this->assertSame('Karim Desk', $entry['as_user']);
    }

    #[Test]
    public function a_cancellation_is_the_last_entry_and_fills_the_banner(): void
    {
        $tx = $this->tx();

        $this->actingAs($this->admin)->delete(route('transactions.destroy', $tx))->assertRedirect();

        $tx = $tx->fresh();
        $entries = TransactionHistory::entries($tx);
        $this->assertSame(['recorded', 'cancelled'], $this->types($entries));

        $banner = TransactionHistory::cancellation($tx, $entries);
        $this->assertSame('Cashier One', $banner['user']);
        $this->assertSame($entries[1]['at'], $banner['at']);
        $this->assertSame([], $banner['replaced_by']);

        $this->assertSame('cancelled', TransactionHistory::trace($entries)['modified']['type']);
    }

    #[Test]
    public function a_live_transaction_has_no_banner_and_no_modification(): void
    {
        $tx = $this->tx();
        $entries = TransactionHistory::entries($tx);

        $this->assertNull(TransactionHistory::cancellation($tx, $entries));
        $this->assertSame('Cashier One', TransactionHistory::trace($entries)['recorded']['user']);
        $this->assertNull(TransactionHistory::trace($entries)['modified']);
    }

    #[Test]
    public function a_corrected_payment_and_its_replacement_point_at_each_other(): void
    {
        $player = Player::create([
            'membership_id' => '8000000002', 'firstname' => 'Flow', 'lastname' => 'Test',
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
        $line = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => 2000, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        $this->actingAs($this->admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $line->id, 'category' => 'subscription', 'amount' => 800, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $original = Transaction::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('players.transactions.update', [$player, $original]), ['amount' => 500, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $replacement = Transaction::where('archived', false)->sole();

        $old = TransactionHistory::entries($original->fresh());
        $this->assertSame(['recorded', 'replaced'], $this->types($old));
        $this->assertSame([['id' => $replacement->id]], $old[1]['links']);
        $this->assertSame([$replacement->id], TransactionHistory::cancellation($original->fresh(), $old)['replaced_by']);

        $new = TransactionHistory::entries($replacement);
        $this->assertSame('correction', $new[0]['type']);
        $this->assertSame([['id' => $original->id]], $new[0]['links']);
        $amount = collect($new[0]['changes'])->firstWhere('field', 'amount');
        $this->assertEquals(800, $amount['old']);
        $this->assertEquals(500, $amount['new']);
    }

    private function player(string $membershipId = '8000000003', string $firstname = 'Flow', string $lastname = 'Test'): Player
    {
        return Player::create([
            'membership_id' => $membershipId, 'firstname' => $firstname, 'lastname' => $lastname,
            'is_student' => true, 'outstanding_debt' => 0,
        ]);
    }

    private function line(Player $player, float $owed): PlayerSubscription
    {
        $line = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => null, 'transaction_id' => null,
            'year' => (int) now()->year, 'status_at_time' => 'student',
            'is_mandatory' => true, 'amount_owed' => $owed, 'amount_paid' => 0,
        ]);
        app(RecalculatePlayerDebtService::class)->forPlayer($player->fresh());

        return $line;
    }

    /** A 500 payment on a 500 line corrected to 800: 500 on the line, 300 as a donation. */
    private function splitCorrection(Player $player, ?User $member = null): Transaction
    {
        $this->actingAs($this->admin)->post(route('players.transactions.store', $player), [
            'player_subscription_id' => $this->line($player, 500)->id, 'category' => 'subscription', 'amount' => 500, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $original = Transaction::latest('id')->firstOrFail();

        $request = $member
            ? $this->actingAs($member)->withSession([Impersonation::SESSION_KEY => $this->admin->id])
            : $this->actingAs($this->admin);
        $request->put(route('players.transactions.update', [$player, $original]), ['amount' => 800, 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::where('replaces_transaction_id', $original->id)->count());

        return $original;
    }

    #[Test]
    public function a_player_change_shows_both_players_by_name(): void
    {
        $karim = $this->player('8000000004', 'Karim', 'Bensalah');
        $sami = $this->player('8000000005', 'Sami', 'Haddad');
        $linked = ['related_entity_type' => 'Player', 'related_entity_id' => $karim->id];
        $this->actingAs($this->admin)->post(route('transactions.store'), $this->payload($linked))->assertSessionHasNoErrors();
        $tx = Transaction::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['related_entity_id' => $sami->id] + $linked))
            ->assertSessionHasNoErrors();

        $edited = collect(TransactionHistory::entries($tx->fresh()))->firstWhere('type', 'edited');

        $this->assertSame([['field' => 'related_entity_id', 'old' => $karim->fullname, 'new' => $sami->fullname]], $edited['changes']);
    }

    #[Test]
    public function a_grouped_sibling_recorded_while_logged_in_as_someone_names_the_real_person(): void
    {
        $member = User::factory()->admin()->create(['name' => 'Karim Desk']);
        $player = $this->player();
        $a = $this->line($player, 1000);
        $b = $this->line($player, 500);

        $this->actingAs($member)
            ->withSession([Impersonation::SESSION_KEY => $this->admin->id])
            ->post(route('players.transactions.store', $player), [
                'category' => 'subscription', 'payment_method' => 'cash', 'amount' => 1500,
                'payables' => [['player_subscription_id' => $a->id], ['player_subscription_id' => $b->id]],
            ])
            ->assertSessionHasNoErrors();

        $payments = Transaction::where('related_entity_id', $player->id)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertCount(1, $payments->pluck('payment_group')->unique()->filter());

        foreach ($payments as $payment) {
            $entry = TransactionHistory::entries($payment)[0];
            $this->assertSame('Cashier One', $entry['user'], "transaction #{$payment->id}");
            $this->assertSame('Karim Desk', $entry['as_user'], "transaction #{$payment->id}");
        }
    }

    #[Test]
    public function every_row_of_a_split_correction_made_logged_in_as_someone_names_the_real_person(): void
    {
        $member = User::factory()->admin()->create(['name' => 'Karim Desk']);
        $original = $this->splitCorrection($this->player(), $member);

        foreach (Transaction::where('replaces_transaction_id', $original->id)->orderBy('id')->get() as $row) {
            $entry = TransactionHistory::entries($row)[0];
            $this->assertSame('Cashier One', $entry['user'], "transaction #{$row->id}");
            $this->assertSame('Karim Desk', $entry['as_user'], "transaction #{$row->id}");
        }

        $replaced = collect(TransactionHistory::entries($original->fresh()))->firstWhere('type', 'replaced');
        $this->assertSame('Cashier One', $replaced['user']);
        $this->assertSame('Karim Desk', $replaced['as_user']);
    }

    #[Test]
    public function each_row_of_a_split_correction_links_to_the_original_without_changes(): void
    {
        $original = $this->splitCorrection($this->player());

        foreach (Transaction::where('replaces_transaction_id', $original->id)->orderBy('id')->get() as $row) {
            $first = TransactionHistory::entries($row)[0];
            $this->assertSame('correction', $first['type']);
            $this->assertSame([['id' => $original->id]], $first['links']);
            $this->assertSame([], $first['changes']);
        }
    }

    #[Test]
    public function an_edit_whose_log_row_is_gone_still_shows_its_values(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_UPDATED, $tx);
        TransactionRevision::create([
            'transaction_id' => $tx->id,
            'activity_log_id' => $log->id,
            'diff' => ['amount' => ['old' => 1000, 'new' => 1200]],
        ]);

        ActivityLog::whereKey($log->id)->delete();

        $entries = TransactionHistory::entries($tx);
        $this->assertNull(TransactionRevision::sole()->activity_log_id);
        $this->assertSame(['recorded', 'edited'], $this->types($entries));
        $this->assertNull($entries[1]['user']);
        $this->assertNull($entries[1]['as_user']);
        $this->assertEquals([['field' => 'amount', 'old' => 1000, 'new' => 1200]], $entries[1]['changes']);
    }

    #[Test]
    public function a_log_older_than_the_transaction_belongs_to_an_earlier_row_with_the_same_id(): void
    {
        $tx = $this->tx();
        $before = ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_CANCELLED, $tx);
        $after = ActivityRecorder::record($this->admin, ActivityAction::TRANSACTION_CANCELLED, $tx);
        ActivityLog::whereKey($before->id)->update(['occurred_at' => $tx->created_at->copy()->subDay()]);
        ActivityLog::whereKey($after->id)->update(['occurred_at' => $tx->created_at->copy()->addHour()]);

        $entries = TransactionHistory::entries($tx);

        $this->assertSame(['recorded', 'cancelled'], $this->types($entries));
        $this->assertSame($tx->created_at->copy()->addHour()->format('Y-m-d H:i'), $entries[1]['at']);
    }

    #[Test]
    public function every_printed_receipt_is_an_entry_with_its_code(): void
    {
        $tx = $this->tx();
        ReceiptPrint::create([
            'transaction_id' => $tx->id, 'transaction_number' => $tx->id, 'code' => '123456789012',
            'copy_number' => 1, 'format' => ReceiptPrint::FORMAT_THERMAL,
            'printed_by_user_id' => $this->admin->id, 'snapshot' => [],
        ]);

        $entries = TransactionHistory::entries($tx);

        $this->assertSame(['recorded', 'printed'], $this->types($entries));
        $this->assertSame(['copy_number' => 1, 'format' => 'thermal', 'code' => '1234 5678 9012'], $entries[1]['print']);
        $this->assertSame('Cashier One', $entries[1]['user']);
        // Printing is not a modification.
        $this->assertNull(TransactionHistory::trace($entries)['modified']);
    }

    #[Test]
    public function payments_taken_together_are_listed_with_their_total(): void
    {
        $group = (string) Str::uuid();
        $first = $this->tx(['amount' => 1000, 'transaction_type' => 'income', 'payment_group' => $group]);
        $second = $this->tx(['amount' => 600, 'transaction_type' => 'income', 'payment_group' => $group]);

        $result = TransactionHistory::group($first);

        $this->assertSame(1600.0, $result['total']);
        $this->assertSame([$first->id, $second->id], array_column($result['lines'], 'id'));
        $this->assertSame([true, false], array_column($result['lines'], 'current'));
        $this->assertSame('Hall rent', $result['lines'][0]['title']);

        $this->assertNull(TransactionHistory::group($this->tx()));
    }
}
