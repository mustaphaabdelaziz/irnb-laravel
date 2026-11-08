<?php

namespace App\Services\Help;

use RuntimeException;

/** A help article that can't be read; the message starts with the article key. */
class HelpParseException extends RuntimeException {}
