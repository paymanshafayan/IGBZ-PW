@echo off
rem ---------------------------------------------------------------------------
rem  اجرای هوشا از هر جایی داخل مخزن.
rem
rem  چرا این فایل هست: راهنما می‌گفت «cd hoosha» و بعد «node src/cli.js». اگر کسی فقط
rem  خط دوم را کپی کند و در ریشهٔ مخزن بزند، Node می‌گوید
rem
rem      Error: Cannot find module '...\IGBZ-WP\src\cli.js'
rem      code: 'MODULE_NOT_FOUND', requireStack: []
rem
rem  که هیچ سرنخی نمی‌دهد ماجرا سرِ پوشه است. این اسکریپت خودش به پوشهٔ درست می‌رود،
rem  پس دیگر فرقی نمی‌کند از کجا صدایش بزنی.
rem
rem  کاربرد:  .\hoosha.cmd  [همان گزینه‌های همیشگی]
rem  مثال:    .\hoosha.cmd --port 7788 --no-open
rem ---------------------------------------------------------------------------

setlocal

where node >nul 2>nul
if errorlevel 1 (
	echo.
	echo   Node.js پیدا نشد. نسخهٔ ۲۰ یا بالاتر را از nodejs.org نصب کن.
	echo.
	exit /b 1
)

if not exist "%~dp0hoosha\src\cli.js" (
	echo.
	echo   پوشهٔ hoosha کنار این فایل نیست: %~dp0hoosha
	echo   این اسکریپت باید در ریشهٔ مخزن IGBZ-WP بماند.
	echo.
	exit /b 1
)

cd /d "%~dp0hoosha"

if not exist "node_modules" (
	echo   وابستگی‌ها نصب نیستند. یک بار npm ci اجرا می‌شود...
	call npm ci
	if errorlevel 1 exit /b 1
)

node src\cli.js %*
