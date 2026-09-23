<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceCategoryTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_18_000001_add_locale_names_to_finance_categories.php';

    private function admin(string $locale = 'en'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function runBackfill(): void
    {
        (require database_path(self::MIGRATION))->backfill();
    }

    #[Test]
    public function localized_name_follows_the_app_locale_and_falls_back_to_the_base_name(): void
    {
        $cat = FinanceCategory::create([
            'type' => 'expense', 'name' => 'Rent',
            'name_ar' => 'كراء', 'name_fr' => 'Loyer', 'name_en' => null,
            'is_active' => true,
        ]);

        app()->setLocale('ar');
        $this->assertSame('كراء', $cat->localized_name);

        app()->setLocale('fr');
        $this->assertSame('Loyer', $cat->localized_name);

        app()->setLocale('en');
        $this->assertSame('Rent', $cat->localized_name);
    }

    #[Test]
    public function migration_translates_the_built_in_categories(): void
    {
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();

        $this->assertSame('تبرعات', $donations->name_ar);
        $this->assertSame('Dons', $donations->name_fr);
        // English comes from the base name through the fallback.
        $this->assertNull($donations->name_en);
    }

    #[Test]
    public function backfill_translates_auto_created_names(): void
    {
        $id = DB::table('finance_categories')->insertGetId([
            'type' => 'income', 'name' => 'Donation', 'is_active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $row = DB::table('finance_categories')->find($id);
        $this->assertSame('Don', $row->name_fr);
        $this->assertSame('تبرع', $row->name_ar);
        $this->assertNull($row->name_en);
    }

    #[Test]
    public function backfill_never_overwrites_a_translation_the_user_entered(): void
    {
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();
        $donations->update(['name_fr' => 'Dons et mécénat']);

        $this->runBackfill();

        $this->assertSame('Dons et mécénat', $donations->fresh()->name_fr);
    }

    #[Test]
    public function store_and_update_persist_the_translations(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('finance.categories.store'), [
                'type' => 'expense', 'name' => 'Rent',
                'name_ar' => 'كراء', 'name_fr' => 'Loyer', 'name_en' => 'Rent',
            ])
            ->assertSessionHasNoErrors();

        $cat = FinanceCategory::where('type', 'expense')->where('name', 'Rent')->firstOrFail();
        $this->assertSame('كراء', $cat->name_ar);
        $this->assertSame('Loyer', $cat->name_fr);

        $this->actingAs($admin)
            ->put(route('finance.categories.update', $cat), [
                'type' => 'expense', 'name' => 'Rent', 'name_fr' => 'Location',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Location', $cat->fresh()->name_fr);
        $this->assertSame('كراء', $cat->fresh()->name_ar);
    }

    private function donationTransaction(): Transaction
    {
        FiscalYear::firstOrCreate(['year' => now()->year], [
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
            'opening_balance' => 0,
        ]);
        $donations = FinanceCategory::where('code', 'DON')->firstOrFail();

        return Transaction::create([
            'amount' => 900, 'transaction_type' => 'income', 'category' => 'donations',
            'finance_category_id' => $donations->id, 'status' => 'Paid',
            'transaction_date' => now(), 'fiscal_year' => now()->year,
        ]);
    }

    #[Test]
    public function transactions_index_labels_categories_in_the_user_language(): void
    {
        $this->donationTransaction();

        $this->actingAs($this->admin('fr'))
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financeCategories', fn ($cats) => collect($cats)->firstWhere('name', 'Donations')['localized_name'] === 'Dons')
                ->where('transactions.data.0.finance_category.localized_name', 'Dons'));
    }

    #[Test]
    public function transaction_form_options_are_localized(): void
    {
        $this->actingAs($this->admin('ar'))
            ->get(route('transactions.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financeCategories', fn ($cats) => collect($cats)->firstWhere('name', 'Donations')['localized_name'] === 'تبرعات'));
    }

    #[Test]
    public function transaction_show_exposes_the_localized_category(): void
    {
        $tx = $this->donationTransaction();

        $this->actingAs($this->admin('fr'))
            ->get(route('transactions.show', $tx))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('transaction.finance_category.localized_name', 'Dons'));
    }

    #[Test]
    public function finance_dashboard_breakdown_and_budget_use_the_user_language(): void
    {
        $this->donationTransaction();

        $this->actingAs($this->admin('ar'))
            ->get(route('finance.index', ['year' => now()->year]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.income_by_category.0.name', 'تبرعات')
                ->where('detail.budget.0.name', 'تبرعات'));
    }

    #[Test]
    public function backfill_leaves_renamed_built_in_categories_alone(): void
    {
        DB::table('finance_categories')->where('code', 'OIN')->update([
            'name' => 'Cafeteria sales', 'name_ar' => null, 'name_fr' => null, 'name_en' => null,
        ]);

        $this->runBackfill();

        $row = DB::table('finance_categories')->where('code', 'OIN')->first();
        $this->assertNull($row->name_ar);
        $this->assertNull($row->name_fr);
        $this->assertNull($row->name_en);

        app()->setLocale('ar');
        $this->assertSame('Cafeteria sales', FinanceCategory::where('code', 'OIN')->first()->localized_name);
    }

    #[Test]
    public function categories_auto_created_by_a_transaction_get_stock_translations(): void
    {
        Transaction::create([
            'amount' => 300, 'transaction_type' => 'income', 'category' => 'debt_payment',
            'status' => 'Paid', 'transaction_date' => now(), 'fiscal_year' => now()->year,
        ]);

        $cat = FinanceCategory::where('type', 'income')->where('name', 'Debt Payment')->firstOrFail();
        $this->assertSame('Paiement de dette', $cat->name_fr);
        $this->assertSame('تسديد دين', $cat->name_ar);
        $this->assertNull($cat->name_en);
    }

    #[Test]
    public function creation_keeps_translations_already_provided(): void
    {
        $cat = FinanceCategory::create([
            'type' => 'income', 'name' => 'Donation', 'name_fr' => 'Dons et mécénat', 'is_active' => true,
        ]);

        $this->assertSame('Dons et mécénat', $cat->name_fr);
        $this->assertSame('تبرع', $cat->name_ar);
    }

    #[Test]
    public function unknown_names_get_no_stock_translation(): void
    {
        $cat = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->assertNull($cat->name_ar);
        $this->assertNull($cat->name_fr);
        $this->assertNull($cat->name_en);
    }
}
