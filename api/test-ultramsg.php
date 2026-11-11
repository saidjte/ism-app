<?php
/**
 * API: Test UltraMsg Connection — غلاف رقيق
 * يفوّض إلى WhatsAppController عبر الموجه المركزي
 *
 * v5.21.0: تحويل إلى غلاف رقيق يفوّض إلى المتحكم المركزي
 */

// تشغيل output buffering لمنع أي تحذيرات من إفساد JSON
ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';

// v6.3.2 (أمني — المرحلة 0): هذا المسار كان متاحاً لأي زائر بدون جلسة ولا CSRF
// ويسمح بإرسال رسائل WhatsApp اختبارية عبر حساب UltraMsg المرتبط بالنظام.
// الإصلاح: مصادقة جلسة إلزامية + فحص CSRF (يدعم X-CSRF-Token وجسم JSON)
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCSRF();

// تفويض إلى المتحكم المركزي
$controller = new WhatsAppController();
$controller->testUltraMsg();