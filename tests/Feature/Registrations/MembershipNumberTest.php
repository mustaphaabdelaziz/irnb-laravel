<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\MembershipNumber;
use App\Support\BranchCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function numbers_run_per_branch_and_year(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);

        $this->assertSame('FB-2022-00001', MembershipNumber::forBranch($foot, 2022));
        Player::create(['firstname' => 'A', 'membership_id' => 'FB-2022-00017']);

        // The lowest free number, not the highest plus one.
        $this->assertSame('FB-2022-00001', MembershipNumber::forBranch($foot, 2022));
        $this->assertSame('FB-2023-00001', MembershipNumber::forBranch($foot, 2023));
        $this->assertSame('SW-2022-00001', MembershipNumber::forBranch($swim, 2022));
    }

    #[Test]
    public function it_tells_old_ids_from_new_ones(): void
    {
        $this->assertTrue(MembershipNumber::isLegacy('202200017'));
        $this->assertFalse(MembershipNumber::isLegacy('FB-2022-00017'));
        $this->assertFalse(MembershipNumber::isLegacy(null));
    }

    #[Test]
    public function a_new_branch_gets_a_free_code_and_the_first_one_becomes_main(): void
    {
        $foot = Branch::create(['name' => 'Football']);
        $fox = Branch::create(['name' => 'Fo', 'name_en' => 'Foxes']);
        $arabic = Branch::create(['name' => 'السباحة']);

        $this->assertSame('FO', $foot->code);
        $this->assertSame('FOX', $fox->code);
        $this->assertSame('BRA', $arabic->code);
        WebsiteConfig::singleton();
        $this->assertSame($foot->id, Branch::mainId());
    }

    #[Test]
    public function the_form_asks_for_a_code_when_no_name_has_latin_letters(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('branches.store'), ['name' => 'كرة اليد'])
            ->assertSessionHasErrors(['code' => __('validation.branch_code_needs_latin')]);
        $this->assertSame(0, Branch::count());

        // A French name gives the letters; a typed code needs none.
        $this->actingAs($admin)->post(route('branches.store'), ['name' => 'كرة اليد', 'name_fr' => 'Handball'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('branches.store'), ['name' => 'السباحة', 'code' => 'sw'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['HA', 'SW'], Branch::orderBy('id')->pluck('code')->all());
    }

    #[Test]
    public function a_code_locks_once_an_id_uses_it(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->actingAs($admin)->put(route('branches.update', $foot), ['name' => 'Football', 'code' => 'fc'])
            ->assertSessionHasNoErrors();
        $this->assertSame('FC', $foot->fresh()->code);

        $player = Player::create(['firstname' => 'A', 'membership_id' => 'FC-2022-00001']);
        $player->registrations()->create(['branch_id' => $foot->id, 'membership_id' => 'FC-2022-00001', 'category_id' => null]);
        $this->assertTrue(BranchCode::isLocked($foot->fresh()));

        $this->actingAs($admin)->put(route('branches.update', $foot), ['name' => 'Football', 'code' => 'XX'])
            ->assertSessionHasErrors('code');
    }
}
