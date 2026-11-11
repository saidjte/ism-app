<?php
/**
 * API الإشعارات
 * نقطة نهاية موحدة لجميع عمليات الإشعارات
 */
ob_start();
require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

// تنظيف أي إخراج غير مقصود (تحذيرات PHP)
if (ob_get_level()) ob_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// دعم بيانات JSON المرسلة عبر App.post()
$jsonInput = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $rawBody = file_get_contents('php://input');
    $jsonInput = json_decode($rawBody, true) ?: [];
    // نقل بيانات JSON إلى $_POST حتى يعمل التحقق من CSRF ودوال أخرى
    foreach ($jsonInput as $key => $value) {
        if (!isset($_POST[$key])) {
            $_POST[$key] = $value;
        }
    }
}

$userId = intval($_SESSION['user_id']);
$action = $_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // جلب قائمة الإشعارات
            $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
            $offset = max(0, intval($_GET['offset'] ?? 0));
            $type = $_GET['type'] ?? null;
            $unread = ($_GET['unread'] ?? '0') === '1';
            $read = ($_GET['read'] ?? '0') === '1';
            $search = trim($_GET['search'] ?? '');

            $notifications = AppNotification::getForUser($userId, $limit, $offset, $type, $unread, $read, $search ?: null);
            $unreadCount = AppNotification::getUnreadCount($userId);

            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
                'last_id' => AppNotification::getLastId($userId),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'dropdown':
            // جلب إشعارات القائمة المنسدلة (آخر 10)
            $notifications = AppNotification::getForUser($userId, 10, 0);
            $unreadCount = AppNotification::getUnreadCount($userId);

            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'poll':
            // Polling للإشعارات الجديدة
            $lastId = max(0, intval($_GET['last_id'] ?? 0));
            $result = AppNotification::pollNew($userId, $lastId);

            echo json_encode([
                'success' => true,
                'new_notifications' => $result['notifications'],
                'unread_count' => $result['unread_count'],
                'last_id' => AppNotification::getLastId($userId),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'mark_read':
            // تحديد إشعار كمقروء
            if (!AppCsrf::verify()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'خطأ أمني: رمز CSRF غير صالح. يرجى تحديث الصفحة.', 'csrf_error' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            $notifId = intval($_POST['notification_id'] ?? $_POST['id'] ?? $jsonInput['notification_id'] ?? $jsonInput['id'] ?? 0);
            if ($notifId <= 0) {
                echo json_encode(['success' => false, 'message' => 'معرف الإشعار غير صالح'], JSON_UNESCAPED_UNICODE);
                break;
            }
            AppNotification::markRead($notifId, $userId);
            echo json_encode([
                'success' => true,
                'unread_count' => AppNotification::getUnreadCount($userId),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'mark_all_read':
            // تحديد الكل كمقروء
            if (!AppCsrf::verify()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'خطأ أمني: رمز CSRF غير صالح. يرجى تحديث الصفحة.', 'csrf_error' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            AppNotification::markAllRead($userId);
            echo json_encode([
                'success' => true,
                'unread_count' => AppNotification::getUnreadCount($userId),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            // حذف إشعار
            if (!AppCsrf::verify()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'خطأ أمني: رمز CSRF غير صالح. يرجى تحديث الصفحة.', 'csrf_error' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            $notifId = intval($_POST['notification_id'] ?? $_POST['id'] ?? $jsonInput['notification_id'] ?? $jsonInput['id'] ?? 0);
            if ($notifId <= 0) {
                echo json_encode(['success' => false, 'message' => 'معرف الإشعار غير صالح'], JSON_UNESCAPED_UNICODE);
                break;
            }
            AppNotification::delete($notifId, $userId);
            echo json_encode([
                'success' => true,
                'unread_count' => AppNotification::getUnreadCount($userId),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'cleanup':
            // تنظيف الإشعارات المقروءة الأقدم من 30 يوم
            if (!AppCsrf::verify()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'خطأ أمني: رمز CSRF غير صالح. يرجى تحديث الصفحة.', 'csrf_error' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            $days = max(1, intval($_POST['days'] ?? 30));
            $deleted = AppNotification::cleanup($days);
            $unreadCount = AppNotification::getUnreadCount($userId);
            echo json_encode([
                'success' => true,
                'deleted' => $deleted,
                'message' => "تم حذف {$deleted} إشعار مقروء قديم (أقدم من {$days} يوم)",
                'unread_count' => $unreadCount,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'unread_count':
            // فقط عدد غير المقروءة + آخر معرف
            echo json_encode([
                'success' => true,
                'unread_count' => AppNotification::getUnreadCount($userId),
                'last_id' => AppNotification::getLastId($userId),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'track':
            // تتبع تفاعل المستخدم مع الإشعار (Analytics)
            $notifId = intval($_POST['notification_id'] ?? 0);
            $actionType = $_POST['action_type'] ?? '';
            if ($notifId > 0 && in_array($actionType, ['read', 'click', 'dismiss', 'delete'])) {
                try {
                    $db = AppDatabase::getInstance();
                    $db->query(
                        "INSERT INTO notification_analytics (notification_id, user_id, action, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE action = VALUES(action), created_at = NOW()",
                        [$notifId, $userId, $actionType]
                    );
                } catch (\Throwable $e) { /* صامت — لا يُعطّل الواجهة */ }
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'track_analytics':
            // استقبال دفعة أحداث من Service Worker
            $eventsJson = $_POST['events'] ?? '[]';
            $events = json_decode($eventsJson, true);
            if (is_array($events)) {
                try {
                    $db = AppDatabase::getInstance();
                    $stmt = $db->getConnection()->prepare(
                        "INSERT INTO notification_analytics (notification_id, user_id, action, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE action = VALUES(action)"
                    );
                    foreach (array_slice($events, 0, 100) as $evt) {
                        $stmt->execute([
                            intval($evt['notification_id'] ?? 0),
                            $userId,
                            $evt['action'] ?? 'unknown',
                            intval(($evt['timestamp'] ?? time()) / 1000)
                        ]);
                    }
                } catch (\Throwable $e) { /* صامت */ }
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'prefs':
            // تفضيلات إشعارات المستخدم
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                // جلب التفضيلات
                try {
                    $db = AppDatabase::getInstance();
                    $row = $db->query("SELECT prefs FROM user_notification_prefs WHERE user_id = ?", [$userId])->fetch();
                    $prefs = $row ? json_decode($row['prefs'], true) : [];
                } catch (\Throwable $e) { $prefs = []; }
                echo json_encode(['success' => true, 'prefs' => $prefs ?: new \stdClass()], JSON_UNESCAPED_UNICODE);
            } else {
                // حفظ التفضيلات
                if (!AppCsrf::verify()) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'خطأ أمني'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $prefsJson = $_POST['prefs'] ?? '{}';
                $prefs = json_decode($prefsJson, true);
                if (!is_array($prefs)) $prefs = [];
                try {
                    $db = AppDatabase::getInstance();
                    $db->query(
                        "INSERT INTO user_notification_prefs (user_id, prefs, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs), updated_at = NOW()",
                        [$userId, json_encode($prefs, JSON_UNESCAPED_UNICODE)]
                    );
                } catch (\Throwable $e) { /* صامت */ }
                echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    // تسجيل الخطأ التفصيلي في السجلات فقط — لا نكشفه للمستخدم
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/api-errors.log', sprintf("[%s] [NOTIFICATIONS] %s\n", date('Y-m-d H:i:s'), $e->getMessage()), FILE_APPEND);
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء معالجة الطلب. يرجى المحاولة لاحقاً.'], JSON_UNESCAPED_UNICODE);
}

// === تنظيف نهائي: استخراج JSON فقط من المخزن المؤقت ===
// أي تحذير/إشعار PHP أثناء التنفيذ يختلط مع JSON ويُكسّر JSON.parse
// هنا نستخرج أول { أو [ من المخرجات ونتجاهل ما قبله
if (ob_get_level()) {
    $raw = ob_get_clean();
    if ($raw === false || $raw === '') {
        // المخزن فارغ — لا شيء للإخراج
    } else {
        $pos = strpos($raw, '{"');
        if ($pos === false) {
            $pos = strpos($raw, '[');
        }
        if ($pos !== false && $pos > 0) {
            echo substr($raw, $pos);
        } else {
            echo $raw;
        }
    }
}
