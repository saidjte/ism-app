@echo off
chcp 65001 > nul
echo ============================================
echo   اختبارات PHPUnit — ISM POS
echo ============================================
echo.

if not exist "vendor\bin\phpunit" (
    echo ❌ PHPUnit غير مثبت — شغّل أولاً: composer install
    pause
    exit /b 1
)

php vendor\bin\phpunit -c phpunit.xml.dist %*

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
