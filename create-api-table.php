<?php
/**
 * ===== صفحة إعداد API الهاتف — النسخة 4 =====
 * v4.36.1: كشف الأعمدة المفقودة وإصلاح الجدول الموجود
 *
 * التغييرات عن النسخة السابقة:
 *   - كشف الأعمدة المفقودة في جدول api_tokens وإضافتها
 *   - دعم DROP + CREATE لإعادة بناء الجدول التالف
 *   - تشخيص كامل لهيكل الجدول الحالي
 *   - مؤشر إصدار مرئي للتأكد من رفع الملف
 */

// ===== مؤشر الإصدار — إذا لم ترَ هذا في الصفحة، فالملف لم يُرفع =====
$SETUP_API_VERSION = '4.1';
$SETUP_API_BUILD = '2025-05-20-D';

ob_start();
set_error_handler(function($severity, $message, $file, $line) {
    error_log("create-api-table.php [{$severity}]: {$message} in {$file}:{$line}");
    return false;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8"><title>خطأ</title>';
        echo '<style>body{font-family:system-ui,sans-serif;direction:rtl;padding:40px;background:#fef2f2;color:#991b1b;}';
        echo '.error-box{max-width:600px;margin:0 auto;background:#fff;border:2px solid #fca5a5;border-radius:12px;padding:24px;}';
        echo 'h1{font-size:1.2rem;margin-bottom:12px;}p{font-size:0.9rem;color:#64748b;line-height:1.7;}</style></head>';
        echo '<body><div class="error-box">';
        echo '<h1>خطأ غير متوقع</h1>';
        echo '<p>حدث خطأ أثناء تحميل الصفحة. يرجى المحاولة مرة أخرى.</p>';
        echo '</div></body></html>';
    }
});

$page_title = 'إعداد API الهاتف';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
requireAdmin();

// ===== متغيرات =====
$success_msg = '';
$error_msg = '';
$warning_msg = '';
$debug_log = [];
$table_exists = false;
$table_healthy = false;
$token_count = 0;
$sql_executed = false;
$missing_columns = [];
$existing_columns = [];
$columns_fixed = false;

// ===== الأعمدة المطلوبة =====
$required_columns = [
    'id'           => 'INT AUTO_INCREMENT PRIMARY KEY',
    'user_id'      => 'INT NOT NULL',
    'token'        => 'VARCHAR(128) NOT NULL UNIQUE',
    'device_name'  => "VARCHAR(255) DEFAULT ''",
    'is_revoked'   => 'TINYINT(1) DEFAULT 0',
    'expires_at'   => 'DATETIME DEFAULT NULL',
    'last_used_at' => 'DATETIME DEFAULT NULL',
    'created_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
];

// ===== فحص حالة الجدول =====
try {
    $db = AppDatabase::getInstance();
    $pdo = $db->getConnection();
    $debug_log[] = 'تم الاتصال بقاعدة البيانات بنجاح';
    $debug_log[] = 'اسم القاعدة: ' . (defined('DB_NAME') ? DB_NAME : 'غير معروف');

    // فحص وجود الجدول
    $stmt = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
    $found = $stmt->fetch();
    $table_exists = !empty($found);
    $debug_log[] = 'فحص الجدول: SHOW TABLES = ' . ($table_exists ? 'موجود' : 'غير موجود');

    if ($table_exists) {
        // كشف الأعمدة الموجودة
        $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
        $existing_columns = [];
        while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_columns[] = $row['Field'];
        }
        $debug_log[] = 'الأعمدة الموجودة: ' . implode(', ', $existing_columns);

        // كشف الأعمدة المفقودة
        foreach ($required_columns as $col => $def) {
            if (!in_array($col, $existing_columns)) {
                $missing_columns[] = $col;
            }
        }

        if (empty($missing_columns)) {
            $table_healthy = true;
            $debug_log[] = 'الجدول سليم — جميع الأعمدة موجودة';
        } else {
            $debug_log[] = 'أعمدة مفقودة: ' . implode(', ', $missing_columns);
        }

        // عدد الرموز
        try {
            $countRow = $pdo->query("SELECT COUNT(*) as c FROM api_tokens")->fetch(PDO::FETCH_ASSOC);
            $token_count = intval($countRow['c'] ?? 0);
        } catch (\Throwable $e) {
            $debug_log[] = 'خطأ في عد الرموز: ' . $e->getMessage();
        }
    }

    // فحص جدول users
    $usersStmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $users_exists = !empty($usersStmt->fetch());
    $debug_log[] = 'جدول users: ' . ($users_exists ? 'موجود' : 'غير موجود');

    // فحص إصدار MySQL
    try {
        $verStmt = $pdo->query("SELECT VERSION() as v");
        $verRow = $verStmt->fetch(PDO::FETCH_ASSOC);
        $debug_log[] = 'إصدار MySQL: ' . ($verRow['v'] ?? 'غير معروف');
    } catch (\Throwable $e) {
        $debug_log[] = 'لم يتم فحص إصدار MySQL';
    }

} catch (\Throwable $e) {
    $debug_log[] = 'خطأ في الاتصال أو الفحص: ' . $e->getMessage();
}

