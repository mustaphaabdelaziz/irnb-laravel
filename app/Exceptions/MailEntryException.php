<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A mail-register step the entry's state refuses (editing a cancelled entry,
 * moving a date out of its number's year, cancelling an imported letter…).
 * An expected refusal: rendered as the flash error it names, never logged.
 */
class MailEntryException extends RuntimeException
{
    public function __construct(public readonly string $flashKey)
    {
        parent::__construct($flashKey);
    }

    public function report(): void {}

    public function render(): RedirectResponse
    {
        return back()->withInput()->with('error', $this->flashKey);
    }
}
