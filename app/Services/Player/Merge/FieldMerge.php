<?php

namespace App\Services\Player\Merge;

use App\Models\Player;
use App\Services\Player\Duplicates\DuplicateScorer;

/**
 * The player's own columns in a merge (owner 2026-11-26): where only the
 * duplicate has a value it fills in by itself, where both differ the user
 * chooses, phones from both are kept, and the kept player stays active if
 * either was. Membership ID, status, category and debt stay the keeper's.
 */
final class FieldMerge
{
    public const FIELDS = [
        'firstname', 'lastname', 'nickname', 'father', 'grandfather', 'birthdate', 'gender', 'picture',
        'email', 'city', 'wilaya_id', 'is_student', 'member_job_id',
        'health_medical_conditions', 'health_blood_group_rhesus', 'licence_number',
    ];

    /** @return list<array{field:string, keeper:?string, duplicate:?string, mode:string}> */
    public function diff(Player $keeper, Player $duplicate): array
    {
        return array_map(function (string $field) use ($keeper, $duplicate) {
            $mine = $this->raw($keeper, $field);
            $theirs = $this->raw($duplicate, $field);

            return [
                'field' => $field,
                'keeper' => $this->display($keeper, $field),
                'duplicate' => $this->display($duplicate, $field),
                'mode' => match (true) {
                    $mine === $theirs => 'same',
                    $mine === null => 'auto',
                    $theirs === null => 'keep',
                    default => 'choose',
                },
            ];
        }, self::FIELDS);
    }

    /** @param  array<string, string>  $choices  field => 'keeper' | 'duplicate' */
    public function resolve(Player $keeper, Player $duplicate, array $choices): array
    {
        $attributes = [];
        foreach ($this->diff($keeper, $duplicate) as $row) {
            $take = $row['mode'] === 'auto'
                || ($row['mode'] === 'choose' && ($choices[$row['field']] ?? 'keeper') === 'duplicate');
            if (! $take) {
                continue;
            }
            foreach ($this->columns($row['field']) as $column) {
                $attributes[$column] = $duplicate->getRawOriginal($column);
            }
        }

        $phones = [];
        $seen = [];
        foreach ([...($keeper->phones ?? []), ...($duplicate->phones ?? [])] as $phone) {
            $raw = trim((string) $phone);
            $key = DuplicateScorer::phone($raw) ?: $raw;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $phones[] = $phone;
        }
        $attributes['phones'] = $phones;
        $attributes['archived'] = (bool) $keeper->archived && (bool) $duplicate->archived;

        return $attributes;
    }

    /** @return list<string> */
    private function columns(string $field): array
    {
        return $field === 'picture' ? ['picture_url', 'picture_filename'] : [$field];
    }

    /** Comparable value, null when empty. */
    private function raw(Player $player, string $field): ?string
    {
        $value = $player->getRawOriginal($field === 'picture' ? 'picture_url' : $field);
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            $value = (int) $value;
        }
        $value = trim((string) $value);
        if ($field === 'birthdate') {
            $value = substr($value, 0, 10);
        }

        return $value === '' ? null : $value;
    }

    private function display(Player $player, string $field): ?string
    {
        return match ($field) {
            'member_job_id' => $player->memberJob?->localized_name ?? $player->memberJob?->name,
            'wilaya_id' => $player->wilaya?->name,
            default => $this->raw($player, $field),
        };
    }
}
