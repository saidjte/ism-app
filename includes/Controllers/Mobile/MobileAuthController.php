<?php
/**
 * ===== متحكم المصادقة للهاتف (Mobile Auth Controller) =====
 * يوفر تسجيل الدخول/الخروج باستخدام رموز API (Bearer Tokens)
 *
 * نقاط النهاية:
 *   POST /api/mobile/auth/login           — تسجيل الدخول والحصول على رمز
 *   POST /api/mobile/auth/logout          — إبطال الرمز الحالي
 *   GET  /api/mobile/auth/me              — بيانات المستخدم الحالي
 *   POST /api/mobile/auth/refresh         — تجديد الرمز
 *   PUT  /api/mobile/auth/change-password — تغيير كلمة المرور
 *
 * v4.43.0: إصلاح حرج — getCurrentTokenHash() يقرأ الرمز من ?token= أيضاً
 *          السبب: Apache CGI/FastCGI يحذف رأس Authorization
 */

require_once __DIR__ . '/../ApiController.php';

class MobileAuthController extends ApiController
{
    /**
     * تسجيل الدخول — يُرجع رمز API (Bearer Token)
     */
    public function login(array $params = []): void
    {
        try {
            $this->requirePost();

            $username = $this->input('username', '');
            $password = $this->input('password', '');
            $deviceName = $this->input('device_name', 'جهاز غير معروف');

            // التحقق من المدخلات
            if (empty($username) || empty($password)) {
                ApiResponse::validationError(['username' => 'اسم المستخدم مطلوب', 'password' => 'كلمة المرور مطلوبة']);
            }

            // استخدام نظام المصادقة الموجود
            $result = AppAuth::attemptLogin($username, $password);

            if (!$result['success']) {
                // تسجيل المحاولة الفاشلة
                try { AppAuth::recordFailedAttempt($username); } catch (\Throwable $e) {}
                ApiResponse::error($result['message'] ?? 'بيانات الدخول غير صحيحة', 'auth_failed', 401);
            }

            // إنشاء رمز API
            $token = self::generateToken();
            $userId = intval($_SESSION['user_id'] ?? 0);
            $userRole = $_SESSION['user_role'] ?? 'user';
            $userName = $_SESSION['user_name'] ?? '';
            $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

            if ($userId <= 0) {
                ApiResponse::serverError('فشل في استرداد بيانات المستخدم بعد المصادقة');
            }

            $db = AppDatabase::getInstance();
            $db->insert(
                "INSERT INTO api_tokens (user_id, token, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?, ?)",
                [$userId, $token, hash('sha256', $token), $deviceName, $expiresAt]
            );

            // تسجيل النشاط
            try { addAuditLog('api_login', "تسجيل دخول API من: {$deviceName}", '', 'user', $userId); } catch (\Throwable $e) {}

            // تسجيل الخروج من الجلسة (لا نحتاج جلسة للهاتف)
            unset($_SESSION['user_id'], $_SESSION['logged_in'], $_SESSION['user_role'], $_SESSION['user_name']);

            ApiResponse::success([
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
                'user' => [
                    'id' => $userId,
                    'username' => $username,
                    'full_name' => $userName,
                    'role' => $userRole,
                ],
            ], 'تم تسجيل الدخول بنجاح', 200);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileAuthController::login');

            $errorMsg = 'حدث خطأ أثناء تسجيل الدخول';
            $errType = 'server_error';
            $statusCode = 500;
            $msg = $e->getMessage();
            if (strpos($msg, 'Column not found') !== false || strpos($msg, 'Champ') !== false) {
                // v6.3.6: الإصلاح التلقائي في api/mobile/index.php يعالج الأعمدة المفقودة
                // عند كل محاولة دخول — أعد المحاولة بعد لحظات إن فشلت المحاولة الأولى
                $errorMsg = 'جدول api_tokens يحتاج إصلاح — أعمدة مفقودة. أعد المحاولة بعد لحظات (الإصلاح تلقائي)، أو افتح صفحة create-api-table.php من المتصفح.';
                $errType = 'db_schema_error';
            } elseif (strpos($msg, 'Table') !== false && strpos($msg, 'doesn\'t exist') !== false) {
                $errorMsg = 'جدول api_tokens غير موجود. أعد المحاولة بعد لحظات (يُنشأ تلقائياً)، أو افتح صفحة create-api-table.php من المتصفح.';
                $errType = 'db_table_missing';
            } elseif (strpos($msg, 'SQLSTATE') !== false) {
                $errorMsg = 'خطأ في قاعدة البيانات أثناء تسجيل الدخول. يرجى المحاولة لاحقاً.';
                $errType = 'db_error';
            }

            $details = [];
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                $details = [
                    'exception' => get_class($e),
                    'message' => $msg,
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ];
            }

            ApiResponse::error($errorMsg, $errType, $statusCode, $details);
        }
    }

    /**
     * تسجيل الخروج — إبطال الرمز الحالي
     */
    public function logout(array $params = []): void
    {
        $tokenHash = self::getCurrentTokenHash();
        if ($tokenHash) {
            try {
                $db = AppDatabase::getInstance();
                $db->query("UPDATE api_tokens SET is_revoked = 1 WHERE token_hash = ?", [$tokenHash]);

                $userId = $this->getUserIdFromToken($tokenHash);
                if ($userId && function_exists('addAuditLog')) {
                    addAuditLog('api_logout', 'تسجيل خروج API', '', 'user', $userId);
                }
            } catch (\Throwable $e) {
                ErrorHandler::logException($e, 'MobileAuthController::logout');
            }
        }

        ApiResponse::success(null, 'تم تسجيل الخروج بنجاح');
    }

    /**
     * بيانات المستخدم الحالي
     */
    public function me(array $params = []): void
    {
        $userId = intval($_SESSION['mobile_user_id'] ?? 0);
        if ($userId <= 0) {
            ApiResponse::unauthorized();
            return;
        }

        try {
            $user = fetchOne(
                "SELECT id, username, full_name, role, is_active, last_login, created_at FROM users WHERE id = ?",
                [$userId]
            );

            if (!$user) {
                ApiResponse::notFound('المستخدم غير موجود');
                return;
            }

            try {
                $loginCount = fetchOne("SELECT login_count FROM users WHERE id = ?", [$userId]);
                $user['login_count'] = $loginCount ? intval($loginCount['login_count']) : 0;
            } catch (\Throwable $e) {
                $user['login_count'] = 0;
            }

            $permissions = [];
            if (function_exists('getUserPermissions')) {
                try {
                    $permissions = getUserPermissions($userId);
                } catch (\Throwable $e) {
                    $permissions = [];
                }
            }

            $user['permissions'] = $permissions;

            ApiResponse::success($user, '');
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileAuthController::me');

            $errorMsg = 'خطأ في جلب بيانات المستخدم';
            $details = [];
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                $details = [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ];
            }
            ApiResponse::error($errorMsg, 'server_error', 500, $details);
        }
    }

    /**
     * تجديد الرمز — يُبطِل القديم ويُنشئ جديد
     */
    public function refresh(array $params = []): void
    {
        $oldTokenHash = self::getCurrentTokenHash();
        if (!$oldTokenHash) {
            ApiResponse::unauthorized();
            return;
        }

        $userId = $this->getUserIdFromToken($oldTokenHash);
        if (!$userId) {
            ApiResponse::unauthorized('الرمز غير صالح');
            return;
        }

        try {
            $db = AppDatabase::getInstance();

            $newToken = self::generateToken();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
            $deviceName = $this->getDeviceNameFromToken($oldTokenHash) ?? 'جهاز غير معروف';

            $db->insert(
                "INSERT INTO api_tokens (user_id, token, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?, ?)",
                [$userId, $newToken, hash('sha256', $newToken), $deviceName, $expiresAt]
            );

            // إبطال الرمز القديم
            $db->query("UPDATE api_tokens SET is_revoked = 1 WHERE token_hash = ?", [$oldTokenHash]);

            if (function_exists('addAuditLog')) {
                addAuditLog('api_token_refresh', 'تجديد رمز API', '', 'user', $userId);
            }

            ApiResponse::success([
                'token' => $newToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
            ], 'تم تجديد الرمز بنجاح');
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileAuthController::refresh');

            $errorMsg = 'خطأ في تجديد الرمز';
            $details = [];
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                $details = [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ];
            }
            ApiResponse::error($errorMsg, 'server_error', 500, $details);
        }
    }

    /**
     * تغيير كلمة المرور
     */
    public function changePassword(array $params = []): void
    {
        try {
            $userId = intval($_SESSION['mobile_user_id'] ?? $_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $currentPassword = $this->input('current_password', '');
            $newPassword = $this->input('new_password', '');

            $errors = [];
            if (empty($currentPassword)) {
                $errors[] = 'كلمة المرور الحالية مطلوبة';
            }
            if (empty($newPassword) || mb_strlen($newPassword) < 8) {
                $errors[] = 'كلمة المرور الجديدة مطلوبة (8 أحرف على الأقل)';
            }

            if (!empty($errors)) {
                ApiResponse::validationError($errors);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                ApiResponse::notFound('المستخدم غير موجود');
                return;
            }

            if (!password_verify($currentPassword, $user['password'])) {
                ApiResponse::error('كلمة المرور الحالية غير صحيحة', 'invalid_password', 422);
                return;
            }

            if (password_verify($newPassword, $user['password'])) {
                ApiResponse::validationError(['كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية']);
                return;
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo->prepare(
                "UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?"
            )->execute([$hashedPassword, $userId]);

            if (function_exists('addAuditLog')) {
                addAuditLog('password_change', "تغيير كلمة المرور: {$user['username']}");
            }

            ApiResponse::success(null, 'تم تغيير كلمة المرور بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileAuthController::changePassword');
            }
            ApiResponse::serverError('خطأ في تغيير كلمة المرور');
        }
    }

    // ===== دوال مساعدة =====

    /**
     * توليد رمز API عشوائي آمن
     */
    public static function generateToken(): string
    {
        return 'ism_' . bin2hex(random_bytes(32));
    }

    /**
     * الحصول على هاش الرمز الحالي من رأس الطلب
     *
     * v4.43.0: إصلاح حرج — البحث عن الرمز في عدة مصادر
     *          السبب: Apache CGI/FastCGI يحذف رأس Authorization
     *          الحل: البحث في 5 مصادر بما فيها ?token=
     */
    public static function getCurrentTokenHash(): ?string
    {
        // 1. رأس Authorization القياسي
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return hash('sha256', $matches[1]);
        }

        // 2. REDIRECT_HTTP_AUTHORIZATION (بعد .htaccess rewrite)
        $redirectAuth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!empty($redirectAuth) && preg_match('/Bearer\s+(.+)$/i', $redirectAuth, $matches)) {
            return hash('sha256', $matches[1]);
        }

        // 3. رأس X-Authorization المخصص
        $xAuth = $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
        if (!empty($xAuth) && preg_match('/Bearer\s+(.+)$/i', $xAuth, $matches)) {
            return hash('sha256', $matches[1]);
        }

        // 4. رأس X-Api-Token المخصص
        $xApiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
        if (!empty($xApiToken)) {
            return hash('sha256', $xApiToken);
        }

        // 5. معامل URL ?token= (الأكثر موثوقية — Apache لا يحذفه أبداً)
        $urlToken = $_GET['token'] ?? '';
        if (!empty($urlToken)) {
            return hash('sha256', $urlToken);
        }

        return null;
    }

    /**
     * الحصول على معرف المستخدم من الرمز
     */
    private function getUserIdFromToken(string $tokenHash): ?int
    {
        try {
            $row = fetchOne(
                "SELECT user_id FROM api_tokens WHERE token_hash = ? AND is_revoked = 0 AND expires_at > NOW()",
                [$tokenHash]
            );
            return $row ? intval($row['user_id']) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * الحصول على اسم الجهاز من الرمز
     */
    private function getDeviceNameFromToken(string $tokenHash): ?string
    {
        try {
            $row = fetchOne("SELECT device_name FROM api_tokens WHERE token_hash = ?", [$tokenHash]);
            return $row ? $row['device_name'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}