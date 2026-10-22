<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/*
 * Route timings, in-process, against a COPY of a SQLite database.
 *
 *   php scripts/perf/bench.php <dbCopy> [userId=4] [iterations=8] [routes,comma,separated]
 *
 * Boots the app fresh for every request (like `php -S` does), as the given
 * user, as an Inertia visit; the first iteration is dropped. Prints p50/p95,
 * query count and time, duplicate queries and payload size per route.
 * GET requests can write (activity log, calendar generation), so it refuses
 * to run on a database inside the project. Run it the way the app runs:
 * with OPcache (PHP_INI_SCAN_DIR=php-ini) and config not cached.
 */

$root = dirname(__DIR__, 2);
[$dbCopy, $userId, $iterations] = [$argv[1] ?? null, (int) ($argv[2] ?? 4), max(2, (int) ($argv[3] ?? 8))];
$routes = isset($argv[4]) ? explode(',', $argv[4]) : [
    'dashboard', 'players', 'players?category_id[]=1', 'players?branch_id[]=1', 'players?status[]=1',
    'attendance', 'attendance/stats', 'attendance/ranking', 'attendance/alerts', 'attendance/injuries',
    'transactions', 'subscriptions', 'finance', 'users/activity',
];

$real = $dbCopy ? realpath($dbCopy) : false;
if ($real === false || str_starts_with(strtolower($real), strtolower(realpath($root)))) {
    fwrite(STDERR, "Give the path of a database COPY outside the project.\n");
    exit(1);
}

foreach (['DB_DATABASE' => $real, 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false', 'LOG_LEVEL' => 'error'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
chdir($root);
require $root.'/vendor/autoload.php';

$boot = function () use ($root) {
    Facade::clearResolvedInstances();
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    return [$app, $kernel];
};

[$app] = $boot();
if (realpath($app['db']->connection()->getDatabaseName()) !== $real) {
    fwrite(STDERR, "Refusing: the app is not on the copy (is the config cached?).\n");
    exit(1);
}

$version = null;
foreach ($routes as $uri) {
    $times = [];
    $peak = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        [$app, $kernel] = $boot();
        $app->instance('request', Request::create('/'));
        $app['auth']->guard('web')->setUser(User::find($userId));
        $queries = [];
        $app['db']->listen(function ($event) use (&$queries) {
            $queries[] = [$event->sql.'|'.json_encode($event->bindings), $event->time];
        });
        $version ??= (string) (app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '');
        $request = Request::create('/'.$uri, 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => $version, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $response = $kernel->handle($request);
        $bytes = strlen((string) $response->getContent());
        $kernel->terminate($request, $response);
        if ($i > 0) {
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        $peak = max($peak, memory_get_peak_usage(true));
    }
    sort($times);
    $pick = fn (float $pct) => $times[min(count($times) - 1, (int) floor($pct * (count($times) - 1) + 0.5))];
    $repeats = array_sum(array_map(fn ($n) => $n - 1, array_filter(array_count_values(array_column($queries, 0)), fn ($n) => $n > 1)));
    printf("%-34s %3d  p50 %8.1f  p95 %8.1f  q %3d  qms %7.1f  dup %3d  kb %8.1f\n",
        $uri, $response->getStatusCode(), $pick(0.5), $pick(0.95), count($queries), array_sum(array_column($queries, 1)), $repeats, $bytes / 1024);
}
printf("peak memory %.1f MB\n", $peak / 1048576);
