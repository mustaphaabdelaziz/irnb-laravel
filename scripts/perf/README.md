# Performance scripts

Measure before and after a change, on the same machine, with nothing else running.

| Script | Measures |
|---|---|
| `bench.php` | Route timings in-process (p50/p95, queries, payload) against a database copy |
| `scale.php` | Grows a database copy (more players, more seasons) to see how pages scale |
| `desktop-startup.ps1` | Packaged desktop app: time until the window shows, per-phase log |

```bash
# a grown copy, outside the project (scale.php refuses paths inside it)
cp database/database.sqlite /tmp/club-scaled.sqlite
php scripts/perf/scale.php /tmp/club-scaled.sqlite 10 5

# route timings, with OPcache as start.bat runs the app, config not cached
php artisan config:clear
PHP_INI_SCAN_DIR=php-ini php -d memory_limit=2G scripts/perf/bench.php /tmp/club-scaled.sqlite 4 6 "attendance/stats,attendance/injuries"
```

```powershell
# a test build, on its own data folder
.\scripts\perf\desktop-startup.ps1 -Exe nativephp\electron\dist\win-unpacked\sport-club.exe -Runs 5 -UserDataDir $env:TEMP\perf-userdata
```

Notes from the 2026-10 round:

- The club databases have no planner statistics (`sqlite_stat1`); do not `ANALYZE` a copy you measure.
- Pages write on GET (activity log, calendar generation): always work on a copy.
- The Bash tool's shell sets `ELECTRON_RUN_AS_NODE=1`; the PowerShell script removes it for the app it starts.
