<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Services\Player\MembershipNumber;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipNumberReuseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private RegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->service = app(RegistrationService::class);
    }

    private function member(string $id, ?Branch $branch = null, ?int $year = 2022): Player
    {
        $player = Player::create(['firstname' => 'A', 'membership_id' => $id]);
        $player->registrations()->create([
            'branch_id' => ($branch ?? $this->foot)->id,
            'membership_id' => $id,
            'category_id' => null,
            'legacy_join_year' => $year,
        ]);

        return $player;
    }

    #[Test]
    public function the_lowest_free_number_is_issued(): void
    {
        $this->member('FB-2022-00001');
        $this->member('FB-2022-00002');
        $this->member('FB-2022-00004');

        $this->assertSame('FB-2022-00003', MembershipNumber::forBranch($this->foot, 2022));
    }

    #[Test]
    public function with_no_gap_the_next_number_follows_the_highest(): void
    {
        $this->member('FB-2022-00001');
        $this->member('FB-2022-00002');

        $this->assertSame('FB-2022-00003', MembershipNumber::forBranch($this->foot, 2022));
    }

    #[Test]
    public function a_number_held_only_by_the_players_copy_is_not_free(): void
    {
        // A player with no registrations keeps their own ID, and players.membership_id is unique.
        Player::create(['firstname' => 'A', 'membership_id' => 'FB-2022-00001']);

        $this->assertSame('FB-2022-00002', MembershipNumber::forBranch($this->foot, 2022));
    }

    #[Test]
    public function a_number_is_free_per_branch_and_year(): void
    {
        $this->member('FB-2022-00001');

        $this->assertSame('SW-2022-00001', MembershipNumber::forBranch($this->swim, 2022));
        $this->assertSame('FB-2023-00001', MembershipNumber::forBranch($this->foot, 2023));
    }

    #[Test]
    public function a_left_members_number_stays_taken(): void
    {
        $player = $this->member('FB-2022-00001');
        $this->service->leave($player, [$this->foot->id], '2026-01-10');

        $this->assertSame('FB-2022-00002', MembershipNumber::forBranch($this->foot, 2022));
    }

    #[Test]
    public function a_year_correction_frees_the_old_number_for_the_next_member(): void
    {
        $moved = $this->member('FB-2022-00001');
        $this->member('FB-2022-00002');

        $this->service->setJoinDate($moved->registrations()->firstOrFail(), '2021-06-01');

        $this->assertSame('FB-2021-00001', $moved->fresh()->membership_id);
        $this->assertSame('FB-2022-00001', MembershipNumber::forBranch($this->foot, 2022));
    }

    #[Test]
    public function a_deleted_registration_frees_its_number_for_the_next_member(): void
    {
        $player = $this->member('FB-2022-00001');
        $swimming = $player->registrations()->create([
            'branch_id' => $this->swim->id, 'membership_id' => 'SW-2022-00001', 'category_id' => null, 'legacy_join_year' => 2022,
        ]);
        $this->member('SW-2022-00002', $this->swim);

        $this->assertNull($this->service->removeMistake($swimming));

        $this->assertSame('SW-2022-00001', MembershipNumber::forBranch($this->swim, 2022));
    }

    #[Test]
    public function a_reissued_number_can_be_saved(): void
    {
        $moved = $this->member('FB-2022-00001');
        $this->service->setJoinDate($moved->registrations()->firstOrFail(), '2021-06-01');

        // A second registration, as a first one adopts the ID the player holds.
        $next = $this->member('SW-2022-00001', $this->swim);
        $row = $this->service->register($next, ['branch_id' => $this->foot->id, 'legacy_join_year' => 2022]);

        $this->assertSame('FB-2022-00001', $row->membership_id);
    }
}
