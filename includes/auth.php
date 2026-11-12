<?php
/**
 * ===== نظام المصادقة والأمان =====
 * 
 * فئة AppAuth (OOP) + دوال إجرائية للتوافق مع الكود القديم
 * 
 * ⚠️ ملاحظة: لا نستخدم namespace App لأنها تسبب مشاكل على Windows (NTFS)
 * الفئة مُسماة AppAuth مباشرة في المساحة العامة
 */

// ⚠️ تأمين: تحميل فئة قاعدة البيانات ودوال التوافق إذا لم تكن محملة بعد
if (!class_exists('AppDatabase')) {
    require_once __DIR__ . '/Database.php';
}
if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/ErrorHandler.php'; }
if (!function_exists('fetchOne')) {
    require_once __DIR__ . '/../config/database.php';
}

// بدء الجلسة مع الإعدادات الأمنية المركزية
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

/**
 * فئة المصادقة والأمان
 */
class AppAuth
{
    // ===== دوال تقييد IP =====

    /**
     * التحقق من عدد المحاولات الفاشلة من عنوان IP الحالي
     */
    public static function checkIPRateLimit(): array {
        $ip = self::getClientIP();
        $max_attempts = 15;
        $block_minutes = 30;

        // === التحقق من قاعدة البيانات أولاً (موثوق — لا يمكن تجاوزه) ===
        try {
            $row = fetchOne("SELECT attempts, blocked_until FROM ip_rate_limits WHERE ip = ?", [$ip]);
            if ($row) {
                $attempts = intval($row['attempts']);
                $blocked_until = intval($row['blocked_until']);

                if ($blocked_until > 0 && time() < $blocked_until) {
                    $remaining = ceil(($blocked_until - time()) / 60);
                    return [
                        'allowed' => false,
                        'message' => t('auth_ip_blocked') . ' ' . $remaining . ' ' . t('auth_minute')
                    ];
                }

                if ($attempts >= $max_attempts && $blocked_until > 0 && time() >= $blocked_until) {
                    // انتهت فترة الحظر — إعادة تعيين
                    executeQuery("UPDATE ip_rate_limits SET attempts = 0, blocked_until = NULL, last_attempt = NULL WHERE ip = ?", [$ip]);
                    return ['allowed' => true, 'message' => ''];
                }

                if ($attempts >= $max_attempts) {
                    $new_blocked = time() + ($block_minutes * 60);
                    executeQuery("UPDATE ip_rate_limits SET blocked_until = ? WHERE ip = ?", [$new_blocked, $ip]);
                    $remaining = ceil(($block_minutes));
                    return [
                        'allowed' => false,
                        'message' => t('auth_ip_blocked') . ' ' . $remaining . ' ' . t('auth_minute')
                    ];
                }
            }
        } catch (\Throwable $e) { ErrorHandler::logException($e, 'auth::checkIPRateLimit'); }

        return ['allowed' => true, 'message' => ''];
    }

    /**
     * تسجيل محاولة فاشلة من عنوان IP
     */
    public static function recordIPFailedAttempt($username): void {
        $ip = self::getClientIP();

        // === تسجيل في قاعدة البيانات (موثوق — لا يمكن تجاوزه) ===
        try {
            $row = fetchOne("SELECT id, attempts FROM ip_rate_limits WHERE ip = ?", [$ip]);
            if ($row) {
                executeQuery("UPDATE ip_rate_limits SET attempts = attempts + 1, last_attempt = NOW(), username = ? WHERE ip = ?", [$username, $ip]);
            } else {
                executeQuery("INSERT INTO ip_rate_limits (ip, attempts, last_attempt, username) VALUES (?, 1, NOW(), ?)", [$ip, $username]);
            }
        } catch (\Throwable $e) {
            // Fallback: تخزين مؤقت في الجلسة
            $ip_key = 'ip_failed_' . md5($ip);
            $ip_data = $_SESSION[$ip_key] ?? ['attempts' => 0, 'last_attempt' => 0, 'blocked_until' => 0];
            $ip_data['attempts'] = intval($ip_data['attempts']) + 1;
            $ip_data['last_attempt'] = time();
            $_SESSION[$ip_key] = $ip_data;
        }
    }

    /**
     * مسح محاولات IP الفاشلة عند تسجيل الدخول بنجاح
     */
    public static function clearIPRateLimit(): void {
        $ip = self::getClientIP();
        $ip_key = 'ip_failed_' . md5($ip);
        unset($_SESSION[$ip_key]);

        // مسح من قاعدة البيانات أيضاً
        try {
            executeQuery("DELETE FROM ip_rate_limits WHERE ip = ?", [$ip]);
        } catch (\Throwable $e) { ErrorHandler::logException($e, 'auth::clearIPRateLimit'); }
    }


