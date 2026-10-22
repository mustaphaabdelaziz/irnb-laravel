<?php

namespace App\Services\Finance;

use App\Models\ReceiptPrint;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionTitle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records every printed receipt and checks a paper one against the books.
 *
 * Each print gets its own random 12-digit code, shown in the QR and in clear
 * under it. Digits only: a USB scanner "types" the QR, and on an AZERTY or
 * Arabic keyboard letters and punctuation come out wrong, digits can be mapped
 * back (normalizeCode). The code is a lookup key, not a signature, so it keeps
 * working when the app key changes (a desktop reinstall).
 */
class ReceiptPrinter
{
    /** Reopening the same receipt within this window (PDF viewer reload, double click) is not a new copy. */
    private const REUSE_SECONDS = 60;

    /** What the paper states; compared field by field in differences(). */
    private const CHECKED_FIELDS = [
        'amount', 'date', 'type', 'status', 'payment_method', 'player_id', 'player_subscription_id', 'archived',
    ];

    /** The digit each key of the AZERTY top row types without Shift. */
    private const AZERTY_DIGITS = [
        '&' => '1', 'é' => '2', '"' => '3', "'" => '4', '(' => '5',
        '-' => '6', 'è' => '7', '_' => '8', 'ç' => '9', 'à' => '0',
    ];

    private const ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** @param  Collection<int, Transaction>|null  $lines  the payment group printed with it */
    public function record(Transaction $transaction, string $format, ?User $user, ?Collection $lines = null): ReceiptPrint
    {
        $recent = ReceiptPrint::query()
            ->where('transaction_id', $transaction->id)
            ->where('format', $format)
            ->where('printed_by_user_id', $user?->id)
            ->where('created_at', '>=', now()->subSeconds(self::REUSE_SECONDS))
            ->latest('id')
            ->first();

        if ($recent) {
            return $recent;
        }

        return DB::transaction(fn () => ReceiptPrint::create([
            'transaction_id' => $transaction->id,
            'transaction_number' => $transaction->id,
            'code' => $this->newCode(),
            'copy_number' => ReceiptPrint::query()->where('transaction_number', $transaction->id)->count() + 1,
            'format' => $format,
            'printed_by_user_id' => $user?->id,
            'snapshot' => $this->snapshot($transaction, $lines),
        ]));
    }

    /**
     * The transaction as the receipt prints it. Reads TransactionTitle::RELATIONS
     * when loaded; never lazy-loads them. A payment group prints its total, every
     * subscription it settles, and one entry per line.
     *
     * @param  Collection<int, Transaction>|null  $lines
     * @return array<string, mixed>
     */
    public function snapshot(Transaction $transaction, ?Collection $lines = null): array
    {
        $single = $this->single($transaction);
        if (! $lines || $lines->count() < 2) {
            return $single;
        }

        $lines = $lines->values();
        $each = $lines->map(fn (Transaction $line) => $this->single($line));
        $statuses = $each->pluck('status')->unique();

        return [
            ...$single,
            'amount' => number_format((float) $lines->sum('amount'), 2, '.', ''),
            'status' => $statuses->count() === 1 ? $statuses->first() : 'Partial',
            'player_subscription_id' => $each->pluck('player_subscription_id')->filter()->implode(','),
            'subscription' => $each->pluck('subscription')->filter()->implode(' + ') ?: null,
            'title' => $each->pluck('title')->filter()->implode(' + '),
            'lines' => $lines->map(fn (Transaction $line, int $i) => [
                'transaction_id' => $line->id,
                'amount' => $each[$i]['amount'],
                'title' => $each[$i]['title'],
                'status' => $each[$i]['status'],
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function single(Transaction $transaction): array
    {
        $player = $transaction->related_entity_type === 'Player' && $transaction->relationLoaded('relatedPlayer')
            ? $transaction->relatedPlayer
            : null;

        return [
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'date' => $transaction->transaction_date?->format('Y-m-d'),
            'type' => $transaction->transaction_type,
            'status' => $transaction->status,
            'payment_method' => $transaction->payment_method,
            'payment_reference' => $transaction->payment_reference,
            'player_id' => $transaction->related_entity_type === 'Player' ? $transaction->related_entity_id : null,
            'player_name' => $player?->fullname,
            'membership_id' => $player?->membership_id,
            'player_subscription_id' => $transaction->player_subscription_id,
            'subscription' => TransactionTitle::subscriptionLabel($transaction),
            'title' => TransactionTitle::for($transaction),
            'archived' => (bool) $transaction->archived,
        ];
    }

    /**
     * Fields where the transaction no longer says what the paper says.
     *
     * @return list<array{field: string, printed: mixed, current: mixed}>
     */
    public function differences(ReceiptPrint $print, Transaction $current): array
    {
        // A grouped receipt is checked against the group as it stands now.
        $now = $this->snapshot($current, isset($print->snapshot['lines']) ? $current->receiptLines(TransactionTitle::RELATIONS) : null);
        $printed = $print->snapshot;
        $changes = [];

        foreach (self::CHECKED_FIELDS as $field) {
            $was = $printed[$field] ?? null;
            $is = $now[$field] ?? null;

            // Ids come back as int or string depending on the driver.
            if ((string) $was !== (string) $is) {
                $changes[] = ['field' => $field, 'printed' => $was, 'current' => $is];
            }
        }

        return $changes;
    }

    /** Twelve digits from whatever a scanner or a person typed, or null when it is not a code. */
    public function normalizeCode(string $input): ?string
    {
        $input = strtr(trim($input), self::ARABIC_DIGITS);
        $digits = preg_replace('/\D/u', '', $input);

        if (strlen($digits) !== 12) {
            // A scanner set up for QWERTY on an AZERTY keyboard types "&é"'(" for "12345".
            $digits = preg_replace('/\D/u', '', strtr($input, self::AZERTY_DIGITS));
        }

        return strlen($digits) === 12 ? $digits : null;
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= (string) random_int(0, 9);
            }
        } while (ReceiptPrint::query()->where('code', $code)->exists());

        return $code;
    }
}
