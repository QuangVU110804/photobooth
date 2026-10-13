@echo off
setlocal

set "projectRoot=%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%projectRoot%scripts\setup.ps1" %*
set "setupExit=%ERRORLEVEL%"

if not "%setupExit%"=="0" (
    echo.
    echo Dependency setup failed with exit code %setupExit%.
    exit /b %setupExit%
)

echo.
echo Dependency setup completed.
exit /b 0
