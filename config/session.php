<?php
/**
 * ========================================================================
 *  إعدادات الجلسة المركزية — Centralized Session Configuration
 * ========================================================================
 *
 *  نقطة التكوين الوحيدة لجميع إعدادات الجلسة في التطبيق.
 *  أي تعديل هنا يُطبَّق تلقائياً في جميع المواقع.
 *
 *  الملفات التي تستخدم هذا التكوين:
 *  - includes/bootstrap.php        (المصدر الرئيسي — يحمّل هذا الملف)
 *  - includes/auth.php             (مهلة الجلسة)
 *  - includes/csrf.php             (كوكيز الجلسة)
 *  - includes/BaseController.php   (كوكيز الجلسة)
 *  - includes/Router/Middleware/AuthMiddleware.php  (مهلة الجلسة)
 *  - includes/header.php           (كوكيز الجلسة)
 *  - api/pos-process-sale.php      (كوكيز الجلسة)
 *  - api/send-whatsapp-alert.php   (كوكيز الجلسة)
 *  - api/test-ultramsg.php         (كوكيز الجلسة)
 *  - cron/whatsapp-daily.php       (كوكيز الجلسة)
 *  - deployer.php                  (كوكيز الجلسة — نسخة مستقلة)
 *
 *  منذ v4.16.9
 *  ========================================================================
 */

// ===== ثوابت إعدادات الجلسة =====

/** @var int مهلة الجلسة الافتراضية بالثواني (30 دقيقة) */
defined('SESSION_DEFAULT_TIMEOUT') || define('SESSION_DEFAULT_TIMEOUT', 1800);

/** @var int الحد الأدنى لمهلة الجلسة بالثواني (1 دقيقة) */
defined('SESSION_MIN_TIMEOUT') || define('SESSION_MIN_TIMEOUT', 60);

/** @var string مفتاح إعداد المهلة في قاعدة البيانات */
defined('SESSION_TIMEOUT_SETTING_KEY') || define('SESSION_TIMEOUT_SETTING_KEY', 'session_timeout_minutes');

// ===== دالة مركزية لتهيئة كوكيز الجلسة =====

/**
 * تهيئة إعدادات أمنية لكوكيز الجلسة قبل session_start()
 *
 * يجب استدعاؤها قبل session_start() مباشرة.
 * تُطبق الإعدادات التالية:
 * - HttpOnly: يمنع JavaScript من الوصول لكوكيز الجلسة (حماية XSS)
 * - SameSite=Lax: حماية CSRF مع توافق الهواتف
 * - Strict Mode: يرفض معرفات الجلسات غير المُهيأة (حماية Session Fixation)
 * - Secure: يمنع إرسال الكوكيز عبر HTTP المكشوف (فقط عبر HTTPS)
 *
 * @since v4.16.9
 */
if (!function_exists('configureSessionCookies')) {
    function configureSessionCookies(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return; // الجلسة بدأت بالفعل — لا يمكن تعديل الإعدادات
        }

        // HttpOnly: يمنع JavaScript من الوصول إلى كوكيز الجلسة (حماية من XSS)
        ini_set('session.cookie_httponly', '1');

        // SameSite=Lax: حماية من هجمات CSRF مع توافق الهواتف
        // (Strict أفضل أمنياً لكنه يسبب مشاكل في الروابط الخارجية على الهاتف)
        ini_set('session.cookie_samesite', 'Lax');

        // Strict Mode: يرفض معرفات الجلسات غير المُهيأة (حماية من Session Fixation)
        ini_set('session.use_strict_mode', '1');

        // Secure flag: يمنع إرسال كوكيز الجلسة عبر HTTP المكشوف (فقط عبر HTTPS)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
        if ($isHttps) {
            ini_set('session.cookie_secure', '1');
        }
    }
}

// ===== دالة مركزية للحصول على مهلة الجلسة =====

/**
 * الحصول على مهلة الجلسة بالثواني من الإعدادات
 *
 * تقرأ القيمة من قاعدة البيانات (system_settings) إذا توفرت دالة getSetting،
 * وإلا تستخدم القيمة الافتراضية.
 *
 * @return int مهلة الجلسة بالثواني (الافتراضي: 1800 = 30 دقيقة)
 * @since v4.16.9
 */
if (!function_exists('getSessionTimeout')) {
    function getSessionTimeout(): int {
        $timeoutSeconds = SESSION_DEFAULT_TIMEOUT;

        try {
            if (function_exists('getSetting')) {
                $configuredMinutes = intval(getSetting(SESSION_TIMEOUT_SETTING_KEY, strval(SESSION_DEFAULT_TIMEOUT / 60)));
                if ($configuredMinutes >= 1) {
                    $timeoutSeconds = max($configuredMinutes * 60, SESSION_MIN_TIMEOUT);
                }
            }
        } catch (\Throwable $e) {
            // في حالة خطأ قاعدة البيانات، نستخدم القيمة الافتراضية
        }

        return $timeoutSeconds;
    }
}

// ===== دالة مركزية لبدء الجلسة الآمن =====

/**
 * بدء الجلسة مع الإعدادات الأمنية
 *
 * دالة موحدة تجمع بين configureSessionCookies() و session_start()
 * مع فحص أن الجلسة لم تبدأ بعد.
 *
 * @since v4.16.9
 */
if (!function_exists('startSecureSession')) {
    function startSecureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            configureSessionCookies();
            session_start();
        }
    }
}
