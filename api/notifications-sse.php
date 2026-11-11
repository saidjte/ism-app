<?php
/**
 * ========================================================================
 *  نقطة نهاية Server-Sent Events (SSE) للإشعارات الفورية
 *  تحل محل polling التقليدي — اتصال مستمر يُرسل الأحداث فوراً
 *
 *  الاستخدام (JavaScript):
 *    const es = new EventSource('/api/notifications-sse.php?last_id=123');
 *    es.addEventListener('notification', e => { ... });
 *    es.addEventListener('unread_count', e => { ... });
 *
 *  آلية العمل:
 *  1. اتصال HTTP طويل الأمد (حتى 5 دقائق)
 *  2. فحص إشعارات جديدة كل 5 ثوانٍ
 *  3. إرسال أحداث فورية عند وجود إشعارات جديدة
 *  4. heartbeat كل 25 ثانية لمنع انقطاع الاتصال
 *  5. إعادة الاتصال تلقائياً من المتصفح عند انقطاع
 *
 *  اعتبارات الأداء:
 *  - session_write_close() فوري لتحرير قفل الجلسة
 *  - لا يُستدعى fastcgi_finish_request() — يُنهي الاتصال
 *  - max_execution_time = 0 فقط لهذا السكربت
 *  - X-Accel-Buffering: no لتعطيل تخزين nginx المؤقت
 * ========================================================================
 */

// تعطيل حد الوقت لهذا الاتصال الطويل
@set_time_limit(0);
@ignore_user_abort(false);

// بدء الجلسة لقراءة user_id ثم تحرير القفل فوراً
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = intval($_SESSION['user_id'] ?? 0);
$csrfToken = $_SESSION['csrf_token'] ?? '';
session_write_close(); // تحرير القفل — يسمح بطلبات أخرى من نفس المستخدم

// التحقق من المصادقة
if ($userId <= 0) {
    http_response_code(401);
    header('Content-Type: text/event-stream');
    echo "event: error\ndata: " . json_encode(['code' => 'unauthorized', 'message' => 'يجب تسجيل الدخول']) . "\n\n";
    exit;
}

// ===== رؤوس SSE =====
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // تعطيل تخزين nginx المؤقت
header('Pragma: no-cache');
header('Expires: 0');

// تنظيف أي مخازن مؤقتة
while (ob_get_level()) {
    ob_end_clean();
}

// تحميل الأنظمة الأساسية
require_once __DIR__ . '/../includes/bootstrap.php';

// ===== فحص إعداد SSE — هل هو مفعّل؟ =====
$sseEnabled = '0'; // افتراضي: معطّل — يجب تفعيله من الإعدادات
try {
    $sseEnabled = getSetting('notifications_sse_enabled', '0');
} catch (\Throwable $e) {
    // الإعداد غير موجود — نستخدم الافتراضي (مفعّل)
}

if ($sseEnabled !== '1') {
    // SSE معطّل من الإعدادات — إرسال حدث خطأ ثم إغلاق
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    echo "event: disabled\ndata: " . json_encode([
        'code' => 'sse_disabled',
        'message' => 'SSE معطّل من الإعدادات — يُستخدم polling بدلاً منه',
        'fallback' => 'polling',
    ]) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
    exit;
}

// ===== معاملات الاتصال =====
$lastId = max(0, intval($_GET['last_id'] ?? 0));
$pollInterval = 5;       // ثوانٍ بين كل فحص
$heartbeatInterval = 25; // ثوانٍ بين كل heartbeat
$maxLifetime = 300;      // 5 دقائق — أقصى مدة للاتصال
$startTime = time();
$lastHeartbeat = $startTime;

/**
 * إرسال حدث SSE
 * @param string $event اسم الحدث
 * @param mixed $data البيانات (تُحوّل لـ JSON تلقائياً)
 */
function sendSSE(string $event, $data): void {
    echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ===== إرسال حدث الاتصال الأولي =====
sendSSE('connected', [
    'last_id' => $lastId,
    'server_time' => time(),
]);

// ===== الحلقة الرئيسية =====
while (!connection_aborted() && (time() - $startTime) < $maxLifetime) {
    try {
        // فحص إشعارات جديدة
        if (class_exists('AppNotification')) {
            $result = AppNotification::pollNew($userId, $lastId);

            if (!empty($result['notifications'])) {
                foreach ($result['notifications'] as $n) {
                    $newId = max($lastId, intval($n['id']));
                    $lastId = $newId;
                    sendSSE('notification', $n);
                }
                // إرسال عدد غير المقروءة
                sendSSE('unread_count', ['count' => $result['unread_count'] ?? 0]);
            }
        }
    } catch (\Throwable $e) {
        // تسجيل الخطأ دون إيقاف الاتصال
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'notifications-sse::poll');
        }
        sendSSE('error', ['code' => 'poll_error', 'message' => 'خطأ مؤقت في جلب الإشعارات']);
    }

    // ===== Heartbeat — منع انقطاع الاتصال =====
    $now = time();
    if (($now - $lastHeartbeat) >= $heartbeatInterval) {
        echo ": heartbeat {$now}\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        $lastHeartbeat = $now;
    }

    // انتظار قبل الفحص التالي
    sleep($pollInterval);
}

// ===== إرسال حدث الإغلاق =====
sendSSE('close', [
    'reason' => 'timeout',
    'last_id' => $lastId,
    'reconnect' => true,
]);
