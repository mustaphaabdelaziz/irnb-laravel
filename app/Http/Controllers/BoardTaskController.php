<?php

namespace App\Http\Controllers;

use App\Models\BoardTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardTaskController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['created_by_user_id'] = $request->user()?->id;
        $this->syncCompletion($data);
        BoardTask::create($data);

        return back()->with('success', 'Task created.');
    }

    public function update(Request $request, BoardTask $boardTask): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->syncCompletion($data);
        $boardTask->update($data);

        return back()->with('success', 'Task updated.');
    }

    public function destroy(BoardTask $boardTask): RedirectResponse
    {
        $boardTask->delete();

        return back()->with('success', 'Task deleted.');
    }

    /** Keep progress / status / completed_at consistent. */
    private function syncCompletion(array &$data): void
    {
        if (($data['status'] ?? null) === 'completed') {
            $data['progress'] = 100;
            $data['completed_at'] = now();
        } else {
            $data['completed_at'] = null;
            if (($data['status'] ?? null) === 'not_started') {
                $data['progress'] = $data['progress'] ?? 0;
            }
            if ((int) ($data['progress'] ?? 0) >= 100 && ($data['status'] ?? null) === 'in_progress') {
                $data['progress'] = 99;
            }
        }
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'board_member_id' => ['nullable', 'integer', 'exists:board_members,id'],
            'board_meeting_id' => ['nullable', 'integer', 'exists:board_meetings,id'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['not_started', 'in_progress', 'completed', 'cancelled'])],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
        ]);
    }
}
