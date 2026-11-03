<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\Dashboard\Support\DeltaCalculator;
use App\Services\Dashboard\Support\MonthBucket;
use App\Services\Finance\BudgetVsActual;
use App\Services\FinanceService;
use App\Support\FiscalPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(private FinanceService $finance) {}

    public function index(Request $request): Response
    {
        $years = FiscalYear::query()->orderByDesc('year')->get()->map(fn (FiscalYear $fy) => [
            'id' => $fy->id,
            'year' => $fy->year,
            'season_label' => FiscalPeriod::label($fy->year),
            'status' => $fy->status,
            'opening_balance' => (float) $fy->opening_balance,
            'total_income' => (float) $fy->total_income,
            'total_expense' => (float) $fy->total_expense,
            'net' => (float) $fy->total_income - (float) $fy->total_expense,
            'closing_balance' => $fy->closing_balance !== null ? (float) $fy->closing_balance : null,
            'closed_at' => $fy->closed_at?->toDateString(),
        ]);

        $selectedYear = (int) ($request->query('year') ?: (
            $years->firstWhere('year', FiscalPeriod::currentKey())['year'] ?? ($years->first()['year'] ?? FiscalPeriod::currentKey())
        ));
        $fiscalYear = FiscalYear::where('year', $selectedYear)->first();
        $detail = $fiscalYear ? $this->yearDetail($fiscalYear) : null;
        if ($detail !== null) {
            $detail['deltas'] = $this->seasonDeltas($detail, $years->firstWhere('year', $selectedYear - 1));
        }

        return Inertia::render('Finance/Index', [
            'years' => $years,
            'selectedYear' => $selectedYear,
            'detail' => $detail,
            'accounts' => FinanceAccount::query()
                // The Fonds Initial stays out of sight unless it still holds money.
                ->where(fn ($query) => $query->where('is_opening_fund', false)->orWhere('current_balance', '!=', 0))
                ->where(function ($query) {
                    $query->where('is_treasury', true)
                        ->orWhere(fn ($root) => $root->whereNull('branch_id')->whereNull('parent_account_id'));
                })
                ->with('branch:id,name,name_ar,name_fr,name_en')
                ->orderBy('sort_order')->orderBy('id')->get(),
            'allTime' => [
                'income' => (float) Transaction::where('archived', false)->where('transaction_type', 'income')->sum('amount'),
                'expense' => (float) Transaction::where('archived', false)->where('transaction_type', 'expense')->sum('amount'),
            ],
        ]);
    }

    public function settings(): Response
    {
        // budgets keyed as { fiscal_year_id: { finance_category_id: planned_amount } }
        $budgets = [];
        foreach (Budget::all() as $b) {
            $budgets[$b->fiscal_year_id][$b->finance_category_id] = (float) $b->planned_amount;
        }

        return Inertia::render('Finance/Settings', [
            'categories' => FinanceCategory::withCount('transactions')
                ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(),
            'years' => $this->yearsWithDeletability(),
            'budgets' => $budgets,
            'seasonOptions' => collect(range(FiscalPeriod::currentKey() + 1, FiscalPeriod::currentKey() - 5))
                ->map(fn (int $key) => ['value' => $key, 'label' => FiscalPeriod::label($key)])
                ->values(),
        ]);
    }

    /**
     * The fiscal years, each flagged `can_delete` by the same rule as
     * FiscalYear::isDeletable(), from one query rather than one per year.
     */
    private function yearsWithDeletability(): Collection
    {
        $active = Transaction::where('archived', false)
            ->select('fiscal_year', 'fiscal_year_id')
            ->distinct()
            ->get();
        $busyIds = $active->pluck('fiscal_year_id')->filter()->flip();
        $busyYears = $active->pluck('fiscal_year')->filter()->flip();

        return FiscalYear::orderByDesc('year')->get()
            ->each(fn (FiscalYear $fy) => $fy->setAttribute('can_delete', ! $fy->isClosed()
                && ! $busyIds->has($fy->id)
                && ! $busyYears->has($fy->year)));
    }

    /**
     * Change against the season before, from the season list already loaded —
     * no extra query. No previous season, or one with nothing booked, means no
     * badge rather than a made-up +100%.
     *
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>|null  $previous
     * @return array{income: ?array, expense: ?array, net: ?array}
     */
    private function seasonDeltas(array $detail, ?array $previous): array
    {
        return [
            'income' => DeltaCalculator::compute($detail['income'], $previous['total_income'] ?? null),
            'expense' => DeltaCalculator::compute($detail['expense'], $previous['total_expense'] ?? null, DeltaCalculator::DOWN_GOOD),
            'net' => DeltaCalculator::compute($detail['net'], $previous['net'] ?? null),
        ];
    }

    /**
     * Rich detail for one fiscal year: category breakdown, monthly trend,
     * budget vs actual.
     *
     * @return array<string, mixed>
     */
    private function yearDetail(FiscalYear $fy): array
    {
        $year = $fy->year;

        $byCategory = DB::table('transactions')
            ->where('archived', false)
            ->where('fiscal_year', $year)
            ->groupBy('transaction_type', 'finance_category_id')
            ->selectRaw('transaction_type as type, finance_category_id as category_id, SUM(amount) as total, COUNT(*) as count')
            ->get();

        // Names through the model so the locale fallback lives in one place.
        $categories = FinanceCategory::findMany($byCategory->pluck('category_id')->filter())->keyBy('id');
        $byCategory->each(function ($row) use ($categories) {
            $category = $categories->get($row->category_id);
            $row->name = $category?->localized_name;
            $row->color = $category?->color;
        });

        $income = $byCategory->where('type', 'income')->sortByDesc('total')->values();
        $expense = $byCategory->where('type', 'expense')->sortByDesc('total')->values();

        // Monthly income/expense trend.
        $monthlyRows = DB::table('transactions')
            ->where('archived', false)->where('fiscal_year', $year)
            ->groupBy('m', 'transaction_type')
            ->selectRaw(MonthBucket::monthNumber('transaction_date').' as m, transaction_type, SUM(amount) as total')
            ->get();
        $monthly = [];
        foreach (FiscalPeriod::months() as $m) {
            $monthly[] = [
                'month' => $m,
                'income' => (float) $monthlyRows->where('m', $m)->where('transaction_type', 'income')->sum('total'),
                'expense' => (float) $monthlyRows->where('m', $m)->where('transaction_type', 'expense')->sum('total'),
            ];
        }

        // Budget vs actual (shared with the budget report).
        $budgetRows = BudgetVsActual::for($fy);

        return [
            'id' => $fy->id,
            'year' => $fy->year,
            'season_label' => FiscalPeriod::label($fy->year),
            'status' => $fy->status,
            'opening_balance' => (float) $fy->opening_balance,
            'income' => (float) $fy->total_income,
            'expense' => (float) $fy->total_expense,
            'net' => (float) $fy->total_income - (float) $fy->total_expense,
            'closing_balance' => $fy->closing_balance !== null ? (float) $fy->closing_balance : null,
            'closed_at' => $fy->closed_at?->toDateString(),
            'closed_by' => $fy->closedBy?->name,
            'income_by_category' => $income,
            'expense_by_category' => $expense,
            'monthly' => $monthly,
            'budget' => $budgetRows,
        ];
    }
}
