<?php

namespace App\Services\Finance;

use App\Models\FiscalYear;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * One-time move from calendar fiscal years to seasons: every transaction is
 * re-keyed to the season of its date and every fiscal-year row gets season
 * dates and fresh totals. Writes through the query builder on purpose — the
 * observer would refuse rows in closed years, and this is bookkeeping, not
 * a change to anyone's money. Safe to run again: a second run moves nothing.
 */
class SeasonRebucketer
{
    public function __construct(private FinanceService $finance) {}

    /** @return array{moved:int, years:int} */
    public function run(): array
    {
        return DB::transaction(function () {
            $moved = 0;
            $rowFor = [];

            DB::table('transactions')->whereNotNull('transaction_date')->orderBy('id')
                ->select('id', 'transaction_date', 'fiscal_year', 'fiscal_year_id')
                ->chunkById(500, function ($chunk) use (&$moved, &$rowFor) {
                    foreach ($chunk as $tx) {
                        $key = FiscalPeriod::keyForDate($tx->transaction_date);
                        $rowFor[$key] ??= $this->yearRow($key)->id;

                        if ((int) $tx->fiscal_year === $key && (int) $tx->fiscal_year_id === $rowFor[$key]) {
                            continue;
                        }

                        DB::table('transactions')->where('id', $tx->id)
                            ->update(['fiscal_year' => $key, 'fiscal_year_id' => $rowFor[$key]]);
                        $moved++;
                    }
                });

            $years = FiscalYear::all();
            foreach ($years as $fy) {
                $fy->forceFill([
                    'start_date' => FiscalPeriod::start($fy->year)->toDateString(),
                    'end_date' => FiscalPeriod::end($fy->year)->toDateString(),
                ])->save();
                $this->finance->recomputeYear($fy);
            }

            $this->carryClosedBalancesForward();

            return ['moved' => $moved, 'years' => $years->count()];
        });
    }

    /**
     * A closed season's closing balance may have changed now that its rows
     * moved, so the open season after it must open on the new figure — the
     * same rule closeYear follows. A season that is itself closed stands.
     */
    private function carryClosedBalancesForward(): void
    {
        $years = FiscalYear::query()->orderBy('year')->get()->keyBy('year');

        foreach ($years as $closed) {
            $next = $years->get($closed->year + 1);
            if (! $closed->isClosed() || ! $next || $next->isClosed()) {
                continue;
            }

            $next->opening_balance = $closed->closing_balance;
            $this->finance->recomputeYear($next);
        }
    }

    private function yearRow(int $key): FiscalYear
    {
        return FiscalYear::firstOrCreate(['year' => $key], [
            'start_date' => FiscalPeriod::start($key)->toDateString(),
            'end_date' => FiscalPeriod::end($key)->toDateString(),
            'status' => 'open',
            'opening_balance' => 0,
        ]);
    }
}
