<?php

namespace App\Http\Controllers;

use App\Models\BoardMember;
use App\Models\BoardTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardTermController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $term = BoardTerm::create($data);

        if ($term->is_current) {
            $this->makeCurrent($term);
        }

        return back()->with('success', 'flash.term_created');
    }

    public function update(Request $request, BoardTerm $boardTerm): RedirectResponse
    {
        $data = $this->validateData($request);
        $boardTerm->update($data);

        if ($boardTerm->is_current) {
            $this->makeCurrent($boardTerm);
        }

        return back()->with('success', 'flash.term_updated');
    }

    public function destroy(BoardTerm $boardTerm): RedirectResponse
    {
        if ($boardTerm->members()->exists()) {
            return back()->with('error', 'flash.term_in_use');
        }

        $boardTerm->delete();

        return back()->with('success', 'flash.term_deleted');
    }

    /**
     * Start a new term from the last one: its active members, their roles and
     * committee seats. Former members stay behind. Only into an empty term.
     */
    public function copyPrevious(BoardTerm $boardTerm): RedirectResponse
    {
        if ($boardTerm->members()->exists()) {
            return back()->with('error', 'flash.term_not_empty');
        }

        $source = $boardTerm->previous()?->members()->where('status', 'active')->with('committees:id')->get() ?? collect();
        if ($source->isEmpty()) {
            return back()->with('error', 'flash.term_nothing_to_copy');
        }

        DB::transaction(function () use ($source, $boardTerm) {
            foreach ($source as $member) {
                $copy = BoardMember::create([
                    'player_id' => $member->player_id,
                    'user_id' => $member->user_id,
                    'board_term_id' => $boardTerm->id,
                    'name' => $member->getRawOriginal('name'),
                    'role' => $member->role,
                    'status' => 'active',
                    'sort_order' => $member->sort_order,
                    // Members from before the player link keep their own contact; photos are not shared.
                    'email' => $member->getRawOriginal('email'),
                    'phone' => $member->getRawOriginal('phone'),
                ]);
                $copy->committees()->attach(
                    $member->committees->mapWithKeys(fn ($c) => [$c->id => ['position' => $c->pivot->position]])->all()
                );
            }
        });

        return back()->with('success', 'flash.term_members_copied');
    }

    /** Only one term can be current. */
    private function makeCurrent(BoardTerm $term): void
    {
        BoardTerm::where('id', '!=', $term->id)->update(['is_current' => false]);
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['boolean'],
        ]);
    }
}
