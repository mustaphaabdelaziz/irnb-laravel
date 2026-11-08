<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Transaction;
use App\Services\Finance\PlayerPaymentRecorder;
use App\Services\Finance\RecalculatePlayerDebtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair: «دفع دين» used to record flat income linked to no obligation,
 * so the money reached the caisse while the player kept the whole debt. Each such
 * payment is replaced (archive + recreate, as an edit does) by the same money on
 * the same day settling the player's unpaid obligations oldest season first.
 */
class FixDebtPayments extends Command
{
    protected $signature = 'finance:fix-debt-payments {--force : Apply the repair (default is a dry run)}';

    protected $description = 'Make old «دفع دين» payments settle the player\'s debt, oldest season first (dry run unless --force)';

    public function handle(PlayerPaymentRecorder $payments, RecalculatePlayerDebtService $debt): int
    {
        $force = (bool) $this->option('force');

        $legacy = Transaction::query()
            ->where('category', 'debt_payment')
            ->where('transaction_type', 'income')
            ->where('archived', false)
            ->whereNull('player_subscription_id')
            ->where('related_entity_type', 'Player')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        if ($legacy->isEmpty()) {
            $this->info('No unlinked «دفع دين» payments: nothing to repair.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($legacy as $tx) {
            $player = Player::find($tx->related_entity_id);
            if (! $player) {
                $this->warn("Payment #{$tx->id}: player {$tx->related_entity_id} not found, skipped.");

                continue;
            }

            $owed = round((float) $payments->unpaidOldestFirst($player)->sum(fn ($sub) => (float) $sub->remaining_amount), 2);
            $rows[] = [$tx->id, $player->id, $tx->transaction_date?->format('Y-m-d'), (float) $tx->amount, $owed];

            if (! $force) {
                continue;
            }

            DB::transaction(function () use ($tx, $player, $payments) {
                $tx->update(['archived' => true]);

                $created = $payments->debt(
                    $player,
                    (float) $tx->amount,
                    $tx->payment_method,
                    $tx->description,
                    $tx->recorded_by_user_id,
                    $tx->finance_account_id,
                    $tx->transaction_date,
                );

                // A payment that was already part of a grouped receipt stays in it.
                if ($tx->payment_group && $created !== []) {
                    Transaction::whereKey(array_map(fn (Transaction $t) => $t->id, $created))
                        ->update(['payment_group' => $tx->payment_group]);
                }
            });

            $debt->forPlayer($player->fresh());
        }

        $this->table(['payment', 'player', 'date', 'amount', 'debt before'], $rows);

        if ($force) {
            $this->info('Repaired '.count($rows).' payment(s).');
        } else {
            $this->warn('[dry-run] Would repair '.count($rows).' payment(s). Re-run with --force.');
        }

        return self::SUCCESS;
    }
}
