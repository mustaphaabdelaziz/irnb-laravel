<?php

namespace Tests\Feature;

use App\Models\WebsiteConfig;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_apply_until_saved_and_other_settings_survive(): void
    {
        $this->assertSame(AttendanceSettings::DEFAULTS, AttendanceSettings::get());

        $config = WebsiteConfig::singleton();
        $config->settings = ['seasonStartMonth' => 9] + ($config->settings ?? []);
        $config->save();

        AttendanceSettings::save(['points' => ['late' => 0.5], 'alerts' => ['min_score_pct' => 70]]);

        $settings = AttendanceSettings::get();
        $this->assertSame(0.5, $settings['points']['late']);
        $this->assertSame(1, $settings['points']['present']);
        $this->assertSame(70, $settings['alerts']['min_score_pct']);
        $this->assertSame(9, WebsiteConfig::singleton()->settings['seasonStartMonth']);
    }
}
