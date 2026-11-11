<?php
$page_title = 'إدارة المستخدمين';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireAdmin();

// === معالجة POST قبل إرسال أي محتوى HTML ===

// حذف مستخدم (POST فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    requireCSRF();
    $delete_id = intval($_POST['user_id'] ?? 0);
    if ($delete_id > 0 && $delete_id != $_SESSION['user_id']) {
        try {
            $pdo = getDBConnection();
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$delete_id]);
            header("Location: list-users.php?msg=deleted");
            exit;
        } catch (Exception $e) {
            $error_msg = 'حدث خطأ أثناء حذف المستخدم';
        }
    } elseif ($delete_id == $_SESSION['user_id']) {
        $error_msg = 'لا يمكنك حذف حسابك الخاص';
    }
}

// تفعيل/تعطيل مستخدم (POST فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_user') {
    requireCSRF();
    $toggle_id = intval($_POST['user_id'] ?? 0);
    if ($toggle_id > 0 && $toggle_id != $_SESSION['user_id']) {
        try {
            $pdo = getDBConnection();
            $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$toggle_id]);
            header("Location: list-users.php?msg=toggled");
            exit;
        } catch (Exception $e) {
            $error_msg = 'حدث خطأ أثناء تحديث حالة المستخدم';
        }
    }
}

// إعادة تعيين كلمة المرور (POST فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    requireCSRF();
    $reset_id = intval($_POST['user_id'] ?? 0);
    if ($reset_id > 0) {
        try {
            // توليد كلمة مرور عشوائية آمنة بدلاً من 123456
            $new_password_plain = bin2hex(random_bytes(4)); // 8 أحرف
            $new_password = password_hash($new_password_plain, PASSWORD_DEFAULT);
            $pdo = getDBConnection();
            $pdo->prepare("UPDATE users SET password = ?, failed_attempts = 0 WHERE id = ?")->execute([$new_password, $reset_id]);
            // تخزين كلمة المرور في الجلسة بدلاً من عرضها في الرابط
            $_SESSION['reset_password_msg'] = 'تم إعادة تعيين كلمة المرور بنجاح. الكلمة الجديدة: ' . $new_password_plain;
            header("Location: list-users.php?msg=reset");
            exit;
        } catch (Exception $e) {
            $error_msg = 'حدث خطأ أثناء إعادة تعيين كلمة المرور';
        }
    }
}

require_once '../includes/header.php';

// رسائل
$success_msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $success_msg = 'تم حذف المستخدم بنجاح';
    if ($_GET['msg'] == 'added') $success_msg = 'تم إضافة المستخدم بنجاح';
    if ($_GET['msg'] == 'updated') $success_msg = 'تم تحديث بيانات المستخدم بنجاح';
    if ($_GET['msg'] == 'toggled') $success_msg = 'تم تحديث حالة المستخدم بنجاح';
    if ($_GET['msg'] == 'reset') {
        $success_msg = $_SESSION['reset_password_msg'] ?? 'تم إعادة تعيين كلمة المرور بنجاح';
        unset($_SESSION['reset_password_msg']);
    }
}

// جلب المستخدمين
$users = fetchAll("SELECT * FROM users ORDER BY created_at DESC");
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            إدارة المستخدمين
        </h1>
        <a href="add-user.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
            إضافة مستخدم جديد
        </a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card blue">
            <div class="stat-info"><h3>إجمالي المستخدمين</h3><div class="stat-value"><?php echo count($users); ?></div></div>
        </div>
        <div class="stat-card green">
            <div class="stat-info"><h3>المستخدمون النشطون</h3><div class="stat-value"><?php echo count(array_filter($users, fn($u) => $u['is_active'] == 1)); ?></div></div>
        </div>
        <div class="stat-card red">
            <div class="stat-info"><h3>المستخدمون المعطّلون</h3><div class="stat-value"><?php echo count(array_filter($users, fn($u) => $u['is_active'] == 0)); ?></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>قائمة المستخدمين</h2></div>
        <div class="card-body">
            <?php if (empty($users)): ?>
                <p class="text-muted" style="text-align:center; padding: 2rem;">لا يوجد مستخدمين بعد.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم المستخدم</th>
                            <th>الاسم الكامل</th>
                            <th>البريد الإلكتروني</th>
                            <th>الدور</th>
                            <th>الحالة</th>
                            <th>آخر دخول</th>
                            <th>مرات الدخول</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($users as $user): ?>
                        <tr style="<?php echo $user['is_active'] == 0 ? 'opacity:0.5;' : ''; ?>">
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                    <span class="badge badge-success" style="font-size:0.65rem;">أنت</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                            <td><span class="badge <?php echo getRoleBadgeClass($user['role']); ?>"><?php echo getRoleName($user['role']); ?></span></td>
                            <td>
                                <?php if ($user['is_active'] == 1): ?>
                                    <span class="badge badge-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">معطّل</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem;"><?php echo $user['last_login'] ?? 'لم يسجل دخول بعد'; ?></td>
                            <td><?php echo $user['login_count']; ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="edit-user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary">تعديل</a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد؟')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_user">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $user['is_active'] == 1 ? 'btn-warning' : 'btn-success'; ?>">
                                                <?php echo $user['is_active'] == 1 ? 'تعطيل' : 'تفعيل'; ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('سيتم توليد كلمة مرور عشوائية جديدة. متأكد؟')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline">إعادة كلمة المرور</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم نهائياً؟')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
