<?php

namespace App\Services\Finance;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlayerPaymentRecorder
{
    /**
     * Record a subscription payment. Any amount beyond the subscription's remaining
     * balance is split off into a separate player-level donation.
     *
     * @return list<Transaction> the created transactions, the subscription payment first
     */
    public function subscription(
        PlayerSubscription $sub,
        Player $player,
        float $amount,
        ?string $method,
        ?string $description,
        ?int $userId,
        ?int $financeAccountId,
        ?DateTimeInterface $date = null,
    ): array {
        return DB::transaction(function () use ($sub, $player, $amount, $method, $description, $userId, $financeAccountId, $date) {
            $created = [];
            // remaining_amount is discount- and exemption-aware: only what is
            // genuinely still owed may land on the subscription, the rest is a donation.
            $remaining = (float) $sub->remaining_amount;
            $subPortion = min($amount, $remaining);
            $donationPortion = round($amount - $subPortion, 2);

            if ($subPortion > 0) {
                // Status is judged against what is owed after any discount, so paying
                // the net in full stamps the payment Paid rather than Partial.
                $status = app(ResolvePaymentStatusService::class)
                    ->handle((float) $sub->amount_paid + $subPortion, (float) $sub->net_owed);

                $transaction = Transaction::create([
                    'amount' => $subPortion,
                    'transaction_date' => $date ?? now(),
                    'transaction_type' => 'income',
                    'category' => 'subscription',
                    'description' => $description,
                    'payment_method' => $method ?? 'cash',
                    'related_entity_type' => 'Player',
                    'related_entity_id' => $player->id,
                    'player_subscription_id' => $sub->id,
                    'recorded_by_user_id' => $userId,
                    'finance_account_id' => $financeAccountId,
                    'status' => $status,
                ]);

                $sub->update(['transaction_id' => $transaction->id]);
                $created[] = $transaction;
            }

            if ($donationPortion > 0) {
                $created[] = $this->playerLevel($player, 'donation', $donationPortion, $method, $description, $userId, $financeAccountId, $date);
            }

            return $created;
        });
    }

    /**
     * Pay several subscriptions with one amount, in the order given: each is paid
     * in full before the next, so only the last one reached can end up partial.
     * What is left after all of them becomes a donation. The transactions share
     * a payment group, printed as one receipt.
     *
     * @param  Collection<int, PlayerSubscription>  $subs
     * @return list<Transaction>
     */
    public function group(
        Collection $subs,
        Player $player,
        float $amount,
        ?string $method,
        ?string $description,
        ?int $userId,
        ?int $financeAccountId,
        ?DateTimeInterface $date = null,
    ): array {
        $created = [];
        $left = $amount;

        foreach ($subs as $sub) {
            $portion = round(min($left, max(0.0, (float) $sub->remaining_amount)), 2);
            if ($portion <= 0) {
                continue;
            }

            array_push($created, ...$this->subscription($sub, $player, $portion, $method, $description, $userId, $financeAccountId, $date));
            $left = round($left - $portion, 2);
        }

        if ($left > 0) {
            $created[] = $this->playerLevel($player, 'donation', $left, $method, $description, $userId, $financeAccountId, $date);
        }

        if (count($created) > 1) {
            Transaction::whereKey(array_map(fn (Transaction $t) => $t->id, $created))
                ->update(['payment_group' => (string) Str::uuid()]);
        }

        return $created;
    }

    /**
     * «دفع دين»: the amount settles the player's unpaid obligations oldest season
     * first (see group); what is left over becomes a donation on the same receipt.
     *
     * @return list<Transaction>
     */
    public function debt(
        Player $player,
        float $amount,
        ?string $method,
        ?string $description,
        ?int $userId,
        ?int $financeAccountId,
        ?DateTimeInterface $date = null,
    ): array {
        return DB::transaction(fn () => $this->group(
            $this->unpaidOldestFirst($player),
            $player,
            $amount,
            $method,
            $description,
            $userId,
            $financeAccountId,
            $date,
        ));
    }

    /**
     * The obligations a debt payment settles, in the order it settles them:
     * oldest season first, then the order they were added. Exempt and settled
     * ones are left out (their remaining amount is zero).
     *
     * @return Collection<int, PlayerSubscription>
     */
    public function unpaidOldestFirst(Player $player): Collection
    {
        return $player->playerSubscriptions()
            ->orderBy('year')
            ->orderBy('id')
            ->get()
            ->filter(fn (PlayerSubscription $sub) => (float) $sub->remaining_amount > 0)
            ->values();
    }

    public function playerLevel(
        Player $player,
        string $category,
        float $amount,
        ?string $method,
        ?string $description,
        ?int $userId,
        ?int $financeAccountId,
        ?DateTimeInterface $date = null,
    ): Transaction {
        return Transaction::create([
            'amount' => $amount,
            'transaction_date' => $date ?? now(),
            'transaction_type' => 'income',
            'category' => $category,
            'description' => $description,
            'payment_method' => $method ?? 'cash',
            'related_entity_type' => 'Player',
            'related_entity_id' => $player->id,
            'player_subscription_id' => null,
            'recorded_by_user_id' => $userId,
            'finance_account_id' => $financeAccountId,
            'status' => 'Paid',
        ]);
    }
}
