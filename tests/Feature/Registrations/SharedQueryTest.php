<?php

namespace Tests\Feature\Registrations;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\PlayerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class SharedQueryTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function ids(...$args): array
    {
        return Player::query()->registeredWhere(...$args)->orderBy('id')->pluck('id')->all();
    }

    #[Test]
    public function branch_and_category_must_hold_on_the_same_registration(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], categories: [$this->senior->id]));
        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], categories: [$this->u15->id]));
        $this->assertSame([], $this->ids(branches: [$this->swim->id], categories: [$this->senior->id]));
    }

    #[Test]
    public function active_ignores_a_branch_the_player_left(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([], $this->ids(branches: [$this->foot->id], active: true));
        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id]));
        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], active: true));
        $this->assertSame([$player->id], $this->ids(active: true));
    }

    #[Test]
    public function left_everywhere_is_not_active_anywhere(): void
    {
        $this->leftBoth();

        $this->assertSame([], $this->ids(active: true));
    }

    #[Test]
    public function active_on_includes_the_leave_date_and_nothing_after(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], activeOn: '2026-03-14'));
        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], activeOn: $this->leaveDate));
        $this->assertSame([], $this->ids(branches: [$this->foot->id], activeOn: '2026-03-16'));
    }

    #[Test]
    public function active_on_reads_a_session_date_held_with_a_time(): void
    {
        $player = $this->leftFootball();

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], activeOn: $this->leaveDate.' 00:00:00'));
    }

    #[Test]
    public function a_legacy_left_row_with_no_date_is_never_active_on_a_date(): void
    {
        $player = $this->leftFootball();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->foot->id)->update(['left_at' => null]);

        $this->assertSame([], $this->ids(branches: [$this->foot->id], activeOn: '2020-01-01'));
    }

    #[Test]
    public function a_status_is_matched_on_the_registration(): void
    {
        $player = $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertSame([$player->id], $this->ids(branches: [$this->foot->id], statuses: [$left]));
        $this->assertSame([], $this->ids(branches: [$this->swim->id], statuses: [$left]));
    }

    #[Test]
    public function a_player_with_no_registration_falls_back_to_their_own_columns(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame([$flat->id], $this->ids(categories: [$this->senior->id]));
        $this->assertSame([], $this->ids(categories: [$this->u15->id]));
        $this->assertSame([$flat->id], $this->ids(active: true));
        $this->assertSame([], $this->ids(branches: [$this->foot->id]));

        $flat->update(['status_id' => PlayerStatus::where('code', 'left')->value('id'), 'left_at' => $this->leaveDate]);
        $this->assertSame([], $this->ids(active: true));
        $this->assertSame([$flat->id], $this->ids(activeOn: $this->leaveDate));
        $this->assertSame([], $this->ids(activeOn: '2026-03-16'));
    }

    #[Test]
    public function a_registered_player_never_falls_back_to_the_mirror(): void
    {
        $player = $this->bothActive();
        DB::table('players')->where('id', $player->id)->update(['category_id' => null]);

        $this->assertSame([$player->id], $this->ids(categories: [$this->senior->id]));
        DB::table('players')->where('id', $player->id)->update(['category_id' => $this->u15->id]);
        $this->assertSame([$player->id], $this->ids(categories: [$this->u15->id]));
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);
        $this->assertSame([], $this->ids(categories: [$this->u15->id]));
    }

    #[Test]
    public function an_empty_list_matches_nothing_and_null_matches_everyone(): void
    {
        $player = $this->bothActive();
        $flat = $this->noRegistration();

        $this->assertSame([], $this->ids(categories: []));
        $this->assertSame([], $this->ids(branches: []));
        $this->assertSame([$player->id, $flat->id], $this->ids());
    }

    #[Test]
    public function it_takes_a_single_id_or_a_collection(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->ids(branches: $this->foot->id));
        $this->assertSame([$player->id], $this->ids(branches: collect([$this->foot->id])));
    }

    #[Test]
    public function registration_in_reads_the_loaded_rows_without_a_query(): void
    {
        $this->bothActive();
        $player = Player::with('registrations')->first();

        DB::enableQueryLog();
        $this->assertSame($this->u15->id, $player->registrationIn($this->swim->id)->category_id);
        $this->assertNull($player->registrationIn(999));
        $this->assertNull($player->registrationIn(null));
        $this->assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function every_row_gives_one_row_per_registration_and_one_per_unregistered_player(): void
    {
        $both = $this->bothActive();
        $flat = $this->noRegistration();

        $rows = DB::query()->fromSub(BranchRegistration::everyRow(), 'reg')->orderBy('player_id')->orderBy('branch_id')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(2, $rows->where('player_id', $both->id)->count());
        $this->assertNull($rows->firstWhere('player_id', $flat->id)->branch_id);
        $this->assertEquals($this->senior->id, $rows->firstWhere('player_id', $flat->id)->category_id);
    }

    #[Test]
    public function the_query_does_not_widen_an_outer_or(): void
    {
        $this->bothActive();
        $flat = $this->noRegistration('Other');

        $found = Player::query()->where('firstname', 'Other')->registeredWhere(categories: [$this->senior->id])->pluck('id')->all();

        $this->assertSame([$flat->id], $found);
    }
}
