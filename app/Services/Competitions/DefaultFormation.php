<?php

namespace App\Services\Competitions;

/**
 * The automatic shape for a format that has no formations (owner decision):
 * spot "1" near our goal, the others spread evenly over rows of up to four
 * going up the pitch. Coordinates are 0–100, y measured from our goal line,
 * like the slots of a saved formation.
 */
final class DefaultFormation
{
    /** @return list<array{label: string, x: int, y: int}> */
    public static function slots(int $playersOnField): array
    {
        if ($playersOnField <= 0) {
            return [];
        }
        $slots = [['label' => '1', 'x' => 50, 'y' => 8]];
        $rest = $playersOnField - 1;
        $rows = (int) ceil($rest / 4);

        for ($row = 0; $row < $rows; $row++) {
            // Earlier rows take the leftovers: 10 outfield players → 4, 3, 3.
            $count = intdiv($rest, $rows) + ($row < $rest % $rows ? 1 : 0);
            $y = $rows === 1 ? 55 : (int) round(30 + $row * 50 / ($rows - 1));
            for ($i = 0; $i < $count; $i++) {
                $slots[] = [
                    'label' => (string) (count($slots) + 1),
                    'x' => (int) round(10 + ($i + 1) * 80 / ($count + 1)),
                    'y' => $y,
                ];
            }
        }

        return $slots;
    }
}
