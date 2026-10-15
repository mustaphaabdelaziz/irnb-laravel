<?php

namespace App\Http\Controllers;

use App\Models\BoardMember;
use App\Models\Player;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardMemberController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        BoardMember::create($this->fillFromPlayer($this->validateData($request)));

        return back()->with('success', 'flash.board_member_added');
    }

    public function update(Request $request, BoardMember $boardMember): RedirectResponse
    {
        $boardMember->update($this->fillFromPlayer($this->validateData($request)));

        return back()->with('success', 'flash.board_member_updated');
    }

    public function destroy(BoardMember $boardMember, FileStorageService $files): RedirectResponse
    {
        // Photos uploaded before members came from players.
        $files->delete($boardMember->photo_filename);
        $boardMember->delete();

        return back()->with('success', 'flash.board_member_removed');
    }

    /**
     * A member is a player: name, contact and photo are read from the player
     * (BoardMember::withPlayer()); only the name is copied, for narrow lookups.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillFromPlayer(array $data): array
    {
        $data['name'] = Player::findOrFail($data['player_id'])->fullname;

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'board_term_id' => ['nullable', 'integer', 'exists:board_terms,id'],
            'role' => ['required', 'string', 'max:100', 'exists:board_roles,name'],
            'status' => ['required', Rule::in(['active', 'former'])],
        ]);
    }
}
