<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
    ];

    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transactions');

        foreach (self::COLUMNS as $index => [$key, $header, $example]) {
            $column = chr(65 + $index);
            $sheet->setCellValue($column.'1', $header);
            $sheet->setCellValueExplicit($column.'2', $example, DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth(24);
        }
        $style = $sheet->getStyle('A1:'.chr(65 + count(self::COLUMNS) - 1).'1');
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E40AF');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'transactions-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240']]);

        try {
            $rows = IOFactory::load($request->file('file')->getRealPath())
                ->getActiveSheet()->toArray(null, true, true, false);
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
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
