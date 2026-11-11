<?php
$page_title = 'جرد المخزون';
require_once '../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('stock.inventory_count');

$errors = [];
$success = false;

// AJAX: تحديث كمية منتج واحد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_qty') {
    requireCSRF();
    header('Content-Type: application/json');

    $pid = intval($_POST['product_id'] ?? 0);
    $new_qty = floatval($_POST['new_qty'] ?? 0);

    if ($pid > 0 && $new_qty >= 0) {
        try {
            ProductService::updateQuantity($pid, $new_qty);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'inventory-count:update_qty');
            echo json_encode(['success' => false, 'error' => ErrorHandler::safeExceptionMessage($e, 'حدث خطأ أثناء تحديث الكمية.')]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'بيانات غير صالحة']);
    }
    exit;
}

// حفظ الجرد الكامل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inventory'])) {
    requireCSRF();

    try {
        $count_date = $_POST['count_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        $products_data = $_POST['products'] ?? [];
        $actual_qtys = $_POST['actual_qty'] ?? [];

        // تجهيز قائمة الجرد بالتنسيق المطلوب من ProductService
        $items = [];
        foreach ($products_data as $index => $product_id) {
            $items[] = [
                'product_id' => intval($product_id),
                'actual_quantity' => floatval($actual_qtys[$index] ?? 0),
            ];
        }

        $result = ProductService::inventoryCount($items, $count_date, $notes);
        $success = true;
        $success_msg = $result['message'];
    } catch (Exception $e) {
        error_log('Inventory Count Error: ' . $e->getMessage());
        $errors[] = 'حدث خطأ أثناء جرد المخزون. يرجى المحاولة مرة أخرى.';
    }
}

// جلب كل المنتجات مرتبة بالكود
$products = ProductService::getInventoryProducts();

// إحصائيات سابقة
$last_inventory = null;
try {
    $last_inventory = ProductService::getLastInventorySummary();
} catch (Exception $e) { ErrorHandler::logException($e, 'inventory-count:fetch_last_inventory'); }

require_once '../includes/header.php';
require_once __DIR__ . '/Templates/inventory-count-template.php';
require_once '../includes/footer.php';