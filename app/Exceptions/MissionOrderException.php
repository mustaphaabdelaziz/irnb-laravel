<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A mission-order step the order's state refuses (editing or cancelling a
 * cancelled or imported order, taking a reference for one…). An expected
 * refusal, never logged: the flash error it names on a page, or — for the
 * Print buttons' JSON call — a 422 whose message is that flash key.
 */
class MissionOrderException extends RuntimeException
{
    public function __construct(public readonly string $flashKey)
    {
        parent::__construct($flashKey);
    }

    public function report(): void {}

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->flashKey], 422);
        }

        return back()->withInput()->with('error', $this->flashKey);
    }
}
