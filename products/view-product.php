<?php
require_once '../includes/bootstrap.php';
$page_title = t('products_details');
requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header("Location: list-products.php"); exit; }

// جلب كل بيانات المنتج عبر ProductService
$product = ProductService::getProductViewData($id);
if (!$product) { header("Location: list-products.php"); exit; }

// استخراج المتغيرات للقالب (تتوافق مع تسميات القالب الأصلية)
$productImages = $product['images'] ?? [];
$primaryImage = $product['primary_image'] ?? null;
$additional_units = $product['additional_units'] ?? [];
$purchase_additional_units = $product['purchase_additional_units'] ?? [];
$recent_purchases = $product['recent_purchases'] ?? [];
$recent_sales = $product['recent_sales'] ?? [];
$price_history = $product['price_history'] ?? [];
$available_batches = $product['available_batches'] ?? [];

// إحصائيات المشتريات والمبيعات
$total_purchased = $product['total_purchased'] ?? 0;
$total_sold = $product['total_sold'] ?? 0;

// حساب إجماليات الكميات والأرباح من السجلات
$total_qty_purchased = 0;
$total_qty_sold = 0;
$total_cost = 0;
$total_revenue = 0;

foreach ($recent_purchases as $rp) {
    $total_qty_purchased += floatval($rp['quantity'] ?? 0);
    $total_cost += floatval($rp['quantity'] ?? 0) * floatval($rp['purchase_price'] ?? 0);
}

foreach ($recent_sales as $rs) {
    $total_qty_sold += floatval($rs['quantity'] ?? 0);
    $total_revenue += floatval($rs['quantity'] ?? 0) * floatval($rs['selling_price'] ?? 0);
}

$total_profit = $total_revenue - $total_cost;

// تصنيف الدفعات حسب الصلاحية
$expired_batches = [];
$expiring_soon_batches = [];
$valid_batches_exp = [];
$total_expired_qty = 0;
$total_expiring_soon_qty = 0;

$today = date('Y-m-d');
$expiry_alert_days_view = 30;
try {
    $expiry_alert_days_view = intval(getSetting('expiry_alert_days', '30'));
    if ($expiry_alert_days_view < 1) $expiry_alert_days_view = 30;
} catch (Exception $e) {}
$expiry_limit_view = date('Y-m-d', strtotime('+' . $expiry_alert_days_view . ' days'));

if ($product['has_expiry'] && !empty($available_batches)) {
    foreach ($available_batches as $batch) {
        $exp = $batch['expiry_date'] ?? null;
        if (!$exp) {
            $valid_batches_exp[] = $batch;
            continue;
        }
        if ($exp < $today) {
            $expired_batches[] = $batch;
            $total_expired_qty += floatval($batch['remaining_quantity'] ?? 0);
        } elseif ($exp <= $expiry_limit_view) {
            $expiring_soon_batches[] = $batch;
            $total_expiring_soon_qty += floatval($batch['remaining_quantity'] ?? 0);
        } else {
            $valid_batches_exp[] = $batch;
        }
    }
}

require_once '../includes/header.php';
require_once __DIR__ . '/Templates/view-product-template.php';
require_once '../includes/footer.php';