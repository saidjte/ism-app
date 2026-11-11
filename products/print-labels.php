<?php
$page_title = 'طباعة ملصقات الأرفف (Balisage)';
require_once '../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('labels.print');

// Définir base_url
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
if (strpos($script_dir, 'products') !== false) {
    $base_url = rtrim(dirname($script_dir), '/');
} else {
    $base_url = rtrim($script_dir, '/');
}
if (empty($base_url)) $base_url = '';

// جلب العملة
$currency = getCurrencySymbol();

// جلب التصنيفات
$categories = [];
try {
    $categories = ProductService::getActiveCategories();
} catch (Exception $e) { ErrorHandler::logException($e, 'print-labels:fetch_categories'); }

// جلب المنتجات المحددة للطباعة
$print_mode = false;
$labels_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print_labels'])) {
    $print_mode = true;
    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    // دمج المنتجات المختارة فردياً
    $individual_ids = $_POST['individual_ids'] ?? [];
    $individual_qtys = $_POST['individual_qtys'] ?? [];
    if (!empty($individual_ids)) {
        foreach ($individual_ids as $ii => $iid) {
            $product_ids[] = $iid;
            $quantities[] = $individual_qtys[$ii] ?? 1;
        }
    }
    $label_layout = $_POST['label_layout'] ?? 'large';
    $show_purchase = isset($_POST['show_purchase']);
    $show_code = isset($_POST['show_code']);
    $show_barcode = isset($_POST['show_barcode']);
    $show_category = isset($_POST['show_category']);
    $show_date = isset($_POST['show_date']);
    $store_name = trim($_POST['store_name'] ?? '');
    $unit_with_price = isset($_POST['unit_with_price']);
    $price_display = $_POST['price_display'] ?? 'basic';
    $code_type = $_POST['code_type'] ?? 'barcode'; // barcode | qrcode | none
    $barcode_format = $_POST['barcode_format'] ?? 'CODE128'; // CODE128, EAN13, CODE39, UPC
    // دمج المنتجات المكررة (نفس product_id يُجمّع عددُه)
    $merged = [];
    foreach ($product_ids as $key => $pid) {
        $pid = intval($pid);
        $qty = intval($quantities[$key] ?? 0);
        if ($qty < 1) continue;
        if (isset($merged[$pid])) {
            $merged[$pid] += $qty;
        } else {
            $merged[$pid] = $qty;
        }
    }
    // استبدال القوائم المدمجة
    $product_ids = array_keys($merged);
    $quantities = array_values($merged);
    $element_order = json_decode($_POST['element_order'] ?? '["storeName","productName","price","code","category","unitInfo"]', true);
    $element_visibility = json_decode($_POST['element_visibility'] ?? '{"storeName":true,"productName":true,"price":true,"code":true,"category":true,"unitInfo":true}', true);
        // إضافة عناصر جديدة تلقائياً إذا لم تكن في الترتيب
    $all_known_elements = ['storeName','productName','price','code','category','unitInfo','date'];
    foreach ($all_known_elements as $el) {
        if (!in_array($el, $element_order)) $element_order[] = $el;
        if (!isset($element_visibility[$el])) $element_visibility[$el] = true;
    }
    $visible_elements = array_filter($element_order, function($el) use ($element_visibility) {
        return isset($element_visibility[$el]) && $element_visibility[$el];
    });
    // إخفاء التاريخ إذا لم يُحدد
    if (!$show_date) {
        $visible_elements = array_values(array_filter($visible_elements, function($el) {
            return $el !== 'date';
        }));
    }
    // إذا كانت الوحدة بجانب السعر، أخفي عنصر unitInfo تلقائياً
    if ($unit_with_price) {
        $visible_elements = array_values(array_filter($visible_elements, function($el) {
            return $el !== 'unitInfo';
        }));
    }
    // إذا كان نوع الكود "none"، أخفي عنصر code
    if ($code_type === 'none') {
        $visible_elements = array_values(array_filter($visible_elements, function($el) {
            return $el !== 'code';
        }));
    }

    // جمع IDs فريدة (بعد الدمج)
    $unique_pids = array_unique(array_map('intval', $product_ids));

    // جلب الوحدات الإضافية لكل المنتجات المحددة دفعة واحدة
    $all_additional_units = [];
    if (!empty($unique_pids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($unique_pids), '?'));
            $all_au_rows = fetchAll("SELECT * FROM additional_units WHERE product_id IN ($placeholders) ORDER BY product_id, id", array_values($unique_pids));
            foreach ($all_au_rows as $au_row) {
                $all_additional_units[$au_row['product_id']][] = $au_row;
            }
        } catch (Exception $e) {
            // جدول additional_units قد لا يكون موجوداً بعد
            ErrorHandler::logException($e, 'print-labels:fetch_additional_units');
        }
    }

    foreach ($product_ids as $key => $pid) {
        $pid = intval($pid);
        $qty = intval($quantities[$key] ?? 0);
        if ($qty < 1) continue;
        $product = fetchOne("SELECT p.*, c.name as category_name, c.color as category_color 
                            FROM products p 
                            LEFT JOIN categories c ON p.category_id = c.id 
                            WHERE p.id = ?", [$pid]);
        if ($product) {
            // إرفاق الوحدات الإضافية بالمنتج
            $product['additional_units'] = $all_additional_units[$pid] ?? [];
            for ($i = 0; $i < $qty; $i++) {
                $labels_data[] = $product;
            }
        }
    }
}

