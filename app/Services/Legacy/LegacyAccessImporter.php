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
 * Imports the export of the club's old MS Access app (format irnb-access-v1,
 * made by scripts/legacy/export-access.ps1). History only: imported payment
 * orders are paid, read-only and create no transaction, so finance totals do
 * not move. Running it again creates nothing (every row is matched first).
 */
final class LegacyAccessImporter
{
    public const FORMAT = 'irnb-access-v1';

    private const METHOD_BY_SOURCE = ['transfer' => 'bank', 'cash' => 'cash', 'payment' => null];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, int>>
     */
    public function import(array $data, User $by): array
    {
        return DB::transaction(function () use ($data, $by) {
            $result = [
                'suppliers' => $this->suppliers($data['suppliers'] ?? []),
                'payment_orders' => $this->paymentOrders($data['payment_orders'] ?? []),
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
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $existing = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($existing) {
                // Fill a missing bank detail from a later row, never overwrite one.
                $existing->update(array_filter([
                    'bank_name' => $existing->bank_name ?: self::text($row['bank'] ?? null),
                    'rib' => $existing->rib ?: self::rib($row['rib'] ?? null),
                ]));
                $skipped++;

                continue;
            }
            Supplier::create([
                'name' => Str::limit($name, 200, ''),
                'bank_name' => self::text($row['bank'] ?? null),
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
        usort($rows, fn ($a, $b) => (($a['source'] ?? '') === 'payment' ? 0 : 1) <=> (($b['source'] ?? '') === 'payment' ? 0 : 1));

        foreach ($rows as $row) {
            $source = (string) ($row['source'] ?? '');
            if (! array_key_exists($source, self::METHOD_BY_SOURCE)) {
                continue;
            }
            $date = ! empty($row['date']) ? Carbon::parse($row['date'])->format('Y-m-d') : null;
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $reference = self::reference((string) ($row['reference'] ?? ''), $date, (string) ($row['payment_reference'] ?? ''));
            if ($date === null) {
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
                    $twin->update([
                        'payment_method' => $twin->payment_method ?? 'cash',
                        'beneficiary_name' => $twin->beneficiary_name ?: self::text($row['beneficiary'] ?? null),
                        'payment_reference' => $twin->payment_reference ?: self::text($row['payment_reference'] ?? null),
                    ]);
                    $merged++;

                    continue;
                }
            }

            PaymentOrder::create([
                'year' => (int) substr($date, 0, 4),
                'number' => null,
                'legacy_reference' => $reference,
                'is_legacy' => true,
                'legacy_source' => $source,
                'status' => PaymentOrder::PAID,
                'order_date' => $date,
                'subject' => Str::limit(trim((string) ($row['subject'] ?? '')) ?: '—', 150, ''),
                'amount' => $amount,
                'payment_method' => self::METHOD_BY_SOURCE[$source],
                'beneficiary_name' => self::text($row['beneficiary'] ?? null),
                'beneficiary_bank' => self::text($row['bank'] ?? null),
                'beneficiary_rib' => self::rib($row['rib'] ?? null),
                'payment_reference' => self::text($row['payment_reference'] ?? null),
                'payment_date' => $date,
                'observation' => self::text($row['nature'] ?? null),
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

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function rib(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : substr($digits, 0, 30);
    }
}
