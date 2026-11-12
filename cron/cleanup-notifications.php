<?php
/**
 * ========================================================================
 *  تنظيف الإشعارات المقروءة القديمة
 *  يُستدعى عبر cron النظام أو wget/curl
 *
 *  يحذف الإشعارات المقروءة الأقدم من 30 يوماً لتقليل حجم الجدول
 *  وتحسين أداء استعلامات الإشعارات.
 *
 *  أمثلة إعداد cron:
 *  - يومياً الساعة 3 صباحاً: 0 3 * * * php /path/to/app/cron/cleanup-notifications.php
 *  - أو عبر wget:             0 3 * * * wget -q -O /dev/null "https://example.com/cron/cleanup-notifications.php?key=YOUR_SECRET_KEY"
 *  - أو عبر curl:             0 3 * * * curl -s "https://example.com/cron/cleanup-notifications.php?key=YOUR_SECRET_KEY" > /dev/null
 * ========================================================================
 */

// ===== وضع CLI (سطر الأوامر) =====
if (php_sapi_name() === 'cli') {
    $isCLI = true;
    $isWeb = false;
} else {
    // ===== وضع الويب (wget/curl) =====
    $isCLI = false;
    $isWeb = true;

    header('Content-Type: application/json; charset=utf-8');

    // التحقق من مفتاح الأمان لمنع الاستدعاء العشوائي
    $providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
    if (empty($providedKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'مفتاح الأمان مطلوب — أضف ?key=YOUR_SECRET_KEY']);
        exit;
    }
}

// تحميل الأنظمة الأساسية
require_once __DIR__ . '/../includes/bootstrap.php';

// التحقق من مفتاح الأمان (في وضع الويب)
if ($isWeb) {
    $configKey = getSetting('cron_secret_key', '');
    if (empty($configKey)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'مفتاح cron غير مُعد — أضف إعداد cron_secret_key من صفحة الإعدادات']);
        exit;
    }
    if (!hash_equals($configKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'مفتاح الأمان غير صحيح']);
        exit;
    }
}

// ===== تنفيذ التنظيف =====
$archived = 0;
$deleted = 0;
$expired = 0;
$daysThreshold = 30; // عدد الأيام — الإشعارات المقروءة الأقدم من هذا العدد تُؤرشف ثم تُحذف

try {
    $pdo = \Database::getInstance()->getConnection();

    // ── TTL: تعيين الإشعارات غير المقروءة المنتهية كمقروءة ──
    $ttlDays = intval(getSetting('notification_ttl_days', '90'));
    if ($ttlDays > 0) {
        $expired = intval($pdo->exec(
            "UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND created_at < DATE_SUB(NOW(), INTERVAL {$ttlDays} DAY)"
        ));
    }

    // عدد الإشعارات المقروءة قبل التنظيف (للتقرير)
    $beforeCount = intval($pdo->query(
        "SELECT COUNT(*) FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL {$daysThreshold} DAY)"
    )->fetchColumn());

    if ($beforeCount > 0) {
        // ── أرشفة قبل الحذف ──
        $hasArchive = false;
        try {
            $pdo->query("SELECT 1 FROM archive_notifications LIMIT 1");
            $hasArchive = true;
        } catch (\Throwable $e) {}

        if ($hasArchive) {
            $pdo->exec(
                "INSERT INTO archive_notifications (user_id, type, title, message, data, title_key, message_key, message_params, priority, group_key, group_count, original_created_at, archived_at)
                 SELECT user_id, type, title, message, data, title_key, message_key, message_params, priority, group_key, group_count, created_at, NOW()
                 FROM notifications
                 WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL {$daysThreshold} DAY)"
            );
            $archived = intval($pdo->query("SELECT ROW_COUNT()")->fetchColumn());
        }

        // ── حذف الإشعارات المقروءة القديمة ──
        $deleted = intval($pdo->exec(
            "DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL {$daysThreshold} DAY)"
        ));
    }

    // تحسين الجدول بعد الحذف (إعادة تنظيم المساحة)
    if ($deleted > 0) {
        try {
            $pdo->exec("OPTIMIZE TABLE notifications");
        } catch (\Throwable $e) {
            error_log("cron/cleanup-notifications: OPTIMIZE TABLE فشل: " . $e->getMessage());
        }
    }

    $result = [
        'success'  => true,
        'archived' => $archived,
        'deleted'  => $deleted,
        'expired'  => $expired,
        'message'  => "أرشفة: {$archived} | حذف: {$deleted} | TTL منتهي: {$expired} | أقدم من {$daysThreshold} يوم",
        'datetime' => date('Y-m-d H:i:s'),
    ];

} catch (\Throwable $e) {
    error_log("cron/cleanup-notifications: فشل التنظيف: " . $e->getMessage());
    $result = [
        'success' => false,
        'error'   => $e->getMessage(),
        'datetime' => date('Y-m-d H:i:s'),
    ];
}

// ===== الإخراج =====
if ($isCLI) {
    echo $result['success']
        ? "[cleanup-notifications] {$result['message']}\n"
        : "[cleanup-notifications] خطأ: {$result['error']}\n";
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}