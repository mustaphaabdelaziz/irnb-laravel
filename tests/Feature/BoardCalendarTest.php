<?php

namespace Tests\Feature;

use App\Models\BoardMeeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'approved' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function calendar_lists_meetings_for_the_month(): void
    {
        BoardMeeting::create([
            'title' => 'Monthly board',
            'type' => 'ordinary',
            'meeting_date' => now()->startOfMonth()->addDays(10)->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin())
            ->get(route('board.calendar', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Board/Calendar')
                ->where('month', now()->format('Y-m'))
                ->has('events', 1)
                ->where('events.0.kind', 'meeting')
                ->where('events.0.title', 'Monthly board'));
    }

    #[Test]
    public function admin_can_upload_and_remove_a_minutes_file(): void
    {
        Storage::fake('public');
        $meeting = BoardMeeting::create([
            'title' => 'AGM', 'type' => 'general_assembly',
            'meeting_date' => now(), 'status' => 'held',
        ]);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.attachment', $meeting), [
                'attachment' => UploadedFile::fake()->create('minutes.pdf', 200, 'application/pdf'),
            ])->assertRedirect();

        $meeting->refresh();
        $this->assertStringStartsWith('minutes/', (string) $meeting->attachment_filename);
        $this->assertStringStartsWith('/media/minutes/', (string) $meeting->attachment_url);
        Storage::disk('public')->assertExists($meeting->attachment_filename);

        $stored = $meeting->attachment_filename;

        $this->actingAs($this->admin())
            ->delete(route('board.meetings.attachment.delete', $meeting))
            ->assertRedirect();

        $meeting->refresh();
        $this->assertNull($meeting->attachment_url);
        Storage::disk('public')->assertMissing($stored);
    }

    #[Test]
    public function minutes_upload_rejects_an_executable(): void
    {
        Storage::fake('public');
        $meeting = BoardMeeting::create([
            'title' => 'Ordinary', 'type' => 'ordinary',
            'meeting_date' => now(), 'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin())
            ->post(route('board.meetings.attachment', $meeting), [
                'attachment' => UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream'),
            ])->assertSessionHasErrors('attachment');
    }
}
