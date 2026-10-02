<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceCustomStatusRequest;
use App\Models\AttendanceCustomStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The owner's custom attendance codes (settings page). Every write needs
 * attendance/edit (config/permissions.php). A code some mark uses can be
 * hidden (is_active false) but never deleted, so history keeps its meaning.
 */
class AttendanceCustomStatusController extends Controller
{
    public function store(AttendanceCustomStatusRequest $request): RedirectResponse
    {
        AttendanceCustomStatus::createWithKey($request->attributesToSave() + [
            'is_active' => true,
            'sort_order' => (int) AttendanceCustomStatus::max('sort_order') + 1,
        ]);

        return back()->with('success', 'flash.attendance_settings_saved');
    }

    public function update(AttendanceCustomStatusRequest $request, AttendanceCustomStatus $customStatus): RedirectResponse
    {
        $customStatus->update($request->attributesToSave());

        return back()->with('success', 'flash.attendance_settings_saved');
    }

    public function destroy(AttendanceCustomStatus $customStatus): RedirectResponse
    {
        if ($customStatus->isUsed()) {
            throw ValidationException::withMessages(['custom_status' => 'att.error.code_in_use']);
        }

        $customStatus->delete();

        return back()->with('success', 'flash.attendance_settings_saved');
    }
}
