<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionDocumentFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function meeting(): BoardMeeting
    {
        return BoardMeeting::create([
            'title' => 'Bureau', 'type' => 'ordinary', 'meeting_date' => '2026-11-10 18:00:00', 'status' => 'held',
        ]);
    }

    private function payload(array $extra = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'expense', 'name' => 'Equipment'], ['is_active' => true]);
        $account = FinanceAccount::selectable()->first();

        return array_merge([
            'title' => 'Ballons', 'amount' => 16050, 'transaction_date' => '2026-11-20',
            'transaction_type' => 'expense', 'finance_category_id' => $category->id,
            'finance_account_id' => $account?->id, 'payment_method' => 'cheque',
            'payment_reference' => '1234567', 'status' => 'Paid',
        ], $extra);
    }

    #[Test]
    public function an_expense_keeps_its_supplier_invoice_cheque_and_meeting(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $meeting = $this->meeting();

        $this->actingAs($this->admin())->post(route('transactions.store'), $this->payload([
            'supplier_id' => $supplier->id, 'invoice_number' => 'FA-14/2026', 'invoice_date' => '2026-11-18',
            'cheque_date' => '2026-11-20', 'board_meeting_id' => $meeting->id,
        ]))->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->first();
        $this->assertSame($supplier->id, $transaction->supplier_id);
        $this->assertSame('FA-14/2026', $transaction->invoice_number);
        $this->assertSame('2026-11-18', $transaction->invoice_date->format('Y-m-d'));
        $this->assertSame('2026-11-20', $transaction->cheque_date->format('Y-m-d'));
        $this->assertSame($meeting->id, $transaction->board_meeting_id);
        $this->assertSame('cheque', $transaction->payment_method);
    }

    #[Test]
    public function a_used_supplier_cannot_be_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $this->actingAs($this->admin())->post(route('transactions.store'), $this->payload(['supplier_id' => $supplier->id]));

        $this->delete(route('suppliers.destroy', $supplier))->assertSessionHas('error', 'flash.supplier_in_use');
        $this->assertModelExists($supplier);
    }

    #[Test]
    public function the_form_offers_active_suppliers_and_meetings(): void
    {
        Supplier::create(['name' => 'Active One', 'rib' => '00100000000000001234']);
        Supplier::create(['name' => 'Gone', 'is_active' => false]);
        $this->meeting();

        $this->actingAs($this->admin())->get(route('transactions.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('suppliers', 1)
                ->where('suppliers.0.name', 'Active One')
                ->where('suppliers.0.rib', '00100000000000001234')
                ->has('boardMeetings', 1));
    }

    #[Test]
    public function editing_the_supplier_is_kept_in_the_history(): void
    {
        $a = Supplier::create(['name' => 'Shop A']);
        $b = Supplier::create(['name' => 'Shop B']);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('transactions.store'), $this->payload(['supplier_id' => $a->id]));
        $transaction = Transaction::latest('id')->first();

        $this->put(route('transactions.update', $transaction), $this->payload(['supplier_id' => $b->id]))->assertSessionHasNoErrors();

        $diff = $transaction->revisions()->latest('id')->first()->diff;
        $this->assertSame('Shop A', $diff['supplier_id']['old_label']);
        $this->assertSame('Shop B', $diff['supplier_id']['new_label']);
    }

    #[Test]
    public function an_entry_with_a_free_text_method_can_still_be_edited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('transactions.store'), $this->payload());
        $transaction = Transaction::latest('id')->first();
        $transaction->forceFill(['payment_method' => 'Virement'])->save();

        $this->put(route('transactions.update', $transaction), $this->payload(['payment_method' => 'Virement']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Virement', $transaction->fresh()->payment_method);
    }

    #[Test]
    public function a_new_entry_cannot_use_a_free_text_method(): void
    {
        $this->actingAs($this->admin())->post(route('transactions.store'), $this->payload(['payment_method' => 'Virement']))
            ->assertSessionHasErrors('payment_method');
    }

    #[Test]
    public function a_meeting_keeps_its_pv_number(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->admin())->put(route('board.meetings.update', $meeting), [
            'title' => 'Bureau', 'type' => 'ordinary', 'meeting_date' => '2026-11-10 18:00:00',
            'status' => 'held', 'pv_number' => '07/2026',
        ])->assertSessionHasNoErrors();

        $this->assertSame('07/2026', $meeting->fresh()->pv_number);
    }
}
