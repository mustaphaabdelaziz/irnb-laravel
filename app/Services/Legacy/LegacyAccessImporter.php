<?php

namespace App\Services\Legacy;

use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the export of the club's old MS Access app (format access-legacy-v1,
 * made by scripts/legacy/export-access.ps1). History only: imported payment
 * orders are paid, read-only and create no transaction, so finance totals do
 * not move. Running it again creates nothing (every row is matched first).
 */
final class LegacyAccessImporter
{
    public const FORMAT = 'access-legacy-v1';

    private const METHOD_BY_SOURCE = ['transfer' => 'bank', 'cash' => 'cash', 'payment' => null];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, int>>
     */
    public function import(array $data, User $by): array
    {
        return DB::transaction(function () use ($data, $by) {
            $result = [
                'suppliers' => $this->suppliers(is_array($data['suppliers'] ?? null) ? $data['suppliers'] : []),
                'payment_orders' => $this->paymentOrders(is_array($data['payment_orders'] ?? null) ? $data['payment_orders'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
            ]);

            return $result;
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function suppliers(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $name = trim(is_scalar($row['name'] ?? null) ? (string) $row['name'] : '');
            if ($name === '') {
                $skipped++;

                continue;
            }
            $existing = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($existing) {
                // Fill a missing bank detail from a later row, never overwrite one.
                $existing->update(array_filter([
                    'bank_name' => $existing->bank_name ?: self::text($row['bank'] ?? null, 150),
                    'rib' => $existing->rib ?: self::rib($row['rib'] ?? null),
                ]));
                $skipped++;

                continue;
            }
            Supplier::create([
                'name' => Str::limit($name, 200, ''),
                'bank_name' => self::text($row['bank'] ?? null, 150),
                'rib' => self::rib($row['rib'] ?? null),
                'notes' => 'Ancien logiciel (Access)',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @param list<array<string, mixed>> $rows */
    private function paymentOrders(array $rows): array
    {
        $created = $skipped = $merged = 0;

        // The Arabic payment order first: its cash-order twin merges into it.
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => ((is_array($a) ? ($a['source'] ?? '') : '') === 'payment' ? 0 : 1) <=> ((is_array($b) ? ($b['source'] ?? '') : '') === 'payment' ? 0 : 1));

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $source = is_string($row['source'] ?? null) ? $row['source'] : '';
            if (! array_key_exists($source, self::METHOD_BY_SOURCE)) {
                $skipped++;

                continue;
            }
            $date = self::date($row['date'] ?? null);
            $amount = is_numeric($row['amount'] ?? null) ? round((float) $row['amount'], 2) : 0.0;
            $reference = Str::limit(self::reference(self::plain($row['reference'] ?? null), $date, self::plain($row['payment_reference'] ?? null)), 50, '');
            if ($date === null || $amount <= 0) {
                $skipped++;

                continue;
            }

            if (PaymentOrder::where('is_legacy', true)->where('legacy_source', $source)
                ->where('legacy_reference', $reference)->whereDate('order_date', $date)->where('amount', $amount)->exists()) {
                $skipped++;

                continue;
            }

            // A cash order whose Arabic payment order was imported: same expense.
            if ($source === 'cash') {
                $twin = PaymentOrder::where('is_legacy', true)->where('legacy_source', 'payment')
                    ->whereDate('order_date', $date)->where('amount', $amount)->first();
                if ($twin) {
                    $twin->fill([
                        'payment_method' => $twin->payment_method ?? 'cash',
                        'beneficiary_name' => $twin->beneficiary_name ?: self::text($row['beneficiary'] ?? null, 200),
                        'payment_reference' => $twin->payment_reference ?: self::text($row['payment_reference'] ?? null, 100),
                    ]);
                    if ($twin->isDirty()) {
                        $twin->save();
                        $merged++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }
            }

            $beneficiary = self::text($row['beneficiary'] ?? null, 200);
            // A beneficiary that is a known supplier is linked, so the order shows under that supplier.
            $supplierId = $beneficiary === null ? null
                : Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($beneficiary)])->value('id');

            PaymentOrder::create([
                'year' => (int) substr($date, 0, 4),
                'number' => null,
                'legacy_reference' => $reference,
                'is_legacy' => true,
                'legacy_source' => $source,
                'status' => PaymentOrder::PAID,
                'order_date' => $date,
                'subject' => Str::limit(trim(self::plain($row['subject'] ?? null)) ?: '—', 150, ''),
                'amount' => $amount,
                'payment_method' => self::METHOD_BY_SOURCE[$source],
                'beneficiary_type' => $supplierId ? 'supplier' : null,
                'beneficiary_id' => $supplierId,
                'beneficiary_name' => $beneficiary,
                'beneficiary_bank' => self::text($row['bank'] ?? null, 150),
                'beneficiary_rib' => self::rib($row['rib'] ?? null),
                'payment_reference' => self::text($row['payment_reference'] ?? null, 100),
                'payment_date' => $date,
                'observation' => self::text($row['nature'] ?? null, 5000),
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'merged' => $merged];
    }

    /** "01/2021" stays; a bare "01" (Arabic orders) gets its year: from the cash reference or the date. */
    private static function reference(string $reference, ?string $date, string $paymentReference): string
    {
        $reference = trim(preg_replace('/\s+/', ' ', $reference));
        if (str_contains($reference, '/')) {
            return $reference;
        }
        $year = $date ? substr($date, 0, 4) : (str_contains($paymentReference, '/') ? explode('/', $paymentReference)[1] : '');

        return $year !== '' ? sprintf('%s/%s', str_pad($reference, 2, '0', STR_PAD_LEFT), $year) : $reference;
    }

    private static function plain(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $value, int $limit = 255): ?string
    {
        $value = trim(self::plain($value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private static function rib(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', self::plain($value));

        return $digits === '' ? null : substr($digits, 0, 30);
    }
}
