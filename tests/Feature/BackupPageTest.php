<?php

namespace Tests\Feature;

use App\Jobs\CreateBackup;
use App\Models\User;
use App\Services\Backup\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeNativeSettings;
use Tests\TestCase;

class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::swap(new FakeNativeSettings);

        $this->destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-feat-'.uniqid();
        mkdir($this->destination, 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->destination);

        parent::tearDown();
    }

    private function desktop(): void
    {
        config(['nativephp-internal.running' => true]);
    }

    // Mirrors tests/Feature/RoleManagementTest.php:15-18 — `privileges` is an array,
    // and the `verified` middleware needs email_verified_at.
    private function superadmin(): User
    {
        return User::factory()->create([
            'privileges' => ['superadmin'],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function member(): User
    {
        return User::factory()->create([
            'privileges' => [],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function the_backup_page_does_not_exist_on_the_web_build(): void
    {
        config(['nativephp-internal.running' => false]);

        $this->actingAs($this->superadmin())
            ->get('/backups')
            ->assertNotFound();
    }

    #[Test]
    public function a_non_superadmin_cannot_reach_the_backup_page_on_desktop(): void
    {
        $this->desktop();

        $this->actingAs($this->member())
            ->get('/backups')
            ->assertForbidden();
    }

    #[Test]
    public function a_superadmin_sees_the_backup_page_with_its_settings(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination,
            'frequency' => 'weekly',
            'retention' => 5,
        ]);

        $this->actingAs($this->superadmin())
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Backups/Index')
                ->where('settings.enabled', true)
                ->where('settings.frequency', 'weekly')
                ->where('settings.retention', 5)
                ->where('destinationWritable', true)
                ->has('backups', 0));
    }

    #[Test]
    public function it_reports_an_unavailable_destination(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination.'-unplugged',
        ]);

        $this->actingAs($this->superadmin())
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('destinationWritable', false));
    }

    #[Test]
    public function settings_can_be_updated(): void
    {
        $this->desktop();

        $this->actingAs($this->superadmin())
            ->put('/backups/settings', [
                'enabled' => true,
                'frequency' => 'weekly',
                'retention' => 4,
            ])
            ->assertRedirect();

        $settings = app(BackupSettings::class)->all();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('weekly', $settings['frequency']);
        $this->assertSame(4, $settings['retention']);
    }

    #[Test]
    public function settings_reject_an_unknown_frequency(): void
    {
        $this->desktop();

        $this->actingAs($this->superadmin())
            ->put('/backups/settings', [
                'enabled' => true,
                'frequency' => 'hourly',
                'retention' => 4,
            ])
            ->assertSessionHasErrors('frequency');
    }

    #[Test]
    public function the_heartbeat_dispatches_a_backup_only_when_one_is_due(): void
    {
        $this->desktop();
        Queue::fake();

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination,
            'frequency' => 'every_launch',
        ]);

        $user = $this->superadmin();

        $this->actingAs($user)
            ->postJson('/backups/tick', ['trigger' => 'heartbeat'])
            ->assertOk()
            ->assertJson(['dispatched' => false]);

        Queue::assertNothingPushed();

        $this->actingAs($user)
            ->postJson('/backups/tick', ['trigger' => 'launch'])
            ->assertOk()
            ->assertJson(['dispatched' => true]);

        Queue::assertPushed(CreateBackup::class);
    }

    #[Test]
    public function the_heartbeat_does_nothing_when_automatic_backup_is_off(): void
    {
        $this->desktop();
        Queue::fake();

        app(BackupSettings::class)->put([
            'enabled' => false,
            'destination' => $this->destination,
        ]);

        $this->actingAs($this->superadmin())
            ->postJson('/backups/tick', ['trigger' => 'launch'])
            ->assertOk()
            ->assertJson(['dispatched' => false]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function restore_refuses_a_request_without_the_typed_confirmation(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put(['destination' => $this->destination]);

        $this->actingAs($this->superadmin())
            ->from('/backups')
            ->post('/backups/restore', [
                'name' => 'sport-club-backup-2026-07-01_100000.zip',
                'confirmation' => 'yes please',
            ])
            ->assertSessionHasErrors('confirmation');
    }
}
