<?php

namespace App\Models\Concerns;

use App\Support\ClubTimezone;
use DateTimeInterface;

/**
 * Dates reach the screens on the club's clock, with its offset
 * ("2010-05-01T00:00:00.000000+01:00"). Laravel's default sends them in UTC,
 * where a `date` (midnight in Algiers) is still the day before — and every
 * edit form that keeps the date part would save that earlier day.
 *
 * A model that formats its own dates (Transaction, FinanceTransfer) keeps its
 * serializeDate(): a class method wins over a trait's.
 */
trait SerializesClubDates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format(ClubTimezone::JSON_FORMAT);
    }
}
