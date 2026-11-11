@echo off
chcp 65001 > nul
echo ============================================
echo   نظام اختبارات الحماية من الانحدار
echo   is-app - نظام إدارة المخزون
echo ============================================
echo.

php tests\run.php %*

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ❌ هناك اختبارات فاشلة!
    pause
    exit /b 1
) else (
    echo.
    echo ✅ كل الاختبارات ناجحة!
    pause
    exit /b 0
)
