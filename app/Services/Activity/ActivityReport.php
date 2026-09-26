<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of activity tracking: who did what in a period. Every figure
 * comes from GROUP BY queries or a single fetch per figure, so the number of
 * queries never grows with the number of events.
 */
final class ActivityReport
{
    /** Actions whose properties carry an `amount` (see the action-code table). */
    private const AMOUNT_ACTIONS = [
        ActivityAction::TRANSACTION_RECORDED,
        ActivityAction::TRANSACTION_IMPORTED,
        ActivityAction::PAYMENT_RECORDED,
        ActivityAction::PAYMENT_EDITED,
        ActivityAction::TRANSACTION_CANCELLED,
        ActivityAction::TRANSFER_RECORDED,
    ];

    /** Which subject model a quality kind is read from: its `archived` flag. */
    private const QUALITY_SUBJECTS = [
        'cancelled' => Transaction::class,
        'archived' => Player::class,
    ];

    /**
     * One row per user with at least one event in the period, plus every
     * active user with none (all zeros), ranked by payments, then total
     * events, then name.
     *
     * @return list<array{user: array{id: int, name: string}, areas: array<string, int>, payments: array{count: int, amount: float}, total: int}>
     */
    public static function comparison(ActivityPeriod $period): array
    {
        $counts = self::inPeriod($period)
            ->whereNotNull('user_id')
            ->select('user_id', 'action')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('user_id', 'action')
            ->get();

        $amounts = [];
        foreach (self::inPeriod($period)
            ->whereNotNull('user_id')
            ->where('action', ActivityAction::PAYMENT_RECORDED)
            ->get(['user_id', 'properties']) as $row) {
            $amounts[(int) $row->user_id] = ($amounts[(int) $row->user_id] ?? 0.0) + self::amountOf($row->properties);
        }

        $areaOf = self::areaIndex();
        $blank = array_fill_keys(array_keys(ActivityAction::AREAS), 0);
        $stats = [];
        foreach ($counts as $row) {
            $id = (int) $row->user_id;
            $stats[$id] ??= ['areas' => $blank, 'payments' => 0, 'total' => 0];
            $n = (int) $row->aggregate;
            if (isset($areaOf[$row->action])) {
                $stats[$id]['areas'][$areaOf[$row->action]] += $n;
            }
            if ($row->action === ActivityAction::PAYMENT_RECORDED) {
                $stats[$id]['payments'] = $n;
            }
            $stats[$id]['total'] += $n;
        }

        $users = User::query()
            ->where(fn (Builder $q) => $q
                ->whereIn('id', array_keys($stats))
                ->orWhere(fn (Builder $active) => $active
                    ->where('is_user', true)
                    ->where('is_active', true)
                    ->where('approved', true)))
            ->get(['id', 'name']);

        $rows = $users->map(function (User $user) use ($stats, $amounts, $blank) {
            $stat = $stats[$user->id] ?? ['areas' => $blank, 'payments' => 0, 'total' => 0];

            return [
                'user' => ['id' => (int) $user->id, 'name' => (string) $user->name],
                'areas' => $stat['areas'],
                'payments' => ['count' => $stat['payments'], 'amount' => round($amounts[$user->id] ?? 0.0, 2)],
                'total' => $stat['total'],
            ];
        })->all();

        usort($rows, fn (array $a, array $b) => [$b['payments']['count'], $b['total']] <=> [$a['payments']['count'], $a['total']]
            ?: strnatcasecmp($a['user']['name'], $b['user']['name'])
            ?: $a['user']['id'] <=> $b['user']['id']);

        return $rows;
    }

