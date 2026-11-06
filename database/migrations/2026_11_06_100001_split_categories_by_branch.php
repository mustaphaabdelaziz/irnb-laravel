<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each branch gets its own categories. The main branch keeps every existing
 * row and its ID. Another branch gets a copy of only what it uses, and that
 * branch's rows move to the copy. No amount changes.
 *
 * Every schema step checks its own work and the data runs in one transaction,
 * so a failed run finishes on the next one and a second run changes nothing.
 *
 * On SQLite the foreign key is declared with the column: foreign() would rebuild
 * the table, and categories has cascading children. BranchController::destroy()
 * deletes unused categories first and refuses a category still used.
 */
return new class extends Migration
{
    /** Tables whose rows name both their branch and their category. */
    private const BY_BRANCH = [
        'branch_player', 'training_sessions', 'training_schedules', 'deleted_session_slots',
        'preseason_targets', 'teams', 'finance_accounts',
    ];

    private const UNIQUE = 'categories_branch_name_unique';

    private const FOREIGN = 'categories_branch_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'branch_id')) {
            if ($this->isSqlite()) {
                DB::statement('ALTER TABLE categories ADD COLUMN branch_id INTEGER REFERENCES branches(id) ON DELETE RESTRICT');
            } else {
                Schema::table('categories', fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
            }
        }
        if (! Schema::hasColumn('categories', 'is_active')) {
            Schema::table('categories', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        }
        if (! Schema::hasIndex('categories', self::UNIQUE)) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique(['branch_id', 'name'], self::UNIQUE));
        }
        if (Schema::hasIndex('categories', 'categories_name_unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique('categories_name_unique'));
        }
        if (! $this->isSqlite() && ! $this->hasForeignKey()) {
            Schema::table('categories', fn (Blueprint $t) => $t->foreign('branch_id', self::FOREIGN)->references('id')->on('branches')->restrictOnDelete());
        }

        DB::transaction(fn () => $this->split());
    }

    private function split(): void
    {
        $branchIds = DB::table('branches')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id);
        if ($branchIds->isEmpty()) {
            return;
        }
        $mainId = $this->mainId($branchIds);

        DB::table('categories')->whereNull('branch_id')->update(['branch_id' => $mainId]);

        $moved = collect();
        foreach ($branchIds->reject(fn (int $id) => $id === $mainId) as $branchId) {
            foreach ($this->usedFromElsewhere($branchId) as $categoryId) {
                $copyId = $this->copyFor((int) $categoryId, $branchId);
                $moved = $moved->merge(DB::table('branch_player')->where('branch_id', $branchId)->where('category_id', $categoryId)->pluck('player_id'));
                $this->repoint((int) $categoryId, $copyId, $branchId);
                $this->copyMarks((int) $categoryId, $copyId, $branchId);
                $this->movePrices((int) $categoryId, $copyId, $branchId);
            }
        }

        // The mirror follows the app's rule: no main branch set means no preference.
        $this->refreshMirror($moved->unique()->values(), DB::table('website_configs')->value('main_branch_id'));
    }

    private function mainId(Collection $branchIds): int
    {
        return (int) (DB::table('website_configs')->value('main_branch_id') ?? $branchIds->first());
    }

    /** @return Collection<int, int> the categories this branch uses, or prices on a subscription ticked only for it, but does not own */
    private function usedFromElsewhere(int $branchId): Collection
    {
        $used = collect(self::BY_BRANCH)
            ->flatMap(fn (string $table) => DB::table($table)->where('branch_id', $branchId)->whereNotNull('category_id')->distinct()->pluck('category_id'))
            ->merge(DB::table('training_session_category as link')
                ->join('training_sessions as session', 'session.id', '=', 'link.training_session_id')
                ->where('session.branch_id', $branchId)->distinct()->pluck('link.category_id'))
            ->merge(DB::table('activity_category as link')
                ->join('activities as activity', 'activity.id', '=', 'link.activity_id')
                ->where('activity.branch_id', $branchId)->distinct()->pluck('link.category_id'))
            ->merge(DB::table('category_subscription as price')
                ->join('branch_subscription as ticked', 'ticked.subscription_id', '=', 'price.subscription_id')
                ->where('ticked.branch_id', $branchId)
                ->whereNotExists(fn ($other) => $other->from('branch_subscription as other')
                    ->whereColumn('other.subscription_id', 'price.subscription_id')
                    ->where('other.branch_id', '!=', $branchId))
                ->distinct()->pluck('price.category_id'))
            ->unique()->values();

        return DB::table('categories')->whereIn('id', $used)->where('branch_id', '!=', $branchId)->orderBy('id')->pluck('id');
    }

    private function copyFor(int $categoryId, int $branchId): int
    {
        $original = (array) DB::table('categories')->where('id', $categoryId)->first();
        $existing = DB::table('categories')->where('branch_id', $branchId)->where('name', $original['name'])->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        unset($original['id']);

        return (int) DB::table('categories')->insertGetId(['branch_id' => $branchId] + $original);
    }

    private function repoint(int $from, int $to, int $branchId): void
    {
        foreach (self::BY_BRANCH as $table) {
            DB::table($table)->where('branch_id', $branchId)->where('category_id', $from)->update(['category_id' => $to]);
        }

        $sessions = DB::table('training_sessions')->where('branch_id', $branchId)->select('id');
        DB::table('attendances')->where('category_id', $from)->whereIn('training_session_id', $sessions)->update(['category_id' => $to]);
        $this->movePivot('training_session_category', 'training_session_id', $sessions, $from, $to);
        $this->movePivot('activity_category', 'activity_id', DB::table('activities')->where('branch_id', $branchId)->select('id'), $from, $to);
    }

    private function movePivot(string $table, string $key, mixed $owners, int $from, int $to): void
    {
        $ids = DB::table($table)->where('category_id', $from)->whereIn($key, $owners)->pluck($key);
        foreach ($ids->chunk(200) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk->map(fn ($id) => [$key => $id, 'category_id' => $to])->values()->all());
            DB::table($table)->where('category_id', $from)->whereIn($key, $chunk->values()->all())->delete();
        }
    }

    /** A month already generated from a schedule that moved stays generated for the copy. */
    private function copyMarks(int $from, int $to, int $branchId): void
    {
        if (! DB::table('training_schedules')->where('branch_id', $branchId)->where('category_id', $to)->exists()) {
            return;
        }
        foreach (DB::table('session_generation_marks')->where('category_id', $from)->get() as $mark) {
            DB::table('session_generation_marks')->insertOrIgnore(['category_id' => $to, 'month' => $mark->month, 'created_at' => $mark->created_at]);
        }
    }

    /**
     * Ticked only for this branch: the row moves. Ticked for several or none: the
     * copy gets the same amounts. Ticked only for others: never charged here.
     */
    private function movePrices(int $from, int $to, int $branchId): void
    {
        foreach (DB::table('category_subscription')->where('category_id', $from)->get() as $row) {
            $ticked = DB::table('branch_subscription')->where('subscription_id', $row->subscription_id)->pluck('branch_id')->map(fn ($id) => (int) $id);

            if ($ticked->count() === 1 && $ticked->first() === $branchId) {
                DB::table('category_subscription')->where('category_id', $from)->where('subscription_id', $row->subscription_id)->update(['category_id' => $to]);
            } elseif ($ticked->isEmpty() || $ticked->contains($branchId)) {
                DB::table('category_subscription')->insertOrIgnore(['category_id' => $to] + (array) $row);
            }
        }
    }

    /**
     * The player copy holds the main registration's category. This repeats
     * RegistrationService::pickPrimary's rule, so the migration keeps its meaning
     * if that code changes later.
     *
     * @param  Collection<int, int>  $playerIds
     */
    private function refreshMirror(Collection $playerIds, mixed $mainBranch): void
    {
        $left = DB::table('player_statuses')->where('code', 'left')->value('id');

        foreach ($playerIds->chunk(200) as $chunk) {
            $rows = DB::table('branch_player')->whereIn('player_id', $chunk->values()->all())->orderBy('id')->get()->groupBy('player_id');
            foreach ($rows as $playerId => $registrations) {
                $active = $registrations->reject(fn (object $r) => $left !== null && (int) $r->status_id === (int) $left);
                $primary = $active->first(fn (object $r) => $mainBranch !== null && (int) $r->branch_id === (int) $mainBranch)
                    ?? $active->sortBy(fn (object $r) => [$this->joinSortKey($r), $r->id])->first()
                    ?? $registrations->first();
                DB::table('players')->where('id', $playerId)->update(['category_id' => $primary->category_id]);
            }
        }
    }

    private function joinSortKey(object $registration): string
    {
        if ($registration->join_date !== null) {
            return substr((string) $registration->join_date, 0, 10);
        }

        return $registration->legacy_join_year !== null ? $registration->legacy_join_year.'-12-31' : '9999-12-31';
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('categories'))->contains(fn (array $key) => $key['columns'] === ['branch_id']);
    }

    /**
     * Keeps one row per name: the main branch's, else the lowest ID, so the old
     * unique name fits again. A stale row on the same slot as its copy fails loudly
     * here, inside the transaction, and nothing changes.
     */
    public function down(): void
    {
        DB::transaction(function () {
            $mainId = DB::table('website_configs')->value('main_branch_id');
            foreach (DB::table('categories')->orderBy('id')->get()->groupBy('name') as $group) {
                $keeper = $group->first(fn (object $c) => $mainId !== null && (int) $c->branch_id === (int) $mainId) ?? $group->first();
                foreach ($group->reject(fn (object $c) => $c->id === $keeper->id) as $copy) {
                    $this->mergeInto((int) $copy->id, (int) $keeper->id);
                }
            }
        });

        if (! $this->isSqlite() && $this->hasForeignKey()) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropForeign(self::FOREIGN));
        }
        if (! Schema::hasIndex('categories', 'categories_name_unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique('name', 'categories_name_unique'));
        }
        if (Schema::hasIndex('categories', self::UNIQUE)) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique(self::UNIQUE));
        }
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['branch_id', 'is_active']));
    }

    private function mergeInto(int $from, int $to): void
    {
        foreach ([...self::BY_BRANCH, 'attendances', 'players', 'users'] as $table) {
            DB::table($table)->where('category_id', $from)->update(['category_id' => $to]);
        }
        foreach (['training_session_category', 'activity_category', 'category_subscription', 'session_generation_marks'] as $table) {
            foreach (DB::table($table)->where('category_id', $from)->get() as $row) {
                DB::table($table)->insertOrIgnore(['category_id' => $to] + (array) $row);
            }
            DB::table($table)->where('category_id', $from)->delete();
        }
        DB::table('categories')->where('id', $from)->delete();
    }
};
