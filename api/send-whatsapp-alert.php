<?php
/**
 * API: Send WhatsApp Expiry Alert — غلاف رقيق
 * يفوّض إلى WhatsAppController عبر الموجه المركزي
 *
 * v5.21.0: تحويل إلى غلاف رقيق يفوّض إلى المتحكم المركزي
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// v6.3.2 (أمني — المرحلة 0): هذا المسار كان متاحاً لأي زائر بدون جلسة ولا CSRF
// ويسمح بتشغيل إرسال تنبيهات WhatsApp للعملاء. الإصلاح: مصادقة إلزامية + CSRF.
// ملاحظة: الإرسال التلقائي المجدول يمر عبر cron/whatsapp-daily.php (محمي بمفتاح) وليس من هنا
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCSRF();

// تفويض إلى المتحكم المركزي
$controller = new WhatsAppController();
$controller->sendAlert();