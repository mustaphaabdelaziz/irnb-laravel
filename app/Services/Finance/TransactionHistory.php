<?php

namespace App\Services\Finance;

use App\Models\ActivityLog;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\ReceiptPrint;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Support\FinanceAccountLabel;
use App\Support\TransactionTitle;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * A transaction's story for its page: who recorded it and when, every edit
 * with old and new values, cancellation or correction, every receipt printed.
 * Read-only; built from the row itself, the activity log, the revisions, the
 * replacement rows and the print log.
 *
 * In an entry, `user` is the person at the keyboard. When an admin acted
 * logged in as another account, `as_user` is that account.
 */
final class TransactionHistory
{
    private const USER = 'id,name,firstname,lastname';

    /** The events that mean "this row was recorded". */
    private const RECORDING = [
        ActivityAction::TRANSACTION_RECORDED,
        ActivityAction::PAYMENT_RECORDED,
        ActivityAction::PAYMENT_EDITED,
    ];

    /** What a corrected player payment can differ by from its original. */
    private const CORRECTION_FIELDS = ['amount', 'payment_method', 'finance_account_id', 'description'];

    /** Entries of the same second, in the order they can only have happened. */
    private const ORDER = ['recorded' => 0, 'correction' => 0, 'edited' => 1, 'printed' => 2, 'cancelled' => 3, 'replaced' => 3];

    /** The fields whose stored value is an id shown by its name. */
    private const NAMED = ['finance_category_id', 'finance_account_id', 'related_entity_id', 'supplier_id', 'board_meeting_id'];

