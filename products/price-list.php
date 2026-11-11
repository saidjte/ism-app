<?php
require_once '../includes/bootstrap.php';
$page_title = t('products_price_list');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('prices.view');

// جلب التصنيفات
$categories = [];
try {
    $categories = ProductService::getActiveCategories();
} catch (Exception $e) {
    try {
        $categories = ProductService::getActiveCategories();
    } catch (Exception $e2) { ErrorHandler::logException($e2, 'price-list:fetch_categories_fallback'); }
}

// إعدادات الطباعة
$show_code = isset($_POST['show_code']);
$selected_categories = $_POST['categories'] ?? [];
$store_name = trim($_POST['store_name'] ?? '');
$store_phone = trim($_POST['store_phone'] ?? '');
$store_address = trim($_POST['store_address'] ?? '');
$layout = $_POST['layout'] ?? 'grid'; // grid أو list
$currency_position = $_POST['currency_position'] ?? 'right'; // right أو left
$categories_mode = $_POST['categories_mode'] ?? 'one'; // one أو separate
$print_mode = isset($_POST['print_price_list']);
$show_unit = isset($_POST['show_unit']);
$unit_position = $_POST['unit_position'] ?? 'after'; // after, before, below, above
$show_additional_units = isset($_POST['show_additional_units']);
$additional_units_count = max(1, min(10, intval($_POST['additional_units_count'] ?? 3)));
$au_position = $_POST['au_position'] ?? 'after'; // after, before, above, below

// جلب العملة
$currency = getCurrencySymbol();

// جلب اسم المتجر من الإعدادات
if (empty($store_name)) {
    try {
        $row = fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'store_name'");
        $store_name = $row ? $row['setting_value'] : '';
    } catch (Exception $e) { ErrorHandler::logException($e, 'price-list:fetch_store_name'); }
}

// بناء استعلام المنتجات
$where = "";
$params = [];

if (!empty($selected_categories)) {
    $placeholders = implode(',', array_fill(0, count($selected_categories), '?'));
    $where = " WHERE p.category_id IN ($placeholders)";
    $params = array_map('intval', $selected_categories);
}

// جلب المنتجات مع التصنيف
$products = [];
try {
    $products = fetchAll(
        "SELECT p.*, c.name as category_name, c.color as category_color
         FROM products p 
         LEFT JOIN categories c ON p.category_id = c.id 
         $where 
         ORDER BY c.sort_order ASC, c.name ASC, p.name ASC",
        $params
    );
} catch (Exception $e) {
    // جدول categories غير موجود - استعلام بدون التصنيفات
    try {
        $products = fetchAll(
            "SELECT p.*, NULL as category_name, NULL as category_color
             FROM products p 
             $where 
             ORDER BY p.name ASC",
            $params
        );
    } catch (Exception $e2) {
        $products = [];
    }
}

// جلب الوحدات الإضافية للمنتجات المختارة
$additional_units_data = [];
if ($show_additional_units && !empty($products)) {
    $product_ids = array_column($products, 'id');
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    try {
        $au_rows = fetchAll("SELECT * FROM additional_units WHERE product_id IN ($placeholders) ORDER BY product_id ASC, id ASC", $product_ids);
        foreach ($au_rows as $au) {
            $pid = $au['product_id'];
            if (!isset($additional_units_data[$pid])) {
                $additional_units_data[$pid] = [];
            }
            $additional_units_data[$pid][] = $au;
        }
    } catch (Exception $e) { ErrorHandler::logException($e, 'price-list:fetch_additional_units'); }
}

// تجميع المنتجات حسب التصنيف
$grouped = [];
foreach ($products as $p) {
    $catName = $p['category_name'] ?: 'بدون تصنيف';
    $catColor = $p['category_color'] ?: '#64748b';
    if (!isset($grouped[$catName])) {
        $grouped[$catName] = ['color' => $catColor, 'products' => []];
    }
    $p['_additional_units'] = $additional_units_data[$p['id']] ?? [];
    $grouped[$catName]['products'][] = $p;
}

$total_products = count($products);
$total_categories = count($grouped);

// بيانات التصنيفات لاستخدامها في حساب عدد الصفحات ديناميكياً
$categoriesJson = [];
foreach ($categories as $cat) {
    $catProducts = 0;
    foreach ($grouped as $gName => $gData) {
        if ($gName === $cat['name']) {
            $catProducts = count($gData['products']);
            break;
        }
    }
    $categoriesJson[] = ['id' => (int)$cat['id'], 'products' => $catProducts];
}
$use_page_break = ($categories_mode === 'separate' && $total_categories > 1);

// === MVC: تحميل القالب ===
if ($print_mode) {
    require_once __DIR__ . '/Templates/price-list-template.php';
    exit;
}

require_once '../includes/header.php';
require_once __DIR__ . '/Templates/price-list-template.php';
require_once '../includes/footer.php';
