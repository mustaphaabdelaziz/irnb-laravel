<?php

namespace App\Services\Dashboard\Support;

/**
 * Period-over-period change, with tone decided by meaning rather than sign.
 *
 * Debt falling is good news and renders positive; debt rising is bad news and
 * renders negative. Colouring by the arithmetic sign instead is the mistake
 * that makes a dashboard quietly lie.
 */
class DeltaCalculator
{
    public const UP_GOOD = 'up_good';

    public const DOWN_GOOD = 'down_good';

    /**
     * @param  string  $direction  self::UP_GOOD or self::DOWN_GOOD
     * @return array{percent: float, raw: float, tone: string}|null
     *                                                              Null when there is nothing to compare against — a missing previous
     *                                                              period is not a 100% rise, and dividing by zero is not infinity worth
     *                                                              printing.
     */
    public static function compute(float $current, ?float $previous, string $direction = self::UP_GOOD): ?array
    {
        if ($previous === null || abs($previous) < 0.00001) {
            return null;
        }

        $raw = $current - $previous;
        $percent = round($raw / abs($previous) * 100, 1);

        return [
            'percent' => $percent,
            'raw' => round($raw, 2),
            'tone' => self::tone($percent, $direction),
        ];
    }

    private static function tone(float $percent, string $direction): string
    {
        if (abs($percent) < 0.05) {
            return 'neutral';
        }

        $improved = $direction === self::DOWN_GOOD ? $percent < 0 : $percent > 0;

        return $improved ? 'positive' : 'negative';
    }
}
