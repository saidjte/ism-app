<?php
/**
 * ===== اختبار توحيد إعدادات الجلسة =====
 *
 * يتحقق من أن إعدادات الجلسة مركزية وموحدة في جميع أنحاء التطبيق:
 * 1. الدالة getSessionTimeout() تعيد نفس القيمة في auth.php و AuthMiddleware
 * 2. الدالة startSecureSession() تطبق نفس إعدادات الكوكيز في جميع المواقع
 * 3. لا توجد قيم مشفرة (hardcoded) لمهلة الجلسة خارج config/session.php
 * 4. الثوابت معرّفة بشكل صحيح
 *
 * @since v4.16.9
 * @updated v6.3.4 — ترحيل إلى PHPUnit (المرحلة 2 — أتمتة الجودة)
 */

declare(strict_types=1);

class SessionConfigTest extends TestCase
{
    // ========================================================================
    //  اختبارات الثوابت والدوال المركزية
    // ========================================================================

    public function testSessionConstantsDefined(): void
    {
        // تحميل ملف التكوين المركزي
        require_once __DIR__ . '/../config/session.php';

        $this->assertSame(1800, SESSION_DEFAULT_TIMEOUT, 'الثابت SESSION_DEFAULT_TIMEOUT يجب أن يساوي 1800');
        $this->assertSame(60, SESSION_MIN_TIMEOUT, 'الثابت SESSION_MIN_TIMEOUT يجب أن يساوي 60');
        $this->assertSame(
            'session_timeout_minutes',
            SESSION_TIMEOUT_SETTING_KEY,
            'الثابت SESSION_TIMEOUT_SETTING_KEY يجب أن يساوي session_timeout_minutes'
        );
    }

    public function testCentralFunctionsExist(): void
    {
        foreach (['configureSessionCookies', 'getSessionTimeout', 'startSecureSession'] as $func) {
            $this->assertTrue(
                function_exists($func),
                "الدالة المركزية {$func} غير موجودة"
            );
        }
    }

    public function testGetSessionTimeoutDefault(): void
    {
        $timeout = getSessionTimeout();

        $this->assertSame(
            SESSION_DEFAULT_TIMEOUT,
            $timeout,
            "getSessionTimeout() تعيد {$timeout} — المتوقع القيمة الافتراضية " . SESSION_DEFAULT_TIMEOUT
        );
    }

    public function testGetSessionTimeoutMinValue(): void
    {
        $timeout = getSessionTimeout();

        $this->assertGreaterThanOrEqual(
            SESSION_MIN_TIMEOUT,
            $timeout,
            "مهلة الجلسة ({$timeout}) أقل من الحد الأدنى (" . SESSION_MIN_TIMEOUT . ")"
        );
    }

    public function testSessionConfigFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../config/session.php');
    }

    // ========================================================================
    //  اختبارات عدم وجود قيم مشفرة (Hardcoded)
    // ========================================================================

    public function testNoHardcodedTimeoutInAuthMiddleware(): void
    {
        $file = __DIR__ . '/../includes/Router/Middleware/AuthMiddleware.php';

        if (!file_exists($file)) {
            $this->markTestSkipped('ملف AuthMiddleware.php غير موجود');
        }

        $content = file_get_contents($file);
        $found = $this->findHardcodedTimeout($content, '/>\s*1800\b/', '/==\s*1800\b/');

        $this->assertFalse(
            $found,
            'تم العثور على قيمة مشفرة 1800 في AuthMiddleware.php — يجب استخدام getSessionTimeout()'
        );
        $this->assertStringContainsString(
            'getSessionTimeout',
            $content,
            'AuthMiddleware.php لا يستخدم الدالة المركزية getSessionTimeout()'
        );
    }

    public function testNoHardcodedTimeoutInAuth(): void
    {
        $file = __DIR__ . '/../includes/auth.php';

        if (!file_exists($file)) {
            $this->markTestSkipped('ملف auth.php غير موجود');
        }

        $content = file_get_contents($file);
        $found = $this->findHardcodedTimeout($content, '/session_timeout\s*=\s*1800\b/');

        $this->assertFalse(
            $found,
            'تم العثور على قيمة مشفرة 1800 في auth.php — يجب استخدام getSessionTimeout()'
        );
        $this->assertStringContainsString(
            'getSessionTimeout',
            $content,
            'auth.php لا يستخدم الدالة المركزية getSessionTimeout()'
        );
    }

    /**
     * جميع الملفات تستخدم startSecureSession() بدلاً من ini_set يدوي
     */
    public function testSessionCookieSettingsConsistency(): void
    {
        $filesToCheck = [
            'includes/bootstrap.php',
            'includes/csrf.php',
            'includes/auth.php',
            'includes/BaseController.php',
            'includes/header.php',
            'api/pos-process-sale.php',
            'api/send-whatsapp-alert.php',
            'api/test-ultramsg.php',
            'cron/whatsapp-daily.php',
        ];

        $inconsistent = [];
        foreach ($filesToCheck as $relPath) {
            $filePath = __DIR__ . '/../' . $relPath;
            if (!file_exists($filePath)) continue;

            $content = file_get_contents($filePath);

            // إذا كان الملف يحتوي على ini_set لـ session.cookie_httponly مباشرة
            // (بدون أن يكون ضمن دالة configureSessionCookies) فهو غير موحد
            if (preg_match("/ini_set\s*\(\s*['\"]session\.cookie_httponly['\"]/", $content)) {
                // استثناء: إذا كان الملف هو config/session.php نفسه
                if (strpos($filePath, 'config/session.php') !== false) continue;

                // استثناء: إذا كان الملف هو deployer.php (مستقل عن التطبيق)
                if (strpos($filePath, 'deployer.php') !== false) continue;

                $inconsistent[] = $relPath;
            }
        }

        $this->assertSame(
            [],
            $inconsistent,
            'الملفات التالية لا تزال تستخدم ini_set يدوي لإعدادات الكوكيز: ' . implode(', ', $inconsistent)
        );
    }

    // ========================================================================
    //  اختبارات السلوك
    // ========================================================================

    public function testStartSecureSessionIdempotent(): void
    {
        $this->assertDoesNotThrow(function () {
            // استدعاء الدالة مرتين — يجب ألا يسبب خطأ
            startSecureSession();
            startSecureSession();
        }, 'startSecureSession() تسبب خطأ عند الاستدعاء المتكرر');
    }

    // ========================================================================
    //  أدوات مساعدة
    // ========================================================================

    /**
     * البحث عن قيمة مهلة مشفرة 1800 في نص ملف (مع تجاهل التعليقات)
     *
     * @param string ...$patterns أنماط البحث عن القيمة المشفرة
     */
    private function findHardcodedTimeout(string $content, string ...$patterns): bool
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // تجاهل التعليقات
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    return true;
                }
            }
        }
        return false;
    }
}
