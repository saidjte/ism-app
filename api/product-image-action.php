<?php
/**
 * API إجراءات صور المنتجات — حذف / تعيين كرئيسية
 *
 * @since v5.10.1
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// التحقق من المصادقة مباشرة — يُرجع JSON بدل redirect
// ملاحظة: الجلسة تبدأ بالفعل في bootstrap.php — لا حاجة لاستدعاء session_start() مرة أخرى
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
    ApiResponse::custom(['success' => false, 'error' => 'غير مصادق — يرجى تسجيل الدخول'], 401);
}

header('Cache-Control: no-cache, no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير مسموحة');
    }

    // قراءة البيانات (JSON أو form-data)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    // التحقق من CSRF
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (class_exists('AppCsrf') && method_exists('AppCsrf', 'verify')) {
        AppCsrf::verify($csrfToken);
    } elseif (class_exists('Csrf') && method_exists('Csrf', 'verify')) {
        Csrf::verify($csrfToken);
    } elseif (function_exists('requireCSRF')) {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            throw new Exception('رمز الأمان غير صالح');
        }
    }

    $action = $input['action'] ?? '';
    $imageId = intval($input['image_id'] ?? 0);

    if ($imageId <= 0) {
        throw new Exception('معرف الصورة غير صالح');
    }

    require_once __DIR__ . '/../includes/ProductImageService.php';

    $result = match ($action) {
        'delete'     => ProductImageService::softDelete($imageId),
        'set_primary' => ProductImageService::setPrimary($imageId),
        'restore'    => ProductImageService::restore($imageId),
        default      => ['success' => false, 'error' => 'إجراء غير معروف'],
    };

    ApiResponse::custom($result);

} catch (Throwable $e) {
    ApiResponse::custom([
        'success' => false,
        'error' => ErrorHandler::safeExceptionMessage($e, 'حدث خطأ أثناء تنفيذ الإجراء'),
    ], 400);
}