// ===== SQL لإنشاء الجدول =====
$SQL_WITH_FK = "CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    device_name VARCHAR(255) DEFAULT '',
    is_revoked TINYINT(1) DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_token (token),
    INDEX idx_api_tokens_revoked (is_revoked),
    INDEX idx_api_tokens_expires (expires_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$SQL_NO_FK = "CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    device_name VARCHAR(255) DEFAULT '',
    is_revoked TINYINT(1) DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_token (token),
    INDEX idx_api_tokens_revoked (is_revoked),
    INDEX idx_api_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ===== SQL لإضافة أعمدة مفقودة =====
// v4.39.1: إصلاح حرج — إضافة بـ NULL أولاً ثم تعديل لتجنب فشل NOT NULL مع صفوف موجودة
$ALTER_SQL_STEP1 = [
    'user_id'      => "ALTER TABLE api_tokens ADD COLUMN user_id INT NULL DEFAULT NULL AFTER id",
    'token'        => "ALTER TABLE api_tokens ADD COLUMN token VARCHAR(128) NULL DEFAULT NULL AFTER user_id",
    'device_name'  => "ALTER TABLE api_tokens ADD COLUMN device_name VARCHAR(255) DEFAULT '' AFTER token",
    'is_revoked'   => "ALTER TABLE api_tokens ADD COLUMN is_revoked TINYINT(1) DEFAULT 0 AFTER device_name",
    'expires_at'   => "ALTER TABLE api_tokens ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER is_revoked",
    'last_used_at' => "ALTER TABLE api_tokens ADD COLUMN last_used_at DATETIME DEFAULT NULL AFTER expires_at",
    'created_at'   => "ALTER TABLE api_tokens ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER last_used_at",
];
$ALTER_SQL_STEP2 = [
    'user_id' => "ALTER TABLE api_tokens MODIFY COLUMN user_id INT NOT NULL",
    'token'   => "ALTER TABLE api_tokens MODIFY COLUMN token VARCHAR(128) NOT NULL",
];

// ===== SQL لإعادة بناء الجدول (DROP + CREATE) =====
$SQL_DROP = "DROP TABLE IF EXISTS api_tokens";

// ===== معالجة العمليات =====

// --- العملية 1: إصلاح الجدول (إضافة أعمدة مفقودة) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_columns'])) {
    $debug_log[] = '=== بدء إصلاح الأعمدة ===';
    try {
        if (!AppCsrf::verify()) {
            throw new Exception('خطأ أمني: الطلب غير صالح. يرجى تحديث الصفحة.');
        }
        $debug_log[] = 'CSRF: ناجح';

        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();

        // إضافة كل عمود مفقود (الخطوة 1: بـ NULL/DEFAULT)
        foreach ($missing_columns as $col) {
            if (isset($ALTER_SQL_STEP1[$col])) {
                $debug_log[] = "إضافة عمود (NULL أولاً): {$col}...";
                try {
                    $pdo->exec($ALTER_SQL_STEP1[$col]);
                    $debug_log[] = "نجح إضافة العمود: {$col}";
                } catch (\Throwable $e) {
                    $debug_log[] = "فشل إضافة العمود {$col}: " . $e->getMessage();
                }
            }
        }

        // الخطوة 2: تعبئة قيم فريدة للرموز القديمة
        if (in_array('token', $missing_columns)) {
            try {
                $legacyRows = $pdo->query("SELECT id FROM api_tokens WHERE token IS NULL")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($legacyRows as $row) {
                    $fakeHash = hash('sha256', 'legacy_' . $row['id'] . '_' . bin2hex(random_bytes(16)));
                    $pdo->exec("UPDATE api_tokens SET token = '{$fakeHash}' WHERE id = " . intval($row['id']));
                }
                if (!empty($legacyRows)) {
                    $debug_log[] = "تم تعبئة قيم فريدة لـ " . count($legacyRows) . " صف قديم";
                }
            } catch (\Throwable $e) {
                $debug_log[] = "تعبئة الرموز القديمة: " . $e->getMessage();
            }
        }

        // الخطوة 3: تعديل الأعمدة إلى NOT NULL
        foreach ($missing_columns as $col) {
            if (isset($ALTER_SQL_STEP2[$col])) {
                $debug_log[] = "تعديل عمود إلى NOT NULL: {$col}...";
                try {
                    $pdo->exec($ALTER_SQL_STEP2[$col]);
                    $debug_log[] = "نجح تعديل العمود: {$col}";
                } catch (\Throwable $e) {
                    $debug_log[] = "تعديل العمود {$col}: " . $e->getMessage();
                }
            }
        }

        // إضافة UNIQUE index على token
        if (in_array('token', $missing_columns)) {
            try {
                $pdo->exec("ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_token (token)");
                $debug_log[] = "تمت إضافة فهرس UNIQUE على token";
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $debug_log[] = "فهرس token موجود مسبقاً";
                } else {
                    $debug_log[] = "فهرس token: " . $e->getMessage();
                }
            }
        }

        // إضافة فهارس إذا لم تكن موجودة
        $indexes = [
            'idx_api_tokens_user'    => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_user (user_id)',
            'idx_api_tokens_token'   => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_token (token)',
            'idx_api_tokens_revoked' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_revoked (is_revoked)',
            'idx_api_tokens_expires' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_expires (expires_at)',
        ];
        foreach ($indexes as $name => $sql) {
            try {
                $pdo->exec($sql);
                $debug_log[] = "تمت إضافة الفهرس: {$name}";
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $debug_log[] = "الفهرس موجود مسبقاً: {$name}";
                } else {
                    $debug_log[] = "فهرس {$name}: " . $e->getMessage();
                }
            }
        }

        // التحقق النهائي
        $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
        $final_columns = [];
        while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $final_columns[] = $row['Field'];
        }
        $debug_log[] = 'الأعمدة بعد الإصلاح: ' . implode(', ', $final_columns);

        $still_missing = array_diff(array_keys($required_columns), $final_columns);
        if (empty($still_missing)) {
            $columns_fixed = true;
            $table_healthy = true;
            $missing_columns = [];
            $success_msg = 'تم إصلاح جدول api_tokens بنجاح — جميع الأعمدة موجودة الآن!';
            try { addAuditLog('api_table_fix', 'إصلاح أعمدة جدول api_tokens المفقودة', '', 'system', 0); } catch (\Throwable $e) {}
        } else {
            $error_msg = 'لا تزال أعمدة مفقودة: ' . implode(', ', $still_missing) . ' — جرب إعادة بناء الجدول.';
        }

    } catch (\Throwable $e) {
        $debug_log[] = 'استثناء: ' . $e->getMessage();
        $error_msg = 'خطأ: ' . $e->getMessage();
    }
    $debug_log[] = '=== نهاية إصلاح الأعمدة ===';
}

