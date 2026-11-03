<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PreseasonTarget;
use App\Models\Team;
use App\Models\WebsiteConfig;
use App\Models\TrainingSchedule;
use App\Models\TrainingSession;
use App\Services\Player\RegistrationService;
use App\Services\Storage\FileStorageService;
use App\Support\BranchCode;
use App\Support\ValidationAttributes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BranchController extends Controller
{
    public function index(): Response
    {
        // Members who left stay in the registration but are not offered as members,
        // or saving the members dialog would reopen them.
        $left = Player::leftStatusId();
        $active = fn ($q) => $left === null ? $q : $q->where(
            fn ($w) => $w->whereNull('branch_player.status_id')->orWhere('branch_player.status_id', '!=', $left)
        );

        $branches = Branch::query()
            ->withCount(['players' => $active])
            ->with(['players' => fn ($q) => $active($q->select('players.id'))])
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Branches', [
            'branches' => $branches,
            'mainBranchId' => Branch::mainId(),
            // For assigning members directly from the branches menu.
            'players' => Player::where('archived', false)->orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                ]),
        ]);
    }

    public function syncPlayers(Request $request, Branch $branch, RegistrationService $registrations): RedirectResponse
    {
        $validated = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'join_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ]);

        $left = Player::leftStatusId();
        $rows = $branch->registrations()->get(['player_id', 'status_id']);
        $known = $rows->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $active = $rows->reject(fn ($r) => $left !== null && (int) $r->status_id === $left)
            ->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $wanted = array_map('intval', $validated['player_ids']);

        // Only a player with no row here at all is new; a returning member keeps the old date.
        if (array_diff($wanted, $known) !== [] && empty($validated['join_date'])) {
            throw ValidationException::withMessages([
                'join_date' => __('validation.required', ['attribute' => ValidationAttributes::for(app()->getLocale())['join_date']]),
            ]);
        }

        DB::transaction(function () use ($branch, $registrations, $validated, $active, $wanted) {
            Player::whereIn('id', array_diff($wanted, $active))->get()->each(fn (Player $player) => $registrations->register($player, [
                'branch_id' => $branch->id,
                'join_date' => $validated['join_date'] ?? null,
            ]));
            Player::whereIn('id', array_diff($active, $wanted))->get()
                ->each(fn (Player $player) => $registrations->leave($player, [$branch->id]));
        });

        return back()->with('success', 'flash.branch_members_updated');
    }

    public function setMain(Branch $branch, RegistrationService $registrations): RedirectResponse
    {
        WebsiteConfig::singleton()->update(['main_branch_id' => $branch->id]);
        Player::whereHas('registrations', fn ($q) => $q->where('branch_id', $branch->id))->get()
            ->each(fn (Player $player) => $registrations->refreshMirror($player));

        return back()->with('success', 'flash.branch_main_set');
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
            // Only stale details can remain: a branch with sessions is refused above.
            DB::table('injury_notes')->where('branch_id', $branch->id)->delete();
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
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:branches,name'.($ignoreId ? ','.$ignoreId : '')],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'regex:'.BranchCode::RULE, Rule::unique('branches', 'code')->ignore($ignoreId)],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        unset($data['logo'], $data['remove_logo']);

        if (empty($data['code'])) {
            unset($data['code']);
        } elseif ($ignoreId !== null) {
            $branch = Branch::findOrFail($ignoreId);
            if ($data['code'] !== $branch->code && BranchCode::isLocked($branch)) {
                throw ValidationException::withMessages(['code' => __('validation.branch_code_locked')]);
            }
        }

        if ($request->hasFile('logo')) {
            $stored = $files->storeImage($request->file('logo'), 'branches', 256);
            $data['logo_url'] = $stored['url'];
            $data['logo_filename'] = $stored['filename'];
        }

        return $data;
    }
}
