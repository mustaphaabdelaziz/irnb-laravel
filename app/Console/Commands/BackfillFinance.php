<?php

namespace App\Console\Commands;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\FinanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillFinance extends Command
{
    protected $signature = 'finance:backfill';

    protected $description = 'Link existing transactions to fiscal years, chart-of-accounts categories and the cash account.';

    /** Map legacy free-text categories onto the seeded chart of accounts. */
    private array $map = [
        'subscription' => 'Subscriptions',
        'membership_fee' => 'Membership Fees',
        'donation' => 'Donations',
        'debts' => 'Arrears / Debts',
        'works' => 'Works / Construction',
        'supplies' => 'Supplies',
        'equipment_purchase' => 'Equipment',
        'equipment' => 'Equipment',
    ];

    public function handle(FinanceService $finance): int
    {
        // 1. Fiscal years for every year present in the data.
        $years = Transaction::query()
            ->selectRaw('DISTINCT COALESCE(fiscal_year, CAST(strftime("%Y", transaction_date) AS INTEGER)) as y')
            ->pluck('y')->filter()->sort()->values();

        foreach ($years as $y) {
            FiscalYear::firstOrCreate(
                ['year' => (int) $y],
                ['start_date' => "$y-01-01", 'end_date' => "$y-12-31", 'status' => 'open', 'opening_balance' => 0]
            );
        }
        $this->info('Fiscal years: '.$years->implode(', '));

        $cash = FinanceAccount::where('type', 'cash')->orderBy('id')->first();
        $linked = 0;

        // 2. Link each transaction.
        Transaction::query()->chunkById(200, function ($chunk) use (&$linked, $cash) {
            foreach ($chunk as $t) {
                $year = $t->fiscal_year ?: (int) Carbon::parse($t->transaction_date)->format('Y');
                $fy = FiscalYear::where('year', $year)->first();

                $name = $this->map[$t->category] ?? Str::title(str_replace(['_', '-'], ' ', (string) $t->category));
                $cat = FinanceCategory::firstOrCreate(
                    ['type' => $t->transaction_type, 'name' => $name],
                    ['is_active' => true, 'is_system' => false]
                );

                $t->forceFill([
                    'fiscal_year' => $year,
                    'fiscal_year_id' => $fy?->id,
                    'finance_category_id' => $cat->id,
                    'finance_account_id' => $t->finance_account_id ?: $cash?->id,
                ])->saveQuietly();
                $linked++;
            }
        });
        $this->info("Linked {$linked} transactions.");

        // 3. Recompute cached balances/totals.
        $finance->recomputeAccountBalances();
        FiscalYear::each(fn (FiscalYear $fy) => $finance->recomputeYear($fy));
        $this->info('Recomputed account balances and fiscal-year totals.');

        return self::SUCCESS;
    }
}
