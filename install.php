<?php
// ملف تثبيت التطبيق - إنشاء قاعدة البيانات والجداول
header('Content-Type: text/html; charset=utf-8');

// تحميل الملفات المطلوبة أولاً (لا تحتاج قاعدة بيانات)
require_once __DIR__ . '/config/upgrades.php';
require_once __DIR__ . '/includes/csrf.php';

// منع إعادة التثبيت بعد التثبيت الأولي
// التحقق من أن التطبيق مثبت بالفعل: يجب أن يوجد ملف .env + اتصال ناجح + جداول محملة
$already_installed = false;
$env_file = __DIR__ . '/.env';

if (file_exists($env_file) && filesize($env_file) > 50) {
    try {
        @require_once __DIR__ . '/config/database.php';
        if (function_exists('getDBConnection')) {
            $pdo = getDBConnection();
            if ($pdo) {
                $result = $pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
                if ($result > 0) {
                    $already_installed = true;
                }
            }
        }
    } catch (Throwable $e) {
        // أي خطأ (استثناء أو خطأ PHP) = السماح بالتثبيت
        $already_installed = false;
    }
}

if ($already_installed) {
    header("Location: index.php");
    exit;
}

$message = '';
$success = false;

// القيم الافتراضية
$db_host = $_POST['db_host'] ?? 'localhost';
$db_user = $_POST['db_user'] ?? 'root';
$db_pass = $_POST['db_pass'] ?? '';
$db_name = $_POST['db_name'] ?? 'inventory_db';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    try {
        // الاتصال بـ MySQL بدون تحديد قاعدة بيانات
        $pdo = new PDO(
            "mysql:host=" . $db_host . ";charset=utf8mb4",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // إنشاء قاعدة البيانات
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db_name`");

        // التحقق مما إذا كان التطبيق مثبتاً مسبقاً
        $current_version = getCurrentDBVersion($pdo);
        if (compareVersions($current_version, '0.0.0') > 0) {
            // التطبيق مثبت مسبقاً - تحويل إلى صفحة التحديث
            $success = true;
            $message = 'التطبيق مثبت مسبقاً (الإصدار ' . $current_version . '). يرجى استخدام صفحة التحديث.';
        } else {
            // تثبيت جديد - تطبيق جميع التحديثات بالترتيب
            foreach ($DB_UPDATES as $update) {
                $version = $update['version'];
                // بناء اسم الدالة من الإصدار: 4.9.87 → db_upgrade_4_9_87
                $func = 'db_upgrade_' . str_replace('.', '_', $version);
                if (function_exists($func)) {
                    call_user_func($func, $pdo);
                }
                updateDBVersion($pdo, $version);
            }

            // تحديث الإصدار النهائي
            updateDBVersion($pdo, APP_VERSION);

            // حفظ إعدادات الاتصال في ملف .env
            $env_content = "# إعدادات قاعدة البيانات\n";
            $env_content .= "# تم إنشاؤه تلقائياً بواسطة معلّم التثبيت في " . date('Y-m-d H:i:s') . "\n";
            $env_content .= "DB_HOST=" . $db_host . "\n";
            $env_content .= "DB_NAME=" . $db_name . "\n";
            $env_content .= "DB_USER=" . $db_user . "\n";
            $env_content .= "DB_PASS=" . $db_pass . "\n";
            
            file_put_contents(__DIR__ . '/.env', $env_content);

            // إنشاء المستخدم الافتراضي (مدير النظام)
            // التحقق مما إذا كان التحديث 3.0.0 يُنشئ المستخدمين تلقائياً
            try {
                $admin_exists = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                if ($admin_exists == 0) {
                    $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, password_changed_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute(['admin', $admin_pass, 'مدير النظام', 'admin@example.com', 'admin']);
                }
            } catch (Throwable $e) {
                // جدول المستخدمين قد لا يكون موجوداً بعد - سيتم إنشاؤه عند التحديث
            }

            $success = true;
            $message = 'تم تثبيت التطبيق بنجاح! الإصدار: ' . APP_VERSION;
        }

    } catch (PDOException $e) {
        // لا نكشف تفاصيل خطأ قاعدة البيانات للمستخدم
        $message = 'حدث خطأ أثناء التثبيت. تأكد من صحة بيانات الاتصال بقاعدة البيانات وحاول مرة أخرى.';
        // تسجيل الخطأ الحقيقي في السجلات
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents($logDir . '/install-error.log', date('Y-m-d H:i:s') . ' — ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تثبيت التطبيق</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            direction: rtl;
        }
        .install-card {
            background: #fff;
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 550px;
            width: 100%;
        }
        h1 { text-align: center; font-size: 1.5rem; margin-bottom: 0.5rem; color: #1e293b; }
        .subtitle { text-align: center; color: #64748b; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #334155; font-size: 0.9rem; }
        .form-control {
            width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px;
            font-size: 0.95rem; transition: all 0.2s; background: #fff; color: #1e293b;
        }
        .form-control:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 480px) { .form-grid { grid-template-columns: 1fr; } }
        .btn {
            display: block; width: 100%; padding: 12px; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s;
            text-align: center; text-decoration: none;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #059669; color: #fff; }
        .btn-success:hover { background: #047857; }
        .btn-outline { background: transparent; color: #2563eb; border: 2px solid #2563eb; }
        .btn-outline:hover { background: #2563eb; color: #fff; }
        .alert {
            padding: 14px 18px; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .install-info { margin-top: 2rem; }
        .install-info h3 { font-size: 1rem; margin-bottom: 0.75rem; color: #334155; }
        .install-info ul { list-style: none; padding: 0; }
        .install-info li { padding: 4px 0; color: #475569; font-size: 0.85rem; }
        .install-info li strong {
            color: #1e293b; font-family: monospace; background: #f1f5f9;
            padding: 2px 6px; border-radius: 4px; margin-left: 4px;
        }
        .version-tag { 
            display: inline-block; background: #dbeafe; color: #1d4ed8; 
            font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; 
            font-weight: 600; font-family: monospace; margin-right: 6px;
        }
        .divider { border-top: 1px solid #e2e8f0; margin: 1.5rem 0; }
    </style>
</head>
<body>
    <div class="install-card">
        <h1>📦 تثبيت تطبيق إدارة المخزون</h1>
        <p class="subtitle">
            الإصدار <span class="version-tag"><?php echo APP_VERSION; ?></span>
            — أدخل بيانات الاتصال بقاعدة البيانات MySQL
        </p>

        <?php if ($message): ?>
            <div class="alert <?php echo $success ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <a href="index.php" class="btn btn-success">🚀 الدخول للتطبيق</a>
            <?php if (isset($current_version) && compareVersions($current_version, '0.0.0') > 0): ?>
            <div class="divider"></div>
            <a href="upgrade.php" class="btn btn-outline">🔄 تحديث النظام إلى أحدث إصدار</a>
            <?php endif; ?>
        <?php else: ?>
            <form method="POST" action="" data-validate>
                <div class="form-grid">
                    <div class="form-group">
                        <label>خادم قاعدة البيانات</label>
                        <input type="text" name="db_host" class="form-control" value="<?php echo htmlspecialchars($db_host); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>اسم قاعدة البيانات</label>
                        <input type="text" name="db_name" class="form-control" value="<?php echo htmlspecialchars($db_name); ?>" required>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>اسم المستخدم</label>
                        <input type="text" name="db_user" class="form-control" value="<?php echo htmlspecialchars($db_user); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>كلمة المرور</label>
                        <input type="password" name="db_pass" class="form-control" value="<?php echo htmlspecialchars($db_pass); ?>" placeholder="أدخل كلمة المرور">
                    </div>
                </div>

                <div class="divider"></div>

                <?php echo csrfField(); ?>
                <button type="submit" name="install" value="1" class="btn btn-primary">⚙️ تثبيت الآن</button>
            </form>
        <?php endif; ?>

        <div class="install-info">
            <h3>ما سيتم إنشاؤه:</h3>
            <ul>
                <li><strong>products</strong> — جدول المنتجات</li>
                <li><strong>additional_units</strong> — وحدات القياس الإضافية</li>
                <li><strong>price_history</strong> — سجل تغيير الأسعار</li>
                <li><strong>purchases</strong> — جدول المشتريات</li>
                <li><strong>sales</strong> — جدول المبيعات (مع حساب الربح)</li>
                <li><strong>inventory_counts</strong> — جدول جرد المخزون</li>
                <li><strong>system_settings</strong> — إعدادات النظام ورقم الإصدار</li>
                <li><strong>users</strong> — جدول المستخدمين والمصادقة</li>
            </ul>
            <h3 style="margin-top:1rem;">بيانات الدخول الافتراضية:</h3>
            <ul>
                <li><strong>اسم المستخدم:</strong> admin</li>
                <li><strong>كلمة المرور:</strong> admin123</li>
            </ul>
        </div>
    </div>
</body>
</html>
