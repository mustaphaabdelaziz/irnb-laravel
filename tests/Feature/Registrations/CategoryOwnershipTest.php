<?php

namespace Tests\Feature\Registrations;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\Subscription;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Player\RegistrationService;
use App\Support\CategoryOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class CategoryOwnershipTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    private function slot(array $extra = []): array
    {
        return $extra + ['kind' => SessionKind::Extra->value, 'date' => '2026-03-02', 'start_time' => '18:00', 'end_time' => '19:00'];
    }

    #[Test]
    public function the_helper_checks_owner_stored_value_and_unowned(): void
    {
        $unowned = Category::create(['name' => 'Open']);

        $this->assertTrue(CategoryOwnership::outside($this->senior->id, [$this->swim->id]));
        $this->assertFalse(CategoryOwnership::outside($this->u15->id, [$this->swim->id]));
        $this->assertFalse(CategoryOwnership::outside($unowned->id, [$this->swim->id]));
        $this->assertFalse(CategoryOwnership::outside($this->senior->id, [$this->swim->id], $this->senior->id));
        $this->assertFalse(CategoryOwnership::outside($this->senior->id, []));
        $this->assertFalse(CategoryOwnership::outside(null, [$this->swim->id]));
        $this->assertTrue(CategoryOwnership::outside([$this->u15->id, $this->senior->id], [$this->swim->id]));
    }

    #[Test]
    public function a_session_refuses_a_category_of_another_branch(): void
    {
        $this->post(route('attendance.sessions.store'), $this->slot(['branch_id' => $this->swim->id, 'category_id' => $this->senior->id]))
            ->assertSessionHasErrors('category_id');
        $this->assertSame(0, TrainingSession::count());

        $this->post(route('attendance.sessions.store'), $this->slot(['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function every_category_of_a_joint_session_must_belong_to_its_branch(): void
    {
        $this->post(route('attendance.sessions.store'), $this->slot([
            'kind' => SessionKind::Preseason->value, 'branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'category_ids' => [$this->u15->id, $this->senior->id],
        ]))->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function a_session_with_no_branch_takes_any_category(): void
    {
        $session = TrainingSession::create(['category_id' => $this->senior->id, 'date' => '2026-03-03', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);

        $this->put(route('attendance.sessions.update', $session), ['title' => 'x'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function moving_a_session_to_another_branch_checks_its_categories(): void
    {
        $session = TrainingSession::create(['branch_id' => $this->foot->id, 'category_id' => $this->senior->id, 'date' => '2026-03-03', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);
        $session->categories()->syncWithoutDetaching([$this->senior->id]);

        $this->put(route('attendance.sessions.update', $session), ['branch_id' => $this->swim->id])->assertSessionHasErrors('category_ids');
    }

    #[Test]
    public function a_session_that_no_longer_fits_can_still_be_edited(): void
    {
        $session = TrainingSession::create(['branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'date' => '2026-03-03', 'start_time' => '18:00', 'end_time' => '19:00', 'kind' => SessionKind::Extra, 'state' => SessionState::Planned]);
        $session->categories()->syncWithoutDetaching([$this->senior->id]);

        $this->put(route('attendance.sessions.update', $session), ['title' => 'x'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_schedule_refuses_another_branchs_category_and_keeps_a_stored_one(): void
    {
        $body = ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'valid_from' => '2026-01-01'];

        $this->post(route('attendance.schedules.store'), $body)->assertSessionHasErrors('category_id');

        $stale = TrainingSchedule::create($body);
        $this->put(route('attendance.schedules.update', $stale), $body)->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_target_refuses_another_branchs_category(): void
    {
        $this->post(route('attendance.preseason-targets.store'), [
            'branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'season_start_year' => 2026, 'target_count' => 5,
        ])->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function a_team_refuses_another_branchs_category(): void
    {
        $format = $this->format($this->swim);

        $this->post(route('teams.store'), ['branch_id' => $this->swim->id, 'category_id' => $this->senior->id, 'name' => 'A', 'default_game_format_id' => $format->id])
            ->assertSessionHasErrors('category_id');
        $this->post(route('teams.store'), ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id, 'name' => 'A', 'default_game_format_id' => $format->id])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_caisse_refuses_another_branchs_category(): void
    {
        $this->post(route('finance.accounts.store'), ['name' => 'C', 'type' => 'cash', 'branch_id' => $this->swim->id, 'category_id' => $this->senior->id])
            ->assertSessionHasErrors('category_id');
        $this->assertSame(0, FinanceAccount::where('name', 'C')->count());
    }

    #[Test]
    public function a_subscription_category_must_belong_to_a_ticked_branch_if_any(): void
    {
        $body = ['kind' => 'annual', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100];

        $this->post(route('subscriptions.store'), $body + ['name' => 'A', 'branch_ids' => [$this->swim->id], 'category_ids' => [$this->senior->id]])
            ->assertSessionHasErrors('category_ids');
        $this->post(route('subscriptions.store'), $body + ['name' => 'B', 'branch_ids' => [$this->swim->id, $this->foot->id], 'category_ids' => [$this->senior->id, $this->u15->id]])
            ->assertSessionHasNoErrors();
        $this->post(route('subscriptions.store'), $body + ['name' => 'C', 'category_ids' => [$this->senior->id, $this->u15->id]])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_subscription_keeps_a_stored_category_that_no_longer_fits(): void
    {
        $subscription = Subscription::create(['name' => 'S', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100]);
        $subscription->categories()->attach($this->senior->id);
        $subscription->branches()->attach($this->swim->id);

        $this->put(route('subscriptions.update', $subscription), [
            'kind' => 'annual', 'name' => 'S', 'year' => 2026, 'amount_student' => 100, 'amount_worker' => 100,
            'branch_ids' => [$this->swim->id], 'category_ids' => [$this->senior->id],
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function an_activity_category_must_belong_to_its_chosen_branch_if_any(): void
    {
        $body = ['title' => 'A', 'activity_type_id' => DB::table('activity_types')->value('id'), 'starts_on' => '2026-03-01'];

        $this->post(route('activities.store'), $body + ['branch_id' => $this->swim->id, 'category_ids' => [$this->senior->id]])
            ->assertSessionHasErrors('category_ids');
        $this->post(route('activities.store'), $body + ['category_ids' => [$this->senior->id, $this->u15->id]])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_registration_card_refuses_another_branchs_category_and_keeps_a_stored_one(): void
    {
        $player = $this->bothActive();
        DB::table('branch_player')->where('player_id', $player->id)->where('branch_id', $this->swim->id)->update(['category_id' => $this->senior->id]);
        $card = fn (int $category) => ['branch_id' => $this->swim->id, 'category_id' => $category];
        $base = ['firstname' => 'Both', 'lastname' => 'Test', 'gender' => 'Male', 'registrations' => [
            ['branch_id' => $this->foot->id, 'category_id' => $this->senior->id],
        ]];

        $this->put(route('players.update', $player), ['registrations' => [...$base['registrations'], $card($this->senior->id)]] + $base)
            ->assertSessionDoesntHaveErrors('registrations.1.category_id');

        $other = Category::create(['name' => 'Cadet', 'branch_id' => $this->foot->id]);
        $this->put(route('players.update', $player), ['registrations' => [...$base['registrations'], $card($other->id)]] + $base)
            ->assertSessionHasErrors('registrations.1.category_id');
    }

    #[Test]
    public function bulk_category_goes_to_the_registration_in_its_branch(): void
    {
        $both = $this->bothActive();
        $service = app(RegistrationService::class);
        $swimmer = \App\Models\Player::create(['membership_id' => $this->fixtureId(), 'firstname' => 'Swimmer', 'join_year' => 2020]);
        $service->register($swimmer, ['branch_id' => $this->swim->id, 'category_id' => $this->u15->id]);
        $cadet = Category::create(['name' => 'Cadet', 'branch_id' => $this->foot->id]);

        $service->setField($both, 'category_id', $cadet->id);
        $service->setField($swimmer, 'category_id', $cadet->id);

        $this->assertSame($cadet->id, $both->registrationIn($this->foot->id)->category_id);
        $this->assertSame($this->u15->id, $both->refresh()->registrationIn($this->swim->id)->category_id);
        $this->assertSame($this->u15->id, $swimmer->refresh()->registrationIn($this->swim->id)->category_id, 'left alone');
    }
}
