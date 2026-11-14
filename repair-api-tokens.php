<?php
/**
 * ===== صفحة إصلاح جدول api_tokens الشاملة =====
 *
 * سيناريو الاستخدام: الهاتف يعرض "جدول api_tokens يحتاج إصلاح — أعمدة مفقودة"
 * افتح هذه الصفحة كمدير → اضغط "إصلاح تلقائي كامل" → جرّب الدخول من الهاتف.
 *
 * النسخة 1.0 (2026-11-14) — وفق إصلاح v6.3.6:
 *   - القائمة الكاملة للأعمدة (11 عموداً) بما فيها token_hash الذي كان يُغفل
 *     عن أدوات الإصلاح القديمة فيبقى خطأ "Column not found: token_hash" قائماً
 *   - تعبئة token_hash = SHA2(token, 256) للرموز القديمة — تبقى صالحة
 *   - الفهارس المطلوبة بما فيها UNIQUE idx_api_tokens_hash
 *   - فحص كود الخادم المنشور: هل الإصلاح التلقائي يعرف token_hash؟
 *   - إعادة بناء كاملة (اختيارية — تحذف البيانات)
 *
 * الأمان: جلسة مدير + CSRF + كلمة مرور للعميلة الهادمة
 */

// ===== مؤشر الإصدار — إذا لم ترَ هذا الرقم في الصفحة فالملف لم يُرفع =====
$REPAIR_VERSION = '1.0';
$REPAIR_BUILD = '2026-11-14-A';

ob_start();
set_error_handler(function($severity, $message, $file, $line) {
    error_log("repair-api-tokens.php [{$severity}]: {$message} in {$file}:{$line}");
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
        echo '<h1>خطأ غير متوقع في صفحة الإصلاح</h1>';
        echo '<p>' . htmlspecialchars($error['message']) . ' — ' . htmlspecialchars(basename($error['file'])) . ':' . (int)$error['line'] . '</p>';
        echo '</div></body></html>';
    }
});

$page_title = 'إصلاح جدول api_tokens';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
requireAdmin();

// ===== متغيرات الحالة =====
$success_msg = '';
$error_msg = '';
$warning_msg = '';
$debug_log = [];
$table_exists = false;
$table_healthy = false;
$existing_columns = [];
$missing_columns = [];
$existing_indexes = [];
$missing_indexes = [];
$stats = ['total' => 0, 'revoked' => 0, 'expired' => 0, 'need_backfill' => 0];
$sha2_ok = false;
$ran_action = false;

// ===== الأعمدة المطلوبة (قائمة v6.3.6 الكاملة — 11 عموداً) =====
$required_columns = [
    'id'           => 'INT AUTO_INCREMENT PRIMARY KEY',
    'user_id'      => 'INT NOT NULL',
    'token'        => 'VARCHAR(128) NOT NULL UNIQUE',
    'token_hash'   => 'VARCHAR(64) DEFAULT NULL — يستخدمه TokenAuthMiddleware',
    'token_name'   => "VARCHAR(255) DEFAULT 'Mobile App'",
    'scopes'       => "VARCHAR(255) DEFAULT 'read,write'",
    'device_name'  => "VARCHAR(255) DEFAULT ''",
    'is_revoked'   => 'TINYINT(1) DEFAULT 0',
    'expires_at'   => 'DATETIME DEFAULT NULL',
    'last_used_at' => 'DATETIME DEFAULT NULL',
    'created_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
];

