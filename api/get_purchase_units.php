<?php
/**
 * API لجلب وحدات الشراء لمنتج معين
 */

require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

$product_id = intval($_GET['product_id'] ?? 0);
if ($product_id <= 0) {
    ApiResponse::custom(['purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
}

$product = fetchOne("SELECT unit, purchase_unit, purchase_unit_factor FROM products WHERE id = ?", [$product_id]);
if (!$product) {
    ApiResponse::custom(['purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
}

// إذا لم تكن هناك وحدة شراء أساسية محددة، استخدم وحدة البيع
$purchase_unit = $product['purchase_unit'] ?: $product['unit'];
$purchase_unit_factor = floatval($product['purchase_unit_factor'] ?? 1);

// إذا كانت وحدة الشراء = وحدة البيع، فالمعامل = 1
if (empty($product['purchase_unit'])) {
    $purchase_unit_factor = 1;
}

$purchase_additional = [];
try {
    $purchase_additional = fetchAll("SELECT * FROM purchase_additional_units WHERE product_id = ?", [$product_id]);
} catch (Exception $e) {
    // جدول قد لا يكون موجوداً بعد
}

ApiResponse::custom([
    'purchase_unit' => $purchase_unit,
    'purchase_unit_factor' => $purchase_unit_factor,
    'selling_unit' => $product['unit'],
    'units' => $purchase_additional
]);