    /**
     * Every action the user performed in the period (count > 0), in the
     * action-code order, with summed amounts and "later cancelled/archived".
     *
     * @return list<array{action: string, area: string, count: int, amount: ?float, quality: ?array{kind: string, count: int}}>
     */
    public static function summary(int $userId, ActivityPeriod $period): array
    {
        $counts = self::inPeriod($period)
            ->where('user_id', $userId)
            ->select('action')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        $amounts = [];
        foreach (self::inPeriod($period)
            ->where('user_id', $userId)
            ->whereIn('action', self::AMOUNT_ACTIONS)
            ->get(['action', 'properties']) as $row) {
            $amounts[$row->action] = ($amounts[$row->action] ?? 0.0) + self::amountOf($row->properties);
        }

        $quality = self::qualityCounts($userId, $period);
        $areaOf = self::areaIndex();
        $rows = [];

        foreach (ActivityAction::ALL as $action) {
            $count = (int) ($counts[$action] ?? 0);
            if ($count === 0) {
                continue;
            }

            $kind = ActivityAction::QUALITY[$action] ?? null;

            $rows[] = [
                'action' => $action,
                'area' => $areaOf[$action],
                'count' => $count,
                'amount' => in_array($action, self::AMOUNT_ACTIONS, true) ? round($amounts[$action] ?? 0.0, 2) : null,
                'quality' => $kind === null ? null : ['kind' => $kind, 'count' => $quality[$action] ?? 0],
            ];
        }

        return $rows;
    }

    /**
     * The user's events of one action in the period, newest first, each with
     * its subject resolved (one query per subject type on the page).
     *
     * @return LengthAwarePaginator<int, array{id: int, occurred_at: string, properties: array, subject: array{label: string, url: ?string, deleted: bool}}>
     */
    public static function entries(int $userId, string $action, ActivityPeriod $period, int $perPage = 25, ?int $page = null): LengthAwarePaginator
    {
        $paginator = ActivityLog::query()
            ->where('user_id', $userId)
            ->where('action', $action)
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        ActivitySubjectLink::preload($paginator->getCollection());

        return $paginator->through(fn (ActivityLog $log) => [
            'id' => $log->id,
            'occurred_at' => $log->occurred_at->toIso8601String(),
            'properties' => $log->properties ?? [],
            'subject' => ActivitySubjectLink::for($log),
        ]);
    }

    /**
     * Per quality action, how many of the user's events point at a subject
     * that still exists and is now archived. One JOIN query per subject model.
     *
     * @return array<string, int>
     */
    private static function qualityCounts(int $userId, ActivityPeriod $period): array
    {
        $counts = [];

        foreach (self::QUALITY_SUBJECTS as $kind => $class) {
            $actions = array_keys(array_filter(ActivityAction::QUALITY, fn (string $k) => $k === $kind));
            $model = new $class;
            $table = $model->getTable();

            $found = DB::table('activity_logs')
                ->join($table, "{$table}.{$model->getKeyName()}", '=', 'activity_logs.subject_id')
                ->where('activity_logs.subject_type', $model->getMorphClass())
                ->where('activity_logs.user_id', $userId)
                ->whereIn('activity_logs.action', $actions)
                ->whereBetween('activity_logs.occurred_at', [$period->start, $period->end])
                ->where("{$table}.archived", true)
                ->select('activity_logs.action')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy('activity_logs.action')
                ->pluck('aggregate', 'action');

            foreach ($found as $action => $n) {
                $counts[$action] = (int) $n;
            }
        }

        return $counts;
    }

    private static function inPeriod(ActivityPeriod $period): QueryBuilder
    {
        return DB::table('activity_logs')->whereBetween('occurred_at', [$period->start, $period->end]);
    }

    /** @return array<string, string> action => area */
    private static function areaIndex(): array
    {
        $index = [];
        foreach (ActivityAction::AREAS as $area => $actions) {
            foreach ($actions as $action) {
                $index[$action] = $area;
            }
        }

        return $index;
    }

    /** The `amount` inside a raw JSON properties column; int or float, anything else counts 0. */
    private static function amountOf(?string $properties): float
    {
        $amount = $properties === null ? null : (json_decode($properties, true)['amount'] ?? null);

        return is_numeric($amount) ? (float) $amount : 0.0;
    }
}
