<?php
/**
 * ===== متحكم الإشعارات الفورية للهاتف (Mobile Push Controller) =====
 * يوفر واجهة API لإدارة اشتراكات الإشعارات الفورية من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   POST /api/mobile/push/subscribe        — تسجيل اشتراك إشعارات
 *   POST /api/mobile/push/unsubscribe      — إلغاء اشتراك إشعارات
 *   GET  /api/mobile/push/status           — حالة الإشعارات الفورية
 *   POST /api/mobile/push/permission       — تحديث حالة إذن الإشعارات
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف الإشعارات الفورية
 */

require_once __DIR__ . '/../ApiController.php';

class MobilePushController extends ApiController
{
    /**
     * تسجيل اشتراك إشعارات فورية
     *
     * الجسم (JSON):
     *   endpoint    — عنوان الاشتراك (مطلوب)
     *   p256dh      — مفتاح التشفير العام (مطلوب)
     *   auth        — مفتاح المصادقة (مطلوب)
     *   device_type — نوع الجهاز: android|ios|web (اختياري)
     *
     * الاستجابة:
     *   { success, message }
     */
    public function subscribe(array $params = []): void
    {
        try {
            $this->requirePost();
            $endpoint = trim($this->input('endpoint', ''));
            $p256dh = trim($this->input('p256dh', ''));
            $auth = trim($this->input('auth', ''));
            $deviceType = trim($this->input('device_type', 'mobile'));

            if (empty($endpoint)) {
                ApiResponse::validationError(['endpoint' => 'عنوان الاشتراك مطلوب']);
                return;
            }
            if (empty($p256dh)) {
                ApiResponse::validationError(['p256dh' => 'مفتاح التشفير مطلوب']);
                return;
            }
            if (empty($auth)) {
                ApiResponse::validationError(['auth' => 'مفتاح المصادقة مطلوب']);
                return;
            }

            $userId = $this->userId();

            // حفظ أو تحديث الاشتراك
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // فحص وجود اشتراك بنفس endpoint
            $existing = fetchOne(
                "SELECT id FROM push_subscriptions WHERE endpoint = ?",
                [$endpoint]
            );

            if ($existing) {
                $stmt = $pdo->prepare("UPDATE push_subscriptions
                    SET p256dh = ?, auth_key = ?, user_id = ?, device_type = ?, updated_at = NOW()
                    WHERE endpoint = ?");
                $stmt->execute([$p256dh, $auth, $userId, $deviceType, $endpoint]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO push_subscriptions
                    (user_id, endpoint, p256dh, auth_key, device_type, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$userId, $endpoint, $p256dh, $auth, $deviceType]);
            }

            ApiResponse::success(null, 'تم تسجيل اشتراك الإشعارات بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePushController::subscribe');
            ApiResponse::serverError('حدث خطأ أثناء تسجيل اشتراك الإشعارات');
        }
    }

    /**
     * إلغاء اشتراك إشعارات فورية
     *
     * الجسم (JSON):
     *   endpoint — عنوان الاشتراك (مطلوب)
     *
     * الاستجابة:
     *   { success, message }
     */
    public function unsubscribe(array $params = []): void
    {
        try {
            $this->requirePost();
            $endpoint = trim($this->input('endpoint', ''));

            if (empty($endpoint)) {
                ApiResponse::validationError(['endpoint' => 'عنوان الاشتراك مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
            $stmt->execute([$endpoint, $this->userId()]);

            ApiResponse::success(null, 'تم إلغاء اشتراك الإشعارات');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePushController::unsubscribe');
            ApiResponse::serverError('حدث خطأ أثناء إلغاء اشتراك الإشعارات');
        }
    }

    /**
     * حالة الإشعارات الفورية للمستخدم
     *
     * الاستجابة:
     *   { success, data: { has_subscriptions, push_enabled, device_count } }
     */
    public function status(array $params = []): void
    {
        try {
            $userId = $this->userId();

            $pushEnabled = function_exists('getSetting') ? (getSetting('push_notifications_enabled', '1') === '1') : true;

            $subscriptions = [];
            try {
                $subscriptions = fetchAll(
                    "SELECT id, endpoint, device_type, created_at FROM push_subscriptions WHERE user_id = ?",
                    [$userId]
                );
                if (!is_array($subscriptions)) $subscriptions = [];
            } catch (\Throwable $e) {
                $subscriptions = [];
            }

            ApiResponse::success([
                'has_subscriptions' => !empty($subscriptions),
                'push_enabled' => $pushEnabled,
                'device_count' => count($subscriptions),
                'devices' => array_map(function ($s) {
                    return [
                        'id' => intval($s['id']),
                        'device_type' => $s['device_type'] ?? 'web',
                        'created_at' => $s['created_at'] ?? '',
                    ];
                }, $subscriptions),
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePushController::status');
            ApiResponse::serverError('حدث خطأ أثناء جلب حالة الإشعارات');
        }
    }

    /**
     * تحديث حالة إذن الإشعارات
     *
     * الجسم (JSON):
     *   permission — حالة الإذن: granted|denied|default (مطلوب)
     *
     * الاستجابة:
     *   { success, message }
     */
    public function permission(array $params = []): void
    {
        try {
            $this->requirePost();
            $permission = trim($this->input('permission', ''));

            if (!in_array($permission, ['granted', 'denied', 'default'])) {
                ApiResponse::validationError(['permission' => 'قيمة الإذن غير صالحة']);
                return;
            }

            // حفظ في إعدادات المستخدم أو جدول منفصل
            $userId = $this->userId();

            try {
                $db = AppDatabase::getInstance();
                $pdo = $db->getConnection();

                $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value)
                    VALUES (?, 'push_permission', ?)
                    ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$userId, $permission, $permission]);
            } catch (\Throwable $e) {
                // تجاهل إذا لم يكن الجدول موجوداً
            }

            ApiResponse::success(null, 'تم تحديث حالة إذن الإشعارات');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePushController::permission');
            ApiResponse::serverError('حدث خطأ أثناء تحديث حالة إذن الإشعارات');
        }
    }
}
