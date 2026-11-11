<?php
/**
 * ===== متحكم إشعارات الموبايل (MobileNotificationController) =====
 * يوفر عمليات الإشعارات عبر API الموبايل مع دعم Bearer Token
 *
 * المسارات:
 *   GET  /api/mobile/notifications             — قائمة الإشعارات
 *   GET  /api/mobile/notifications/unread-count — عدد غير المقروءة
 *   GET  /api/mobile/notifications/poll         — استطلاع إشعارات جديدة
 *   POST /api/mobile/notifications/mark-read    — تعيين كمقروء
 *   POST /api/mobile/notifications/mark-all-read— تعيين الكل كمقروء
 *   DELETE /api/mobile/notifications/{id}       — حذف إشعار
 *
 * v5.11.0: إنشاء متحكم إشعارات الموبايل (تحويل من Session إلى Bearer)
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileNotificationController extends ApiController
{
    /**
     * الحصول على معرف المستخدم الحالي من Bearer Token
     * يتجاوز $_SESSION['user_id'] لأن الوسيط يضعه بالفعل
     */
    private function getCurrentUserId(): int
    {
        // TokenAuthMiddleware يضع user_id في $_SESSION
        return intval($_SESSION['user_id'] ?? $_SESSION['mobile_user_id'] ?? 0);
    }

    /**
     * قائمة الإشعارات مع ترقيم الصفحات والتصفية
     *
     * GET /api/mobile/notifications?page=1&per_page=20&type=sale&unread=1
     */
    public function index(array $params = []): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            $type = trim($this->input('type', ''));
            $unreadOnly = $this->input('unread', '0') === '1';

            // بناء الاستعلام
            $where = ["n.user_id = ?"];
            $bindings = [$userId];

            if (!empty($type)) {
                $where[] = "n.type = ?";
                $bindings[] = $type;
            }
            if ($unreadOnly) {
                $where[] = "n.is_read = 0";
            }

            $whereClause = 'WHERE ' . implode(' AND ', $where);

            // عدد الإجمالي
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n {$whereClause}");
            $countStmt->execute($bindings);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $stmt = $pdo->prepare(
                "SELECT n.* FROM notifications n {$whereClause}
                 ORDER BY n.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($bindings, [$perPage, $offset]));
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // عدد غير المقروءة
            $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $unreadStmt->execute([$userId]);
            $unreadCount = intval($unreadStmt->fetchColumn());

            // آخر معرف
            $lastIdStmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?");
            $lastIdStmt->execute([$userId]);
            $lastId = intval($lastIdStmt->fetchColumn());

            // تنسيق البيانات
            $notifications = array_map(function ($row) {
                $data = null;
                if (!empty($row['data'])) {
                    $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
                }
                return [
                    'id' => intval($row['id']),
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'data' => $data,
                    'is_read' => intval($row['is_read']) === 1,
                    'created_at' => $row['created_at'],
                    'time_ago' => $this->timeAgo($row['created_at']),
                ];
            }, $notifications);

            ApiResponse::success([
                'data' => $notifications,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
                'unread_count' => $unreadCount,
                'last_id' => $lastId,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileNotificationController::index');
            }
            ApiResponse::serverError('خطأ في جلب الإشعارات');
        }
    }

    /**
     * عدد الإشعارات غير المقروءة
     *
     * GET /api/mobile/notifications/unread-count
     */
    public function unreadCount(array $params = []): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            $unreadCount = intval($stmt->fetchColumn());

            $lastIdStmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?");
            $lastIdStmt->execute([$userId]);
            $lastId = intval($lastIdStmt->fetchColumn());

            ApiResponse::success([
                'unread_count' => $unreadCount,
                'last_id' => $lastId,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileNotificationController::unreadCount');
            }
            ApiResponse::serverError('خطأ في جلب عدد الإشعارات');
        }
    }

    /**
     * استطلاع إشعارات جديدة
     *
     * GET /api/mobile/notifications/poll?last_id=42
     */
    public function poll(array $params = []): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $lastId = max(0, intval($this->input('last_id', 0)));

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // جلب الإشعارات الأحدث من last_id
            $stmt = $pdo->prepare(
                "SELECT * FROM notifications
                 WHERE user_id = ? AND id > ?
                 ORDER BY created_at DESC
                 LIMIT 50"
            );
            $stmt->execute([$userId, $lastId]);
            $newNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $unreadStmt->execute([$userId]);
            $unreadCount = intval($unreadStmt->fetchColumn());

            $lastIdStmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?");
            $lastIdStmt->execute([$userId]);
            $currentLastId = intval($lastIdStmt->fetchColumn());

            $newNotifications = array_map(function ($row) {
                $data = null;
                if (!empty($row['data'])) {
                    $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
                }
                return [
                    'id' => intval($row['id']),
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'data' => $data,
                    'is_read' => intval($row['is_read']) === 1,
                    'created_at' => $row['created_at'],
                ];
            }, $newNotifications);

            ApiResponse::success([
                'new_notifications' => $newNotifications,
                'unread_count' => $unreadCount,
                'last_id' => $currentLastId,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileNotificationController::poll');
            }
            ApiResponse::serverError('خطأ في استطلاع الإشعارات');
        }
    }

    /**
     * تعيين إشعار كمقروء
     *
     * POST /api/mobile/notifications/mark-read
     * Body: { "id": 42 }
     */
    public function markRead(array $params = []): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $notifId = intval($this->input('id', 0));
            if ($notifId <= 0) {
                ApiResponse::validationError(['معرف الإشعار مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من أن الإشعار يخص المستخدم
            $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $userId]);

            if (!$stmt->fetch()) {
                ApiResponse::notFound('الإشعار غير موجود');
                return;
            }

            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);

            // عدد غير المقروءة المتبقية
            $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $unreadStmt->execute([$userId]);

            ApiResponse::success([
                'unread_count' => intval($unreadStmt->fetchColumn()),
            ], 'تم تعيين الإشعار كمقروء');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileNotificationController::markRead');
            }
            ApiResponse::serverError('خطأ في تعيين الإشعار كمقروء');
        }
    }

    /**
     * تعيين كل الإشعارات كمقروءة
     *
     * POST /api/mobile/notifications/mark-all-read
     */
    public function markAllRead(array $params = []): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);

            ApiResponse::success([
                'unread_count' => 0,
            ], 'تم تعيين جميع الإشعارات كمقروءة');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileNotificationController::markAllRead');
            }
            ApiResponse::serverError('خطأ في تعيين الإشعارات كمقروءة');
        }
    }

    /**
     * حذف إشعار
     *
     * DELETE /api/mobile/notifications/{id}
     */
    public function delete(array $params = []): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if ($userId <= 0) {
                ApiResponse::unauthorized('مستخدم غير مصادق عليه');
                return;
            }

            $id = intval($params['id'] ?? $this->input('id', 0));
            if ($id <= 0) {
                ApiResponse::validationError(['معرف الإشعار مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من أن الإشعار يخص المستخدم
            $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);

            if (!$stmt->fetch()) {
                ApiResponse::notFound('الإشعار غير موجود');
                return;
            }

            $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $userId]);

            // عدد غير المقروءة المتبقية
            $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $unreadStmt->execute([$userId]);

            ApiResponse::success([
                'unread_count' => intval($unreadStmt->fetchColumn()),
            ], 'تم حذف الإشعار بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileNotificationController::delete');
            }
            ApiResponse::serverError('خطأ في حذف الإشعار');
        }
    }

    /**
     * حساب الوقت المنقضي بصيغة إنسانية
     */
    private function timeAgo(string $datetime): string
    {
        $now = new \DateTime();
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);

        // استخدام نظام الترجمة t() — مثل AppNotification::timeAgo
        if ($diff->y > 0) return str_replace('{n}', $diff->y, t('notif_time_ago_year'));
        if ($diff->m > 0) return str_replace('{n}', $diff->m, t('notif_time_ago_month'));
        if ($diff->d > 0) return str_replace('{n}', $diff->d, t('notif_time_ago_day'));
        if ($diff->h > 0) return str_replace('{n}', $diff->h, t('notif_time_ago_hour'));
        if ($diff->i > 0) return str_replace('{n}', $diff->i, t('notif_time_ago_minute'));
        return t('common_now');
    }
}
