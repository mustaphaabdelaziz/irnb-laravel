<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\BoardMeeting;
use App\Models\BoardTask;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\EquipmentRental;
use App\Models\FinanceTransfer;
use App\Models\InventorySession;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerAcademicRecord;
use App\Models\PlayerDocument;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Support\UiLang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Turns an activity log's subject into something a report can show: a short
 * label and the page it lives on. History outlives its records, so a subject
 * that no longer exists comes back as "record deleted" with no link.
 *
 * Labels carry only what the linked page already shows (a player's name, a
 * transaction's title) — never a rental's recipient or other personal data.
 */
final class ActivitySubjectLink
{
    /** Relations a subject type needs for its label and URL, eager-loaded with it. */
    private const WITH = [
        PlayerDocument::class => ['type', 'player'],
        PlayerAcademicRecord::class => ['academicYear.player'],
        EquipmentRental::class => ['equipmentItem.catalog'],
        EquipmentItem::class => ['catalog'],
    ];

    /**
     * Load the subjects of many logs at once: one query per subject type,
     * whatever the number of logs. Sets each log's `subject` relation (null
     * when the row is gone).
     *
     * @param  iterable<ActivityLog>  $logs
     */
    public static function preload(iterable $logs): void
    {
        $pending = Collection::make($logs)->filter(
            fn (ActivityLog $log) => $log->subject_type !== null
                && $log->subject_id !== null
                && ! $log->relationLoaded('subject'),
        );

        foreach ($pending->groupBy('subject_type') as $type => $group) {
            $class = self::modelClass((string) $type);
            $models = $class === null
                ? Collection::make()
                : $class::query()
                    ->with(self::WITH[$class] ?? [])
                    ->whereKey($group->pluck('subject_id')->unique()->values()->all())
                    ->get()
                    ->keyBy(fn (Model $model) => (string) $model->getKey());

            foreach ($group as $log) {
                $log->setRelation('subject', $models->get((string) $log->subject_id));
            }
        }
    }

    /** @return array{label: string, url: ?string, deleted: bool} */
    public static function for(ActivityLog $log): array
    {
        if ($log->subject_type === null || $log->subject_id === null) {
            return ['label' => self::propertiesLabel($log->properties ?? []), 'url' => null, 'deleted' => false];
        }

        if (! $log->relationLoaded('subject')) {
            self::preload([$log]);
        }

        $subject = $log->getRelation('subject');

        if (! $subject instanceof Model) {
            return ['label' => UiLang::get('activity.record_deleted', 'Record deleted'), 'url' => null, 'deleted' => true];
        }

        [$label, $url] = self::describe($subject);

        return ['label' => $label, 'url' => $url, 'deleted' => false];
    }

    /** @return class-string<Model>|null */
    private static function modelClass(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /** @return array{0: string, 1: ?string} */
    private static function describe(Model $subject): array
    {
        return match (true) {
            $subject instanceof Player => [$subject->fullname, route('players.show', $subject)],
            $subject instanceof Transaction => [
                self::filled($subject->title) ?? self::amount($subject->amount),
                route('transactions.show', $subject),
            ],
            $subject instanceof FinanceTransfer => [
                self::amount($subject->amount).(self::filled($subject->reference) !== null ? ' · '.$subject->reference : ''),
                route('finance.registers.index'),
            ],
            $subject instanceof Subscription => [(string) $subject->name, route('subscriptions.show', $subject)],
            $subject instanceof MemberJob => [(string) $subject->name, route('jobs.index')],
            $subject instanceof PlayerAcademicRecord => self::playerPart(
                $subject->academicYear?->player,
                (string) $subject->period,
            ),
            $subject instanceof PlayerDocument => self::playerPart(
                $subject->player,
                (string) ($subject->type?->localized_name ?? ''),
            ),
            $subject instanceof EquipmentCatalog => [(string) $subject->name, route('equipment.catalogs.show', $subject)],
            $subject instanceof EquipmentItem => [self::itemLabel($subject), route('equipment.items.history', $subject)],
            $subject instanceof EquipmentRental => $subject->equipmentItem === null
                ? ['#'.$subject->getKey(), null]
                : [self::itemLabel($subject->equipmentItem), route('equipment.items.history', $subject->equipmentItem)],
            $subject instanceof InventorySession => [
                self::filled($subject->reference) ?? '#'.$subject->getKey(),
                route('inventory.show', $subject),
            ],
            $subject instanceof BoardMeeting => [(string) $subject->title, route('board.meetings.show', $subject)],
            $subject instanceof BoardTask => [(string) $subject->title, route('board.tasks')],
            default => [class_basename($subject).' #'.$subject->getKey(), null],
        };
    }

    /**
     * Records that belong to a player (documents, grades) link to the player's page.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function playerPart(?Player $player, string $what): array
    {
        if ($player === null) {
            return [$what !== '' ? $what : '—', null];
        }

        return [
            $what !== '' ? $what.' · '.$player->fullname : $player->fullname,
            route('players.show', $player),
        ];
    }

    private static function itemLabel(EquipmentItem $item): string
    {
        return self::filled($item->designation)
            ?? self::filled($item->unique_identifier)
            ?? self::filled($item->catalog?->name)
            ?? '#'.$item->getKey();
    }

    /** Events without a subject (imports) are described by their row count. */
    private static function propertiesLabel(array $properties): string
    {
        if (isset($properties['count']) && is_numeric($properties['count'])) {
            return str_replace('{count}', (string) (int) $properties['count'], UiLang::get('activity.rows_count', '{count} rows'));
        }

        return '—';
    }

    private static function amount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ' ');
    }

    private static function filled(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
