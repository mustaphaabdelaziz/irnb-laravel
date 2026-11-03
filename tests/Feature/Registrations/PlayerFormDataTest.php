<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\SportingStatus;
use App\Models\User;
use App\Services\Player\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFormDataTest extends TestCase
{
    use RefreshDatabase;

    private Player $player;

    private Branch $foot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
        $this->foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        SportingStatus::create(['name' => 'Competing']);
        SportingStatus::create(['name' => 'Hidden', 'is_active' => false]);
        $this->player = Player::create(['firstname' => 'Ali', 'membership_id' => '201700099', 'join_year' => 2017]);
        $this->player->registrations()->create([
            'branch_id' => $this->foot->id, 'membership_id' => 'FB-2017-00001', 'category_id' => null,
            'join_date' => '2017-03-12', 'legacy_join_year' => 2017, 'legacy_membership_id' => '201700001',
        ]);
    }

    #[Test]
    public function edit_and_show_carry_registrations_and_dates_stay_plain(): void
    {
        foreach (['players.edit', 'players.show'] as $route) {
            $this->get(route($route, $this->player))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('player.registrations.0.join_date', '2017-03-12')
                    ->where('player.registrations.0.membership_id', 'FB-2017-00001')
                    ->has('player.registrations.0.branch')
                    ->etc());
        }
    }

    #[Test]
    public function the_form_offers_active_sporting_statuses_only(): void
    {
        foreach ([route('players.create'), route('players.edit', $this->player)] as $url) {
            $this->get($url)
                ->assertInertia(fn (AssertableInertia $page) => $page->has('sportingStatuses', 1)->etc());
        }
    }

    #[Test]
    public function search_finds_any_registrations_id_and_the_old_one(): void
    {
        $this->assertSame(1, Player::query()->search('FB-2017')->count());
        $this->assertSame(1, Player::query()->search('201700001')->count());
        $this->assertSame(0, Player::query()->search('SW-2017')->count());
    }

    #[Test]
    public function an_old_id_kept_in_the_legacy_column_survives_removing_its_row(): void
    {
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $this->player->registrations()->create([
            'branch_id' => $swim->id, 'membership_id' => 'SW-2020-00001', 'join_date' => '2020-01-10',
        ]);
        $mistake = $this->player->registrations()->where('branch_id', $this->foot->id)->first();

        $this->assertNull(app(RegistrationService::class)->removeMistake($mistake));

        $this->assertSame(1, Player::query()->search('201700001')->count());
    }

    #[Test]
    public function the_old_id_stays_searchable_after_a_mistaken_registration_is_removed(): void
    {
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $player = Player::create(['firstname' => 'Sara', 'membership_id' => '201900077', 'join_year' => 2019]);
        $mistake = $player->registrations()->create([
            'branch_id' => $this->foot->id, 'membership_id' => '201900077', 'join_date' => '2019-05-01',
        ]);
        $player->registrations()->create([
            'branch_id' => $swim->id, 'membership_id' => 'SW-2020-00001', 'join_date' => '2020-01-10',
        ]);

        $this->assertNull(app(RegistrationService::class)->removeMistake($mistake));

        $remaining = $player->registrations()->first();
        $this->assertSame('201900077', $remaining->legacy_membership_id);
        $this->assertSame(1, Player::query()->search('201900077')->count());
    }
}
