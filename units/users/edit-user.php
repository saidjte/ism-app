<?php
$page_title = 'تعديل المستخدم';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header("Location: list-users.php");
    exit;
}

// المستخدم العادي يمكنه تعديل حسابه فقط، المدير يمكنه تعديل الكل
if ($_SESSION['user_role'] !== 'admin' && $id != $_SESSION['user_id']) {
    http_response_code(403);
    die('ليس لديك صلاحية تعديل هذا المستخدم.');
}

$user = fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
if (!$user) {
    header("Location: list-users.php");
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? $user['role'];
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // التحقق من البيانات
    if (empty($full_name)) $errors[] = 'الاسم الكامل مطلوب';

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني غير صحيح';
    }

    if (!in_array($role, ['admin', 'manager', 'user'])) {
        $errors[] = 'الدور غير صحيح';
    }

    // التحقق من كلمة المرور (فقط إذا تم إدخال واحدة)
    if (!empty($password)) {
        if (strlen($password) < 6) {
            $errors[] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        }
        if ($password !== $password_confirm) {
            $errors[] = 'كلمتا المرور غير متطابقتين';
        }
    }

    // التحقق من عدم تكرار البريد الإلكتروني
    if (empty($errors) && !empty($email)) {
        $existing_email = fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id]);
        if ($existing_email) $errors[] = 'البريد الإلكتروني مستخدم من قبل مستخدم آخر';
    }

    // منع المدير من إزالة صلاحية الإدارة عن نفسه
    if (empty($errors) && $id == $_SESSION['user_id'] && $role !== 'admin') {
        $errors[] = 'لا يمكنك تغيير دورك الخاص من مدير النظام';
    }

    // المستخدمون غير المديرين لا يمكنهم تغيير الدور
    if ($_SESSION['user_role'] !== 'admin' && $role !== $user['role']) {
        $role = $user['role']; // إبقاء الدور كما هو
    }

    if (empty($errors)) {
        try {
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                executeQuery(
                    "UPDATE users SET full_name = ?, email = ?, role = ?, password = ? WHERE id = ?",
                    [$full_name, $email ?: null, $role, $hashed_password, $id]
                );
            } else {
                executeQuery(
                    "UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?",
                    [$full_name, $email ?: null, $role, $id]
                );
            }

            // تحديث الجلسة إذا كان المستخدم يعدّل بياناته
            if ($id == $_SESSION['user_id']) {
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_role'] = $role;
            }

            header("Location: list-users.php?msg=updated");
            exit;
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'update_user');
            $errors[] = 'حدث خطأ أثناء التحديث: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء التحديث');
        }
    }
}

require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>تعديل المستخدم: <?php echo htmlspecialchars($user['full_name']); ?></h1>
        <a href="list-users.php" class="btn btn-outline">العودة للقائمة</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>بيانات المستخدم</h2></div>
        <div class="card-body">
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>اسم المستخدم</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly style="background:#f1f5f9; color:#64748b; cursor:not-allowed;">
                        <div class="form-text">لا يمكن تغيير اسم المستخدم</div>
                    </div>

                    <div class="form-group">
                        <label>الاسم الكامل <span class="required">*</span></label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>الدور <span class="required">*</span></label>
                        <select name="role" class="form-control" required <?php echo ($id == $_SESSION['user_id'] || $_SESSION['user_role'] !== 'admin') ? 'disabled' : ''; ?>>
                            <option value="user" <?php echo $user['role'] == 'user' ? 'selected' : ''; ?>>مستخدم عادي</option>
                            <option value="manager" <?php echo $user['role'] == 'manager' ? 'selected' : ''; ?>>مدير</option>
                            <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>مدير النظام</option>
                        </select>
                        <?php if ($id == $_SESSION['user_id']): ?>
                            <div class="form-text" style="color:#d97706;">لا يمكنك تغيير دورك الخاص</div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>كلمة المرور الجديدة</label>
                        <input type="password" name="password" class="form-control" placeholder="اتركها فارغة إذا لم ترد تغييرها" minlength="6">
                        <div class="form-text">اتركها فارغة للإبقاء على كلمة المرور الحالية</div>
                    </div>

                    <div class="form-group">
                        <label>تأكيد كلمة المرور الجديدة</label>
                        <input type="password" name="password_confirm" class="form-control" placeholder="أعد إدخال كلمة المرور الجديدة">
                    </div>
                </div>

                <div style="margin-top:1.5rem;" class="btn-group">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-primary btn-lg">حفظ التعديلات</button>
                    <a href="list-users.php" class="btn btn-outline btn-lg">إلغاء</a>
                </div>
            </form>
        </div>
    </div>

    <!-- معلومات إضافية عن المستخدم -->
    <div class="card" style="margin-top:2rem;">
        <div class="card-header"><h2>معلومات النظام</h2></div>
        <div class="card-body">
            <div class="form-grid">
                <div><strong>تاريخ التسجيل:</strong> <?php echo $user['created_at']; ?></div>
                <div><strong>آخر تحديث:</strong> <?php echo $user['updated_at']; ?></div>
                <div><strong>آخر تسجيل دخول:</strong> <?php echo $user['last_login'] ?? 'لم يسجل دخول بعد'; ?></div>
                <div><strong>عدد مرات الدخول:</strong> <?php echo $user['login_count']; ?></div>
                <div><strong>المحاولات الفاشلة:</strong> <?php echo $user['failed_attempts']; ?></div>
                <div><strong>الحالة:</strong>
                    <?php if ($user['is_active'] == 1): ?>
                        <span class="badge badge-success">نشط</span>
                    <?php else: ?>
                        <span class="badge badge-danger">معطّل</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
