<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\Position;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerRegistrationCardsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $foot;

    private Branch $swim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $this->swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $this->foot->id]);
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function a_new_player_gets_one_registration_and_id_per_card(): void
    {
        $category = Category::create(['name' => 'Senior']);

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [
                ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15', 'category_id' => $category->id],
                ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $player = Player::firstOrFail();
        $ids = $player->registrations()->orderBy('branch_id')->pluck('membership_id')->all();
        $this->assertSame(['FB-2022-00001', 'SW-2025-00001'], $ids);
        $this->assertSame('FB-2022-00001', $player->membership_id);
        $this->assertSame($category->id, $player->category_id);
        $this->assertNull($player->position_id);
    }

    #[Test]
    public function a_new_players_id_comes_from_the_main_branch_whatever_the_card_order(): void
    {
        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [
                ['branch_id' => $this->swim->id, 'join_date' => '2025-03-10'],
                ['branch_id' => $this->foot->id, 'join_date' => '2022-09-15'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $player = Player::firstOrFail();
        $football = $player->registrations()->where('branch_id', $this->foot->id)->value('membership_id');
        $swimming = $player->registrations()->where('branch_id', $this->swim->id)->value('membership_id');

        $this->assertStringStartsWith('FB-', $football);
        $this->assertStringStartsWith('SW-', $swimming);
        $this->assertSame($football, $player->membership_id);
    }

    #[Test]
    public function a_new_card_needs_a_valid_date(): void
    {
        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->foot->id]],
        ])->assertSessionHasErrors('registrations.0.join_date');

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->foot->id, 'join_date' => now()->addDay()->toDateString()]],
        ])->assertSessionHasErrors('registrations.0.join_date');

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test', 'birthdate' => '2010-05-01',
            'registrations' => [['branch_id' => $this->foot->id, 'join_date' => '2009-01-01']],
        ])->assertSessionHasErrors('registrations.0.join_date');

        $this->assertSame(0, Player::count());
    }

    #[Test]
    public function a_legacy_registration_can_be_edited_without_a_date_but_a_saved_one_stays(): void
    {
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700001', 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T',
            'registrations' => [['branch_id' => $this->foot->id, 'join_date' => null, 'licence_number' => 'L1']],
        ])->assertSessionHasNoErrors();

        $row = $player->registrations()->firstOrFail();
        $this->assertNull($row->join_date);
        $this->assertSame('L1', $row->licence_number);
        $this->assertSame('201700001', $player->fresh()->membership_id);
    }

    #[Test]
    public function a_position_must_belong_to_the_cards_branch_unless_it_was_already_there(): void
    {
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $this->foot->id]);

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [['branch_id' => $this->swim->id, 'join_date' => '2025-03-10', 'position_id' => $gk->id]],
        ])->assertSessionHasErrors('registrations.0.position_id');

        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700002', 'join_year' => 2017, 'position_id' => $gk->id]);
        $player->branches()->attach($this->swim->id, ['position_id' => $gk->id, 'category_id' => null]);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T',
            'registrations' => [['branch_id' => $this->swim->id, 'position_id' => $gk->id]],
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function other_positions_must_belong_to_the_cards_branch_and_not_repeat_the_main(): void
    {
        $gk = Position::create(['name' => 'GK', 'abbreviation' => 'GK', 'branch_id' => $this->foot->id]);
        $crawl = Position::create(['name' => 'Crawl', 'abbreviation' => 'CR', 'branch_id' => $this->swim->id]);

        $this->post(route('players.store'), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'registrations' => [[
                'branch_id' => $this->foot->id, 'join_date' => '2022-09-15',
                'position_id' => $gk->id, 'other_position_ids' => [$crawl->id, $gk->id],
            ]],
        ])->assertSessionHasErrors('registrations.0.other_position_ids');
    }

    #[Test]
    public function a_saved_branch_with_no_card_is_not_marked_left(): void
    {
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700003', 'join_year' => 2017]);
        $player->branches()->attach([$this->foot->id, $this->swim->id]);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T',
            'registrations' => [['branch_id' => $this->foot->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $player->registrations()->count());
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->assertNotSame($left, $player->registrations()->where('branch_id', $this->swim->id)->value('status_id'));
    }

    #[Test]
    public function flat_fields_are_ignored_once_a_player_has_cards(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = Player::create(['firstname' => 'Ali', 'lastname' => 'T', 'membership_id' => '201700004', 'join_year' => 2017]);
        $player->branches()->attach($this->foot->id);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'T', 'category_id' => $category->id, 'join_year' => 1999,
            'registrations' => [['branch_id' => $this->foot->id]],
        ])->assertSessionHasNoErrors();

        $this->assertNull($player->fresh()->category_id);
        $this->assertSame(2017, $player->fresh()->join_year);
    }

    #[Test]
    public function a_club_with_no_branches_keeps_its_flat_form(): void
    {
        Branch::query()->delete();

        $this->post(route('players.store'), ['firstname' => 'Ali', 'lastname' => 'Test', 'join_year' => 2024])
            ->assertSessionHasNoErrors();

        $this->assertSame(2024, Player::firstOrFail()->join_year);
        $this->assertStringStartsWith('2024', Player::firstOrFail()->membership_id);
    }
}
