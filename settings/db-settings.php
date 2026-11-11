<?php
/**
 * ===== إعدادات الاتصال بقاعدة البيانات =====
 *
 * يتيح للمدير تغيير معطيات الاتصال بقاعدة البيانات من واجهة الويب:
 * - المضيف (DB_HOST)
 * - اسم قاعدة البيانات (DB_NAME)
 * - المستخدم (DB_USER)
 * - كلمة المرور (DB_PASS) — تُشفَّر تلقائياً بـ AES-256-GCM
 *
 * الميزات:
 * - اختبار الاتصال قبل الحفظ
 * - تشفير كلمة المرور تلقائياً
 * - نسخ احتياطي لملف .env قبل التعديل
 * - عرض حالة الاتصال الحالية
 *
 * @since v4.20.0
 */

require_once '../includes/bootstrap.php';
$page_title = t('settings_db_settings');
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
requirePermission('settings.manage');

$success_msg = '';
$error_msg = '';
$test_result = null;

// ===== دالة قراءة ملف .env كاملاً =====
function readEnvFile(string $path): array {
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $env = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            $env[] = ['type' => 'comment', 'raw' => $line];
            continue;
        }
        if (!str_contains($trimmed, '=')) {
            $env[] = ['type' => 'other', 'raw' => $line];
            continue;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $env[] = ['type' => 'entry', 'key' => trim($key), 'value' => trim($value), 'raw' => $line];
    }
    return $env;
}

// ===== دالة كتابة ملف .env =====
function writeEnvFile(string $path, array $envData, array $updates): bool {
    $lines = [];
    $updatedKeys = [];

    foreach ($envData as $item) {
        if ($item['type'] === 'entry' && isset($updates[$item['key']])) {
            $newValue = $updates[$item['key']];
            $lines[] = $item['key'] . '=' . $newValue;
            $updatedKeys[$item['key']] = true;
        } else {
            $lines[] = $item['raw'];
        }
    }

    // إضافة المفاتيح الجديدة غير الموجودة
    foreach ($updates as $key => $value) {
        if (!isset($updatedKeys[$key])) {
            $lines[] = $key . '=' . $value;
        }
    }

    // كتابة آلية (atomic write)
    $tmpFile = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmpFile, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        @unlink($tmpFile);
        return false;
    }
    if (!rename($tmpFile, $path)) {
        @unlink($tmpFile);
        return false;
    }
    return true;
}

// ===== دالة اختبار الاتصال بقاعدة بيانات =====
function testDbConnection(string $host, string $name, string $user, string $pass): array {
    try {
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // جلب معلومات الخادم
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();

        // عدد الجداول
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        return [
            'success' => true,
            'version' => $version,
            'database' => $dbName,
            'tables_count' => count($tables),
            'message' => t('settings_connection_success'),
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => t('common_connection_failed') . ': ' . $e->getMessage(),
        ];
    }
}

// ===== معالجة اختبار الاتصال =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    $test_host = trim($_POST['test_host'] ?? '');
    $test_name = trim($_POST['test_name'] ?? '');
    $test_user = trim($_POST['test_user'] ?? '');
    $test_pass = $_POST['test_pass'] ?? '';

    // فك تشفير كلمة المرور إذا لم يتغير حقل كلمة المرور (يحتوي على نجوم)
    if ($test_pass === '••••••••' || $test_pass === '') {
        $test_pass = DB_PASS;
    }

    if (empty($test_host) || empty($test_name) || empty($test_user)) {
        $test_result = ['success' => false, 'message' => 'يرجى ملء جميع الحقول المطلوبة'];
    } else {
        $test_result = testDbConnection($test_host, $test_name, $test_user, $test_pass);
    }
}

