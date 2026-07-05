<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\FinanceService;
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
            'status' => $fy->status,
            'opening_balance' => (float) $fy->opening_balance,
            'total_income' => (float) $fy->total_income,
            'total_expense' => (float) $fy->total_expense,
            'net' => (float) $fy->total_income - (float) $fy->total_expense,
            'closing_balance' => $fy->closing_balance !== null ? (float) $fy->closing_balance : null,
            'closed_at' => $fy->closed_at?->toDateString(),
        ]);

        $selectedYear = (int) ($request->query('year') ?: ($years->first()['year'] ?? now()->year));
        $fiscalYear = FiscalYear::where('year', $selectedYear)->first();

        return Inertia::render('Finance/Index', [
            'years' => $years,
            'selectedYear' => $selectedYear,
            'detail' => $fiscalYear ? $this->yearDetail($fiscalYear) : null,
            'accounts' => FinanceAccount::orderBy('sort_order')->orderBy('id')->get(),
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
            'accounts' => FinanceAccount::withCount('transactions')
                ->orderBy('sort_order')->orderBy('id')->get(),
            'years' => FiscalYear::orderByDesc('year')->get(),
            'budgets' => $budgets,
        ]);
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

        $byCategory = DB::table('transactions as t')
            ->leftJoin('finance_categories as c', 'c.id', '=', 't.finance_category_id')
            ->where('t.archived', false)
            ->where('t.fiscal_year', $year)
            ->groupBy('t.transaction_type', 'c.id', 'c.name', 'c.color')
            ->selectRaw('t.transaction_type as type, c.id as category_id, c.name, c.color, SUM(t.amount) as total, COUNT(*) as count')
            ->get();

        $income = $byCategory->where('type', 'income')->sortByDesc('total')->values();
        $expense = $byCategory->where('type', 'expense')->sortByDesc('total')->values();

        // Monthly income/expense trend.
        $monthlyRows = DB::table('transactions')
            ->where('archived', false)->where('fiscal_year', $year)
            ->groupBy('m', 'transaction_type')
            ->selectRaw("CAST(strftime('%m', transaction_date) AS INTEGER) as m, transaction_type, SUM(amount) as total")
            ->get();
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[] = [
                'month' => $m,
                'income' => (float) $monthlyRows->where('m', $m)->where('transaction_type', 'income')->sum('total'),
                'expense' => (float) $monthlyRows->where('m', $m)->where('transaction_type', 'expense')->sum('total'),
            ];
        }

        // Budget vs actual.
        $budgets = Budget::where('fiscal_year_id', $fy->id)->pluck('planned_amount', 'finance_category_id');
        $actuals = $byCategory->keyBy('category_id');
        $budgetRows = FinanceCategory::where('is_active', true)->orderBy('type')->orderBy('sort_order')->get()
            ->map(function (FinanceCategory $c) use ($budgets, $actuals) {
                $planned = (float) ($budgets[$c->id] ?? 0);
                $actual = (float) ($actuals[$c->id]->total ?? 0);

                return [
                    'category_id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'color' => $c->color,
                    'planned' => $planned, 'actual' => $actual,
                    'variance' => $c->type === 'expense' ? $planned - $actual : $actual - $planned,
                ];
            })
            ->filter(fn ($r) => $r['planned'] > 0 || $r['actual'] > 0)
            ->values();

        return [
            'id' => $fy->id,
            'year' => $fy->year,
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
