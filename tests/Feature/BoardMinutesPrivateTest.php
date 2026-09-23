<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardMinutesPrivateTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100004_move_meeting_minutes_to_private_disk.php';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function meeting(array $attributes = []): BoardMeeting
    {
        return BoardMeeting::create([
            'title' => 'AGM',
            'type' => 'general_assembly',
            'meeting_date' => now(),
            'status' => 'held',
            ...$attributes,
        ]);
    }

    private function upload(BoardMeeting $meeting, string $name = 'minutes.pdf'): void
    {
        $this->actingAs($this->admin())->post(route('board.meetings.attachment', $meeting), [
            'attachment' => UploadedFile::fake()->create($name, 200, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    #[Test]
    public function uploaded_minutes_go_to_the_private_disk(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        $meeting->refresh();
        $this->assertStringStartsWith('minutes/', $meeting->attachment_filename);
        Storage::disk('local')->assertExists($meeting->attachment_filename);
        Storage::disk('public')->assertMissing($meeting->attachment_filename);
        $this->assertNull($meeting->getAttributes()['attachment_url']);
        $this->assertSame(route('board.meetings.attachment.show', $meeting, false), $meeting->attachment_url);
    }

    #[Test]
    public function the_minutes_open_inline_for_a_board_viewer(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        $response = $this->actingAs($this->admin())->get(route('board.meetings.attachment.show', $meeting));

        $response->assertOk();
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function the_minutes_need_a_login_and_board_rights(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        // Back to a guest.
        auth()->forgetGuards();
        $this->get(route('board.meetings.attachment.show', $meeting))->assertRedirect(route('login'));

        $coach = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'coach', 'name' => ['en' => 'Coach'], 'permissions' => ['players' => Role::ACTIONS]])->id,
        ]);
        $this->actingAs($coach)->get(route('board.meetings.attachment.show', $meeting))->assertForbidden();

        $this->assertSame(['board', 'view'], PermissionMap::resolve('board.meetings.attachment.show'));
    }

    #[Test]
    public function the_public_media_route_no_longer_serves_minutes(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting);

        $this->get('/media/'.$meeting->fresh()->attachment_filename)->assertNotFound();
    }

    #[Test]
    public function a_meeting_without_minutes_has_nothing_to_show(): void
    {
        $this->actingAs($this->admin())
            ->get(route('board.meetings.attachment.show', $this->meeting()))
            ->assertNotFound();
    }

    #[Test]
    public function replacing_the_minutes_removes_the_previous_file(): void
    {
        $meeting = $this->meeting();
        $this->upload($meeting, 'first.pdf');
        $first = $meeting->fresh()->attachment_filename;

        $this->upload($meeting, 'second.pdf');

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($meeting->fresh()->attachment_filename);
    }

    #[Test]
    public function the_migration_moves_existing_minutes_off_the_public_disk(): void
    {
        Storage::disk('public')->put('minutes/old.pdf', 'OLD-MINUTES');
        Storage::disk('public')->put('minutes/legacy.pdf', 'LEGACY-MINUTES');

        $current = $this->meeting(['attachment_url' => '/media/minutes/old.pdf', 'attachment_filename' => 'minutes/old.pdf']);
        // An old row that only kept an absolute URL.
        $legacy = $this->meeting(['attachment_url' => 'http://localhost:8000/storage/minutes/legacy.pdf', 'attachment_filename' => null]);
        // A row whose file is already gone from every disk.
        $gone = $this->meeting(['attachment_url' => '/media/minutes/gone.pdf', 'attachment_filename' => 'minutes/gone.pdf']);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        // Desktop: a re-run after a partial failure must be harmless.
        $migration->up();

        $this->assertSame('OLD-MINUTES', Storage::disk('local')->get('minutes/old.pdf'));
        $this->assertSame('LEGACY-MINUTES', Storage::disk('local')->get('minutes/legacy.pdf'));
        Storage::disk('public')->assertMissing('minutes/old.pdf');
        Storage::disk('public')->assertMissing('minutes/legacy.pdf');

        $rows = DB::table('board_meetings')->get()->keyBy('id');
        $this->assertSame('minutes/old.pdf', $rows[$current->id]->attachment_filename);
        $this->assertNull($rows[$current->id]->attachment_url);
        $this->assertSame('minutes/legacy.pdf', $rows[$legacy->id]->attachment_filename);
        $this->assertNull($rows[$legacy->id]->attachment_url);
        $this->assertSame('minutes/gone.pdf', $rows[$gone->id]->attachment_filename);
        $this->assertSame('/media/minutes/gone.pdf', $rows[$gone->id]->attachment_url);
    }

    #[Test]
    public function the_migration_finishes_a_copy_that_an_earlier_run_left_half_done(): void
    {
        Storage::disk('public')->put('minutes/old.pdf', 'OLD-MINUTES');
        Storage::disk('local')->put('minutes/old.pdf', 'OLD-'); // interrupted copy
        $meeting = $this->meeting(['attachment_url' => '/media/minutes/old.pdf', 'attachment_filename' => 'minutes/old.pdf']);

        (require database_path(self::MIGRATION))->up();

        $this->assertSame('OLD-MINUTES', Storage::disk('local')->get('minutes/old.pdf'));
        Storage::disk('public')->assertMissing('minutes/old.pdf');
        $this->assertNull(DB::table('board_meetings')->where('id', $meeting->id)->value('attachment_url'));
    }
}
