<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\Team;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BranchController extends Controller
{
    public function index(): Response
    {
        $branches = Branch::query()
            ->withCount('players')
            ->with('players:id')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Branches', [
            'branches' => $branches,
            // For assigning members directly from the branches menu.
            'players' => Player::where('archived', false)->orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                ]),
        ]);
    }

    public function syncPlayers(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
        ]);

        $branch->players()->sync($validated['player_ids']);

        return back()->with('success', 'flash.branch_members_updated');
    }

    public function store(Request $request, FileStorageService $files): RedirectResponse
    {
        $data = $this->validated($request, $files);
        $this->savingLogo($data, $files, fn () => Branch::create($data));

        return back()->with('success', 'flash.branch_created');
    }

    public function update(Request $request, Branch $branch, FileStorageService $files): RedirectResponse
    {
        $data = $this->validated($request, $files, $branch->id);
        $oldLogo = $branch->logo_filename;

        // "Remove" clears the logo without uploading a new one.
        if (! isset($data['logo_filename']) && $request->boolean('remove_logo')) {
            $data['logo_url'] = null;
            $data['logo_filename'] = null;
        }

        $this->savingLogo($data, $files, fn () => $branch->update($data));

        // Only once the row points elsewhere is the old file safe to drop.
        if (array_key_exists('logo_filename', $data)) {
            $files->delete($oldLogo);
        }

        return back()->with('success', 'flash.branch_updated');
    }

    public function destroy(Branch $branch, FileStorageService $files): RedirectResponse
    {
        if (Team::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_has_teams');
        }
        // Attendance rows have no foreign key on branch_id (see the add_branch_to_attendance_tables migration).
        if (TrainingSession::where('branch_id', $branch->id)->exists() || TrainingSchedule::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_has_sessions');
        }
        $files->delete($branch->logo_filename);
        DB::transaction(function () use ($branch) {
            PreseasonTarget::where('branch_id', $branch->id)->delete();
            DB::table('deleted_session_slots')->where('branch_id', $branch->id)->delete();
            $branch->delete();
        });

        return back()->with('success', 'flash.branch_deleted');
    }

    /**
     * Run the save; if it fails, delete the logo just uploaded so it is not left orphaned on disk.
     *
     * @param  array<string, mixed>  $data
     */
    private function savingLogo(array $data, FileStorageService $files, callable $save): void
    {
        try {
            $save();
        } catch (Throwable $e) {
            $files->delete($data['logo_filename'] ?? null);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed> the columns to save, with the stored logo when one was uploaded
     */
    private function validated(Request $request, FileStorageService $files, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:branches,name'.($ignoreId ? ','.$ignoreId : '')],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        unset($data['logo'], $data['remove_logo']);

        if ($request->hasFile('logo')) {
            $stored = $files->storeImage($request->file('logo'), 'branches', 256);
            $data['logo_url'] = $stored['url'];
            $data['logo_filename'] = $stored['filename'];
        }

        return $data;
    }
}
