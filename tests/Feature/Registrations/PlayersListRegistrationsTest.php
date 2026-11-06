<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class PlayersListRegistrationsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
    }

    /** @return array<string, mixed> */
    private function props(array $query = []): array
    {
        $props = [];
        $this->get(route('players.index', $query))->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function rowNames(array $query = []): array
    {
        return collect($this->props($query)['players']['data'])->pluck('firstname')->all();
    }

    private function swimId(Player $player, string $id = 'SW-2020-00001'): void
    {
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['membership_id' => $id]);
    }

    #[Test]
    public function swimming_and_senior_no_longer_match_a_football_senior(): void
    {
        $this->bothActive();

        $this->assertSame([], $this->rowNames(['branch_id' => [$this->swim->id], 'category_id' => [$this->senior->id]]));
        $this->assertSame(['Both'], $this->rowNames(['branch_id' => [$this->swim->id], 'category_id' => [$this->u15->id]]));
    }

    #[Test]
    public function a_two_branch_player_is_one_row(): void
    {
        $this->bothActive();

        $this->assertSame(['Both'], $this->rowNames());
        $this->assertSame(['Both'], $this->rowNames(['branch_id' => [$this->foot->id, $this->swim->id]]));
    }

    #[Test]
    public function one_branch_shows_that_registrations_values(): void
    {
        $this->swimId($this->bothActive());

        $row = $this->props(['branch_id' => [$this->swim->id]])['players']['data'][0];

        $this->assertSame('SW-2020-00001', $row['membership_id']);
        $this->assertSame($this->u15->id, $row['category_id']);
        $this->assertSame('U15', $row['category']['name']);
        $this->assertArrayNotHasKey('registrations', $row);
    }

    #[Test]
    public function no_branch_or_several_show_the_main_values(): void
    {
        $this->swimId($this->bothActive());

        foreach ([[], ['branch_id' => [$this->foot->id, $this->swim->id]]] as $query) {
            $row = $this->props($query)['players']['data'][0];
            $this->assertSame($this->senior->id, $row['category_id']);
            $this->assertStringStartsWith('FX-', $row['membership_id']);
        }
    }

    #[Test]
    public function a_player_who_left_one_branch_is_hidden_only_there(): void
    {
        $this->leftFootball();

        $this->assertSame([], $this->rowNames(['branch_id' => [$this->foot->id]]));
        $this->assertSame(['LeftFoot'], $this->rowNames(['branch_id' => [$this->swim->id]]));
        $this->assertSame(['LeftFoot'], $this->rowNames());
    }

    #[Test]
    public function asking_for_left_in_a_branch_finds_who_left_that_branch(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertSame(['LeftFoot'], $this->rowNames(['branch_id' => [$this->foot->id], 'status' => [$left]]));
        $this->assertSame([], $this->rowNames(['status' => [$left]]));
    }

    #[Test]
    public function left_everywhere_stays_out_until_asked_for(): void
    {
        $this->leftBoth();
        $left = PlayerStatus::where('code', 'left')->value('id');

        $this->assertSame([], $this->rowNames());
        $this->assertSame(['LeftBoth'], $this->rowNames(['status' => [$left]]));
    }

    #[Test]
    public function a_player_with_no_registration_keeps_working(): void
    {
        $this->noRegistration();

        $this->assertSame(['Flat'], $this->rowNames(['category_id' => [$this->senior->id]]));
        $this->assertSame([], $this->rowNames(['branch_id' => [$this->foot->id]]));
    }

    #[Test]
    public function the_category_chart_counts_both_bars_club_wide_and_the_branchs_row_with_one(): void
    {
        $this->bothActive();

        $bars = fn (array $query) => collect($this->props($query)['categoryStats'])->pluck('count', 'category_id')->all();

        $this->assertEquals([$this->senior->id => 1, $this->u15->id => 1], $bars([]));
        $this->assertEquals([$this->u15->id => 1], $bars(['branch_id' => [$this->swim->id]]));
    }

    #[Test]
    public function the_status_chart_is_the_person_club_wide_and_the_registration_with_one_branch(): void
    {
        $this->leftFootball();
        $left = PlayerStatus::where('code', 'left')->value('id');
        $registered = PlayerStatus::where('code', 'registered')->value('id');

        $bars = fn (array $query) => collect($this->props($query)['statusStats'])->pluck('count', 'status_id')->all();

        $this->assertEquals([$registered => 1], $bars([]));
        $this->assertEquals([$left => 1], $bars(['branch_id' => [$this->foot->id]]));
    }

    #[Test]
    public function the_position_filter_finds_a_second_branchs_main_position(): void
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['position_id' => $crawl->id]);

        $this->assertSame(['Both'], $this->rowNames(['position_id' => [$crawl->id]]));
    }

    #[Test]
    public function the_export_follows_the_one_branch(): void
    {
        $this->swimId($this->bothActive());

        $swim = $this->get(route('players.export', ['branch_id' => [$this->swim->id], 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('SW-2020-00001', $swim);
        $this->assertStringContainsString('U15', $swim);

        $all = $this->get(route('players.export', ['format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('FX-0', $all);
    }

    #[Test]
    public function the_print_list_follows_the_one_branch(): void
    {
        $this->swimId($this->bothActive());

        $this->get(route('players.print-list', ['branch_id' => [$this->swim->id]]))->assertOk();
    }
}