    /**
     * قائمة عناوين الوكلاء العكسيين الموثوقين (Trusted Proxies)
     * يمكن تهيئتها من إعدادات النظام أو تعديلها حسب البنية التحتية
     * 
     * أمثلة:
     *   - '127.0.0.1' — وكيل محلي (nginx/apache على نفس الخادم)
     *   - '::1' — localhost بـ IPv6
     *   - '10.0.0.1' — وكيل على شبكة داخلية
     *   - '172.16.0.0/12' — نطاق شبكة خاصة (يتطلب تحقق CIDR)
     */
    private static ?array $trustedProxies = null;

    /**
     * الحصول على قائمة الوكلاء الموثوقين
     * يتم قراءتها من إعدادات النظام مع قيم افتراضية آمنة
     */
    private static function getTrustedProxies(): array {
        if (self::$trustedProxies !== null) {
            return self::$trustedProxies;
        }

        // القيم الافتراضية: الوكلاء المحليون فقط
        self::$trustedProxies = ['127.0.0.1', '::1'];

        // محاولة قراءة الوكلاء الموثوقين من إعدادات النظام
        try {
            if (function_exists('getSetting')) {
                $proxiesSetting = getSetting('trusted_proxies', '');
                if (!empty($proxiesSetting)) {
                    $proxies = array_filter(array_map('trim', explode(',', $proxiesSetting)));
                    if (!empty($proxies)) {
                        self::$trustedProxies = $proxies;
                    }
                }
            }
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'auth::getTrustedProxies');
            // استخدام القيم الافتراضية عند أي خطأ
        }

