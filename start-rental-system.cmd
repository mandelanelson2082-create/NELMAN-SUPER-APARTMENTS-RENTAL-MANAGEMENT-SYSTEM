@echo off
setlocal
set "XAMPP=C:\xampp"
set "APP_DIR=C:\xampp\htdocs\rental_system"
set "WORKSPACE_DIR=C:\Users\USER\Documents\Qoder\2026-09-27\a14aed9c\rental_system"

if not exist "%APP_DIR%" (
    if exist "%WORKSPACE_DIR%" (
        if exist "%XAMPP%\htdocs" (
            mklink /J "%APP_DIR%" "%WORKSPACE_DIR%" >nul 2>nul
        )
    )
)

if not exist "%XAMPP%\apache\bin\httpd.exe" (
    echo XAMPP Apache not found at %XAMPP%\apache\bin\httpd.exe
    exit /b 1
)

if not exist "%XAMPP%\mysql\bin\mysqld.exe" (
    echo XAMPP MySQL not found at %XAMPP%\mysql\bin\mysqld.exe
    exit /b 1
)

rem Start Apache and MySQL if they are not already running.
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I "httpd.exe" >NUL || call "%XAMPP%\apache_start.bat"
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL || call "%XAMPP%\mysql_start.bat"

echo XAMPP services started.
echo Open the app at http://localhost/rental_system/
exit /b 0
