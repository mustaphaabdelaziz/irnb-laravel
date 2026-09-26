<?php

namespace App\Http\Controllers;

use App\Services\Finance\FinanceResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Start fresh" for the books: superadmin-only twin of `php artisan finance:reset`,
 * so the desktop app (no terminal) can do it too.
 */
class FinanceResetController extends Controller
{
    public function store(Request $request, FinanceResetService $reset): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:RESET']]);

        $reset->backup();
        $reset->reset();

        return back()->with('success', 'flash.finance_reset_done');
    }
}