    /** @return list<array<string, mixed>> oldest first */
    public static function entries(Transaction $transaction): array
    {
        $logs = self::logsFor($transaction);
        $entries = [self::firstEntry($transaction, $logs)];

        foreach ($logs->where('action', ActivityAction::TRANSACTION_UPDATED) as $log) {
            $entries[] = self::entry('edited', $log->occurred_at, $log->user, $log->impersonator, $log->revision?->diff ?? []);
        }

        // An edit whose log row is gone: the values remain, the author does not.
        $orphans = TransactionRevision::where('transaction_id', $transaction->id)->whereNull('activity_log_id')->orderBy('id')->get();
        foreach ($orphans as $revision) {
            $entries[] = self::entry('edited', $revision->created_at, null, null, $revision->diff);
        }

        foreach ($logs->where('action', ActivityAction::TRANSACTION_CANCELLED) as $log) {
            $entries[] = self::entry('cancelled', $log->occurred_at, $log->user, $log->impersonator);
        }

        $replacements = Transaction::query()
            ->with('recordedBy:'.self::USER)
            ->where('replaces_transaction_id', $transaction->id)
            ->orderBy('id')
            ->get();
        if ($replacements->isNotEmpty()) {
            $first = $replacements->first();
            $entries[] = self::entry(
                'replaced',
                $first->created_at,
                $first->recordedBy,
                self::mainRowLog($first)?->impersonator,
                links: $replacements->modelKeys(),
            );
        }

        $prints = ReceiptPrint::with('printedBy:'.self::USER)
            ->where('transaction_number', $transaction->id)
            ->orderBy('copy_number')
            ->get();
        foreach ($prints as $print) {
            $entries[] = self::entry('printed', $print->created_at, $print->printedBy, null, print: [
                'copy_number' => $print->copy_number,
                'format' => $print->format,
                'code' => $print->displayCode(),
            ]);
        }

        usort($entries, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return self::presented($entries);
    }

    /**
     * Who recorded the transaction, and who last changed it (edit, cancellation
     * or correction). Not `updated_at`: bulk queries touch it too.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{recorded: ?array<string, mixed>, modified: ?array<string, mixed>}
     */
    public static function trace(array $entries): array
    {
        $entries = collect($entries);
        $pick = fn (?array $entry) => $entry
            ? ['type' => $entry['type'], 'at' => $entry['at'], 'user' => $entry['user'], 'as_user' => $entry['as_user']]
            : null;

        return [
            'recorded' => $pick($entries->first(fn (array $e) => in_array($e['type'], ['recorded', 'correction'], true))),
            'modified' => $pick($entries->last(fn (array $e) => in_array($e['type'], ['edited', 'cancelled', 'replaced'], true))),
        ];
    }

    /**
     * For an archived transaction: who cancelled it and when, and the rows
     * recorded in its place when it was a correction. Null for a live one.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return ?array{at: ?string, user: ?string, as_user: ?string, replaced_by: list<int>}
     */
    public static function cancellation(Transaction $transaction, array $entries): ?array
    {
        if (! $transaction->archived) {
            return null;
        }

        $entries = collect($entries);
        $replaced = $entries->firstWhere('type', 'replaced');
        $source = $entries->last(fn (array $e) => $e['type'] === 'cancelled') ?? $replaced;

        return [
            // Archived by a path that left no event (an old fix): the row's own clock.
            'at' => $source['at'] ?? $transaction->updated_at?->format('Y-m-d H:i'),
            'user' => $source['user'] ?? null,
            'as_user' => $source['as_user'] ?? null,
            'replaced_by' => array_column($replaced['links'] ?? [], 'id'),
        ];
    }

    /**
     * The payments taken on the same receipt, this one included, with their
     * total. Null when the transaction was paid alone.
     *
     * @return ?array{total: float, lines: list<array{id: int, title: string, amount: float, current: bool}>}
     */
    public static function group(Transaction $transaction): ?array
    {
        if (! $transaction->payment_group) {
            return null;
        }

        $lines = $transaction->receiptLines(TransactionTitle::RELATIONS);
        if ($lines->count() < 2) {
            return null;
        }

        return [
            'total' => round((float) $lines->sum(fn (Transaction $line) => (float) $line->amount), 2),
            'lines' => $lines->map(fn (Transaction $line) => [
                'id' => $line->id,
                'title' => TransactionTitle::for($line),
                'amount' => (float) $line->amount,
                'current' => $line->is($transaction),
            ])->values()->all(),
        ];
    }

    /** The row itself: recorded, or recorded as the correction of another payment. */
    private static function firstEntry(Transaction $transaction, Collection $logs): array
    {
        $log = self::recordingLog($logs) ?? self::mainRowLog($transaction);
        $original = $transaction->replaces_transaction_id
            ? Transaction::find($transaction->replaces_transaction_id)
            : null;

        if (! $original) {
            return self::entry('recorded', $transaction->created_at, $transaction->recordedBy ?? $log?->user, $log?->impersonator);
        }

        // One payment corrected into one: say what differs. Split into several
        // rows (subscription + donation), an amount comparison would mislead.
        $changes = Transaction::where('replaces_transaction_id', $original->id)->count() === 1
            ? array_intersect_key(
                TransactionChanges::between(TransactionChanges::snapshot($original), TransactionChanges::snapshot($transaction)),
                array_flip(self::CORRECTION_FIELDS),
            )
            : [];

        return self::entry(
            'correction',
            $transaction->created_at,
            $transaction->recordedBy ?? $log?->user,
            $log?->impersonator,
            $changes,
            [$original->id],
        );
    }

    /**
     * The transaction's own logs. A log older than the row belongs to an
     * earlier transaction that had the same id: finance reset deletes
     * transactions but keeps the activity log, and SQLite can reuse the id.
     *
     * @return Collection<int, ActivityLog>
     */
    private static function logsFor(Transaction $transaction): Collection
    {
        return ActivityLog::query()
            ->with(['user:'.self::USER, 'impersonator:'.self::USER, 'revision'])
            ->where('subject_type', $transaction->getMorphClass())
            ->where('subject_id', $transaction->id)
            ->when($transaction->created_at, fn ($query, $createdAt) => $query->where('occurred_at', '>=', $createdAt))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * One request records one event, on its main row. The other rows it
     * created (the rest of a grouped payment, the rows of a split correction)
     * read who did it — and whether they were logged in as someone — there.
     */
    private static function mainRowLog(Transaction $transaction): ?ActivityLog
    {
        if ($transaction->replaces_transaction_id) {
            $actions = [ActivityAction::PAYMENT_EDITED];
            $siblings = Transaction::query()->select('id')->where('replaces_transaction_id', $transaction->replaces_transaction_id);
        } elseif ($transaction->payment_group) {
            $actions = [ActivityAction::PAYMENT_RECORDED, ActivityAction::TRANSACTION_RECORDED];
            $siblings = Transaction::query()->select('id')
                ->where('payment_group', $transaction->payment_group)
                ->whereNull('replaces_transaction_id');
        } else {
            return null;
        }

        return ActivityLog::query()
            ->with(['user:'.self::USER, 'impersonator:'.self::USER])
            ->where('subject_type', $transaction->getMorphClass())
            ->whereIn('subject_id', $siblings)
            ->whereIn('action', $actions)
            // Recorded once every row of the request exists.
            ->when($transaction->created_at, fn ($query, $createdAt) => $query->where('occurred_at', '>=', $createdAt))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();
    }

    private static function recordingLog(Collection $logs): ?ActivityLog
    {
        return $logs->first(fn (ActivityLog $log) => in_array($log->action, self::RECORDING, true));
    }

    /**
     * @param  array<string, array<string, mixed>>  $changes  as stored: { field: { old, new, … } }
     * @param  list<int>  $links
     */
    private static function entry(
        string $type,
        ?DateTimeInterface $at,
        ?User $user,
        ?User $impersonator,
        array $changes = [],
        array $links = [],
        ?array $print = null,
    ): array {
        return [
            'type' => $type,
            'at' => $at?->format('Y-m-d H:i'),
            'user' => ($impersonator ?? $user)?->fullname,
            'as_user' => $impersonator ? $user?->fullname : null,
            'changes' => $changes,
            'links' => array_map(fn (int $id) => ['id' => $id], $links),
            'print' => $print,
            'sort' => [$at?->getTimestamp() ?? 0, self::ORDER[$type]],
        ];
    }

    /**
     * Stored diffs become display rows: a category, register or player is shown
     * by its name — today's when it still exists, else the one kept at edit time.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function presented(array $entries): array
    {
        $names = self::names($entries);

        return array_map(function (array $entry) use ($names) {
            $rows = [];
            foreach ($entry['changes'] as $field => $change) {
                $rows[] = [
                    'field' => $field,
                    'old' => self::shown($field, $change, 'old', $names),
                    'new' => self::shown($field, $change, 'new', $names),
                ];
            }

            $entry['changes'] = $rows;
            unset($entry['sort']);

            return $entry;
        }, $entries);
    }

    /** @param array<string, array<int, string>> $names */
    private static function shown(string $field, array $change, string $side, array $names): mixed
    {
        $value = $change[$side] ?? null;

        if (! in_array($field, self::NAMED, true) || $value === null) {
            return $value;
        }

        return $names[$field][$value] ?? $change[$side.'_label'] ?? '#'.$value;
    }

    /**
     * Today's names of every category, register and player the entries mention: one query per kind.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array<int, string>>
     */
    private static function names(array $entries): array
    {
        $ids = fn (string $field) => collect($entries)
            ->map(fn (array $entry) => $entry['changes'][$field] ?? null)
            ->filter()
            ->flatMap(fn (array $change) => [$change['old'] ?? null, $change['new'] ?? null])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categories = $ids('finance_category_id');
        $accounts = $ids('finance_account_id');
        $players = $ids('related_entity_id');

        return [
            'finance_category_id' => $categories === [] ? [] : FinanceCategory::whereIn('id', $categories)->get()
                ->mapWithKeys(fn (FinanceCategory $category) => [$category->id => $category->localized_name])->all(),
            'finance_account_id' => $accounts === [] ? [] : FinanceAccount::with(['branch', 'category'])->whereIn('id', $accounts)->get()
                ->mapWithKeys(fn (FinanceAccount $account) => [$account->id => FinanceAccountLabel::for($account)])->all(),
            'related_entity_id' => $players === [] ? [] : Player::whereIn('id', $players)->get(['id', ...Player::NAME_COLUMNS])
                ->mapWithKeys(fn (Player $player) => [$player->id => $player->fullname])->all(),
        ];
    }
}
