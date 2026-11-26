<?php

namespace App\Services\Secretariat;

use App\Exceptions\MissionOrderException;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\MissionOrderTraveler;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use App\Support\UiLang;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every write to a mission order, one DB transaction each, each guarded by an
 * atomic conditional claim (lock_version bumped): create, update (fields and
 * travelers, also after printing), cancel (the order and its mail entry
 * together) and register() — the first print taking the order's outgoing
 * mail-register reference. Orders have no number of their own and are never
 * deleted.
 */
final class MissionOrders
{
    /** How many travelers the mail entry's correspondent names before "+N". */
    private const NAMED = 3;

    public function __construct(private readonly MailRegister $mail) {}

    /** @param array<string, mixed> $data validated fields (MissionOrder::FIELDS) and `travelers` */
    public function create(array $data, User $by): MissionOrder
    {
        return DB::transaction(function () use ($data, $by) {
            $order = MissionOrder::create([
                ...Arr::only($data, MissionOrder::FIELDS),
                'status' => MissionOrder::ACTIVE,
                'created_by' => $by->id,
            ]);
            $count = $this->writeTravelers($order, $data['travelers'] ?? []);
            ActivityRecorder::record($by, ActivityAction::MISSION_ORDER_CREATED, $order, ['travelers' => $count]);

            return $order->fresh('travelers');
        });
    }