// ===== الفهارس المطلوبة =====
$required_indexes = [
    'idx_api_tokens_user'    => ['sql' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_user (user_id)',       'label' => 'INDEX (user_id)'],
    'idx_api_tokens_revoked' => ['sql' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_revoked (is_revoked)', 'label' => 'INDEX (is_revoked)'],
    'idx_api_tokens_expires' => ['sql' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_expires (expires_at)', 'label' => 'INDEX (expires_at)'],
    'idx_api_tokens_hash'    => ['sql' => 'ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_hash (token_hash)', 'label' => 'UNIQUE (token_hash) — حرج للمصادقة'],
];

// ===== CREATE TABLE (نسختان: مع FK وبدونه) =====
$SQL_WITH_FK = "CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    token_hash VARCHAR(64) DEFAULT NULL,
    token_name VARCHAR(255) DEFAULT 'Mobile App',
    scopes VARCHAR(255) DEFAULT 'read,write',
    device_name VARCHAR(255) DEFAULT '',
    is_revoked TINYINT(1) DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_token (token),
    INDEX idx_api_tokens_revoked (is_revoked),
    INDEX idx_api_tokens_expires (expires_at),
    UNIQUE INDEX idx_api_tokens_hash (token_hash),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$SQL_NO_FK = "CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    token_hash VARCHAR(64) DEFAULT NULL,
    token_name VARCHAR(255) DEFAULT 'Mobile App',
    scopes VARCHAR(255) DEFAULT 'read,write',
    device_name VARCHAR(255) DEFAULT '',
    is_revoked TINYINT(1) DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_token (token),
    INDEX idx_api_tokens_revoked (is_revoked),
    INDEX idx_api_tokens_expires (expires_at),
    UNIQUE INDEX idx_api_tokens_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ===== ALTER للأعمدة المفقودة (بدون AFTER — الأكثر موثوقية) =====
$ALTER_DEFS = [
    'user_id'      => 'ALTER TABLE api_tokens ADD COLUMN user_id INT NULL DEFAULT NULL',
    'token'        => 'ALTER TABLE api_tokens ADD COLUMN token VARCHAR(128) NULL DEFAULT NULL',
    'token_hash'   => 'ALTER TABLE api_tokens ADD COLUMN token_hash VARCHAR(64) NULL DEFAULT NULL',
    'token_name'   => "ALTER TABLE api_tokens ADD COLUMN token_name VARCHAR(255) DEFAULT 'Mobile App'",
    'scopes'       => "ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(255) DEFAULT 'read,write'",
    'device_name'  => "ALTER TABLE api_tokens ADD COLUMN device_name VARCHAR(255) DEFAULT ''",
    'is_revoked'   => 'ALTER TABLE api_tokens ADD COLUMN is_revoked TINYINT(1) DEFAULT 0',
    'expires_at'   => 'ALTER TABLE api_tokens ADD COLUMN expires_at DATETIME DEFAULT NULL',
    'last_used_at' => 'ALTER TABLE api_tokens ADD COLUMN last_used_at DATETIME DEFAULT NULL',
    'created_at'   => 'ALTER TABLE api_tokens ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
];

// ===== دوال التشخيص =====

/**
 * فحص كامل لحالة الجدول — يُنفَّذ عند تحميل الصفحة وبعد كل عملية
 */
function diagnoseApiTokens(array $required_columns, array $required_indexes): array {
    $result = [
        'connected' => false, 'table_exists' => false, 'existing_columns' => [],
        'missing_columns' => [], 'existing_indexes' => [], 'missing_indexes' => [],
        'stats' => ['total' => 0, 'revoked' => 0, 'expired' => 0, 'need_backfill' => 0],
        'sha2_ok' => false, 'healthy' => false, 'log' => [],
    ];
    try {
        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();
        $result['connected'] = true;
        $result['log'][] = 'الاتصال بقاعدة البيانات: ناجح';

        // اختبار SHA2 (يدعمه MySQL 5.5+) ومطابقته لـ hash() في PHP
        try {
            $probe = 'ism_repair_probe_' . time();
            $row = $pdo->query("SELECT SHA2(" . $pdo->quote($probe) . ", 256) AS h")->fetch(PDO::FETCH_ASSOC);
            $result['sha2_ok'] = isset($row['h']) && hash_equals((string)$row['h'], hash('sha256', $probe));
            $result['log'][] = 'دالة SHA2 في MySQL: ' . ($result['sha2_ok'] ? 'تعمل ومطابقة لـ PHP' : 'غير متوافقة!');
        } catch (\Throwable $e) {
            $result['log'][] = 'اختبار SHA2 فشل: ' . $e->getMessage();
        }

        // وجود الجدول
        $found = $pdo->query("SHOW TABLES LIKE 'api_tokens'")->fetch();
        $result['table_exists'] = !empty($found);
        $result['log'][] = 'جدول api_tokens: ' . ($result['table_exists'] ? 'موجود' : 'غير موجود');
        if (!$result['table_exists']) {
            return $result;
        }

        // الأعمدة الموجودة
        $colStmt = $pdo->query('SHOW COLUMNS FROM api_tokens');
        while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $result['existing_columns'][] = $row['Field'];
        }
        $result['missing_columns'] = array_diff(array_keys($required_columns), $result['existing_columns']);
        $result['log'][] = 'الأعمدة: ' . count($result['existing_columns']) . ' موجود / ' . count($result['missing_columns']) . ' مفقود' . (count($result['missing_columns']) ? ' (' . implode(', ', $result['missing_columns']) . ')' : '');

        // الفهارس الموجودة
        $idxStmt = $pdo->query('SHOW INDEX FROM api_tokens');
        while ($row = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
            $result['existing_indexes'][$row['Key_name']][] = $row['Column_name'];
        }
        foreach (array_keys($required_indexes) as $idxName) {
            if (!isset($result['existing_indexes'][$idxName])) {
                $result['missing_indexes'][] = $idxName;
            }
        }
        $result['log'][] = 'الفهارس: ' . count($result['existing_indexes']) . ' موجود / ' . count($result['missing_indexes']) . ' مفقود' . (count($result['missing_indexes']) ? ' (' . implode(', ', $result['missing_indexes']) . ')' : '');

        // الإحصائيات (تعمل فقط إذا الأعمدة الحرجة موجودة)
        try {
            $row = $pdo->query("SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN is_revoked = 1 THEN 1 ELSE 0 END), 0) AS revoked,
                COALESCE(SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN 1 ELSE 0 END), 0) AS expired,
                COALESCE(SUM(CASE WHEN token_hash IS NULL AND token IS NOT NULL AND token != '' THEN 1 ELSE 0 END), 0) AS need_backfill
                FROM api_tokens")->fetch(PDO::FETCH_ASSOC);
            $result['stats'] = [
                'total' => (int)($row['total'] ?? 0),
                'revoked' => (int)($row['revoked'] ?? 0),
                'expired' => (int)($row['expired'] ?? 0),
                'need_backfill' => (int)($row['need_backfill'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $result['log'][] = 'الإحصائيات: غير متاحة (' . $e->getMessage() . ')';
        }

        // الحكم النهائي
        $result['healthy'] = empty($result['missing_columns'])
            && !in_array('idx_api_tokens_hash', $result['missing_indexes'], true)
            && $result['stats']['need_backfill'] === 0;
        $result['log'][] = $result['healthy'] ? 'الحالة: الجدول جاهز بالكامل' : 'الحالة: يحتاج إصلاح';
    } catch (\Throwable $e) {
        $result['log'][] = 'خطأ في التشخيص: ' . $e->getMessage();
    }
    return $result;
}

/**
 * فحص كود الخادم المنشور — هل يعرف token_hash؟
 * يكشف إن كان الكود على الخادم أقدم من v6.3.6
 */
function checkDeployedCode(): array {
    $checks = [
        'autofix' => ['file' => __DIR__ . '/api/mobile/index.php', 'knows_hash' => false, 'readable' => false],
        'auth'    => ['file' => __DIR__ . '/includes/Controllers/Mobile/MobileAuthController.php', 'knows_hash' => false, 'readable' => false],
    ];
    foreach ($checks as $k => $c) {
        $src = @file_get_contents($c['file']);
        $checks[$k]['readable'] = ($src !== false && $src !== '');
        $checks[$k]['knows_hash'] = ($src !== false && strpos($src, 'token_hash') !== false);
    }
    return $checks;
}

// ===== تنفيذ العمليات (POST) =====
$db = null;
$pdo = null;
try {
    $db = AppDatabase::getInstance();
    $pdo = $db->getConnection();
} catch (\Throwable $e) {
    $error_msg = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null) {
    $ran_action = true;

    // --- العملية 1: الإصلاح التلقائي الكامل (آمن) ---
    if (isset($_POST['fix_all'])) {
        $debug_log[] = '=== بدء الإصلاح التلقائي الكامل ===';
        try {
            if (!AppCsrf::verify()) {
                throw new Exception('خطأ أمني: الطلب غير صالح. يرجى تحديث الصفحة.');
            }
            $debug_log[] = 'CSRF: ناجح';

            // 1) إنشاء الجدول إن لم يوجد
            $found = $pdo->query("SHOW TABLES LIKE 'api_tokens'")->fetch();
            if (empty($found)) {
                $debug_log[] = 'الجدول غير موجود — إنشاؤه...';
                try {
                    $pdo->exec($SQL_WITH_FK);
                    $debug_log[] = 'إنشاء الجدول (مع FK): نجح';
                } catch (\Throwable $e1) {
                    $pdo->exec($SQL_NO_FK);
                    $debug_log[] = 'إنشاء الجدول (بدون FK): نجح';
                    $warning_msg = 'تم إنشاء الجدول بدون مفتاح أجنبي.';
                }
            }

            // 2) إضافة الأعمدة المفقودة
            $colStmt = $pdo->query('SHOW COLUMNS FROM api_tokens');
            $cols = [];
            while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) { $cols[] = $row['Field']; }
            $toAdd = array_diff(array_keys($required_columns), $cols);
            foreach ($toAdd as $col) {
                if (isset($ALTER_DEFS[$col])) {
                    try {
                        $pdo->exec($ALTER_DEFS[$col]);
                        $debug_log[] = "إضافة العمود {$col}: نجحت";
                    } catch (\Throwable $e) {
                        $debug_log[] = "إضافة العمود {$col} فشلت: " . $e->getMessage();
                    }
                }
            }
            if (empty($toAdd)) { $debug_log[] = 'الأعمدة: جميعها موجودة مسبقاً'; }

            // 3) تعبئة قيم token للصفوف القديمة (إن أُضيف العمود الآن ووُجدت صفوف)
            $rowCount = 0;
            try { $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn(); } catch (\Throwable $e) {}
            if ($rowCount > 0 && in_array('token', $toAdd, true)) {
                $rows = $pdo->query('SELECT id FROM api_tokens WHERE token IS NULL')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $fakeHash = hash('sha256', 'legacy_' . $row['id'] . '_' . bin2hex(random_bytes(16)));
                    $stmt = $pdo->prepare('UPDATE api_tokens SET token = ? WHERE id = ?');
                    $stmt->execute([$fakeHash, (int)$row['id']]);
                }
                if (!empty($rows)) { $debug_log[] = 'تعبئة token لصفوف قديمة: ' . count($rows); }
            }

            // 4) تعبئة token_hash للرموز القديمة — تبقى صالحة
            $backfilled = 0;
            try {
                $backfilled = $pdo->exec("UPDATE api_tokens SET token_hash = SHA2(token, 256) WHERE token_hash IS NULL AND token IS NOT NULL AND token != ''");
                $debug_log[] = "تعبئة token_hash: {$backfilled} رمز قديم صار صالحاً";
            } catch (\Throwable $e) {
                $debug_log[] = 'تعبئة token_hash فشلت: ' . $e->getMessage();
            }

            // 5) تعبئة token_name/scopes للصفوف القديمة (قيم افتراضية)
            try { $pdo->exec("UPDATE api_tokens SET token_name = 'Mobile App' WHERE token_name IS NULL"); } catch (\Throwable $e) {}
            try { $pdo->exec("UPDATE api_tokens SET scopes = 'read,write' WHERE scopes IS NULL"); } catch (\Throwable $e) {}

            // 6) الفهارس المفقودة
            $idxStmt = $pdo->query('SHOW INDEX FROM api_tokens');
            $haveIdx = [];
            while ($row = $idxStmt->fetch(PDO::FETCH_ASSOC)) { $haveIdx[$row['Key_name']] = true; }
            foreach ($required_indexes as $idxName => $def) {
                if (!isset($haveIdx[$idxName])) {
                    try {
                        $pdo->exec($def['sql']);
                        $debug_log[] = "إضافة الفهرس {$idxName}: نجحت";
                    } catch (\Throwable $e) {
                        $debug_log[] = "الفهرس {$idxName}: " . $e->getMessage();
                    }
                }
            }

            try { addAuditLog('api_tokens_repair', 'إصلاح شامل لجدول api_tokens من صفحة الإصلاح المستقلة', '', 'system', 0); } catch (\Throwable $e) {}

            $diag = diagnoseApiTokens($required_columns, $required_indexes);
            if ($diag['healthy']) {
                $success_msg = 'تم الإصلاح بالكامل — الجدول جاهز! جرّب تسجيل الدخول من الهاتف الآن.';
            } else {
                $error_msg = 'بقي شيء ناقص: ' . implode(', ', array_merge($diag['missing_columns'], $diag['missing_indexes'])) . ' — راجع سجل التشخيص.';
            }
        } catch (\Throwable $e) {
            $debug_log[] = 'استثناء: ' . $e->getMessage();
            $error_msg = 'خطأ: ' . $e->getMessage();
        }
        $debug_log[] = '=== نهاية الإصلاح ===';
    }

    // --- العملية 2: إعادة البناء الكاملة (هادمة) ---
    if (isset($_POST['rebuild_table'])) {
        $debug_log[] = '=== بدء إعادة بناء الجدول ===';
        try {
            if (!AppCsrf::verify()) {
                throw new Exception('خطأ أمني: الطلب غير صالح. يرجى تحديث الصفحة.');
            }
            $debug_log[] = 'CSRF: ناجح';

            $confirm_password = trim($_POST['confirm_password'] ?? '');
            $admin_id = intval($_SESSION['user_id'] ?? 0);
            $admin = fetchOne("SELECT id, password FROM users WHERE id = ? AND role = 'admin'", [$admin_id]);
            if (!$admin || !password_verify($confirm_password, $admin['password'])) {
                throw new Exception('كلمة المرور غير صحيحة — لم يتم حذف أي شيء.');
            }
            $debug_log[] = 'كلمة المرور: صحيحة';

            // نسخة احتياطية إن توفّرت الدالة
            try {
                if (function_exists('createBackup')) {
                    $b = createBackup();
                    if (isset($b['file']) && file_exists($b['file'])) {
                        $debug_log[] = 'نسخة احتياطية: ' . basename($b['file']);
                    }
                }
            } catch (\Throwable $e) {
                $debug_log[] = 'النسخة الاحتياطية: تخطي (' . $e->getMessage() . ')';
            }

            $pdo->exec('DROP TABLE IF EXISTS api_tokens');
            $debug_log[] = 'DROP: تم';
            try {
                $pdo->exec($SQL_WITH_FK);
                $debug_log[] = 'CREATE (مع FK): نجح';
            } catch (\Throwable $e1) {
                $pdo->exec($SQL_NO_FK);
                $debug_log[] = 'CREATE (بدون FK): نجح';
                $warning_msg = 'تم إنشاء الجدول بدون مفتاح أجنبي.';
            }

            try { addAuditLog('api_tokens_rebuild', 'إعادة بناء جدول api_tokens (حذف كامل) من صفحة الإصلاح المستقلة', '', 'system', 0); } catch (\Throwable $e) {}

            $success_msg = 'تمت إعادة بناء الجدول فارغاً — أنشئ رمزاً جديداً ثم سجّل الدخول من الهاتف.';
        } catch (\Throwable $e) {
            $debug_log[] = 'استثناء: ' . $e->getMessage();
            $error_msg = 'خطأ: ' . $e->getMessage();
        }
        $debug_log[] = '=== نهاية إعادة البناء ===';
    }
}

// ===== التشخيص النهائي المعروض =====
$diag = diagnoseApiTokens($required_columns, $required_indexes);
$table_exists = $diag['table_exists'];
$table_healthy = $diag['healthy'];
$existing_columns = $diag['existing_columns'];
$missing_columns = $diag['missing_columns'];
$existing_indexes = $diag['existing_indexes'];
$missing_indexes = $diag['missing_indexes'];
$stats = $diag['stats'];
$sha2_ok = $diag['sha2_ok'];
foreach ($diag['log'] as $l) { $debug_log[] = '[فحص] ' . $l; }

$deployed = checkDeployedCode();
$code_needs_update = $deployed['auth']['knows_hash'] && !$deployed['autofix']['knows_hash'];

$requirements = [
    'php' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'pdo' => extension_loaded('pdo_mysql'),
    'sha2' => $sha2_ok,
];
$all_req_ok = !in_array(false, $requirements, true);

// آخر 10 رموز (بدون أي قيم حساسة)
$recent_tokens = [];
if ($table_exists) {
    try {
        $recent_tokens = $pdo->query(
            'SELECT at.id, at.device_name, at.is_revoked, at.expires_at, at.created_at, u.username
             FROM api_tokens at LEFT JOIN users u ON at.user_id = u.id
             ORDER BY at.id DESC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

$page_title = htmlspecialchars(getSetting('app_name', 'إدارة المخزون')) . ' - إصلاح جدول api_tokens';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
.rp-hero{background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border-radius:14px;padding:28px 30px;margin-bottom:24px;color:#fff;position:relative;overflow:hidden}
.rp-hero h1{font-size:1.4rem;font-weight:800;margin:0 0 6px;display:flex;align-items:center;gap:10px}
.rp-hero .subtitle{font-size:0.85rem;color:#94a3b8}
.rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04)}
.rp-card h3{font-size:0.95rem;font-weight:700;color:#1e293b;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.rp-badge{padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;margin-right:auto}
.rp-badge.ok{background:#dcfce7;color:#166534}
.rp-badge.bad{background:#fef2f2;color:#991b1b}
.rp-badge.warn{background:#fef3c7;color:#92400e}
.rp-box{border-radius:10px;padding:16px 20px;margin-bottom:16px;font-size:0.88rem;line-height:1.7}
.rp-box.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.rp-box.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.rp-box.warning{background:#fef3c7;border:1px solid #fcd34d;color:#92400e}
.rp-box.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.rp-step{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#2563eb;color:#fff;border-radius:50%;font-weight:800;font-size:0.85rem;flex-shrink:0}
.rp-check{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:0.84rem;color:#475569;border-bottom:1px solid #f1f5f9}
.rp-check:last-child{border-bottom:none}
.rp-icon{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;flex-shrink:0}
.rp-icon.pass{background:#dcfce7;color:#166534}
.rp-icon.fail{background:#fef2f2;color:#991b1b}
.rp-col{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600}
.rp-col.ok{background:#dcfce7;color:#166534}
.rp-col.missing{background:#fef2f2;color:#991b1b}
.rp-debug{background:#0f172a;color:#67e8f9;border-radius:10px;padding:16px 18px;font-family:monospace;font-size:0.76rem;line-height:1.8;direction:ltr;text-align:left;max-height:320px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;margin:10px 0}
.rp-debug .ok{color:#4ade80}
.rp-debug .err{color:#f87171}
.rp-debug .warn{color:#fbbf24}
.rp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:12px}
.rp-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center}
.rp-stat .num{font-size:1.3rem;font-weight:800;color:#1e293b}
.rp-stat .lbl{font-size:0.72rem;color:#64748b;margin-top:2px}
.rp-table{width:100%;border-collapse:collapse;font-size:0.8rem}
.rp-table th{background:#f1f5f9;color:#334155;padding:8px 10px;text-align:right;font-weight:700;border-bottom:2px solid #e2e8f0}
.rp-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#475569}
.btn-repair{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-weight:700;border-radius:8px;border:none;cursor:pointer;color:#fff;font-size:0.95rem;background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 2px 8px rgba(22,163,74,0.3)}
.btn-danger{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;font-weight:700;border-radius:8px;border:none;cursor:pointer;color:#fff;font-size:0.95rem;background:linear-gradient(135deg,#dc2626,#ef4444);box-shadow:0 2px 8px rgba(220,38,38,0.3)}
</style>

<div class="container">
    <!-- رأس الصفحة -->
    <div class="rp-hero">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
            إصلاح جدول api_tokens
            <span style="background:rgba(255,255,255,0.15);color:#93c5fd;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;font-family:monospace">v<?php echo $REPAIR_VERSION; ?> — <?php echo $REPAIR_BUILD; ?></span>
        </h1>
        <div class="subtitle">إصلاح شامل وفق v6.3.6 — كشف وإصلاح كل ما يحتاجه تسجيل دخول الهاتف بضغطة واحدة</div>
    </div>

    <!-- ★★★ إذا لم ترَ v1.0 أعلاه فالملف لم يُرفع إلى الخادم ★★★ -->

    <?php if ($success_msg): ?>
    <div class="rp-box success"><strong>✅ <?php echo htmlspecialchars($success_msg); ?></strong></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="rp-box error"><strong>❌ <?php echo htmlspecialchars($error_msg); ?></strong></div>
    <?php endif; ?>
    <?php if ($warning_msg): ?>
    <div class="rp-box warning"><strong>⚠️ <?php echo htmlspecialchars($warning_msg); ?></strong></div>
    <?php endif; ?>

    <?php if ($code_needs_update): ?>
    <div class="rp-box warning">
        <strong>⚠️ كود الخادم يحتاج تحديثاً أيضاً</strong>
        <p style="margin:6px 0 0;font-size:0.84rem">
            الملف <code>api/mobile/index.php</code> المنشور على الخادم <strong>قديم</strong> — إصلاحه التلقائي لا يعرف عمود <code>token_hash</code>.
            هذه الصفحة تصلح الجدول الآن، لكن بعد تحديث كود التطبيق إلى v6.3.6 سيصبح النظام ذاتي الإصلاح نهائياً.
        </p>
    </div>
    <?php endif; ?>

    <!-- الخطوة 1: البيئة -->
    <div class="rp-card">
        <h3>
            <span class="rp-step">1</span>
            فحص البيئة
            <?php if ($all_req_ok): ?><span class="rp-badge ok" style="margin-right:auto">جاهزة</span>
            <?php else: ?><span class="rp-badge bad" style="margin-right:auto">مشكلة</span><?php endif; ?>
        </h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px">
            <div>
                <div class="rp-check"><span class="rp-icon <?php echo $requirements['php']?'pass':'fail'; ?>"><?php echo $requirements['php']?'✓':'✗'; ?></span>PHP 7.4+ <span style="color:#94a3b8;font-size:0.75rem">(<?php echo PHP_VERSION; ?>)</span></div>
                <div class="rp-check"><span class="rp-icon <?php echo $requirements['pdo']?'pass':'fail'; ?>"><?php echo $requirements['pdo']?'✓':'✗'; ?></span>PDO MySQL</div>
            </div>
            <div>
                <div class="rp-check"><span class="rp-icon <?php echo $requirements['sha2']?'pass':'fail'; ?>"><?php echo $requirements['sha2']?'✓':'✗'; ?></span>دالة SHA2 في MySQL <span style="color:#94a3b8;font-size:0.75rem">(مطلوبة للتعبئة)</span></div>
                <div class="rp-check"><span class="rp-icon <?php echo $deployed['autofix']['knows_hash']?'pass':'warn'; ?>"><?php echo $deployed['autofix']['knows_hash']?'✓':'!'; ?></span>الإصلاح التلقائي يعرف token_hash <span style="color:#94a3b8;font-size:0.75rem">(كود v6.3.6)</span></div>
            </div>
        </div>
    </div>

    <!-- الخطوة 2: حالة الجدول -->
    <div class="rp-card">
        <h3>
            <span class="rp-step">2</span>
            حالة جدول api_tokens
            <?php if ($table_exists && $table_healthy): ?>
                <span class="rp-badge ok" style="margin-right:auto">جاهز بالكامل ✅</span>
            <?php elseif ($table_exists): ?>
                <span class="rp-badge bad" style="margin-right:auto">يحتاج إصلاح ⚠️</span>
            <?php else: ?>
                <span class="rp-badge warn" style="margin-right:auto">غير موجود</span>
            <?php endif; ?>
        </h3>

        <?php if ($table_exists): ?>
            <div style="margin-bottom:14px;">
                <div style="font-weight:700;font-size:0.82rem;color:#334155;margin-bottom:8px;">الأعمدة المطلوبة (<?php echo count($required_columns); ?>):</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:6px;">
                    <?php foreach ($required_columns as $col => $def): ?>
                    <?php $exists = in_array($col, $existing_columns, true); ?>
                    <div class="rp-col <?php echo $exists ? 'ok' : 'missing'; ?>" title="<?php echo htmlspecialchars($def); ?>">
                        <?php echo $exists ? '✓' : '✗'; ?> <?php echo htmlspecialchars($col); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-bottom:6px;">
                <div style="font-weight:700;font-size:0.82rem;color:#334155;margin-bottom:8px;">الفهارس:</div>
                <?php foreach ($required_indexes as $idxName => $def): ?>
                <?php $exists = isset($existing_indexes[$idxName]); ?>
                <div class="rp-check">
                    <span class="rp-icon <?php echo $exists?'pass':'fail'; ?>"><?php echo $exists?'✓':'✗'; ?></span>
                    <code style="font-size:0.78rem"><?php echo htmlspecialchars($idxName); ?></code>
                    <span style="color:#94a3b8;font-size:0.75rem"><?php echo htmlspecialchars($def['label']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="rp-stats">
                <div class="rp-stat"><div class="num"><?php echo (int)$stats['total']; ?></div><div class="lbl">إجمالي الرموز</div></div>
                <div class="rp-stat"><div class="num"><?php echo (int)max(0, $stats['total'] - $stats['revoked'] - $stats['expired']); ?></div><div class="lbl">رموز نشطة</div></div>
                <div class="rp-stat"><div class="num"><?php echo (int)$stats['revoked']; ?></div><div class="lbl">مُبطلة</div></div>
                <div class="rp-stat"><div class="num"><?php echo (int)$stats['expired']; ?></div><div class="lbl">منتهية الصلاحية</div></div>
                <div class="rp-stat"><div class="num" style="color:<?php echo $stats['need_backfill'] > 0 ? '#991b1b' : '#166534'; ?>"><?php echo (int)$stats['need_backfill']; ?></div><div class="lbl">تحتاج تعبئة token_hash</div></div>
            </div>

            <?php if (!$table_healthy): ?>
            <div class="rp-box error" style="margin-top:14px">
                <strong>ما ينقص الجدول:</strong>
                <?php if (!empty($missing_columns)): ?>
                    <p style="margin:6px 0 0;font-size:0.84rem">أعمدة: <strong><?php echo implode(', ', $missing_columns); ?></strong></p>
                <?php endif; ?>
                <?php if (!empty($missing_indexes)): ?>
                    <p style="margin:4px 0 0;font-size:0.84rem">فهارس: <strong><?php echo implode(', ', $missing_indexes); ?></strong></p>
                <?php endif; ?>
                <?php if ($stats['need_backfill'] > 0): ?>
                    <p style="margin:4px 0 0;font-size:0.84rem"><?php echo (int)$stats['need_backfill']; ?> رمز قديم بدون token_hash (سيبقى صالحاً بعد التعبئة التلقائية)</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="rp-box warning" style="margin-bottom:0">
                <strong>جدول api_tokens غير موجود.</strong> اضغط زر الإصلاح أدناه وسيُنشأ كاملاً وفق المخطط الرسمي.
            </div>
        <?php endif; ?>
    </div>

    <!-- الخطوة 3: الإجراءات -->
    <div class="rp-card">
        <h3>
            <span class="rp-step">3</span>
            الإجراء
            <?php if ($table_healthy): ?>
            <span class="rp-badge ok" style="margin-right:auto">لا حاجة لإجراء</span>
            <?php endif; ?>
        </h3>

        <?php if ($table_healthy): ?>
            <div class="rp-box success" style="margin-bottom:0">
                <strong>الجدول جاهز — جرّب تسجيل الدخول من الهاتف الآن.</strong>
                <p style="margin:6px 0 0;font-size:0.84rem">إذا استمرت المشكلة في الهاتف رغم هذه الرسالة فالكود على الخادم أقدم من v6.3.6 — حدّث ملفات التطبيق.</p>
            </div>
        <?php else: ?>
            <!-- الخيار الآمن -->
            <div class="rp-box info">
                <strong>الإصلاح الآمن (موصى به):</strong>
                يضيف الأعمدة والفهارس المفقودة، ويعبّئ <code>token_hash</code> للرموز القديمة فتبقى صالحة — <strong>بدون حذف أي بيانات</strong>.
            </div>
            <form method="POST" action="repair-api-tokens.php" style="margin-bottom:22px">
                <?php echo csrfField(); ?>
                <button type="submit" name="fix_all" value="1" class="btn-repair">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                    إصلاح تلقائي كامل — بضغطة واحدة
                </button>
            </form>

            <!-- الخيار الهادم -->
            <details style="border:1px solid #fecaca;border-radius:10px;overflow:hidden">
                <summary style="padding:12px 16px;background:#fef2f2;color:#991b1b;font-weight:700;font-size:0.86rem;cursor:pointer">
                    خيار متقدم: إعادة بناء الجدول بالكامل (يحذف جميع الرموز!)
                </summary>
                <div style="padding:16px">
                    <div class="rp-box warning">
                        يُحذف الجدول مع <strong>جميع الرموز</strong> ويُعاد إنشاؤه فارغاً. ستحتاج لإنشاء رمز جديد أو تسجيل دخول جديد من الهاتف.
                        استخدمه فقط إذا فشل الإصلاح الآمن.
                    </div>
                    <form method="POST" action="repair-api-tokens.php" onsubmit="return confirm('هل أنت متأكد؟ سيتم حذف جميع رموز API نهائياً!');">
                        <?php echo csrfField(); ?>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                            <input type="password" name="confirm_password" placeholder="كلمة مرور المدير للتأكيد" required
                                   style="padding:11px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.9rem;min-width:240px">
                            <button type="submit" name="rebuild_table" value="1" class="btn-danger">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/></svg>
                                حذف وإعادة بناء
                            </button>
                        </div>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <!-- سجل التشخيص بعد العمليات -->
    <?php if ($ran_action): ?>
    <div class="rp-card">
        <h3><span class="rp-step">🔍</span> سجل التشخيص التفصيلي</h3>
        <div class="rp-debug"><?php
            foreach ($debug_log as $line) {
                $class = '';
                if (stripos($line, 'فشل') !== false || stripos($line, 'خطأ') !== false || stripos($line, 'استثناء') !== false || stripos($line, 'مفقود') !== false || stripos($line, 'غير موجود') !== false || stripos($line, 'غير متاحة') !== false) {
                    $class = 'err';
                } elseif (stripos($line, 'نجح') !== false || stripos($line, 'جاهز') !== false || stripos($line, 'صالح') !== false || stripos($line, 'ناجح') !== false || stripos($line, 'صحيحة') !== false || stripos($line, 'موجودة مسبقاً') !== false || stripos($line, 'صار') !== false) {
                    $class = 'ok';
                } elseif (stripos($line, 'تخطي') !== false || stripos($line, 'قديم') !== false || stripos($line, 'بدون') !== false) {
                    $class = 'warn';
                }
                echo '<span class="' . $class . '">' . htmlspecialchars($line) . "</span>\n";
            }
        ?></div>
    </div>
    <?php endif; ?>

    <!-- آخر الرموز -->
    <?php if ($table_exists && !empty($recent_tokens)): ?>
    <div class="rp-card">
        <h3><span class="rp-step">📋</span> آخر الرموز (آمنة — بدون قيم الرموز نفسها)</h3>
        <div style="overflow-x:auto;max-height:24rem;overflow-y:auto">
            <table class="rp-table">
                <thead>
                    <tr><th>#</th><th>المستخدم</th><th>الجهاز</th><th>الحالة</th><th>ينتهي</th><th>أُنشئ</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_tokens as $t): ?>
                    <?php
                        $revoked = !empty($t['is_revoked']);
                        $expired = !empty($t['expires_at']) && strtotime((string)$t['expires_at']) < time();
                        if ($revoked) { $st = '<span class="rp-col missing">مُبطل</span>'; }
                        elseif ($expired) { $st = '<span class="rp-col" style="background:#fef3c7;color:#92400e">منتهي</span>'; }
                        else { $st = '<span class="rp-col ok">نشط</span>'; }
                    ?>
                    <tr>
                        <td><?php echo (int)$t['id']; ?></td>
                        <td><?php echo htmlspecialchars((string)($t['username'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string)($t['device_name'] !== null && $t['device_name'] !== '' ? $t['device_name'] : '—')); ?></td>
                        <td><?php echo $st; ?></td>
                        <td><?php echo htmlspecialchars((string)($t['expires_at'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string)($t['created_at'] ?? '—')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- روابط مفيدة -->
    <div class="rp-card">
        <h3><span class="rp-step">↗</span> روابط ذات صلة</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center">
                <a href="settings/api-tokens.php" style="color:#2563eb;font-weight:700;font-size:0.88rem;text-decoration:none">إدارة رموز API</a>
                <p style="color:#64748b;font-size:0.78rem;margin:6px 0 0">إنشاء وإبطال رموز من الواجهة</p>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center">
                <a href="api-test.php" style="color:#2563eb;font-weight:700;font-size:0.88rem;text-decoration:none">اختبار API</a>
                <p style="color:#64748b;font-size:0.78rem;margin:6px 0 0">اختبار تفاعلي لنقاط النهاية</p>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center">
                <a href="create-api-table.php" style="color:#2563eb;font-weight:700;font-size:0.88rem;text-decoration:none">صفحة إعداد API القديمة</a>
                <p style="color:#64748b;font-size:0.78rem;margin:6px 0 0">النسخة الأصلية (استخدم هذه عند الشك)</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
