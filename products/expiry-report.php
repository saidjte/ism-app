<?php
require_once '../includes/bootstrap.php';
$page_title = t('expiry_report_page_title');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('expiry.view');

// جلب إعدادات الصلاحية
$alert_days = 30;
try {
    $alert_days = intval(getSetting('expiry_alert_days', '30'));
    if ($alert_days < 1) $alert_days = 30;
} catch (Exception $e) { ErrorHandler::logException($e, 'expiry-report:fetch_expiry_settings'); }

// فلاتر
$filter_status = $_GET['status'] ?? 'all'; // all, expired, expiring, valid
$filter_days = intval($_GET['days'] ?? $alert_days);
if ($filter_days < 1) $filter_days = 30;
$filter_category = intval($_GET['category'] ?? 0);
$search_query = trim($_GET['q'] ?? '');

$today = date('Y-m-d');
$expiry_limit = date('Y-m-d', strtotime('+' . $filter_days . ' days'));

// === إحصائيات عامة ===
try {
    $stats = ProductService::getExpiryStats($today, $expiry_limit);
    $total_expired_count = $stats['expired']['count'] ?? 0;
    $total_expired_qty = $stats['expired']['qty'] ?? 0;
    $total_expired_value = $stats['expired']['value'] ?? 0;
    $total_expiring_count = $stats['expiring']['count'] ?? 0;
    $total_expiring_qty = $stats['expiring']['qty'] ?? 0;
    $total_expiring_value = $stats['expiring']['value'] ?? 0;
    $total_valid_count = $stats['valid']['count'] ?? 0;
    $total_valid_qty = $stats['valid']['qty'] ?? 0;
    $total_valid_value = $stats['valid']['value'] ?? 0;
} catch (Exception $e) {
    ErrorHandler::logException($e, 'expiry-report:fetch_expiry_stats');
    $total_expired_count = 0; $total_expired_qty = 0; $total_expired_value = 0;
    $total_expiring_count = 0; $total_expiring_qty = 0; $total_expiring_value = 0;
    $total_valid_count = 0; $total_valid_qty = 0; $total_valid_value = 0;
}

// === جلب التصنيفات للفلتر ===
$categories = [];
try {
    $categories = ProductService::getAllCategories();
} catch (Exception $e) { ErrorHandler::logException($e, 'expiry-report:fetch_categories'); }

// === جلب الدفعات ===
$filters = [
    'status' => $filter_status,
    'days' => $filter_days,
    'category' => $filter_category,
    'q' => $search_query,
    'today' => $today,
    'expiry_limit' => $expiry_limit,
];

$batches = [];
try {
    $batches = ProductService::getExpiryBatches($filters);
} catch (Exception $e) { ErrorHandler::logException($e, 'expiry-report:fetch_batches'); }

// حساب الإجماليات للنتائج المعروضة
$display_total_value = array_sum(array_column($batches, 'batch_value'));
$display_total_qty = array_sum(array_column($batches, 'remaining_quantity'));
$display_total_batches = count($batches);
$display_unique_products = count(array_unique(array_column($batches, 'product_id')));

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once '../includes/header.php';
require_once __DIR__ . '/Templates/expiry-report-template.php';
require_once '../includes/footer.php';