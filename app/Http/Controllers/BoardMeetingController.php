<?php

namespace App\Http\Controllers;

use App\Models\BoardMeeting;
use App\Models\MeetingAttendance;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardMeetingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCore($request);
        $data['created_by_user_id'] = $request->user()?->id;
        $meeting = BoardMeeting::create($data);

        return redirect()->route('board.meetings.show', $meeting)->with('success', 'Meeting created.');
    }

    public function update(Request $request, BoardMeeting $meeting): RedirectResponse
    {
        $data = $this->validateCore($request);
        $data += $request->validate([
            'minutes' => ['nullable', 'string'],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['nullable', 'string', 'max:1000'],
        ]);
        $meeting->update($data);

        return back()->with('success', 'Meeting updated.');
    }

    public function attendance(Request $request, BoardMeeting $meeting): RedirectResponse
    {
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

        return back()->with('success', 'Attendance saved.');
    }

    /**
     * Attach the signed minutes document to a meeting (PDF, Word, or a photo).
     * Stored on the public disk with a host-relative /media URL so it downloads
     * on both the web app and the desktop app. Replaces any previous file.
     */
    public function attachment(Request $request, BoardMeeting $meeting, FileStorageService $storage): RedirectResponse
    {
        $request->validate([
            'attachment' => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $storage->delete($meeting->attachment_filename);

        $stored = $storage->storeFile($request->file('attachment'), 'minutes');
        $meeting->update([
            'attachment_url' => $stored['url'],
            'attachment_filename' => $stored['filename'],
        ]);

        return back()->with('success', 'Minutes file uploaded.');
    }

    public function deleteAttachment(BoardMeeting $meeting, FileStorageService $storage): RedirectResponse
    {
        $storage->delete($meeting->attachment_filename);
        $meeting->update(['attachment_url' => null, 'attachment_filename' => null]);

        return back()->with('success', 'Minutes file removed.');
    }

    public function destroy(BoardMeeting $meeting): RedirectResponse
    {
        $meeting->delete();

        return redirect()->route('board.meetings')->with('success', 'Meeting deleted.');
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
            'status' => ['required', Rule::in(['scheduled', 'held', 'cancelled'])],
            'quorum_required' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
