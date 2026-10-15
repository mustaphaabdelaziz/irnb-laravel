<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Support\LegacyFinanceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payment writers send only a slug. A legacy twin ("Subscription", created by
 * the old observer, no code) must keep receiving its payments after the club
 * renames it in the manager — so the twin is given the seeded code.
 */
class LegacyFinanceCategoryCodesTest extends TestCase
{
    use RefreshDatabase;

    /** The live state: no category holds a code; the old observer's twins exist. */
    private function liveData(): array
    {
        FinanceCategory::query()->update(['code' => null]);
        // The seeded "Equipment" shares the twin's name; live data has only the twin.
        FinanceCategory::where('type', 'expense')->where('name', 'Equipment')->delete();

        $twin = fn (string $type, string $name) => FinanceCategory::create([
            'type' => $type, 'name' => $name, 'is_active' => true, 'is_system' => false,
        ]);

        return [
            'subscription' => $twin('income', 'Subscription'),
            'donation' => $twin('income', 'Donation'),
            'debt_payment' => $twin('income', 'Debt Payment'),
            'equipment' => $twin('expense', 'Equipment'),
        ];
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_15_000001_give_legacy_finance_categories_their_codes.php'))->up();
    }

    private function pay(string $type, string $slug): Transaction
    {
        return Transaction::create([
            'amount' => 100, 'transaction_type' => $type, 'category' => $slug,
            'status' => 'Paid', 'payment_method' => 'cash', 'transaction_date' => now(),
        ]);
    }

    #[Test]
    public function the_migration_gives_each_legacy_twin_its_seeded_code(): void
    {
        $twins = $this->liveData();

        $this->migrate();

        $this->assertSame('SUB', $twins['subscription']->fresh()->code);
        $this->assertSame('DON', $twins['donation']->fresh()->code);
        $this->assertSame('ARR', $twins['debt_payment']->fresh()->code);
        $this->assertSame('EQP', $twins['equipment']->fresh()->code);
        foreach ($twins as $twin) {
            $this->assertFalse($twin->fresh()->is_system);
        }
        $this->assertSame(1, FinanceCategory::where('code', 'SUB')->count());
    }

    #[Test]
    public function the_migration_is_idempotent(): void
    {
        $this->liveData();
        $this->migrate();
        $after = FinanceCategory::orderBy('id')->pluck('code', 'id')->all();

        $this->migrate();

        $this->assertSame($after, FinanceCategory::orderBy('id')->pluck('code', 'id')->all());
    }

    #[Test]
    public function the_migration_never_overwrites_an_existing_code(): void
    {
        // Seeded SUB still holds its code: the twin gets nothing.
        $sub = FinanceCategory::where('code', 'SUB')->first();
        $twin = FinanceCategory::create(['type' => 'income', 'name' => 'Subscription', 'is_active' => true]);
        // A twin that already carries a code of its own keeps it.
        FinanceCategory::where('code', 'DON')->update(['code' => null]);
        $donation = FinanceCategory::create(['type' => 'income', 'name' => 'Donation', 'code' => 'GIFT', 'is_active' => true]);

        $this->migrate();

        $this->assertSame('SUB', $sub->fresh()->code);
        $this->assertNull($twin->fresh()->code);
        $this->assertSame('GIFT', $donation->fresh()->code);
    }

    #[Test]
    public function a_renamed_twin_still_receives_subscription_payments(): void
    {
        $twins = $this->liveData();
        $this->migrate();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('finance.categories.update', $twins['subscription']), [
                'type' => 'income', 'name_ar' => 'اشتراكات', 'name_fr' => 'Cotisations', 'name_en' => 'Membership dues',
            ])->assertSessionHasNoErrors();
        $this->assertSame('Membership dues', $twins['subscription']->fresh()->name);
        $before = FinanceCategory::count();

        $this->assertSame($twins['subscription']->id, $this->pay('income', 'subscription')->finance_category_id);
        $this->assertSame($before, FinanceCategory::count());
    }

    #[Test]
    public function a_club_that_never_ran_the_migration_converges_on_first_payment(): void
    {
        $twins = $this->liveData();

        $this->assertSame($twins['subscription']->id, LegacyFinanceCategory::resolve('income', 'subscription')->id);
        $this->assertSame('SUB', $twins['subscription']->fresh()->code);

        $twins['subscription']->update(['name' => 'Membership dues']);
        $before = FinanceCategory::count();

        $this->assertSame($twins['subscription']->id, $this->pay('income', 'subscription')->finance_category_id);
        $this->assertSame($before, FinanceCategory::count());
    }

    #[Test]
    public function self_healing_never_overwrites_a_twins_own_code(): void
    {
        FinanceCategory::where('code', 'EQP')->delete();
        $twin = FinanceCategory::create(['type' => 'expense', 'name' => 'Equipment', 'code' => 'GEAR', 'is_active' => true]);

        $this->assertSame($twin->id, LegacyFinanceCategory::resolve('expense', 'equipment')->id);
        $this->assertSame('GEAR', $twin->fresh()->code);
    }
}