// ===== معالجة حفظ الإعدادات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $new_host = trim($_POST['db_host'] ?? '');
        $new_name = trim($_POST['db_name'] ?? '');
        $new_user = trim($_POST['db_user'] ?? '');
        $new_pass = $_POST['db_pass'] ?? '';

        // التحقق من الحقول المطلوبة
        if (empty($new_host)) {
            throw new Exception('المضيف (DB_HOST) مطلوب');
        }
        if (empty($new_name)) {
            throw new Exception('اسم قاعدة البيانات (DB_NAME) مطلوب');
        }
        if (empty($new_user)) {
            throw new Exception('اسم المستخدم (DB_USER) مطلوب');
        }

        // إذا كانت كلمة المرور لم تتغير (نجوم)
        if ($new_pass === '••••••••' || $new_pass === '') {
            $new_pass = DB_PASS;
        }

        // اختبار الاتصال بالمعطيات الجديدة قبل الحفظ
        $connectionTest = testDbConnection($new_host, $new_name, $new_user, $new_pass);
        if (!$connectionTest['success']) {
            throw new Exception('فشل اختبار الاتصال بقاعدة البيانات الجديدة: ' . $connectionTest['message']);
        }

        // تشفير كلمة المرور
        $encryptedPass = $new_pass;
        if (class_exists('EnvEncryptor') && !empty($new_pass)) {
            try {
                $encryptedPass = EnvEncryptor::encrypt($new_pass);
            } catch (Exception $e) {
                // إذا فشل التشفير، نستخدم كلمة المرور كما هي
                $encryptedPass = $new_pass;
            }
        }

        // قراءة ملف .env الحالي
        $envPath = __DIR__ . '/../.env';
        $envData = readEnvFile($envPath);

        // إنشاء نسخة احتياطية من ملف .env
        if (file_exists($envPath)) {
            $backupPath = $envPath . '.backup.' . date('Y-m-d_His');
            copy($envPath, $backupPath);
        }

        // تحديث القيم
        $updates = [
            'DB_HOST' => $new_host,
            'DB_NAME' => $new_name,
            'DB_USER' => $new_user,
            'DB_PASS' => $encryptedPass,
        ];

        if (!writeEnvFile($envPath, $envData, $updates)) {
            throw new Exception('فشل كتابة ملف .env — تحقق من صلاحيات الملف');
        }

        $success_msg = t('settings_db_saved_relogin');

        // تسجيل النشاط
        if (function_exists('logActivity')) {
            logActivity('settings_update', t('settings_update_db_connection'));
        }

    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// ===== جلب الإعدادات الحالية =====
$current_host = defined('DB_HOST') ? DB_HOST : 'localhost';
$current_name = defined('DB_NAME') ? DB_NAME : 'inventory_db';
$current_user = defined('DB_USER') ? DB_USER : 'root';

// حالة الاتصال الحالية
$connection_status = 'unknown';
$connection_info = '';
try {
    $pdo = getDBConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();
        $dbSize = fetchOne("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]);
        $connection_status = 'connected';
        $connection_info = 'MySQL ' . $version . ' | حجم القاعدة: ' . ($dbSize['size'] ?? '0') . ' MB';
    } else {
        $connection_status = 'connected';
        $connection_info = ucfirst($driver);
    }
} catch (Exception $e) {
    $connection_status = 'error';
    $connection_info = $e->getMessage();
}

// معلومات إضافية عن قاعدة البيانات
$db_tables_count = 0;
$db_products_count = 0;
try {
    $db_tables_count = count(fetchAll("SHOW TABLES"));
    $db_products_count = fetchOne("SELECT COUNT(*) as c FROM products")['c'] ?? 0;
} catch (Exception $e) {
    // تجاهل الأخطاء
}
?>

