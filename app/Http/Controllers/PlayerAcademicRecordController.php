<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\SaveAcademicRecordRequest;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use Illuminate\Http\RedirectResponse;

class PlayerAcademicRecordController extends Controller
{
    public function store(SaveAcademicRecordRequest $request, Player $player): RedirectResponse
    {
        $player->academicRecords()->create($request->validated());

        return back()->with('success', 'flash.academic_record_added');
    }

    // {academicRecord} is scope-bound to {player} in routes/web.php, so a
    // record of another player 404s before reaching here.
    public function update(SaveAcademicRecordRequest $request, Player $player, PlayerAcademicRecord $academicRecord): RedirectResponse
    {
        $academicRecord->update($request->validated());

        return back()->with('success', 'flash.academic_record_updated');
    }

    public function destroy(Player $player, PlayerAcademicRecord $academicRecord): RedirectResponse
    {
        $academicRecord->delete();

        return back()->with('success', 'flash.academic_record_deleted');
    }
}
