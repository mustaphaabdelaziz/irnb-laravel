<?php

namespace App\Exceptions;

use App\Support\FiscalPeriod;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Money cannot be written into, out of or removed from a closed season.
 * Thrown by TransactionObserver so every path — the form, player payments,
 * imports, equipment purchases — obeys the same lock.
 */
class ClosedFiscalYearException extends RuntimeException
{
    public function __construct(public readonly int $year)
    {
        parent::__construct(__('Season :season is closed. Reopen it to change its transactions.', [
            'season' => FiscalPeriod::label($year),
        ]));
    }

    /** An expected refusal, not a fault: keep it out of the error log. */
    public function report(): void {}

    public function render(): RedirectResponse
    {
        return back()->withInput()->with('error', [
            'key' => 'flash.season_closed',
            'params' => ['season' => FiscalPeriod::label($this->year)],
        ]);
    }
}
