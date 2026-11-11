<?php
// API لجلب وحدات القياس الإضافية لمنتج معين
header('Content-Type: application/json; charset=utf-8');

// التحقق من الجلسة
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['units' => []]);
    exit;
}

require_once '../config/database.php';

$product_id = intval($_GET['product_id'] ?? 0);

if ($product_id <= 0) {
    echo json_encode(['units' => []]);
    exit;
}

$units = [];
try {
    $units = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$product_id]);
} catch (Exception $e) {
    // جدول additional_units قد لا يكون موجوداً بعد
}

echo json_encode(['units' => $units]);