<?php require_once '../includes/header.php'; ?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <ellipse cx="12" cy="5" rx="9" ry="3"/>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
            </svg>
            <?php echo t('settings_db_settings'); ?>
            <span style="font-size:0.75rem; font-weight:400; color:#94a3b8; margin-right:8px;">MySQL</span>
        </h1>
    </div>

    <!-- زر العودة -->
    <div style="margin-bottom:1rem;">
        <a href="system-settings.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--primary, #2563eb); text-decoration:none; font-weight:600; font-size:0.9rem;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
            <?php echo t('settings_back_to_system'); ?>
        </a>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" style="display:flex; align-items:center; gap:8px; margin-bottom:1rem;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#059669; flex-shrink:0;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php echo htmlspecialchars($success_msg); ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger" style="display:flex; align-items:center; gap:8px; margin-bottom:1rem;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#dc2626; flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>

    <?php if ($test_result): ?>
    <div class="alert <?php echo $test_result['success'] ? 'alert-success' : 'alert-danger'; ?>" style="display:flex; align-items:flex-start; gap:8px; margin-bottom:1rem;">
        <?php if ($test_result['success']): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#059669; flex-shrink:0; margin-top:2px;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <div>
            <strong>تم الاتصال بنجاح!</strong><br>
            <span style="font-size:0.85rem; color:#166534;">
                الإصدار: <?php echo htmlspecialchars($test_result['version'] ?? ''); ?> |
                القاعدة: <?php echo htmlspecialchars($test_result['database'] ?? ''); ?> |
                الجداول: <?php echo $test_result['tables_count'] ?? 0; ?>
            </span>
        </div>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#dc2626; flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div>
            <strong>فشل الاتصال</strong><br>
            <span style="font-size:0.85rem; color:#991b1b;"><?php echo htmlspecialchars($test_result['message']); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- حالة الاتصال الحالية -->
    <div class="card" style="margin-bottom:1.5rem; border:2px solid <?php echo $connection_status === 'connected' ? '#22c55e' : '#dc2626'; ?>; position:relative; overflow:hidden;">
        <div style="position:absolute; top:0; left:0; right:0; height:4px; background:<?php echo $connection_status === 'connected' ? 'linear-gradient(90deg, #22c55e, #16a34a)' : 'linear-gradient(90deg, #dc2626, #ef4444)'; ?>;"></div>
        <div class="card-header" style="background:linear-gradient(135deg, <?php echo $connection_status === 'connected' ? 'rgba(34,197,94,0.05), rgba(22,163,74,0.05)' : 'rgba(220,38,38,0.05), rgba(239,68,68,0.05)'; ?>);">
            <h2 style="display:flex; align-items:center; gap:10px;">
                <span style="width:12px; height:12px; border-radius:50%; background:<?php echo $connection_status === 'connected' ? '#22c55e' : '#dc2626'; ?>; display:inline-block; box-shadow:0 0 8px <?php echo $connection_status === 'connected' ? 'rgba(34,197,94,0.4)' : 'rgba(220,38,38,0.4)'; ?>;"></span>
                <?php echo $connection_status === 'connected' ? t('settings_db_connected') : t('settings_connection_error'); ?>
                <span style="font-size:0.75rem; font-weight:400; color:#94a3b8; margin-right:6px;"><?php echo htmlspecialchars($connection_info); ?></span>
            </h2>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px;">
                <div style="text-align:center; padding:10px; background:#f8fafc; border-radius:8px;">
                    <div style="font-size:1.5rem; font-weight:900; color:#2563eb;"><?php echo $db_tables_count; ?></div>
                    <div style="font-size:0.8rem; color:#64748b; font-weight:600;">جدول</div>
                </div>
                <div style="text-align:center; padding:10px; background:#f8fafc; border-radius:8px;">
                    <div style="font-size:1.5rem; font-weight:900; color:#16a34a;"><?php echo $db_products_count; ?></div>
                    <div style="font-size:0.8rem; color:#64748b; font-weight:600;">منتج</div>
                </div>
                <div style="text-align:center; padding:10px; background:#f8fafc; border-radius:8px;">
                    <div style="font-size:0.85rem; font-weight:700; color:#7c3aed; direction:ltr;"><?php echo htmlspecialchars($current_host); ?></div>
                    <div style="font-size:0.8rem; color:#64748b; font-weight:600;">المضيف</div>
                </div>
                <div style="text-align:center; padding:10px; background:#f8fafc; border-radius:8px;">
                    <div style="font-size:0.85rem; font-weight:700; color:#d97706; direction:ltr;"><?php echo htmlspecialchars($current_name); ?></div>
                    <div style="font-size:0.8rem; color:#64748b; font-weight:600;">قاعدة البيانات</div>
                </div>
                <div style="text-align:center; padding:10px; background:#f8fafc; border-radius:8px;">
                    <div style="font-size:0.85rem; font-weight:700; color:#64748b; direction:ltr;"><?php echo htmlspecialchars($current_user); ?></div>
                    <div style="font-size:0.8rem; color:#64748b; font-weight:600;">المستخدم</div>
                </div>
            </div>
        </div>
    </div>

    <!-- تحذير مهم -->
    <div class="alert" style="background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:12px 16px; margin-bottom:1.5rem; display:flex; align-items:flex-start; gap:8px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#d97706; flex-shrink:0; margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div style="font-size:0.85rem; color:#92400e;">
            <strong>تحذير مهم:</strong> تغيير إعدادات الاتصال يؤثر على عمل النظام بالكامل. تأكد من صحة المعطيات قبل الحفظ. يتم اختبار الاتصال تلقائياً قبل حفظ أي تغييرات. يُنصح بأخذ نسخة احتياطية قبل التعديل.
        </div>
    </div>

    <form method="POST" action="db-settings.php" id="dbSettingsForm">
        <?php echo csrfField(); ?>

        <!-- ===== إعدادات الاتصال ===== -->
        <div class="card" style="margin-bottom:1.5rem; border:2px solid var(--primary, #2563eb); position:relative; overflow:hidden;">
            <div style="position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg, var(--primary, #2563eb), #7c3aed, var(--primary, #2563eb));"></div>
            <div class="card-header" style="background:linear-gradient(135deg, rgba(37,99,235,0.05), rgba(124,58,237,0.05));">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                        <line x1="6" y1="6" x2="6.01" y2="6"/>
                        <line x1="6" y1="18" x2="6.01" y2="18"/>
                    </svg>
                    معطيات الاتصال
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:16px;">

                    <!-- المضيف -->
                    <div>
                        <label for="db_host" style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            المضيف (DB_HOST) <span style="color:#dc2626;">*</span>
                            <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">عنوان خادم MySQL</span>
                        </label>
                        <input type="text" name="db_host" id="db_host" class="form-control"
                               value="<?php echo htmlspecialchars($current_host); ?>"
                               placeholder="localhost" required
                               style="direction:ltr; text-align:right; font-family:monospace;">
                        <small style="color:#94a3b8; font-size:0.75rem;">مثال: localhost أو 127.0.0.1 أو عنوان IP الخادم</small>
                    </div>

                    <!-- اسم قاعدة البيانات -->
                    <div>
                        <label for="db_name" style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            اسم قاعدة البيانات (DB_NAME) <span style="color:#dc2626;">*</span>
                            <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">اسم قاعدة البيانات على الخادم</span>
                        </label>
                        <input type="text" name="db_name" id="db_name" class="form-control"
                               value="<?php echo htmlspecialchars($current_name); ?>"
                               placeholder="inventory_db" required
                               style="direction:ltr; text-align:right; font-family:monospace;">
                        <small style="color:#94a3b8; font-size:0.75rem;">يجب أن تكون قاعدة البيانات موجودة على الخادم</small>
                    </div>

                    <!-- المستخدم -->
                    <div>
                        <label for="db_user" style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            المستخدم (DB_USER) <span style="color:#dc2626;">*</span>
                            <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">اسم مستخدم MySQL</span>
                        </label>
                        <input type="text" name="db_user" id="db_user" class="form-control"
                               value="<?php echo htmlspecialchars($current_user); ?>"
                               placeholder="root" required
                               style="direction:ltr; text-align:right; font-family:monospace;">
                        <small style="color:#94a3b8; font-size:0.75rem;">مستخدم لديه صلاحيات القراءة والكتابة على القاعدة</small>
                    </div>

                    <!-- كلمة المرور -->
                    <div>
                        <label for="db_pass" style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            كلمة المرور (DB_PASS)
                            <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">اتركها كما هي إن لم ترد تغييرها</span>
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="db_pass" id="db_pass" class="form-control"
                                   value="••••••••" placeholder="أدخل كلمة المرور الجديدة"
                                   style="direction:ltr; text-align:right; font-family:monospace; padding-left:40px;"
                                   onfocus="if(this.value==='••••••••')this.value='';"
                                   autocomplete="new-password">
                            <button type="button" onclick="togglePasswordVisibility('db_pass', this)"
                                    style="position:absolute; left:8px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#94a3b8; padding:4px;"
                                    title="إظهار/إخفاء كلمة المرور">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" class="eye-icon">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem;">تُشفَّر تلقائياً بـ AES-256-GCM عند الحفظ</small>
                    </div>
                </div>

                <!-- أزرار الإجراء -->
                <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">

                    <!-- زر اختبار الاتصال -->
                    <button type="submit" name="test_connection" value="1"
                            class="btn" style="background:#7c3aed; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:0.9rem;"
                            onclick="fillHiddenFields();">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                        اختبار الاتصال
                    </button>

                    <!-- زر الحفظ -->
                    <button type="submit" name="save_settings" value="1"
                            class="btn" style="background:var(--primary, #2563eb); color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:0.9rem;"
                            onclick="return confirmSave();">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        حفظ الإعدادات
                    </button>

                    <!-- زر إعادة التعيين -->
                    <button type="button"
                            class="btn" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; padding:10px 20px; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.9rem;"
                            onclick="resetForm();">
                        إعادة تعيين
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- ===== معلومات إضافية ===== -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                معلومات وملاحظات
            </h2>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:16px;">

                <div style="padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
                    <h3 style="font-size:0.9rem; font-weight:700; color:#166534; margin:0 0 8px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        كيف يعمل النظام
                    </h3>
                    <ul style="font-size:0.82rem; color:#166534; margin:0; padding-right:18px; line-height:1.8;">
                        <li>يتم قراءة إعدادات الاتصال من ملف <code style="background:rgba(0,0,0,0.05); padding:1px 6px; border-radius:3px;">.env</code> في المجلد الجذري</li>
                        <li>كلمة المرور تُشفَّر تلقائياً بخوارزمية AES-256-GCM</li>
                        <li>يتم اختبار الاتصال قبل حفظ أي تغييرات</li>
                        <li>يتم إنشاء نسخة احتياطية من ملف .env تلقائياً قبل التعديل</li>
                    </ul>
                </div>

                <div style="padding:14px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px;">
                    <h3 style="font-size:0.9rem; font-weight:700; color:#92400e; margin:0 0 8px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        تنبيهات مهمة
                    </h3>
                    <ul style="font-size:0.82rem; color:#92400e; margin:0; padding-right:18px; line-height:1.8;">
                        <li>بعد حفظ الإعدادات الجديدة، يجب تسجيل الخروج والدخول مجدداً لتطبيق التغييرات</li>
                        <li>إذا كانت قاعدة البيانات الجديدة فارغة، ستحتاج لاستيراد البيانات أو إعادة تثبيت النظام</li>
                        <li>تأكد من أن المستخدم لديه صلاحيات كافية (SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER)</li>
                        <li>لا تُغيّر الإعدادات أثناء عمليات نشطة (مبيعات، مشتريات) لتجنب فقدان البيانات</li>
                    </ul>
                </div>

                <div style="padding:14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px;">
                    <h3 style="font-size:0.9rem; font-weight:700; color:#1e40af; margin:0 0 8px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        مسار ملف .env
                    </h3>
                    <p style="font-size:0.82rem; color:#1e40af; margin:0; line-height:1.8;">
                        <code style="background:rgba(0,0,0,0.05); padding:1px 6px; border-radius:3px; direction:ltr; display:inline-block;"><?php echo htmlspecialchars(realpath(__DIR__ . '/../.env') ?: __DIR__ . '/../.env'); ?></code><br>
                        النسخ الاحتياطية تُحفظ بلاحقة <code style="background:rgba(0,0,0,0.05); padding:1px 6px; border-radius:3px;">.backup.Y-m-d_His</code>
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// إظهار/إخفاء كلمة المرور
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const svg = btn.querySelector('.eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        input.type = 'password';
        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

// تأكيد الحفظ
function confirmSave() {
    const host = document.getElementById('db_host').value;
    const name = document.getElementById('db_name').value;

    return confirm(
        'هل أنت متأكد من حفظ إعدادات الاتصال الجديدة؟\n\n' +
        'المضيف: ' + host + '\n' +
        'قاعدة البيانات: ' + name + '\n\n' +
        'سيتم اختبار الاتصال قبل الحفظ. تحتاج لتسجيل الدخول مجدداً بعد الحفظ.'
    );
}

// إعادة تعيين النموذج
function resetForm() {
    document.getElementById('db_host').value = '<?php echo htmlspecialchars($current_host); ?>';
    document.getElementById('db_name').value = '<?php echo htmlspecialchars($current_name); ?>';
    document.getElementById('db_user').value = '<?php echo htmlspecialchars($current_user); ?>';
    document.getElementById('db_pass').value = '••••••••';
    document.getElementById('db_pass').type = 'password';
}
</script>

<?php require_once '../includes/footer.php'; ?>
