<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\FinanceTransfer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaisseRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->branch = Branch::create(['name' => 'Football']);
    }

    private function caisse(array $extra = []): FinanceAccount
    {
        return FinanceAccount::create([
            'branch_id' => $this->branch->id, 'parent_account_id' => $this->branch->treasury->id,
            'name' => 'Caisse', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ] + $extra);
    }

    #[Test]
    public function a_caisse_can_be_created_under_a_coffre_and_linked_to_a_category(): void
    {
        $category = Category::create(['name' => 'Cadet']);

        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'Caisse cadets', 'type' => 'cash', 'branch_id' => $this->branch->id,
            'category_id' => $category->id, 'opening_balance' => 0,
        ])->assertSessionHas('success', 'flash.account_added');

        $caisse = FinanceAccount::where('name', 'Caisse cadets')->sole();
        $this->assertSame($this->branch->treasury->id, $caisse->parent_account_id);
        $this->assertSame($category->id, $caisse->category_id);
        $this->assertFalse($caisse->is_treasury);
    }

    #[Test]
    public function a_category_has_at_most_one_caisse_per_branch(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $this->caisse(['category_id' => $category->id]);

        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'Second', 'type' => 'cash', 'branch_id' => $this->branch->id, 'category_id' => $category->id,
        ])->assertSessionHasErrors(['category_id' => __('This category already has a caisse in this branch.')]);
    }

    #[Test]
    public function a_club_level_account_cannot_be_linked_to_a_category(): void
    {
        $category = Category::create(['name' => 'Cadet']);

        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'Bank', 'type' => 'bank', 'category_id' => $category->id,
        ])->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function updating_without_a_category_keeps_the_existing_link(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $caisse = $this->caisse(['category_id' => $category->id]);

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Renamed', 'type' => 'cash', 'branch_id' => $this->branch->id,
        ])->assertSessionHas('success', 'flash.account_updated');

        $this->assertSame($category->id, $caisse->fresh()->category_id);
        $this->assertSame('Renamed', $caisse->fresh()->name);
    }

    #[Test]
    public function resaving_the_same_category_link_is_not_flagged_as_taken(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $caisse = $this->caisse(['category_id' => $category->id]);

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Caisse', 'type' => 'cash', 'branch_id' => $this->branch->id, 'category_id' => $category->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.account_updated');

        $this->assertSame($category->id, $caisse->fresh()->category_id);
    }

    #[Test]
    public function moving_a_linked_caisse_to_club_level_clears_the_link(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $caisse = $this->caisse(['category_id' => $category->id]);

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Caisse', 'type' => 'cash', 'branch_id' => null,
        ])->assertSessionHas('success', 'flash.account_updated');

        $caisse->refresh();
        $this->assertNull($caisse->branch_id);
        $this->assertNull($caisse->parent_account_id);
        $this->assertNull($caisse->category_id);
    }

    #[Test]
    public function moving_a_linked_caisse_into_a_branch_that_already_has_that_category_is_refused(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $caisse = $this->caisse(['category_id' => $category->id]);
        $other = Branch::create(['name' => 'Handball']);
        FinanceAccount::create([
            'branch_id' => $other->id, 'parent_account_id' => $other->treasury->id, 'category_id' => $category->id,
            'name' => 'Caisse HB', 'type' => 'cash', 'is_active' => true, 'currency' => 'DZD',
        ]);

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Caisse', 'type' => 'cash', 'branch_id' => $other->id,
        ])->assertSessionHasErrors(['category_id' => __('This category already has a caisse in this branch.')]);

        $this->assertSame($this->branch->id, $caisse->fresh()->branch_id);
    }

    #[Test]
    public function updating_without_a_branch_key_leaves_branch_parent_and_category_unchanged(): void
    {
        $category = Category::create(['name' => 'Cadet']);
        $caisse = $this->caisse(['category_id' => $category->id]);

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Renamed', 'type' => 'cash',
        ])->assertSessionHas('success', 'flash.account_updated');

        $caisse->refresh();
        $this->assertSame($this->branch->id, $caisse->branch_id);
        $this->assertSame($this->branch->treasury->id, $caisse->parent_account_id);
        $this->assertSame($category->id, $caisse->category_id);
    }

    #[Test]
    public function an_unused_caisse_can_be_deleted(): void
    {
        $caisse = $this->caisse();

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $caisse))
            ->assertSessionHas('success', 'flash.account_deleted');
        $this->assertModelMissing($caisse);
    }

    #[Test]
    public function a_caisse_that_still_holds_money_cannot_be_deleted(): void
    {
        $caisse = $this->caisse(['opening_balance' => 500]);

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $caisse))
            ->assertSessionHas('error', 'flash.caisse_has_money');
        $this->assertModelExists($caisse);
    }

    #[Test]
    public function a_used_caisse_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $caisse = $this->caisse();
        Transaction::create(['amount' => 100, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid', 'finance_account_id' => $caisse->id]);

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $caisse))
            ->assertSessionHas('error', 'flash.caisse_used_deactivate');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $caisse), [
            'name' => 'Caisse', 'type' => 'cash', 'branch_id' => $this->branch->id, 'is_active' => false,
        ])->assertSessionHas('success', 'flash.account_updated');
        $this->assertFalse($caisse->fresh()->is_active);
    }

    #[Test]
    public function a_coffre_with_caisses_cannot_be_deleted_and_never_deactivates(): void
    {
        $coffre = $this->branch->treasury;
        $this->caisse();

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $coffre))
            ->assertSessionHas('error', 'flash.coffre_has_caisses');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $coffre), [
            'name' => 'Safe', 'type' => 'cash', 'is_active' => false, 'opening_balance' => 500,
        ]);
        $coffre->refresh();
        $this->assertTrue($coffre->is_active);
        $this->assertSame($this->branch->id, $coffre->branch_id);
        $this->assertEquals(500, (float) $coffre->opening_balance);
    }

    #[Test]
    public function a_used_coffre_cannot_be_deleted(): void
    {
        $coffre = $this->branch->treasury;
        $coffre->update(['opening_balance' => 100]);
        $bank = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'is_active' => true, 'currency' => 'DZD']);
        FinanceTransfer::create(['from_account_id' => $coffre->id, 'to_account_id' => $bank->id, 'amount' => 10, 'transfer_date' => '2026-10-01']);

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $coffre))
            ->assertSessionHas('error', 'flash.coffre_cannot_be_deleted');
    }

    #[Test]
    public function the_opening_fund_cannot_be_deleted_or_deactivated(): void
    {
        $fund = FinanceAccount::where('is_opening_fund', true)->first()
            ?? FinanceAccount::create(['name' => 'Opening Fund', 'type' => 'cash', 'is_opening_fund' => true, 'is_active' => true, 'currency' => 'DZD']);

        $this->actingAs($this->admin)->delete(route('finance.accounts.destroy', $fund))
            ->assertSessionHas('error', 'flash.opening_fund_required');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $fund), ['name' => 'Opening Fund', 'type' => 'cash', 'is_active' => false]);
        $this->assertTrue($fund->fresh()->is_active);
    }
}
