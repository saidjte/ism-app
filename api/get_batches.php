<?php
/**
 * API لجلب معلومات دفعات الشراء المتوفرة لمنتج معين (FIFO)
 *
 * v5.13.0: إعادة تفعيل استعلام الدفعات الفعلي
 */

require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

$product_id = intval($_GET['product_id'] ?? 0);

if ($product_id <= 0) {
    ApiResponse::custom(['success' => false, 'batches' => [], 'message' => 'معرف المنتج مطلوب']);
}

try {
    $pdo = getDBConnection();

    $product = $pdo->prepare("SELECT purchase_price, has_expiry FROM products WHERE id = ?");
    $product->execute([$product_id]);
    $product_data = $product->fetch(PDO::FETCH_ASSOC);
    $current_price = $product_data ? floatval($product_data['purchase_price']) : 0;

    // Query actual batches
    $batchStmt = $pdo->prepare(
        "SELECT id, batch_number, remaining_quantity, purchase_price, purchase_date, expiry_date
         FROM purchase_batches
         WHERE product_id = ? AND remaining_quantity > 0
         ORDER BY CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE '9999-12-31' END ASC, purchase_date ASC, id ASC"
    );
    $batchStmt->execute([$product_id]);
    $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

    $total_remaining = 0;
    $weighted_price = $current_price;
    if (!empty($batches)) {
        $total_remaining = array_sum(array_column($batches, 'remaining_quantity'));
        $cost_sum = 0;
        foreach ($batches as $b) {
            $cost_sum += floatval($b['remaining_quantity']) * floatval($b['purchase_price']);
        }
        $weighted_price = $total_remaining > 0 ? $cost_sum / $total_remaining : $current_price;
    }

    ApiResponse::custom([
        'success' => true,
        'batches' => $batches,
        'total_remaining' => $total_remaining,
        'weighted_price' => round($weighted_price, 2),
        'current_purchase_price' => $current_price,
        'has_expiry' => $product_data ? intval($product_data['has_expiry']) : 0,
        'fallback' => empty($batches)
    ]);
} catch (Exception $e) {
    ApiResponse::custom(['success' => false, 'batches' => [], 'message' => 'حدث خطأ أثناء جلب البيانات. يرجى المحاولة لاحقاً.']);
}