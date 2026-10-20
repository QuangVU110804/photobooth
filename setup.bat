@echo off
setlocal

set "projectRoot=%~dp0"
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%projectRoot%scripts\setup.ps1" %*
set "setupExit=%ERRORLEVEL%"

if not "%setupExit%"=="0" (
    echo.
    echo Dependency setup failed with exit code %setupExit%.
    exit /b %setupExit%
)

echo.
echo Photobooth dependencies are ready.
exit /b 0
