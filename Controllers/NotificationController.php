<?php
/**
 * ===== متحكم إشعارات API =====
 * نقطة نهاية موحدة لجميع عمليات الإشعارات
 */

require_once __DIR__ . '/ApiController.php';

class NotificationController extends ApiController
{
    /**
     * جلب الإشعارات حسب الإجراء
     * GET /api/notifications?action=list|dropdown|poll|unread_count
     * POST /api/notifications?action=mark_read|mark_all_read|delete
     */
    public function handle(array $params): void
    {
        $this->mergeJsonToPost();
        $userId = $this->userId();
        $action = $this->input('action') ?? $_GET['action'] ?? '';

        switch ($action) {
            case 'list':
                $this->listNotifications($userId);
                break;
            case 'dropdown':
                $this->dropdownNotifications($userId);
                break;
            case 'poll':
                $this->pollNotifications($userId);
                break;
            case 'mark_read':
                $this->markRead($userId);
                break;
            case 'mark_all_read':
                $this->markAllRead($userId);
                break;
            case 'delete':
                $this->deleteNotification($userId);
                break;
            case 'unread_count':
                $this->unreadCount($userId);
                break;
            default:
                ApiResponse::error('إجراء غير معروف', 'invalid_action', 400);
        }
    }

    private function listNotifications(int $userId): void
    {
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        $type = $_GET['type'] ?? null;
        $unread = ($_GET['unread'] ?? '0') === '1';

        $notifications = AppNotification::getForUser($userId, $limit, $offset, $type, $unread);
        $unreadCount = AppNotification::getUnreadCount($userId);

        ApiResponse::custom([
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'last_id'       => AppNotification::getLastId($userId),
        ]);
    }

    private function dropdownNotifications(int $userId): void
    {
        $notifications = AppNotification::getForUser($userId, 10, 0);
        $unreadCount = AppNotification::getUnreadCount($userId);

        ApiResponse::custom([
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    private function pollNotifications(int $userId): void
    {
        $lastId = max(0, intval($_GET['last_id'] ?? 0));
        $result = AppNotification::pollNew($userId, $lastId);

        ApiResponse::custom([
            'success'           => true,
            'new_notifications' => $result['notifications'],
            'unread_count'      => $result['unread_count'],
            'last_id'           => AppNotification::getLastId($userId),
        ]);
    }

    private function markRead(int $userId): void
    {
        $notifId = intval($this->input('notification_id') ?? $this->input('id') ?? 0);
        if ($notifId <= 0) {
            ApiResponse::validationError(['notification_id' => 'معرف الإشعار غير صالح']);
        }
        AppNotification::markRead($notifId, $userId);
        ApiResponse::custom(['success' => true, 'unread_count' => AppNotification::getUnreadCount($userId)]);
    }

    private function markAllRead(int $userId): void
    {
        AppNotification::markAllRead($userId);
        ApiResponse::custom(['success' => true, 'unread_count' => 0]);
    }

    private function deleteNotification(int $userId): void
    {
        $notifId = intval($this->input('notification_id') ?? $this->input('id') ?? 0);
        if ($notifId <= 0) {
            ApiResponse::validationError(['notification_id' => 'معرف الإشعار غير صالح']);
        }
        AppNotification::delete($notifId, $userId);
        ApiResponse::custom(['success' => true, 'unread_count' => AppNotification::getUnreadCount($userId)]);
    }

    private function unreadCount(int $userId): void
    {
        ApiResponse::custom(['success' => true, 'unread_count' => AppNotification::getUnreadCount($userId)]);
    }
}
