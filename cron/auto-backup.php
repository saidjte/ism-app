<?php
/**
 * ========================================================================
 *  نقطة نهاية النسخ الاحتياطي التلقائي
 *  يُستدعى عبر cron النظام أو wget/curl
 *
 *  أمثلة إعداد cron:
 *  - كل يوم الساعة 2 صباحاً:     0 2 * * * php /path/to/app/cron/auto-backup.php
 *  - أو عبر wget:                0 2 * * * wget -q -O /dev/null "https://example.com/cron/auto-backup.php?key=YOUR_SECRET_KEY"
 *  - أو عبر curl:                0 2 * * * curl -s "https://example.com/cron/auto-backup.php?key=YOUR_SECRET_KEY" > /dev/null
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
require_once __DIR__ . '/../config/backup.php';

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

// ===== التحقق من تفعيل النسخ التلقائي =====
$enabled = getSetting('auto_backup_enabled', '0');
if ($enabled !== '1') {
    $msg = 'النسخ الاحتياطي التلقائي غير مفعّل';
    if ($isCLI) { echo $msg . "\n"; } else { echo json_encode(['success' => false, 'error' => $msg]); }
    exit;
}

// ===== التحقق من الوقت — هل حان موعد النسخة؟ =====
$interval = getSetting('auto_backup_frequency', 'daily');
$last_backup = getSetting('last_auto_backup', '');

$should_backup = false;
$reason = '';

if (empty($last_backup)) {
    $should_backup = true;
    $reason = 'لم يتم إنشاء نسخة تلقائية من قبل';
} else {
    $last_time = strtotime($last_backup);
    $now = time();
    $elapsed = $now - $last_time;

    switch ($interval) {
        case 'daily':
            $should_backup = ($elapsed >= 86400);
            $reason = $should_backup
                ? 'مرت أكثر من 24 ساعة منذ آخر نسخة (' . $last_backup . ')'
                : 'لم يمر 24 ساعة بعد منذ آخر نسخة (' . $last_backup . ')';
            break;
        case 'weekly':
            $should_backup = ($elapsed >= 604800);
            $reason = $should_backup
                ? 'مر أكثر من أسبوع منذ آخر نسخة (' . $last_backup . ')'
                : 'لم يمر أسبوع بعد منذ آخر نسخة (' . $last_backup . ')';
            break;
        case 'monthly':
            $should_backup = ($elapsed >= 2592000);
            $reason = $should_backup
                ? 'مر أكثر من شهر منذ آخر نسخة (' . $last_backup . ')'
                : 'لم يمر شهر بعد منذ آخر نسخة (' . $last_backup . ')';
            break;
        default:
            $should_backup = ($elapsed >= 86400);
            $reason = 'تكرار غير معروف (' . $interval . ')، استخدام يومي افتراضي';
    }
}

if (!$should_backup) {
    if ($isCLI) { echo "لا حاجة للنسخ الآن: {$reason}\n"; }
    else { echo json_encode(['success' => true, 'skipped' => true, 'reason' => $reason]); }
    exit;
}

// ===== إنشاء النسخة الاحتياطية =====
$result = [
    'success' => false,
    'timestamp' => date('Y-m-d H:i:s'),
    'interval' => $interval,
    'last_backup' => $last_backup,
    'reason' => $reason,
];

try {
    $labels = ['daily' => 'يومية', 'weekly' => 'أسبوعية', 'monthly' => 'شهرية'];
    $filename = createBackup('نسخة تلقائية - ' . ($labels[$interval] ?? $interval));

    // تسجيل وقت آخر نسخة تلقائية
    saveSetting('last_auto_backup', date('Y-m-d H:i:s'));

    $result['success'] = true;
    $result['filename'] = $filename;
    $result['message'] = "تم إنشاء نسخة احتياطية تلقائية: {$filename}";

    if ($isCLI) {
        echo "✅ {$result['message']}\n";
    } else {
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
} catch (\Throwable $e) {
    ErrorHandler::logException($e, 'auto-backup');
    $result['error'] = ErrorHandler::safeExceptionMessage($e, 'فشل إنشاء النسخة الاحتياطية.');
    $result['message'] = 'فشل إنشاء النسخة الاحتياطية.';

    if ($isCLI) {
        echo "❌ {$result['message']} — {$e->getMessage()}\n";
    } else {
        http_response_code(500);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
