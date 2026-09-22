<?php

namespace App\Http\Controllers;

use App\Models\Branch;
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
            'accounts' => FinanceAccount::query()
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
            'accounts' => FinanceAccount::with([
                'branch:id,name,name_ar,name_fr,name_en',
                'category:id,name,name_ar,name_fr,name_en',
            ])
                ->withCount('transactions')
                ->orderBy('sort_order')->orderBy('id')->get(),
            'branches' => Branch::orderBy('name')->get([
                'id', 'name', 'name_ar', 'name_fr', 'name_en',
            ]),
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
                    'category_id' => $c->id, 'name' => $c->localized_name, 'type' => $c->type, 'color' => $c->color,
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
