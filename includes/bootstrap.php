<?php
/**
 * ===== نقطة الدخول الموحدة (Bootstrap) =====
 * يُحمَّل في بداية كل صفحة لتهيئة الأنظمة الأساسية
 *
 * ترتيب التحميل:
 *   1. إعدادات عرض الأخطاء (APP_DEBUG)
 *   2. فحص وضع الصيانة (.maintenance)
 *   3. بدء الجلسة مع إعدادات أمنية
 *   4. قاعدة البيانات + .env
 *   5. معالجة الأخطاء
 *   6. رؤوس الأمان الديناميكية (CSP + HSTS)
 *   7. تشفير البيانات الحساسة
 *   8. التخزين المؤقت (Redis مع تراجع للملفات)
 *   9. أنظمة المصادقة والصلاحيات
 *  10. خدمات التطبيق (Services)
 *
 * الاستخدام: require_once __DIR__ . '/includes/bootstrap.php';
 */

// ===== إعدادات عرض الأخطاء (أمنية للإنتاج) =====
// في الإنتاج: إخفاء الأخطاء عن المستخدمين بالكامل
// في التطوير (APP_DEBUG=true): عرض الأخطاء للتشخيص
if (!defined('APP_DEBUG')) {
    // قراءة من متغير البيئة أولاً
    $envDebug = getenv('APP_DEBUG');
    if ($envDebug === 'true' || $envDebug === '1' || $envDebug === 'yes') {
        define('APP_DEBUG', true);
    } else {
        // القيمة الافتراضية: وضع الإنتاج (آمن)
        define('APP_DEBUG', false);
    }
}
if (APP_DEBUG) {
    // وضع التطوير: عرض كل الأخطاء
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    // وضع الإنتاج: إخفاء الأخطاء عن المستخدمين
    error_reporting(E_ALL); // نسجل كل شيء
    ini_set('display_errors', '0'); // لكن لا نعرضه
    ini_set('display_startup_errors', '0');
}

// ===== فحص وضع الصيانة (قبل أي شيء آخر) =====
// إذا وُجد ملف .maintenance في المجلد الجذري، يتم حجب جميع الطلبات
// باستثناء صفحة التحديث (upgrade.php) التي تدير عملية النشر الذري
$maintenanceFile = __DIR__ . '/../.maintenance';
if (file_exists($maintenanceFile) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'upgrade.php') {
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>صيانة</title></head>'
       . '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;background:#f1f5f9;">'
       . '<div style="text-align:center;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);">'
       . '<h1 style="font-size:2rem;margin:0 0 .5rem;">🔧 صيانة مؤقتة</h1>'
       . '<p style="color:#64748b;font-size:1.1rem;">جاري تحديث النظام — يرجى الانتظار لحظات...</p>'
       . '</div></body></html>';
    exit;
}

// ===== تحميل نظام الترجمة متعدد اللغات (مبكراً — قبل أي شيء آخر) =====
// يجب تحميله أولاً لضمان توفر دالة t() في كل مكان
// يتم إعادة التهيئة لاحقاً بعد تحميل قاعدة البيانات (initI18n(true))
require_once __DIR__ . '/i18n.php';

// بدء الجلسة (مع إعدادات أمنية موحدة من config/session.php)
require_once __DIR__ . '/../config/session.php';
startSecureSession();

// تحميل فئة قاعدة البيانات (قبل ملف الإعدادات حتى تتوفر الدوال التوافقية)
require_once __DIR__ . '/Database.php';

// تحميل إعدادات قاعدة البيانات (المجلد الأب لأننا داخل includes/)
require_once __DIR__ . '/../config/database.php';

// تحميل نظام معالجة الأخطاء (نفس المجلد)
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/AppException.php';

// تهيئة معالج الأخطاء
ErrorHandler::init();

// تحميل نظام رؤوس الأمان الديناميكي (CSP nonce + HSTS)
require_once __DIR__ . '/SecurityHeaders.php';

// تحميل نظام تشفير البيانات الحساسة في .env
require_once __DIR__ . '/EnvEncryptor.php';

// تهيئة رؤوس الأمان الديناميكية (CSP مع nonce + HSTS)
// يجب أن يكون بعد session_start وقبل أي إخراج HTML
// وضع CSP: يُحدد من متغير CSP_ENFORCE في .env
//   false = تقرير فقط (آمن للانتقال التدريجي)
//   true  = تنفيذ قسري (بعد إضافة nonce لكل السكربتات)
SecurityHeaders::init();