        return self::$trustedProxies;
    }

    /**
     * التحقق مما إذا كان الطلب قادماً من وكيل عكسي موثوق
     */
    private static function isTrustedProxy(string $remoteAddr): bool {
        $trustedProxies = self::getTrustedProxies();

        foreach ($trustedProxies as $proxy) {
            // مطابقة مباشرة لعنوان IP
            if ($proxy === $remoteAddr) {
                return true;
            }

            // دعم التحقق من نطاقات CIDR (مثل 172.16.0.0/12 أو 10.0.0.0/8)
            if (strpos($proxy, '/') !== false) {
                try {
                    [$subnet, $bits] = explode('/', $proxy, 2);
                    $bits = intval($bits);
                    if ($bits < 0 || $bits > 32) continue;

                    $remote = ip2long($remoteAddr);
                    $net = ip2long($subnet);
                    if ($remote === false || $net === false) continue;

                    $mask = -1 << (32 - $bits);
                    if (($remote & $mask) === ($net & $mask)) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        return false;
    }

    /**
     * الحصول على عنوان IP الحالي للعميل
     * تم الإصلاح: عدم الثقة برؤوس X-Forwarded-For إلا من وكلاء موثوقين
     * 
     * الآلية:
     * 1. نبدأ بـ REMOTE_ADDR (الموثوق دائماً)
     * 2. إذا كان REMOTE_ADDR وكيل موثوق، نقرأ X-Forwarded-For
     * 3. نأخذ أول عنوان IP غير موثوق من السلسلة (العميل الحقيقي)
     * 4. لا نثق أبداً بـ HTTP_CLIENT_IP (رأس غير قياسي وسهل التزوير)
     */
    public static function getClientIP(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // التحقق من أن REMOTE_ADDR هو وكيل موثوق
        if (self::isTrustedProxy($remoteAddr)) {
            // قراءة سلسلة X-Forwarded-For فقط إذا كان الطلب من وكيل موثوق
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                // البحث عن أول عنوان IP غير موثوق من اليمين (أقرب إلى العميل)
                // هذا يمنع المهاجم من حقن عناوين IP مزيفة
                for ($i = count($ips) - 1; $i >= 0; $i--) {
                    $ip = trim($ips[$i]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        // إذا كان هذا IP ليس وكيلاً موثوقاً، فهو العميل الحقيقي
                        if (!self::isTrustedProxy($ip)) {
                            return $ip;
                        }
                    }
                }
                // إذا كانت جميع IPs في السلسلة وكلاء موثوقين، نأخذ الأول
                $firstIp = trim($ips[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }
        }

        // HTTP_CLIENT_IP لم يعد موثوقاً — يتم تجاهله نهائياً
        // كان يستخدم رأساً غير قياسياً يمكن تزويره بسهولة

        return $remoteAddr;
    }

    /**
     * التحقق من ميزة "تذكرني" عبر ملفات تعريف الارتباط
     */
    public static function checkRememberMe(): void {
        if (isset($_SESSION['user_id'])) return;

        if (!isset($_COOKIE['remember_selector']) || !isset($_COOKIE['remember_token'])) return;

        require_once __DIR__ . '/../config/database.php';

        $selector = $_COOKIE['remember_selector'];
        $token = $_COOKIE['remember_token'];

        try {
            $row = fetchOne(
                "SELECT ut.*, u.id as user_id, u.username, u.full_name, u.role, u.is_active 
                 FROM user_tokens ut 
                 JOIN users u ON ut.user_id = u.id 
                 WHERE ut.selector = ? AND ut.expires_at > NOW()",
                [$selector]
            );

            if (!$row) return;
            if (!hash_equals($row['token'], hash('sha256', $token))) return;
            if (!$row['is_active']) return;

            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['user_role'] = $row['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();
            $_SESSION['session_initiated'] = true;
            $_SESSION['login_time'] = time();

            $new_token = bin2hex(random_bytes(32));
            executeQuery(
                "UPDATE user_tokens SET token = ? WHERE selector = ?",
                [hash('sha256', $new_token), $selector]
            );

            // تم الإصلاح: إضافة Secure flag عند HTTPS لتدوير رمز "تذكرني"
            $isHttpsRemember = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
            setcookie('remember_token', $new_token, [
                'expires' => strtotime('+30 days'),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => $isHttpsRemember,
            ]);

            executeQuery("UPDATE users SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?", [$row['user_id']]);

            require_once __DIR__ . '/audit_log.php';
            addAuditLog('login', t('auth_auto_login_remember'), '', 'user', $row['user_id']);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'auth::checkRememberMe');
            // Ignore errors — including PDOException when database tables don't exist
        }
    }

    /**
     * التحقق من تسجيل دخول المستخدم
     */
    public static function requireLogin(): void {
        self::checkRememberMe();

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header("Location: " . self::getBaseUrl() . "/login.php");
            exit;
        }

        try {
            self::requirePasswordChange();
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'auth::requireLogin_passwordChange');
            // في حالة خطأ قاعدة البيانات (مثل عدم وجود جدول users)،
            // لا نوقف التطبيق — نتابع بشكل طبيعي مع تجاهل فحص كلمة المرور
        }

        // v6.3.4: استخدام الدالة المركزية getSessionTimeout() من config/session.php
        // (توحيد المهلة في كل التطبيق — كان هناك نسخة محلية مكررة من نفس المنطق)
        $session_timeout_seconds = getSessionTimeout();

        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_timeout_seconds) {
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header("Location: " . self::getBaseUrl() . "/login.php?msg=session_expired");
            exit;
        }

        $_SESSION['last_activity'] = time();

        if (!isset($_SESSION['session_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['session_initiated'] = true;
        }
    }

    /**
     * التحقق من أن المستخدم مسجل الدخول (بدون تحويل)
     */
    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * التحقق من أن المستخدم مدير النظام
     */
    public static function requireAdmin(): void {
        self::requireLogin();
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            http_response_code(403);
            die(t('auth_admin_only_page'));
        }
    }

    /**
     * تسجيل الدخول
     */
    public static function attemptLogin($username, $password): array {
        require_once __DIR__ . '/../config/database.php';

        $ipCheck = self::checkIPRateLimit();
        if (!$ipCheck['allowed']) {
            return ['success' => false, 'message' => $ipCheck['message'], 'ip_blocked' => true];
        }

        $user = fetchOne("SELECT * FROM users WHERE username = ? AND is_active = 1", [$username]);

        if (!$user) {
            return ['success' => false, 'message' => t('auth_invalid_credentials')];
        }

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => t('auth_invalid_credentials')];
        }

        if ($user['failed_attempts'] >= 5) {
            $lock_time = strtotime($user['last_failed_attempt']);
            $wait_time = 15 * 60;
            if (time() - $lock_time < $wait_time) {
                $remaining = ceil(($wait_time - (time() - $lock_time)) / 60);
                return ['success' => false, 'message' => t('auth_account_locked') . ' ' . $remaining . ' ' . t('auth_minute')];
            }
            executeQuery("UPDATE users SET failed_attempts = 0, last_failed_attempt = NULL WHERE id = ?", [$user['id']]);
        }

        self::clearIPRateLimit();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        $_SESSION['session_initiated'] = true;

        executeQuery("UPDATE users SET failed_attempts = 0, last_failed_attempt = NULL, last_login = NOW(), login_count = login_count + 1, password_changed_at = COALESCE(password_changed_at, created_at, NOW()) WHERE id = ?", [$user['id']]);

        session_regenerate_id(true);

        if (isset($_POST['remember_me']) && $_POST['remember_me'] === '1') {
            $token = bin2hex(random_bytes(32));
            $selector = bin2hex(random_bytes(8));
            $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

            try {
                executeQuery(
                    "INSERT INTO user_tokens (user_id, selector, token, expires_at) VALUES (?, ?, ?, ?)",
                    [$user['id'], $selector, hash('sha256', $token), $expires]
                );

                // تحديد إعدادات الكوكيز الآمنة (مع Secure flag عند HTTPS)
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
            $cookieOptions = [
                'expires' => strtotime('+30 days'),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => $isHttps,
            ];
            setcookie('remember_selector', $selector, $cookieOptions);
            setcookie('remember_token', $token, $cookieOptions);
            } catch (\Exception $e) {
                ErrorHandler::logException($e, 'auth::attemptLogin_rememberMe');
                // Ignore - login still works without remember me
            }
        }

        if (!empty($user['must_change_password'])) {
            $_SESSION['must_change_password'] = true;
        }

        // فحص انتهاء صلاحية كلمة المرور بناءً على فترة الإعدادات
        if (empty($_SESSION['must_change_password'])) {
            self::checkPasswordExpiryForUser($user);
        }

        require_once __DIR__ . '/audit_log.php';
        addAuditLog('login', t('auth_login_successful_audit'), '', 'user', $user['id']);

        return ['success' => true, 'message' => t('auth_login_success')];
    }

    /**
     * تسجيل محاولة فاشلة
     */
    public static function recordFailedAttempt($username): void {
        require_once __DIR__ . '/../config/database.php';

        $user = fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($user) {
            executeQuery("UPDATE users SET failed_attempts = failed_attempts + 1, last_failed_attempt = NOW() WHERE id = ?", [$user['id']]);
        }

        require_once __DIR__ . '/audit_log.php';
        addAuditLog('login_failed', 'محاولة دخول فاشلة للمستخدم: ' . $username, '', 'user', $user ? $user['id'] : 0);

        self::recordIPFailedAttempt($username);
    }

    /**
     * تسجيل الخروج
     */
    public static function logout(): void {
        $user_id = $_SESSION['user_id'] ?? 0;
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/audit_log.php';
        addAuditLog('logout', t('auth_logout_audit'), '', 'user', $user_id);

        // تم الإصلاح: حساب $isHttps داخل logout() بدلاً من الاعتماد على متغير غير معرّف
        // كان $isHttps معرّفاً فقط في نطاق بدء الجلسة وفي attemptLogin()،
        // مما يعني أن كوكيز remember_me لم تُحذف بشكل صحيح على HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
        setcookie('remember_selector', '', time() - 3600, '/', '', $isHttps, true);
        setcookie('remember_token', '', time() - 3600, '/', '', $isHttps, true);

        try { executeQuery("DELETE FROM user_tokens WHERE expires_at < NOW()"); } catch (\Exception $e) { ErrorHandler::logException($e, 'auth::logout_cleanupTokens'); }

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
     * فحص انتهاء صلاحية كلمة المرور بناءً على الإعداد force_password_change_interval_days
     * يُستدعى تلقائياً داخل requireLogin() و attemptLogin()
     */
    public static function checkPasswordExpiry(): void {
        if (!isset($_SESSION['user_id'])) return;

        // فحص الجلسة أولاً — إذا كان must_change_password مفعّل، لا حاجة لإعادة الفحص
        if (!empty($_SESSION['must_change_password'])) return;

        require_once __DIR__ . '/../config/database.php';

        try {
            $user = fetchOne("SELECT password_changed_at FROM users WHERE id = ?", [$_SESSION['user_id']]);
            if (!$user) return;
            self::checkPasswordExpiryForUser($user);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'auth::checkPasswordExpiry');
            // صامت — لا نوقف التطبيق بسبب خطأ في الفحص
        }
    }

    /**
     * فحص انتهاء صلاحية كلمة المرور لمستخدم معين (يُستخدم عند تسجيل الدخول)
     * @param array $user بيانات المستخدم (يجب أن يحتوي على password_changed_at)
     */
    public static function checkPasswordExpiryForUser(array $user): void {
        require_once __DIR__ . '/../config/database.php';

        $interval_days = getSetting('force_password_change_interval_days', '90');
        // توافق مع القيم القديمة: 'never' = 0
        if ($interval_days === 'never') $interval_days = 0;
        $interval_days = intval($interval_days);

        // إذا كانت 0 = أبداً، لا فحص
        if ($interval_days <= 0) return;

        $changed_at = $user['password_changed_at'] ?? null;
        // إذا لم يكن هناك تاريخ، نعتبره قديم ويجب التغيير
        if (empty($changed_at)) {
            self::enforcePasswordChange();
            return;
        }

        // حساب الفرق بالأيام
        $changed_timestamp = strtotime($changed_at);
        $days_since_change = floor((time() - $changed_timestamp) / 86400);

        if ($days_since_change >= $interval_days) {
            self::enforcePasswordChange();
        }
    }

    /**
     * تفعيل إجبار تغيير كلمة المرور للمستخدم الحالي
     */
    private static function enforcePasswordChange(): void {
        $_SESSION['must_change_password'] = true;
        try {
            require_once __DIR__ . '/../config/database.php';
            executeQuery("UPDATE users SET must_change_password = 1 WHERE id = ?", [$_SESSION['user_id']]);
        } catch (\Throwable $e) { ErrorHandler::logException($e, 'auth::enforcePasswordChange'); }
    }

    /**
     * التحقق من ضرورة تغيير كلمة المرور
     */
    public static function requirePasswordChange(): void {
        // فحص انتهاء الصلاحية بناءً على الفترة المحددة في الإعدادات
        self::checkPasswordExpiry();

        if (!empty($_SESSION['must_change_password'])) {
            $currentScript = basename($_SERVER['SCRIPT_NAME']);
            if ($currentScript !== 'change-password.php') {
                header("Location: " . self::getBaseUrl() . "/users/change-password.php");
                exit;
            }
        }
    }

    /**
     * الحصول على بيانات المستخدم الحالي
     */
    public static function getCurrentUser(): ?array {
        if (!self::isLoggedIn()) return null;
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
    public static function getRoleName($role): string {
        $roles = [
            'admin' => t('auth_role_system_admin'),
            'manager' => t('users_role_admin'),
            'user' => t('auth_role_regular_user')
        ];
        return $roles[$role] ?? $role;
    }

    /**
     * الحصول على لون شارة الدور
     */
    public static function getRoleBadgeClass($role): string {
        $classes = [
            'admin' => 'badge-danger',
            'manager' => 'badge-warning',
            'user' => 'badge-info'
        ];
        return $classes[$role] ?? 'badge-info';
    }

    /**
     * حساب المسار الأساسي للتطبيق
     */
    public static function getBaseUrl(): string {
        $script_dir = dirname($_SERVER['SCRIPT_NAME']);
        if (in_array(basename($script_dir), ['products', 'purchases', 'sales', 'api', 'users', 'settings', 'expenses', 'units', 'categories', 'backups', 'reports', 'admin'])) {
            return rtrim(dirname($script_dir), '/');
        }
        return rtrim($script_dir, '/');
    }
}

