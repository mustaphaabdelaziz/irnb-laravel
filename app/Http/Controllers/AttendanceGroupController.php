<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Services\Attendance\GroupMembership;
use App\Support\CategoryOwnership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Training groups inside a branch + category, managed from the attendance settings page. */
class AttendanceGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => [Rule::requiredIf(fn () => Branch::exists()), 'nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:60'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $categoryId = (int) $data['category_id'];
        CategoryOwnership::assertIn('category_id', $categoryId, $branchId);

        TrainingGroup::create([
            'branch_id' => $branchId,
            'category_id' => $categoryId,
            'name' => $this->freeName($data['name'], $branchId, $categoryId),
        ]);

        return back()->with('success', 'flash.training_group_saved');
    }

    /** Only the name changes: a group never moves to another branch or category. */
    public function update(Request $request, TrainingGroup $group): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $group->update(['name' => $this->freeName($data['name'], $group->branch_id, $group->category_id, $group->id)]);

        return back()->with('success', 'flash.training_group_saved');
    }

    public function destroy(TrainingGroup $group): RedirectResponse
    {
        // The two group_id columns have no foreign key (see the create_training_groups migration).
        if ($group->isInUse()) {
            return back()->with('error', 'flash.training_group_in_use');
        }

        $group->delete();

        return back()->with('success', 'flash.training_group_deleted');
    }

    /**
     * The tick list: who was ticked (`add`) and unticked (`remove`), applied
     * from `from` on; no date = from the beginning (see GroupMembership).
     */
    public function members(Request $request, TrainingGroup $group, GroupMembership $membership): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'add' => ['nullable', 'array'],
            'add.*' => ['integer', 'distinct'],
            'remove' => ['nullable', 'array'],
            'remove.*' => ['integer', 'distinct'],
        ]);
        $add = array_map('intval', $data['add'] ?? []);
        $remove = array_map('intval', $data['remove'] ?? []);
        $from = $data['from'] ?? null;

        // Only players of the group's own branch + category can join it.
        $fitting = Player::registeredWhere(branches: $group->branch_id, categories: $group->category_id)->whereIn('id', $add)->count();
        if ($fitting !== count($add)) {
            throw ValidationException::withMessages(['add' => 'att.error.group_wrong_player']);
        }

        DB::transaction(function () use ($membership, $group, $add, $remove, $from) {
            foreach ($remove as $playerId) {
                $membership->remove($group, $playerId, $from);
            }
            foreach ($add as $playerId) {
                $membership->add($group, $playerId, $from);
            }
        });

        return back()->with('success', 'flash.training_group_members_saved');
    }

    /** The trimmed name, refused when another group of the same branch + category already has it. */
    private function freeName(string $name, ?int $branchId, int $categoryId, ?int $ignoreId = null): string
    {
        $name = trim($name);
        $taken = TrainingGroup::where('branch_id', $branchId) // null → whereNull
            ->where('category_id', $categoryId)
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'att.error.group_name_taken']);
        }

        return $name;
    }
}
