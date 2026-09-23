<?php

namespace Tests\Feature;

use App\Jobs\CreateBackup;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\RestoreFailedAfterSwapException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Shell;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeNativeApp;
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

    /**
     * Stands BackupService in, so the controller's three restore outcomes can each be
     * driven on demand. The real service needs a live SQLite file, a media tree and a
     * genuinely un-renameable directory to produce them, and none of that says anything
     * about what the CONTROLLER does with the result — which is what is under test here.
     *
     * @param  \Closure(): ?string  $restore  returns the leftover media path, or throws
     */
    private function stubRestore(\Closure $restore): void
    {
        $stub = new class(app(BackupSettings::class), 'db.sqlite', 'media') extends BackupService
        {
            public \Closure $onRestore;

            /** The real one hits the filesystem; the name never has to exist here. */
            public function resolve(string $name): string
            {
                return 'C:\\backups\\'.$name;
            }

            public function restore(string $zipPath): ?string
            {
                return ($this->onRestore)();
            }
        };

        $stub->onRestore = $restore;

        $this->app->instance(BackupService::class, $stub);
    }

    private function postRestore(): TestResponse
    {
        return $this->actingAs($this->superadmin())
            ->from('/backups')
            ->post('/backups/restore', [
                'name' => 'sport-club-backup-2026-07-01_100000.zip',
                'confirmation' => 'restore',
            ]);
    }

    #[Test]
    public function a_restore_that_fails_past_the_swap_relaunches_and_keeps_the_snapshot_where_the_user_can_read_it(): void
    {
        // The single most important outcome in this feature, and the one whose message was
        // most likely to be lost. The database has ALREADY been replaced and the queue
        // worker ALREADY killed, so the app cannot be left running as it is — it must
        // relaunch even though the restore failed. And the message's entire job is to name
        // the pre-restore snapshot: the only way back to the club's data. Flashing it meant
        // racing the relaunch that is tearing the PHP process down before the session — and
        // with it the flash bag — is ever written.
        $this->desktop();

        $snapshot = 'C:\\backups\\pre-restore\\pre-restore-2026-07-13_100000.zip';

        $app = new FakeNativeApp;
        App::swap($app);

        $this->stubRestore(fn () => throw new RestoreFailedAfterSwapException(
            'The restore failed partway through. The database file is still in use. '
            .'Your previous data was saved first and is safe in: '.$snapshot,
            $snapshot,
        ));

        $this->postRestore()->assertRedirect('/backups');

        $this->assertSame(1, $app->relaunches, 'A failure PAST the swap must still relaunch the app.');

        $recorded = app(BackupSettings::class)->lastRestore();

        $this->assertSame('failed_after_swap', $recorded['outcome']);
        $this->assertSame($snapshot, $recorded['snapshot'], 'The banner must be able to name the snapshot.');
        $this->assertStringContainsString($snapshot, $recorded['message']);
        $this->assertNotNull($recorded['at']);
    }

    #[Test]
    public function a_successful_restore_that_leaves_media_behind_relaunches_and_names_the_leftover_folder(): void
    {
        // Success with a caveat: the restore worked, but the superseded media folder — a
        // full duplicate of every photo the club has — could not be deleted and nothing in
        // the app ever prunes it. restore() RETURNS that path rather than throwing, and the
        // user has to be told, in a message they can still read after the relaunch.
        $this->desktop();

        $leftover = 'C:\\Users\\club\\storage\\app\\public.old-2026-07-13_100000';

        $app = new FakeNativeApp;
        App::swap($app);

        $this->stubRestore(fn () => $leftover);

        $this->postRestore()->assertRedirect('/backups');

        $this->assertSame(1, $app->relaunches, 'A successful restore swaps the database, so it must relaunch.');

        $recorded = app(BackupSettings::class)->lastRestore();

        $this->assertSame('success', $recorded['outcome']);
        $this->assertSame($leftover, $recorded['leftover_media']);
        $this->assertStringContainsString($leftover, $recorded['message']);
        $this->assertStringContainsString('delete it by hand', $recorded['message']);
    }

    #[Test]
    public function a_restore_that_fails_before_the_swap_does_not_relaunch(): void
    {
        // The live data is untouched and the queue worker has been put back, so the app is
        // safe to keep using exactly as it is. Relaunching would throw the user out of the
        // application to recover from a restore that changed nothing at all.
        $this->desktop();

        $app = new FakeNativeApp;
        App::swap($app);

        $this->stubRestore(fn () => throw new RuntimeException(
            'That backup belongs to a different application. Nothing was changed — your data is exactly as it was.'
        ));

        $this->postRestore()->assertRedirect('/backups');

        $this->assertSame(0, $app->relaunches, 'A failure BEFORE the swap must not relaunch the app.');

        $recorded = app(BackupSettings::class)->lastRestore();

        $this->assertSame('failed', $recorded['outcome']);
        $this->assertNull($recorded['snapshot']);
        $this->assertStringContainsString('different application', $recorded['message']);
    }

    #[Test]
    public function the_page_carries_the_last_restore_outcome_and_can_dismiss_it(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put(['destination' => $this->destination]);
        app(BackupSettings::class)->recordRestore('failed_after_swap', 'Restore the snapshot.', 'C:\\snap.zip');

        $user = $this->superadmin();

        $this->actingAs($user)
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lastRestore.outcome', 'failed_after_swap')
                ->where('lastRestore.snapshot', 'C:\\snap.zip')
                // It is not part of the settings form — those are the values the user edits.
                ->missing('settings.last_restore'));

        $this->actingAs($user)
            ->from('/backups')
            ->delete('/backups/last-restore')
            ->assertRedirect('/backups');

        $this->assertNull(app(BackupSettings::class)->lastRestore());

        $this->actingAs($user)
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('lastRestore', null));
    }

    #[Test]
    public function deleting_a_backup_that_cannot_be_removed_is_reported_as_a_failure(): void
    {
        // On Windows a single open handle — antivirus, an Explorer preview, the indexer —
        // makes unlink() fail. Discarding its return value and flashing "deleted
        // successfully" tells the user the file is gone while it sits in the list.
        $this->desktop();

        app(BackupSettings::class)->put(['destination' => $this->destination]);

        $name = BackupService::PREFIX.'2026-07-01_100000.zip';
        $path = $this->destination.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, 'zip');

        // Make unlink() fail, on whichever platform this runs. On Windows the read-only
        // ATTRIBUTE is enough — DeleteFileW refuses it, which is the same refusal a file
        // held open by antivirus or an Explorer preview produces. On POSIX the permission
        // that governs deletion belongs to the directory, so that is what gets locked down.
        // Either way: unlink() fails and the backup is still sitting there afterwards,
        // which is all the controller can actually observe.
        $posix = DIRECTORY_SEPARATOR === '/';

        $posix ? chmod($this->destination, 0555) : chmod($path, 0444);

        try {
            $this->actingAs($this->superadmin())
                ->from('/backups')
                ->delete('/backups/'.$name)
                ->assertRedirect('/backups')
                ->assertSessionHas('error')
                ->assertSessionMissing('success');

            clearstatcache(true, $path);
            $this->assertFileExists($path, 'The file is still there — which is exactly what the user must be told.');
        } finally {
            $posix ? chmod($this->destination, 0755) : chmod($path, 0666);

            @unlink($path);
        }
    }

    #[Test]
    public function deleting_a_backup_that_really_goes_away_still_reports_success(): void
    {
        $this->desktop();

        app(BackupSettings::class)->put(['destination' => $this->destination]);

        $name = BackupService::PREFIX.'2026-07-02_100000.zip';
        $path = $this->destination.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, 'zip');

        $this->actingAs($this->superadmin())
            ->from('/backups')
            ->delete('/backups/'.$name)
            ->assertRedirect('/backups')
            ->assertSessionHas('success');

        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function revealing_a_backup_that_has_since_been_deleted_falls_back_to_the_folder(): void
    {
        // The user deletes the zip in Explorer with this page still open, then clicks
        // "Show in folder". resolve() throws for a file that is no longer there, and an
        // unhandled throw is an Inertia error modal on a button whose whole job is to open
        // a folder that is right there.
        $this->desktop();

        app(BackupSettings::class)->put(['destination' => $this->destination]);

        $shell = Shell::fake();

        $this->actingAs($this->superadmin())
            ->from('/backups')
            ->post('/backups/reveal', ['name' => BackupService::PREFIX.'2026-01-01_000000.zip'])
            ->assertRedirect('/backups')
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$this->destination],
            $shell->showInFolderCalls,
            'A stale name must fall back to the destination folder, not blow up in the user\'s face.',
        );
    }

    #[Test]
    public function heartbeats_while_the_queue_worker_is_dead_cannot_pile_up_into_a_backup_storm(): void
    {
        // tick() answers isDue() at DISPATCH time, but only the job's create() calls
        // markRun() — so nothing is marked as run until the job actually runs. With
        // QUEUE_CONNECTION=database the jobs persist on disk, and if the worker is dead
        // (exactly the state a pre-swap restore abort used to leave behind) every 30-minute
        // heartbeat enqueued another one. On the next launch the worker drained the lot:
        // each job runs create() then prune(), so N backups written seconds apart filled the
        // retention window and evicted every genuinely old backup. The backup feature ate
        // the user's backup history.
        $this->desktop();

        // The production queue, not the test default: a job that is dispatched but not run
        // is the entire premise, and `sync` runs it immediately.
        config(['queue.default' => 'database']);

        app(BackupSettings::class)->put([
            'enabled' => true,
            'destination' => $this->destination,
            'frequency' => 'daily',
        ]);

        $backups = new class(app(BackupSettings::class), 'db.sqlite', 'media') extends BackupService
        {
            public int $creates = 0;

            public function __construct(private BackupSettings $backupSettings, string $db, string $media)
            {
                parent::__construct($backupSettings, $db, $media);
            }

            public function create(): string
            {
                $this->creates++;

                // What the real create() does last, and what makes the next isDue() false.
                $this->backupSettings->markRun();

                return 'backup-'.$this->creates.'.zip';
            }
        };

        $this->app->instance(BackupService::class, $backups);

        $user = $this->superadmin();

        // Six heartbeats — three hours of an app left open with a dead worker.
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->postJson('/backups/tick', ['trigger' => 'heartbeat'])
                ->assertOk();
        }

        $this->assertSame(
            1,
            DB::table('jobs')->count(),
            'A backup that is already queued and has not run yet must not be enqueued again.',
        );

        // The worker comes back on the next launch and drains everything it finds.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $this->assertSame(
            1,
            $backups->creates,
            'Heartbeats that piled up while the worker was dead must produce AT MOST ONE backup — '
            .'not N backups in the same minute, which is enough to evict every real backup in the '
            .'retention window.',
        );

        $this->assertSame(0, DB::table('jobs')->count(), 'The queue must be drained.');
    }
}
