<?php
// ملف إلغاء تثبيت التطبيق — حذف قاعدة البيانات بالكامل
// هذا الإجراء نهائي وغير قابل للتراجع!

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';

// 1. التحقق من صلاحية المدير
requireAdmin();

$message = '';
$message_type = '';
$uninstalled = false;

// === الخطوة 1: عرض صفحة التأكيد (GET) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // لا شيء — عرض النموذج فقط
}

// === الخطوة 2: معالجة طلب الإلغاء (POST) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_uninstall'])) {
    // 2.1 التحقق من رمز CSRF
    if (!verifyCSRFToken()) {
        $message = 'خطأ أمني: طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
        $message_type = 'error';
    } else {
        // 2.2 التحقق من إدخال كلمة "UNINSTALL"
        $typed_confirm = trim($_POST['typed_confirm'] ?? '');
        if ($typed_confirm !== 'UNINSTALL') {
            $message = 'لم تتم كتابة كلمة التأكيد بشكل صحيح. يجب كتابة UNINSTALL بالأحرف الكبيرة.';
            $message_type = 'error';
        } else {
            // 2.3 التحقق من كلمة مرور المدير
            $admin_password = $_POST['admin_password'] ?? '';
            $user = fetchOne("SELECT password FROM users WHERE id = ? AND role = 'admin'", [$_SESSION['user_id']]);

            if (!$user || !password_verify($admin_password, $user['password'])) {
                $message = 'كلمة المرور غير صحيحة. لم يتم تنفيذ أي إجراء.';
                $message_type = 'error';
            } else {
                // === تنفيذ إلغاء التثبيت ===
                try {
                    $pdo = getDBConnection();

                    if ($pdo) {
                        // جلب اسم قاعدة البيانات
                        $db_name = DB_NAME;
                        $db_host = DB_HOST;

                        // 2.4 تعطيل المفاتيح الأجنبية مؤقتاً
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                        // 2.5 حذف جميع الجداول
                        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($tables as $table) {
                            $pdo->exec("DROP TABLE IF EXISTS `$table`");
                        }

                        // 2.6 إعادة تفعيل المفاتيح الأجنبية
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                        // 2.7 حذف قاعدة البيانات بالكامل
                        $pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
                    }

                    // 2.8 حذف ملف .env
                    $env_file = __DIR__ . '/.env';
                    if (file_exists($env_file)) {
                        unlink($env_file);
                    }

                    // 2.9 إنشاء ملف علامة الإلغاء (لتجنب فحص قاعدة البيانات عند التحويل)
                    @file_put_contents(__DIR__ . '/.uninstalled', date('Y-m-d H:i:s') . ' - تم إلغاء التثبيت بواسطة: ' . $_SESSION['username']);

                    // 2.10 تسجيل الخروج وتدمير الجلسة
                    logout();

                    $uninstalled = true;
                    $message = 'تم إلغاء تثبيت التطبيق بنجاح. تم حذف قاعدة البيانات وجميع البيانات نهائياً.';
                    $message_type = 'success';

                } catch (Throwable $e) {
                    ErrorHandler::logException($e, 'uninstall');
                    $message = 'حدث خطأ أثناء إلغاء التثبيت: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء إلغاء التثبيت');
                    $message_type = 'error';
                }
            }
        }
    }
}