// ===== دوال إجرائية للتوافق مع الكود القديم =====

function checkIPRateLimit() {
    return AppAuth::checkIPRateLimit();
}

function recordIPFailedAttempt($username) {
    return AppAuth::recordIPFailedAttempt($username);
}

function clearIPRateLimit() {
    return AppAuth::clearIPRateLimit();
}

function getClientIP() {
    return AppAuth::getClientIP();
}

function checkRememberMe() {
    return AppAuth::checkRememberMe();
}

function requireLogin() {
    return AppAuth::requireLogin();
}

function isLoggedIn() {
    return AppAuth::isLoggedIn();
}

function requireAdmin() {
    return AppAuth::requireAdmin();
}

function attemptLogin($username, $password) {
    return AppAuth::attemptLogin($username, $password);
}

function recordFailedAttempt($username) {
    return AppAuth::recordFailedAttempt($username);
}

function logout() {
    return AppAuth::logout();
}

function requirePasswordChange() {
    return AppAuth::requirePasswordChange();
}

function getCurrentUser() {
    return AppAuth::getCurrentUser();
}

function getRoleName($role) {
    return AppAuth::getRoleName($role);
}

function getRoleBadgeClass($role) {
    return AppAuth::getRoleBadgeClass($role);
}

function getBaseUrl() {
    return AppAuth::getBaseUrl();
}
