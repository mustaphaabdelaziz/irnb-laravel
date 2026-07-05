<?php

namespace App\Http\Controllers;

use App\Http\Requests\Subscription\StoreSubscriptionRequest;
use App\Models\Category;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Subscription::query()->with('categories');

        if ($request->filled('year')) {
            $query->where('year', $request->input('year'));
        }

        $subscriptions = $query->orderByDesc('year')->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => $request->only(['year']),
        ]);
    }

    public function show(Subscription $subscription): Response
    {
        $subscription->load('categories');

        $playerSubscriptions = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->with(['player.category', 'transaction'])
            ->get();

        // Calculate stats
        $total = $playerSubscriptions->count();
        $paidCount = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'paid' || $ps->payment_status === 'exempt')->count();
        $unpaidCount = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'unpaid')->count();
        $partialCount = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'partial')->count();
        $totalCollected = $playerSubscriptions->sum('amount_paid');
        $paidPercentage = $total > 0 ? round(($paidCount / $total) * 100, 1) : 0;

        // Players not yet assigned to this subscription
        $assignedPlayerIds = $playerSubscriptions->pluck('player_id')->toArray();
        $availablePlayers = Player::query()
            ->where('archived', false)
            ->whereNotIn('id', $assignedPlayerIds)
            ->with('category')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname', 'nickname', 'is_student', 'category_id', 'membership_id']);

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $subscription,
            'playerSubscriptions' => $playerSubscriptions,
            'categories' => Category::orderBy('name')->get(),
            'availablePlayers' => $availablePlayers,
            'stats' => [
                'total' => $total,
                'paid_count' => $paidCount,
                'unpaid_count' => $unpaidCount,
                'partial_count' => $partialCount,
                'total_collected' => $totalCollected,
                'paid_percentage' => $paidPercentage,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Subscriptions/Create', [
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(StoreSubscriptionRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $categoryIds = $validated['category_ids'] ?? [];
        unset($validated['category_ids']);

        $subscription = Subscription::create($validated);

        if (! empty($categoryIds)) {
            $subscription->categories()->attach($categoryIds);
        }

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription created successfully.');
    }

    public function edit(Subscription $subscription): Response
    {
        $subscription->load('categories');

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function update(StoreSubscriptionRequest $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validated();
        $categoryIds = $validated['category_ids'] ?? [];
        unset($validated['category_ids']);

        $subscription->update($validated);
        $subscription->categories()->sync($categoryIds);

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', 'Subscription updated successfully.');
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        $subscription->delete();

        return redirect()->route('subscriptions.index')
            ->with('success', 'Subscription deleted successfully.');
    }

    public function assign(Request $request, Subscription $subscription): RedirectResponse
    {
        $request->validate([
            'player_ids' => ['nullable', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assign_all' => ['nullable', 'boolean'],
        ]);

        $query = Player::query()->where('archived', false);

        if ($request->boolean('assign_all')) {
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->input('category_id'));
            }
            $playerIds = $query->pluck('id');
        } else {
            $playerIds = collect($request->input('player_ids', []));
        }

        $existingPlayerIds = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('player_id', $playerIds)
            ->pluck('player_id');

        $newPlayerIds = $playerIds->diff($existingPlayerIds);

        DB::transaction(function () use ($newPlayerIds, $subscription) {
            foreach ($newPlayerIds as $playerId) {
                $player = Player::find($playerId);
                if (! $player) {
                    continue;
                }

                $amountOwed = $player->is_student
                    ? (float) $subscription->amount_student
                    : (float) $subscription->amount_worker;

                PlayerSubscription::create([
                    'player_id' => $player->id,
                    'subscription_id' => $subscription->id,
                    'transaction_id' => null,
                    'year' => $subscription->year,
                    'status_at_time' => $player->is_student ? 'student' : 'worker',
                    'is_mandatory' => (bool) $subscription->is_mandatory,
                    'amount_owed' => $amountOwed,
                    'amount_paid' => 0,
                ]);
                app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player);
            }
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', $newPlayerIds->count().' players assigned.');
    }

    public function assignOne(Request $request, Subscription $subscription): RedirectResponse
    {
        $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
        ]);

        $player = Player::findOrFail($request->input('player_id'));

        $alreadyAssigned = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->where('player_id', $player->id)
            ->exists();

        if ($alreadyAssigned) {
            return redirect()->route('subscriptions.show', $subscription)
                ->with('error', 'Player is already assigned to this subscription.');
        }

        $amountOwed = $player->is_student
            ? (float) $subscription->amount_student
            : (float) $subscription->amount_worker;

        DB::transaction(function () use ($player, $subscription, $amountOwed) {
            PlayerSubscription::create([
                'player_id' => $player->id,
                'subscription_id' => $subscription->id,
                'transaction_id' => null,
                'year' => $subscription->year,
                'status_at_time' => $player->is_student ? 'student' : 'worker',
                'is_mandatory' => (bool) $subscription->is_mandatory,
                'amount_owed' => $amountOwed,
                'amount_paid' => 0,
            ]);
            app(\App\Services\Finance\RecalculatePlayerDebtService::class)->forPlayer($player);
        });

        return redirect()->route('subscriptions.show', $subscription)
            ->with('success', $player->firstname.' '.$player->lastname.' added to subscription.');
    }

    public function export(Request $request, Subscription $subscription): HttpResponse
    {
        $playerSubscriptions = PlayerSubscription::query()
            ->where('subscription_id', $subscription->id)
            ->with(['player.category', 'transaction'])
            ->get();

        $filter = $request->query('filter', 'all'); // all | paid | unpaid | partial

        if ($filter === 'paid') {
            $playerSubscriptions = $playerSubscriptions->filter(fn ($ps) => in_array($ps->payment_status, ['paid', 'exempt']));
        } elseif ($filter === 'unpaid') {
            $playerSubscriptions = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'unpaid');
        } elseif ($filter === 'partial') {
            $playerSubscriptions = $playerSubscriptions->filter(fn ($ps) => $ps->payment_status === 'partial');
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Subscription Payments');

        // Header row styling
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];

        // Title row
        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', $subscription->name.' - '.$subscription->year.' ('.ucfirst($filter).')');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF1E40AF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Column headers
        $headers = ['#', 'Membership ID', 'Player Name', 'Category', 'Amount Owed', 'Amount Paid', 'Status'];
        foreach ($headers as $colIdx => $header) {
            $cell = chr(65 + $colIdx).'3';
            $sheet->setCellValue($cell, $header);
        }
        $sheet->getStyle('A3:G3')->applyFromArray($headerStyle);
        $sheet->getRowDimension(3)->setRowHeight(18);

        // Data rows
        $row = 4;
        $rowNum = 1;
        foreach ($playerSubscriptions as $ps) {
            $player = $ps->player;
            $name = $player ? ($player->lastname.' '.$player->firstname) : 'N/A';
            $category = $player?->category?->name ?? '-';

            $sheet->setCellValue('A'.$row, $rowNum);
            $sheet->setCellValue('B'.$row, $player?->membership_id ?? '-');
            $sheet->setCellValue('C'.$row, $name);
            $sheet->setCellValue('D'.$row, $category);
            $sheet->setCellValue('E'.$row, (float) $ps->amount_owed);
            $sheet->setCellValue('F'.$row, (float) $ps->amount_paid);
            $sheet->setCellValue('G'.$row, strtoupper($ps->payment_status));

            // Status cell color
            $statusColor = match ($ps->payment_status) {
                'paid' => 'FFD1FAE5',
                'partial' => 'FFFEF3C7',
                'exempt' => 'FFF1F5F9',
                default => 'FFFEE2E2',
            };
            $sheet->getStyle('G'.$row)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $statusColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            // Alternating row background
            if ($row % 2 === 0) {
                $sheet->getStyle('A'.$row.':F'.$row)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFC']],
                ]);
            }

            $rowNum++;
            $row++;
        }

        // Summary row
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->setCellValue('A'.$row, 'TOTAL');
        $sheet->setCellValue('E'.$row, $playerSubscriptions->sum('amount_owed'));
        $sheet->setCellValue('F'.$row, $playerSubscriptions->sum('amount_paid'));
        $sheet->getStyle('A'.$row.':G'.$row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE0E7FF']],
        ]);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(28);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(16);
        $sheet->getColumnDimension('G')->setWidth(14);

        // Format money columns
        $moneyFormat = '#,##0.00 "DZD"';
        $sheet->getStyle('E4:F'.$row)->getNumberFormat()->setFormatCode($moneyFormat);

        $filename = 'subscription_'.str_replace(' ', '_', $subscription->name).'_'.$subscription->year.'_'.$filter.'.xlsx';

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
