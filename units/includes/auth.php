<?php
// ===== نظام المصادقة والأمان =====

// بدء الجلسة إذا لم تكن بدأت بعد
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * التحقق من تسجيل دخول المستخدم
 * يُستدعى في بداية كل صفحة محمية
 * إذا لم يكن المستخدم مسجل الدخول، يُحوَّل إلى صفحة تسجيل الدخول
 */
function requireLogin() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
        // حفظ الصفحة المطلوبة للعودة إليها بعد تسجيل الدخول
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header("Location: " . getBaseUrl() . "/login.php");
        exit;
    }

    // التحقق من انتهاء الجلسة (30 دقيقة من عدم النشاط)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header("Location: " . getBaseUrl() . "/login.php?msg=session_expired");
        exit;
    }

    // تحديث وقت آخر نشاط
    $_SESSION['last_activity'] = time();

    // إعادة توليد معرف الجلسة لحماية من اختطاف الجلسة
    if (!isset($_SESSION['session_initiated'])) {
        session_regenerate_id(true);
        $_SESSION['session_initiated'] = true;
    }
}

/**
 * التحقق من أن المستخدم مسجل الدخول (بدون تحويل)
 * يُستخدم لعرض/إخفاء عناصر في الواجهة
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * التحقق من أن المستخدم مدير النظام
 */
function requireAdmin() {
    requireLogin();
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        die('ليس لديك صلاحية الوصول إلى هذه الصفحة. هذه الصفحة متاحة للمدير فقط.');
    }
}

/**
 * تسجيل الدخول
 * @return array ['success' => bool, 'message' => string]
 */
function attemptLogin($username, $password) {
    require_once __DIR__ . '/../config/database.php';

    $user = fetchOne("SELECT * FROM users WHERE username = ? AND is_active = 1", [$username]);

    if (!$user) {
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }

    // التحقق من كلمة المرور
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'];
    }

    // التحقق من عدد المحاولات الفاشلة
    if ($user['failed_attempts'] >= 5) {
        $lock_time = strtotime($user['last_failed_attempt']);
        $wait_time = 15 * 60; // 15 دقيقة
        if (time() - $lock_time < $wait_time) {
            $remaining = ceil(($wait_time - (time() - $lock_time)) / 60);
            return ['success' => false, 'message' => 'تم قفل الحساب بسبب محاولات فاشلة متكررة. يرجى المحاولة بعد ' . $remaining . ' دقيقة.'];
        }
        // إعادة تعيين المحاولات بعد انتهاء فترة القفل
        executeQuery("UPDATE users SET failed_attempts = 0, last_failed_attempt = NULL WHERE id = ?", [$user['id']]);
    }

    // تسجيل الدخول بنجاح
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    $_SESSION['session_initiated'] = true;

    // إعادة تعيين المحاولات الفاشلة وتحديث آخر تسجيل دخول
    executeQuery("UPDATE users SET failed_attempts = 0, last_failed_attempt = NULL, last_login = NOW(), login_count = login_count + 1 WHERE id = ?", [$user['id']]);

    // إعادة توليد معرف الجلسة
    session_regenerate_id(true);

    return ['success' => true, 'message' => 'تم تسجيل الدخول بنجاح'];
}

/**
 * تسجيل محاولة فاشلة
 */
function recordFailedAttempt($username) {
    require_once __DIR__ . '/../config/database.php';

    $user = fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
    if ($user) {
        executeQuery("UPDATE users SET failed_attempts = failed_attempts + 1, last_failed_attempt = NOW() WHERE id = ?", [$user['id']]);
    }
}

/**
 * تسجيل الخروج
 */
function logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * الحصول على بيانات المستخدم الحالي
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['user_role']
    ];
}

/**
 * الحصول على اسم الدور بالعربية
 */
function getRoleName($role) {
    $roles = [
        'admin' => 'مدير النظام',
        'manager' => 'مدير',
        'user' => 'مستخدم عادي'
    ];
    return $roles[$role] ?? $role;
}

/**
 * الحصول على لون شارة الدور
 */
function getRoleBadgeClass($role) {
    $classes = [
        'admin' => 'badge-danger',
        'manager' => 'badge-warning',
        'user' => 'badge-info'
    ];
    return $classes[$role] ?? 'badge-info';
}

/**
 * حساب المسار الأساسي للتطبيق (للاستخدام في auth.php)
 */
function getBaseUrl() {
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    if (in_array(basename($script_dir), ['products', 'purchases', 'sales', 'api', 'users', 'settings', 'expenses', 'units', 'categories', 'backups'])) {
        return rtrim(dirname($script_dir), '/');
    }
    return rtrim($script_dir, '/');
}
?>
