<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\SaveAcademicRecordRequest;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PlayerAcademicRecordController extends Controller
{
    // The first grade of a school year creates that year with its school info;
    // later grades reuse it (school info sent then is excluded by the request).
    public function store(SaveAcademicRecordRequest $request, Player $player): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $player, $data) {
            $year = $player->academicYears()->firstOrCreate(
                ['academic_year' => (int) $data['academic_year']],
                Arr::only($data, ['education_level', 'institution', 'field_of_study']),
            );

            $record = $year->records()->create(Arr::only($data, ['period', 'gpa', 'certificate', 'remark']));

            ActivityRecorder::record($request->user(), ActivityAction::ACADEMIC_RECORD_ADDED, $record);
        });

        return back()->with('success', 'flash.academic_record_added');
    }

    // {academicRecord} is scope-bound to {player} through Player::academicRecords()
    // in routes/web.php, so a record of another player 404s before reaching here.
    public function update(SaveAcademicRecordRequest $request, Player $player, PlayerAcademicRecord $academicRecord): RedirectResponse
    {
        $academicRecord->update($request->validated());

        return back()->with('success', 'flash.academic_record_updated');
    }

    // An emptied year is kept: its school info is still history.
    public function destroy(Player $player, PlayerAcademicRecord $academicRecord): RedirectResponse
    {
        $academicRecord->delete();

        return back()->with('success', 'flash.academic_record_deleted');
    }
}
