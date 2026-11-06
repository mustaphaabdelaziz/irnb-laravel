<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BranchPageTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function the_rights_stay_on_categories(): void
    {
        $this->assertSame(['categories', 'view'], PermissionMap::resolve('categories.show'));
        $this->assertSame(['categories', 'edit'], PermissionMap::resolve('categories.toggle-active'));
        $this->assertSame(['branches', 'view'], PermissionMap::resolve('branches.show'));
    }

    #[Test]
    public function the_old_page_sends_people_to_the_main_branchs_tab(): void
    {
        $this->twoSportClub();

        $this->get(route('categories.index'))->assertRedirect(route('categories.show', $this->foot));
    }

    #[Test]
    public function with_no_branches_the_old_page_stays(): void
    {
        Category::create(['name' => 'Senior']);

        $this->get(route('categories.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Categories')->has('categories', 1));
    }

    #[Test]
    public function the_tab_lists_only_that_branchs_categories_with_their_counts(): void
    {
        $this->twoSportClub();
        $this->bothActive();

        $this->get(route('categories.show', $this->swim))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/BranchDetail')
                ->where('tab', 'categories')
                ->where('branch.id', $this->swim->id)
                ->has('categories', 1)
                ->where('categories.0.name', 'U15')
                ->where('categories.0.registrations_count', 1));
    }

    #[Test]
    public function the_general_tab_holds_the_branch(): void
    {
        $this->twoSportClub();

        $this->get(route('branches.show', $this->swim))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/BranchDetail')
                ->where('tab', 'general')
                ->where('branch.code', 'SW'));
    }

    #[Test]
    public function adding_goes_to_the_named_branch_and_a_name_is_unique_within_it(): void
    {
        $this->twoSportClub();

        $this->post(route('categories.store'), ['name' => 'Senior', 'branch_id' => $this->swim->id])->assertSessionHasNoErrors();
        $this->assertSame(1, Category::where('branch_id', $this->swim->id)->where('name', 'Senior')->count());

        $this->post(route('categories.store'), ['name' => 'Senior', 'branch_id' => $this->swim->id])->assertSessionHasErrors('name');
        $this->post(route('categories.store'), ['name' => 'Senior'])->assertSessionHasErrors('name');
    }

    #[Test]
    public function with_no_branches_a_name_stays_unique_across_the_club(): void
    {
        $this->post(route('categories.store'), ['name' => 'Senior'])->assertSessionHasNoErrors();
        $this->post(route('categories.store'), ['name' => 'Senior'])->assertSessionHasErrors('name');
        $this->assertNull(Category::first()->branch_id);
    }

    #[Test]
    public function adding_without_a_branch_uses_the_main_one(): void
    {
        $this->twoSportClub();

        $this->post(route('categories.store'), ['name' => 'Cadet'])->assertSessionHasNoErrors();

        $this->assertSame($this->foot->id, Category::where('name', 'Cadet')->value('branch_id'));
    }

    #[Test]
    public function a_category_cannot_move_to_another_branch(): void
    {
        $this->twoSportClub();

        $this->put(route('categories.update', $this->u15), ['name' => 'U15', 'branch_id' => $this->foot->id])->assertSessionHasNoErrors();

        $this->assertSame($this->swim->id, $this->u15->refresh()->branch_id);
    }

    #[Test]
    public function renaming_is_checked_within_the_categorys_own_branch(): void
    {
        $this->twoSportClub();
        Category::create(['name' => 'Cadet', 'branch_id' => $this->swim->id]);

        $this->put(route('categories.update', $this->u15), ['name' => 'Cadet'])->assertSessionHasErrors('name');
        $this->put(route('categories.update', $this->u15), ['name' => 'Senior'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function deactivating_keeps_it_stored_and_listed_on_the_tab(): void
    {
        $this->twoSportClub();
        $player = $this->bothActive();

        $this->post(route('categories.toggle-active', $this->u15))->assertSessionHas('success', 'flash.category_deactivated');

        $this->assertFalse($this->u15->refresh()->is_active);
        $this->assertSame($this->u15->id, $player->registrationIn($this->swim->id)->category_id);
        $this->get(route('categories.show', $this->swim))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categories.0.is_active', false));

        $this->post(route('categories.toggle-active', $this->u15))->assertSessionHas('success', 'flash.category_activated');
        $this->assertTrue($this->u15->refresh()->is_active);
    }

    #[Test]
    public function delete_keeps_todays_refusals(): void
    {
        $this->twoSportClub();
        $this->bothActive();

        $this->delete(route('categories.destroy', $this->u15))->assertSessionHas('error', 'flash.category_has_registrations');
        $this->assertTrue(Category::whereKey($this->u15->id)->exists());
    }

    #[Test]
    public function deleting_a_branch_takes_its_unused_categories_with_it(): void
    {
        $this->twoSportClub();
        $extra = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $unused = Category::create(['name' => 'Loisir', 'branch_id' => $extra->id]);

        $this->delete(route('branches.destroy', $extra))->assertSessionHas('success', 'flash.branch_deleted');

        $this->assertFalse(Branch::whereKey($extra->id)->exists());
        $this->assertFalse(Category::whereKey($unused->id)->exists());
    }

    #[Test]
    public function a_used_category_refuses_the_branch_delete(): void
    {
        $this->twoSportClub();
        $extra = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $used = Category::create(['name' => 'Loisir', 'branch_id' => $extra->id]);
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 1, 'amount_worker' => 1]);
        $subscription->categories()->attach($used->id);

        $this->delete(route('branches.destroy', $extra))->assertSessionHas('error', 'flash.branch_has_used_categories');

        $this->assertTrue(Branch::whereKey($extra->id)->exists());
        $this->assertTrue(Category::whereKey($used->id)->exists());
    }

    #[Test]
    public function a_flat_player_using_a_category_also_counts_as_use(): void
    {
        $this->twoSportClub();
        $extra = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $used = Category::create(['name' => 'Loisir', 'branch_id' => $extra->id]);
        Player::create(['firstname' => 'Flat', 'category_id' => $used->id, 'membership_id' => $this->fixtureId()]);

        $this->delete(route('branches.destroy', $extra))->assertSessionHas('error', 'flash.branch_has_used_categories');
    }

    #[Test]
    public function the_branches_list_links_to_the_page(): void
    {
        $this->twoSportClub();

        $this->get(route('branches.index'))->assertOk();
        $this->get(route('branches.show', $this->foot))->assertOk();
    }
}
