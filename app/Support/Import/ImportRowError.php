<?php

namespace App\Support\Import;

use DomainException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The text an import shows for a row that failed. The app's own refusals
 * (a closed season, a birth date after the join date, a validation error)
 * are already sentences in the reader's language and are shown as they are.
 * Anything else — a database error above all — is logged, and the reader
 * gets one translated sentence instead of English SQL.
 */
final class ImportRowError
{
    public static function message(Throwable $e): string
    {
        // QueryException is a PDOException, which is a RuntimeException: test it first.
        $domain = ! $e instanceof PDOException && (
            $e instanceof ValidationException
            || $e instanceof RuntimeException
            || $e instanceof InvalidArgumentException
            || $e instanceof DomainException
        );

        if ($domain) {
            return $e->getMessage();
        }

        report($e);

        return __('This row could not be saved.');
    }
}
