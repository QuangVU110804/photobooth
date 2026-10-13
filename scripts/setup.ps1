[CmdletBinding()]
param()

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
    (Join-Path $programFiles 'nodejs\node.exe')
)
$npmPath = Find-Executable -Name 'npm.cmd' -FallbackPaths @(
    (Join-Path $programFiles 'nodejs\npm.cmd')
)

if (-not $nodePath -or -not $npmPath) {
    Write-Error 'Node.js LTS and npm are required. Install Node.js 18.17.0 or newer, open a new terminal, then run setup.bat again.'
    exit 1
}

$nodeVersionText = (& $nodePath --version | Select-Object -First 1).Trim().TrimStart('v')
try {
    $nodeVersion = [version]$nodeVersionText
} catch {
    Write-Error "Could not determine the Node.js version: $nodeVersionText"
    exit 1
}

if ($nodeVersion -lt [version]'18.17.0') {
    Write-Error "Node.js 18.17.0 or newer is required. Found: v$nodeVersionText"
    exit 1
}

$npmVersion = (& $npmPath --version | Select-Object -First 1).Trim()
Write-Host "Node.js: v$nodeVersionText"
Write-Host "npm: $npmVersion"
Write-Host 'Installing locked dependencies...'

& $npmPath ci --ignore-scripts --no-audit --no-fund
if ($LASTEXITCODE -ne 0) {
    Write-Error "npm ci failed with exit code $LASTEXITCODE"
    exit $LASTEXITCODE
}

$requiredDependencyFiles = @(
    'node_modules/normalize.css/normalize.css',
    'node_modules/font-awesome/css/font-awesome.css',
    'node_modules/material-icons/iconfont/material-icons.css',
    'node_modules/material-icons/css/material-icons.css',
    'node_modules/whatwg-fetch/dist/fetch.umd.js',
    'node_modules/jquery/dist/jquery.min.js',
    'node_modules/@andreasremdt/simple-translator/dist/umd/translator.min.js'
)

$missingFiles = @(
    $requiredDependencyFiles | Where-Object {
        -not (Test-Path -LiteralPath (Join-Path $projectRoot $_) -PathType Leaf)
    }
)

if ($missingFiles.Count -gt 0) {
    Write-Error ('Dependency installation completed, but required files are missing: ' + ($missingFiles -join ', '))
    exit 1
}

Write-Host 'Dependency setup completed successfully.' -ForegroundColor Green
Write-Host 'Start Apache in XAMPP and open http://localhost:8080/photobooth/'
