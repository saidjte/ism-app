<?php
/**
 * ===== متحكم رموز API للهاتف (Mobile API Token Controller) =====
 * يوفر واجهة API لإدارة رموز API من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET    /api/mobile/tokens                — قائمة رموز API
 *   POST   /api/mobile/tokens/create         — إنشاء رمز جديد
 *   POST   /api/mobile/tokens/revoke/{id}    — إلغاء رمز
 *   DELETE /api/mobile/tokens/{id}            — حذف رمز
 *
 * ملاحظة: جميع العمليات تتطلب صلاحيات المدير
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف إدارة رموز API
 */

require_once __DIR__ . '/../ApiController.php';

class MobileApiTokenController extends ApiController
{
    /**
     * قائمة رموز API
     *
     * معاملات GET:
     *   status — تصفية بالحالة: active|revoked|expired|all (افتراضي: all)
     *   page   — رقم الصفحة
     *   per_page — عدد العناصر
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function index(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $status = trim($this->input('status', 'all'));
            $pagination = $this->getPaginationParams(20);
            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            $sql = "SELECT t.id, t.user_id, t.device_name, t.is_revoked, t.expires_at,
                           t.last_used_at, t.created_at, u.username, u.full_name
                    FROM api_tokens t
                    LEFT JOIN users u ON t.user_id = u.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total FROM api_tokens t WHERE 1=1";
            $bindings = [];

            // تصفية بالحالة
            if ($status === 'active') {
                $sql .= " AND t.is_revoked = 0 AND (t.expires_at IS NULL OR t.expires_at > NOW())";
                $countSql .= " AND t.is_revoked = 0 AND (t.expires_at IS NULL OR t.expires_at > NOW())";
            } elseif ($status === 'revoked') {
                $sql .= " AND t.is_revoked = 1";
                $countSql .= " AND t.is_revoked = 1";
            } elseif ($status === 'expired') {
                $sql .= " AND t.expires_at IS NOT NULL AND t.expires_at <= NOW() AND t.is_revoked = 0";
                $countSql .= " AND t.expires_at IS NOT NULL AND t.expires_at <= NOW() AND t.is_revoked = 0";
            }

            $sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            $tokens = fetchAll($sql, $bindings);
            $countRow = fetchOne($countSql, array_slice($bindings, 0, -2));
            $total = intval($countRow['total'] ?? 0);

            if (!is_array($tokens)) {
                $tokens = [];
            }

            // إضافة حالة مُحسوبة
            foreach ($tokens as &$token) {
                $token['status'] = $this->getTokenStatus($token);
                // إخفاء قيمة الرمز (الأمان أولاً)
                unset($token['token']);
            }
            unset($token);

            // إحصائيات سريعة
            $stats = [
                'total' => $total,
                'active' => 0,
                'revoked' => 0,
                'expired' => 0,
            ];

            try {
                $activeCount = fetchOne("SELECT COUNT(*) as c FROM api_tokens WHERE is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())");
                $stats['active'] = intval($activeCount['c'] ?? 0);

                $revokedCount = fetchOne("SELECT COUNT(*) as c FROM api_tokens WHERE is_revoked = 1");
                $stats['revoked'] = intval($revokedCount['c'] ?? 0);

                $expiredCount = fetchOne("SELECT COUNT(*) as c FROM api_tokens WHERE expires_at IS NOT NULL AND expires_at <= NOW() AND is_revoked = 0");
                $stats['expired'] = intval($expiredCount['c'] ?? 0);
            } catch (\Throwable $e) {}

            ApiResponse::paginated($tokens, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileApiTokenController::index');
            ApiResponse::serverError('حدث خطأ أثناء جلب رموز API');
        }
    }

    /**
     * إنشاء رمز API جديد
     *
     * الجسم (JSON):
     *   user_id      — معرف المستخدم (مطلوب)
     *   device_name  — اسم الجهاز (اختياري)
     *   expiry_days  — مدة الصلاحية بالأيام: 30|60|90|180|365 (افتراضي: 90)
     *
     * الاستجابة:
     *   201 { success, data: { token, token_id, expires_at }, message }
     *
     * ⚠️ الرمز يظهر مرة واحدة فقط!
     */
    public function create(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $userId = intval($this->input('user_id', 0));
            $deviceName = trim($this->input('device_name', 'Mobile App'));
            $expiryDays = intval($this->input('expiry_days', 90));

            if ($userId <= 0) {
                ApiResponse::validationError(['user_id' => 'معرف المستخدم مطلوب']);
                return;
            }

            // التحقق من المستخدم
            $user = fetchOne("SELECT id, username, is_active FROM users WHERE id = ?", [$userId]);
            if (!$user) {
                ApiResponse::notFound('المستخدم غير موجود');
                return;
            }
            if (!$user['is_active']) {
                ApiResponse::validationError(['user_id' => 'المستخدم غير مفعّل']);
                return;
            }

            // التحقق من مدة الصلاحية
            if (!in_array($expiryDays, [30, 60, 90, 180, 365])) {
                $expiryDays = 90;
            }

            // توليد الرمز
            $plainToken = 'ism_' . bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $plainToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at, created_at)
                VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $tokenHash, $deviceName, $expiresAt]);
            $tokenId = intval($pdo->lastInsertId());

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('api_token_create', "إنشاء رمز API للمستخدم {$user['username']} — {$deviceName}");
            }

            ApiResponse::created([
                'token' => $plainToken,
                'token_id' => $tokenId,
                'expires_at' => $expiresAt,
                'user_id' => $userId,
                'device_name' => $deviceName,
            ], 'تم إنشاء الرمز بنجاح. ⚠️ احفظ الرمز الآن — لن يظهر مرة أخرى!');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileApiTokenController::create');
            ApiResponse::serverError('حدث خطأ أثناء إنشاء رمز API');
        }
    }

    /**
     * إلغاء رمز API (إبطال الوصول دون حذف)
     *
     * معامل المسار: {id}
     *
     * الاستجابة:
     *   { success, message }
     */
    public function revoke(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $tokenId = intval($params['id'] ?? 0);
            if ($tokenId <= 0) {
                ApiResponse::validationError(['id' => 'معرف الرمز مطلوب']);
                return;
            }

            $token = fetchOne("SELECT id, user_id, device_name FROM api_tokens WHERE id = ?", [$tokenId]);
            if (!$token) {
                ApiResponse::notFound('الرمز غير موجود');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("UPDATE api_tokens SET is_revoked = 1 WHERE id = ?");
            $stmt->execute([$tokenId]);

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('api_token_revoke', "إلغاء رمز API #{$tokenId} — {$token['device_name']}");
            }

            ApiResponse::success(null, 'تم إلغاء الرمز بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileApiTokenController::revoke');
            ApiResponse::serverError('حدث خطأ أثناء إلغاء الرمز');
        }
    }

    /**
     * حذف رمز API نهائياً
     *
     * معامل المسار: {id}
     *
     * الاستجابة:
     *   { success, message }
     */
    public function delete(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $tokenId = intval($params['id'] ?? 0);
            if ($tokenId <= 0) {
                ApiResponse::validationError(['id' => 'معرف الرمز مطلوب']);
                return;
            }

            $token = fetchOne("SELECT id, device_name FROM api_tokens WHERE id = ?", [$tokenId]);
            if (!$token) {
                ApiResponse::notFound('الرمز غير موجود');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE id = ?");
            $stmt->execute([$tokenId]);

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('api_token_delete', "حذف رمز API #{$tokenId} — {$token['device_name']}");
            }

            ApiResponse::success(null, 'تم حذف الرمز نهائياً');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileApiTokenController::delete');
            ApiResponse::serverError('حدث خطأ أثناء حذف الرمز');
        }
    }

    /**
     * تحديد حالة الرمز
     */
    private function getTokenStatus(array $token): string
    {
        if (!empty($token['is_revoked'])) {
            return 'revoked';
        }
        if (!empty($token['expires_at']) && strtotime($token['expires_at']) < time()) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * تدوير جميع الرموز النشطة — إبطال الجميع وإلزام المستخدمين بتسجيل الدخول مجدداً
     */
    public function rotateAll(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("UPDATE api_tokens SET is_revoked = 1 WHERE is_revoked = 0");
            $stmt->execute();
            $rotatedCount = $stmt->rowCount();

            if (function_exists('addAuditLog')) {
                addAuditLog('api_token_rotate_all', "تدوير جميع رموز API — تم إبطال {$rotatedCount} رمز نشط");
            }

            ApiResponse::success([
                'rotated_count' => $rotatedCount,
            ], "تم إبطال {$rotatedCount} رمز نشط. جميع الأجهزة بحاجة لتسجيل الدخول مجدداً.");
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileApiTokenController::rotateAll');
            ApiResponse::serverError('فشل في تدوير الرموز');
        }
    }
}
