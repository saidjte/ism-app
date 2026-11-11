<?php
// نظام تسجيل الأخطاء والأحداث

define('LOG_DIR', dirname(__DIR__) . '/logs');

/**
 * تسجيل رسالة في ملف اللوغ
 * @param string $message رسالة الخطأ
 * @param string $level مستوى الخطأ (error, warning, info, debug)
 * @param array $context بيانات إضافية
 */
function logMessage($message, $level = 'info', $context = []) {
    // Ensure log directory exists
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
    
    // Protect log directory
    $htaccess = LOG_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }
    
    $date = date('Y-m-d H:i:s');
    $logFile = LOG_DIR . '/app-' . date('Y-m-d') . '.log';
    
    // Build log entry
    $entry = "[$date] [$level] $message";
    
    if (!empty($context)) {
        $entry .= " | " . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    
    // Add request info
    if (isset($_SERVER['REQUEST_URI'])) {
        $entry .= " | URL: " . $_SERVER['REQUEST_URI'];
    }
    if (isset($_SERVER['REMOTE_ADDR'])) {
        $entry .= " | IP: " . $_SERVER['REMOTE_ADDR'];
    }
    
    $entry .= "\n";
    
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    
    // Rotate logs - keep only last 30 days
    $logFiles = glob(LOG_DIR . '/app-*.log');
    if ($logFiles && count($logFiles) > 30) {
        usort($logFiles);
        for ($i = 0; $i < count($logFiles) - 30; $i++) {
            @unlink($logFiles[$i]);
        }
    }
}

/**
 * تسجيل خطأ (shortcuts)
 */
function logError($message, $context = []) {
    logMessage($message, 'error', $context);
}

function logWarning($message, $context = []) {
    logMessage($message, 'warning', $context);
}

function logInfo($message, $context = []) {
    logMessage($message, 'info', $context);
}

function logDebug($message, $context = []) {
    logMessage($message, 'debug', $context);
}

/**
 * Set custom error handler
 * @deprecated v4.16.9 — لا تستخدم هذه الدالة. تم استبدالها بـ ErrorHandler::init()
 * الذي يُستدعى تلقائياً من bootstrap.php. استخدام هذه الدالة سيؤدي لتعارض
 * مع ErrorHandler المركزي.
 * @see ErrorHandler::init()
 */
function setAppErrorHandler() {
    // تم الإهمال — ErrorHandler::init() يُعالج كل شيء الآن
    trigger_error(
        'setAppErrorHandler() مهملة منذ v4.16.9 — استخدم ErrorHandler::init() بدلاً منها',
        E_USER_DEPRECATED
    );
    // لا نسجّل معالجات جديدة —ErrorHandler يعمل بالفعل
}
