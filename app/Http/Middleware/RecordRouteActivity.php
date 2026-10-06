<?php

namespace App\Http\Middleware;

use App\Services\Activity\ActivityRecorder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records edits, deletions and settings changes for the routes listed in
 * config/activity.php, once the write has succeeded. Imports, migrations,
 * seeders and console fixes never pass through here, so they never inflate
 * the counts (the reason activity is not recorded by model observers).
 */
class RecordRouteActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $entry = config('activity.routes')[$request->route()?->getName() ?? ''] ?? null;

        if ($entry !== null && $request->user() && $this->succeeded($request, $response)) {
            try {
                $this->record($request, (array) $entry);
            } catch (Throwable $e) {
                // Tracking must never break the action it describes.
                report($e);
            }
        }

        return $response;
    }

    private function succeeded(Request $request, Response $response): bool
    {
        if ($request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return false;
        }

        // A validation failure or another exception rendered into a redirect.
        if (property_exists($response, 'exception') && $response->exception !== null) {
            return false;
        }

        // A refusal flashed by the controller in this very request.
        return ! ($request->hasSession() && in_array('error', $request->session()->get('_flash.new', []), true));
    }

    /** @param array{0: string, kind?: string, count?: string} $entry */
    private function record(Request $request, array $entry): void
    {
        // The most specific bound model: the grade in {player}/{academicRecord}.
        $subject = collect($request->route()->parameters())->last(fn ($p) => $p instanceof Model);

        $properties = [];
        if (isset($entry['kind'])) {
            $properties['kind'] = $entry['kind'];
        }
        if (isset($entry['count']) && is_array($request->input($entry['count']))) {
            $properties['count'] = count($request->input($entry['count']));
        }

        ActivityRecorder::record($request->user(), $entry[0], $subject, $properties);
    }
}