// --- العملية 2: إعادة بناء الجدول بالكامل (DROP + CREATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rebuild_table'])) {
    $debug_log[] = '=== بدء إعادة بناء الجدول ===';
    try {
        if (!AppCsrf::verify()) {
            throw new Exception('خطأ أمني: الطلب غير صالح. يرجى تحديث الصفحة.');
        }
        $debug_log[] = 'CSRF: ناجح';

        // التحقق من كلمة المرور
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        if (empty($confirm_password)) {
            throw new Exception('يرجى إدخال كلمة المرور للتأكيد.');
        }

        $admin_id = intval($_SESSION['user_id']);
        $admin = fetchOne("SELECT id, password FROM users WHERE id = ? AND role = 'admin'", [$admin_id]);
        if (!$admin || !password_verify($confirm_password, $admin['password'])) {
            throw new Exception('كلمة المرور غير صحيحة.');
        }
        $debug_log[] = 'كلمة المرور: صحيحة';

        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();

        // إنشاء نسخة احتياطية
        $backup_created = false;
        $backup_file = '';
        try {
            if (function_exists('createBackup')) {
                $backupResult = createBackup();
                if (isset($backupResult['file']) && file_exists($backupResult['file'])) {
                    $backup_created = true;
                    $backup_file = basename($backupResult['file']);
                    $debug_log[] = 'نسخة احتياطية: ' . $backup_file;
                }
            }
        } catch (\Throwable $e) {
            $debug_log[] = 'نسخة احتياطية: اختيارية — ' . $e->getMessage();
        }

        // حذف الجدول القديم
        $debug_log[] = 'DROP TABLE IF EXISTS api_tokens...';
        $pdo->exec($SQL_DROP);
        $debug_log[] = 'تم حذف الجدول القديم';

        // إنشاء الجدول الجديد
        $fk_success = false;
        $debug_log[] = 'المحاولة 1: CREATE TABLE مع FOREIGN KEY...';
        try {
            $pdo->exec($SQL_WITH_FK);
            $fk_success = true;
            $debug_log[] = 'المحاولة 1: نجحت!';
        } catch (\Throwable $e1) {
            $debug_log[] = 'المحاولة 1: فشلت — ' . $e1->getMessage();
            $debug_log[] = 'المحاولة 2: CREATE TABLE بدون FOREIGN KEY...';
            try {
                $pdo->exec($SQL_NO_FK);
                $debug_log[] = 'المحاولة 2: نجحت!';
            } catch (\Throwable $e2) {
                throw new Exception('فشلت كلتا المحاولتين: ' . $e2->getMessage());
            }
        }

        // التحقق النهائي
        $check = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
        $table_exists = !empty($check->fetch());

        if ($table_exists) {
            $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
            $final_columns = [];
            while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $final_columns[] = $row['Field'];
            }
            $debug_log[] = 'الأعمدة بعد إعادة البناء: ' . implode(', ', $final_columns);

            $still_missing = array_diff(array_keys($required_columns), $final_columns);
            if (empty($still_missing)) {
                $table_healthy = true;
                $missing_columns = [];
                $sql_executed = true;
                $token_count = 0;
                $success_msg = 'تم إعادة بناء جدول api_tokens بنجاح!' . ($backup_created ? ' (نسخة احتياطية: ' . htmlspecialchars($backup_file) . ')' : '');
                if (!$fk_success) {
                    $warning_msg = 'تم إنشاء الجدول بدون مفتاح أجنبي (FOREIGN KEY). الجدول سيعمل بشكل طبيعي.';
                }
                try { addAuditLog('api_table_rebuild', 'إعادة بناء جدول api_tokens', '', 'system', 0); } catch (\Throwable $e) {}
            } else {
                $error_msg = 'لا تزال أعمدة مفقودة: ' . implode(', ', $still_missing);
            }
        } else {
            $error_msg = 'فشل إنشاء الجدول رغم عدم ظهور خطأ.';
        }

    } catch (\Throwable $e) {
        $debug_log[] = 'استثناء: ' . $e->getMessage();
        $error_msg = 'خطأ: ' . $e->getMessage();
    }
    $debug_log[] = '=== نهاية إعادة البناء ===';
}

