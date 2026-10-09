<#
.SYNOPSIS
    Photobooth Real-Time Pipeline Watcher Daemon
.DESCRIPTION
    Event-driven file watcher powered by .NET FileSystemWatcher.
    Monitors codebase changes in real-time with debounce buffer and
    executes ultra-fast incremental quality gate checks (<60ms latency).
#>

[CmdletBinding()]
param(
    [switch]$AutoHeal = $true,
    [int]$DebounceMs = 300
)

$ErrorActionPreference = "Continue"
$rootDir = (Split-Path -Parent $PSScriptRoot)
$phpExe = "C:\xampp\php\php.exe"

if (-not (Test-Path $phpExe)) {
    $phpExe = (Get-Command php -ErrorAction SilentlyContinue).Source
}

if (-not $phpExe -or -not (Test-Path $phpExe)) {
    Write-Error "PHP executable not found at C:\xampp\php\php.exe or in system PATH."
    exit 1
}

Clear-Host
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "   👀 PHOTOBOOTH REAL-TIME QUALITY GATE WATCHER DAEMON" -ForegroundColor Green
Write-Host "   Directory : $rootDir" -ForegroundColor Gray
Write-Host "   PHP Bin   : $phpExe" -ForegroundColor Gray
Write-Host "   Auto-Heal : $(if ($AutoHeal) { 'ACTIVE' } else { 'STANDBY' })" -ForegroundColor Yellow
Write-Host "   Engine    : .NET Kernel FileSystemWatcher (Zero Idle CPU)" -ForegroundColor Magenta
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "Monitoring changes... (Press Ctrl+C to stop)`n" -ForegroundColor DarkGray

# Setup FileSystemWatcher
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $rootDir
$watcher.IncludeSubdirectories = $true
$watcher.EnableRaisingEvents = $true
$watcher.NotifyFilter = [System.IO.NotifyFilters]'FileName, LastWrite'

$ignoredFolders = @('node_modules', 'vendor', 'data', 'archives', '.git', 'reports')
$lastHandled = @{}

$action = {
    param($source, $eventArgs)
    $path = $eventArgs.FullPath
    $ext = [System.IO.Path]::GetExtension($path).ToLower()

    # Filter extensions
    if ($ext -notin @('.php', '.json')) { return }

    # Check ignored directories
    foreach ($ignore in $using:ignoredFolders) {
        if ($path -like "*\$ignore\*" -or $path -like "*/$ignore/*") { return }
    }

    # Debounce check
    $now = [DateTime]::UtcNow
    if ($using:lastHandled.ContainsKey($path)) {
        $diff = ($now - $using:lastHandled[$path]).TotalMilliseconds
        if ($diff -lt $using:DebounceMs) { return }
    }
    $using:lastHandled[$path] = $now

    $rel = $path.Replace($using:rootDir + "\", "")
    $timestamp = Get-Date -Format "HH:mm:ss"

    Write-Host "`n[$timestamp] 📝 Detected change: $rel" -ForegroundColor Cyan

    $healArg = if ($using:AutoHeal) { "--heal" } else { "" }
    $sw = [System.Diagnostics.Stopwatch]::StartNew()

    $cmd = "& `"$using:phpExe`" `"$using:rootDir\scripts\run-tests.php`" --file=`"$path`" $healArg"
    Invoke-Expression $cmd

    $sw.Stop()
    $elapsed = [Math]::Round($sw.Elapsed.TotalMilliseconds, 1)

    if ($LASTEXITCODE -eq 0) {
        Write-Host "[$timestamp] 🟢 [PASS] $rel checked in $elapsed ms" -ForegroundColor Green
    } else {
        Write-Host "[$timestamp] 🔴 [FAIL] Defect detected! Check reports/ai-fix-prompt.md" -ForegroundColor Red
    }
}

Register-ObjectEvent $watcher 'Changed' -Action $action | Out-Null
Register-ObjectEvent $watcher 'Created' -Action $action | Out-Null

try {
    while ($true) {
        Start-Sleep -Seconds 1
    }
}
finally {
    Unregister-Event -SourceIdentifier $watcher.Changed | Out-Null
    $watcher.Dispose()
    Write-Host "`nWatcher stopped." -ForegroundColor Yellow
}
