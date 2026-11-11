<?php
// ⚠️ يجب تحميل فئة AppDatabase أولاً لأن دوال التوافق في هذا الملف تعتمد عليها
// نستخدم require_once لمنع التحميل المزدوج
if (!class_exists('AppDatabase')) {
    require_once __DIR__ . '/../includes/Database.php';
}

// إعدادات قاعدة البيانات - قراءة من ملف .env أو استخدام القيم الافتراضية

// === دالة تحميل ملف .env ===
function loadEnv($path) {
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
    return true;
}

// تحميل متغيرات البيئة من ملف .env
$env_loaded = loadEnv(__DIR__ . '/../.env');

// إذا لم يتم تحميل .env، نحاول إنشاؤه من القيم المحددة أدناه (للتوافق مع الإصدارات القديمة)
if (!$env_loaded) {
    // القيم الأصلية - قم بتعديلها حسب إعداداتك
    $_env_defaults = [
        'DB_HOST' => 'localhost',
        'DB_NAME' => 'inventory_db',
        'DB_USER' => 'root',
        'DB_PASS' => '',
    ];
    foreach ($_env_defaults as $key => $value) {
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// تعريف الثوابت من متغيرات البيئة
// دعم فك تشفير القيم المشفرة (تبدأ بـ ENC:) تلقائياً
if (!class_exists('EnvEncryptor')) {
    require_once __DIR__ . '/../includes/EnvEncryptor.php';
}

$rawDbHost = getenv('DB_HOST') ?: 'localhost';
$rawDbName = getenv('DB_NAME') ?: 'inventory_db';
$rawDbUser = getenv('DB_USER') ?: 'root';
$rawDbPass = getenv('DB_PASS') ?: '';

// فك تشفير القيم إذا كانت مشفرة
define('DB_HOST', EnvEncryptor::decryptOrRaw($rawDbHost));
define('DB_NAME', EnvEncryptor::decryptOrRaw($rawDbName));
define('DB_USER', EnvEncryptor::decryptOrRaw($rawDbUser));
define('DB_PASS', EnvEncryptor::decryptOrRaw($rawDbPass));
define('DB_CHARSET', 'utf8mb4');

// === دوال متوافقة مع الإصدارات القديمة ===
// تُفوَّض إلى فئة App\Database (النمط الكائني الجديد)
// يمكن إزالتها تدريجياً بعد تحديث كل الملفات لاستخدام الكائن مباشرة

// ⚠️ لا نستخدم use App\Database لأنها تسبب مشاكل على Windows (NTFS)
// نستخدم AppDatabase مباشرة من المساحة العامة

/**
 * إنشاء الاتصال بقاعدة البيانات
 * @deprecated استخدم AppDatabase::getInstance()->getConnection() بدلاً من ذلك
 */
function getDBConnection() {
    return AppDatabase::getInstance()->getConnection();
}

/**
 * تنفيذ استعلام مع معاملات
 * @deprecated استخدم AppDatabase::getInstance()->query() بدلاً من ذلك
 */
function executeQuery($sql, $params = []) {
    return AppDatabase::getInstance()->query($sql, $params);
}

/**
 * جلب صف واحد من نتائج الاستعلام
 * @deprecated استخدم AppDatabase::getInstance()->fetchOne() بدلاً من ذلك
 */
function fetchOne($sql, $params = []) {
    return AppDatabase::getInstance()->fetchOne($sql, $params);
}

/**
 * جلب كل الصفوف من نتائج الاستعلام
 * @deprecated استخدم AppDatabase::getInstance()->fetchAll() بدلاً من ذلك
 */
function fetchAll($sql, $params = []) {
    return AppDatabase::getInstance()->fetchAll($sql, $params);
}

/**
 * إدراج بيانات وإرجاع آخر معرف
 * @deprecated استخدم AppDatabase::getInstance()->insert() بدلاً من ذلك
 */
function insertData($sql, $params = []) {
    return AppDatabase::getInstance()->insert($sql, $params);
}

/**
 * جلب قيمة إعداد من جدول system_settings
 * @deprecated استخدم AppDatabase::getInstance()->getSetting() بدلاً من ذلك
 */
function getSetting($key, $default = '') {
    return AppDatabase::getInstance()->getSetting($key, $default);
}

/**
 * حفظ قيمة إعداد في جدول system_settings
 * @deprecated استخدم AppDatabase::getInstance()->saveSetting() بدلاً من ذلك
 */
function saveSetting($key, $value, $description = null) {
    return AppDatabase::getInstance()->saveSetting($key, $value, $description);
}

/**
 * جلب رمز العملة من إعدادات النظام
 * @deprecated استخدم AppDatabase::getInstance()->getCurrencySymbol() بدلاً من ذلك
 */
function getCurrencySymbol() {
    return AppDatabase::getInstance()->getCurrencySymbol();
}

/**
 * تنسيق المبلغ المالي مع رمز العملة
 * @deprecated استخدم AppDatabase::getInstance()->formatMoney() بدلاً من ذلك
 */
function formatMoney($amount, $decimals = 2) {
    return AppDatabase::getInstance()->formatMoney($amount, $decimals);
}

/**
 * تنسيق التاريخ حسب إعداد date_format في النظام
 * يدعم: Y-m-d (2025-01-15), d/m/Y (15/01/2025), m/d/Y (01/15/2025)
 * @param string $dateStr تاريخ بصيغة Y-m-d أو أي صيغة يفهمها strtotime
 * @param string|null $format صيغة اختيارية لتجاوز إعداد النظام
 * @return string التاريخ منسقاً
 */
function formatDate($dateStr, $format = null) {
    if (empty($dateStr) || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return '—';
    }
    if ($format === null) {
        $format = getSetting('date_format', 'Y-m-d');
    }
    $timestamp = is_numeric($dateStr) ? $dateStr : strtotime($dateStr);
    if ($timestamp === false) {
        return htmlspecialchars($dateStr);
    }
    return date($format, $timestamp);
}

/**
 * تنسيق التاريخ والوقت حسب إعداد date_format مع إضافة الوقت
 * @param string $dateStr تاريخ ووقت بصيغة Y-m-d H:i:s أو أي صيغة يفهمها strtotime
 * @param string|null $format صيغة اختيارية لتجاوز إعداد النظام (بدون جزء الوقت)
 * @return string التاريخ والوقت منسقاً
 */
function formatDateTime($dateStr, $format = null) {
    if (empty($dateStr) || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return '—';
    }
    if ($format === null) {
        $format = getSetting('date_format', 'Y-m-d');
    }
    $timestamp = is_numeric($dateStr) ? $dateStr : strtotime($dateStr);
    if ($timestamp === false) {
        return htmlspecialchars($dateStr);
    }
    return date($format . ' H:i', $timestamp);
}

/**
 * جلب إعداد POS مع قيمة افتراضية
 * @param string $key مفتاح الإعداد (pos_theme, pos_sound_enabled, pos_auto_complete)
 * @param string $default القيمة الافتراضية
 * @return string قيمة الإعداد
 */
function getPosSetting($key, $default = '') {
    return getSetting($key, $default);
}

// === تحميل نظام الترجمة متعدد اللغات (i18n) ===
// i18n.php يُحمَّل مسبقاً من bootstrap.php أو i18n-early.php
// نستدعي initI18n(true) لضمان التهيئة الصحيحة مع لغة قاعدة البيانات
if (!function_exists('t')) {
    require_once __DIR__ . '/../includes/i18n.php';
}
if (function_exists('initI18n')) {
    initI18n(true);
}
?>
