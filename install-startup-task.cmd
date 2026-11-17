@echo off
setlocal
set "XAMPP=C:\xampp"
set "APP_DIR=%XAMPP%\htdocs\rental_system"
set "START_SCRIPT=C:\Users\USER\Documents\Qoder\2026-09-27\a14aed9c\rental_system\start-rental-system.cmd"

if not exist "%START_SCRIPT%" (
    echo Startup script not found: %START_SCRIPT%
    exit /b 1
)

if not exist "%XAMPP%\php\php.exe" (
    echo PHP not found: %XAMPP%\php\php.exe
    exit /b 1
)

if not exist "%APP_DIR%\cron\generate_monthly.php" (
    echo Monthly invoice generator not found: %APP_DIR%\cron\generate_monthly.php
    echo Start the rental system once to create its XAMPP web-root link, then retry.
    exit /b 1
)

schtasks /Delete /TN "Rental System XAMPP" /F 2>nul
schtasks /Create /TN "Rental System XAMPP" /SC ONLOGON /RL HIGHEST /TR "\"%START_SCRIPT%\"" /F
if errorlevel 1 (
    echo Could not create the XAMPP startup task. Run this script as Administrator.
    exit /b 1
)

schtasks /Delete /TN "Rental System Monthly Invoicing" /F 2>nul
schtasks /Create /TN "Rental System Monthly Invoicing" /SC MONTHLY /D 1 /ST 06:00 /TR "%XAMPP%\php\php.exe %APP_DIR%\cron\generate_monthly.php" /RU SYSTEM /RL HIGHEST /F
if errorlevel 1 (
    echo Could not create the monthly invoicing task. Run this script as Administrator.
    exit /b 1
)

echo Startup and monthly invoicing tasks created.
echo Apache and MySQL will start automatically when this user signs in.
echo Monthly rent invoices will be generated at 06:00 on the first of each month.
exit /b 0
