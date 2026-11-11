<?php
// صفحة تسجيل الدخول
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

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
        $message = 'خطأ أمني: طلب غير صالح. يرجى تحديث الصفحة.';
        $message_type = 'error';
    } elseif (empty($username) || empty($password)) {
        $message = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        $message_type = 'error';
    } else {
        $result = attemptLogin($username, $password);

        if ($result['success']) {
            // إعادة توجيه إلى الصفحة المطلوبة أو الرئيسية
            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
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
        $message = 'انتهت جلستك بسبب عدم النشاط. يرجى تسجيل الدخول مرة أخرى.';
        $message_type = 'warning';
    } elseif ($_GET['msg'] == 'logged_out') {
        $message = 'تم تسجيل الخروج بنجاح.';
        $message_type = 'success';
    } elseif ($_GET['msg'] == 'no_permission') {
        $message = 'ليس لديك صلاحية الوصول. يرجى تسجيل الدخول.';
        $message_type = 'error';
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
    <meta name="theme-color" content="#2563eb">
    <title>تسجيل الدخول - إدارة المخزون</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #1e3a5f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            direction: rtl;
        }
        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }
        .login-card {
            background: #fff;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 25px 80px rgba(0,0,0,0.35);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-logo .icon-box {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a87);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            box-shadow: 0 8px 25px rgba(30, 58, 95, 0.4);
        }
        .login-logo .icon-box svg {
            width: 40px;
            height: 40px;
            color: #fff;
        }
        .login-logo h1 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .login-logo p {
            color: #64748b;
            font-size: 0.9rem;
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
        .input-wrapper {
            position: relative;
        }
        .input-wrapper svg {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #94a3b8;
            pointer-events: none;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #f8fafc;
            color: #1e293b;
        }
        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
            background: #fff;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            text-decoration: none;
        }
        .btn-login {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            box-shadow: 0 4px 15px rgba(37,99,235,0.4);
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37,99,235,0.5);
        }
        .btn-login:active {
            transform: translateY(0);
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .remember-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        .remember-row label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .remember-row label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
        }
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: #94a3b8;
            font-size: 0.8rem;
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 1.5rem 0;
            color: #cbd5e1;
            font-size: 0.85rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        .shake { animation: shake 0.5s ease-in-out; }
    </style>
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
                <h1>إدارة المخزون</h1>
                <p>سجّل دخولك للمتابعة</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">اسم المستخدم</label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="username" id="username" class="form-control" placeholder="أدخل اسم المستخدم" required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">كلمة المرور</label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" name="password" id="password" class="form-control" placeholder="أدخل كلمة المرور" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn btn-login">تسجيل الدخول</button>
                <?php echo csrfField(); ?>
            </form>

            <div class="login-footer">
                <p>تطبيق إدارة المخزون &copy; <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>
</body>
</html>
