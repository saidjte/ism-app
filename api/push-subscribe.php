<?php
/**
 * API اشتراكات الإشعارات الفورية
 * Push Subscriptions — نقطة نهاية موحدة لاشتراكات Web Push
 */

require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

header('Cache-Control: no-cache, no-store');

$userId = intval($_SESSION['user_id']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'subscribe':
            // حفظ اشتراك Push جديد
            requireCSRF();
            $endpoint = trim($_POST['endpoint'] ?? '');
            $p256dh = trim($_POST['p256dh'] ?? '');
            $authKey = trim($_POST['auth'] ?? '');
            $browser = trim($_POST['browser'] ?? '');

            if (empty($endpoint) || empty($p256dh) || empty($authKey)) {
                ApiResponse::custom(['success' => false, 'message' => 'بيانات الاشتراك غير مكتملة']);
            }

            $db = AppDatabase::getInstance();

            // التحقق من وجود الاشتراك مسبقاً
            $existing = $db->query(
                "SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?",
                [$userId, $endpoint]
            )->fetch();

            if ($existing) {
                // تحديث المفاتيح إذا تغيرت
                $db->query(
                    "UPDATE push_subscriptions SET p256dh = ?, auth_key = ?, browser = ?, updated_at = NOW() WHERE id = ?",
                    [$p256dh, $authKey, $browser, $existing['id']]
                );
            } else {
                // إنشاء اشتراك جديد
                $db->query(
                    "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, browser, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                    [$userId, $endpoint, $p256dh, $authKey, $browser]
                );
            }

            ApiResponse::custom(['success' => true, 'message' => 'تم حفظ الاشتراك بنجاح']);
            break;

        case 'unsubscribe':
            // حذف اشتراك Push
            requireCSRF();
            $endpoint = trim($_POST['endpoint'] ?? '');

            if (empty($endpoint)) {
                ApiResponse::custom(['success' => false, 'message' => 'بيانات الاشتراك مفقودة']);
            }

            $db = AppDatabase::getInstance();
            $db->query(
                "DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?",
                [$userId, $endpoint]
            );

            ApiResponse::custom(['success' => true, 'message' => 'تم إلغاء الاشتراك']);
            break;

        case 'status':
            // جلب حالة اشتراك المستخدم الحالي
            $db = AppDatabase::getInstance();
            $count = $db->query(
                "SELECT COUNT(*) as c FROM push_subscriptions WHERE user_id = ?",
                [$userId]
            )->fetchColumn();

            $pushEnabled = getSetting('push_notifications_enabled', '0');

            ApiResponse::custom([
                'success' => true,
                'has_subscription' => intval($count) > 0,
                'push_enabled' => $pushEnabled === '1',
                'browser_support' => true // يتم فحصها فعلياً في JavaScript
            ]);
            break;

        case 'permission_status':
            // تحديث حالة الإذن في قاعدة البيانات
            $permission = trim($_POST['permission'] ?? '');
            if (in_array($permission, ['granted', 'denied', 'default'])) {
                $db = AppDatabase::getInstance();
                // حفظ حالة الإذن كمعلومات المستخدم (optional)
                saveSetting('push_permission_user_' . $userId, $permission, 'حالة إذن الإشعارات للمستخدم ' . $userId);
            }
            ApiResponse::custom(['success' => true]);
            break;

        case 'vapid_key':
            // جلب مفتاح VAPID العام — للاستخدام من المتصفح
            $vapidPublicKey = '';
            try {
                $vapidPublicKey = trim(getSetting('vapid_public_key', ''));
            } catch (Exception $e) { /* تجاهل — المفتاح غير مُعد */ }
            ApiResponse::custom([
                'success' => true,
                'vapid_public_key' => $vapidPublicKey,
                'vapid_configured' => !empty($vapidPublicKey),
            ]);
            break;

        case 'generate_vapid':
            // توليد مفاتيح VAPID جديدة — مسؤول فقط
            requireCSRF();
            $userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
            if ($userRole !== 'admin') {
                ApiResponse::custom(['success' => false, 'message' => 'غير مصرح — مسؤول فقط'], 403);
                break;
            }
            if (!class_exists('VapidKeys')) {
                require_once __DIR__ . '/../includes/vapid-keys.php';
            }
            $result = VapidKeys::generateAndSave();
            ApiResponse::custom($result);
            break;

        default:
            ApiResponse::custom(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Exception $e) {
    // تسجيل الخطأ التفصيلي في السجلات فقط — لا نكشفه للمستخدم
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/api-errors.log', sprintf("[%s] [PUSH-SUBSCRIBE] %s\n", date('Y-m-d H:i:s'), $e->getMessage()), FILE_APPEND);

    ApiResponse::custom(['success' => false, 'message' => 'حدث خطأ أثناء معالجة الطلب. يرجى المحاولة لاحقاً.'], 500);
}