<?php
/**
 * ===== متحكم اشتراكات الإشعارات الفورية API =====
 * Push Subscriptions — عمليات اشتراك Web Push
 */

require_once __DIR__ . '/ApiController.php';

class PushController extends ApiController
{
    /**
     * معالجة عمليات اشتراكات Push
     * GET /api/push-subscribe?action=status
     * POST /api/push-subscribe?action=subscribe|unsubscribe|permission_status
     */
    public function handle(array $params): void
    {
        $this->mergeJsonToPost();
        $userId = $this->userId();
        $action = $this->input('action') ?? $_GET['action'] ?? '';

        switch ($action) {
            case 'subscribe':
                $this->subscribe($userId);
                break;
            case 'unsubscribe':
                $this->unsubscribe($userId);
                break;
            case 'status':
                $this->status($userId);
                break;
            case 'permission_status':
                $this->permissionStatus($userId);
                break;
            default:
                ApiResponse::error('إجراء غير معروف', 'invalid_action', 400);
        }
    }

    private function subscribe(int $userId): void
    {
        $endpoint = trim($this->input('endpoint', ''));
        $p256dh = trim($this->input('p256dh', ''));
        $authKey = trim($this->input('auth', ''));
        $browser = trim($this->input('browser', ''));

        if (empty($endpoint) || empty($p256dh) || empty($authKey)) {
            ApiResponse::validationError(['endpoint' => 'بيانات الاشتراك غير مكتملة']);
        }

        $db = AppDatabase::getInstance();

        $existing = $db->query(
            "SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?",
            [$userId, $endpoint]
        )->fetch();

        if ($existing) {
            $db->query(
                "UPDATE push_subscriptions SET p256dh = ?, auth_key = ?, browser = ?, updated_at = NOW() WHERE id = ?",
                [$p256dh, $authKey, $browser, $existing['id']]
            );
        } else {
            $db->query(
                "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, browser, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$userId, $endpoint, $p256dh, $authKey, $browser]
            );
        }

        ApiResponse::custom(['success' => true, 'message' => 'تم حفظ الاشتراك بنجاح']);
    }

    private function unsubscribe(int $userId): void
    {
        $endpoint = trim($this->input('endpoint', ''));

        if (empty($endpoint)) {
            ApiResponse::validationError(['endpoint' => 'بيانات الاشتراك مفقودة']);
        }

        $db = AppDatabase::getInstance();
        $db->query(
            "DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?",
            [$userId, $endpoint]
        );

        ApiResponse::custom(['success' => true, 'message' => 'تم إلغاء الاشتراك']);
    }

    private function status(int $userId): void
    {
        $db = AppDatabase::getInstance();
        $count = $db->query(
            "SELECT COUNT(*) as c FROM push_subscriptions WHERE user_id = ?",
            [$userId]
        )->fetchColumn();

        $pushEnabled = getSetting('push_notifications_enabled', '0');

        ApiResponse::custom([
            'success'          => true,
            'has_subscription' => intval($count) > 0,
            'push_enabled'     => $pushEnabled === '1',
            'browser_support'  => true,
        ]);
    }

    private function permissionStatus(int $userId): void
    {
        $permission = trim($this->input('permission', ''));
        if (in_array($permission, ['granted', 'denied', 'default'])) {
            saveSetting('push_permission_user_' . $userId, $permission, 'حالة إذن الإشعارات للمستخدم ' . $userId);
        }
        ApiResponse::custom(['success' => true]);
    }
}
