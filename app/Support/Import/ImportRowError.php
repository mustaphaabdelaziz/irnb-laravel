<?php

namespace App\Support\Import;

use App\Exceptions\ClosedFiscalYearException;
use App\Exceptions\Equipment\StockException;
use App\Exceptions\Import\ImportRowException;
use App\Support\UiLang;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The text an import shows for a row that failed. The app's own refusals
 * (a closed season, a birth date after the join date, a validation error,
 * a stock limit) are sentences in the reader's language and are shown.
 * Anything else — a database or framework error — is logged, and the reader
 * gets one translated sentence instead of English internals.
 */
final class ImportRowError
{
    public static function message(Throwable $e): string
    {
        if ($e instanceof StockException) {
            // Its message is English; its key is the UI's translated sentence.
            return strtr(UiLang::get($e->key, $e->getMessage()), collect($e->params)
                ->mapWithKeys(fn ($value, $name) => ['{'.$name.'}' => (string) $value])
                ->all());
        }

        if ($e instanceof ValidationException
            || $e instanceof ClosedFiscalYearException
            || $e instanceof ImportRowException) {
            return $e->getMessage();
        }

        report($e);

        return __('This row could not be saved.');
    }
}
