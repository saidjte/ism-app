<?php
require_once '../includes/bootstrap.php';
$page_title = t('pricehistory_title');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('prices.view');

// فلاتر البحث والتصفية
$search_product = trim($_GET['search'] ?? '');
$filter_type = $_GET['type'] ?? 'all'; // all, purchase, selling
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;


$filters = [
    'search' => $search_product,
    'type' => $filter_type,
    'date_from' => $filter_date_from,
    'date_to' => $filter_date_to,
];

try {
    $result = ProductService::getPriceHistory($filters, $page, $per_page);
    $price_logs = $result['logs'] ?? [];
    $total_records = $result['total'] ?? 0;
    $total_pages = $result['total_pages'] ?? 0;
    $offset = $result['offset'] ?? 0;
    $total_changes = $result['total_changes'] ?? 0;
    $purchase_price_changes = $result['purchase_changes'] ?? 0;
    $selling_price_changes = $result['selling_changes'] ?? 0;
    $last_change = $result['last_change'] ?? null;
} catch (Exception $e) {
    // جدول price_history غير موجود
    $price_logs = [];
    $total_records = 0;
    $total_pages = 0;
    $offset = ($page - 1) * $per_page;
    $total_changes = 0;
    $purchase_price_changes = 0;
    $selling_price_changes = 0;
    $last_change = null;
}

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once '../includes/header.php';
require_once __DIR__ . '/Templates/price-history-template.php';
require_once '../includes/barcode-scanner.php';
require_once '../includes/footer.php';