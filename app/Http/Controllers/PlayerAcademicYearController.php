<?php

namespace App\Http\Controllers;

use App\Http\Requests\Player\UpdateAcademicYearRequest;
use App\Models\Player;
use App\Models\PlayerAcademicYear;
use Illuminate\Http\RedirectResponse;

/** School years are created by their first grade; here they are corrected or removed. */
class PlayerAcademicYearController extends Controller
{
    // {academicYear} is scope-bound to {player} in routes/web.php.
    public function update(UpdateAcademicYearRequest $request, Player $player, PlayerAcademicYear $academicYear): RedirectResponse
    {
        $academicYear->update($request->validated());

        return back()->with('success', 'flash.academic_year_updated');
    }

    // Its trimesters go with it (cascadeOnDelete on player_academic_records).
    public function destroy(Player $player, PlayerAcademicYear $academicYear): RedirectResponse
    {
        $academicYear->delete();

        return back()->with('success', 'flash.academic_year_deleted');
    }
}
