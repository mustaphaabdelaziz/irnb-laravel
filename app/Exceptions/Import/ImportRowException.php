<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * An import row the app refuses on purpose — a join date before the birth
 * date, a category of another branch. The message is already a sentence in
 * the reader's language, so the import shows it as it is.
 */
class ImportRowException extends RuntimeException {}
