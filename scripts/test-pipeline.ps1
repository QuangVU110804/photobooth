<#
.SYNOPSIS
    Photobooth Automated Test Pipeline for Local Development
.DESCRIPTION
    Runs automated checks, PHP syntax linting, configuration verification,
    and API smoke tests before committing or building features/fixes.
#>

$ErrorActionPreference = "Stop"

$repoDir = Split-Path -Parent $PSScriptRoot
$phpExe = "C:\xampp\php\php.exe"

if (-not (Test-Path $phpExe)) {
    $phpExe = (Get-Command php -ErrorAction SilentlyContinue).Source
}

if (-not $phpExe -or -not (Test-Path $phpExe)) {
    Write-Error "PHP executable not found at C:\xampp\php\php.exe or in PATH."
    exit 1
}

Write-Host "Using PHP binary: $phpExe" -ForegroundColor Cyan
& $phpExe "$PSScriptRoot\run-tests.php"
if ($LASTEXITCODE -ne 0) {
    Write-Host "`nTest pipeline failed! Fix errors before committing." -ForegroundColor Red
    exit 1
}

Write-Host "`nTest pipeline passed successfully." -ForegroundColor Green
exit 0
