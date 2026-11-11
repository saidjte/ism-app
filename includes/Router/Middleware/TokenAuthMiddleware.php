<?php
/**
 * ===== وسيط مصادقة الرموز (Token Auth Middleware) =====
 * يتحقق من صحة رمز API (Bearer Token) للطلبات القادمة من الهاتف
 *
 * كيفية العمل:
 *   1. يقرأ الرمز من عدة مصادر (رأس Authorization + ?token= وغيرها)
 *   2. يبحث عن الرمز في جدول api_tokens
 *   3. يتحقق من: عدم الإبطال + عدم الانتهاء + نشاط المستخدم
 *   4. يُعدّل $_SESSION ببيانات المستخدم للتوافق مع الكود الموجود
 *
 * ميزة رئيسية: لا يُنشئ جلسة حقيقية — فقط يُعدّل المتغيرات المطلوبة
 * للتوافق مع الكود الموجود (hasPermission, fetchOne, إلخ)
 *
 * v4.43.0: إصلاح حرج — البحث عن الرمز في عدة مصادر
 *          السبب: Apache CGI/FastCGI يحذف رأس Authorization قبل وصوله إلى PHP
 *          الحل: البحث في 5 مصادر بما فيها ?token= الذي لا يحذفه Apache
 */

class TokenAuthMiddleware
{
    /**
     * التحقق من رمز المصادقة
     *
     * v4.43.0: يبحث عن الرمز في عدة مصادر لأن Apache CGI/FastCGI
     * قد يحذف رأس Authorization قبل وصوله إلى PHP
     *
     * @return bool هل الرمز صالح؟
     */
    public static function handle(): bool
    {
        // استخراج الرمز من عدة مصادر
        $token = self::extractToken();

        if ($token === null) {
            ApiResponse::error(
                'رمز المصادقة مفقود. أرسل رأس Authorization: Bearer <token> أو معامل ?token=',
                'missing_token',
                401
            );
            return false;
        }

        $tokenHash = hash('sha256', $token);

        try {
            // البحث عن الرمز في قاعدة البيانات
            $tokenRow = fetchOne(
                "SELECT at.id, at.user_id, at.device_name, at.is_revoked, at.expires_at,
                        at.last_used_at, at.created_at,
                        u.username, u.full_name, u.role, u.is_active
                 FROM api_tokens at
                 JOIN users u ON at.user_id = u.id
                 WHERE at.token_hash = ?",
                [$tokenHash]
            );

            // الرمز غير موجود
            if (!$tokenRow) {
                ApiResponse::error('رمز المصادقة غير صالح', 'invalid_token', 401);
                return false;
            }

            // الرمز مُبطَل
            if (!empty($tokenRow['is_revoked'])) {
                ApiResponse::error('تم إبطال رمز المصادقة. يرجى تسجيل الدخول مرة أخرى.', 'token_revoked', 401);
                return false;
            }

            // الرمز منتهي الصلاحية
            if (!empty($tokenRow['expires_at']) && strtotime($tokenRow['expires_at']) < time()) {
                ApiResponse::error('انتهت صلاحية رمز المصادقة. يرجى تسجيل الدخول مرة أخرى.', 'token_expired', 401);
                return false;
            }

            // الحساب غير مفعّل
            if (empty($tokenRow['is_active'])) {
                ApiResponse::error('الحساب معطّل. تواصل مع المدير.', 'account_disabled', 403);
                return false;
            }

            // ===== تعيين متغيرات الجلسة للتوافق مع الكود الموجود =====
            // هذا ضروري لأن الدوال مثل hasPermission() و requirePermission()
            // تعتمد على $_SESSION['user_id'] و $_SESSION['user_role']
            $_SESSION['user_id'] = intval($tokenRow['user_id']);
            $_SESSION['username'] = $tokenRow['username'];
            $_SESSION['user_name'] = $tokenRow['full_name'];
            $_SESSION['user_role'] = $tokenRow['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();

            // متغير خاص بالهاتف — لتمييز الطلبات
            $_SESSION['mobile_user_id'] = intval($tokenRow['user_id']);
            $_SESSION['auth_via_token'] = true;

            // تحديث آخر استخدام للرمز
            try {
                $db = AppDatabase::getInstance();
                $db->query(
                    "UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?",
                    [intval($tokenRow['id'])]
                );
            } catch (\Throwable $e) {
                // صامت — لا نوقف الطلب بسبب خطأ في التحديث
            }

            return true;

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'TokenAuthMiddleware::handle');
            ApiResponse::serverError('خطأ في التحقق من المصادقة');
            return false;
        }
    }

    /**
     * استخراج رمز المصادقة من الطلب
     *
     * v4.43.0: يبحث في عدة مصادر لأن Apache CGI/FastCGI يحذف رأس Authorization
     *
     * ترتيب البحث:
     *   1. رأس Authorization القياسي
     *   2. REDIRECT_HTTP_AUTHORIZATION (بعد إعادة كتابة .htaccess)
     *   3. رأس X-Authorization المخصص
     *   4. رأس X-Api-Token المخصص
     *   5. معامل URL ?token= (يعمل دائماً — Apache لا يحذفه)
     *
     * @return string|null الرمز الخام أو null
     */
    private static function extractToken(): ?string
    {
        // 1. رأس Authorization القياسي
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!empty($authHeader) && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // 2. REDIRECT_HTTP_AUTHORIZATION (بعد .htaccess rewrite)
        $redirectAuth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!empty($redirectAuth) && preg_match('/Bearer\s+(.+)$/i', $redirectAuth, $matches)) {
            return $matches[1];
        }

        // 3. رأس X-Authorization المخصص
        $xAuth = $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
        if (!empty($xAuth) && preg_match('/Bearer\s+(.+)$/i', $xAuth, $matches)) {
            return $matches[1];
        }

        // 4. رأس X-Api-Token المخصص
        $xApiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
        if (!empty($xApiToken)) {
            return $xApiToken;
        }

        // 5. معامل URL ?token= (الأكثر موثوقية — Apache لا يحذفه أبداً)
        $urlToken = $_GET['token'] ?? '';
        if (!empty($urlToken)) {
            return $urlToken;
        }

        return null;
    }

    /**
     * التحقق من صلاحية محددة (للاستخدام كـ middleware)
     *
     * @param string $permission الصلاحية المطلوبة
     * @return callable دالة الوسيط
     */
    public static function permission(string $permission): callable
    {
        return function () use ($permission): bool {
            // أولاً: التحقق من المصادقة
            if (!isset($_SESSION['mobile_user_id'])) {
                // لم يتم التحقق بعد — نفذ التحقق أولاً
                if (!self::handle()) {
                    return false;
                }
            }

            // التحقق من الصلاحية
            if (function_exists('hasPermission')) {
                if (hasPermission($permission)) {
                    return true;
                }
            }

            // المدير يملك كل الصلاحيات
            if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                return true;
            }

            ApiResponse::error(
                "ليس لديك صلاحية الوصول. الصلاحية المطلوبة: {$permission}",
                'permission_denied',
                403
            );
            return false;
        };
    }

    /**
     * التحقق من صلاحية المدير فقط
     */
    public static function admin(): bool
    {
        if (!isset($_SESSION['mobile_user_id'])) {
            if (!self::handle()) {
                return false;
            }
        }

        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            ApiResponse::error('هذه العملية تتطلب صلاحية المدير', 'admin_required', 403);
            return false;
        }

        return true;
    }
}
