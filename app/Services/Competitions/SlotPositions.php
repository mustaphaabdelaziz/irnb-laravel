<?php

namespace App\Services\Competitions;

use Illuminate\Support\Collection;

/**
 * Which position a formation slot stands for. A slot label is first tried as a
 * position abbreviation itself (GK, DF, CB…); preset labels that no seeded
 * position carries (ST, CM, LW…) fall back to the aliases below, in order.
 * The one place this mapping lives: the line-up save, the auto-fill (through
 * the "positions" of each slot sent to the page), the demo seeder and the
 * repair command all read it.
 */
final class SlotPositions
{
    /** @var array<string, list<string>> */
    public const ALIASES = [
        'ST' => ['FW', 'CF'],
        'CF' => ['FW'],
        'CM' => ['MF'],
        'LM' => ['MF'],
        'RM' => ['MF'],
        'LW' => ['WG'],
        'RW' => ['WG'],
        'LWB' => ['LB'],
        'RWB' => ['RB'],
    ];

    /** @return list<string> abbreviations in lookup order: the label itself, then its aliases */
    public function candidates(string $label): array
    {
        $label = strtoupper(trim($label));
        if ($label === '' || ctype_digit($label)) {
            return [];
        }

        return [$label, ...(self::ALIASES[$label] ?? [])];
    }

    /**
     * @param  Collection<string, int>|array<string, int>  $positions  abbreviation => id
     */
    public function resolve(string $label, Collection|array $positions): ?int
    {
        $positions = collect($positions)->mapWithKeys(fn ($id, $abbr) => [strtoupper((string) $abbr) => $id]);
        foreach ($this->candidates($label) as $abbreviation) {
            if ($positions->has($abbreviation)) {
                return (int) $positions[$abbreviation];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots  a formation's slots
     * @return array<int, array<string, mixed>> the same slots with their "positions" candidates
     */
    public function withCandidates(array $slots): array
    {
        return array_map(fn (array $slot) => $slot + ['positions' => $this->candidates((string) ($slot['label'] ?? ''))], $slots);
    }
}
