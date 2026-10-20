[CmdletBinding()]
param([switch]$CheckOnly)

$ErrorActionPreference = 'Stop'

function Find-Executable {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Name,

        [Parameter(Mandatory = $true)]
        [string[]]$FallbackPaths
    )

    $command = Get-Command -Name $Name -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -ne $command -and $command.Path) {
        return $command.Path
    }

    foreach ($fallbackPath in $FallbackPaths) {
        if (Test-Path -LiteralPath $fallbackPath -PathType Leaf) {
            return (Resolve-Path -LiteralPath $fallbackPath).Path
        }
    }

    return $null
}

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

$programFiles = [Environment]::GetFolderPath('ProgramFiles')
$nodePath = Find-Executable -Name 'node.exe' -FallbackPaths @(
    (Join-Path $projectRoot 'data\tmp\nodejs\node.exe'),
    (Join-Path $programFiles 'nodejs\node.exe')
)
if ($nodePath) {
    # npm.cmd and its child processes must find the selected Node installation.
    $env:PATH = (Split-Path -Parent $nodePath) + [IO.Path]::PathSeparator + $env:PATH
}
$npmPath = Find-Executable -Name 'npm.cmd' -FallbackPaths @(
    (Join-Path $projectRoot 'data\tmp\nodejs\npm.cmd'),
    (Join-Path $programFiles 'nodejs\npm.cmd')
)

if (-not $nodePath -or -not $npmPath) {
    Write-Host 'Node.js and npm were not found. Install a supported Node.js LTS release (22 or 24), open a new terminal, then run setup.bat again.' -ForegroundColor Red
    exit 1
}

$engines = (Get-Content -LiteralPath (Join-Path $projectRoot 'package.json') -Raw | ConvertFrom-Json).engines
$minimumNodeVersion = [version]($engines.node -replace '^>=', '')
$minimumNpmVersion = [version]($engines.npm -replace '^>=', '')
$nodeVersionOutput = & $nodePath --version
$nodeExitCode = $LASTEXITCODE
$nodeVersionText = ([string]($nodeVersionOutput | Select-Object -First 1)).Trim().TrimStart('v')
try {
    $nodeVersion = [version]$nodeVersionText
} catch {
    Write-Error "Could not determine the Node.js version: $nodeVersionText"
    exit 1
}

if ($nodeExitCode -ne 0 -or $nodeVersion -lt $minimumNodeVersion) {
    Write-Error "Node.js $minimumNodeVersion or newer is required. Found: v$nodeVersionText"
    exit 1
}

$npmVersionOutput = & $npmPath --version
$npmExitCode = $LASTEXITCODE
$npmVersion = ([string]($npmVersionOutput | Select-Object -First 1)).Trim()
try {
    $parsedNpmVersion = [version]$npmVersion
} catch {
    Write-Error "Could not determine the npm version: $npmVersion"
    exit 1
}
if ($npmExitCode -ne 0 -or $parsedNpmVersion -lt $minimumNpmVersion) {
    Write-Error "npm $minimumNpmVersion or newer is required. Found: $npmVersion"
    exit 1
}
Write-Host "Node.js: v$nodeVersionText"
Write-Host "npm: $npmVersion"

if (-not $CheckOnly) {
    Write-Host 'Installing locked dependencies...'
    & $npmPath ci --ignore-scripts --no-audit --no-fund
    $installExitCode = $LASTEXITCODE
    if ($installExitCode -ne 0) {
        Write-Host "npm ci failed with exit code $installExitCode" -ForegroundColor Red
        exit $installExitCode
    }
}

$requiredDependencyFiles = @(
    'node_modules/normalize.css/normalize.css',
    'node_modules/font-awesome/css/font-awesome.css',
    'node_modules/material-icons/iconfont/material-icons.css',
    'node_modules/material-icons/css/material-icons.css',
    'node_modules/whatwg-fetch/dist/fetch.umd.js',
    'node_modules/jquery/dist/jquery.min.js',
    'node_modules/@andreasremdt/simple-translator/dist/umd/translator.min.js',
    'node_modules/driver.js/dist/driver.css',
    'node_modules/driver.js/dist/driver.js.iife.js',
    'node_modules/selectize/dist/css/selectize.css',
    'node_modules/selectize/dist/js/standalone/selectize.min.js',
    'node_modules/waypoints/lib/jquery.waypoints.min.js',
    'node_modules/marvinj/marvinj/release/marvinj-1.0.js',
    'node_modules/github-markdown-css/github-markdown.css'
)

$missingFiles = @(
    $requiredDependencyFiles | Where-Object {
        -not (Test-Path -LiteralPath (Join-Path $projectRoot $_) -PathType Leaf)
    }
)

if ($missingFiles.Count -gt 0) {
    Write-Error ('Required frontend files are missing. Run setup.bat to install them: ' + ($missingFiles -join ', '))
    exit 1
}

Write-Host 'Dependency setup completed successfully.' -ForegroundColor Green
$xamppRoot = Split-Path -Parent (Split-Path -Parent $projectRoot)
$apacheConfig = Join-Path $xamppRoot 'apache\conf\httpd.conf'
$apachePort = $null
if (Test-Path -LiteralPath $apacheConfig -PathType Leaf) {
    foreach ($configLine in Get-Content -LiteralPath $apacheConfig) {
        if ($configLine -match '^\s*Listen\s+(?:\S+:)?(\d+)\s*(?:#.*)?$') {
            $apachePort = [int]$Matches[1]
            break
        }
    }
}
if ($apachePort) {
    $portSuffix = if ($apachePort -eq 80) { '' } else { ':' + $apachePort }
    $installationFolder = [Uri]::EscapeDataString((Split-Path -Leaf $projectRoot))
    Write-Host "Start Apache in XAMPP and open http://localhost$portSuffix/$installationFolder/"
} else {
    Write-Host 'Start Apache in XAMPP and open this project using the configured Apache host and port.'
}
