<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The player form's exact payload, and bulk Replace with nothing ticked. */
class RegistrationFormFixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    private RegistrationService $service;

    private int $registered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
        $this->service = app(RegistrationService::class);
        $this->registered = (int) PlayerStatus::where('code', 'registered')->value('id');
    }

    private function base(array $extra = []): array
    {
        return ['firstname' => 'F', 'lastname' => 'L', 'birthdate' => '2000-01-01', 'gender' => 'Male', ...$extra];
    }

    /** What the form sends for a card: every key, an empty string for an emptied list. */
    private function card(Branch $branch, array $extra = []): array
    {
        return [
            'branch_id' => $branch->id, 'join_date' => null, 'category_id' => null, 'position_id' => null,
            'other_position_ids' => '', 'licence_number' => null, 'status_id' => $this->registered,
            'left_at' => null, 'sporting_status_id' => null, ...$extra,
        ];
    }

    private function snapshot(): string
    {
        return DB::table('branch_player')->orderBy('id')->get()->toJson().DB::table('players')->orderBy('id')->get()->toJson();
    }

    private function crawlPlayer(): array
    {
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);
        $player = Player::create($this->base(['membership_id' => '201500001', 'join_year' => 2015, 'position_id' => $crawl->id]));

        return [$player, $crawl];
    }

    // R1

    #[Test]
    public function bulk_replace_with_nothing_ticked_is_refused_and_changes_nothing(): void
    {
        $player = Player::create($this->base(['membership_id' => '201700001', 'join_year' => 2017]));
        $this->service->register($player, ['branch_id' => $this->foot->id]);
        $this->service->register($player, ['branch_id' => $this->swim->id, 'join_date' => '2020-02-02']);
        $before = $this->snapshot();

        foreach ([['value' => []], ['value' => ''], []] as $value) {
            $payload = ['ids' => [$player->id], 'field' => 'branches', 'mode' => 'replace', ...$value];
            $this->post(route('players.bulkUpdate'), $payload)->assertSessionHasErrors('value');
        }

        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function bulk_replace_with_a_branch_ticked_still_leaves_the_others(): void
    {
        $player = Player::create($this->base(['membership_id' => '201700001', 'join_year' => 2017]));
        $this->service->register($player, ['branch_id' => $this->foot->id]);
        $this->service->register($player, ['branch_id' => $this->swim->id, 'join_date' => '2020-02-02']);

        $this->post(route('players.bulkUpdate'), ['ids' => [$player->id], 'field' => 'branches', 'mode' => 'replace', 'value' => [$this->foot->id]])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Player::isLeftStatus($player->registrations()->where('branch_id', $this->swim->id)->value('status_id')));
        $this->assertFalse(Player::isLeftStatus($player->registrations()->where('branch_id', $this->foot->id)->value('status_id')));
    }

    // R2

    #[Test]
    public function the_form_keeps_a_position_another_branch_owns_when_the_first_card_is_added(): void
    {
        [$player, $crawl] = $this->crawlPlayer();

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [$this->card($this->foot, ['join_date' => '2026-01-01'])],
        ]))->assertSessionHasNoErrors();

        $this->assertNull($player->registrations()->firstOrFail()->position_id);
        $this->assertNull($player->fresh()->position_id);
        $this->assertSame([$crawl->id], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function a_first_card_that_picks_the_players_own_position_is_unaffected(): void
    {
        $striker = Position::create(['name' => 'Striker', 'abbreviation' => 'ST', 'branch_id' => $this->foot->id]);
        $player = Player::create($this->base(['membership_id' => '201500001', 'join_year' => 2015, 'position_id' => $striker->id]));

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [$this->card($this->foot, ['join_date' => '2026-01-01', 'position_id' => $striker->id])],
        ]))->assertSessionHasNoErrors();

        $this->assertSame($striker->id, $player->registrations()->firstOrFail()->position_id);
        $this->assertSame($striker->id, $player->fresh()->position_id);
        $this->assertSame([], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function a_player_holding_no_position_gets_no_other_position(): void
    {
        $player = Player::create($this->base(['membership_id' => '201500001', 'join_year' => 2015]));

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [$this->card($this->foot, ['join_date' => '2026-01-01'])],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([], $player->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function the_form_keeps_a_moved_position_when_it_adds_its_own_branch_card(): void
    {
        [$player, $crawl] = $this->crawlPlayer();
        $this->post(route('branches.players.sync', $this->foot), ['player_ids' => [$player->id]])->assertSessionHasNoErrors();
        $this->assertSame([$crawl->id], $player->otherPositions()->pluck('positions.id')->all());

        // The edit form: the football card as saved, and a new swimming card that lists Crawl (newCard).
        $this->put(route('players.update', $player), $this->base([
            'registrations' => [
                $this->card($this->foot),
                $this->card($this->swim, ['join_date' => '2026-01-01', 'other_position_ids' => [$crawl->id]]),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$crawl->id], $player->otherPositions()->pluck('positions.id')->all());
        $this->assertNull($player->registrations()->where('branch_id', $this->swim->id)->value('position_id'));
        $this->assertNull($player->fresh()->position_id);
    }

    // m1

    #[Test]
    public function a_date_typed_on_a_left_rows_own_card_saves_through_the_form(): void
    {
        $left = Player::leftStatusId();
        $player = Player::create($this->base(['membership_id' => '201800005', 'join_year' => 2018]));
        $player->branches()->attach($this->foot->id, ['membership_id' => '201800005', 'category_id' => null, 'legacy_join_year' => 2018]);
        $this->service->leave($player, [$this->foot->id], '2024-01-01');

        $this->put(route('players.update', $player), $this->base([
            'registrations' => [$this->card($this->foot, ['join_date' => '2018-06-01', 'status_id' => $left, 'left_at' => '2024-01-01'])],
        ]))->assertSessionHasNoErrors();

        $row = $player->registrations()->firstOrFail();
        $this->assertSame('2018-06-01', $row->join_date->toDateString());
        $this->assertSame('201800005', $row->membership_id);
        $this->assertTrue(Player::isLeftStatus($row->status_id));
        $this->assertSame('2024-01-01', $row->left_at->toDateString());
    }
}
