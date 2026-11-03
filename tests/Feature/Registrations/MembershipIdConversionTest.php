<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\MembershipIdConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipIdConversionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private MembershipIdConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->conversion = app(MembershipIdConversion::class);
    }

    /** @param list<array{0:Branch, 1:?string}> $links */
    private function member(string $id, int $year, array $links): Player
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => $id, 'join_year' => $year]);
        foreach ($links as [$branch, $registrationId]) {
            $player->registrations()->create([
                'branch_id' => $branch->id, 'membership_id' => $registrationId,
                'legacy_join_year' => $year, 'category_id' => null,
            ]);
        }

        return $player;
    }

    private function userWith(array $privileges): User
    {
        return User::factory()->create(['privileges' => $privileges, 'is_active' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_keeps_the_year_and_number(): void
    {
        $player = $this->member('202200017', 2022, [[$this->foot, '202200017']]);

        $this->conversion->convert(null);

        $row = $player->registrations()->firstOrFail();
        $this->assertSame('FB-2022-00017', $row->membership_id);
        $this->assertSame('202200017', $row->legacy_membership_id);
        $this->assertSame('FB-2022-00017', $player->fresh()->membership_id);
    }

    #[Test]
    public function an_id_whose_year_differs_follows_the_join_year_and_the_plan_lists_it(): void
    {
        $this->member('201800012', 2019, [[$this->foot, '201800012']]);

        $plan = $this->conversion->plan();

        $this->assertSame('FB-2019-00012', $plan['changes'][0]['to']);
        $this->assertTrue($plan['changes'][0]['year_changed']);
    }

    #[Test]
    public function a_second_registration_gets_a_fresh_number_and_matching_years_keep_theirs(): void
    {
        $double = $this->member('202200001', 2022, [[$this->foot, '202200001'], [$this->swim, null]]);
        $swimmer = $this->member('202200002', 2022, [[$this->swim, '202200002']]);

        $this->conversion->convert(null);

        $this->assertSame('SW-2022-00002', $swimmer->registrations()->first()->membership_id);
        $this->assertSame('FB-2022-00001', $double->registrations()->where('branch_id', $this->foot->id)->first()->membership_id);
        $this->assertSame('SW-2022-00003', $double->registrations()->where('branch_id', $this->swim->id)->first()->membership_id);
    }

    #[Test]
    public function a_taken_number_moves_to_the_next_free_one(): void
    {
        $this->member('201900005', 2019, [[$this->foot, '201900005']]);
        $moved = $this->member('201800005', 2019, [[$this->foot, '201800005']]);

        $this->conversion->convert(null);

        $this->assertSame('FB-2019-00006', $moved->registrations()->first()->membership_id);
    }

    #[Test]
    public function running_twice_changes_nothing_and_new_ids_are_left_alone(): void
    {
        $player = $this->member('202200017', 2022, [[$this->foot, '202200017']]);
        $new = $this->member('FB-2026-00001', 2026, [[$this->foot, 'FB-2026-00001']]);

        $this->assertSame(1, $this->conversion->convert(null));
        $this->assertSame(0, $this->conversion->convert(null));

        $this->assertSame('FB-2022-00017', $player->registrations()->first()->membership_id);
        $this->assertNull($new->registrations()->first()->legacy_membership_id);
    }

    #[Test]
    public function a_registration_with_no_year_is_skipped_and_reported(): void
    {
        $this->member('000000001', 0, []);
        $player = Player::firstOrFail();
        $player->registrations()->create(['branch_id' => $this->foot->id, 'membership_id' => '000000001', 'legacy_join_year' => null, 'category_id' => null]);

        $plan = $this->conversion->plan();

        $this->assertSame([], $plan['changes']);
        $this->assertCount(1, $plan['skipped']);
    }

    #[Test]
    public function undo_restores_old_ids_and_the_player_copy(): void
    {
        $player = $this->member('202200017', 2022, [[$this->foot, '202200017']]);
        $this->conversion->convert(null);

        $this->assertSame(1, $this->conversion->undo(null));

        $this->assertSame('202200017', $player->registrations()->first()->membership_id);
        $this->assertNull($player->registrations()->first()->legacy_membership_id);
        $this->assertSame('202200017', $player->fresh()->membership_id);
    }

    #[Test]
    public function only_a_superadmin_reaches_it(): void
    {
        $admin = $this->userWith(['admin']);

        $this->actingAs($admin)->get(route('membership-ids.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('membership-ids.convert'))->assertForbidden();
        $this->actingAs($admin)->post(route('membership-ids.undo'))->assertForbidden();
        $this->assertSame(0, BranchRegistration::count());
    }

    #[Test]
    public function a_superadmin_sees_the_preview_and_can_convert_and_undo(): void
    {
        $this->member('202200017', 2022, [[$this->foot, '202200017']]);
        $superadmin = $this->userWith(['superadmin']);

        $this->actingAs($superadmin)->get(route('membership-ids.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/MembershipIds')
                ->where('count', 1)->where('canUndo', false));

        $this->actingAs($superadmin)->post(route('membership-ids.convert'))->assertRedirect();
        $this->assertSame('FB-2022-00017', BranchRegistration::firstOrFail()->membership_id);

        $this->actingAs($superadmin)->post(route('membership-ids.undo'))->assertRedirect();
        $this->assertSame('202200017', BranchRegistration::firstOrFail()->membership_id);
    }

    #[Test]
    public function the_branches_page_links_to_conversion_for_a_superadmin_only(): void
    {
        $this->actingAs($this->userWith(['admin']))->get(route('branches.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canConvertIds', false));

        $this->actingAs($this->userWith(['superadmin']))->get(route('branches.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canConvertIds', true));
    }
}
