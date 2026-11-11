<?php
// صفحة تغيير كلمة المرور الإجباري
require_once __DIR__ . '/../includes/bootstrap.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    header("Location: " . getBaseUrl() . "/login.php");
    exit;
}

// التحقق من أن المستخدم يحتاج لتغيير كلمة المرور
if (empty($_SESSION['must_change_password'])) {
    header("Location: " . getBaseUrl() . "/index.php");
    exit;
}

$message = '';
$message_type = '';
$success = false;

// معالجة نموذج تغيير كلمة المرور
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF
    if (!verifyCSRFToken()) {
        $message = t('login_security_error');
        $message_type = 'error';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = t('login_error_empty');
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) {
            $message = t('users_password_min_length');
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = t('users_confirm_password');
            $message_type = 'error';
        } else {
            // التحقق من كلمة المرور الحالية
            $user = fetchOne("SELECT id, password FROM users WHERE id = ?", [$_SESSION['user_id']]);

            if (!$user || !password_verify($current_password, $user['password'])) {
                $message = t('login_password');
                $message_type = 'error';
            } else {
                // تحديث كلمة المرور
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                executeQuery(
                    "UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?",
                    [$new_hash, $_SESSION['user_id']]
                );

                // مسح مؤشر تغيير كلمة المرور
                unset($_SESSION['must_change_password']);

                // تسجيل النشاط
                addAuditLog('password_change', 'تغيير كلمة المرور (إجباري)', '', 'user', $_SESSION['user_id']);

                $message = t('users_password_changed_success');
                $message_type = 'success';
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#dc2626">
    <title><?php echo t('users_change_password'); ?> - <?php echo t('app_name'); ?></title>
    <link rel="stylesheet" href="../assets/css/change-password.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card <?php echo $message_type === 'error' ? 'shake' : ''; ?>">
            <div class="login-logo">
                <div class="icon-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                </div>
                <h1><?php echo t('users_change_password'); ?></h1>
            </div>

            <div class="warning-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <?php echo t('users_change_password'); ?>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="changePasswordForm">
                <div class="form-group">
                    <label for="current_password"><?php echo t('users_current_password'); ?></label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" name="current_password" id="current_password" class="form-control" placeholder="<?php echo t('users_enter_current_password'); ?>" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password"><?php echo t('users_new_password'); ?></label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                        <input type="password" name="new_password" id="new_password" class="form-control" placeholder="<?php echo t('users_enter_new_password'); ?>" required minlength="6" oninput="checkStrength(this.value)">
                    </div>
                    <div class="password-strength" id="strengthBar">
                        <div class="bar"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><?php echo t('users_confirm_password'); ?></label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="<?php echo t('users_retype_new_password_confirm'); ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" <?php echo $success ? 'disabled' : ''; ?>>
                    <?php echo $success ? t('common_done') : t('users_change_password'); ?>
                </button>
                <?php echo csrfField(); ?>
            </form>

            <div class="login-footer">
                <p><?php echo t('app_name'); ?> &copy; <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>

    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    <?php if ($success): ?>
        setTimeout(function() {
            window.location.href = '<?php echo htmlspecialchars(getBaseUrl()); ?>/index.php';
        }, 1500);
    <?php endif; ?>

    function checkStrength(password) {
        var bar = document.getElementById('strengthBar');
        var barInner = bar.querySelector('.bar');
        bar.className = 'password-strength';

        if (password.length === 0) return;

        var strength = 0;
        if (password.length >= 6) strength++;
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;

        if (strength <= 2) {
            bar.classList.add('strength-weak');
        } else if (strength <= 3) {
            bar.classList.add('strength-medium');
        } else {
            bar.classList.add('strength-strong');
        }
    }
    </script>
</body>
</html>
