<?php
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
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'inventory_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// إنشاء الاتصال بقاعدة البيانات
function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (Throwable $e) {
        // إرجاع null بدلاً من die() للسماح بالتعامل المرن مع الأخطاء
        return null;
    }
}

// دالة مساعدة لتنفيذ الاستعلامات
function executeQuery($sql, $params = []) {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new RuntimeException("خطأ في الاتصال بقاعدة البيانات. تأكد من أن قاعدة البيانات موجودة ومُهيأة بشكل صحيح.");
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// دالة لجلب صف واحد
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch();
}

// دالة لجلب كل الصفوف
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll();
}

// دالة لإدراج بيانات وإرجاع آخر معرف
function insertData($sql, $params = []) {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new RuntimeException("خطأ في الاتصال بقاعدة البيانات. تأكد من أن قاعدة البيانات موجودة ومُهيأة بشكل صحيح.");
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $pdo->lastInsertId();
}

// === دوال الإعدادات ===

/**
 * جلب قيمة إعداد من جدول system_settings
 */
function getSetting($key, $default = '') {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $row = fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
        $cache[$key] = $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * حفظ قيمة إعداد في جدول system_settings
 */
function saveSetting($key, $value, $description = null) {
    if ($description) {
        executeQuery(
            "INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_at = CURRENT_TIMESTAMP",
            [$key, $value, $description]
        );
    } else {
        executeQuery(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    }
}

// === دوال العملة ===

/**
 * جلب رمز العملة من إعدادات النظام
 * الافتراضي: د.م. (درهم مغربي)
 */
function getCurrencySymbol() {
    static $symbol = null;
    if ($symbol !== null) return $symbol;
    try {
        $row = fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'currency_symbol'");
        $symbol = $row ? $row['setting_value'] : 'د.م.';
    } catch (Exception $e) {
        $symbol = 'د.م.';
    }
    return $symbol;
}

/**
 * تنسيق المبلغ المالي مع رمز العملة
 * @param float $amount المبلغ
 * @param int $decimals عدد الأرقام العشرية (افتراضي 2)
 * @return string المبلغ المنسق مع رمز العملة
 */
function formatMoney($amount, $decimals = 2) {
    return number_format($amount, $decimals) . ' ' . getCurrencySymbol();
}
?>
