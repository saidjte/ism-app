<?php
/**
 * API لجلب وحدات القياس الإضافية لمنتج معين
 */

require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

$product_id = intval($_GET['product_id'] ?? 0);

if ($product_id <= 0) {
    ApiResponse::custom(['units' => []]);
}

$units = [];
try {
    $units = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$product_id]);
} catch (Exception $e) {
    // جدول additional_units قد لا يكون موجوداً بعد
}

ApiResponse::custom(['units' => $units]);