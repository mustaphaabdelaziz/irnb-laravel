<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeasonTest extends TestCase
{
    use RefreshDatabase;

    private function startMonth(int $month): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'seasonStartMonth' => $month]]);
    }

    #[Test]
    public function the_season_starts_in_september_unless_the_club_says_otherwise(): void
    {
        $this->assertSame(9, Season::startMonth());
    }

    #[Test]
    public function a_date_before_the_start_month_belongs_to_the_previous_season(): void
    {
        $this->startMonth(9);

        $this->assertSame(2025, Season::forDate('2026-08-31')->startYear);
        $this->assertSame(2026, Season::forDate('2026-09-01')->startYear);
    }

    #[Test]
    public function a_season_knows_its_bounds_and_label(): void
    {
        $this->startMonth(9);
        $season = Season::forDate('2026-10-05');

        $this->assertSame('2026-09-01', $season->start()->toDateString());
        $this->assertSame('2027-08-31', $season->end()->toDateString());
        $this->assertSame('2026/27', $season->label());
        $this->assertTrue($season->contains('2027-03-01'));
        $this->assertFalse($season->contains('2027-09-01'));
    }

    #[Test]
    public function a_january_start_makes_the_season_a_plain_calendar_year(): void
    {
        $this->startMonth(1);
        $season = Season::forDate('2026-05-05');

        $this->assertSame('2026-01-01', $season->start()->toDateString());
        $this->assertSame('2026-12-31', $season->end()->toDateString());
        $this->assertSame('2026', $season->label());
    }

    #[Test]
    public function an_impossible_start_month_falls_back_to_september(): void
    {
        $this->startMonth(13);

        $this->assertSame(9, Season::startMonth());
    }

    #[Test]
    public function the_settings_form_stores_both_file_settings(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['seasonStartMonth' => 7, 'fileDrawerSize' => 250]])
            ->assertRedirect();

        $settings = WebsiteConfig::singleton()->fresh()->settings;
        $this->assertSame(7, $settings['seasonStartMonth']);
        $this->assertSame(250, $settings['fileDrawerSize']);
        // The merge must not drop the keys the other tabs own.
        $this->assertArrayHasKey('currency', $settings);
    }

    #[Test]
    public function an_out_of_range_setting_is_rejected(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['seasonStartMonth' => 13]])
            ->assertSessionHasErrors('settings.seasonStartMonth');

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['fileDrawerSize' => 0]])
            ->assertSessionHasErrors('settings.fileDrawerSize');
    }
}
