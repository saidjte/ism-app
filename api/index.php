<?php
/**
 * ===== نقطة الدخول المركزية لـ API =====
 * يوجه كل طلبات API عبر ApiRouter الموحد
 *
 * جميع نقاط نهاية API تمر من هنا:
 *   - مصادقة موحدة عبر وسيط AuthMiddleware
 *   - حماية CSRF موحدة عبر وسيط CsrfMiddleware
 *   - استجابات موحدة عبر ApiResponse
 *   - تسجيل أخطاء موحد عبر appLog()
 *
 * v4.35.0: إصلاح نظام الوسيط — كل مسار يملك وسيطه الخاص
 */

// === ضمان إرجاع JSON دائماً حتى في حالة الخطأ ===
ob_start();

// === تحميل الأنظمة الأساسية ===
try {
    require_once __DIR__ . '/../includes/bootstrap.php';
} catch (\Throwable $e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => ['type' => 'bootstrap_error', 'message' => 'خطأ في تحميل النظام. يرجى تحديث الصفحة.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تنظيف أي output غير مرغوب من bootstrap
if (ob_get_level()) ob_clean();

// === تحميل ApiRouter والمتحكمات ===
require_once __DIR__ . '/../includes/ApiRouter.php';
require_once __DIR__ . '/../includes/Controllers/ApiController.php';
require_once __DIR__ . '/../includes/Controllers/SaleController.php';
require_once __DIR__ . '/../includes/Controllers/NotificationController.php';
require_once __DIR__ . '/../includes/Controllers/PushController.php';
require_once __DIR__ . '/../includes/Controllers/ProductApiController.php';
require_once __DIR__ . '/../includes/Controllers/WhatsAppController.php';
require_once __DIR__ . '/../includes/Controllers/PriceCheckController.php';
require_once __DIR__ . '/../includes/Controllers/ExpiryController.php';
require_once __DIR__ . '/../includes/Router/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../includes/Router/Middleware/CsrfMiddleware.php';

// === تعريف دوال الوسيط ===

/** وسيط المصادقة — يتحقق من تسجيل الدخول عبر الجلسة */
$authMiddleware = function($params) {
    return AuthMiddleware::handle();
};

/** وسيط CSRF — يتحقق من رمز الحماية للطلبات الكاتبة */
$csrfMiddleware = function($params) {
    return CsrfMiddleware::handle();
};

/** وسيط صلاحية المدير */
$adminMiddleware = function($params) {
    return AuthMiddleware::admin();
};

/** إنشاء وسيط صلاحية محددة */
function makePermissionMiddleware(string $permission): callable {
    return function($params) use ($permission) {
        $result = AuthMiddleware::requirePermission($permission);
        return $result !== false;
    };
}

// === تسجيل المسارات ===
$router = new ApiRouter();

// ───────────────────────────────────────────
//  نقاط نهاية عامة (بدون مصادقة)
// ───────────────────────────────────────────

$router->group('api', function($router) {

    // بيانات التحقق من الأسعار — PWA عامة (بدون مصادقة)
    $router->get('price-check-data', [PriceCheckController::class, 'getData']);

});

// ───────────────────────────────────────────
//  نقاط نهاية تتطلب مصادقة فقط
// ───────────────────────────────────────────

$router->middleware($authMiddleware)->group('api', function($router) {

    // بحث المنتجات (GET — لا يتطلب CSRF)
    $router->get('search-products', [ProductApiController::class, 'search']);

    // وحدات القياس (GET — لا يتطلب CSRF)
    $router->get('get-units', [ProductApiController::class, 'getUnits']);
    $router->get('get-purchase-units', [ProductApiController::class, 'getPurchaseUnits']);
    $router->get('get-batches', [ProductApiController::class, 'getBatches']);

    // الإشعارات (GET — بدون CSRF)
    $router->get('notifications', [NotificationController::class, 'handle']);

    // اشتراكات Push (GET — بدون CSRF)
    $router->get('push-subscribe', [PushController::class, 'handle']);

});

// ───────────────────────────────────────────
//  نقاط نهاية تتطلب مصادقة + CSRF
// ───────────────────────────────────────────

$router->middleware($authMiddleware)->middleware($csrfMiddleware)->group('api', function($router) {

    // الإشعارات (POST — يتطلب CSRF)
    $router->post('notifications', [NotificationController::class, 'handle']);

    // اشتراكات Push (POST — يتطلب CSRF)
    $router->post('push-subscribe', [PushController::class, 'handle']);

    // إخفاء تنبيه الصلاحية (POST — يتطلب CSRF)
    $router->post('dismiss-expiry-popup', [ExpiryController::class, 'dismissPopup']);

});

// ───────────────────────────────────────────
//  نقاط نهاية تتطلب مصادقة + CSRF + صلاحية
// ───────────────────────────────────────────

$router->middleware($authMiddleware)->middleware($csrfMiddleware)->middleware(makePermissionMiddleware('sales.add'))->group('api', function($router) {

    // معالجة مبيعات POS (POST — يتطلب CSRF + صلاحية)
    $router->post('pos-process-sale', [SaleController::class, 'processSale']);

});

// ───────────────────────────────────────────
//  التصدير (GET — يتطلب مصادقة + صلاحية)
// ───────────────────────────────────────────

$router->middleware($authMiddleware)->middleware(makePermissionMiddleware('export.data'))->group('api', function($router) {

    $router->get('export', function($params) {
        // التحقق من CSRF للتصدير (يأتي عبر GET مع csrf_token)
        $csrfToken = $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
            http_response_code(403);
            die('رمز الأمان (CSRF) غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
        // تفويض إلى الملف الإجرائي (يُخرج CSV/PDF)
        include __DIR__ . '/export.php';
    });

});

// ───────────────────────────────────────────
//  نقاط نهاية تتطلب صلاحية المدير
// ───────────────────────────────────────────

$router->middleware($authMiddleware)->middleware($adminMiddleware)->middleware($csrfMiddleware)->group('api', function($router) {

    // اختبار اتصال UltraMsg (POST — يتطلب CSRF + admin)
    $router->post('test-ultramsg', [WhatsAppController::class, 'testUltraMsg']);

    // إرسال تنبيه واتساب (POST — يتطلب CSRF + admin)
    $router->post('send-whatsapp-alert', [WhatsAppController::class, 'sendAlert']);

});

// === تنفيذ التوجيه ===
$router->dispatch();
