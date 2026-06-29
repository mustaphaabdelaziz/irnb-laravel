<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $debtExpression = "CASE
            WHEN transactions.category = 'donation' THEN 0
            WHEN player_subscriptions.amount_owed > player_subscriptions.amount_paid THEN player_subscriptions.amount_owed - player_subscriptions.amount_paid
            ELSE 0
        END";

        $playerDebtSubquery = DB::table('player_subscriptions')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->leftJoin('transactions', 'transactions.id', '=', 'player_subscriptions.transaction_id')
            ->where('players.archived', false)
            ->selectRaw('player_subscriptions.player_id, SUM('.$debtExpression.') as outstanding')
            ->groupBy('player_subscriptions.player_id')
            ->havingRaw('SUM('.$debtExpression.') > 0');

        $totalPlayers = Player::query()->where('archived', false)->count();
        $unpaidPlayers = (int) DB::query()
            ->fromSub($playerDebtSubquery, 'player_debts')
            ->count();

        $paidPlayers = max(0, $totalPlayers - $unpaidPlayers);

        $outstandingDebt = (float) (DB::table('player_subscriptions')
            ->join('players', 'players.id', '=', 'player_subscriptions.player_id')
            ->leftJoin('transactions', 'transactions.id', '=', 'player_subscriptions.transaction_id')
            ->where('players.archived', false)
            ->selectRaw('COALESCE(SUM('.$debtExpression.'), 0) as total')
            ->value('total') ?? 0);

        $monthlyIncome = (float) Transaction::query()
            ->where('transaction_type', 'income')
            ->where('archived', false)
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $monthlyExpense = (float) Transaction::query()
            ->where('transaction_type', 'expense')
            ->where('archived', false)
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // All-time financial totals (Express dashboard parity).
        $year = Carbon::now()->year;
        $totalIncome = (float) Transaction::query()->where('transaction_type', 'income')->where('archived', false)->sum('amount');
        $totalExpense = (float) Transaction::query()->where('transaction_type', 'expense')->where('archived', false)->sum('amount');
        $totalDonations = (float) Transaction::query()->where('category', 'donation')->where('archived', false)->sum('amount');
        $netBalance = $totalIncome - $totalExpense;
        $financeStatus = $netBalance > 0 ? 'profit' : ($netBalance < 0 ? 'loss' : 'balanced');

        // Players-by-category distribution (doughnut chart).
        $categoryDistribution = DB::table('players')
            ->leftJoin('categories', 'categories.id', '=', 'players.category_id')
            ->where('players.archived', false)
            ->groupBy('categories.name')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as name, COUNT(*) as total")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['name' => $row->name, 'count' => (int) $row->total])
            ->values();

        // Monthly income for the current year (line chart) — bucketed in PHP for DB portability.
        $monthlyRevenue = array_fill(0, 12, 0.0);
        Transaction::query()
            ->where('transaction_type', 'income')
            ->where('archived', false)
            ->whereYear('transaction_date', $year)
            ->get(['transaction_date', 'amount'])
            ->each(function (Transaction $tx) use (&$monthlyRevenue): void {
                $monthlyRevenue[(int) $tx->transaction_date->format('n') - 1] += (float) $tx->amount;
            });

        // Paid vs owed per subscription type (bar chart).
        $subscriptionSummary = DB::table('player_subscriptions')
            ->join('subscriptions', 'subscriptions.id', '=', 'player_subscriptions.subscription_id')
            ->groupBy('subscriptions.id', 'subscriptions.name', 'subscriptions.year')
            ->selectRaw('subscriptions.name, subscriptions.year, SUM(player_subscriptions.amount_paid) as paid, SUM(player_subscriptions.amount_owed) as owed')
            ->orderByDesc('subscriptions.year')
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'year' => (int) $row->year,
                'paid' => (float) $row->paid,
                'owed' => (float) $row->owed,
            ])
            ->values();

        $recentTransactions = Transaction::query()
            ->where('archived', false)
            ->latest('transaction_date')
            ->limit(8)
            ->get([
                'id',
                'transaction_date',
                'amount',
                'transaction_type',
                'category',
                'status',
                'description',
            ])
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'date' => optional($transaction->transaction_date)?->format('Y-m-d H:i'),
                'amount' => (float) $transaction->amount,
                'type' => $transaction->transaction_type,
                'category' => $transaction->category,
                'status' => $transaction->status,
                'description' => $transaction->description,
            ])
            ->values();

        $topDebtors = DB::table('players')
            ->leftJoin('player_subscriptions', 'player_subscriptions.player_id', '=', 'players.id')
            ->leftJoin('transactions', 'transactions.id', '=', 'player_subscriptions.transaction_id')
            ->where('players.archived', false)
            ->groupBy('players.id', 'players.firstname', 'players.lastname', 'players.membership_id')
            ->selectRaw('players.id, players.firstname, players.lastname, players.membership_id, SUM('.$debtExpression.') as outstanding')
            ->havingRaw('SUM('.$debtExpression.') > 0')
            ->orderByDesc('outstanding')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => trim(($row->firstname ?? '').' '.($row->lastname ?? '')),
                'membership_id' => $row->membership_id,
                'outstanding' => (float) $row->outstanding,
            ])
            ->values();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalPlayers' => $totalPlayers,
                'paidPlayers' => $paidPlayers,
                'unpaidPlayers' => $unpaidPlayers,
                'outstandingDebt' => $outstandingDebt,
                'monthlyIncome' => $monthlyIncome,
                'monthlyExpense' => $monthlyExpense,
                'balance' => $monthlyIncome - $monthlyExpense,
                'totalIncome' => $totalIncome,
                'totalExpense' => $totalExpense,
                'totalDonations' => $totalDonations,
                'netBalance' => $netBalance,
                'financeStatus' => $financeStatus,
                'totalTransactions' => Transaction::query()->count(),
                'activeUsers' => User::query()->where('is_active', true)->count(),
            ],
            'charts' => [
                'categoryDistribution' => $categoryDistribution,
                'monthlyRevenue' => $monthlyRevenue,
                'subscriptionSummary' => $subscriptionSummary,
            ],
            'recentTransactions' => $recentTransactions,
            'topDebtors' => $topDebtors,
        ]);
    }
}
