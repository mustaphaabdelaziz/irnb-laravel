<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Support\LegacyFinanceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceCategoryRulesTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::factory()->admin()->create();
    }

    private function useCategory(FinanceCategory $category, int $times = 1): void
    {
        foreach (range(1, $times) as $i) {
            Transaction::create([
                'amount' => 10 * $i, 'transaction_type' => $category->type, 'category' => 'x',
                'finance_category_id' => $category->id, 'status' => 'Paid', 'transaction_date' => now(),
            ]);
        }
    }

    #[Test]
    public function a_built_in_category_cannot_be_deleted_but_can_be_renamed(): void
    {
        $equipment = FinanceCategory::where('code', 'EQP')->firstOrFail();

        $this->actingAs($this->admin())->delete(route('finance.categories.destroy', $equipment))
            ->assertSessionHas('error', 'flash.category_is_system');
        $this->assertModelExists($equipment);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $equipment), [
            'type' => 'income', 'name_ar' => 'عتاد', 'name_fr' => 'Matériel', 'name_en' => 'Gear', 'color' => '#000000', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $equipment->refresh();
        $this->assertSame('Equipment', $equipment->name);   // base name kept: the app finds it by it
        $this->assertSame('expense', $equipment->type);     // and its side of the books
        $this->assertSame('Gear', $equipment->name_en);
        $this->assertSame('عتاد', $equipment->name_ar);
        $this->assertSame('#000000', $equipment->color);
    }

    #[Test]
    public function a_used_category_cannot_be_deleted_and_the_message_counts_its_transactions(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);
        $this->useCategory($rent, 2);

        $this->actingAs($this->admin())->delete(route('finance.categories.destroy', $rent))
            ->assertSessionHas('error', ['key' => 'flash.category_has_transactions', 'params' => ['count' => 2]]);
        $this->assertModelExists($rent);
    }

    #[Test]
    public function an_unused_category_is_deleted(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);

        $this->actingAs($this->admin())->delete(route('finance.categories.destroy', $rent))
            ->assertSessionHas('success', 'flash.category_deleted');
        $this->assertModelMissing($rent);
    }

    #[Test]
    public function a_category_typed_in_arabic_only_stays_arabic_in_every_language(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), [
            'type' => 'expense', 'name_ar' => 'مصاريف أشغال المقر', 'color' => '#ef4444',
        ])->assertSessionHasNoErrors();

        $category = FinanceCategory::where('name_ar', 'مصاريف أشغال المقر')->sole();
        $this->assertSame('مصاريف أشغال المقر', $category->name);
        app()->setLocale('fr');
        $this->assertSame('مصاريف أشغال المقر', $category->localized_name);
    }

    #[Test]
    public function the_base_name_prefers_english_then_french(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), [
            'type' => 'expense', 'name_ar' => 'كراء', 'name_fr' => 'Loyer',
        ])->assertSessionHasNoErrors();
        $category = FinanceCategory::where('name_ar', 'كراء')->sole();
        $this->assertSame('Loyer', $category->name);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $category), [
            'type' => 'expense', 'name_ar' => 'كراء', 'name_fr' => 'Loyer', 'name_en' => 'Rent',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Rent', $category->fresh()->name);
    }

    #[Test]
    public function a_name_is_needed_in_at_least_one_language(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), ['type' => 'income', 'color' => '#000000'])
            ->assertSessionHasErrors('name_ar');
    }

    #[Test]
    public function the_missing_name_error_names_the_fields_like_the_form(): void
    {
        $expected = [
            'ar' => ['الاسم بالعربية', 'الاسم بالفرنسية', 'الاسم بالإنجليزية'],
            'fr' => ['Nom en arabe', 'Nom en français', 'Nom en anglais'],
            'en' => ['Name in Arabic', 'Name in French', 'Name in English'],
        ];

        foreach ($expected as $lng => $labels) {
            $user = User::factory()->admin()->create(['preferred_lng' => $lng]);
            $response = $this->actingAs($user)->post(route('finance.categories.store'), ['type' => 'income']);

            foreach ($labels as $label) {
                $response->assertInvalid(['name_ar' => $label]);
            }
        }
    }

    #[Test]
    public function two_categories_of_one_type_cannot_share_a_name(): void
    {
        $this->actingAs($this->admin())->post(route('finance.categories.store'), ['type' => 'expense', 'name_en' => 'Rent'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post(route('finance.categories.store'), ['type' => 'expense', 'name_en' => 'Rent'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_used_category_keeps_its_type(): void
    {
        $rent = FinanceCategory::create(['type' => 'expense', 'name' => 'Rent', 'is_active' => true]);
        $this->useCategory($rent);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $rent), ['type' => 'income', 'name_en' => 'Rent'])
            ->assertSessionHasNoErrors();
        $this->assertSame('expense', $rent->fresh()->type);
    }

    #[Test]
    public function a_built_in_category_keeps_its_code(): void
    {
        $equipment = FinanceCategory::where('code', 'EQP')->firstOrFail();

        $this->actingAs($this->admin())->put(route('finance.categories.update', $equipment), [
            'type' => 'expense', 'name_en' => 'Gear', 'code' => 'XYZ',
        ])->assertSessionHasNoErrors();

        $this->assertSame('EQP', $equipment->fresh()->code);   // the app finds it by code (LegacyFinanceCategory)
    }

    /** The live legacy twin: base "Subscription", fr/ar filled by finance:backfill, en empty. */
    private function legacyTwin(): FinanceCategory
    {
        // A club category already holding the French name: a base rewritten to it collides.
        FinanceCategory::create(['type' => 'income', 'name' => 'Abonnement', 'is_active' => true]);

        $twin = FinanceCategory::create([
            'type' => 'income', 'name' => 'Subscription', 'name_fr' => 'Abonnement', 'name_ar' => 'اشتراك',
            'color' => '#10b981', 'is_active' => true, 'is_system' => false,
        ]);
        $this->useCategory($twin, 2);

        return $twin;
    }

    #[Test]
    public function saving_the_legacy_twin_from_the_manager_keeps_its_base_name(): void
    {
        $twin = $this->legacyTwin();

        // A colour-only change with the names exactly as stored (English empty):
        // an older client, or one that could not place the base.
        $this->actingAs($this->admin())->put(route('finance.categories.update', $twin), [
            'type' => 'income', 'name_ar' => 'اشتراك', 'name_fr' => 'Abonnement', 'name_en' => '',
            'color' => '#123456', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $twin->refresh();
        $this->assertSame('Subscription', $twin->name);
        $this->assertSame('#123456', $twin->color);

        // What the manager sends: the base lands in the empty English field.
        $this->actingAs($this->admin())->put(route('finance.categories.update', $twin), [
            'type' => 'income', 'name_ar' => 'اشتراك', 'name_fr' => 'Abonnement', 'name_en' => 'Subscription',
            'color' => '#10b981', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Subscription', $twin->fresh()->name);

        // A club that deleted the seeded "Subscriptions" still books old slugs on the twin.
        FinanceCategory::where('code', 'SUB')->delete();
        $this->assertSame($twin->id, LegacyFinanceCategory::resolve('income', 'subscription')->id);
    }

    #[Test]
    public function saving_an_arabic_base_with_another_arabic_name_keeps_the_base(): void
    {
        $category = FinanceCategory::create([
            'type' => 'expense', 'name' => 'مصاريف التنقل', 'name_ar' => 'التنقل', 'name_fr' => 'Déplacements',
            'is_active' => true, 'is_system' => false,
        ]);

        $this->actingAs($this->admin())->put(route('finance.categories.update', $category), [
            'type' => 'expense', 'name_ar' => 'التنقل', 'name_fr' => 'Déplacements', 'name_en' => '',
            'color' => '#000000', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('مصاريف التنقل', $category->fresh()->name);
    }

    #[Test]
    public function renaming_a_language_still_moves_the_base_name(): void
    {
        $twin = $this->legacyTwin();

        $this->actingAs($this->admin())->put(route('finance.categories.update', $twin), [
            'type' => 'income', 'name_ar' => 'اشتراك', 'name_fr' => 'Abonnement', 'name_en' => 'Membership dues',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Membership dues', $twin->fresh()->name);
    }
}
