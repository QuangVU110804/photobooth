param(
    [Parameter(Mandatory)] [string]$OutputDirectory,
    [ValidateSet('win-x64', 'win-x86', 'win-arm64')] [string]$Architecture = 'win-x64'
)
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$destination = [IO.Path]::GetFullPath($ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutputDirectory))
$site = [IO.Path]::GetFullPath($project).TrimEnd('\')
if ($destination.TrimEnd('\') -ieq $site -or $destination.StartsWith($site + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a publish folder outside the served Photobooth directory.'
}

& dotnet publish (Join-Path $PSScriptRoot 'PhotoboothKiosk.csproj') --configuration Release --runtime $Architecture --self-contained true --output $destination
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
foreach ($name in 'README.md', 'Dat-mat-khau.cmd') {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $name) -Destination $destination
}
