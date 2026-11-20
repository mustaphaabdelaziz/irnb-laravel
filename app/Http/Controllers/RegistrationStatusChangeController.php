<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\StatusChangeRequest;
use App\Models\BranchRegistration;
use App\Models\Player;
use App\Models\RegistrationStatusChange;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Services\Player\StatusHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Past status lines typed, corrected or deleted by hand. They change the history
 * only: the current status is changed in the player form.
 */
class RegistrationStatusChangeController extends Controller
{
    public function store(StatusChangeRequest $request, Player $player, BranchRegistration $registration, StatusHistory $history): RedirectResponse
    {
        abort_unless((int) $registration->player_id === (int) $player->id, 404);
        $data = $request->validated();

        $history->record(
            $registration,
            isset($data['from_status_id']) ? (int) $data['from_status_id'] : null,
            isset($data['to_status_id']) ? (int) $data['to_status_id'] : null,
            $data['changed_on'],
            $data['note'] ?? null,
            $request->user(),
            RegistrationStatusChange::ORIGIN_MANUAL,
        );
        ActivityRecorder::record($request->user(), ActivityAction::PLAYER_UPDATED, $player);

        return back()->with('success', 'flash.status_change_saved');
    }

    public function update(StatusChangeRequest $request, Player $player, RegistrationStatusChange $statusChange, StatusHistory $history): RedirectResponse
    {
        $this->assertOwned($player, $statusChange);

        $history->correct($statusChange, $request->validated());
        ActivityRecorder::record($request->user(), ActivityAction::PLAYER_UPDATED, $player);

        return back()->with('success', 'flash.status_change_saved');
    }

    public function destroy(Request $request, Player $player, RegistrationStatusChange $statusChange): RedirectResponse
    {
        $this->assertOwned($player, $statusChange);

        $statusChange->delete();
        ActivityRecorder::record($request->user(), ActivityAction::PLAYER_UPDATED, $player);

        return back()->with('success', 'flash.status_change_deleted');
    }

    private function assertOwned(Player $player, RegistrationStatusChange $line): void
    {
        abort_unless((int) $line->registration?->player_id === (int) $player->id, 404);
    }
}
