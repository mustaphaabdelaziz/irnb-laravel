<?php

namespace Tests\Unit\Services;

use App\Services\Backup\BackupSettings;
use Illuminate\Support\Carbon;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;

class BackupSettingsTest extends TestCase
{
    private BackupSettings $settings;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-dest-'.uniqid();
        mkdir($this->dir, 0777, true);

        $this->settings = new BackupSettings;
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function it_returns_sane_defaults_when_nothing_is_stored(): void
    {
        $this->assertSame([
            'enabled' => false,
            'destination' => null,
            'frequency' => 'daily',
            'retention' => 10,
            'last_run_at' => null,
        ], $this->settings->all());
    }

    #[Test]
    public function it_merges_partial_updates_and_normalizes_values(): void
    {
        $this->settings->put(['enabled' => true, 'retention' => 3]);
        $this->settings->put(['destination' => $this->dir]);

        $all = $this->settings->all();

        $this->assertTrue($all['enabled']);
        $this->assertSame(3, $all['retention']);
        $this->assertSame($this->dir, $all['destination']);
        $this->assertSame('daily', $all['frequency']);
    }

    #[Test]
    public function it_rejects_an_unknown_frequency_and_a_negative_retention(): void
    {
        $this->settings->put(['frequency' => 'hourly', 'retention' => -5]);

        $all = $this->settings->all();

        $this->assertSame('daily', $all['frequency']);
        $this->assertSame(0, $all['retention']);
    }

    #[Test]
    public function it_is_never_due_when_disabled_or_without_a_writable_destination(): void
    {
        $this->settings->put(['enabled' => false, 'destination' => $this->dir]);
        $this->assertFalse($this->settings->isDue('launch'));

        $this->settings->put(['enabled' => true, 'destination' => null]);
        $this->assertFalse($this->settings->isDue('launch'));

        $this->settings->put(['enabled' => true, 'destination' => $this->dir.'-gone']);
        $this->assertFalse($this->settings->isDue('launch'));
    }

    #[Test]
    public function every_launch_is_due_on_launch_but_not_on_a_heartbeat(): void
    {
        $this->settings->put([
            'enabled' => true,
            'destination' => $this->dir,
            'frequency' => 'every_launch',
        ]);

        $this->assertTrue($this->settings->isDue('launch'));
        $this->assertFalse($this->settings->isDue('heartbeat'));
    }

    #[Test]
    public function daily_is_due_once_a_day_regardless_of_trigger(): void
    {
        $this->settings->put([
            'enabled' => true,
            'destination' => $this->dir,
            'frequency' => 'daily',
        ]);

        // Never run before -> due.
        $this->assertTrue($this->settings->isDue('heartbeat'));

        Carbon::setTestNow('2026-07-13 10:00:00');
        $this->settings->markRun();

        Carbon::setTestNow('2026-07-13 20:00:00');
        $this->assertFalse($this->settings->isDue('heartbeat'));

        Carbon::setTestNow('2026-07-14 10:01:00');
        $this->assertTrue($this->settings->isDue('heartbeat'));

        Carbon::setTestNow();
    }

    #[Test]
    public function weekly_is_due_once_a_week(): void
    {
        $this->settings->put([
            'enabled' => true,
            'destination' => $this->dir,
            'frequency' => 'weekly',
        ]);

        Carbon::setTestNow('2026-07-13 10:00:00');
        $this->settings->markRun();

        Carbon::setTestNow('2026-07-18 10:00:00');
        $this->assertFalse($this->settings->isDue('heartbeat'));

        Carbon::setTestNow('2026-07-20 10:01:00');
        $this->assertTrue($this->settings->isDue('heartbeat'));

        Carbon::setTestNow();
    }
}
