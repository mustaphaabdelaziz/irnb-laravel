<?php

namespace App\Console\Commands;

use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only checks on real data, before and after a release. */
class VerifyRegistrations extends Command
{
    protected $signature = 'registrations:verify';

    protected $description = 'Check that registrations and the player copy agree';

    public function handle(RegistrationService $registrations): int
    {
        $failures = 0;

        $this->line('registrations: '.BranchRegistration::count());
        $this->line('needing a join date: '.BranchRegistration::needsJoinDate()->count());
        $this->line('no branch: '.Player::whereDoesntHave('registrations')->count());
        $this->line('no ID yet: '.BranchRegistration::whereNull('membership_id')->count());
        $this->line('old-format IDs: '.BranchRegistration::whereNotNull('membership_id')->where('membership_id', 'not like', '%-%')->count());

        $duplicateIds = DB::table('branch_player')->whereNotNull('membership_id')
            ->groupBy('membership_id')->havingRaw('COUNT(*) > 1')->pluck('membership_id');
        $failures += $this->report('duplicate IDs', $duplicateIds->all());

        $earliest = fn (Player $player) => $player->registrations->map(fn ($r) => $r->effectiveYear())->filter()->min();

        $copyErrors = [];
        $yearErrors = [];
        $statusErrors = [];
        Player::query()->whereHas('registrations')->with('registrations')->chunkById(200, function ($players) use ($registrations, $earliest, &$copyErrors, &$yearErrors, &$statusErrors) {
            foreach ($players as $player) {
                $primary = $registrations->primary($player);

                foreach (['category_id', 'position_id', 'licence_number'] as $field) {
                    if ((string) $player->{$field} !== (string) $primary->{$field}) {
                        $copyErrors[] = "player {$player->id}: {$field}";
                    }
                }
                [$status, $leftAt] = $registrations->overallStatus($primary, $player->registrations);
                if ((string) $player->status_id !== (string) $status) {
                    $statusErrors[] = "player {$player->id}: status_id";
                }
                if ((string) $player->left_at?->toDateString() !== (string) $leftAt) {
                    $statusErrors[] = "player {$player->id}: left_at";
                }
                if ($primary->membership_id !== null && $player->membership_id !== $primary->membership_id) {
                    $copyErrors[] = "player {$player->id}: membership_id";
                }

                $year = $earliest($player);
                if ($year !== null && (int) $player->join_year !== (int) $year) {
                    $yearErrors[] = "player {$player->id}: {$player->join_year} vs {$year}";
                }
            }
        });

        $failures += $this->report('player copy differs from primary', $copyErrors);
        $failures += $this->report('player status or leave date differs from the registrations', $statusErrors);
        $failures += $this->report('join year differs', $yearErrors);

        $strayLeaveDates = Player::leftStatusId() === null ? [] : BranchRegistration::where(fn ($q) => $q->whereNull('status_id')->orWhere('status_id', '!=', Player::leftStatusId()))
            ->whereNotNull('left_at')->pluck('id')->map(fn ($id) => "registration {$id}")->all();
        $failures += $this->report('leave date on a registration that has not left', $strayLeaveDates);

        $homelessDocuments = DB::table('player_documents as pd')
            ->where(fn ($q) => $q
                ->where(fn ($blank) => $blank->whereNull('pd.branch_registration_id')
                    ->whereExists(fn ($r) => $r->selectRaw('1')->from('branch_player')->whereColumn('branch_player.player_id', 'pd.player_id')))
                ->orWhere(fn ($named) => $named->whereNotNull('pd.branch_registration_id')
                    ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('branch_player')
                        ->whereColumn('branch_player.id', 'pd.branch_registration_id')
                        ->whereColumn('branch_player.player_id', 'pd.player_id'))))
            ->pluck('pd.id')->map(fn ($id) => "document {$id}")->all();
        $failures += $this->report('document with no registration of its player', $homelessDocuments);

