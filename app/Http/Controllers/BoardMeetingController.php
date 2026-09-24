<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Models\MeetingAttendance;
use App\Services\Storage\FileStorageService;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoardMeetingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCore($request);
        $data['created_by_user_id'] = $request->user()?->id;
        $meeting = BoardMeeting::create($data);

        return redirect()->route('board.meetings.show', $meeting)->with('success', 'flash.meeting_created');
    }

    public function update(Request $request, BoardMeeting $meeting): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $data = $this->validateCore($request);
        $data += $request->validate([
            'minutes' => ['nullable', 'string'],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['nullable', 'string', 'max:1000'],
        ]);
        $meeting->update($data);

        return back()->with('success', 'flash.meeting_updated');
    }

    public function attendance(Request $request, BoardMeeting $meeting): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $data = $request->validate([
            'attendances' => ['present', 'array'],
            'attendances.*.board_member_id' => ['required', 'integer', 'exists:board_members,id'],
            'attendances.*.status' => ['required', Rule::in(['present', 'absent', 'excused'])],
        ]);

        foreach ($data['attendances'] as $row) {
            MeetingAttendance::updateOrCreate(
                ['board_meeting_id' => $meeting->id, 'board_member_id' => $row['board_member_id']],
                ['status' => $row['status']]
            );
        }

        return back()->with('success', 'flash.attendance_saved');
    }

    /**
     * Attach the signed minutes document to a meeting (PDF, Word, or a photo).
     *
     * Minutes are private: they go on the private disk and are served only by
     * showAttachment(), behind login and board/view — never by the public
     * /media route. Replaces any previous file.
     */
    public function attachment(Request $request, BoardMeeting $meeting, PrivateFileStorage $storage, FileStorageService $legacy): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $request->validate([
            'attachment' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $this->forgetStoredAttachment($meeting, $storage, $legacy);

        $stored = $storage->store($request->file('attachment'), 'minutes');
        $meeting->update([
            'attachment_url' => null,
            'attachment_filename' => $stored['path'],
        ]);

        return back()->with('success', 'flash.minutes_file_uploaded');
    }

    public function showAttachment(BoardMeeting $meeting, PrivateFileStorage $storage): StreamedResponse
    {
        $path = $meeting->attachment_filename;

        abort_unless($path && $storage->exists($path), 404);

        return $storage->serve($path, basename($path));
    }

    public function deleteAttachment(BoardMeeting $meeting, PrivateFileStorage $storage, FileStorageService $legacy): RedirectResponse
    {
        if ($meeting->isCancelled()) {
            return back()->with('error', 'flash.meeting_is_cancelled');
        }

        $this->forgetStoredAttachment($meeting, $storage, $legacy);
        $meeting->update(['attachment_url' => null, 'attachment_filename' => null]);

        return back()->with('success', 'flash.minutes_file_removed');
    }

    /**
     * Remove the current file wherever it lives: the private disk, or — for a
     * row the minutes migration could not move — the public one.
     */
    private function forgetStoredAttachment(BoardMeeting $meeting, PrivateFileStorage $storage, FileStorageService $legacy): void
    {
        $storage->delete($meeting->attachment_filename);
        $legacy->delete($meeting->attachment_filename);
    }

    /**
     * Cancel = keep the record, mark it cancelled, say why. Only a meeting that
     * has not happened yet can be cancelled; a held meeting is history.
     */
    public function cancel(Request $request, BoardMeeting $meeting): RedirectResponse
    {
        if ($meeting->status !== 'scheduled') {
            return back()->with('error', 'flash.meeting_not_cancellable');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $meeting->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $request->user()?->id,
            'cancel_reason' => $data['reason'],
        ]);

        return back()->with('success', 'flash.meeting_cancelled');
    }

    /** @return array<string, mixed> */
    private function validateCore(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['ordinary', 'extraordinary', 'general_assembly'])],
            'meeting_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:200'],
            'agenda' => ['nullable', 'array'],
            'agenda.*' => ['nullable', 'string', 'max:500'],
            // 'cancelled' is reached only through cancel(), which records who and why.
            'status' => ['required', Rule::in(['scheduled', 'held'])],
            'quorum_required' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