// --- العملية 3: إنشاء الجدول لأول مرة ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_sql'])) {
    $debug_log[] = '=== بدء إنشاء الجدول ===';
    try {
        if (!AppCsrf::verify()) {
            throw new Exception('خطأ أمني: الطلب غير صالح. يرجى تحديث الصفحة.');
        }
        $debug_log[] = 'CSRF: ناجح';

        $confirm_password = trim($_POST['confirm_password'] ?? '');
        if (empty($confirm_password)) {
            throw new Exception('يرجى إدخال كلمة المرور للتأكيد.');
        }

        $admin_id = intval($_SESSION['user_id']);
        $admin = fetchOne("SELECT id, password FROM users WHERE id = ? AND role = 'admin'", [$admin_id]);
        if (!$admin || !password_verify($confirm_password, $admin['password'])) {
            throw new Exception('كلمة المرور غير صحيحة.');
        }
        $debug_log[] = 'كلمة المرور: صحيحة';

        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();

        // نسخة احتياطية
        $backup_created = false;
        $backup_file = '';
        try {
            if (function_exists('createBackup')) {
                $backupResult = createBackup();
                if (isset($backupResult['file']) && file_exists($backupResult['file'])) {
                    $backup_created = true;
                    $backup_file = basename($backupResult['file']);
                }
            }
        } catch (\Throwable $e) {}

        $fk_success = false;
        $debug_log[] = 'المحاولة 1: CREATE TABLE مع FOREIGN KEY...';
        try {
            $pdo->exec($SQL_WITH_FK);
            $fk_success = true;
            $debug_log[] = 'المحاولة 1: نجحت!';
        } catch (\Throwable $e1) {
            $debug_log[] = 'المحاولة 1: فشلت — ' . $e1->getMessage();
            $debug_log[] = 'المحاولة 2: بدون FOREIGN KEY...';
            try {
                $pdo->exec($SQL_NO_FK);
                $debug_log[] = 'المحاولة 2: نجحت!';
            } catch (\Throwable $e2) {
                throw new Exception('فشلت كلتا المحاولتين: ' . $e2->getMessage());
            }
        }

        $check = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
        $table_exists = !empty($check->fetch());

        if ($table_exists) {
            $table_healthy = true;
            $missing_columns = [];
            $sql_executed = true;
            $token_count = 0;
            $success_msg = 'تم إنشاء جدول api_tokens بنجاح!' . ($backup_created ? ' (نسخة: ' . htmlspecialchars($backup_file) . ')' : '');
            if (!$fk_success) {
                $warning_msg = 'تم إنشاء الجدول بدون مفتاح أجنبي.';
            }
            try { addAuditLog('api_setup', 'إنشاء جدول api_tokens', '', 'system', 0); } catch (\Throwable $e) {}
        } else {
            $error_msg = 'فشل إنشاء الجدول.';
        }

    } catch (\Throwable $e) {
        $debug_log[] = 'استثناء: ' . $e->getMessage();
        $error_msg = 'خطأ: ' . $e->getMessage();
    }
    $debug_log[] = '=== نهاية إنشاء الجدول ===';
}

