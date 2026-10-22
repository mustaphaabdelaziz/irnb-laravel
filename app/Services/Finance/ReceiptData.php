<?php

namespace App\Services\Finance;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\Pdf\ClubHeader;
use App\Support\FinanceAccountLabel;
use App\Support\TransactionTitle;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * Everything a printed receipt shows (pdf.receipt and pdf.receipt-ticket),
 * built once so the A4 sheet and the thermal ticket never disagree.
 */
class ReceiptData
{
    /** Eager loads build() reads. */
    public const RELATIONS = [
        'recordedBy', 'receivedBy', ...TransactionTitle::RELATIONS, ...Transaction::FINANCE_ACCOUNT_LABEL,
    ];

    /**
     * @param  Collection<int, Transaction>|null  $lines  its payment group, when paid together
     * @return array<string, mixed>
     */
    public function build(Transaction $transaction, ReceiptPrint $print, ?Collection $lines = null): array
    {
        $transaction->loadMissing(self::RELATIONS);
        $grouped = $lines && $lines->count() > 1;

        $player = $transaction->related_entity_type === 'Player' && $transaction->related_entity_id
            ? Player::with(['category', 'branches'])->find($transaction->related_entity_id)
            : null;

        return [
            'club' => ClubHeader::data(),
            'transaction' => $transaction,
            'print' => $print,
            'receiptNumber' => str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT),
            'title' => TransactionTitle::for($transaction),
            'categoryLabel' => TransactionTitle::categoryLabel($transaction),
            'statusLabel' => UiLang::get(strtolower((string) $transaction->status), (string) $transaction->status),
            'paymentLabel' => $transaction->payment_method
                ? UiLang::get(strtolower((string) $transaction->payment_method), (string) $transaction->payment_method)
                : null,
            'accountLabel' => FinanceAccountLabel::for($transaction->financeAccount) ?: null,
            'player' => $player,
            'relatedName' => $player?->fullname,
            'branches' => $player?->branches->map(fn ($branch) => $branch->localized_name)->filter()->implode(' · ') ?: null,
            'subscription' => $this->subscription($transaction),
            // One block per payment of the group, then their total as the amount paid.
            'lines' => $grouped
                ? $lines->map(fn (Transaction $line) => $this->line($line))->values()->all()
                : null,
            'total' => $grouped ? round((float) $lines->sum('amount'), 2) : (float) $transaction->amount,
        ];
    }

    /**
     * One payment of a group: "Abonnement (2025/2026)" for a subscription, else
     * its title (the donation of what was paid beyond them).
     *
     * @return array{label: string, amount: float, subscription: ?array}
     */
    private function line(Transaction $line): array
    {
        $subscription = $this->subscription($line);
        $label = $subscription
            ? ($subscription['label'] ?: TransactionTitle::categoryLabel($line)).($subscription['season'] ? " ({$subscription['season']})" : '')
            : TransactionTitle::for($line);

        return ['label' => $label, 'amount' => (float) $line->amount, 'subscription' => $subscription];
    }

    /**
     * The subscription line this payment settles, with the balance right after
     * it: payments up to and including this one (by date, then id), so every
     * reprint shows the same figures as the original.
     *
     * @return array{label: ?string, season: ?string, owed: float, paid: float, remaining: float, exempt: bool}|null
     */
    private function subscription(Transaction $transaction): ?array
    {
        // Not the eager-loaded relation: TransactionTitle narrows it to the label columns.
        $line = $transaction->player_subscription_id
            ? PlayerSubscription::find($transaction->player_subscription_id)
            : null;
        if (! $line) {
            return null;
        }

        $date = $transaction->transaction_date?->format('Y-m-d');
        $paid = (float) $line->payments()
            ->where('archived', false)
            ->when($date, fn ($query) => $query->where(fn ($query) => $query
                ->whereDate('transaction_date', '<', $date)
                ->orWhere(fn ($query) => $query->whereDate('transaction_date', $date)->where('id', '<=', $transaction->id))))
            ->sum('amount');

        $owed = (float) $line->net_owed;

        return [
            'label' => TransactionTitle::subscriptionLabel($transaction),
            'season' => $line->year ? Subscription::seasonLabel((int) $line->year) : null,
            'owed' => $owed,
            'paid' => $paid,
            'remaining' => $line->isExempt() ? 0.0 : round(max(0.0, $owed - $paid), 2),
            'exempt' => $line->isExempt(),
        ];
    }
}
