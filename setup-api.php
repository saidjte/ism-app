<?php
/**
 * ===== صفحة إعداد API الهاتف — النسخة 3 =====
 * v4.34.2: إعادة كتابة كاملة مع تشخيص MySQL
 *
 * التغييرات عن النسخة السابقة:
 *   - SQL مُضمّن مباشرة (لا يعتمد على تحليل الملف)
 *   - لا يستخدم Transactions مع DDL
 *   - محاولة تلقائية بدون FOREIGN KEY إذا فشلت الأولى
 *   - تشخيص كامل لخطوات التنفيذ
 *   - مؤشر إصدار مرئي للتأكد من رفع الملف
 */

// ===== مؤشر الإصدار — إذا لم ترَ هذا في الصفحة، فالملف لم يُرفع =====
$SETUP_API_VERSION = '3.0';
$SETUP_API_BUILD = '2025-05-20-B';

ob_start();
set_error_handler(function($severity, $message, $file, $line) {
    error_log("setup-api.php [{$severity}]: {$message} in {$file}:{$line}");
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
$token_count = 0;
$sql_executed = false;

// ===== فحص حالة الجدول =====
try {
    $db = AppDatabase::getInstance();
    $pdo = $db->getConnection();
    $debug_log[] = 'تم الاتصال بقاعدة البيانات بنجاح';
    $debug_log[] = 'اسم القاعدة: ' . (defined('DB_NAME') ? DB_NAME : 'غير معروف');

    // استخدام PDO مباشرة للفحص
    $stmt = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
    $found = $stmt->fetch();
    $table_exists = !empty($found);
    $debug_log[] = 'فحص الجدول: SHOW TABLES = ' . ($table_exists ? 'موجود' : 'غير موجود');

    if ($table_exists) {
        $countRow = $pdo->query("SELECT COUNT(*) as c FROM api_tokens")->fetch(PDO::FETCH_ASSOC);
        $token_count = intval($countRow['c'] ?? 0);
    }

    // فحص جدول users
    $usersStmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $users_exists = !empty($usersStmt->fetch());
    $debug_log[] = 'جدول users: ' . ($users_exists ? 'موجود' : 'غير موجود');

    // فحص محرك users
    if ($users_exists) {
        try {
            $engStmt = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            $engRow = $engStmt->fetch(PDO::FETCH_ASSOC);
            $debug_log[] = 'محرك جدول users: ' . ($engRow['ENGINE'] ?? 'غير معروف');
        } catch (\Throwable $e) {
            $debug_log[] = 'لم يتم فحص محرك users: ' . $e->getMessage();
        }
    }

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

// ===== SQL مُضمّن مباشرة =====
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

// ===== معالجة التنفيذ =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_sql'])) {
    $debug_log[] = '=== بدء التنفيذ ===';

    try {
        // التحقق من CSRF
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
        $debug_log[] = 'الاتصال: جاهز';

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

        // ===== تنفيذ مباشر — لا Transactions مع DDL =====
        $fk_success = false;
        $fk_error = '';
        $nofk_error = '';

        // المحاولة 1: مع FOREIGN KEY
        $debug_log[] = 'المحاولة 1: CREATE TABLE مع FOREIGN KEY...';
        try {
            $result = $pdo->exec($SQL_WITH_FK);
            $fk_success = true;
            $debug_log[] = 'المحاولة 1: نجحت! (exec أرجع: ' . var_export($result, true) . ')';
        } catch (\Throwable $e1) {
            $fk_error = $e1->getMessage();
            $debug_log[] = 'المحاولة 1: فشلت — ' . $fk_error;

            // المحاولة 2: بدون FOREIGN KEY
            $debug_log[] = 'المحاولة 2: CREATE TABLE بدون FOREIGN KEY...';
            try {
                $result2 = $pdo->exec($SQL_NO_FK);
                $debug_log[] = 'المحاولة 2: نجحت! (exec أرجع: ' . var_export($result2, true) . ')';
            } catch (\Throwable $e2) {
                $nofk_error = $e2->getMessage();
                $debug_log[] = 'المحاولة 2: فشلت — ' . $nofk_error;
                throw new Exception('فشلت كلتا المحاولتين. FK Error: ' . $fk_error . ' | No-FK Error: ' . $nofk_error);
            }
        }

        // التحقق النهائي: هل الجدول موجود فعلاً؟
        $debug_log[] = 'التحقق: SHOW TABLES LIKE api_tokens...';
        $check = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
        $row = $check ? $check->fetch(PDO::FETCH_NUM) : null;
        $table_exists = !empty($row);
        $debug_log[] = 'التحقق: ' . ($table_exists ? 'الجدول موجود!' : 'الجدول غير موجود!');

        if ($table_exists) {
            $sql_executed = true;
            $success_msg = 'تم إنشاء جدول api_tokens بنجاح!';
            if (!$fk_success) {
                $warning_msg = 'تم إنشاء الجدول بدون مفتاح أجنبي (FOREIGN KEY). الجدول سيعمل لكن بدون حذف تلقائي عند حذف المستخدم.';
            }
            if ($backup_created) {
                $success_msg .= ' (نسخة احتياطية: ' . htmlspecialchars($backup_file) . ')';
            }
            try { addAuditLog('api_setup', 'إنشاء جدول api_tokens', '', 'system', 0); } catch (\Throwable $e) {}
        } else {
            // الجدول غير موجود رغم نجاح exec — تحقق من القاعدة
            try {
                $dbCheck = $pdo->query("SELECT DATABASE() as db");
                $dbRow = $dbCheck->fetch(PDO::FETCH_ASSOC);
                $debug_log[] = 'القاعدة النشطة: ' . ($dbRow['db'] ?? 'غير معروفة');
            } catch (\Throwable $e) {}

            $error_msg = 'فشل إنشاء الجدول رغم عدم ظهور خطأ. راجع سجل التشخيص أدناه.';
        }

    } catch (\Throwable $e) {
        $debug_log[] = 'استثناء: ' . $e->getMessage();
        $error_msg = 'خطأ: ' . $e->getMessage();
    }

    $debug_log[] = '=== نهاية التنفيذ ===';
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
.setup-sql{background:#1e293b;color:#e2e8f0;border-radius:10px;padding:16px 18px;font-family:monospace;font-size:0.78rem;line-height:1.7;overflow-x:auto;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-word;margin:10px 0}
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
</style>

<div class="container">
    <!-- رأس الصفحة مع مؤشر الإصدار -->
    <div class="setup-hero">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            إعداد API الهاتف
            <span class="version-badge">v<?php echo $SETUP_API_VERSION; ?> — <?php echo $SETUP_API_BUILD; ?></span>
        </h1>
        <div class="subtitle">
            صفحة إعداد آمنة لإنشاء جدول رموز المصادقة — تُنفَّذ مرة واحدة فقط
        </div>
    </div>

    <!-- ★★★ إذا لم ترَ الرقم v3.0 أعلاه، فالملف لم يُرفع بعد ★★★ -->

    <!-- رسائل النجاح / الخطأ / التحذير -->
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

    <!-- سجل التشخيص (دائماً مرئي بعد التنفيذ) -->
    <?php if (!empty($debug_log) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <div class="setup-card">
        <h3>
            <span class="setup-step">🔍</span>
            سجل التشخيص التفصيلي
        </h3>
        <div class="setup-debug"><?php
            foreach ($debug_log as $line) {
                $class = '';
                if (stripos($line, 'فشل') !== false || stripos($line, 'خطأ') !== false || stripos($line, 'استثناء') !== false || stripos($line, 'غير موجود') !== false) {
                    $class = 'err';
                } elseif (stripos($line, 'نجح') !== false || stripos($line, 'موجود') !== false || stripos($line, 'جاهز') !== false || stripos($line, 'صحيحة') !== false) {
                    $class = 'ok';
                } elseif (stripos($line, 'اختيار') !== false || stripos($line, 'محاولة 1: فشلت') !== false) {
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
            <?php if ($table_exists): ?>
            <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto">الجدول موجود ✅</span>
            <?php else: ?>
            <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto">الجدول غير موجود ⚠️</span>
            <?php endif; ?>
        </h3>

        <?php if ($table_exists): ?>
        <div class="setup-box success" style="margin-bottom:0">
            <strong>جدول api_tokens موجود بالفعل!</strong>
            <p style="margin:6px 0 0;font-size:0.84rem">عدد الرموز: <strong><?php echo $token_count; ?></strong></p>
        </div>

        <div class="setup-steps-grid">
            <div class="setup-step-card">
                <a href="settings/api-tokens.php">إدارة رموز API</a>
                <p>إنشاء وإدارة رموز المصادقة</p>
            </div>
            <div class="setup-step-card">
                <a href="api-docs.php">توثيق API</a>
                <p>دليل المطورين ومختبِر API</p>
            </div>
        </div>

        <?php else: ?>
        <div class="setup-box warning" style="margin-bottom:12px">
            جدول <code style="background:#fef3c7;padding:1px 6px;border-radius:3px;font-size:0.8rem">api_tokens</code> غير موجود بعد. يجب إنشاؤه لتتمكن تطبيقات الهاتف من المصادقة.
        </div>

        <!-- معاينة SQL -->
        <details style="margin-bottom:12px">
            <summary style="cursor:pointer;font-weight:700;font-size:0.88rem;color:#2563eb;display:flex;align-items:center;gap:6px">
                معاينة SQL الذي سيتم تنفيذه
            </summary>
            <div class="setup-sql"><?php echo htmlspecialchars($SQL_WITH_FK); ?></div>
            <p style="font-size:0.78rem;color:#94a3b8;margin-top:6px">إذا فشل المفتاح الأجنبي، سيتم تلقائياً المحاولة بدون FK.</p>
        </details>
        <?php endif; ?>
    </div>

    <!-- الخطوة 3: التنفيذ -->
    <?php if (!$table_exists && $all_requirements_met): ?>
    <div class="setup-action">
        <div class="setup-action-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            الخطوة 3: تنفيذ آمن — إنشاء الجدول
        </div>
        <div class="setup-action-body">
            <div class="setup-box info" style="margin-bottom:16px">
                <strong>ماذا سيحدث:</strong>
                <ol style="margin:8px 0 0;padding-right:20px;list-style:decimal">
                    <li>إنشاء نسخة احتياطية (حماية إضافية)</li>
                    <li>تنفيذ <code style="background:#dbeafe;padding:1px 6px;border-radius:3px;font-size:0.8rem">CREATE TABLE IF NOT EXISTS api_tokens</code> مع المفتاح الأجنبي</li>
                    <li>إذا فشل المفتاح الأجنبي → محاولة تلقائية بدون FK</li>
                    <li>التحقق النهائي من وجود الجدول + عرض سجل تشخيص كامل</li>
                </ol>
            </div>

            <form method="POST" action="setup-api.php" onsubmit="return confirmExecute()">
                <?php echo csrfField(); ?>
                <div style="margin-bottom:14px">
                    <label style="display:block;font-weight:700;margin-bottom:6px;font-size:0.9rem;color:#1e293b">
                        كلمة المرور للتأكيد <span style="color:#dc2626">*</span>
                        <span style="font-size:0.75rem;font-weight:400;color:#94a3b8;margin-right:6px">(كلمة مرور المدير)</span>
                    </label>
                    <input type="password" name="confirm_password" class="form-control" required
                           placeholder="أدخل كلمة مرور المدير للتأكيد"
                           style="max-width:400px;font-size:0.9rem;padding:10px 12px"
                           autocomplete="current-password">
                </div>
                <button type="submit" name="execute_sql" class="btn"
                        style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-weight:700;border-radius:8px;border:none;cursor:pointer;background:linear-gradient(135deg,#2563eb,#3b82f6);color:white;font-size:0.95rem;box-shadow:0 2px 8px rgba(37,99,235,0.3)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    تنفيذ آمن — إنشاء جدول api_tokens
                </button>
            </form>
        </div>
    </div>
    <?php elseif (!$table_exists && !$all_requirements_met): ?>
    <div class="setup-box error">
        <strong>لا يمكن المتابعة:</strong> بعض المتطلبات غير متوفرة.
    </div>
    <?php endif; ?>

    <!-- نجاح التنفيذ -->
    <?php if ($sql_executed): ?>
    <div class="setup-card" style="border:2px solid #16a34a">
        <h3 style="color:#16a34a">✅ تم الإعداد بنجاح! — الخطوات التالية</h3>
        <div class="setup-steps-grid">
            <div class="setup-step-card"><a href="settings/api-tokens.php">إنشاء أول رمز API</a><p>أنشئ رمزاً لتطبيق الهاتف</p></div>
            <div class="setup-step-card"><a href="api-docs.php">توثيق API</a><p>دليل المطورين الشامل</p></div>
            <div class="setup-step-card"><a href="api-docs.php#tab-tester">اختبار API</a><p>جرّب نقاط النهاية تفاعلياً</p></div>
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
                <strong style="color:#1e293b">نسخة احتياطية</strong><br>تلقائية قبل التنفيذ
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">IF NOT EXISTS</strong><br>لا يكتب فوق جدول موجود
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">حماية CSRF</strong><br>كل طلب محمي
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">صلاحية المدير</strong><br>متاحة فقط للمدير
            </div>
            <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <strong style="color:#1e293b">سجل تشخيص</strong><br>عرض تفصيلي لكل خطوة
            </div>
        </div>
    </div>
</div>

<script>
function confirmExecute() {
    var pwd = document.querySelector('input[name="confirm_password"]');
    if (!pwd || !pwd.value.trim()) {
        alert('يرجى إدخال كلمة المرور للتأكيد.');
        return false;
    }
    return confirm('هل أنت متأكد من تنفيذ إنشاء جدول api_tokens؟');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