    /**
     * Fields and travelers, also after printing — the reference stays and
     * reprints show it. Cancelled and imported orders are refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MissionOrder $order, array $data, User $by): MissionOrder
    {
        return DB::transaction(function () use ($order, $data, $by) {
            $claimed = MissionOrder::whereKey($order->id)
                ->where('status', MissionOrder::ACTIVE)->where('is_legacy', false)
                ->update([
                    ...Arr::only($data, MissionOrder::FIELDS),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MissionOrderException('flash.mission_locked');
            }
            $count = $this->writeTravelers($order, $data['travelers'] ?? []);
            ActivityRecorder::record($by, ActivityAction::MISSION_ORDER_UPDATED, $order, [
                'reference' => $this->reference($order),
                'travelers' => $count,
            ]);

            return $order->fresh('travelers');
        });
    }

    /**
     * Active orders typed here. The order and its active mail entry are
     * cancelled in this one transaction — MailRegister::cancelSource() nests
     * as a savepoint (no number, no file) — so both are cancelled or neither.
     * The entry keeps its number; the order keeps showing that reference.
     */
    public function cancel(MissionOrder $order, User $by): MissionOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $claimed = MissionOrder::whereKey($order->id)
                ->where('status', MissionOrder::ACTIVE)->where('is_legacy', false)
                ->update([
                    'status' => MissionOrder::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
            if ($claimed === 0) {
                throw new MissionOrderException('flash.mission_cannot_cancel');
            }
            $entry = $this->mail->cancelSource(MailEntry::SOURCE_MISSION, $order->id, MailSources::MISSION_KEY, $by);
            ActivityRecorder::record($by, ActivityAction::MISSION_ORDER_CANCELLED, $order, ['reference' => $entry?->reference]);

            return $order->fresh('travelers');
        });
    }

    /**
     * The Print buttons: the order's active mail entry, or a new outgoing one
     * (today, next number) named after its travelers and destination. Reprints
     * get the same entry. Cancelled and imported orders print without one, so
     * they are refused here.
     */
    public function register(MissionOrder $order, User $by): MailEntry
    {
        if (! $order->isEditable()) {
            throw new MissionOrderException('flash.mission_cannot_register');
        }
        $order->loadMissing('travelers');
        // MailRegister owns this transaction (it retries a unique-index race): never wrapped in another one.
        $entry = $this->mail->register(MailEntry::SOURCE_MISSION, $order->id, MailSources::MISSION_KEY, self::defaults($order), $by);

        // Cancelled in another tab between the check and the new number: that number must not stay active.
        if ($entry->wasRecentlyCreated && ! MissionOrder::whereKey($order->id)->where('status', MissionOrder::ACTIVE)->exists()) {
            $this->mail->cancelSource(MailEntry::SOURCE_MISSION, $order->id, MailSources::MISSION_KEY, $by);

            throw new MissionOrderException('flash.mission_cannot_register');
        }

        return $entry;
    }

    public function reference(MissionOrder $order): ?string
    {
        return $this->references([$order])[$order->id] ?? null;
    }

    /**
     * Each order's reference: an imported order its old text; an active order
     * its active mail entry (none before the first print); a cancelled order
     * the entry cancelled with it (cancel keeps the reference). One query for
     * the whole page. Lookup only — never takes a number.
     *
     * @param  iterable<MissionOrder>  $orders
     * @return array<int, ?string> order id → reference
     */
    public function references(iterable $orders): array
    {
        $orders = collect($orders);
        $ids = $orders->reject(fn (MissionOrder $order) => $order->is_legacy)->pluck('id');
        $entries = $ids->isEmpty() ? collect() : MailEntry::query()
            ->where('source_type', MailEntry::SOURCE_MISSION)->where('source_key', MailSources::MISSION_KEY)
            ->whereIn('source_id', $ids)->orderBy('id')
            ->get(['id', 'direction', 'year', 'number', 'status', 'is_legacy', 'legacy_reference', 'source_id'])
            ->groupBy('source_id');

        return $orders->mapWithKeys(function (MissionOrder $order) use ($entries) {
            if ($order->is_legacy) {
                return [$order->id => $order->legacy_reference];
            }
            $mine = $entries->get($order->id, collect());
            $entry = $order->status === MissionOrder::ACTIVE
                ? $mine->firstWhere('status', MailEntry::ACTIVE)
                : $mine->last();

            return [$order->id => $entry?->reference];
        })->all();
    }

    /**
     * A new mail entry's correspondent (the first three travelers, then "+N")
     * and subject («أمر بمهمة — destination», in the app language).
     *
     * @return array{correspondent: string, address: null, subject: string}
     */
    public static function defaults(MissionOrder $order): array
    {
        $names = $order->travelers->pluck('name')->filter()->values();
        $named = $names->take(self::NAMED)->implode(app()->getLocale() === 'ar' ? '، ' : ', ');
        $rest = $names->count() - self::NAMED;

        return [
            'correspondent' => $rest > 0 ? $named.' +'.$rest : $named,
            'address' => null,
            'subject' => UiLang::get('mis.title').' — '.$order->destination,
        ];
    }

    /**
     * Replaces the order's travelers with $rows in their order. A person
     * (same type + id) is kept once; typed names (no type) always stay; an id
     * without a type, or a type without an id, is a typed name. At least one.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeTravelers(MissionOrder $order, array $rows): int
    {
        MissionOrderTraveler::where('mission_order_id', $order->id)->delete();
        $seen = [];
        $sort = 0;
        foreach ($rows as $row) {
            $name = is_array($row) ? self::text($row['name'] ?? null, 200) : null;
            if ($name === null) {
                continue;
            }
            $type = in_array($row['traveler_type'] ?? null, MissionOrderTraveler::TYPES, true) ? $row['traveler_type'] : null;
            $id = $type !== null && is_numeric($row['traveler_id'] ?? null) ? (int) $row['traveler_id'] : null;
            if ($id === null) {
                $type = null;
            } elseif (isset($seen[$type.'|'.$id])) {
                continue;
            } else {
                $seen[$type.'|'.$id] = true;
            }
            MissionOrderTraveler::create([
                'mission_order_id' => $order->id,
                'traveler_type' => $type,
                'traveler_id' => $id,
                'name' => $name,
                'function' => self::text($row['function'] ?? null, 200),
                'sort_order' => ++$sort,
            ]);
        }
        if ($sort === 0) {
            throw new MissionOrderException('flash.mission_no_traveler');
        }

        return $sort;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
