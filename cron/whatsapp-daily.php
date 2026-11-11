<?php
/**
 * Cron Job: Daily WhatsApp Expiry Alert
 * 
 * This file should be called daily by a scheduled task (Windows Task Scheduler).
 * It checks for expired/expiring products and sends a WhatsApp alert automatically.
 * 
 * Usage: Call this URL daily via Windows Task Scheduler or cron job
 * Example: http://localhost/is-app/cron/whatsapp-daily.php?key=YOUR_SECRET_KEY
 * 
 * Security: v6.3.2 (أمني — المرحلة 0) — مفتاح ثابت عشوائي من الإعدادات
 * (cron_secret_key) مع مقارنة hash_equals — بدل المفتاح اليومي القابل للحساب
 * md5(DB_NAME . 'cron_wa_' . date('Y-m-d')) الذي كان أي شخص يستطيع تخمينه.
 * ضبط المفتاح: صفحة الإعدادات → حقل "مفتاح أمان cron" (نفس مفتاح auto-backup)
 */

// Prevent direct browser access (only allow via server/cron)
header('Content-Type: application/json; charset=utf-8');

// Load dependencies
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upgrades.php';
require_once __DIR__ . '/../includes/whatsapp_alert.php';
if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/../includes/ErrorHandler.php'; }

// Start session for CSRF
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Security check — v6.3.2 (أمني — المرحلة 0):
// سابقاً: $expected_key = md5(DB_NAME . 'cron_wa_' . date('Y-m-d')) — قابل
// للحساب من قبل أي شخص يعرف اسم قاعدة البيانات (الافتراضي inventory_db)!
// الآن: سر ثابت عشوائي من إعدادات النظام (نفس نمط cron/auto-backup.php)
// مع مقارنة timing-safe عبر hash_equals
$provided_key = $_GET['key'] ?? '';
$config_key = '';
try {
    $config_key = trim((string) getSetting('cron_secret_key', ''));
} catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp-daily::cronKey'); }

if ($config_key === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'مفتاح cron غير مُعد — عيّن "مفتاح أمان cron" من صفحة الإعدادات أولاً'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($provided_key === '' || !hash_equals($config_key, $provided_key)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'مفتاح غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Check if cron is enabled in settings
$cron_enabled = false;
try {
    $cron_enabled = (getSetting('whatsapp_cron_enabled', '0') === '1');
} catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp-daily::cronEnabled'); }

if (!$cron_enabled) {
    echo json_encode(['success' => false, 'message' => 'الإرسال التلقائي غير مفعّل في الإعدادات'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Check time window (allow execution within 30 minutes of scheduled time)
$cron_time = '09:00';
try {
    $cron_time = getSetting('whatsapp_cron_time', '09:00');
} catch (Exception $e) { ErrorHandler::logException($e, 'whatsapp-daily::cronTime'); }

$current_time = date('H:i');
// Allow execution within 1 hour of scheduled time
list($sched_hour, $sched_min) = explode(':', $cron_time);
list($curr_hour, $curr_min) = explode(':', $current_time);
$sched_minutes = intval($sched_hour) * 60 + intval($sched_min);
$curr_minutes = intval($curr_hour) * 60 + intval($curr_min);
$time_diff = abs($curr_minutes - $sched_minutes);

// Allow if within 60 minutes (or if forced via ?force=1)
$force = isset($_GET['force']) && $_GET['force'] === '1';
if (!$force && $time_diff > 60) {
    echo json_encode([
        'success' => false, 
        'message' => 'خارج نافذة الإرسال المجدول. الوقت الحالي: ' . $current_time . ' — الوقت المجدول: ' . $cron_time . '. استخدم ?force=1 للإرسال في أي وقت.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Execute the auto-send
$result = sendWhatsAppAlertAuto();

// Log the result
$log_message = date('Y-m-d H:i:s') . ' | Cron WhatsApp | ';
if ($result['sent']) {
    $log_message .= '✅ تم الإرسال (' . $result['method'] . '): ' . $result['message'];
} else {
    $log_message .= 'ℹ️ لم يُرسل: ' . $result['message'];
}

// Try to write to log file
$log_file = __DIR__ . '/../logs/whatsapp-cron.log';
$log_dir = dirname($log_file);
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
@file_put_contents($log_file, $log_message . "\n", FILE_APPEND);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