// المسار الأساسي
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_url = rtrim($script_dir, '/');
if (empty($base_url)) $base_url = '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إلغاء تثبيت التطبيق</title>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
    <style>
        .uninstall-container {
            max-width: 640px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .uninstall-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .uninstall-header h1 {
            font-size: 1.5rem;
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .uninstall-header p {
            color: #64748b;
            margin-top: 0.5rem;
            font-size: 0.95rem;
        }

        /* تحذيرات */
        .warning-box {
            background: #fef2f2;
            border: 2px solid #fca5a5;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .warning-box.danger {
            background: #dc2626;
            border-color: #dc2626;
            color: #fff;
        }

        .warning-box.danger h3 {
            color: #fff;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .warning-box h3 {
            color: #dc2626;
            font-size: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .warning-box ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .warning-box li {
            padding: 6px 0;
            color: #991b1b;
            font-size: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .warning-box.danger li {
            color: #fecaca;
        }

        .warning-box li::before {
            content: "✕";
            font-weight: 700;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* خط فاصل */
        .steps-divider {
            border: none;
            border-top: 2px dashed #e2e8f0;
            margin: 1.5rem 0;
        }

        /* خطوات التأكيد */
        .confirm-steps {
            margin-bottom: 1.5rem;
        }

        .confirm-steps h3 {
            font-size: 1rem;
            color: #334155;
            margin-bottom: 1rem;
        }

        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .step-item:last-child {
            border-bottom: none;
        }

        .step-number {
            background: #dc2626;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .step-text {
            font-size: 0.9rem;
            color: #475569;
            line-height: 1.6;
        }

        .step-text code {
            background: #fee2e2;
            color: #dc2626;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: 700;
            font-size: 0.85rem;
            direction: ltr;
            display: inline-block;
        }

        /* النموذج */
        .uninstall-form {
            background: #fff;
            border: 2px solid #fecaca;
            border-radius: 12px;
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #334155;
            font-size: 0.9rem;
        }

        .form-group .help-text {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
            color: #1e293b;
        }

        .form-control:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-control.is-invalid {
            border-color: #dc2626;
            background: #fef2f2;
        }

        /* أزرار */
        .btn-group {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-danger {
            background: #dc2626;
            color: #fff;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-danger:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        /* رسالة النجاح */
        .success-result {
            text-align: center;
            padding: 2rem;
        }

        .success-result .success-icon {
            width: 80px;
            height: 80px;
            background: #dcfce7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
        }

        .success-result h2 {
            color: #065f46;
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
        }

        .success-result p {
            color: #475569;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

        /* التنبيه */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        /* معلومات النظام */
        .system-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .system-info .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.85rem;
        }

        .system-info .info-label {
            color: #64748b;
        }

        .system-info .info-value {
            color: #1e293b;
            font-weight: 600;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: #dc2626;
            flex-shrink: 0;
        }

        .checkbox-group label {
            font-size: 0.9rem;
            color: #475569;
            cursor: pointer;
            line-height: 1.5;
        }

        /* تجاوب */
        @media (max-width: 480px) {
            .uninstall-container {
                margin: 1rem auto;
                padding: 0 0.5rem;
            }
            .btn-group {
                flex-direction: column;
            }
            .btn {
                width: 100%;
            }
            .uninstall-header h1 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <a href="<?php echo $base_url; ?>/index.php" class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            إدارة المخزون
        </a>
    </div>

    <div class="uninstall-container">
        <?php if ($uninstalled): ?>
            <!-- === نتيجة نجاح الإلغاء === -->
            <div class="success-result">
                <div class="success-icon">🗑️</div>
                <h2>تم إلغاء تثبيت التطبيق</h2>
                <p>تم حذف قاعدة البيانات وجميع البيانات نهائياً. يمكنك إعادة تثبيت التطبيق من جديد.</p>
                <a href="<?php echo $base_url; ?>/install.php" class="btn btn-danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    إعادة التثبيت
                </a>
            </div>
        <?php else: ?>
            <!-- === صفحة التأكيد === -->
            <div class="uninstall-header">
                <h1>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    إلغاء تثبيت التطبيق
                </h1>
                <p>هذا الإجراء نهائي وغير قابل للتراجع — احذر!</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- تحذير رئيسي -->
            <div class="warning-box danger">
                <h3>⚠️ تحذير خطير!</h3>
                <ul>
                    <li>سيتم حذف قاعدة البيانات بالكامل وجميع جداولها</li>
                    <li>سيتم حذف جميع بيانات المنتجات والمبيعات والمشتريات</li>
                    <li>سيتم حذف جميع حسابات المستخدمين</li>
                    <li>سيتم حذف جميع إعدادات النظام</li>
                    <li>لا يمكن التراجع عن هذا الإجراء أبداً</li>
                </ul>
            </div>

            <!-- ما سيتم حذفه -->
            <div class="warning-box">
                <h3>📋 سيتم حذف ما يلي:</h3>
                <ul>
                    <li>قاعدة البيانات <strong><?php echo htmlspecialchars(DB_NAME); ?></strong> بالكامل</li>
                    <li>ملف الإعدادات <strong>.env</strong></li>
                    <li>جميع الجداول: products, sales, purchases, users, system_settings, وغيرها</li>
                </ul>
            </div>

            <div class="system-info">
                <div class="info-row">
                    <span class="info-label">اسم قاعدة البيانات:</span>
                    <span class="info-value"><?php echo htmlspecialchars(DB_NAME); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">خادم قاعدة البيانات:</span>
                    <span class="info-value"><?php echo htmlspecialchars(DB_HOST); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">إصدار التطبيق:</span>
                    <span class="info-value"><?php echo defined('APP_VERSION') ? APP_VERSION : 'غير معروف'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">المستخدم الحالي:</span>
                    <span class="info-value"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?> (مدير النظام)</span>
                </div>
            </div>

            <hr class="steps-divider">

            <!-- خطوات التأكيد -->
            <div class="confirm-steps">
                <h3>🔒 لإكمال الإلغاء، يرجى اتباع الخطوات التالية:</h3>
                <div class="step-item">
                    <span class="step-number">1</span>
                    <span class="step-text">تحديد مربع تأكيد فهم العواقب أدناه</span>
                </div>
                <div class="step-item">
                    <span class="step-number">2</span>
                    <span class="step-text">كتابة كلمة <code>UNINSTALL</code> في حقل التأكيد (بالأحرف الكبيرة الإنجليزية)</span>
                </div>
                <div class="step-item">
                    <span class="step-number">3</span>
                    <span class="step-text">إدخال كلمة مرور حساب المدير الحالي</span>
                </div>
                <div class="step-item">
                    <span class="step-number">4</span>
                    <span class="step-text">الضغط على زر "إلغاء التثبيت نهائياً"</span>
                </div>
            </div>

            <!-- نموذج التأكيد -->
            <div class="uninstall-form">
                <form method="POST" action="" id="uninstallForm" onsubmit="return validateUninstall()">
                    <?php echo csrfField(); ?>

                    <div class="checkbox-group">
                        <input type="checkbox" id="understand_risk" name="understand_risk" required>
                        <label for="understand_risk">أفهم تماماً أن هذا الإجراء سيحذف <strong>جميع البيانات نهائياً</strong> وأنه <strong>لا يمكن التراجع</strong> عنه. أنا المسؤول الوحيد عن هذا القرار.</label>
                    </div>

                    <div class="form-group">
                        <label for="typed_confirm">كتابة كلمة التأكيد</label>
                        <input type="text" id="typed_confirm" name="typed_confirm" class="form-control" placeholder="UNINSTALL" autocomplete="off" dir="ltr" style="text-align:left; letter-spacing: 2px; font-weight: 700;" required>
                        <p class="help-text">اكتب UNINSTALL بالأحرف الكبيرة الإنجليزية للتأكيد</p>
                    </div>

                    <div class="form-group">
                        <label for="admin_password">كلمة مرور المدير</label>
                        <input type="password" id="admin_password" name="admin_password" class="form-control" placeholder="أدخل كلمة مرور المدير" required>
                        <p class="help-text">أدخل كلمة مرور حساب المدير الحالي (<strong><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></strong>) للتأكد من هويتك</p>
                    </div>

                    <div class="btn-group">
                        <button type="submit" name="confirm_uninstall" value="1" class="btn btn-danger" id="uninstallBtn" disabled>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            إلغاء التثبيت نهائياً
                        </button>
                        <a href="<?php echo $base_url; ?>/index.php" class="btn btn-secondary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                            العودة للرئيسية
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    // التحقق من تفعيل زر الإلغاء فقط عند تحديد مربع التأكيد
    const understandCheckbox = document.getElementById('understand_risk');
    const uninstallBtn = document.getElementById('uninstallBtn');

    if (understandCheckbox && uninstallBtn) {
        understandCheckbox.addEventListener('change', function() {
            uninstallBtn.disabled = !this.checked;
        });
    }

    // التحقق من صحة البيانات قبل الإرسال
    function validateUninstall() {
        const typed = document.getElementById('typed_confirm');
        const password = document.getElementById('admin_password');
        const checkbox = document.getElementById('understand_risk');

        // إزالة حالات الخطأ السابقة
        typed.classList.remove('is-invalid');
        password.classList.remove('is-invalid');

        if (!checkbox.checked) {
            alert('يجب تحديد مربع تأكيد فهم العواقب أولاً');
            return false;
        }

        if (typed.value.trim() !== 'UNINSTALL') {
            typed.classList.add('is-invalid');
            alert('يجب كتابة UNINSTALL بالأحرف الكبيرة الإنجليزية');
            typed.focus();
            return false;
        }

        if (!password.value.trim()) {
            password.classList.add('is-invalid');
            alert('يجب إدخال كلمة المرور');
            password.focus();
            return false;
        }

        // تأكيد نهائي
        const confirmed = confirm('⚠️ تأكيد أخير:\n\nسيتم حذف قاعدة البيانات وجميع البيانات نهائياً.\nلا يمكن التراجع عن هذا الإجراء!\n\nهل أنت متأكد تماماً؟');

        if (!confirmed) {
            return false;
        }

        // تأكيد ثانٍ بكتابة "نعم"
        const finalConfirm = prompt('آخر فرصة للتراجع!\n\nاكتب "نعم" للتأكيد النهائي، أو اضغط إلغاء للرجوع:');
        return finalConfirm === 'نعم';
    }
    </script>
</body>
</html>
