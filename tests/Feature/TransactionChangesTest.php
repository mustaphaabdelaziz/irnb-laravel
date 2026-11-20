<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Services\Finance\TransactionChanges;
use App\Support\FinanceAccountLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionChangesTest extends TestCase
{
    use RefreshDatabase;

    private function tx(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'title' => 'Hall rent', 'amount' => 1000, 'transaction_date' => '2026-11-10',
            'transaction_type' => 'expense', 'category' => 'rent', 'payment_method' => 'cash', 'status' => 'Paid',
        ], $overrides));
    }

    /** @return array<string, array<string, mixed>> */
    private function changesAfter(Transaction $tx, array $update): array
    {
        $before = TransactionChanges::snapshot($tx);
        $tx->update($update);

        return TransactionChanges::between($before, TransactionChanges::snapshot($tx));
    }

    #[Test]
    public function only_the_changed_fields_are_reported(): void
    {
        $changes = $this->changesAfter($this->tx(), ['amount' => '1500.00', 'title' => 'Hall rent']);

        $this->assertSame(['amount' => ['old' => 1000.0, 'new' => 1500.0]], $changes);
    }

    #[Test]
    public function the_same_amount_written_differently_is_no_change(): void
    {
        $this->assertSame([], $this->changesAfter($this->tx(), ['amount' => '1000']));
    }

    #[Test]
    public function an_empty_text_and_no_text_are_the_same(): void
    {
        $this->assertSame([], $this->changesAfter($this->tx(['description' => null]), ['description' => '']));
    }

    #[Test]
    public function the_date_is_compared_by_day(): void
    {
        $tx = $this->tx();

        $this->assertSame([], $this->changesAfter($tx, ['transaction_date' => '2026-11-10 15:30:00']));
        $this->assertSame(
            ['transaction_date' => ['old' => '2026-11-10', 'new' => '2026-11-12']],
            $this->changesAfter($tx, ['transaction_date' => '2026-11-12']),
        );
    }

    #[Test]
    public function text_changes_keep_the_old_and_new_wording(): void
    {
        $changes = $this->changesAfter($this->tx(), ['title' => 'Hall rent — March', 'description' => 'Paid late']);

        $this->assertSame([
            'title' => ['old' => 'Hall rent', 'new' => 'Hall rent — March'],
            'description' => ['old' => null, 'new' => 'Paid late'],
        ], $changes);
    }

    #[Test]
    public function category_and_register_changes_carry_their_names(): void
    {
        // Names no migration seeds: finance_categories is unique on (type, name).
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Trace Rent', 'is_active' => true]);
        $bills = FinanceCategory::create(['type' => 'expense', 'name' => 'Trace Bills', 'is_active' => true]);
        $caisseA = FinanceAccount::create(['name' => 'Caisse A', 'type' => 'cash']);
        $caisseB = FinanceAccount::create(['name' => 'Caisse B', 'type' => 'cash']);
        $tx = $this->tx(['finance_category_id' => $rent->id, 'finance_account_id' => $caisseA->id]);

        $changes = TransactionChanges::withLabels($this->changesAfter($tx, [
            'finance_category_id' => $bills->id,
            'finance_account_id' => $caisseB->id,
        ]));

        $this->assertSame([
            'old' => $rent->id, 'new' => $bills->id,
            'old_label' => $rent->fresh()->localized_name, 'new_label' => $bills->fresh()->localized_name,
        ], $changes['finance_category_id']);
        $this->assertSame([
            'old' => $caisseA->id, 'new' => $caisseB->id,
            'old_label' => FinanceAccountLabel::for($caisseA), 'new_label' => FinanceAccountLabel::for($caisseB),
        ], $changes['finance_account_id']);
    }

    #[Test]
    public function labels_are_added_only_where_a_category_or_register_changed(): void
    {
        $changes = ['amount' => ['old' => 1.0, 'new' => 2.0]];

        $this->assertSame($changes, TransactionChanges::withLabels($changes));
    }
}
