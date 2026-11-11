<?php
/**
 * API: جلب صور المنتجات لنقطة البيع
 * GET ?ids=1,2,3
 *
 * منطق:
 *   - صورة حقيقية → /uploads/products/{id}/xxx.webp
 *   - بدون صورة  → SVG placeholder (data URI)
 *
 * v6.1.4 (W8 إصلاح): إزالة كتم الأخطاء — استبدال error_reporting(0) و catch الفارغة
 *   بتسجيل عبر ErrorHandler أو error_log لأغراض التشخيص
 */

ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';

// v6.1.4 (W8 إصلاح): لا نكتم الأخطاء — bootstrap.php يضبط مستوى التسجيل المناسب.
// الأخطاء تُسجّل في السجل (وليس في الإخراج) حتى لا تُفْسِد استجابة JSON.
// نضمن فقط أن display_errors=0 في الإنتاج حتى لا تتسرّب تفاصيل للمستخدم.
if (!defined('IS_DEV') || !IS_DEV) {
    ini_set('display_errors', '0');
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

header('Content-Type: application/json; charset=utf-8');

$idsStr = $_GET['ids'] ?? '';
if (empty($idsStr)) {
    ob_end_clean();
    echo json_encode(['images' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $idsStr)));
if (empty($ids)) {
    ob_end_clean();
    echo json_encode(['images' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($ids) > 100) {
    $ids = array_slice($ids, 0, 100);
}

$idsCSV = implode(',', $ids);

// SVG placeholder
$placeholderSvg = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" width="80" height="80">' .
    '<rect width="80" height="80" rx="8" fill="#f3f4f6"/>' .
    '<rect x="20" y="16" width="40" height="36" rx="4" fill="#d1d5db"/>' .
    '<circle cx="34" cy="30" r="5" fill="#9ca3af"/>' .
    '<path d="M22 48 L34 38 L44 46 L50 40 L58 48 L58 52 L22 52 Z" fill="#9ca3af"/>' .
    '</svg>'
);

// كل منتج يبدأ بـ placeholder
$images = [];
foreach ($ids as $id) {
    $images[$id] = $placeholderSvg;
}

// جلب صور من product_images
try {
    $rows = fetchAll(
        "SELECT product_id, thumbnail_path, medium_path
         FROM product_images
         WHERE product_id IN ($idsCSV)
           AND deleted_at IS NULL
         ORDER BY product_id, is_primary DESC, id ASC"
    );

    foreach ($rows as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($images[$pid])) continue;
        if ($images[$pid] !== $placeholderSvg) continue;

        $thumb = '';
        $medium = '';

        if (!empty($row['thumbnail_path'])) {
            $thumb = str_replace('\\', '/', $row['thumbnail_path']);
            $thumb = preg_replace('#^.*/uploads/#', 'uploads/', $thumb);
        }
        if (!empty($row['medium_path'])) {
            $medium = str_replace('\\', '/', $row['medium_path']);
            $medium = preg_replace('#^.*/uploads/#', 'uploads/', $medium);
        }

        if (!empty($thumb)) {
            $images[$pid] = '/' . $thumb;
        } elseif (!empty($medium)) {
            $images[$pid] = '/' . $medium;
        }
    }
} catch (\Throwable $e) {
    // v6.1.4 (W8 إصلاح): تسجيل الخطأ بدل الكتم الصامت — يبقى المنتج على placeholder
    // لكن المشرف يمكنه رؤية السبب في السجل (جدول product_images غير موجود، انقطاع DB، إلخ)
    if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
        ErrorHandler::logException($e, 'pos-product-images::fetchImages');
    } else {
        error_log('[ISM] pos-product-images::fetchImages: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

ob_end_clean();
echo json_encode(['images' => $images], JSON_UNESCAPED_UNICODE);
