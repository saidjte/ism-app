<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
    exit;
}

$product_id = intval($_GET['product_id'] ?? 0);
if ($product_id <= 0) {
    echo json_encode(['purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
    exit;
}

$product = fetchOne("SELECT unit, purchase_unit, purchase_unit_factor FROM products WHERE id = ?", [$product_id]);
if (!$product) {
    echo json_encode(['purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
    exit;
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

echo json_encode([
    'purchase_unit' => $purchase_unit,
    'purchase_unit_factor' => $purchase_unit_factor,
    'selling_unit' => $product['unit'],
    'units' => $purchase_additional
]);
