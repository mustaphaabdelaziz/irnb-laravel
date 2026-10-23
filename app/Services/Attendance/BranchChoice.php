<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * The branch the attendance pages show (calendar, month grid, month sheet):
 * the one asked for (remembered in the session), else the remembered one if
 * it still exists, else the first; null when the club has no branch (every
 * session then shows).
 */
final class BranchChoice
{
    private const SESSION_KEY = 'attendance.branch_id';

    public static function resolve(Request $request, int|string|null $asked): ?int
    {
        if ($asked !== null && $asked !== '') {
            $request->session()->put(self::SESSION_KEY, (int) $asked);

            return (int) $asked;
        }
        $remembered = $request->session()->get(self::SESSION_KEY);
        if ($remembered !== null && Branch::whereKey($remembered)->exists()) {
            return (int) $remembered;
        }

        $request->session()->forget(self::SESSION_KEY);

        $first = Branch::orderBy('id')->value('id');

        return $first === null ? null : (int) $first;
    }
}
