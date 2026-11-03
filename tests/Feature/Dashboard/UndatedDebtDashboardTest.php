<?php

namespace Tests\Feature\Dashboard;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\User;
use App\Services\Dashboard\DashboardFilters;
use App\Services\Dashboard\OverviewStats;
use App\Services\Dashboard\Support\EffectiveDueDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** An unpaid line with no due date falls due when its season ends, on every database. */
class UndatedDebtDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function undatedDebt(): void
    {
        $player = Player::create(['membership_id' => '202500001', 'firstname' => 'A', 'lastname' => 'B']);

        PlayerSubscription::create([
            'player_id' => $player->id,
            'year' => 2025,
            'amount_owed' => 1000,
            'amount_paid' => 0,
            'due_date' => null,
        ]);
    }

    private function aging(): array
    {
        $overview = app(OverviewStats::class)->get(
            DashboardFilters::fromRequest(Request::create('/dashboard')),
        );

        return collect($overview['debtAging'])->keyBy('bucket')->all();
    }

    #[Test]
    public function the_dashboard_opens_for_a_finance_user(): void
    {
        $this->travelTo('2026-05-14 10:00:00');
        $this->undatedDebt();

        $this->actingAs($this->admin())->get('/dashboard')->assertOk();
    }

    #[Test]
    public function the_debt_ages_from_the_end_of_its_season(): void
    {
        // The default season ends on 31 August: 2025-08-31 is over 90 days before this date.
        $this->travelTo('2026-05-14 10:00:00');
        $this->undatedDebt();

        $aging = $this->aging();

        $this->assertEquals(1000, $aging['90+']['amount']);
        $this->assertEquals(0, $aging['0-30']['amount']);
    }

    #[Test]
    public function the_debt_is_still_recent_just_after_its_season_ends(): void
    {
        $this->travelTo('2025-09-10 10:00:00');
        $this->undatedDebt();

        $this->assertEquals(1000, $this->aging()['0-30']['amount']);
    }

    /** The test suite runs on SQLite, so a bare || would pass here and break on MySQL. */
    #[Test]
    public function each_driver_gets_its_own_way_of_joining_text(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            $sql = EffectiveDueDate::forDriver($driver, '-08-31');
            $this->assertStringContainsString("CONCAT(player_subscriptions.year, '-08-31')", $sql);
            $this->assertStringNotContainsString('||', $sql);
        }

        $this->assertStringContainsString(
            "CAST(player_subscriptions.year || '-08-31' AS date)",
            EffectiveDueDate::forDriver('pgsql', '-08-31'),
        );
    }

    /** MySQL and MariaDB reserve OUT, so this alias fails there and passes on SQLite. */
    #[Test]
    public function no_dashboard_query_uses_a_reserved_word_as_an_alias(): void
    {
        foreach (glob(app_path('Services/Dashboard/*.php')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/\bas out\b|\br\.out\b/', file_get_contents($file), basename($file));
        }
    }
}
