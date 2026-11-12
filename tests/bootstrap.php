<?php
/**
 * ========================================================================
 *  Bootstrap لـ PHPUnit — يُحمّل قبل تشغيل أي اختبارات
 * ========================================================================
 *
 *  يحاكي التهيئة التي كان يقوم بها tests/run.php القديم:
 *  - تحميل أصناف النظام الأساسية
 *  - تعريف ثوابت قاعدة البيانات (قيم اختبارية آمنة)
 *  - دوال مساعدة عامة تفوّض العمل إلى AppDatabase::getInstance()
 *  - تهيئة نظام الترجمة (i18n)
 *
 *  ملاحظة: الاختبارات تُحقن اتصال SQLite في AppDatabase عبر Reflection،
 *  لذا لا تحتاج خادم MySQL حقيقي.
 * ========================================================================
 */

declare(strict_types=1);

// مسار المجلد الأساسي
$baseDir = dirname(__DIR__);

// === طبقة توافق الاختبارات (TestCase يرث PHPUnit\Framework\TestCase) ===
require_once __DIR__ . '/TestCase.php';

// بدء الجلسة مبكراً قبل أي إخراج (بعض الاختبارات تعتمد على $_SESSION)
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// === تحميل ملفات النظام (قبل أي إخراج) ===
require_once $baseDir . '/includes/AppException.php';
require_once $baseDir . '/includes/ErrorHandler.php';
ErrorHandler::init();
require_once $baseDir . '/includes/ApiResponse.php';
require_once $baseDir . '/includes/BaseController.php';
require_once $baseDir . '/includes/Pagination.php';
require_once $baseDir . '/includes/Cache.php';
require_once $baseDir . '/includes/i18n.php';
require_once $baseDir . '/includes/audit_log.php';
require_once $baseDir . '/includes/ApiRouter.php';
require_once $baseDir . '/includes/CaisseHelper.php';
require_once $baseDir . '/includes/Database.php';
require_once $baseDir . '/includes/EnvEncryptor.php';
require_once $baseDir . '/includes/csrf.php';

// === إعدادات الجلسة المركزية (يحتاجها SessionConfigTest) ===
require_once $baseDir . '/config/session.php';

// === ثوابت قاعدة البيانات (مطلوبة من AppDatabase) ===
if (!defined('DB_HOST'))   define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))   define('DB_NAME', 'test_db');
if (!defined('DB_USER'))   define('DB_USER', 'root');
if (!defined('DB_PASS'))   define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// === دوال مساعدة عامة (مطلوبة من الاختبارات) ===
// هذه الدوال تفوّض العمل إلى AppDatabase::getInstance()
// ويتم حقن اتصال SQLite في كل اختبار عبر Reflection

if (!function_exists('fetchOne')) {
    function fetchOne($sql, $params = []) {
        return AppDatabase::getInstance()->fetchOne($sql, $params);
    }
}
if (!function_exists('fetchAll')) {
    function fetchAll($sql, $params = []) {
        return AppDatabase::getInstance()->fetchAll($sql, $params);
    }
}
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        return AppDatabase::getInstance()->getConnection();
    }
}
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') {
        return AppDatabase::getInstance()->getSetting($key, $default);
    }
}
if (!function_exists('saveSetting')) {
    function saveSetting($key, $value, $description = null) {
        return AppDatabase::getInstance()->saveSetting($key, $value, $description);
    }
}
if (!function_exists('executeQuery')) {
    function executeQuery($sql, $params = []) {
        return AppDatabase::getInstance()->query($sql, $params);
    }
}
if (!function_exists('insertData')) {
    function insertData($sql, $params = []) {
        return AppDatabase::getInstance()->insert($sql, $params);
    }
}
if (!function_exists('formatMoney')) {
    function formatMoney(?float $amount, int $decimals = 2): string {
        return number_format($amount ?? 0, $decimals) . ' د.م.';
    }
}
if (!function_exists('getCurrencySymbol')) {
    function getCurrencySymbol(): string {
        return 'د.م.';
    }
}

// === Polyfill لـ mbstring إذا لم تكن الإضافة مثبتة ===
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return preg_match_all('/./us', $string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        $total = count($chars);
        if ($start < 0) $start = max(0, $total + $start);
        if ($length === null) {
            return implode('', array_slice($chars, $start));
        }
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string {
        return strtolower($string);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string {
        return strtoupper($string);
    }
}
if (!function_exists('mb_substr_count')) {
    function mb_substr_count(string $haystack, string $needle, ?string $encoding = null): int {
        return substr_count($haystack, $needle);
    }
}

// === تهيئة نظام الترجمة (i18n) للاختبارات ===
// فرض تحميل الترجمات العربية (اللغة الافتراضية)
// هذا ضروري لأن بعض الأصناف (Pagination, CaisseHelper, audit_log) تستخدم t()
if (empty($GLOBALS['i18n_initialized'])) {
    $GLOBALS['i18n_lang'] = 'ar';
    $GLOBALS['i18n_initialized'] = true;
    $langFile = $baseDir . '/includes/languages/ar.php';
    if (file_exists($langFile)) {
        $GLOBALS['i18n_translations'] = require $langFile;
    }
}

// === تحميل خدمات التطبيق للاختبار ===
require_once $baseDir . '/includes/Services/ProductService.php';
require_once $baseDir . '/includes/Services/SaleService.php';
require_once $baseDir . '/includes/Services/PurchaseService.php';
if (file_exists($baseDir . '/includes/Services/SearchService.php')) {
    require_once $baseDir . '/includes/Services/SearchService.php';
}
