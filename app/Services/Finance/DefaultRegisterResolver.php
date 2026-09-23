<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\Player;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Picks the cash register a payment lands in when none was chosen: the
 * category register of the player's branch, else that branch's treasury,
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

    /** @param Collection<int, FinanceAccount> $accounts active accounts */
    public function __construct(Collection $accounts)
    {
        $this->categoryRegisters = $accounts
            ->filter(fn (FinanceAccount $account) => $account->branch_id && $account->category_id)
            ->keyBy(fn (FinanceAccount $account) => $account->branch_id.':'.$account->category_id);

        $this->treasuries = $accounts
            ->filter(fn (FinanceAccount $account) => $account->branch_id && $account->is_treasury)
            ->keyBy('branch_id');

        $this->clubWide = $accounts
            ->filter(fn (FinanceAccount $account) => $account->type === 'cash'
                && ($account->is_treasury || ! $account->branch_id))
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->first();
    }

    public static function load(): self
    {
        return new self(FinanceAccount::query()
            ->where('is_active', true)
            ->get(['id', 'branch_id', 'category_id', 'type', 'is_treasury', 'sort_order']));
    }

    public function for(?int $branchId, ?int $categoryId): ?FinanceAccount
    {
        return ($branchId && $categoryId ? $this->categoryRegisters->get($branchId.':'.$categoryId) : null)
            ?? ($branchId ? $this->treasuries->get($branchId) : null)
            ?? $this->clubWide;
    }

    /** Expects the player's branches to be loaded; the first one is theirs. */
    public function forPlayer(Player $player): ?FinanceAccount
    {
        return $this->for($player->branches->first()?->id, $player->category_id);
    }

    /**
     * The register of a subscription tied to exactly one branch and one
     * category, or null when it spans several. Expects both relations loaded.
     */
    public function forSubscription(Subscription $subscription): ?FinanceAccount
    {
        if ($subscription->branches->count() !== 1 || $subscription->categories->count() !== 1) {
            return null;
        }

        return $this->categoryRegisters->get(
            $subscription->branches->first()->id.':'.$subscription->categories->first()->id
        );
    }
}
