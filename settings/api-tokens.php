<?php
/**
 * ===== إدارة رموز API =====
 * صفحة إدارة رموز المصادقة الخاصة بواجهة برمجة التطبيقات (API Tokens)
 * متاحة فقط للمدير
 *
 * الميزات:
 *   - عرض جميع الرموز مع إحصائيات سريعة
 *   - إنشاء رمز جديد مع اختيار المستخدم والجهاز وفترة الصلاحية
 *   - إبطال رمز (Revoke)
 *   - حذف رمز نهائياً
 *
 * SQL — أنشئ الجدول إذا لم يكن موجوداً:
 *
 * CREATE TABLE api_tokens (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     user_id INT NOT NULL,
 *     token VARCHAR(128) NOT NULL UNIQUE,
 *     device_name VARCHAR(255) DEFAULT '',
 *     is_revoked TINYINT(1) DEFAULT 0,
 *     expires_at DATETIME,
 *     last_used_at DATETIME NULL,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
 * );
 */

// معالج أخطاء شامل لمنع الصفحة الفارغة
ob_start();
set_error_handler(function($severity, $message, $file, $line) {
    error_log("api-tokens.php [{$severity}]: {$message} in {$file}:{$line}");
    return false;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
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

$page_title = 'إدارة رموز API';
require_once '../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();

$success_msg = '';
$error_msg = '';

// ===== معالجة العمليات =====

// --- إنشاء رمز جديد ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_token'])) {
    try {
        // التحقق من CSRF
        if (!AppCsrf::verify()) {
            throw new Exception('خطأ أمني: الطلب غير صالح. يرجى تحديث الصفحة.');
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $device_name = trim($_POST['device_name'] ?? '');
        $expiry_days = intval($_POST['expiry_days'] ?? 90);

        // التحقق من صحة البيانات
        if ($user_id <= 0) {
            throw new Exception('يرجى اختيار مستخدم صالح.');
        }

        // التحقق من وجود المستخدم
        $user = fetchOne("SELECT id, username, full_name, is_active FROM users WHERE id = ?", [$user_id]);
        if (!$user) {
            throw new Exception('المستخدم المحدد غير موجود.');
        }
        if (empty($user['is_active'])) {
            throw new Exception('حساب المستخدم المحدد معطّل.');
        }

        // التحقق من فترة الصلاحية
        if (!in_array($expiry_days, [30, 60, 90, 180, 365])) {
            $expiry_days = 90;
        }

        // توليد الرمز
        $token_plain = 'ism_' . bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token_plain);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiry_days} days"));

        if (empty($device_name)) {
            $device_name = 'جهاز غير معروف';
        }

        // حفظ في قاعدة البيانات
        // العمود يُسمى 'token' (يحتوي على SHA-256 hash)
        insertData(
            "INSERT INTO api_tokens (user_id, token, device_name, expires_at) VALUES (?, ?, ?, ?)",
            [$user_id, $token_hash, $device_name, $expires_at]
        );

        // تسجيل النشاط
        addAuditLog('api_token_create', "إنشاء رمز API للمستخدم: {$user['full_name']} ({$user['username']}) — الجهاز: {$device_name}", '', 'api_token', $user_id);

        $success_msg = 'تم إنشاء الرمز بنجاح. انسخ الرمز الآن لأنه لن يظهر مرة أخرى:';
        $created_token = $token_plain;

    } catch (\Throwable $e) {
        error_log('API Token Create Error: ' . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}

// --- إبطال رمز ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_token'])) {
    try {
        if (!AppCsrf::verify()) {
            throw new Exception('خطأ أمني: الطلب غير صالح.');
        }

        $token_id = intval($_POST['token_id'] ?? 0);
        if ($token_id <= 0) {
            throw new Exception('معرف الرمز غير صالح.');
        }

        $token_row = fetchOne("SELECT at.id, at.device_name, at.user_id, u.full_name FROM api_tokens at JOIN users u ON at.user_id = u.id WHERE at.id = ?", [$token_id]);
        if (!$token_row) {
            throw new Exception('الرمز غير موجود.');
        }

        executeQuery("UPDATE api_tokens SET is_revoked = 1 WHERE id = ?", [$token_id]);

        addAuditLog('api_token_revoke', "إبطال رمز API — المستخدم: {$token_row['full_name']}, الجهاز: {$token_row['device_name']}", '', 'api_token', $token_id);

        $success_msg = 'تم إبطال الرمز بنجاح. لن يتمكن التطبيق من الوصول بعد الآن.';

    } catch (\Throwable $e) {
        error_log('API Token Revoke Error: ' . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}

// --- حذف رمز ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token'])) {
    try {
        if (!AppCsrf::verify()) {
            throw new Exception('خطأ أمني: الطلب غير صالح.');
        }

        $token_id = intval($_POST['token_id'] ?? 0);
        if ($token_id <= 0) {
            throw new Exception('معرف الرمز غير صالح.');
        }

        $token_row = fetchOne("SELECT at.id, at.device_name, at.user_id, u.full_name FROM api_tokens at JOIN users u ON at.user_id = u.id WHERE at.id = ?", [$token_id]);
        if (!$token_row) {
            throw new Exception('الرمز غير موجود.');
        }

        executeQuery("DELETE FROM api_tokens WHERE id = ?", [$token_id]);

        addAuditLog('api_token_delete', "حذف رمز API — المستخدم: {$token_row['full_name']}, الجهاز: {$token_row['device_name']}", '', 'api_token', $token_id);

        $success_msg = 'تم حذف الرمز نهائياً.';

    } catch (\Throwable $e) {
        error_log('API Token Delete Error: ' . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}

// ===== جلب البيانات =====

// ===== كشف سلامة الجدول =====
$table_broken = false;
$missing_columns = [];
$required_columns = ['id', 'user_id', 'token', 'device_name', 'is_revoked', 'expires_at', 'last_used_at', 'created_at'];

try {
    $db = AppDatabase::getInstance();
    $pdo = $db->getConnection();
    $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
    $existing_cols = [];
    while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_cols[] = $row['Field'];
    }
    $missing_columns = array_diff($required_columns, $existing_cols);
    if (!empty($missing_columns)) {
        $table_broken = true;
    }
} catch (\Throwable $e) {
    // الجدول قد لا يكون موجوداً أو به خطأ
    $table_broken = true;
}

// إحصائيات الرموز
$total_tokens = 0;
$active_tokens = 0;
$revoked_tokens = 0;
$expired_tokens = 0;

if (!$table_broken) {
    try {
        $total_tokens = intval(fetchOne("SELECT COUNT(*) as c FROM api_tokens")['c'] ?? 0);
        $active_tokens = intval(fetchOne("SELECT COUNT(*) as c FROM api_tokens WHERE is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())")['c'] ?? 0);
        $revoked_tokens = intval(fetchOne("SELECT COUNT(*) as c FROM api_tokens WHERE is_revoked = 1")['c'] ?? 0);
        $expired_tokens = intval(fetchOne("SELECT COUNT(*) as c FROM api_tokens WHERE is_revoked = 0 AND expires_at IS NOT NULL AND expires_at <= NOW()")['c'] ?? 0);
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'api-tokens::fetchStats');
        $table_broken = true;
    }
}

// جلب جميع الرموز
$tokens = [];
if (!$table_broken) {
    try {
        $tokens = fetchAll(
            "SELECT at.id, at.user_id, at.token, at.device_name, at.is_revoked, at.expires_at, at.last_used_at, at.created_at, u.full_name, u.username, u.role,
                    CASE
                        WHEN at.is_revoked = 1 THEN 'revoked'
                        WHEN at.expires_at IS NOT NULL AND at.expires_at <= NOW() THEN 'expired'
                        ELSE 'active'
                    END as status
             FROM api_tokens at
             JOIN users u ON at.user_id = u.id
             ORDER BY at.created_at DESC"
        );
        if (!is_array($tokens)) {
            $tokens = [];
        }
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'api-tokens::fetchTokens');
        $tokens = [];
        $table_broken = true;
    }
}

// جلب المستخدمين النشطين
$users = [];
try {
    $users = fetchAll("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC");
    if (!is_array($users)) {
        $users = [];
    }
} catch (\Throwable $e) {
    ErrorHandler::logException($e, 'api-tokens::fetchUsers');
    $users = [];
}

// الرمز المُنشأ حديثاً (لعرضه مرة واحدة)
$created_token = $created_token ?? null;

/**
 * إخفاء الرمز (عرض الأحرف الأولى والأخيرة فقط)
 */
function maskToken(?string $hash): string {
    if (empty($hash)) return '—';
    // الرمز في قاعدة البيانات مُخزّن كـ sha256، لا يمكن استرجاع الأصلي
    // نعرض أول 4 أحرف والأخيرة 4
    $len = strlen($hash);
    if ($len <= 12) return $hash;
    return 'ism_' . substr($hash, 0, 4) . '...' . substr($hash, -4);
}

/**
 * ترجمة حالة الرمز
 */
function getStatusBadge(string $status): string {
    return match($status) {
        'active'  => '<span class="badge" style="background:#dcfce7; color:#166534; border:1px solid #86efac; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600;">نشط</span>',
        'revoked' => '<span class="badge" style="background:#fef2f2; color:#991b1b; border:1px solid #fca5a5; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600;">مُبطَل</span>',
        'expired' => '<span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600;">منتهي</span>',
        default   => '<span class="badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; padding:3px 10px; border-radius:20px; font-size:0.75rem;">غير معروف</span>',
    };
}

$page_title = htmlspecialchars(getSetting('app_name', 'إدارة المخزون')) . ' - إدارة رموز API';
?>
<?php require_once '../includes/header.php'; ?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
            إدارة رموز API
            <span style="font-size:0.75rem; font-weight:400; color:#94a3b8; margin-right:8px;">مدير النظام فقط</span>
            <a href="system-settings.php" style="font-size:0.78rem; font-weight:600; color:#7c3aed; text-decoration:none; margin-right:12px; display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border:1px solid #d8b4fe; border-radius:6px; background:#f5f3ff;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                إعدادات النظام
            </a>
        </h1>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" style="display:flex; align-items:flex-start; gap:8px; margin-bottom:1rem; flex-direction:column;">
        <div style="display:flex; align-items:center; gap:8px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#059669; flex-shrink:0;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
        <?php if ($created_token): ?>
        <div style="width:100%; background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:12px; margin-top:4px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                <code id="newTokenValue" style="direction:ltr; text-align:left; font-family:monospace; font-size:0.85rem; word-break:break-all; color:#166534;"><?php echo htmlspecialchars($created_token); ?></code>
                <button onclick="copyNewToken()" class="btn" style="background:#166534; color:white; border:none; padding:6px 14px; border-radius:6px; font-size:0.78rem; white-space:nowrap; cursor:pointer;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align:middle; margin-left:4px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    نسخ
                </button>
            </div>
            <small style="color:#166534; font-size:0.75rem; margin-top:6px; display:block;">
                ⚠️ هذا الرمز لن يظهر مرة أخرى. احفظه في مكان آمن.
            </small>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger" style="display:flex; align-items:center; gap:8px; margin-bottom:1rem;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#dc2626; flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>

    <?php if ($table_broken): ?>
    <div style="background:#fef2f2; border:2px solid #fca5a5; border-radius:12px; padding:20px; margin-bottom:1.5rem; color:#991b1b;">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" style="color:#dc2626; flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <strong style="font-size:1.05rem;">هيكل جدول api_tokens غير سليم!</strong>
        </div>
        <p style="margin:0 0 10px; font-size:0.88rem; line-height:1.6;">
            الجدول موجود لكن يفتقد أعمدة مطلوبة:
            <strong><?php echo implode(', ', $missing_columns); ?></strong>
        </p>
        <p style="margin:0 0 12px; font-size:0.84rem; color:#64748b;">
            هذا يسبب خطأ <code style="background:#fff; padding:1px 6px; border-radius:3px;">Column not found</code> عند محاولة إنشاء أو عرض الرموز.
        </p>
        <a href="../create-api-table.php" style="display:inline-flex; align-items:center; gap:6px; padding:8px 18px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:0.88rem; text-decoration:none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
            إصلاح الجدول الآن
        </a>
    </div>
    <?php endif; ?>

    <!-- إحصائيات سريعة -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-bottom:1.5rem;">
        <div style="background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #bfdbfe; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#2563eb;"><?php echo $total_tokens; ?></div>
            <div style="font-size:0.78rem; color:#3b82f6; font-weight:600;">إجمالي الرموز</div>
        </div>
        <div style="background:linear-gradient(135deg, #f0fdf4, #dcfce7); border:1px solid #bbf7d0; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#16a34a;"><?php echo $active_tokens; ?></div>
            <div style="font-size:0.78rem; color:#22c55e; font-weight:600;">نشط</div>
        </div>
        <div style="background:linear-gradient(135deg, #fef2f2, #fecaca); border:1px solid #fca5a5; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#dc2626;"><?php echo $revoked_tokens; ?></div>
            <div style="font-size:0.78rem; color:#ef4444; font-weight:600;">مُبطَل</div>
        </div>
        <div style="background:linear-gradient(135deg, #fef3c7, #fde68a); border:1px solid #fcd34d; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#d97706;"><?php echo $expired_tokens; ?></div>
            <div style="font-size:0.78rem; color:#ca8a04; font-weight:600;">منتهي الصلاحية</div>
        </div>
    </div>

    <!-- إنشاء رمز جديد -->
    <div class="card" style="margin-bottom:1.5rem; border:2px solid #16a34a; position:relative; overflow:hidden;">
        <div style="position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg, #16a34a, #22c55e, #16a34a);"></div>
        <div class="card-header" style="background:linear-gradient(135deg, rgba(22,163,74,0.05), rgba(34,197,94,0.05));">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 4v16m8-8H4"/></svg>
                إنشاء رمز جديد
                <span style="font-size:0.7rem; font-weight:400; color:#94a3b8; margin-right:6px;">للاتصال بتطبيق الهاتف</span>
            </h2>
        </div>
        <div class="card-body">
            <form method="POST" action="api-tokens.php" id="createForm">
                <?php echo csrfField(); ?>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                    <div>
                        <label style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            المستخدم <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="user_id" class="form-control" required style="font-size:0.9rem; padding:10px 12px;">
                            <option value="">— اختر المستخدم —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo intval($u['id']); ?>">
                                <?php echo htmlspecialchars($u['full_name']); ?>
                                (<?php echo htmlspecialchars($u['username']); ?>
                                — <?php echo $u['role'] === 'admin' ? 'مدير' : 'مستخدم'; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            اسم الجهاز
                            <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:4px;">مثال: Samsung Galaxy S24</span>
                        </label>
                        <input type="text" name="device_name" class="form-control" placeholder="اسم الجهاز (اختياري)" style="font-size:0.9rem; padding:10px 12px;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            فترة الصلاحية
                        </label>
                        <select name="expiry_days" class="form-control" style="font-size:0.9rem; padding:10px 12px;">
                            <option value="30">30 يوم</option>
                            <option value="60">60 يوم</option>
                            <option value="90" selected>90 يوم (افتراضي)</option>
                            <option value="180">180 يوم</option>
                            <option value="365">سنة كاملة</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:14px; display:flex; align-items:center; gap:10px;">
                    <button type="submit" name="create_token" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px; padding:10px 20px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:#16a34a; color:white; font-size:0.9rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        إنشاء الرمز
                    </button>
                    <small style="color:#64748b; font-size:0.78rem;">
                        سيتم عرض الرمز مرة واحدة فقط بعد الإنشاء. احفظه في مكان آمن.
                    </small>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة الرموز -->
    <div class="card" style="margin-bottom:1.5rem; position:relative; overflow:hidden;">
        <div style="position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg, #2563eb, #7c3aed, #2563eb);"></div>
        <div class="card-header" style="background:linear-gradient(135deg, rgba(37,99,235,0.05), rgba(124,58,237,0.05));">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                جميع الرموز
                <span style="font-size:0.7rem; font-weight:400; color:#94a3b8; margin-right:6px;"><?php echo $total_tokens; ?> رمز</span>
            </h2>
        </div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <?php if (empty($tokens)): ?>
            <div style="text-align:center; padding:40px 20px; color:#94a3b8;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="margin:0 auto 12px; display:block; color:#cbd5e1;">
                    <path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
                <p style="font-size:0.95rem; font-weight:600; margin-bottom:4px;">لا توجد رموز API بعد</p>
                <p style="font-size:0.82rem;">أنشئ رمزاً جديداً للسماح لتطبيق الهاتف بالاتصال بالنظام.</p>
            </div>
            <?php else: ?>
            <table class="table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                        <th style="padding:12px 14px; text-align:right; font-weight:700; color:#334155; white-space:nowrap;">المستخدم</th>
                        <th style="padding:12px 14px; text-align:right; font-weight:700; color:#334155; white-space:nowrap;">الجهاز</th>
                        <th style="padding:12px 14px; text-align:right; font-weight:700; color:#334155; white-space:nowrap;">الرمز</th>
                        <th style="padding:12px 14px; text-align:right; font-weight:700; color:#334155; white-space:nowrap;">تاريخ الإنشاء</th>
                        <th style="padding:12px 14px; text-align:right; font-weight:700; color:#334155; white-space:nowrap;">آخر استخدام</th>
                        <th style="padding:12px 14px; text-align:right; font-weight:700; color:#334155; white-space:nowrap;">ينتهي في</th>
                        <th style="padding:12px 14px; text-align:center; font-weight:700; color:#334155; white-space:nowrap;">الحالة</th>
                        <th style="padding:12px 14px; text-align:center; font-weight:700; color:#334155; white-space:nowrap;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $i => $t): ?>
                    <tr style="border-bottom:1px solid #f1f5f9; <?php echo $i % 2 === 0 ? '' : 'background:#fafbfc;'; ?>">
                        <td style="padding:10px 14px; white-space:nowrap;">
                            <div style="font-weight:600; color:#1e293b;"><?php echo htmlspecialchars($t['full_name']); ?></div>
                            <div style="font-size:0.75rem; color:#94a3b8;"><?php echo htmlspecialchars($t['username']); ?> · <?php echo $t['role'] === 'admin' ? 'مدير' : 'مستخدم'; ?></div>
                        </td>
                        <td style="padding:10px 14px; color:#475569; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($t['device_name']); ?>">
                            <?php echo htmlspecialchars($t['device_name'] ?: '—'); ?>
                        </td>
                        <td style="padding:10px 14px;">
                            <code style="direction:ltr; text-align:left; font-family:monospace; font-size:0.78rem; background:#f1f5f9; padding:3px 8px; border-radius:4px; color:#64748b; display:inline-block;">
                                <?php echo htmlspecialchars(maskToken($t['token'])); ?>
                            </code>
                        </td>
                        <td style="padding:10px 14px; color:#64748b; white-space:nowrap; font-size:0.82rem;">
                            <?php echo date('Y/m/d H:i', strtotime($t['created_at'])); ?>
                        </td>
                        <td style="padding:10px 14px; color:#64748b; white-space:nowrap; font-size:0.82rem;">
                            <?php echo !empty($t['last_used_at']) ? date('Y/m/d H:i', strtotime($t['last_used_at'])) : '<span style="color:#cbd5e1;">لم يُستخدم</span>'; ?>
                        </td>
                        <td style="padding:10px 14px; color:#64748b; white-space:nowrap; font-size:0.82rem;">
                            <?php if (!empty($t['expires_at'])): ?>
                                <?php
                                $exp = strtotime($t['expires_at']);
                                $now = time();
                                $days_left = ceil(($exp - $now) / 86400);
                                $exp_color = $t['status'] === 'expired' ? '#dc2626' : ($days_left <= 7 ? '#d97706' : '#64748b');
                                ?>
                                <span style="color:<?php echo $exp_color; ?>">
                                    <?php echo date('Y/m/d', $exp); ?>
                                    <?php if ($t['status'] === 'active' && $days_left > 0): ?>
                                    <small style="color:#94a3b8;">(باقي <?php echo $days_left; ?> يوم)</small>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">بدون انتهاء</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px 14px; text-align:center;">
                            <?php echo getStatusBadge($t['status']); ?>
                        </td>
                        <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                            <?php if ($t['status'] === 'active'): ?>
                            <form method="POST" action="api-tokens.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من إبطال هذا الرمز؟ لن يتمكن التطبيق من الوصول بعد الآن.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="token_id" value="<?php echo intval($t['id']); ?>">
                                <button type="submit" name="revoke_token" class="btn btn-danger" style="padding:5px 12px; font-size:0.78rem; border-radius:6px; border:none; cursor:pointer; background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; font-weight:600;" title="إبطال الرمز">
                                    إبطال
                                </button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" action="api-tokens.php" style="display:inline;" onsubmit="return confirm('⚠️ هل أنت متأكد من حذف هذا الرمز نهائياً؟ لا يمكن التراجع عن هذا الإجراء.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="token_id" value="<?php echo intval($t['id']); ?>">
                                <button type="submit" name="delete_token" class="btn" style="padding:5px 12px; font-size:0.78rem; border-radius:6px; border:1px solid #e2e8f0; cursor:pointer; background:#fff; color:#64748b; font-weight:600;" title="حذف الرمز نهائياً">
                                    حذف
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- معلومات إضافية -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                معلومات مهمة
            </h2>
        </div>
        <div class="card-body" style="font-size:0.85rem; color:#475569; line-height:1.8;">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
                <div style="padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                    <strong style="color:#1e293b;">🔐 الأمان</strong>
                    <ul style="margin:8px 0 0; padding-right:20px; list-style:disc;">
                        <li>الرموز مُخزّنة مشفّرة (SHA-256) في قاعدة البيانات</li>
                        <li>الرمز الأصلي يظهر مرة واحدة فقط عند الإنشاء</li>
                        <li>إبطال الرمز يمنع الوصول فوراً بدون حذفه</li>
                    </ul>
                </div>
                <div style="padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                    <strong style="color:#1e293b;">📱 الاستخدام</strong>
                    <ul style="margin:8px 0 0; padding-right:20px; list-style:disc;">
                        <li>أرسل الرمز في رأس <code style="background:#fef3c7; padding:1px 6px; border-radius:3px; font-size:0.8rem;">Authorization: Bearer &lt;token&gt;</code></li>
                        <li>كل طلب API يتطلب رمزاً صالحاً ونشطاً</li>
                        <li>يُحدَّث تاريخ آخر استخدام تلقائياً</li>
                    </ul>
                </div>
                <div style="padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                    <strong style="color:#1e293b;">⏰ الصلاحية</strong>
                    <ul style="margin:8px 0 0; padding-right:20px; list-style:disc;">
                        <li>الرموز المنتهية تُرفض تلقائياً</li>
                        <li>يمكن تجديد الرمز عبر نقطة <code style="background:#fef3c7; padding:1px 6px; border-radius:3px; font-size:0.8rem;">/api/mobile/auth/refresh</code></li>
                        <li>أنشئ رمزاً جديداً بدلاً من استخدام رمز منتهي</li>
                    </ul>
                </div>
            </div>
            <div style="margin-top:14px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:0.82rem; color:#1e40af;">
                <strong>ℹ️ ملاحظة:</strong> لعرض توثيق API الكامل والتجربة التفاعلية، زر صفحة
                <a href="../api-docs.php" style="color:#2563eb; font-weight:600; text-decoration:underline;">توثيق API للمطورين</a>.
            </div>
        </div>
    </div>
</div>

<script>
function copyNewToken() {
    var tokenEl = document.getElementById('newTokenValue');
    if (!tokenEl) return;
    var token = tokenEl.textContent || tokenEl.innerText;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(token).then(function() {
            showCopyFeedback();
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = token;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showCopyFeedback(); } catch(e) {}
        document.body.removeChild(ta);
    }
}
function showCopyFeedback() {
    var btn = event.target.closest('button');
    if (btn) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align:middle; margin-left:4px;"><polyline points="20 6 9 17 4 12"/></svg> تم النسخ!';
        setTimeout(function() { btn.innerHTML = orig; }, 2000);
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
