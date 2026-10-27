<?php

namespace App\Http\Controllers;

use App\Models\BoardCommittee;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Seats on a committee, set from the organigram: one head, two deputies,
 * any number of members — counted among the members of the same term.
 */
class BoardCommitteeMemberController extends Controller
{
    /**
     * Seat a player: their board member for the term is reused, or created
     * with the first member-level role when they are not on the board yet.
     */
    public function store(Request $request, BoardCommittee $committee): RedirectResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'board_term_id' => ['required', 'integer', 'exists:board_terms,id'],
            'position' => ['required', Rule::in(BoardCommittee::POSITIONS)],
        ]);

        $member = BoardMember::firstOrCreate(
            ['player_id' => $data['player_id'], 'board_term_id' => $data['board_term_id']],
            [
                'name' => Player::findOrFail($data['player_id'])->fullname,
                'role' => BoardRole::where('level', 'member')->orderBy('sort_order')->value('name') ?? 'member',
                'status' => 'active',
            ],
        );

        $this->ensureSeatFree($committee, $member, $data['position']);
        $committee->members()->syncWithoutDetaching([$member->id => ['position' => $data['position']]]);

        return back()->with('success', 'flash.committee_seat_saved');
    }

    public function update(Request $request, BoardCommittee $committee, BoardMember $boardMember): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['required', Rule::in(BoardCommittee::POSITIONS)],
        ]);

        abort_unless($committee->members()->whereKey($boardMember->id)->exists(), 404);

        $this->ensureSeatFree($committee, $boardMember, $data['position']);
        $committee->members()->updateExistingPivot($boardMember->id, ['position' => $data['position']]);

        return back()->with('success', 'flash.committee_seat_saved');
    }

    /** Leaves the committee only; the person stays on the board. */
    public function destroy(BoardCommittee $committee, BoardMember $boardMember): RedirectResponse
    {
        $committee->members()->detach($boardMember->id);

        return back()->with('success', 'flash.committee_seat_removed');
    }

    private function ensureSeatFree(BoardCommittee $committee, BoardMember $member, string $position): void
    {
        $limit = BoardCommittee::LIMITS[$position] ?? null;
        if ($limit === null) {
            return;
        }

        $taken = $committee->members()
            ->where('board_members.board_term_id', $member->board_term_id)
            ->where('board_members.id', '!=', $member->id)
            ->wherePivot('position', $position)
            ->count();

        if ($taken >= $limit) {
            // An i18n key: the organigram translates it.
            throw ValidationException::withMessages(['position' => 'board.error.seat_taken']);
        }
    }
}
