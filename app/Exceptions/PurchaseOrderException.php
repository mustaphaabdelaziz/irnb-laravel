<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A purchase order step the order's state refuses (editing a sent order's
 * lines, receiving a draft…). An expected refusal: rendered as the flash error
 * it names, never logged.
 */
class PurchaseOrderException extends RuntimeException
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
