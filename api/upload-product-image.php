<?php
/**
 * API رفع صور المنتجات — AJAX endpoint
 *
 * يستخدم في صفحات إضافة/تعديل المنتج لرفع الصور بشكل غير متزامن
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
    // التحقق من طريقة الطلب
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير مسموحة');
    }

    // التحقق من CSRF
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (class_exists('AppCsrf') && method_exists('AppCsrf', 'verify')) {
        AppCsrf::verify($csrfToken);
    } elseif (class_exists('Csrf') && method_exists('Csrf', 'verify')) {
        Csrf::verify($csrfToken);
    } elseif (function_exists('requireCSRF')) {
        // طريقة أخرى للتحقق
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            throw new Exception('رمز الأمان غير صالح');
        }
    }

    // معرف المنتج
    $productId = intval($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        throw new Exception('معرف المنتج غير صالح');
    }

    // التحقق من وجود الملف
    if (empty($_FILES['image']) || !isset($_FILES['image']['tmp_name']) || empty($_FILES['image']['tmp_name'])) {
        throw new Exception('لم يتم اختيار ملف');
    }

    // خيارات إزالة الخلفية
    $removeBg = !empty($_POST['remove_bg']) && $_POST['remove_bg'] === '1';
    $bgTolerance = $_POST['bg_tolerance'] ?? 'medium';
    if (!in_array($bgTolerance, ['low', 'medium', 'high'])) {
        $bgTolerance = 'medium';
    }

    // رفع الصورة
    require_once __DIR__ . '/../includes/ProductImageService.php';
    $result = ProductImageService::upload($productId, $_FILES['image'], $removeBg, $bgTolerance);

    // تحويل المسارات إلى مسارات ويب
    if ($result['success']) {
        if (!empty($result['original_path'])) {
            $result['original_path_web'] = ProductImageService::getWebPath($result['original_path']);
        }
        if (!empty($result['medium_path'])) {
            $result['medium_path_web'] = ProductImageService::getWebPath($result['medium_path']);
        }
        if (!empty($result['thumbnail_path'])) {
            $result['thumbnail_path_web'] = ProductImageService::getWebPath($result['thumbnail_path']);
        }
    }

    ApiResponse::custom($result);

} catch (Throwable $e) {
    ApiResponse::custom([
        'success' => false,
        'error' => ErrorHandler::safeExceptionMessage($e, 'حدث خطأ أثناء رفع الصورة'),
    ], 400);
}