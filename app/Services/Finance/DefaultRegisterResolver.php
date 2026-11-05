<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\Subscription;
use App\Services\Player\RegistrationDisplay;
use Illuminate\Support\Collection;

/**
 * Picks the cash register a payment lands in when none was chosen: the
 * subscription's branch when it has exactly one, else the player's main
 * registration, each with its category register, else that branch's treasury,
 * else the first club-level cash account. The server default and the value
 * the forms pre-select both come from here, so they cannot disagree.
 *
 * Works in memory over one list of active accounts, so resolving for many
 * players costs a single query.
 */
final class DefaultRegisterResolver
{
    /** @var Collection<string, FinanceAccount> keyed "branch:category" */
    private Collection $categoryRegisters;

    /** @var Collection<int, FinanceAccount> keyed by branch id */
    private Collection $treasuries;

    private ?FinanceAccount $clubWide;

    private ?RegistrationDisplay $display = null;

    /** @param Collection<int, FinanceAccount> $accounts active accounts */
    public function __construct(Collection $accounts)
    {
        $this->categoryRegisters = $accounts
            ->filter(fn (FinanceAccount $account) => $account->branch_id && $account->category_id)
            ->keyBy(fn (FinanceAccount $account) => $account->branch_id.':'.$account->category_id);

        $this->treasuries = $accounts
            ->filter(fn (FinanceAccount $account) => $account->branch_id && $account->is_treasury)
            ->keyBy('branch_id');

        // Club level: cash accounts outside any branch, never the Fonds Initial,
        // never a coffre whose branch was deleted.
        $this->clubWide = $accounts
            ->filter(fn (FinanceAccount $account) => $account->type === 'cash'
                && ! $account->branch_id && ! $account->is_treasury && ! $account->is_opening_fund)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->first();
    }

    public static function load(): self
    {
        return new self(FinanceAccount::query()
            ->where('is_active', true)
            ->get(['id', 'branch_id', 'category_id', 'type', 'is_treasury', 'is_opening_fund', 'sort_order']));
    }

    public function for(?int $branchId, ?int $categoryId): ?FinanceAccount
    {
        return ($branchId && $categoryId ? $this->categoryRegisters->get($branchId.':'.$categoryId) : null)
            ?? ($branchId ? $this->treasuries->get($branchId) : null)
            ?? $this->clubWide;
    }

    /**
     * The main registration's register. Never a branch the player left: left
     * everywhere, or with no registration, it is the club-level account. Eager-load
     * `registrations` when resolving for many players.
     */
    public function forPlayer(Player $player): ?FinanceAccount
    {
        $this->display ??= RegistrationDisplay::make();
        $main = $this->display->main($player);

        return $main !== null && $this->display->isActiveIn($player, (int) $main->branch_id)
            ? $this->for((int) $main->branch_id, $main->category_id)
            : $this->for(null, null);
    }

    /**
     * The register of a subscription tied to exactly one branch, or null otherwise.
     * Its one category, else the player's category in that branch, else that branch's
     * treasury. A debt of a branch the player left still lands there: the money
     * was owed to that sport. Expects both relations loaded.
     */
    public function forSubscription(Subscription $subscription, Player $player): ?FinanceAccount
    {
        if ($subscription->branches->count() !== 1) {
            return null;
        }

        $branchId = (int) $subscription->branches->first()->id;
        $categoryId = $subscription->categories->count() === 1
            ? $subscription->categories->first()->id
            : $player->registrationIn($branchId)?->category_id;

        return $this->for($branchId, $categoryId);
    }
}
