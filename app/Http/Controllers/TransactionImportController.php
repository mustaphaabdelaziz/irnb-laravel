<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Support\Csv;
use App\Support\Import\ImportColumns;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TransactionImportController extends Controller
{
    /**
     * Same order as the transactions export (its trailing "Recorded by" column
     * is ignored here). `legacy` are the headers of the old CSV template, so
     * files made from it still import by header.
     *
     * @var list<array{key:string, label:string, legacy?:list<string>}>
     */
    public const COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'col.date', 'legacy' => ['Date (YYYY-MM-DD)']],
        ['key' => 'title', 'label' => 'col.title', 'legacy' => ['Title']],
        ['key' => 'transaction_type', 'label' => 'col.type', 'legacy' => ['Type (income/expense)']],
        ['key' => 'category', 'label' => 'col.category', 'legacy' => ['Category']],
        ['key' => 'amount', 'label' => 'col.amount', 'legacy' => ['Amount']],
        ['key' => 'status', 'label' => 'col.status', 'legacy' => ['Status (Paid/Partial/Unpaid/Exempt)']],
        ['key' => 'payment_method', 'label' => 'col.payment_method', 'legacy' => ['Payment Method']],
        ['key' => 'finance_account', 'label' => 'col.cash_register'],
        ['key' => 'description', 'label' => 'col.description', 'legacy' => ['Description']],
    ];

    /** Stored type code => UI label key. */
    private const TYPES = ['income' => 'income', 'expense' => 'expense'];

    /** Stored status code => UI label key. */
    private const STATUSES = ['Paid' => 'paid', 'Partial' => 'partial', 'Unpaid' => 'unpaid', 'Exempt' => 'exempt'];

    /** The codes the transaction form offers => UI label key (see resources/js/lib/statusLabels.js). */
    private const PAYMENT_METHODS = ['cash' => 'cash', 'bank' => 'bank_transfer', 'ccp' => 'ccp', 'baridimob' => 'baridimob', 'other' => 'other'];

    /** Today's CSV template, unchanged until the template is rewritten. */
    private const LEGACY_TEMPLATE = [
        ['Date (YYYY-MM-DD)', '2026-01-15'],
        ['Type (income/expense)', 'income'],
        ['Category', 'subscription'],
        ['Amount', '1000'],
        ['Status (Paid/Partial/Unpaid/Exempt)', 'Paid'],
        ['Payment Method', 'cash'],
        ['Description', ''],
        ['Title', ''],
    ];

    public function template(): StreamedResponse
    {
        return Csv::download(
            'transactions-import-template.csv',
            array_column(self::LEGACY_TEMPLATE, 0),
            [array_column(self::LEGACY_TEMPLATE, 1)],
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']]);

        try {
            $rows = Spreadsheet::readRows($request->file('file')->getRealPath());
        } catch (Throwable) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        [$headerRow, $map] = (new ImportColumns(self::COLUMNS))->locate($rows);
        $categories = FinanceCategory::query()->get(['id', 'type', 'name', 'name_ar', 'name_fr', 'name_en']);
        $registers = FinanceAccount::query()->orderByDesc('is_active')->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);

        $imported = 0;
        $errors = [];

        foreach (array_values(array_slice($rows, $headerRow + 1)) as $offset => $row) {
            $line = $headerRow + $offset + 2;
            $cell = function (string $key) use ($row, $map): string {
                $v = $row[$map[$key] ?? -1] ?? null;

                return $v === null ? '' : trim((string) $v);
            };

            $amount = $this->parseAmount($cell('amount'));
            if ($amount === null || $amount <= 0) {
                continue; // blank / invalid line
            }

            $type = ImportColumns::value($cell('transaction_type'), self::TYPES) ?? 'income';
            $category = $this->matchCategory($categories, $type, $cell('category'));
            $payment = $cell('payment_method');
            $registerName = $cell('finance_account');
            $register = $registerName === '' ? null : $registers->first(
                fn (FinanceAccount $a) => NameNormalizer::key($a->name) === NameNormalizer::key($registerName)
            );

            try {
                Transaction::create([
                    'transaction_date' => $this->parseDate($cell('transaction_date')) ?? now(),
                    'transaction_type' => $type,
                    'category' => $category ? Str::slug($category->name, '_') : ($cell('category') ?: 'other'),
                    'finance_category_id' => $category?->id,
                    'amount' => $amount,
                    'status' => ImportColumns::value($cell('status'), self::STATUSES) ?? 'Paid',
                    // The column is free text: an unknown method keeps its text, as before.
                    'payment_method' => ImportColumns::value($payment, self::PAYMENT_METHODS) ?? ($payment !== '' ? mb_substr($payment, 0, 255) : 'cash'),
                    'finance_account_id' => $register?->id, // null → the observer applies the default register
                    'description' => $cell('description') ?: null,
                    'title' => $cell('title') !== '' ? mb_substr($cell('title'), 0, 150) : null,
                    'recorded_by_user_id' => $request->user()?->id,
                ]);
                $imported++;

                if ($registerName !== '' && ! $register) {
                    $errors[] = __('Row :line: cash register ":name" not found, the default register was used.', ['line' => $line, 'name' => $registerName]);
                }
            } catch (Throwable $e) {
                $errors[] = __('Row :line: :message', ['line' => $line, 'message' => $e->getMessage()]);
            }
        }

        $message = __(':count transactions imported successfully.', ['count' => $imported]);

        return $errors === []
            ? back()->with('success', $message)
            : back()->with('success', $message)->with('error', implode("\n", array_slice($errors, 0, 10)));
    }

    /** The category of this type whose name, in any language, matches the cell. */
    private function matchCategory(Collection $categories, string $type, string $raw): ?FinanceCategory
    {
        $needle = NameNormalizer::key($raw);
        if ($needle === '') {
            return null;
        }

        return $categories->first(fn (FinanceCategory $c) => $c->type === $type && in_array(
            $needle,
            array_map(NameNormalizer::key(...), array_filter([$c->name, $c->name_ar, $c->name_fr, $c->name_en])),
            true,
        ));
    }

    /** "1000", "750.5", "1 000,50" → float; anything else → null. */
    private function parseAmount(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $value);
        if (! str_contains($value, '.') && substr_count($value, ',') === 1) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value)
                ? Carbon::createFromFormat('!d/m/Y', $value)->format('Y-m-d')
                : Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