        // The unique key can't see blanks: for a player with no registration, the writers keep it to one.
        $doubledDocuments = DB::table('player_documents')->whereNull('branch_registration_id')
            ->groupBy('player_id', 'document_type_id')->havingRaw('COUNT(*) > 1')
            ->get(['player_id', 'document_type_id'])
            ->map(fn ($row) => "player {$row->player_id}: type {$row->document_type_id}")->all();
        $failures += $this->report('document held twice', $doubledDocuments);

        $failures += $this->report('category with no branch', $this->unownedCategories());
        $failures += $this->report('category of another branch', $this->misplacedCategories());
        [$priced, $unpriced] = $this->priceProblems();
        $failures += $this->report('same-named categories priced differently (expected only after someone sets a price per branch)', $priced);
        $failures += $this->report('same-named category with no price row (expected only after someone removes one)', $unpriced);

        $this->line($failures === 0 ? 'OK' : "FAILED: {$failures} check(s)");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function unownedCategories(): array
    {
        if (! DB::table('branches')->exists()) {
            return [];
        }

        return Category::whereNull('branch_id')->pluck('name')->all();
    }

    /** @return list<string> */
    private function misplacedCategories(): array
    {
        $problems = [];
        foreach (['branch_player' => 'registration', 'training_sessions' => 'session', 'training_schedules' => 'schedule', 'teams' => 'team', 'finance_accounts' => 'caisse'] as $table => $label) {
            $ids = DB::table($table)->join('categories', 'categories.id', '=', "$table.category_id")
                ->whereNotNull("$table.branch_id")->whereNotNull('categories.branch_id')
                ->whereColumn('categories.branch_id', '!=', "$table.branch_id")
                ->pluck("$table.id");
            foreach ($ids as $id) {
                $problems[] = "$label $id";
            }
        }

        $joint = DB::table('training_session_category')
            ->join('training_sessions', 'training_sessions.id', '=', 'training_session_category.training_session_id')
            ->join('categories', 'categories.id', '=', 'training_session_category.category_id')
            ->whereNotNull('training_sessions.branch_id')->whereNotNull('categories.branch_id')
            ->whereColumn('categories.branch_id', '!=', 'training_sessions.branch_id')
            ->pluck('training_session_category.training_session_id');
        foreach ($joint as $id) {
            $problems[] = "session $id (joint)";
        }

        return $problems;
    }

    /**
     * After the split, same-named categories of a subscription hold the same amounts,
     * and each has a row in every branch the subscription applies to, so no amount changed.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function priceProblems(): array
    {
        $rows = DB::table('category_subscription')
            ->join('categories', 'categories.id', '=', 'category_subscription.category_id')
            ->get(['category_subscription.subscription_id', 'category_subscription.category_id', 'category_subscription.amount_student', 'category_subscription.amount_worker', 'categories.name']);
        $sameName = Category::query()->get(['id', 'name', 'branch_id'])->groupBy('name')->filter(fn ($group) => $group->count() > 1);
        $ticked = DB::table('branch_subscription')->get()->groupBy('subscription_id');

        $priced = [];
        $unpriced = [];
        foreach ($rows->groupBy(fn ($row) => $row->subscription_id.'|'.$row->name) as $group) {
            $first = $group->first();
            if ($group->unique(fn ($row) => (float) $row->amount_student.'|'.(float) $row->amount_worker)->count() > 1) {
                $priced[] = "subscription {$first->subscription_id}: {$first->name}";
            }

            $branches = collect($ticked->get($first->subscription_id, []))->pluck('branch_id')->map(fn ($id) => (int) $id);
            $missing = collect($sameName->get($first->name, []))
                ->reject(fn (Category $category) => $group->contains('category_id', $category->id))
                ->filter(fn (Category $category) => $branches->isEmpty() || $branches->contains((int) $category->branch_id));
            foreach ($missing as $category) {
                $unpriced[] = "subscription {$first->subscription_id}: {$first->name}, category {$category->id}";
            }
        }

        return [$priced, $unpriced];
    }


    /** @param  list<string>  $problems */
    private function report(string $label, array $problems): int
    {
        if ($problems === []) {
            return 0;
        }

        $this->error($label.': '.count($problems));
        foreach (array_slice($problems, 0, 20) as $problem) {
            $this->line('  '.$problem);
        }

        return 1;
    }
}
