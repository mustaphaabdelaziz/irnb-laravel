<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dates reach the screens on the club's clock. A `date` attribute is midnight
 * in Algiers, which is still the previous day in UTC: sent as UTC, every edit
 * form that keeps the date part saved the day before, on every save.
 */
class ClubDateSerializationTest extends TestCase
{
    use RefreshDatabase;

    /** What the edit forms do with a server date: keep the part before the "T". */
    private function datePart(?string $value): string
    {
        return substr((string) $value, 0, 10);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function a_birthdate_survives_an_unchanged_edit_form(): void
    {
        $player = Player::create(['membership_id' => '202600001', 'firstname' => 'Ali', 'lastname' => 'Benali', 'birthdate' => '2010-05-01']);
        $admin = $this->admin();
        $sent = null;

        $this->actingAs($admin)->get(route('players.edit', $player))
            ->assertInertia(function (AssertableInertia $page) use (&$sent) {
                $sent = $page->toArray()['props']['player']['birthdate'];
            });

        $this->assertSame('2010-05-01', $this->datePart($sent));

        $this->actingAs($admin)->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'Benali', 'birthdate' => $this->datePart($sent),
        ])->assertRedirect();

        $this->assertSame('2010-05-01', $player->fresh()->birthdate->toDateString());
    }

    #[Test]
    public function the_founding_date_round_trips_through_settings(): void
    {
        WebsiteConfig::singleton()->update(['founding_date' => '1990-03-15']);
        $admin = $this->admin();
        $sent = null;

        $this->actingAs($admin)->get(route('settings.show'))
            ->assertInertia(function (AssertableInertia $page) use (&$sent) {
                $sent = $page->toArray()['props']['config']['founding_date'];
            });

        $this->assertSame('1990-03-15', $this->datePart($sent));

        $this->actingAs($admin)->put(route('settings.update'), ['founding_date' => $this->datePart($sent)])
            ->assertSessionHasNoErrors();

        $this->assertSame('1990-03-15', WebsiteConfig::singleton()->founding_date->toDateString());
    }

    #[Test]
    public function a_debt_due_date_reaches_the_player_page_on_its_own_day(): void
    {
        $player = Player::create(['membership_id' => '202600002', 'firstname' => 'Sara', 'lastname' => 'Amari', 'is_student' => true]);
        PlayerSubscription::create([
            'player_id' => $player->id, 'year' => 2027, 'status_at_time' => 'student', 'is_mandatory' => false,
            'amount_owed' => 1000, 'amount_paid' => 0, 'label' => 'Kit', 'due_date' => '2026-12-31',
        ]);

        $this->actingAs($this->admin())->get(route('players.show', $player))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('player.player_subscriptions.0.due_date', fn ($value) => $this->datePart($value) === '2026-12-31'));
    }

    #[Test]
    public function dates_are_sent_with_the_club_offset(): void
    {
        $date = Carbon::parse('2010-05-01');

        // A model attribute and a bare Carbon in a JSON payload read alike.
        $this->assertSame('2010-05-01T00:00:00.000000+01:00', Player::make(['birthdate' => '2010-05-01'])->toArray()['birthdate']);
        $this->assertSame('{"d":"2010-05-01T00:00:00.000000+01:00"}', json_encode(['d' => $date]));
        $this->assertSame('{"d":"2010-05-01T00:00:00.000000+01:00"}', json_encode(['d' => $date->toImmutable()]));
    }
}
