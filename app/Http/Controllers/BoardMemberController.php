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
    public function store(Request $request, FileStorageService $files): RedirectResponse
    {
        $data = $this->fillFromPlayer($this->validateData($request));
        if ($request->hasFile('photo')) {
            $stored = $files->storeFile($request->file('photo'), 'board');
            $data['photo_url'] = $stored['url'];
            $data['photo_filename'] = $stored['filename'];
        }
        unset($data['photo']);
        BoardMember::create($data);

        return back()->with('success', 'Board member added.');
    }

    public function update(Request $request, BoardMember $boardMember, FileStorageService $files): RedirectResponse
    {
        $data = $this->fillFromPlayer($this->validateData($request));
        if ($request->hasFile('photo')) {
            $files->delete($boardMember->photo_filename);
            $stored = $files->storeFile($request->file('photo'), 'board');
            $data['photo_url'] = $stored['url'];
            $data['photo_filename'] = $stored['filename'];
        }
        unset($data['photo']);
        $boardMember->update($data);

        return back()->with('success', 'Board member updated.');
    }

    public function destroy(BoardMember $boardMember, FileStorageService $files): RedirectResponse
    {
        $files->delete($boardMember->photo_filename);
        $boardMember->delete();

        return back()->with('success', 'Board member removed.');
    }

    /**
     * When a player is picked, default the member's display name (and photo when
     * none was uploaded) from the player record. The name stays editable.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillFromPlayer(array $data): array
    {
        if (! empty($data['player_id'])) {
            $player = Player::find($data['player_id']);
            if ($player) {
                if (empty($data['name'])) {
                    $data['name'] = trim($player->firstname.' '.$player->lastname);
                }
                if (empty($data['photo_url']) && $player->picture_url) {
                    $data['photo_url'] = $player->picture_url;
                }
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required_without:player_id', 'nullable', 'string', 'max:160'],
            'player_id' => ['required_without:name', 'nullable', 'integer', 'exists:players,id'],
            'board_term_id' => ['nullable', 'integer', 'exists:board_terms,id'],
            'role' => ['required', 'string', 'max:100', 'exists:board_roles,name'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'status' => ['required', Rule::in(['active', 'former'])],
            'bio' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);
    }
}
