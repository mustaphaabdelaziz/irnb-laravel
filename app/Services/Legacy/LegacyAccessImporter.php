<?php

namespace App\Services\Legacy;

use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Models\PaymentOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
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
 * orders are paid, the imported purchase order is received; both are
 * read-only and create no transaction, so finance totals do not move.
 * Running it again creates nothing (every row is matched first).
 */
final class LegacyAccessImporter
{
    public const FORMAT = 'access-legacy-v1';

    private const METHOD_BY_SOURCE = ['transfer' => 'bank', 'cash' => 'cash', 'payment' => null];

    /** The status ("printed") the old mail register typed in its address column. */
    private const PRINTED = 'طبعت';

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
                // Optional section (P2): an older export without it still imports.
                'purchase_orders' => $this->purchaseOrders(is_array($data['purchase_orders'] ?? null) ? $data['purchase_orders'] : []),
                // Optional section (P3): the old outgoing letters.
                'mail' => $this->mail(is_array($data['mail'] ?? null) ? $data['mail'] : []),
                // Optional section (P4): the old mission orders.
                'missions' => $this->missions(is_array($data['missions'] ?? null) ? $data['missions'] : []),
            ];
            ActivityRecorder::record($by, ActivityAction::LEGACY_DATA_IMPORTED, null, [
                'suppliers' => $result['suppliers']['created'],
                'payment_orders' => $result['payment_orders']['created'],
                'purchase_orders' => $result['purchase_orders']['created'],
                'mail' => $result['mail']['created'],
                'missions' => $result['missions']['created'],
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

    /**
     * Old `bon de commande` rows → legacy purchase orders: received, read-only,
     * no number. A supplier is linked only when its name matches a known one;
     * otherwise the old name and address stay readable in the observation, with
     * the old "N° Ordre" (never linked to a payment order).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function purchaseOrders(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $reference = Str::limit(trim(preg_replace('/\s+/', ' ', self::plain($row['reference'] ?? null))), 50, '');
            $date = self::date($row['date'] ?? null);
            $lines = array_values(array_filter(array_map(
                fn ($line) => self::purchaseLine($line),
                is_array($row['lines'] ?? null) ? $row['lines'] : [],
            )));
            if ($reference === '' || $date === null || $lines === []) {
                $skipped++;

                continue;
            }
            if (PurchaseOrder::where('is_legacy', true)->where('legacy_source', 'purchase')
                ->where('legacy_reference', $reference)->whereDate('order_date', $date)->exists()) {
                $skipped++;

                continue;
            }

            $supplierName = self::text($row['supplier'] ?? null, 200);
            $supplierId = $supplierName === null ? null
                : Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($supplierName)])->value('id');
            $orderNumber = self::text($row['order_number'] ?? null, 50);
            $address = self::text($row['address'] ?? null, 255);
            $observation = implode("\n", array_filter([
                self::text($row['observation'] ?? null, 2000),
                $orderNumber !== null ? 'N° Ordre : '.$orderNumber : null,
                $supplierName !== null && $supplierId === null ? 'Fournisseur : '.$supplierName : null,
                $address !== null && $supplierId === null ? 'Adresse : '.$address : null,
            ]));

            $order = PurchaseOrder::create([
                'year' => (int) substr($date, 0, 4),
                'number' => null,
                'legacy_reference' => $reference,
                'is_legacy' => true,
                'legacy_source' => 'purchase',
                'status' => PurchaseOrder::RECEIVED,
                'order_date' => $date,
                'supplier_id' => $supplierId,
                'requested_by' => self::text($row['requested_by'] ?? null, 200),
                'delivery_mode' => self::text($row['delivery_mode'] ?? null, 200),
                'payment_mode' => self::paymentMode(self::plain($row['payment_mode'] ?? null)),
                'observation' => $observation === '' ? null : $observation,
                'received_at' => $date,
            ]);
            foreach ($lines as $index => $line) {
                PurchaseOrderLine::create([...$line, 'purchase_order_id' => $order->id, 'sort_order' => $index + 1]);
            }
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Old `Corresponds` rows → outgoing legacy mail entries: no number, the
     * old reference text kept, an empty date left empty (the year then comes
     * from a "YYYY/NN" reference), the recipient as correspondent. The old
     * register typed the status «طبعت» (printed) in its address column: it
     * moves to the notes. A row is matched on (source, reference, date,
     * correspondent), so importing again creates nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function mail(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $reference = self::text(preg_replace('/\s+/u', ' ', self::plain($row['reference'] ?? null)), 50);
            $date = self::date($row['date'] ?? null);
            $correspondent = self::text($row['correspondent'] ?? null, 200);
            $address = self::text($row['address'] ?? null, 255);
            if ($reference === null && $correspondent === null && $date === null) {
                $skipped++;

                continue;
            }
            $notes = null;
            if ($address === self::PRINTED) {
                [$notes, $address] = [$address, null];
            }

            $exists = MailEntry::where('is_legacy', true)->where('legacy_source', 'corresponds')
                ->when($reference !== null, fn ($q) => $q->where('legacy_reference', $reference), fn ($q) => $q->whereNull('legacy_reference'))
                ->when($date !== null, fn ($q) => $q->whereDate('entry_date', $date), fn ($q) => $q->whereNull('entry_date'))
                ->when($correspondent !== null, fn ($q) => $q->where('correspondent', $correspondent), fn ($q) => $q->whereNull('correspondent'))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            MailEntry::create([
                'direction' => MailEntry::OUT,
                'year' => $date !== null ? (int) substr($date, 0, 4) : self::yearOf($reference),
                'number' => null,
                'status' => MailEntry::ACTIVE,
                'entry_date' => $date,
                'correspondent' => $correspondent,
                'address' => $address,
                'notes' => $notes,
                'is_legacy' => true,
                'legacy_reference' => $reference,
                'legacy_source' => 'corresponds',
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Old `Ordre de Mission` rows → legacy mission orders: read-only, the old
     * reference text kept, one typed traveler (Nom + Prénom, Fonction). No
     * mail entry: the old letters are already in the imported mail register.
     * Access kept every date as text: read day first (or Y-m-d); an
     * unreadable date stays empty. A row is matched on (source, reference,
     * order date), so importing again creates nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function missions(array $rows): array
    {
        $created = $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }
            $reference = self::text(preg_replace('/\s+/u', ' ', self::plain($row['reference'] ?? null)), 50);
            $date = self::textDay($row['date'] ?? null);
            $name = self::text(preg_replace('/\s+/u', ' ', self::plain($row['lastname'] ?? null).' '.self::plain($row['firstname'] ?? null)), 200);
            $destination = self::text($row['destination'] ?? null, 200);
            if ($reference === null && $name === null && $destination === null) {
                $skipped++;

                continue;
            }

            $exists = MissionOrder::where('is_legacy', true)->where('legacy_source', MissionOrder::LEGACY_SOURCE)
                ->when($reference !== null, fn ($q) => $q->where('legacy_reference', $reference), fn ($q) => $q->whereNull('legacy_reference'))
                ->when($date !== null, fn ($q) => $q->whereDate('order_date', $date), fn ($q) => $q->whereNull('order_date'))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            $order = MissionOrder::create([
                'order_date' => $date,
                'destination' => $destination,
                'reason' => self::text($row['reason'] ?? null, 1000),
                'departure_date' => self::textDay($row['departure_date'] ?? null),
                'return_date' => self::textDay($row['return_date'] ?? null),
                'transport' => self::text($row['transport'] ?? null, 100),
                'status' => MissionOrder::ACTIVE,
                'is_legacy' => true,
                'legacy_reference' => $reference,
                'legacy_source' => MissionOrder::LEGACY_SOURCE,
            ]);
            MissionOrderTraveler::create([
                'mission_order_id' => $order->id,
                'traveler_type' => null,
                'traveler_id' => null,
                'name' => $name ?? '—',
                'function' => self::text($row['function'] ?? null, 200),
                'sort_order' => 1,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * A day typed as text in Access: "15/03/2018", "15-03-2018", "15.03.18"
     * (day first, as the club wrote them) or "2018-03-15" (with or without a
     * time). An impossible or unreadable date → null. Carbon::parse() is not
     * used: it reads "05/07/2018" month first.
     */
    private static function textDay(mixed $value): ?string
    {
        $text = trim(self::plain($value));
        if (preg_match('#^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})(?!\d)#', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4}|\d{2})(?!\d)#', $text, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year += $year < 100 ? 2000 : 0;
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** "2019/07" → 2019; anything else → null. */
    private static function yearOf(?string $reference): ?int
    {
        return $reference !== null && preg_match('/^(\d{4})\s*\//', $reference, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * One old order line: a designation (or its article reference), a quantity
     * of at least 1 and a price when one was typed.
     *
     * @return array{designation: string, quantity: int, unit_price: ?float, amount: ?float}|null
     */
    private static function purchaseLine(mixed $line): ?array
    {
        if (! is_array($line)) {
            return null;
        }
        $designation = self::text($line['designation'] ?? null, 255) ?? self::text($line['reference'] ?? null, 255);
        if ($designation === null) {
            return null;
        }
        $quantity = is_numeric($line['quantity'] ?? null) ? max(1, (int) round((float) $line['quantity'])) : 1;
        $price = is_numeric($line['unit_price'] ?? null) && (float) $line['unit_price'] > 0 ? round((float) $line['unit_price'], 2) : null;

        return [
            'designation' => $designation,
            'quantity' => $quantity,
            'unit_price' => $price,
            'amount' => PurchaseOrderLine::amountFor($quantity, $price),
        ];
    }

    /** The old free-text payment mode as one of PaymentOrder::METHODS, or null when unclear. */
    private static function paymentMode(string $text): ?string
    {
        $text = mb_strtolower(trim($text));

        return match (true) {
            $text === '' => null,
            str_contains($text, 'vir') || str_contains($text, 'تحويل') => 'bank',
            str_contains($text, 'ccp') => 'ccp',
            str_contains($text, 'chè') || str_contains($text, 'cheq') || str_contains($text, 'chq') || str_contains($text, 'شيك') => 'cheque',
            str_contains($text, 'esp') || str_contains($text, 'cash') || str_contains($text, 'نقد') => 'cash',
            default => null,
        };
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
