<?php

namespace Tests\Feature;

use App\Services\Backup\PreMigrationBackup;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * After an update the desktop shell runs `artisan migrate --force` in its own
 * process before the app's `booted` hook fires — so the backup must hook the
 * migrate command itself, or the copy is taken of the already-migrated file.
 */
class BackupBeforeMigratingTest extends TestCase
{
    private function expectBackups(int $times): void
    {
        $backup = Mockery::mock(PreMigrationBackup::class);
        $backup->shouldReceive('run')->times($times)->andReturn(null);
        $this->app->instance(PreMigrationBackup::class, $backup);
    }

    private function starting(string $command): void
    {
        event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
    }

    #[Test]
    public function the_desktop_app_backs_up_before_migrate_runs(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->expectBackups(1);

        $this->starting('migrate');
    }

    /** Laravel routes console events to CommandStarting everywhere but unit tests. */
    private function routeConsoleEvents(): void
    {
        $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
    }

    #[Test]
    public function migrate_called_from_the_app_backs_up_once(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->routeConsoleEvents();
        $this->expectBackups(1);

        Artisan::call('migrate', ['--force' => true]);
    }

    #[Test]
    public function a_failed_backup_stops_the_migration(): void
    {
        // Symfony runs the command only after the COMMAND listeners return, so
        // the failure surfacing here means migrate never started.
        config(['nativephp-internal.running' => true]);
        $this->routeConsoleEvents();
        $backup = Mockery::mock(PreMigrationBackup::class);
        $backup->shouldReceive('run')->once()->andThrow(new RuntimeException('disk full'));
        $this->app->instance(PreMigrationBackup::class, $backup);

        $this->expectExceptionMessage('disk full');

        Artisan::call('migrate', ['--force' => true]);
    }

    #[Test]
    public function other_commands_do_not_back_up(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->expectBackups(0);

        $this->starting('migrate:status');
        $this->starting('optimize');
    }

    #[Test]
    public function the_web_app_does_not_back_up(): void
    {
        config(['nativephp-internal.running' => false]);
        $this->expectBackups(0);

        $this->starting('migrate');
    }
}
