<?php
/**
 * إخفاء نافذة تنبيه الصلاحية مع آلية إخفاء ذكي
 *
 * يتم استدعاؤها عبر AJAX عند إغلاق النافذة
 * - يُسجّل الإخفاء مع التوقيع الحالي في $_SESSION
 * - النافذة لن تظهر مرة أخرى لمدة ساعتين ما لم يتغيّر التوقيع (تنبيه جديد)
 */

require_once __DIR__ . '/../includes/bootstrap.php';

try {
    // التحقق من تسجيل الدخول
    if (!isLoggedIn()) {
        ApiResponse::unauthorized();
    }

    // التحقق من CSRF
    if (!verifyCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? '')) {
        ApiResponse::forbidden('رمز CSRF غير صالح');
    }

    // التحقق من وجود توقيع
    $sig = $_GET['sig'] ?? '';
    if (empty($sig)) {
        ApiResponse::error('معامل التوقيع مطلوب', 'validation', 422);
    }

    // تخزين وقت الإخفاء (طابع زمني)
    $_SESSION['expiry_popup_dismissed_at'] = time();

    // تخزين توقيع التنبيه الحالي للكشف عن التنبيهات الجديدة
    $_SESSION['expiry_popup_signature'] = $sig;

    ApiResponse::success([
        'dismissed_until' => date('Y-m-d H:i:s', time() + 7200),
    ], 'تم إخفاء التنبيه. سيظهر مجدداً بعد ساعتين أو عند وجود تنبيه جديد.');

} catch (Throwable $e) {
    ApiResponse::custom(['success' => false, 'message' => 'حدث خطأ أثناء معالجة الطلب.'], 500);
}