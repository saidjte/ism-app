<?php
/**
 * صفحة تعديل مستخدم — proxy رفيع يفوّض إلى UserService
 * @since v5.25.0 فصل MVC: المنطق في UserService، العرض في templates/users/edit-user.php
 */
require_once '../includes/bootstrap.php';
$page_title = t('users_edit');
requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header("Location: list-users.php");
    exit;
}

if ($_SESSION['user_role'] !== 'admin' && $id != $_SESSION['user_id']) {
    http_response_code(403);
    die(t('no_permission'));
}

$user = UserService::getById($id);
if (!$user) {
    header("Location: list-users.php");
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    try {
        $result = UserService::update($id, $_POST, intval($_SESSION['user_id']), $_SESSION['user_role']);

        // تحديث الجلسة إذا كان المستخدم يعدّل بياناته
        if ($id == $_SESSION['user_id']) {
            $_SESSION['user_name'] = trim($_POST['full_name'] ?? '');
            $_SESSION['user_role'] = $_POST['role'] ?? $_SESSION['user_role'];
        }

        header("Location: list-users.php?msg=updated");
        exit;
    } catch (AppException $e) {
        $errors = [$e->getMessage()];
    } catch (Exception $e) {
        error_log('User Edit Error: ' . $e->getMessage());
        $errors = [t('users_error_updating')];
    }
}

require_once '../includes/header.php';
?>
<div class="container">
    <div class="page-header">
        <h1><?php echo t('users_edit'); ?>: <?php echo htmlspecialchars($user['full_name']); ?></h1>
        <a href="list-users.php" class="btn btn-outline"><?php echo t('back'); ?></a>
    </div>

    <?php require_once __DIR__ . '/../templates/users/edit-user.php'; ?>
</div>

<?php require_once '../includes/footer.php'; ?>