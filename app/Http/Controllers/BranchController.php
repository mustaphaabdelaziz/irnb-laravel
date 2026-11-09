<?php

namespace App\Http\Controllers;

use App\Exceptions\CategoryInUse;
use App\Models\Branch;
use App\Models\BranchRegistration;
use App\Models\Category;
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
        $active = fn ($q) => BranchRegistration::constrainActive($q, 'branch_player.status_id');

        $branches = Branch::query()
            ->withCount(['players' => $active])
            ->with(['players' => fn ($q) => $active($q->select('players.id'))])
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Branches', [
            'branches' => $branches,
            'mainBranchId' => Branch::mainId(),
            'canConvertIds' => request()->user()?->isSuperadmin() ?? false,
            // For assigning members directly from the branches menu.
            'players' => Player::where('archived', false)->orderBy('lastname')->orderBy('firstname')->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'fullname' => $p->fullname,
                    'membership_id' => $p->membership_id,
                ]),
        ]);
    }

    /** The General tab of a branch's page. */
    public function show(Branch $branch): Response
    {
        return Inertia::render('Settings/BranchDetail', [
            'branch' => $branch->only(['id', 'name', 'localized_name', 'logo_url', 'name_ar', 'name_fr', 'name_en', 'description', 'code']),
            'tab' => 'general',
            'categories' => [],
        ]);
    }

    public function syncPlayers(Request $request, Branch $branch, RegistrationService $registrations): RedirectResponse
    {
        $validated = $request->validate([
            'player_ids' => ['present', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'join_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
        ]);

        $active = $branch->registrations()->active()->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $wanted = array_map('intval', $validated['player_ids']);
        $joining = Player::whereIn('id', array_diff($wanted, $active))->get();

        // The date is for a genuinely new registration only: a returning member keeps
        // theirs, and a first registration of a player who holds a year keeps that year.
        $registrations->assertSharedDate($joining->map(fn (Player $p) => [$p, $branch->id]), $validated['join_date'] ?? null);

        DB::transaction(function () use ($branch, $registrations, $validated, $active, $wanted, $joining) {
            $joining->each(fn (Player $player) => $registrations->registerWithSharedDate($player, $branch->id, $validated['join_date'] ?? null));
            Player::whereIn('id', array_diff($active, $wanted))->get()
                ->each(fn (Player $player) => $registrations->leave($player, [$branch->id]));
        });

        return back()->with('success', 'flash.branch_members_updated');
    }

    public function setMain(Branch $branch, RegistrationService $registrations): RedirectResponse
    {
        // The primary depends on the main branch, so players of the old one can change too.
        $oldMain = Branch::mainId();
        DB::transaction(function () use ($branch, $registrations, $oldMain) {
            WebsiteConfig::singleton()->update(['main_branch_id' => $branch->id]);
            $affected = array_filter([$oldMain, $branch->id]);
            Player::whereHas('registrations', fn ($q) => $q->whereIn('branch_id', $affected))->get()
                ->each(fn (Player $player) => $registrations->refreshMirror($player));
        });

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
        // The main branch is reassigned first; the only branch left may still go.
        if (Branch::mainId() === $branch->id && Branch::where('id', '!=', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_is_main');
        }
        if (Team::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_has_teams');
        }
        // Deleting would cascade away join dates and IDs, left rows included.
        if ($branch->registrations()->exists()) {
            return back()->with('error', 'flash.branch_has_registrations');
        }
        // Attendance rows have no foreign key on branch_id (see the add_branch_to_attendance_tables migration).
        if (TrainingSession::where('branch_id', $branch->id)->exists() || TrainingSchedule::where('branch_id', $branch->id)->exists()) {
            return back()->with('error', 'flash.branch_has_sessions');
        }
        // The branch's own targets and tombstones are not "use". Another branch's rows on one of
        // its categories are: that refuses, and the transaction rolls everything back.
        try {
            DB::transaction(function () use ($branch) {
                PreseasonTarget::where('branch_id', $branch->id)->delete();
                DB::table('deleted_session_slots')->where('branch_id', $branch->id)->delete();
                // Only stale details can remain: a branch with sessions is refused above.
                DB::table('injury_notes')->where('branch_id', $branch->id)->delete();

                $categories = Category::where('branch_id', $branch->id)->get();
                // Generation marks are per category (no branch column) and cascade with it.
                DB::table('session_generation_marks')->whereIn('category_id', $categories->modelKeys())->delete();
                if ($categories->contains(fn (Category $category) => $category->isInUse())) {
                    throw new CategoryInUse;
                }

                $categories->each->delete();
                $branch->delete();
            });
        } catch (CategoryInUse) {
            return back()->with('error', 'flash.branch_has_used_categories');
        }
        // Only once the delete has committed is the logo file safe to drop.
        $files->delete($branch->logo_filename);

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
            // A new branch named only in Arabic would get a meaningless BRA code in every membership ID.
            if ($ignoreId === null && BranchCode::letters($data['name_en'] ?? null, $data['name_fr'] ?? null, $data['name']) === '') {
                throw ValidationException::withMessages(['code' => __('validation.branch_code_needs_latin')]);
            }
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