// جميع المنتجات
$all_products = fetchAll("SELECT p.*, c.name as category_name, c.color as category_color 
                          FROM products p 
                          LEFT JOIN categories c ON p.category_id = c.id 
                          ORDER BY p.name ASC");

// === تنسيقات الملصقات للرفوف - كبيرة ومقروءة من مسافة ===
$layouts = [
    'extra_large' => [
        'name' => 'كبير جداً (2×4)',
        'desc' => '8 ملصقات / صفحة - الأكثر وضوحاً',
        'cols' => 2, 'rows' => 4,
        'label_w' => 99, 'label_h' => 70,
        'name_size' => 16, 'price_size' => 36, 'code_size' => 11,
        'barcode_height' => 50, 'barcode_font' => 13,
    ],
    'wide' => [
        'name' => 'عريض (1×5)',
        'desc' => '5 ملصقات / صفحة - مناسب للأرفف الطويلة',
        'cols' => 1, 'rows' => 5,
        'label_w' => 198, 'label_h' => 55,
        'name_size' => 18, 'price_size' => 42, 'code_size' => 12,
        'barcode_height' => 50, 'barcode_font' => 14,
    ],
    'large' => [
        'name' => 'كبير (2×5)',
        'desc' => '10 ملصقات / صفحة - متوازن',
        'cols' => 2, 'rows' => 5,
        'label_w' => 99, 'label_h' => 55,
        'name_size' => 14, 'price_size' => 30, 'code_size' => 10,
        'barcode_height' => 42, 'barcode_font' => 12,
    ],
    'medium' => [
        'name' => 'متوسط (3×5)',
        'desc' => '15 ملصق / صفحة - اقتصادي',
        'cols' => 3, 'rows' => 5,
        'label_w' => 63, 'label_h' => 55,
        'name_size' => 12, 'price_size' => 24, 'code_size' => 9,
        'barcode_height' => 38, 'barcode_font' => 10,
    ],
    'small' => [
        'name' => t('labels_layout_small_3x6'),
        'desc' => '18 ملصق / صفحة - الأكثر اقتصاداً',
        'cols' => 3, 'rows' => 6,
        'label_w' => 63, 'label_h' => 46,
        'name_size' => 10, 'price_size' => 20, 'code_size' => 8,
        'barcode_height' => 30, 'barcode_font' => 9,
    ],
    'tiny' => [
        'name' => t('labels_layout_tiny_3x7'),
        'desc' => '21 ملصق / صفحة - الأقصى اقتصاداً',
        'cols' => 3, 'rows' => 7,
        'label_w' => 64, 'label_h' => 38,
        'name_size' => 9, 'price_size' => 17, 'code_size' => 8,
        'barcode_height' => 22, 'barcode_font' => 8,
    ],
];

// === وضع الطباعة ===
if ($print_mode) {
    $current_layout = $layouts[$label_layout] ?? $layouts['large'];
    $cols = $current_layout['cols'];
    $rows = $current_layout['rows'];
    $per_page = $cols * $rows;

    // أحجام مُعزَّزة عندما تكون الوحدة بجانب السعر
    $boost_name = $current_layout['name_size'];
    $boost_price = $current_layout['price_size'];
    if ($unit_with_price) {
        $boost_name = (int)ceil($current_layout['name_size'] * 1.25);
        $boost_price = (int)ceil($current_layout['price_size'] * 1.20);
    }

    // تقسيم الملصقات إلى صفحات
    $pages = array_chunk($labels_data, $per_page);
    if (empty($pages)) $pages = [[]];
}

// عداد عالمي للباركود بدلاً من GLOBALS
$bc_counter = 0;

// Helper function to render label elements in order
function renderLabelElement($elementId, $p, $current_layout, $store_name, $show_purchase, $show_barcode, $show_code, $show_category, $price_display, $currency, $has_additional, $unit_with_price = false, $code_type = 'barcode', $barcode_format = 'CODE128', $show_date = false) {
    global $bc_counter;
    ob_start();
    switch ($elementId) {
        case 'storeName':
            if (!empty($store_name)):
            ?>
            <div class="store-name"><?php echo htmlspecialchars($store_name); ?></div>
            <?php
            endif;
            break;

        case 'productName':
            ?>
            <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
            <?php
            break;

        case 'date':
            if ($show_date):
            ?>
            <div class="label-date"><?php echo date('Y/m/d'); ?></div>
            <?php
            endif;
            break;

        case 'price':
            ?>
            <?php if ($price_display === 'basic'): ?>
                <div class="product-price<?php echo $unit_with_price ? ' price-with-unit' : ''; ?>">
                    <?php echo number_format($p['selling_price'], 2); ?>
                    <span class="currency"><?php echo $currency; ?></span>
                    <?php if ($unit_with_price && !empty($p['unit'])): ?>
                    <span class="unit-badge"><?php echo htmlspecialchars($p['unit']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($show_purchase): ?>
                <div class="product-price-purchase">
                    شراء: <?php echo number_format($p['purchase_price'], 2); ?> <?php echo $currency; ?>
                </div>
                <?php endif; ?>
            <?php elseif ($price_display === 'additional'): ?>
                <?php if ($has_additional): ?>
                    <?php foreach ($p['additional_units'] as $au): ?>
                        <?php if ($unit_with_price): ?>
                        <div class="product-price price-with-unit">
                            <?php echo number_format($au['selling_price'], 2); ?>
                            <span class="currency"><?php echo $currency; ?></span>
                            <span class="unit-badge"><?php echo htmlspecialchars($au['unit_name']); ?></span>
                        </div>
                        <?php else: ?>
                        <div class="product-price">
                            <?php echo number_format($au['selling_price'], 2); ?>
                            <span class="currency"><?php echo $currency; ?></span>
                        </div>
                        <div class="product-price-purchase">
                            <?php echo htmlspecialchars($au['unit_name']); ?> (1 = <?php echo floatval($au['conversion_factor']); ?> <?php echo htmlspecialchars($p['unit']); ?>)
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="product-price<?php echo $unit_with_price ? ' price-with-unit' : ''; ?>">
                        <?php echo number_format($p['selling_price'], 2); ?>
                        <span class="currency"><?php echo $currency; ?></span>
                        <?php if ($unit_with_price && !empty($p['unit'])): ?>
                        <span class="unit-badge"><?php echo htmlspecialchars($p['unit']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$unit_with_price): ?>
                    <div class="product-price-purchase">(وحدة أساسية - لا توجد وحدة إضافية)</div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php elseif ($price_display === 'multiple'): ?>
                <?php if ($unit_with_price): ?>
                <div class="product-price price-with-unit">
                    <?php echo number_format($p['selling_price'], 2); ?>
                    <span class="currency"><?php echo $currency; ?></span>
                    <span class="unit-badge"><?php echo htmlspecialchars($p['unit']); ?></span>
                </div>
                <?php else: ?>
                <div class="product-price">
                    <?php echo number_format($p['selling_price'], 2); ?>
                    <span class="currency"><?php echo $currency; ?></span>
                </div>
                <div class="product-price-purchase"><?php echo htmlspecialchars($p['unit']); ?></div>
                <?php endif; ?>
                <?php if ($has_additional): ?>
                    <?php foreach ($p['additional_units'] as $au): ?>
                        <?php if ($unit_with_price): ?>
                        <div class="product-price price-with-unit" style="margin-top:1mm;">
                            <?php echo number_format($au['selling_price'], 2); ?>
                            <span class="currency"><?php echo $currency; ?></span>
                            <span class="unit-badge"><?php echo htmlspecialchars($au['unit_name']); ?></span>
                        </div>
                        <?php else: ?>
                        <div class="product-price" style="margin-top:1mm;">
                            <?php echo number_format($au['selling_price'], 2); ?>
                            <span class="currency"><?php echo $currency; ?></span>
                        </div>
                        <div class="product-price-purchase">
                            <?php echo htmlspecialchars($au['unit_name']); ?> (1 = <?php echo floatval($au['conversion_factor']); ?> <?php echo htmlspecialchars($p['unit']); ?>)
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($show_purchase): ?>
                <div class="product-price-purchase" style="margin-top:1mm; color:#b91c1c;">
                    شراء: <?php echo number_format($p['purchase_price'], 2); ?> <?php echo $currency; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php
            break;

        case 'code':
            if ($code_type === 'qrcode'):
            ?>
            <div class="qrcode-container" style="text-align:center; margin-top:1.5mm;">
                <canvas id="qrcode-<?php echo $bc_counter++; ?>" data-value="<?php echo htmlspecialchars($p['code']); ?>" style="max-width:90%;"></canvas>
            </div>
            <?php elseif ($show_barcode && $code_type === 'barcode'):
            // تحديد تنسيق الباركود المناسب تلقائياً
            $effective_format = $barcode_format;
            $code_val = $p['code'];
            if ($barcode_format === 'EAN13') {
                // EAN-13 يتطلب 13 رقم
                if (!preg_match('/^\\d{13}$/', $code_val)) $effective_format = 'CODE128';
            } elseif ($barcode_format === 'UPC') {
                if (!preg_match('/^\\d{12}$/', $code_val)) $effective_format = 'CODE128';
            } elseif ($barcode_format === 'CODE39') {
                if (!preg_match('/^[A-Z0-9\\s\\-\\.\\$\\/\\+\\%]+$/', strtoupper($code_val))) $effective_format = 'CODE128';
            }
            ?>
            <div class="barcode-container">
                <svg id="barcode-<?php echo $bc_counter++; ?>" data-value="<?php echo htmlspecialchars($code_val); ?>" data-format="<?php echo $effective_format; ?>"></svg>
            </div>
            <?php elseif ($show_code): ?>
            <div class="product-code-text"><?php echo htmlspecialchars($p['code']); ?></div>
            <?php
            endif;
            break;

        case 'category':
            if ($show_category && !empty($p['category_name'])):
            ?>
            <span class="product-category" style="background: <?php echo $p['category_color'] ?? '#475569'; ?>;">
                <?php echo htmlspecialchars($p['category_name']); ?>
            </span>
            <?php
            endif;
            break;

        case 'unitInfo':
            // إذا كانت الوحدة بجانب السعر، لا تعرض هذا العنصر
            if ($unit_with_price):
                break;
            endif;
            ?>
            <div class="label-footer">
                <span class="label-unit"><?php echo htmlspecialchars($p['unit']); ?><?php if ($has_additional): ?> + <?php echo count($p['additional_units']); ?> وحدة إضافية<?php endif; ?></span>
            </div>
            <?php
            break;
    }
    return ob_get_clean();
}


// === MVC: تحميل القالب ===
if ($print_mode) {
    require_once __DIR__ . '/Templates/print-labels-template.php';
    exit;
}

require_once '../includes/header.php';
require_once __DIR__ . '/Templates/print-labels-template.php';
require_once '../includes/footer.php';