<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BoardMeeting;
use App\Models\BoardTask;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityBoardDocumentEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    /** Same fixture as BoardMeetingCancellationTest. */
    private function meeting(array $attributes = []): BoardMeeting
    {
        return BoardMeeting::create(array_merge([
            'title' => 'Monthly board',
            'type' => 'ordinary',
            'meeting_date' => now()->addWeek(),
            'status' => 'scheduled',
        ], $attributes));
    }

    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Book the hall',
            'status' => 'not_started',
            'priority' => 'medium',
        ], $overrides);
    }

    /** Same fixtures as PlayerDocumentActionsTest. */
    private function player(): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20269'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Amine',
            'lastname' => 'Saadi',
            'birthdate' => '1995-04-04',
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function pdf(string $name = 'scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 200, 'application/pdf');
    }

    private function markReceived(User $as, Player $player, string $code, array $extra = [])
    {
        return $this->actingAs($as)->post(route('players.documents.store', $player), [
            'document_type_id' => $this->type($code)->id,
            'received_at' => '2026-10-05',
            ...$extra,
        ]);
    }

    /** @return Collection<int, ActivityLog> */
    private function events(string $action): Collection
    {
        return ActivityLog::query()->where('action', $action)->orderBy('id')->get();
    }

    private function assertEvent(ActivityLog $event, User $actor, object $subject, array $properties = []): void
    {
        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame($subject->getMorphClass(), $event->subject_type);
        $this->assertSame($subject->getKey(), (int) $event->subject_id);
        $this->assertEqualsCanonicalizing(array_keys($properties), array_keys($event->properties ?? []));
        foreach ($properties as $key => $value) {
            $this->assertEquals($value, $event->properties[$key], $key);
        }
    }

    // ── Meetings ─────────────────────────────────────────────────────

    #[Test]
    public function creating_a_meeting_records_meeting_created(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('board.meetings.store'), [
            'title' => 'Monthly board',
            'type' => 'ordinary',
            'meeting_date' => '2026-10-12',
            'status' => 'scheduled',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $meeting = BoardMeeting::sole();
        $this->assertCount(1, $this->events(ActivityAction::MEETING_CREATED));
        $this->assertEvent($this->events(ActivityAction::MEETING_CREATED)->first(), $admin, $meeting);
    }

    #[Test]
    public function an_invalid_meeting_records_nothing(): void
    {
        $this->actingAs($this->admin())->post(route('board.meetings.store'), ['title' => ''])
            ->assertSessionHasErrors('title');

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function cancelling_a_meeting_records_meeting_cancelled(): void
    {
        $admin = $this->admin();
        $meeting = $this->meeting();

        $this->actingAs($admin)->post(route('board.meetings.cancel', $meeting), ['reason' => 'Hall unavailable'])
            ->assertSessionHas('success', 'flash.meeting_cancelled');

        $this->assertCount(1, $this->events(ActivityAction::MEETING_CANCELLED));
        $this->assertEvent($this->events(ActivityAction::MEETING_CANCELLED)->first(), $admin, $meeting);
    }

    #[Test]
    public function a_refused_cancellation_records_nothing(): void
    {
        $meeting = $this->meeting(['status' => 'held']);

        $this->actingAs($this->admin())->post(route('board.meetings.cancel', $meeting), ['reason' => 'Too late'])
            ->assertSessionHas('error', 'flash.meeting_not_cancellable');

        $this->assertSame(0, ActivityLog::count());
    }

    // ── Tasks ────────────────────────────────────────────────────────

    #[Test]
    public function creating_a_task_records_task_created_only(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('board.tasks.store'), $this->taskPayload())
            ->assertSessionHasNoErrors();

        $task = BoardTask::sole();
        $this->assertCount(1, $this->events(ActivityAction::TASK_CREATED));
        $this->assertEvent($this->events(ActivityAction::TASK_CREATED)->first(), $admin, $task);
        $this->assertCount(0, $this->events(ActivityAction::TASK_COMPLETED));
    }

    #[Test]
    public function a_task_created_already_completed_records_both_events(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('board.tasks.store'), $this->taskPayload(['status' => 'completed']))
            ->assertSessionHasNoErrors();

        $task = BoardTask::sole();
        $this->assertCount(1, $this->events(ActivityAction::TASK_CREATED));
        $this->assertCount(1, $this->events(ActivityAction::TASK_COMPLETED));
        $this->assertEvent($this->events(ActivityAction::TASK_COMPLETED)->first(), $admin, $task);
    }

    #[Test]
    public function completing_a_task_records_task_completed_once(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('board.tasks.store'), $this->taskPayload(['status' => 'in_progress']));
        $task = BoardTask::sole();

        $this->actingAs($admin)->put(route('board.tasks.update', $task), $this->taskPayload(['status' => 'completed']))
            ->assertSessionHas('success', 'flash.task_updated');

        $this->assertCount(1, $this->events(ActivityAction::TASK_COMPLETED));
        $this->assertEvent($this->events(ActivityAction::TASK_COMPLETED)->first(), $admin, $task);

        // Re-saving an already completed task (e.g. editing its title) records nothing.
        $this->actingAs($admin)->put(route('board.tasks.update', $task), $this->taskPayload(['status' => 'completed', 'title' => 'Book the big hall']))
            ->assertSessionHas('success', 'flash.task_updated');

        $this->assertCount(1, $this->events(ActivityAction::TASK_COMPLETED));
    }

    #[Test]
    public function reopening_and_completing_again_records_a_second_completion(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('board.tasks.store'), $this->taskPayload(['status' => 'completed']));
        $task = BoardTask::sole();

        $this->actingAs($admin)->put(route('board.tasks.update', $task), $this->taskPayload(['status' => 'in_progress']));
        $this->assertCount(1, $this->events(ActivityAction::TASK_COMPLETED));

        $this->actingAs($admin)->put(route('board.tasks.update', $task), $this->taskPayload(['status' => 'completed']));
        $this->assertCount(2, $this->events(ActivityAction::TASK_COMPLETED));
    }

    #[Test]
    public function editing_or_deleting_an_open_task_records_nothing(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('board.tasks.store'), $this->taskPayload());
        $task = BoardTask::sole();

        $this->actingAs($admin)->put(route('board.tasks.update', $task), $this->taskPayload(['status' => 'in_progress', 'progress' => 40]));
        $this->actingAs($admin)->delete(route('board.tasks.destroy', $task));

        $this->assertSame([ActivityAction::TASK_CREATED], ActivityLog::query()->pluck('action')->all());
    }

    // ── Documents ────────────────────────────────────────────────────

    #[Test]
    public function receiving_a_document_without_files_records_document_received_only(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->markReceived($admin, $player, 'medical_certificate')
            ->assertSessionHas('success', 'flash.document_received');

        $document = $player->documents()->firstOrFail();
        $this->assertCount(1, $this->events(ActivityAction::DOCUMENT_RECEIVED));
        $this->assertEvent($this->events(ActivityAction::DOCUMENT_RECEIVED)->first(), $admin, $document);
        $this->assertCount(0, $this->events(ActivityAction::DOCUMENT_FILE_UPLOADED));
    }

    #[Test]
    public function receiving_with_two_files_records_one_received_and_one_upload_with_count_2(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->markReceived($admin, $player, 'birth_certificate', ['files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]])
            ->assertSessionHas('success', 'flash.document_received');

        $document = $player->documents()->firstOrFail();
        $this->assertCount(1, $this->events(ActivityAction::DOCUMENT_RECEIVED));
        $uploads = $this->events(ActivityAction::DOCUMENT_FILE_UPLOADED);
        $this->assertCount(1, $uploads);
        $this->assertEvent($uploads->first(), $admin, $document, ['count' => 2]);
    }

    #[Test]
    public function a_refused_receipt_records_nothing(): void
    {
        $admin = $this->admin();
        $player = $this->player();
        $this->markReceived($admin, $player, 'birth_certificate');
        ActivityLog::query()->delete();

        $this->markReceived($admin, $player, 'birth_certificate', ['files' => [$this->pdf()]])
            ->assertSessionHas('error', 'flash.document_already_recorded');

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function renewing_records_document_renewed_and_the_upload(): void
    {
        $admin = $this->admin();
        $player = $this->player();
        $this->markReceived($admin, $player, 'medical_certificate', ['received_at' => '2025-10-01']);
        $document = $player->documents()->firstOrFail();

        $this->actingAs($admin)->put(route('players.documents.update', [$player, $document]), [
            'received_at' => '2026-10-05',
            'files' => [$this->pdf('2026.pdf')],
        ])->assertSessionHas('success', 'flash.document_renewed');

        $this->assertCount(1, $this->events(ActivityAction::DOCUMENT_RENEWED));
        $this->assertEvent($this->events(ActivityAction::DOCUMENT_RENEWED)->first(), $admin, $document);
        $uploads = $this->events(ActivityAction::DOCUMENT_FILE_UPLOADED);
        $this->assertCount(1, $uploads);
        $this->assertEvent($uploads->first(), $admin, $document, ['count' => 1]);
    }

    #[Test]
    public function renewing_without_files_records_no_upload(): void
    {
        $admin = $this->admin();
        $player = $this->player();
        $this->markReceived($admin, $player, 'medical_certificate', ['received_at' => '2025-10-01']);
        $document = $player->documents()->firstOrFail();

        $this->actingAs($admin)->put(route('players.documents.update', [$player, $document]), [
            'received_at' => '2026-10-05',
        ])->assertSessionHas('success', 'flash.document_renewed');

        $this->assertCount(1, $this->events(ActivityAction::DOCUMENT_RENEWED));
        $this->assertCount(0, $this->events(ActivityAction::DOCUMENT_FILE_UPLOADED));
    }

    #[Test]
    public function exempting_records_document_exempted(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->actingAs($admin)->post(route('players.documents.exempt', $player), [
            'document_type_id' => $this->type('medical_certificate')->id,
            'reason' => 'Checked by the federation doctor',
        ])->assertSessionHas('success', 'flash.document_exempted');

        $document = $player->documents()->firstOrFail();
        $events = $this->events(ActivityAction::DOCUMENT_EXEMPTED);
        $this->assertCount(1, $events);
        $this->assertEvent($events->first(), $admin, $document);
    }

    #[Test]
    public function adding_files_records_one_upload_event_with_the_count(): void
    {
        $admin = $this->admin();
        $player = $this->player();
        $this->markReceived($admin, $player, 'birth_certificate', ['files' => [$this->pdf('first.pdf')]]);
        $document = $player->documents()->firstOrFail();

        $this->actingAs($admin)->post(route('players.documents.files.store', [$player, $document]), [
            'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf'), $this->pdf('c.pdf')],
        ])->assertSessionHas('success', 'flash.document_files_uploaded');

        $uploads = $this->events(ActivityAction::DOCUMENT_FILE_UPLOADED);
        $this->assertCount(2, $uploads);
        $this->assertEvent($uploads->last(), $admin, $document, ['count' => 3]);
    }

    #[Test]
    public function a_refused_upload_records_nothing(): void
    {
        $admin = $this->admin();
        $player = $this->player();
        $this->markReceived($admin, $player, 'birth_certificate');
        $document = $player->documents()->firstOrFail();
        $document->update(['state' => PlayerDocument::EXEMPT]);
        ActivityLog::query()->delete();

        $this->actingAs($admin)->post(route('players.documents.files.store', [$player, $document]), [
            'files' => [$this->pdf()],
        ])->assertSessionHas('error', 'flash.document_not_received');
        $this->actingAs($admin)->post(route('players.documents.files.store', [$player, $document]), [])
            ->assertSessionHas('error', 'flash.document_not_received');

        $this->assertSame(0, ActivityLog::count());
    }
}
