<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Support\Export;
use App\Support\Import\ImportColumns;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;
use App\Support\UiLang;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class TransactionImportController extends Controller
{
    /**
     * Same order as the transactions export (its trailing "Recorded by" column
     * is ignored here). `legacy` are the headers of the old CSV template, so
     * files made from it still import by header.
     *
     * @var list<array{key:string, label:string, hint?:string, legacy?:list<string>}>
     */
    public const COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'col.date', 'hint' => 'col.hint.date', 'legacy' => ['Date (YYYY-MM-DD)']],
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

    public function template(Request $request): SymfonyResponse
    {
        // One example row in COLUMNS order; enum cells in the user's language.
        $example = ['2026-01-15', '', UiLang::get('income'), 'subscription', '1000', UiLang::get('paid'), UiLang::get('cash'), '', ''];

        return Export::download(Export::format($request), 'transactions-import-template',
            (new ImportColumns(self::COLUMNS))->headers(), [$example]);
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

            $rawAmount = $cell('amount');
            $amount = $this->parseAmount($rawAmount);
            if ($amount === null && $rawAmount !== '') {
                $errors[] = __('Row :line: amount ":value" is not a number, the row was skipped.', ['line' => $line, 'value' => $rawAmount]);

                continue;
            }
            if ($amount === null || $amount <= 0) {
                continue; // blank line, or a zero amount
            }

            $rawDate = $cell('transaction_date');
            $date = $this->parseDate($rawDate);

            $type = ImportColumns::value($cell('transaction_type'), self::TYPES) ?? 'income';
            $category = $this->matchCategory($categories, $type, $cell('category'));
            $payment = $cell('payment_method');
            $title = $cell('title');
            $registerName = $cell('finance_account');
            $register = $registerName === '' ? null : $registers->first(
                fn (FinanceAccount $a) => NameNormalizer::key($a->name) === NameNormalizer::key($registerName)
            );

            try {
                Transaction::create([
                    'transaction_date' => $date ?? now(),
                    'transaction_type' => $type,
                    'category' => $category ? Str::slug($category->name, '_') : ($cell('category') ?: 'other'),
                    'finance_category_id' => $category?->id,
                    'amount' => $amount,
                    'status' => ImportColumns::value($cell('status'), self::STATUSES) ?? 'Paid',
                    // The column is free text: an unknown method keeps its text, as before.
                    'payment_method' => ImportColumns::value($payment, self::PAYMENT_METHODS) ?? ($payment !== '' ? mb_substr($payment, 0, 255) : 'cash'),
                    'finance_account_id' => $register?->id, // null → the observer applies the default register
                    'description' => $cell('description') ?: null,
                    'title' => $title !== '' ? mb_substr($title, 0, 150) : null,
                    'recorded_by_user_id' => $request->user()?->id,
                ]);
                $imported++;

                if ($date === null && $rawDate !== '') {
                    $errors[] = __('Row :line: date ":value" is not valid, today\'s date was used.', ['line' => $line, 'value' => $rawDate]);
                }
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

    /**
     * "1500", "750,5", "1,500", "12 000", "1.000,50", "1,500.75" → float.
     * A dot or comma is the decimal separator only when 1–2 digits follow it
     * at the end; groups of exactly 3 digits after a comma, dot or space are
     * thousands. Anything else (ambiguous or malformed) → null.
     */
    private function parseAmount(string $value): ?float
    {
        $value = (string) preg_replace('/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', $value);
        $decimal = null;
        $fraction = '';

        if (preg_match('/^(.+?)([.,])(\d{1,2})$/u', $value, $m)) {
            [, $value, $decimal, $fraction] = $m;
        } elseif (preg_match('/^(\d+)\.(\d{4,})$/', $value, $m)) {
            // A long plain decimal, as a spreadsheet number cell comes back.
            [, $value, $fraction] = $m;
            $decimal = '.';
        }

        if (preg_match('/^\d+$/', $value)) {
            $digits = $value;
        } elseif (preg_match('/^[1-9]\d{0,2}([,. \x{00A0}\x{202F}])\d{3}(?:\1\d{3})*$/u', $value, $m) && $m[1] !== $decimal) {
            $digits = (string) preg_replace('/\D/', '', $value);
        } else {
            return null;
        }

        return (float) ($fraction === '' ? $digits : $digits.'.'.$fraction);
    }

    /** Y-m-d (or anything Carbon reads) and d/m/Y; impossible dates such as 31/02 → null, never rolled over. */
    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})(?=\D|$)#', $value, $m) && ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
