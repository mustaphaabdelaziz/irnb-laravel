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
            $closingBefore = $years->mapWithKeys(fn (FiscalYear $fy) => [$fy->year => $fy->closing_balance]);
            foreach ($years as $fy) {
                $fy->forceFill([
                    'start_date' => FiscalPeriod::start($fy->year)->toDateString(),
                    'end_date' => FiscalPeriod::end($fy->year)->toDateString(),
                ])->save();
                $this->finance->recomputeYear($fy);
            }

            $this->carryClosedBalancesForward($closingBefore->all());

            return ['moved' => $moved, 'years' => $years->count()];
        });
    }

    /**
     * Rows moved between seasons, so a closed season's closing balance may
     * have changed and the season after it must open on the new figure — even
     * when that season is closed too, since its contents changed as well. The
     * first closed season of a chain keeps the opening balance the club set.
     *
     * Only a season whose closed predecessor's closing actually changed in this
     * run (or was itself re-chained here) is touched, so an opening balance the
     * club typed by hand survives a run that moved nothing into the season
     * before it. A closed → open → closed sequence is deliberately left alone:
     * an open season stores no closing balance to hand on.
     *
     * @param  array<int, mixed>  $closingBefore  closing_balance per year before this run's recompute
     */
    private function carryClosedBalancesForward(array $closingBefore): void
    {
        $years = FiscalYear::query()->orderBy('year')->get()->keyBy('year');
        $rechained = [];

        foreach ($years as $year) {
            $previous = $years->get($year->year - 1);
            if (! $previous?->isClosed()) {
                continue;
            }

            $closingChanged = round((float) $previous->closing_balance, 2)
                !== round((float) ($closingBefore[$previous->year] ?? 0), 2);
            if (! $closingChanged && ! isset($rechained[$previous->year])) {
                continue;
            }

            // Same model instances throughout: recomputeYear updates a closed
            // row's closing_balance in place, and the next iteration reads it.
            $year->opening_balance = $previous->closing_balance;
            $this->finance->recomputeYear($year);
            $rechained[$year->year] = true;
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
