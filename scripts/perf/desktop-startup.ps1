# Desktop startup timing: launches the app $Runs times and reports when its
# window first becomes visible, with a timestamped log of the shell's output
# per run (phases: native:config, optimize, migrate, php -S, booted).
#
#   .\scripts\perf\desktop-startup.ps1 -Exe <path\to\sport-club.exe> -Runs 5 [-UserDataDir <empty folder>]
#
# -UserDataDir runs the app on its own data folder (Electron --user-data-dir):
# use it for a test build so the installed app's database is never touched.
# Only processes started from the exe's folder are stopped between runs.
param(
    [string]$Exe = "$env:LOCALAPPDATA\Programs\sport-club\sport-club.exe",
    [int]$Runs = 3,
    [string]$OutDir = $env:TEMP,
    [string]$UserDataDir = '',
    [string]$Tag = 'run'
)

function Stop-App {
    $root = Split-Path $Exe
    Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($root, [StringComparison]::OrdinalIgnoreCase) } | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Milliseconds 1500
}

$results = @()
for ($r = 1; $r -le $Runs; $r++) {
    Stop-App
    $psi = New-Object System.Diagnostics.ProcessStartInfo $Exe
    $psi.UseShellExecute = $false
    if ($UserDataDir) { $psi.Arguments = "--user-data-dir=`"$UserDataDir`"" }
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.EnvironmentVariables.Remove('ELECTRON_RUN_AS_NODE')
    $psi.EnvironmentVariables['SHELL_VERBOSITY'] = '1'

    $log = [System.Collections.Concurrent.ConcurrentQueue[string]]::new()
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $p = New-Object System.Diagnostics.Process
    $p.StartInfo = $psi
    $handler = { if ($EventArgs.Data) { $Event.MessageData.Q.Enqueue(('{0,7} {1}' -f $Event.MessageData.SW.ElapsedMilliseconds, $EventArgs.Data)) } }
    $md = @{ Q = $log; SW = $sw }
    $e1 = Register-ObjectEvent $p OutputDataReceived -Action $handler -MessageData $md
    $e2 = Register-ObjectEvent $p ErrorDataReceived -Action $handler -MessageData $md
    [void]$p.Start(); $p.BeginOutputReadLine(); $p.BeginErrorReadLine()

    $windowMs = $null
    while ($sw.ElapsedMilliseconds -lt 90000) {
        $w = Get-Process sport-club -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 -and $_.MainWindowTitle }
        if ($w) { $windowMs = $sw.ElapsedMilliseconds; break }
        Start-Sleep -Milliseconds 50
    }
    Start-Sleep -Seconds 4
    $mem = (Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith((Split-Path $Exe), [StringComparison]::OrdinalIgnoreCase) } | Measure-Object WorkingSet64 -Sum).Sum / 1MB
    Unregister-Event -SourceIdentifier $e1.Name; Unregister-Event -SourceIdentifier $e2.Name
    Stop-App

    $lines = @(); $line = $null
    while ($log.TryDequeue([ref]$line)) { $lines += $line }
    $lines | Set-Content -Encoding utf8 (Join-Path $OutDir "desktop-$Tag$r.log")
    $results += [pscustomobject]@{ Run = $r; WindowVisibleMs = $windowMs; WorkingSetMB = [math]::Round($mem, 0); LogLines = $lines.Count }
}
$results | Format-Table -AutoSize
