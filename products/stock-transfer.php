<?php
require_once '../includes/bootstrap.php';
$page_title = t('products_stock_transfer');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('stock.transfer');

$errors = [];
$success = false;
$transfer_info = null;

$products = ProductService::getAllBasic();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $from_id = intval($_POST['from_product_id'] ?? 0);
    $to_id = intval($_POST['to_product_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($from_id <= 0) $errors[] = 'يرجى اختيار المنتج المصدر';
    if ($to_id <= 0) $errors[] = 'يرجى اختيار المنتج الوجهة';
    if ($from_id === $to_id && $from_id > 0) $errors[] = 'لا يمكن التحويل لنفس المنتج';
    if ($quantity <= 0) $errors[] = t('common_quantity_must_be_positive');

    if (empty($errors)) {
        try {
            $result = ProductService::transferBetweenProducts($from_id, $to_id, $quantity, $notes);
            $success = true;
            $transfer_info = $result['transfer_info'];
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'stock-transfer:transfer');
            $errors[] = $e->getMessage() ?: t('transfer_error');
        }
    }
}

// Re-fetch products after transfer
if ($success) {
    $products = ProductService::getAllBasic();
}

require_once '../includes/header.php';
require_once __DIR__ . '/Templates/stock-transfer-template.php';
require_once '../includes/footer.php';