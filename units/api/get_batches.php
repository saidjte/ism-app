<?php
// API لجلب معلومات دفعات الشراء المتوفرة لمنتج معين (FIFO)
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
    echo json_encode(['error' => 'غير مصرح بالوصول']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$product_id = intval($_GET['product_id'] ?? 0);

if ($product_id <= 0) {
    echo json_encode(['error' => 'معرف المنتج مطلوب']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // جلب الدفعات المتوفرة مرتبة بالتاريخ (FIFO)
    $stmt = $pdo->prepare("SELECT id, original_quantity, remaining_quantity, purchase_price, purchase_date 
                           FROM purchase_batches 
                           WHERE product_id = ? AND remaining_quantity > 0 
                           ORDER BY purchase_date ASC, id ASC");
    $stmt->execute([$product_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // حساب متوسط سعر الشراء المرجح (Weighted Average) من الدفعات المتوفرة
    $total_remaining = 0;
    $total_cost = 0;
    
    foreach ($batches as $batch) {
        $remaining = floatval($batch['remaining_quantity']);
        $price = floatval($batch['purchase_price']);
        $total_remaining += $remaining;
        $total_cost += $remaining * $price;
    }
    
    $weighted_price = $total_remaining > 0 ? $total_cost / $total_remaining : 0;
    
    // جلب سعر الشراء الحالي من جدول المنتجات كاحتياطي
    $product = $pdo->prepare("SELECT purchase_price FROM products WHERE id = ?");
    $product->execute([$product_id]);
    $product_data = $product->fetch();
    $current_price = $product_data ? floatval($product_data['purchase_price']) : 0;
    
    echo json_encode([
        'batches' => $batches,
        'total_remaining' => $total_remaining,
        'weighted_price' => $weighted_price > 0 ? $weighted_price : $current_price,
        'current_purchase_price' => $current_price
    ]);
    
} catch (Exception $e) {
    // في حالة عدم وجود جدول الدفعات، نُرجع السعر الحالي
    try {
        $product = fetchOne("SELECT purchase_price FROM products WHERE id = ?", [$product_id]);
        echo json_encode([
            'batches' => [],
            'total_remaining' => 0,
            'weighted_price' => $product ? floatval($product['purchase_price']) : 0,
            'current_purchase_price' => $product ? floatval($product['purchase_price']) : 0,
            'fallback' => true
        ]);
    } catch (Exception $e2) {
        // لا نكشف تفاصيل الخطأ في الإنتاج
        $isDebug = defined('APP_DEBUG') && APP_DEBUG === true;
        echo json_encode(['error' => $isDebug ? $e2->getMessage() : 'حدث خطأ في جلب البيانات']);
    }
}
?>
