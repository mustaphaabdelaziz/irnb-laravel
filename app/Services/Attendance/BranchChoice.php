<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * The branch the attendance pages show (calendar, month grid, month sheet, report pages):
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

    /**
     * The branch a report page shows: a valid `branch_id` from the query
     * (an unknown one fails validation), else as resolve(). The choice is
     * shared with the calendar: switching on one page switches them all.
     */
    public static function fromRequest(Request $request): ?int
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);

        return self::resolve($request, $data['branch_id'] ?? null);
    }

    /** The remembered branch, read without changing it (the profile card's default tab); null when none. */
    public static function remembered(Request $request): ?int
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{id: int, name: string, logo_url: ?string}> the switch's branches, by id */
    public static function options(): array
    {
        return Branch::orderBy('id')->get()
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->localized_name, 'logo_url' => $branch->logo_url])
            ->values()
            ->all();
    }
}
