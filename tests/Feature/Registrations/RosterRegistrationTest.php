<?php

namespace Tests\Feature\Registrations;

use App\Enums\AttendanceStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Services\Attendance\Roster;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\Support\RegistrationSessions;
use Tests\TestCase;

class RosterRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures, RegistrationSessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    /** @return list<int> */
    private function expected(?Branch $branch, Category|array $categories, string $date = '2026-03-10', ?array $statusIds = null): array
    {
        $ids = array_map(fn (Category $c) => $c->id, is_array($categories) ? $categories : [$categories]);

        return app(Roster::class)->expected($ids, $date, $statusIds, $branch?->id)->modelKeys();
    }

    private function statusId(string $code): int
    {
        return (int) PlayerStatus::where('code', $code)->value('id');
    }

    #[Test]
    public function a_branch_session_reads_that_branchs_category(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior));
        $this->assertSame([], $this->expected($this->foot, $this->u15));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15));
        $this->assertSame([], $this->expected($this->swim, $this->senior));
    }

    #[Test]
    public function a_session_with_no_branch_matches_any_registration_and_lists_a_player_once(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->expected(null, $this->senior));
        $this->assertSame([$player->id], $this->expected(null, $this->u15));
        $this->assertSame([$player->id], $this->expected(null, [$this->senior, $this->u15]));
    }

    #[Test]
    public function a_left_branch_lists_the_player_up_to_the_leave_date_and_not_after(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, '2026-03-14'));
        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, $this->leaveDate));
        $this->assertSame([], $this->expected($this->foot, $this->senior, '2026-03-16'));
        // The other sport goes on.
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15, '2026-03-16'));
    }

    #[Test]
    public function left_everywhere_lists_them_nowhere_after_the_date(): void
    {
        $player = $this->leftBoth();

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, $this->leaveDate));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15, $this->leaveDate));
        $this->assertSame([], $this->expected($this->foot, $this->senior, '2026-03-16'));
        $this->assertSame([], $this->expected($this->swim, $this->u15, '2026-03-16'));
        $this->assertSame([], $this->expected(null, $this->senior, '2026-03-16'));
    }

    #[Test]
    public function no_registration_uses_the_players_columns_and_has_no_branch(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame([$flat->id], $this->expected(null, $this->senior));
        $this->assertSame([], $this->expected(null, $this->u15));
        $this->assertSame([], $this->expected($this->foot, $this->senior));
    }

    #[Test]
    public function the_status_set_applies_to_the_row_in_that_branch(): void
    {
        $player = $this->bothActive();
        $paused = $this->statusId('paused');
        app(RegistrationService::class)->setField($player, 'status_id', $paused, $this->foot->id);

        // Default set: registered.
        $this->assertSame([], $this->expected($this->foot, $this->senior));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15));
        // A session's own set.
        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior, '2026-03-10', [$paused]));
        $this->assertSame([], $this->expected($this->swim, $this->u15, '2026-03-10', [$paused]));
    }

    #[Test]
    public function a_row_with_no_status_counts_as_registered(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->foot->id)->update(['status_id' => null]);

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior));
    }

    #[Test]
    public function a_legacy_left_row_with_no_date_never_counts(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->foot->id)
            ->update(['status_id' => $this->statusId('left'), 'left_at' => null]);

        $this->assertSame([], $this->expected($this->foot, $this->senior));
        $this->assertSame([$player->id], $this->expected($this->swim, $this->u15));
    }

    #[Test]
    public function an_archived_player_is_not_expected(): void
    {
        $player = $this->bothActive();
        $player->update(['archived' => true]);

        $this->assertSame([], $this->expected($this->foot, $this->senior));
    }

    #[Test]
    public function the_mirror_decides_nothing_for_a_registered_player(): void
    {
        $player = $this->bothActive();
        DB::table('players')->where('id', $player->id)->update(['category_id' => $this->u15->id]);

        $this->assertSame([$player->id], $this->expected($this->foot, $this->senior));
        $this->assertSame([], $this->expected($this->foot, $this->u15));
    }

    #[Test]
    public function a_marked_session_stays_frozen_after_leaving(): void
    {
        $player = $this->bothActive();
        $session = $this->sessionIn($this->foot, [$this->senior], '2026-03-20');
        $this->markOn($session, $player, AttendanceStatus::Present);

        app(RegistrationService::class)->leave($player, [$this->foot->id], $this->leaveDate);

        $this->assertSame([$player->id], app(Roster::class)->forSession($session->fresh())->modelKeys());
    }

    #[Test]
    public function an_unmarked_session_after_leaving_drops_them(): void
    {
        $player = $this->leftFootball();
        $stays = Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'Stays', 'lastname' => 'Test', 'join_year' => 2020]);
        app(RegistrationService::class)->register($stays, ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id]);
        $session = $this->sessionIn($this->foot, [$this->senior], '2026-03-20');

        $ids = app(Roster::class)->forSession($session)->modelKeys();

        $this->assertSame([$stays->id], $ids);
        $this->assertNotContains($player->id, $ids);
    }
}
