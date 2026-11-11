<?php
// صفحة تسجيل الدخول

// منع التخزين المؤقت (cache) لضمان CSRF صحيح خاصة على الهواتف
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

require_once __DIR__ . '/includes/bootstrap.php';

// إذا كان المستخدم مسجل الدخول بالفعل، إعادة توجيهه
if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$message = '';
$message_type = '';
$success = false;

// معالجة نموذج تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // التحقق من CSRF
    if (!verifyCSRFToken()) {
        // إعادة توليد الرمز بدل منع الدخول (المشكلة شائعة على الهواتف بسبب الـ cache)
        $message = t('login_error_csrf');
        $message_type = 'warning';
    } elseif (empty($username) || empty($password)) {
        $message = t('login_error_empty');
        $message_type = 'error';
    } else {
        $result = attemptLogin($username, $password);

        if ($result['success']) {
            // إعادة توجيه إلى الصفحة المطلوبة أو الرئيسية
            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
            // حماية من ثغرة إعادة التوجيه المفتوح: يبدأ بـ / ولا يبدأ بـ //
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = 'index.php';
            }
            header("Location: $redirect");
            exit;
        } else {
            recordFailedAttempt($username);
            $message = $result['message'];
            $message_type = 'error';
        }
    }
}

// رسائل إضافية من الرابط
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'session_expired') {
        $message = t('login_session_expired');
        $message_type = 'warning';
    } elseif ($_GET['msg'] == 'logged_out') {
        $message = t('login_logged_out');
        $message_type = 'success';
    } elseif ($_GET['msg'] == 'no_permission') {
        $message = t('login_no_permission');
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo lang(); ?>" dir="<?php echo langDir(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#2563eb">
    <title><?php echo t('login_title'); ?> - <?php echo t('app_name'); ?></title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card" <?php echo $message_type === 'error' ? 'class="login-card shake"' : ''; ?>>
            <div class="login-logo">
                <div class="icon-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <h1><?php echo t('app_name'); ?></h1>
                <p><?php echo t('login_subtitle'); ?></p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username"><?php echo t('login_username'); ?></label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="username" id="username" class="form-control" placeholder="<?php echo t('login_username_placeholder'); ?>" required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><?php echo t('login_password'); ?></label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" name="password" id="password" class="form-control" placeholder="<?php echo t('login_password_placeholder'); ?>" required autocomplete="current-password">
                    </div>
                </div>

                <div class="remember-row">
                    <label>
                        <input type="checkbox" name="remember_me" id="remember_me" value="1">
                        <?php echo t('login_remember_me'); ?>
                    </label>
                </div>

                <button type="submit" class="btn btn-login"><?php echo t('login_button'); ?></button>
                <?php echo csrfField(); ?>
            </form>

            <div class="login-footer">
                <p><?php echo t('login_app_footer'); ?> &copy; <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>
</body>
</html>
