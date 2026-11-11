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
 */

require_once __DIR__ . '/TestCase.php';

class SessionConfigTest extends TestCase
{
    protected string $testName = 'توحيد إعدادات الجلسة';

    /**
     * تشغيل جميع اختبارات إعدادات الجلسة
     */
    public function run(): array
    {
        $results = [];

        $results[] = $this->testSessionConstantsDefined();
        $results[] = $this->testCentralFunctionsExist();
        $results[] = $this->testGetSessionTimeoutDefault();
        $results[] = $this->testGetSessionTimeoutMinValue();
        $results[] = $this->testSessionConfigFileExists();
        $results[] = $this->testNoHardcodedTimeoutInAuthMiddleware();
        $results[] = $this->testNoHardcodedTimeoutInAuth();
        $results[] = $this->testSessionCookieSettingsConsistency();
        $results[] = $this->testStartSecureSessionIdempotent();

        return $results;
    }

    /**
     * اختبار: ثوابت الجلسة معرّفة بشكل صحيح
     */
    private function testSessionConstantsDefined(): array
    {
        // تحميل ملف التكوين المركزي
        require_once __DIR__ . '/../config/session.php';

        $constants = [
            'SESSION_DEFAULT_TIMEOUT' => 1800,
            'SESSION_MIN_TIMEOUT' => 60,
            'SESSION_TIMEOUT_SETTING_KEY' => 'session_timeout_minutes',
        ];

        foreach ($constants as $name => $expectedValue) {
            if (!defined($name)) {
                return $this->fail("الثابت {$name} غير معرّف");
            }
            if (constant($name) !== $expectedValue) {
                return $this->fail("الثابت {$name} = " . constant($name) . " — المتوقع: {$expectedValue}");
            }
        }

        return $this->pass('جميع ثوابت الجلسة معرّفة بشكل صحيح');
    }

    /**
     * اختبار: الدوال المركزية موجودة
     */
    private function testCentralFunctionsExist(): array
    {
        $functions = ['configureSessionCookies', 'getSessionTimeout', 'startSecureSession'];

        foreach ($functions as $func) {
            if (!function_exists($func)) {
                return $this->fail("الدالة المركزية {$func} غير موجودة");
            }
        }

        return $this->pass('جميع الدوال المركزية للجلسة موجودة');
    }

    /**
     * اختبار: getSessionTimeout تعيد القيمة الافتراضية الصحيحة
     */
    private function testGetSessionTimeoutDefault(): array
    {
        $timeout = getSessionTimeout();

        if ($timeout !== SESSION_DEFAULT_TIMEOUT) {
            return $this->fail("getSessionTimeout() = {$timeout} — المتوقع: " . SESSION_DEFAULT_TIMEOUT);
        }

        return $this->pass("getSessionTimeout() تعيد القيمة الافتراضية الصحيحة ({$timeout} ثانية)");
    }

    /**
     * اختبار: getSessionTimeout لا تعيد قيمة أقل من الحد الأدنى
     */
    private function testGetSessionTimeoutMinValue(): array
    {
        $timeout = getSessionTimeout();

        if ($timeout < SESSION_MIN_TIMEOUT) {
            return $this->fail("مهلة الجلسة ({$timeout}) أقل من الحد الأدنى (" . SESSION_MIN_TIMEOUT . ")");
        }

        return $this->pass("مهلة الجلسة ({$timeout} ثانية) لا تقل عن الحد الأدنى (" . SESSION_MIN_TIMEOUT . ")");
    }

    /**
     * اختبار: ملف التكوين المركزي موجود
     */
    private function testSessionConfigFileExists(): array
    {
        $configFile = __DIR__ . '/../config/session.php';

        if (!file_exists($configFile)) {
            return $this->fail('ملف config/session.php غير موجود');
        }

        return $this->pass('ملف config/session.php موجود');
    }

    /**
     * اختبار: لا توجد قيمة مشفرة 1800 في AuthMiddleware.php
     */
    private function testNoHardcodedTimeoutInAuthMiddleware(): array
    {
        $file = __DIR__ . '/../includes/Router/Middleware/AuthMiddleware.php';

        if (!file_exists($file)) {
            return $this->skip('ملف AuthMiddleware.php غير موجود');
        }

        $content = file_get_contents($file);

        // البحث عن القيمة المشفرة 1800 (ليست ضمن تعليق أو ثابت)
        // نستبعد الأسطر التي تحتوي على تعليقات أو تعريفات ثوابت
        $lines = explode("\n", $content);
        $found = false;
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            // تجاهل التعليقات
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            // البحث عن 1800 كقيمة مهلة مشفرة
            if (preg_match('/>\s*1800\b/', $line) || preg_match('/==\s*1800\b/', $line)) {
                $found = true;
                break;
            }
        }

        if ($found) {
            return $this->fail('تم العثور على قيمة مشفرة 1800 في AuthMiddleware.php — يجب استخدام getSessionTimeout()');
        }

        // التحقق من استخدام الدالة المركزية
        if (strpos($content, 'getSessionTimeout') === false) {
            return $this->fail('AuthMiddleware.php لا يستخدم الدالة المركزية getSessionTimeout()');
        }

        return $this->pass('AuthMiddleware.php يستخدم getSessionTimeout() بدلاً من القيمة المشفرة');
    }

    /**
     * اختبار: لا توجد قيمة مشفرة 1800 كمهلة في auth.php
     */
    private function testNoHardcodedTimeoutInAuth(): array
    {
        $file = __DIR__ . '/../includes/auth.php';

        if (!file_exists($file)) {
            return $this->skip('ملف auth.php غير موجود');
        }

        $content = file_get_contents($file);

        // البحث عن القيمة المشفرة 1800 (ليست ضمن تعليق)
        $lines = explode("\n", $content);
        $found = false;
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            // البحث عن تعيين 1800 كقيمة مهلة
            if (preg_match('/session_timeout\s*=\s*1800\b/', $line)) {
                $found = true;
                break;
            }
        }

        if ($found) {
            return $this->fail('تم العثور على قيمة مشفرة 1800 في auth.php — يجب استخدام getSessionTimeout()');
        }

        // التحقق من استخدام الدالة المركزية
        if (strpos($content, 'getSessionTimeout') === false) {
            return $this->fail('auth.php لا يستخدم الدالة المركزية getSessionTimeout()');
        }

        return $this->pass('auth.php يستخدم getSessionTimeout() بدلاً من القيمة المشفرة');
    }

    /**
     * اختبار: جميع الملفات تستخدم startSecureSession() بدلاً من ini_set يدوي
     */
    private function testSessionCookieSettingsConsistency(): array
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

        if (!empty($inconsistent)) {
            return $this->fail('الملفات التالية لا تزال تستخدم ini_set يدوي لإعدادات الكوكيز: ' . implode(', ', $inconsistent));
        }

        return $this->pass('جميع الملفات تستخدم startSecureSession() المركزية');
    }

    /**
     * اختبار: startSecureSession آمنة للاستدعاء المتكرر (idempotent)
     */
    private function testStartSecureSessionIdempotent(): array
    {
        try {
            // استدعاء الدالة مرتين — يجب ألا يسبب خطأ
            startSecureSession();
            startSecureSession();

            return $this->pass('startSecureSession() آمنة للاستدعاء المتكرر (idempotent)');
        } catch (\Throwable $e) {
            return $this->fail('startSecureSession() تسبب خطأ عند الاستدعاء المتكرر: ' . $e->getMessage());
        }
    }
}
