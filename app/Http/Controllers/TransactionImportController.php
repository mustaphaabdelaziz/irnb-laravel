<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TransactionImportController extends Controller
{
    /** @var list<array{0:string,1:string,2:string}> */
    private const COLUMNS = [
        ['transaction_date', 'Date (YYYY-MM-DD)', '2026-01-15'],
        ['transaction_type', 'Type (income/expense)', 'income'],
        ['category', 'Category', 'subscription'],
        ['amount', 'Amount', '1000'],
        ['status', 'Status (Paid/Partial/Unpaid/Exempt)', 'Paid'],
        ['payment_method', 'Payment Method', 'cash'],
        ['description', 'Description', ''],
        // Last so files made from the old 7-column template still import.
        ['title', 'Title', ''],
    ];

    public function template(): StreamedResponse
    {
        $headers = array_map(fn ($column) => $column[1], self::COLUMNS);
        $example = array_map(fn ($column) => $column[2], self::COLUMNS);

        return Csv::download('transactions-import-template.csv', $headers, [$example]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        try {
            $rows = Csv::readRows($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        array_shift($rows); // header
        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $data = [];
            foreach (self::COLUMNS as $index => [$key]) {
                $v = $row[$index] ?? null;
                $data[$key] = is_string($v) ? trim($v) : ($v === null ? null : trim((string) $v));
            }

            if (! is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
                continue; // blank / invalid line
            }

            try {
                Transaction::create([
                    'transaction_date' => $this->parseDate($data['transaction_date']) ?? now(),
                    'transaction_type' => mb_strtolower((string) $data['transaction_type']) === 'expense' ? 'expense' : 'income',
                    'category' => $data['category'] ?: 'other',
                    'amount' => (float) $data['amount'],
                    'status' => in_array($data['status'], ['Paid', 'Partial', 'Unpaid', 'Exempt'], true) ? $data['status'] : 'Paid',
                    'payment_method' => $data['payment_method'] ?: 'cash',
                    'description' => $data['description'] ?: null,
                    'title' => $data['title'] ? mb_substr($data['title'], 0, 150) : null,
                    'recorded_by_user_id' => $request->user()?->id,
                ]);
                $imported++;
            } catch (Throwable $e) {
                $errors[] = __('Row :line: :message', ['line' => $i + 2, 'message' => $e->getMessage()]);
            }
        }

        $message = __(':count transactions imported successfully.', ['count' => $imported]);

        return $errors === []
            ? back()->with('success', $message)
            : back()->with('success', $message)->with('error', implode("\n", array_slice($errors, 0, 10)));
    }

    private function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
