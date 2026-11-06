<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Services\Player\RegistrationCounts;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class ListRuleTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    private function ids(...$args): array
    {
        return Player::query()->listedWhere(...$args)->orderBy('id')->pluck('id')->all();
    }

    private function left(): int
    {
        return (int) PlayerStatus::where('code', 'left')->value('id');
    }

    #[Test]
    public function one_branch_holds_every_condition_on_the_same_registration(): void
    {
        $player = $this->bothActive();

        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], categories: [$this->u15->id]));
        $this->assertSame([], $this->ids(branches: [$this->swim->id], categories: [$this->senior->id]));
    }

    #[Test]
    public function hiding_left_uses_the_registration_with_a_branch_and_the_person_without(): void
    {
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();

        $this->assertSame([], $this->ids(branches: [$this->foot->id], hideLeft: true));
        $this->assertSame([$leftFoot->id], $this->ids(branches: [$this->swim->id], hideLeft: true));
        $this->assertSame([$leftFoot->id], $this->ids(hideLeft: true));
        $this->assertSame([$leftFoot->id, $leftBoth->id], $this->ids());
    }

    #[Test]
    public function a_status_club_wide_is_the_overall_status_and_in_a_branch_that_registrations(): void
    {
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();

        $this->assertSame([$leftBoth->id], $this->ids(statuses: [$this->left()]));
        $this->assertSame([$leftFoot->id, $leftBoth->id], $this->ids(branches: [$this->foot->id], statuses: [$this->left()]));
    }

    #[Test]
    public function a_category_club_wide_ignores_a_left_registration_unless_left_is_asked_for(): void
    {
        $leftFoot = $this->leftFootball();
        $leftBoth = $this->leftBoth();
        $registered = (int) PlayerStatus::where('code', 'registered')->value('id');

        $this->assertSame([], $this->ids(categories: [$this->senior->id], hideLeft: true));
        $this->assertSame([], $this->ids(categories: [$this->senior->id], statuses: [$registered]));
        $this->assertSame([$leftBoth->id], $this->ids(categories: [$this->senior->id], statuses: [$this->left()]));
        $this->assertSame([$leftFoot->id, $leftBoth->id], $this->ids(categories: [$this->senior->id]));
    }

    #[Test]
    public function no_status_matches_a_blank_status_on_the_right_row(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['status_id' => null]);

        $this->assertSame([$player->id], $this->ids(branches: [$this->swim->id], noStatus: true));
        $this->assertSame([], $this->ids(branches: [$this->foot->id], noStatus: true));
    }

    #[Test]
    public function a_player_with_no_registration_uses_their_own_columns(): void
    {
        $flat = $this->noRegistration();

        $this->assertSame([$flat->id], $this->ids(categories: [$this->senior->id], hideLeft: true));
        $this->assertSame([], $this->ids(branches: [$this->foot->id]));
        $this->assertSame([$flat->id], $this->ids(noStatus: true));
    }

    #[Test]
    public function overlay_shows_the_branchs_values_and_only_its_other_positions(): void
    {
        $player = $this->bothActive();
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $striker = Position::create(['name' => 'Striker', 'abbreviation' => 'ST', 'branch_id' => $this->foot->id]);
        $anywhere = Position::create(['name' => 'Captain', 'abbreviation' => 'CP']);
        $player->otherPositions()->attach([$crawl->id, $striker->id, $anywhere->id]);
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => 'SW-2020-00001', 'licence_number' => 'LIC']);
        $loaded = Player::with(['category', 'otherPositions', 'registrations.category'])->findOrFail($player->id);

        RegistrationDisplay::make()->overlay($loaded, $this->swim->id);

        $this->assertSame('SW-2020-00001', $loaded->membership_id);
        $this->assertSame('LIC', $loaded->licence_number);
        $this->assertSame($this->u15->id, $loaded->category_id);
        $this->assertSame('U15', $loaded->category->name);
        $this->assertEqualsCanonicalizing([$crawl->id, $anywhere->id], $loaded->otherPositions->pluck('id')->all());
    }

    #[Test]
    public function overlay_with_no_row_there_changes_nothing(): void
    {
        $loaded = Player::with('registrations')->findOrFail($this->noRegistration()->id);
        $before = $loaded->getAttributes();

        RegistrationDisplay::make()->overlay($loaded, $this->swim->id);

        $this->assertSame($before, $loaded->getAttributes());
    }

    #[Test]
    public function category_counts_put_a_two_branch_player_in_both_bars_and_skip_left_rows(): void
    {
        $this->bothActive('A');
        $this->leftFootball('B');
        $this->leftBoth('C');
        $this->noRegistration('D');

        $bars = RegistrationCounts::byCategory(Player::query())->pluck('total', 'key')->all();
        $this->assertEquals([$this->senior->id => 2, $this->u15->id => 2], $bars);

        $withLeft = RegistrationCounts::byCategory(Player::query(), includeLeft: true)->pluck('total', 'key')->all();
        $this->assertEquals([$this->senior->id => 4, $this->u15->id => 3], $withLeft);

        $football = RegistrationCounts::byCategory(Player::query(), [$this->foot->id], includeLeft: true)->pluck('total', 'key')->all();
        $this->assertEquals([$this->senior->id => 3], $football);
    }

    #[Test]
    public function status_counts_read_the_registration(): void
    {
        $this->leftFootball();

        $football = RegistrationCounts::byStatus(Player::query(), [$this->foot->id], includeLeft: true)->pluck('total', 'key')->all();

        $this->assertEquals([$this->left() => 1], $football);
    }
}
