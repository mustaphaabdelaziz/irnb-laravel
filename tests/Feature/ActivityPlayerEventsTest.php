<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityPlayerEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makePlayer(bool $archived = false): Player
    {
        return Player::create([
            'membership_id' => '8'.str_pad((string) ++$this->seq, 9, '0', STR_PAD_LEFT),
            'firstname' => 'P'.$this->seq,
            'lastname' => 'Test',
            'is_student' => true,
            'outstanding_debt' => 0,
            'archived' => $archived,
        ]);
    }

    /** Same builder as PlayerImportTest: a 19-column header row, then the data rows. */
    private function makeCsv(array $dataRows): UploadedFile
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 19, 'header'));
        foreach ($dataRows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'players.csv', 'text/csv', null, true);
    }

    private function row(string $firstname): array
    {
        return [$firstname, 'Brahimi', '', '', '', '2008-05-20', 'Male', '', '', 'Algiers', 'Algiers', '', '', '', 'student', '6', '', '', (string) now()->year];
    }

    /** @return Collection<int, ActivityLog> */
    private function events(string $action)
    {
        return ActivityLog::query()->where('action', $action)->orderBy('id')->get();
    }

    #[Test]
    public function registering_a_player_records_player_registered(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('players.store'), ['firstname' => 'Amine', 'lastname' => 'Test'])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $events = $this->events(ActivityAction::PLAYER_REGISTERED);
        $this->assertCount(1, $events);
        $this->assertSame($admin->id, $events[0]->user_id);
        $this->assertSame($player->getMorphClass(), $events[0]->subject_type);
        $this->assertSame($player->id, (int) $events[0]->subject_id);
    }

    #[Test]
    public function an_import_records_one_summary_event_and_no_registrations(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('players.import.store'), ['file' => $this->makeCsv([$this->row('Ali'), $this->row('Omar')])])
            ->assertRedirect();

        $this->assertSame(2, Player::query()->count());
        $this->assertCount(0, $this->events(ActivityAction::PLAYER_REGISTERED));
        $events = $this->events(ActivityAction::PLAYER_IMPORTED);
        $this->assertCount(1, $events);
        $this->assertSame($admin->id, $events[0]->user_id);
        $this->assertNull($events[0]->subject_type);
        $this->assertSame(2, $events[0]->properties['count']);
    }

    #[Test]
    public function an_import_of_no_valid_rows_records_nothing(): void
    {
        $blank = array_fill(0, 19, '');

        $this->actingAs($this->admin())
            ->post(route('players.import.store'), ['file' => $this->makeCsv([$blank])])
            ->assertRedirect();

        $this->assertSame(0, Player::query()->count());
        $this->assertSame(0, ActivityLog::query()->count());
    }

    #[Test]
    public function archiving_one_player_records_player_archived(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();

        $this->actingAs($admin)->delete(route('players.destroy', $player))->assertRedirect();

        $events = $this->events(ActivityAction::PLAYER_ARCHIVED);
        $this->assertCount(1, $events);
        $this->assertSame($admin->id, $events[0]->user_id);
        $this->assertSame($player->id, (int) $events[0]->subject_id);
    }

    #[Test]
    public function bulk_archive_records_only_the_players_it_actually_archived(): void
    {
        $a = $this->makePlayer();
        $b = $this->makePlayer();
        $already = $this->makePlayer(archived: true);

        $this->actingAs($this->admin())
            ->post(route('players.bulkArchive'), ['ids' => [$a->id, $b->id, $already->id]])
            ->assertRedirect();

        $events = $this->events(ActivityAction::PLAYER_ARCHIVED);
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $events->pluck('subject_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(3, Player::query()->where('archived', true)->count());
    }

    #[Test]
    public function restoring_a_player_records_nothing(): void
    {
        $player = $this->makePlayer(archived: true);

        $this->actingAs($this->admin())->post(route('players.bulkRestore'), ['ids' => [$player->id]])->assertRedirect();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    #[Test]
    public function creating_a_job_from_settings_records_job_created(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('jobs.store'), ['name' => 'Menuisier'])->assertSessionHasNoErrors();

        $job = MemberJob::query()->firstOrFail();
        $events = $this->events(ActivityAction::JOB_CREATED);
        $this->assertCount(1, $events);
        $this->assertSame($admin->id, $events[0]->user_id);
        $this->assertSame($job->getMorphClass(), $events[0]->subject_type);
        $this->assertSame($job->id, (int) $events[0]->subject_id);
    }

    #[Test]
    public function quick_creating_a_job_records_job_created(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'Menuisier'])
            ->assertCreated();

        $events = $this->events(ActivityAction::JOB_CREATED);
        $this->assertCount(1, $events);
        $this->assertSame($response->json('job.id'), (int) $events[0]->subject_id);
    }

    #[Test]
    public function a_duplicate_job_records_nothing(): void
    {
        MemberJob::create(['name' => 'Menuisier', 'name_fr' => 'Menuisier']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('jobs.quick.store'), ['name' => 'MENUISIER ', 'name_fr' => 'menuisier'])
            ->assertStatus(409);
        $this->actingAs($admin)
            ->post(route('jobs.store'), ['name' => 'menuisiér'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, MemberJob::query()->count());
        $this->assertCount(0, $this->events(ActivityAction::JOB_CREATED));
    }

    #[Test]
    public function adding_an_academic_record_records_academic_record_added(): void
    {
        $admin = $this->admin();
        $player = $this->makePlayer();

        $this->actingAs($admin)->post(route('players.academic-records.store', $player), [
            'academic_year' => 2025,
            'period' => 'T1',
            'gpa' => 12.5,
            'education_level' => 'secondary',
        ])->assertSessionHasNoErrors();

        $record = PlayerAcademicRecord::query()->firstOrFail();
        $events = $this->events(ActivityAction::ACADEMIC_RECORD_ADDED);
        $this->assertCount(1, $events);
        $this->assertSame($admin->id, $events[0]->user_id);
        $this->assertSame($record->getMorphClass(), $events[0]->subject_type);
        $this->assertSame($record->id, (int) $events[0]->subject_id);

        // Editing a grade records nothing more.
        $this->actingAs($admin)->put(route('players.academic-records.update', [$player, $record]), [
            'academic_year' => 2025,
            'period' => 'T1',
            'gpa' => 14,
        ]);
        $this->assertSame(1, ActivityLog::query()->count());
    }
}
