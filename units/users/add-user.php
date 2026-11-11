<?php
$page_title = 'إضافة مستخدم جديد';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireAdmin();

$errors = [];
$success = false;

// معالجة النموذج قبل إرسال أي محتوى HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // التحقق من البيانات
    if (empty($username)) $errors[] = 'اسم المستخدم مطلوب';
    elseif (strlen($username) < 3) $errors[] = 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل';

    if (empty($full_name)) $errors[] = 'الاسم الكامل مطلوب';

    if (empty($password)) $errors[] = 'كلمة المرور مطلوبة';
    elseif (strlen($password) < 6) $errors[] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';

    if ($password !== $password_confirm) $errors[] = 'كلمتا المرور غير متطابقتين';

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني غير صحيح';
    }

    if (!in_array($role, ['admin', 'manager', 'user'])) {
        $errors[] = 'الدور غير صحيح';
    }

    // التحقق من عدم تكرار اسم المستخدم
    if (empty($errors)) {
        $existing = fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) $errors[] = 'اسم المستخدم موجود مسبقاً';
    }

    // التحقق من عدم تكرار البريد الإلكتروني
    if (empty($errors) && !empty($email)) {
        $existing_email = fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existing_email) $errors[] = 'البريد الإلكتروني مستخدم مسبقاً';
    }

    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            insertData(
                "INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)",
                [$username, $hashed_password, $full_name, $email ?: null, $role]
            );
            header("Location: list-users.php?msg=added");
            exit;
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'save_user');
            $errors[] = 'حدث خطأ أثناء حفظ المستخدم: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ المستخدم');
        }
    }
}

require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            إضافة مستخدم جديد
        </h1>
        <a href="list-users.php" class="btn btn-outline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
            عرض المستخدمين
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>يرجى تصحيح الأخطاء التالية:</strong>
            <ul style="margin: 5px 20px 0;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>بيانات المستخدم الجديد</h2></div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>اسم المستخدم <span class="required">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="مثال: ahmed" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required minlength="3" maxlength="50">
                        <div class="form-text">3 أحرف على الأقل، يُستخدم لتسجيل الدخول</div>
                    </div>

                    <div class="form-group">
                        <label>الاسم الكامل <span class="required">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="مثال: أحمد محمد" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label>البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control" placeholder="مثال: ahmed@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" maxlength="150">
                        <div class="form-text">اختياري</div>
                    </div>

                    <div class="form-group">
                        <label>الدور <span class="required">*</span></label>
                        <select name="role" class="form-control" required>
                            <option value="user" <?php echo (($_POST['role'] ?? '') == 'user') ? 'selected' : ''; ?>>مستخدم عادي</option>
                            <option value="manager" <?php echo (($_POST['role'] ?? '') == 'manager') ? 'selected' : ''; ?>>مدير</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') == 'admin') ? 'selected' : ''; ?>>مدير النظام</option>
                        </select>
                        <div class="form-text">مدير النظام: صلاحية كاملة | مدير: إدارة البيانات | مستخدم: عرض فقط</div>
                    </div>

                    <div class="form-group">
                        <label>كلمة المرور <span class="required">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="6 أحرف على الأقل" required minlength="6">
                    </div>

                    <div class="form-group">
                        <label>تأكيد كلمة المرور <span class="required">*</span></label>
                        <input type="password" name="password_confirm" class="form-control" placeholder="أعد إدخال كلمة المرور" required minlength="6">
                    </div>
                </div>

                <div style="margin-top: 1.5rem;" class="btn-group">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
                        إنشاء المستخدم
                    </button>
                    <a href="list-users.php" class="btn btn-outline btn-lg">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
