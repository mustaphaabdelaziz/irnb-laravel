@echo off
setlocal
REM ============================================================
REM  start.bat - Launch backend + frontend dev servers
REM  Backend : php artisan serve  -> http://127.0.0.1:8000
REM  Frontend: npm run dev (Vite) -> http://127.0.0.1:5173
REM  Each runs in its own window. Close a window to stop it.
REM ============================================================

cd /d "%~dp0"

REM --- Enable OPcache for this session (project-scoped) --------
REM  php-ini\opcache.ini loads the OPcache extension so PHP stops
REM  recompiling ~8k files on every request. No global ini change.
set "PHP_INI_SCAN_DIR=%~dp0php-ini"

REM --- Dependencies --------------------------------------------
if not exist "vendor\autoload.php" (
    echo [start] Installing PHP dependencies...
    call composer install || goto :err
)
if not exist "node_modules" (
    echo [start] Installing Node dependencies...
    call npm install || goto :err
)

REM --- .env ----------------------------------------------------
if not exist ".env" (
    echo [start] Creating .env from .env.example...
    copy ".env.example" ".env" >nul
    call php artisan key:generate
)

REM --- Fast boot: cache config from the CURRENT .env -----------
REM  Rebuilt every launch so .env edits are picked up on restart.
REM  Cuts cold-boot time (skips parsing ~30 config files + .env).
REM  route:cache is intentionally skipped - web routes use closures.
echo [start] Caching config for fast boot...
call php artisan config:clear >nul 2>&1
call php artisan config:cache >nul 2>&1

REM --- Remove stale Vite hot file ------------------------------
REM  If this file exists but Vite is not running, Laravel points
REM  the browser at a dead dev server and the page won't load.
if exist "public\hot" (
    echo [start] Removing stale public\hot ...
    del /f /q "public\hot"
)

REM --- Launch servers in separate windows ----------------------
REM  Vite first so the hot file exists before the page loads.
echo [start] Starting frontend (npm run dev / Vite)
start "FRONTEND - vite" cmd /k "npm run dev"

echo [start] Starting backend  (php artisan serve)  -> http://127.0.0.1:8000
start "BACKEND - artisan serve" cmd /k "php artisan serve"

REM --- Wait until backend actually responds --------------------
REM  Poll the /up health route instead of a fixed sleep, so the
REM  browser never opens before the server is ready to answer.
echo [start] Waiting for backend to be ready...
set /a _tries=0
:wait
curl -s -o nul http://127.0.0.1:8000/up
if not errorlevel 1 goto :ready
set /a _tries+=1
if %_tries% geq 30 (
    echo [start] Backend not ready after 30s - opening anyway.
    goto :ready
)
timeout /t 1 /nobreak >nul
goto :wait

:ready
start "" "http://127.0.0.1:8000"
echo [start] Done. Two server windows opened. Close them to stop.
goto :eof

:err
echo [start] ERROR: dependency install failed. See output above.
pause