// تحميل كاش بنية الجدول (SchemaCache) — يُستخدم فقط لكشف الأعمدة والجداول
// ⚠️ لا يُستخدم أبداً لبيانات الأعمال (مبيعات، أرباح، إشعارات، صلاحية)
require_once __DIR__ . '/SchemaCache.php';

// تحميل دوال الاستجابة
require_once __DIR__ . '/ApiResponse.php';

// تحميل نظام الترقيم
require_once __DIR__ . '/Pagination.php';

// تحميل نظام القوالب
require_once __DIR__ . '/Template.php';

// تحميل نظام المصادقة (OOP class + wrappers merged في ملف واحد لتجنب تعارض الأسماء على Windows)
// ملاحظة: Auth.php و Csrf.php تم دمجهما في auth.php و csrf.php بسبب تعارض الأحرف على NTFS
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

// تحميل نظام الصلاحيات (مطلوب لجميع الصفحات ونقاط API)
require_once __DIR__ . '/permissions.php';

// تحميل سجل النشاطات
require_once __DIR__ . '/audit_log.php';

// تحميل نظام الإشعارات
require_once __DIR__ . '/notifications.php';

// نظام الترجمة محمّل مسبقاً في بداية bootstrap — إعادة تهيئة مع لغة قاعدة البيانات
if (function_exists('reinitI18n')) {
    reinitI18n();
} elseif (function_exists('initI18n')) {
    initI18n(true);
}

// تحميل بيانات لوحة التحكم (DashboardData)
require_once __DIR__ . '/DashboardData.php';

// تحميل مساعد الصندوق (CaisseHelper)
require_once __DIR__ . '/CaisseHelper.php';

// ===== تحميل خدمات التطبيق (Service Layer) =====
// طبقة الخدمات المركزية — تستبدل الكود المتكرر في ملفات الصفحات تدريجياً
if (file_exists(__DIR__ . '/Services/ServiceFactory.php')) {
    require_once __DIR__ . '/Services/ServiceFactory.php';
}
if (file_exists(__DIR__ . '/Services/ProductService.php')) {
    require_once __DIR__ . '/Services/ProductService.php';
}
if (file_exists(__DIR__ . '/Services/SaleService.php')) {
    require_once __DIR__ . '/Services/SaleService.php';
}
if (file_exists(__DIR__ . '/Services/PurchaseService.php')) {
    require_once __DIR__ . '/Services/PurchaseService.php';
}
if (file_exists(__DIR__ . '/Services/SearchService.php')) {
    require_once __DIR__ . '/Services/SearchService.php';
}
if (file_exists(__DIR__ . '/Services/UserService.php')) {
    require_once __DIR__ . '/Services/UserService.php';
}

/**
 * متغيرات عامة للتطبيق
 */
define('CURRENCY_SYMBOL', getCurrencySymbol());

// ===== فحص أمني: كشف بيانات الاعتماد الضعيفة غير المشفرة =====
// يتم تشغيله تلقائياً في كل طلب لتنبيه المدير بوجود كلمات مرور ضعيفة
// النتائج تُعرض كتحذير في واجهة الإدارة فقط (لا تُظهر للمستخدمين العاديين)
if (class_exists('EnvEncryptor')) {
    $envAuditPath = __DIR__ . '/../.env';
    $envWarnings = EnvEncryptor::auditEnvFile($envAuditPath);

    // تخزين التحذيرات في الجلسة لعرضها في واجهة الإدارة
    if (!empty($envWarnings)) {
        if (session_status() === PHP_SESSION_ACTIVE && !isset($_SESSION['env_security_warnings'])) {
            $_SESSION['env_security_warnings'] = $envWarnings;
        }

        // تسجيل التحذيرات الحرجة في سجل الأخطاء
        foreach ($envWarnings as $warning) {
            if (($warning['severity'] ?? '') === 'high') {
                error_log(sprintf(
                    '[ISM Security] بيانات اعتماد ضعيفة: %s — %s',
                    $warning['key'],
                    implode('; ', $warning['warnings'])
                ));
            }
        }
    } else {
        // لا توجد تحذيرات — إزالة التحذيرات القديمة من الجلسة
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['env_security_warnings'])) {
            unset($_SESSION['env_security_warnings']);
        }
    }
}

// ===== إصدار API =====
// جميع نقاط نهاية API تتبع هذا الإصدار
// سيتم دعم التوافق مع الإصدارات السابقة عند أي تغيير جوهري
define('API_VERSION', 'v1');
define('MOBILE_API_VERSION', 'v1');