// ===== إعادة فحص الحالة بعد العمليات =====
if ($sql_executed || $columns_fixed) {
    try {
        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();
        $stmt = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
        $table_exists = !empty($stmt->fetch());
        if ($table_exists) {
            $countRow = $pdo->query("SELECT COUNT(*) as c FROM api_tokens")->fetch(PDO::FETCH_ASSOC);
            $token_count = intval($countRow['c'] ?? 0);
        }
    } catch (\Throwable $e) {}
}

// ===== فحص المتطلبات =====
$requirements = [
    'php_version' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'json' => extension_loaded('json'),
    'openssl' => extension_loaded('openssl'),
    'api_router' => file_exists(__DIR__ . '/includes/ApiRouter.php'),
    'token_middleware' => file_exists(__DIR__ . '/includes/Router/Middleware/TokenAuthMiddleware.php'),
    'mobile_entry' => file_exists(__DIR__ . '/api/mobile/index.php'),
];
$all_requirements_met = !in_array(false, $requirements, true);
$missing_count = count(array_filter($requirements, fn($v) => !$v));

$page_title = htmlspecialchars(getSetting('app_name', 'إدارة المخزون')) . ' - إعداد API الهاتف';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
.setup-hero{background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border-radius:14px;padding:28px 30px;margin-bottom:24px;color:#fff;position:relative;overflow:hidden}
.setup-hero h1{font-size:1.4rem;font-weight:800;margin:0 0 6px;display:flex;align-items:center;gap:10px}
.setup-hero .subtitle{font-size:0.85rem;color:#94a3b8}
.setup-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04)}
.setup-card h3{font-size:0.95rem;font-weight:700;color:#1e293b;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.setup-check-item{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:0.84rem;color:#475569;border-bottom:1px solid #f1f5f9}
.setup-check-item:last-child{border-bottom:none}
.setup-icon{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;flex-shrink:0}
.setup-icon.pass{background:#dcfce7;color:#166534}
.setup-icon.fail{background:#fef2f2;color:#991b1b}
.setup-icon.warn{background:#fef3c7;color:#92400e}
.setup-box{border-radius:10px;padding:16px 20px;margin-bottom:16px;font-size:0.88rem;line-height:1.7}
.setup-box.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.setup-box.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.setup-box.warning{background:#fef3c7;border:1px solid #fcd34d;color:#92400e}
.setup-box.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.setup-step{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#2563eb;color:#fff;border-radius:50%;font-weight:800;font-size:0.85rem;flex-shrink:0}
.setup-action{background:#fff;border:2px solid #2563eb;border-radius:12px;overflow:hidden;margin-bottom:20px}
.setup-action-header{background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff;padding:14px 20px;font-weight:700;font-size:0.95rem;display:flex;align-items:center;gap:8px}
.setup-action-body{padding:20px}
.setup-debug{background:#0f172a;color:#67e8f9;border-radius:10px;padding:16px 18px;font-family:monospace;font-size:0.76rem;line-height:1.8;direction:ltr;text-align:left;max-height:300px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;margin:10px 0}
.setup-debug .ok{color:#4ade80}
.setup-debug .err{color:#f87171}
.setup-debug .warn{color:#fbbf24}
.setup-steps-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px}
.setup-step-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center;transition:all 0.2s}
.setup-step-card:hover{border-color:#2563eb;box-shadow:0 2px 8px rgba(37,99,235,0.1)}
.setup-step-card a{color:#2563eb;font-weight:700;font-size:0.88rem;text-decoration:none;display:block}
.setup-step-card p{color:#64748b;font-size:0.78rem;margin:6px 0 0}
.version-badge{background:rgba(37,99,235,0.15);color:#93c5fd;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;font-family:monospace;margin-right:8px}
.col-status{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600}
.col-ok{background:#dcfce7;color:#166534}
.col-missing{background:#fef2f2;color:#991b1b}
</style>

<div class="container">
    <!-- رأس الصفحة -->
    <div class="setup-hero">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            إعداد API الهاتف
            <span class="version-badge">v<?php echo $SETUP_API_VERSION; ?> — <?php echo $SETUP_API_BUILD; ?></span>
        </h1>
        <div class="subtitle">
            صفحة إعداد وإصلاح جدول رموز المصادقة — كشف الأعمدة المفقودة تلقائياً
        </div>
    </div>

    <!-- ★★★ إذا لم ترَ الرقم v4.0 أعلاه، فالملف لم يُرفع بعد ★★★ -->

    <!-- رسائل -->
    <?php if ($success_msg): ?>
    <div class="setup-box success">
        <strong>✅ <?php echo htmlspecialchars($success_msg); ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="setup-box error">
        <strong>❌ <?php echo htmlspecialchars($error_msg); ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($warning_msg): ?>
    <div class="setup-box warning">
        <strong>⚠️ <?php echo htmlspecialchars($warning_msg); ?></strong>
    </div>
    <?php endif; ?>

    <!-- سجل التشخيص -->
    <?php if (!empty($debug_log) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="setup-card">
        <h3>
            <span class="setup-step">🔍</span>
            سجل التشخيص التفصيلي
        </h3>
        <div class="setup-debug"><?php
            foreach ($debug_log as $line) {
                $class = '';
                if (stripos($line, 'فشل') !== false || stripos($line, 'خطأ') !== false || stripos($line, 'استثناء') !== false || stripos($line, 'غير موجود') !== false || stripos($line, 'مفقود') !== false) {
                    $class = 'err';
                } elseif (stripos($line, 'نجح') !== false || stripos($line, 'موجود') !== false || stripos($line, 'جاهز') !== false || stripos($line, 'صحيحة') !== false || stripos($line, 'سليم') !== false) {
                    $class = 'ok';
                } elseif (stripos($line, 'اختيار') !== false || stripos($line, 'موجود مسبقا') !== false) {
                    $class = 'warn';
                }
                echo '<span class="' . $class . '">' . htmlspecialchars($line) . "</span>\n";
            }
        ?></div>
    </div>
    <?php endif; ?>

    <!-- الخطوة 1: فحص المتطلبات -->
    <div class="setup-card">
        <h3>
            <span class="setup-step">1</span>
            فحص المتطلبات
            <?php if ($all_requirements_met): ?>
            <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto">جميع المتطلبات متوفرة</span>
            <?php else: ?>
            <span style="background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto"><?php echo $missing_count; ?> ناقصة</span>
            <?php endif; ?>
        </h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px">
            <div>
                <div style="font-weight:700;font-size:0.82rem;color:#334155;margin-bottom:6px">الخادم</div>
                <div class="setup-check-item"><span class="setup-icon <?php echo $requirements['php_version']?'pass':'fail'; ?>"><?php echo $requirements['php_version']?'✓':'✗'; ?></span>PHP 7.4+ <span style="color:#94a3b8;font-size:0.75rem">(<?php echo PHP_VERSION; ?>)</span></div>
                <div class="setup-check-item"><span class="setup-icon <?php echo $requirements['pdo_mysql']?'pass':'fail'; ?>"><?php echo $requirements['pdo_mysql']?'✓':'✗'; ?></span>PDO MySQL</div>
                <div class="setup-check-item"><span class="setup-icon <?php echo $requirements['openssl']?'pass':'fail'; ?>"><?php echo $requirements['openssl']?'✓':'✗'; ?></span>OpenSSL</div>
            </div>
            <div>
                <div style="font-weight:700;font-size:0.82rem;color:#334155;margin-bottom:6px">ملفات API</div>
                <div class="setup-check-item"><span class="setup-icon <?php echo $requirements['api_router']?'pass':'fail'; ?>"><?php echo $requirements['api_router']?'✓':'✗'; ?></span>ApiRouter.php</div>
                <div class="setup-check-item"><span class="setup-icon <?php echo $requirements['token_middleware']?'pass':'fail'; ?>"><?php echo $requirements['token_middleware']?'✓':'✗'; ?></span>TokenAuthMiddleware.php</div>
                <div class="setup-check-item"><span class="setup-icon <?php echo $requirements['mobile_entry']?'pass':'fail'; ?>"><?php echo $requirements['mobile_entry']?'✓':'✗'; ?></span>api/mobile/index.php</div>
            </div>
        </div>
    </div>

    <!-- الخطوة 2: حالة الجدول -->
    <div class="setup-card">
        <h3>
            <span class="setup-step">2</span>
            حالة جدول قاعدة البيانات
            <?php if ($table_exists && $table_healthy): ?>
            <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto">الجدول سليم ✅</span>
            <?php elseif ($table_exists && !$table_healthy): ?>
            <span style="background:#fef2f2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto">يحتاج إصلاح ⚠️</span>
            <?php else: ?>
            <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto">غير موجود ⚠️</span>
            <?php endif; ?>
        </h3>

        <?php if ($table_exists): ?>
            <!-- عرض الأعمدة -->
            <div style="margin-bottom:14px;">
                <div style="font-weight:700;font-size:0.82rem;color:#334155;margin-bottom:8px;">هيكل الجدول:</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;">
                    <?php foreach ($required_columns as $col => $def): ?>
                    <?php $exists = in_array($col, $existing_columns); ?>
                    <div class="col-status <?php echo $exists ? 'col-ok' : 'col-missing'; ?>">
                        <?php echo $exists ? '✓' : '✗'; ?> <?php echo htmlspecialchars($col); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($table_healthy): ?>
            <div class="setup-box success" style="margin-bottom:0">
                <strong>جدول api_tokens سليم!</strong>
                <p style="margin:6px 0 0;font-size:0.84rem">عدد الرموز: <strong><?php echo $token_count; ?></strong></p>
            </div>

            <div class="setup-steps-grid">
                <div class="setup-step-card">
                    <a href="settings/api-tokens.php">إدارة رموز API</a>
                    <p>إنشاء وإدارة رموز المصادقة</p>
                </div>
                <div class="setup-step-card">
                    <a href="api-test.php">اختبار API</a>
                    <p>اختبار تفاعلي لنقاط النهاية</p>
                </div>
            </div>

            <?php else: ?>
            <!-- الجدول موجود لكن بأعمدة مفقودة -->
            <div class="setup-box error" style="margin-bottom:12px">
                <strong>جدول api_tokens موجود لكن يفتقد أعمدة!</strong>
                <p style="margin:6px 0 0;font-size:0.84rem">
                    الأعمدة المفقودة: <strong style="color:#991b1b;"><?php echo implode(', ', $missing_columns); ?></strong>
                </p>
                <p style="margin:4px 0 0;font-size:0.82rem;color:#64748b;">
                    هذا يسبب خطأ <code>Column not found</code> في صفحة إدارة الرموز.
                </p>
            </div>

            <!-- خيار 1: إصلاح الأعمدة -->
            <div class="setup-action" style="margin-bottom:16px;">
                <div class="setup-action-header" style="background:linear-gradient(135deg,#16a34a,#22c55e);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                    خيار 1: إصلاح الأعمدة المفقودة (آمن — يحافظ على البيانات)
                </div>
                <div class="setup-action-body">
                    <div class="setup-box info" style="margin-bottom:14px">
                        سيتم إضافة الأعمدة المفقودة فقط دون حذف أي بيانات.
                    </div>
                    <form method="POST" action="create-api-table.php">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="fix_columns" class="btn" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-weight:700;border-radius:8px;border:none;cursor:pointer;background:linear-gradient(135deg,#16a34a,#22c55e);color:white;font-size:0.95rem;box-shadow:0 2px 8px rgba(22,163,74,0.3)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                            إصلاح الأعمدة المفقودة
                        </button>
                    </form>
                </div>
            </div>

            <!-- خيار 2: إعادة بناء الجدول -->
            <div class="setup-action" style="border-color:#dc2626;">
                <div class="setup-action-header" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    خيار 2: إعادة بناء الجدول بالكامل (يحذف البيانات الموجودة!)
                </div>
                <div class="setup-action-body">
                    <div class="setup-box warning" style="margin-bottom:14px">
                        <strong>تحذير:</strong> هذا سيحذف جدول api_tokens بالكامل وينشئه من جديد. أي رموز موجودة ستُفقد.
                        يتم إنشاء نسخة احتياطية تلقائياً قبل الحذف.
                    </div>
                    <form method="POST" action="create-api-table.php" onsubmit="return confirmRebuild()">
                        <?php echo csrfField(); ?>
                        <div style="margin-bottom:14px">
                            <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;color:#1e293b">
                                كلمة المرور للتأكيد <span style="color:#dc2626">*</span>
                            </label>
                            <input type="password" name="confirm_password" class="form-control" required
                                   placeholder="أدخل كلمة مرور المدير للتأكيد"
                                   style="max-width:400px;font-size:0.9rem;padding:10px 12px"
                                   autocomplete="current-password">
                        </div>
                        <button type="submit" name="rebuild_table" class="btn" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-weight:700;border-radius:8px;border:none;cursor:pointer;background:linear-gradient(135deg,#dc2626,#ef4444);color:white;font-size:0.95rem;box-shadow:0 2px 8px rgba(220,38,38,0.3)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                            إعادة بناء الجدول بالكامل
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- الجدول غير موجود -->
            <div class="setup-box warning" style="margin-bottom:12px">
                جدول <code style="background:#fef3c7;padding:1px 6px;border-radius:3px;font-size:0.8rem">api_tokens</code> غير موجود بعد. يجب إنشاؤه لتتمكن تطبيقات الهاتف من المصادقة.
            </div>

            <?php if ($all_requirements_met): ?>
            <div class="setup-action">
                <div class="setup-action-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    إنشاء جدول api_tokens
                </div>
                <div class="setup-action-body">
                    <form method="POST" action="create-api-table.php" onsubmit="return confirmCreate()">
                        <?php echo csrfField(); ?>
                        <div style="margin-bottom:14px">
                            <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;color:#1e293b">
                                كلمة المرور للتأكيد <span style="color:#dc2626">*</span>
                            </label>
                            <input type="password" name="confirm_password" class="form-control" required
                                   placeholder="أدخل كلمة مرور المدير للتأكيد"
                                   style="max-width:400px;font-size:0.9rem;padding:10px 12px"
                                   autocomplete="current-password">
                        </div>
                        <button type="submit" name="execute_sql" class="btn" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-weight:700;border-radius:8px;border:none;cursor:pointer;background:linear-gradient(135deg,#2563eb,#3b82f6);color:white;font-size:0.95rem;box-shadow:0 2px 8px rgba(37,99,235,0.3)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            إنشاء جدول api_tokens
                        </button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="setup-box error">
                <strong>لا يمكن المتابعة:</strong> بعض المتطلبات غير متوفرة.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- نجاح العملية -->
    <?php if ($sql_executed || $columns_fixed): ?>
    <div class="setup-card" style="border:2px solid #16a34a">
        <h3 style="color:#16a34a">✅ تم الإعداد بنجاح! — الخطوات التالية</h3>
        <div class="setup-steps-grid">
            <div class="setup-step-card"><a href="settings/api-tokens.php">إنشاء أول رمز API</a><p>أنشئ رمزاً لتطبيق الهاتف</p></div>
            <div class="setup-step-card"><a href="api-test.php">اختبار API</a><p>اختبار تفاعلي لنقاط النهاية</p></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- معلومات أمنية -->
    <div class="setup-card">
        <h3>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#dc2626"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            ضمانات الأمان
        </h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;font-size:0.84rem;color:#475569;line-height:1.7">
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">تأكيد مزدوج</strong><br>يتطلب كلمة مرور المدير
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">نسخة احتياطية</strong><br>تلقائية قبل إعادة البناء
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">إصلاح ذكي</strong><br>إضافة أعمدة مفقودة فقط
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">حماية CSRF</strong><br>كل طلب محمي
            </div>
        </div>
    </div>
</div>

<script>
function confirmRebuild() {
    var pwd = document.querySelector('input[name="confirm_password"]');
    if (!pwd || !pwd.value.trim()) {
        alert('يرجى إدخال كلمة المرور للتأكيد.');
        return false;
    }
    return confirm('⚠️ هل أنت متأكد من إعادة بناء الجدول؟ سيتم حذف أي بيانات موجودة!');
}
function confirmCreate() {
    var pwd = document.querySelector('input[name="confirm_password"]');
    if (!pwd || !pwd.value.trim()) {
        alert('يرجى إدخال كلمة المرور للتأكيد.');
        return false;
    }
    return confirm('هل أنت متأكد من إنشاء جدول api_tokens؟');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
