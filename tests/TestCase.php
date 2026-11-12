<?php
/**
 * ========================================================================
 *  TestCase — طبقة توافق بين الاختبارات المنزلية القديمة و PHPUnit
 * ========================================================================
 *
 *  منذ v6.3.4 (المرحلة 2 — أتمتة الجودة): جميع الاختبارات تعمل الآن
 *  عبر PHPUnit، وتوفر هذه الطبقة أسماء التأكيدات القديمة كأسماء بديلة
 *  حتى تبقى ملفات الاختبار الحالية تعمل دون تعديل.
 *
 *  الاستخدام:
 *    vendor/bin/phpunit            (الطريقة الرسمية)
 *    php tests/run.php             (غلاف للاختصار)
 * ========================================================================
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    // ====================================================================
    //  أسماء بديلة (Aliases) لتأكيدات الإطار القديم
    // ====================================================================

    /** تطابق صارم (===) — نفس دلالات assertEqual القديمة */
    public static function assertEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame($expected, $actual, $message);
    }

    /** عدم التطابق الصارم (!==) — نفس دلالات assertNotEqual القديمة */
    public static function assertNotEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertNotSame($expected, $actual, $message);
    }

    /**
     * ملاحظة: assertContains الأصلية في PHPUnit نهائية (final) ولا يمكن
     * تجاوزها — هي تستخدم مقارنة صارمة (===) بينما كان الإطار القديم
     * يستخدم مقارنة مرنة. جميع مواضع الاستدعاء في الاختبارات الحالية
     * تقارن نصوصاً بنصوص، لذا الدلالتان متكافئتان عملياً. للاحتياج
     * للمقارنة المرنة استخدم assertContainsEquals مباشرة.
     */

    /** اسم قديم → assertStringContainsString */
    public static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        self::assertStringContainsString($needle, $haystack, $message);
    }

    /** اسم قديم → assertMatchesRegularExpression */
    public static function assertMatchesRegex(string $pattern, string $string, string $message = ''): void
    {
        self::assertMatchesRegularExpression($pattern, $string, $message);
    }

    /** مقارنة أرقام عشرية مع هامش تسامح (افتراضياً 0.01 كما في الإطار القديم) */
    public static function assertFloatEqual(float $expected, float $actual, float $epsilon = 0.01, string $message = ''): void
    {
        self::assertEqualsWithDelta($expected, $actual, $epsilon, $message);
    }

    // ====================================================================
    //  تأكيدات مخصصة لا يوفرها PHPUnit بنفس الشكل
    // ====================================================================

    /**
     * التأكد من أن الكود القابل للاستدعاء يرمي استثناءً من نوع محدد
     */
    public static function assertThrows(string $exceptionClass, callable $callback, string $message = ''): void
    {
        $caught = false;
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                $caught = true;
            } else {
                self::fail(
                    ($message ? "{$message}: " : '') . "Expected {$exceptionClass}, but caught " . get_class($e)
                );
            }
        }
        if (!$caught) {
            self::fail(
                ($message ? "{$message}: " : '') . "Expected {$exceptionClass} to be thrown, but nothing was thrown"
            );
        }

        // تسجيل تأكيد فعلي لدى PHPUnit (وإلا يُحسب الاختبار Risky بلا تأكيدات)
        // assertTrue متاحة كدالة ساكنة وتزيد عدّاد التأكيدات تلقائياً
        self::assertTrue(true, $message);
    }

    /**
     * التأكد من أن الكود القابل للاستدعاء لا يرمي أي استثناء
     */
    public static function assertDoesNotThrow(callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            self::fail(
                ($message ? "{$message}: " : '') . "Expected no exception, but " . get_class($e)
                . " was thrown: {$e->getMessage()}"
            );
        }

        // تسجيل تأكيد فعلي لدى PHPUnit (وإلا يُحسب الاختبار Risky بلا تأكيدات)
        // assertTrue متاحة كدالة ساكنة وتزيد عدّاد التأكيدات تلقائياً
        self::assertTrue(true, $message);
    }

    // ====================================================================
    //  أدوات مساعدة (Helpers) من الإطار القديم
    // ====================================================================

    /** إنشاء مجلد مؤقت للاختبارات */
    protected function createTempDir(string $prefix = 'test_'): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    /** حذف مجلد مؤقت بالكامل */
    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
}
