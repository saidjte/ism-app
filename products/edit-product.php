<?php
require_once '../includes/bootstrap.php';
$page_title = t('products_edit');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('products.edit');

// التحقق من المعرف قبل إرسال أي محتوى HTML
$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header("Location: list-products.php");
    exit;
}

$product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
if (!$product) {
    header("Location: list-products.php");
    exit;
}

// تهيئة متغيرات الوحدة المخصصة
$custom_unit_name = '';
$custom_unit_type = 'other';
$custom_unit_conversion = 1;
$custom_unit_selling_price = 0;
$has_expiry = 0;

// أنواع الوحدات
$unit_types_for_custom = [
    'quantity' => 'كمية (عدد)',
    'weight'   => 'وزن',
    'volume'   => 'حجم / سائل',
    'length'   => 'طول',
    'area'     => 'مساحة',
    'other'    => 'أخرى',
];

// الوحدات الأساسية حسب النوع
$base_units_by_type = [
    'quantity' => ['name' => 'قطعة', 'abbreviation' => 'قط'],
    'weight'   => ['name' => 'كيلوغرام', 'abbreviation' => 'كلغ'],
    'volume'   => ['name' => 'لتر', 'abbreviation' => 'لتر'],
    'length'   => ['name' => 'متر', 'abbreviation' => 'م'],
    'area'     => ['name' => 'متر مربع', 'abbreviation' => 'م²'],
];

require_once '../includes/header.php';

$additional_units = [];
try {
    $additional_units = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$id]);
} catch (Exception $e) {
    // جدول additional_units قد لا يكون موجوداً بعد
    ErrorHandler::logException($e, 'edit-product:fetch_additional_units');
}
$purchase_additional_units = [];
try {
    $purchase_additional_units = fetchAll("SELECT * FROM purchase_additional_units WHERE product_id = ?", [$id]);
} catch (Exception $e) {
    // جدول purchase_additional_units قد لا يكون موجوداً بعد
    ErrorHandler::logException($e, 'edit-product:fetch_purchase_additional_units');
}

// جلب التصنيفات النشطة
$categories = [];
try {
    $categories = fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
} catch (Exception $e) {
    // جدول التصنيفات قد لا يكون موجوداً بعد
    ErrorHandler::logException($e, 'edit-product:fetch_categories');
}

// جلب وحدات القياس النشطة
$units = [];
try {
    $units = fetchAll("SELECT * FROM measurement_units WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
} catch (Exception $e) {
    // جدول الوحدات قد لا يكون موجوداً بعد
    ErrorHandler::logException($e, 'edit-product:fetch_measurement_units');
}

// جلب دفعات الشراء
$product_batches = [];
try {
    $product_batches = fetchAll("SELECT pb.* FROM purchase_batches pb WHERE pb.product_id = ? ORDER BY pb.created_at DESC, pb.id DESC", [$id]);
} catch (Exception $e) {
    ErrorHandler::logException($e, 'edit-product:fetch_purchase_batches');
}

// جلب سجل تغيير الأسعار
$price_history = [];
try {
    $price_history = fetchAll("SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT 20", [$id]);
} catch (Exception $e) {
    // جدول price_history قد لا يكون موجوداً بعد
    ErrorHandler::logException($e, 'edit-product:fetch_price_history');
}

$errors = [];
$success = false;
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $purchase_unit = trim($_POST['purchase_unit'] ?? '');
    $purchase_unit_factor = floatval($_POST['purchase_unit_factor'] ?? 1);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $min_quantity = floatval($_POST['min_quantity'] ?? 0);
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $change_reason = trim($_POST['change_reason'] ?? '');
    $has_expiry = isset($_POST['has_expiry']) ? 1 : 0;

    // التحقق من صلاحية كمية المنتج و معالجة التواريخ الجديدة
    $new_expiry_dates = [];
    if ($has_expiry) {
        $old_quantity = floatval($product['quantity']);
        $quantity_diff = $quantity - $old_quantity;
        if ($quantity_diff > 0.001) {
            $new_expiry_dates_data = $_POST['new_expiry_date'] ?? [];
            $new_expiry_quantities_data = $_POST['new_expiry_quantity'] ?? [];
            for ($i = 0; $i < count($new_expiry_dates_data); $i++) {
                $ed = trim($new_expiry_dates_data[$i] ?? '');
                $eq = floatval($new_expiry_quantities_data[$i] ?? 0);
                if (!empty($ed) && $eq > 0) {
                    $new_expiry_dates[] = ['date' => $ed, 'quantity' => $eq];
                }
            }
            $total_new_expiry = array_sum(array_column($new_expiry_dates, 'quantity'));
            if (!empty($new_expiry_dates) && abs($total_new_expiry - $quantity_diff) > 0.001) {
                $errors[] = 'مجموع كميات التواريخ الجديدة (' . number_format($total_new_expiry, 2) . ') يجب أن يساوي الزيادة في الكمية (' . number_format($quantity_diff, 2) . ')';
            }
        }
    }

    // وحدة مخصصة (للوحدات الإضافية)
    $custom_unit_name = trim($_POST['custom_unit_name'] ?? '');
    $custom_unit_type = $_POST['custom_unit_type'] ?? 'other';
    $custom_unit_conversion = floatval($_POST['custom_unit_conversion'] ?? 1);
    $custom_unit_selling_price = floatval($_POST['custom_unit_selling_price'] ?? 0);

    // وحدات القياس الإضافية
    $new_units = [];
    if (isset($_POST['au_name']) && is_array($_POST['au_name'])) {
        for ($i = 0; $i < count($_POST['au_name']); $i++) {
            $au_name = trim($_POST['au_name'][$i] ?? '');
            $au_factor = floatval($_POST['au_factor'][$i] ?? 1);
            $au_price = floatval($_POST['au_price'][$i] ?? 0);
            // معالجة اختيار "أخرى" في القائمة
            $au_custom_name = trim($_POST['au_custom_name'][$i] ?? '');
            if ($au_name === '__custom__') {
                if (!empty($au_custom_name)) {
                    $au_name = $au_custom_name;
                } else {
                    continue;
                }
            }
            if (!empty($au_name) && $au_factor > 0) {
                $new_units[] = ['name' => $au_name, 'factor' => $au_factor, 'price' => $au_price];
            }
        }
    }

    // وحدات الشراء الإضافية
    $new_purchase_units = [];
    if (isset($_POST['pau_name']) && is_array($_POST['pau_name'])) {
        for ($i = 0; $i < count($_POST['pau_name']); $i++) {
            $pau_name = trim($_POST['pau_name'][$i] ?? '');
            $pau_factor = floatval($_POST['pau_factor'][$i] ?? 1);
            $pau_custom_name = trim($_POST['pau_custom_name'][$i] ?? '');
            if ($pau_name === '__custom__') {
                if (!empty($pau_custom_name)) {
                    $pau_name = $pau_custom_name;
                } else {
                    continue;
                }
            }
            if (!empty($pau_name) && $pau_factor > 0) {
                $new_purchase_units[] = ['name' => $pau_name, 'factor' => $pau_factor];
            }
        }
    }

    // إضافة الوحدة المخصصة كوحدة إضافية
    if (!empty($custom_unit_name) && $custom_unit_conversion > 0) {
        $new_units[] = [
            'name' => $custom_unit_name,
            'factor' => $custom_unit_conversion,
            'price' => $custom_unit_selling_price
        ];
    }

    if (empty($code)) $errors[] = t('products_code');
    if (empty($name)) $errors[] = t('products_name_required');
    if ($purchase_price <= 0) $errors[] = t('common_purchase_price_must_be_positive');
    if ($selling_price <= 0) $errors[] = t('common_selling_price_must_be_positive');
    if ($purchase_price > 0 && $selling_price > 0 && $selling_price < $purchase_price) $errors[] = 'سعر البيع (' . number_format($selling_price, 2) . ') لا يمكن أن يكون أقل من سعر الشراء (' . number_format($purchase_price, 2) . ')';
    if (empty($unit)) $errors[] = 'وحدة القياس مطلوبة';
    if (!empty($custom_unit_name) && $custom_unit_conversion <= 0) $errors[] = 'معامل التحويل يجب أن يكون أكبر من صفر';

    $existing = fetchOne("SELECT id FROM products WHERE code = ? AND id != ?", [$code, $id]);
    if ($existing) $errors[] = 'كود المنتج موجود مسبقاً';

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // التحقق من تغيير الأسعار وتسجيلها
            $price_changed = false;
            $old_purchase = $product['purchase_price'];
            $old_selling = $product['selling_price'];

            if ($purchase_price != $old_purchase || $selling_price != $old_selling) {
                $price_changed = true;
                try {
                    $historyStmt = $pdo->prepare("INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason) VALUES (?, ?, ?, ?, ?, ?)");
                    $historyStmt->execute([$id, $old_purchase, $purchase_price, $old_selling, $selling_price, $change_reason ?: null]);
                } catch (Exception $e) {
                    // جدول price_history قد لا يكون موجوداً بعد
                    ErrorHandler::logException($e, 'edit-product:insert_price_history');
                }
            }

            $updateStmt = $pdo->prepare("UPDATE products SET code=?, name=?, purchase_price=?, selling_price=?, unit=?, purchase_unit=?, purchase_unit_factor=?, category_id=?, quantity=?, min_quantity=?, has_expiry=? WHERE id=?");
            $updateStmt->execute([$code, $name, $purchase_price, $selling_price, $unit, $purchase_unit ?: null, $purchase_unit_factor, $category_id, $quantity, $min_quantity, $has_expiry, $id]);

            // حذف الوحدات القديمة وإضافة الجديدة
            try {
                $pdo->prepare("DELETE FROM additional_units WHERE product_id = ?")->execute([$id]);
                $auStmt = $pdo->prepare("INSERT INTO additional_units (product_id, unit_name, conversion_factor, selling_price) VALUES (?, ?, ?, ?)");
                foreach ($new_units as $au) {
                    $auStmt->execute([$id, $au['name'], $au['factor'], $au['price']]);
                }
            } catch (Exception $e) {
                // جدول additional_units قد لا يكون موجوداً بعد
                ErrorHandler::logException($e, 'edit-product:save_additional_units');
            }

            // حذف وحدات الشراء الإضافية القديمة وإضافة الجديدة
            try {
                $pdo->prepare("DELETE FROM purchase_additional_units WHERE product_id = ?")->execute([$id]);
                $pauStmt = $pdo->prepare("INSERT INTO purchase_additional_units (product_id, unit_name, conversion_factor) VALUES (?, ?, ?)");
                foreach ($new_purchase_units as $pau) {
                    $pauStmt->execute([$id, $pau['name'], $pau['factor']]);
                }
            } catch (Exception $e) {
                // جدول purchase_additional_units قد لا يكون موجوداً بعد
                ErrorHandler::logException($e, 'edit-product:save_purchase_additional_units');
            }

            // حساب فرق الكمية
            $old_quantity = floatval($product['quantity']);
            $quantity_diff = $quantity - $old_quantity;

            // إنشاء دفعات جديدة مع تواريخ الانتهاء (إذا زادت الكمية)
            if (!empty($new_expiry_dates)) {
                try {
                    $batchStmt = $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES (?, NULL, ?, ?, ?, CURDATE(), ?)");
                    foreach ($new_expiry_dates as $ed) {
                        $batchStmt->execute([$id, $ed['quantity'], $ed['quantity'], $purchase_price, $ed['date']]);
                    }
                } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:insert_batches_with_expiry'); }
            }
            // إذا زادت الكمية بدون تواريخ محددة، أنشئ دفعة واحدة بدون تاريخ
            elseif ($quantity_diff > 0.001 && empty($new_expiry_dates)) {
                try {
                    $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, NULL, ?, ?, ?, CURDATE())")
                        ->execute([$id, $quantity_diff, $quantity_diff, $purchase_price]);
                } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:insert_batch_no_expiry'); }
            }
            // إذا نقصت الكمية، قلّل من الدفعات المتبقية (FEFO: الأقرب انتهاءً أولاً)
            elseif ($quantity_diff < -0.001) {
                try {
                    $reduce_amount = abs($quantity_diff);
                    // جلب الدفعات المتبقية مرتبة حسب تاريخ الانتهاء (الأقرب أولاً، ثم بدون تاريخ)
                    $batches = $pdo->prepare("SELECT id, remaining_quantity FROM purchase_batches WHERE product_id = ? AND remaining_quantity > 0 ORDER BY expiry_date IS NULL ASC, expiry_date ASC, id ASC");
                    $batches->execute([$id]);
                    $batchesList = $batches->fetchAll();

                    foreach ($batchesList as $batch) {
                        if ($reduce_amount <= 0.001) break;
                        $batch_remaining = floatval($batch['remaining_quantity']);
                        if ($batch_remaining <= 0) continue;

                        $deduct = min($batch_remaining, $reduce_amount);
                        $new_remaining = $batch_remaining - $deduct;
                        $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = ? WHERE id = ?")
                            ->execute([$new_remaining, $batch['id']]);
                        $reduce_amount -= $deduct;
                    }
                } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:reduce_batches_on_quantity_decrease'); }
            }

            $pdo->commit();
            $success = true;

            if ($price_changed) {
                $success_msg = t('products_product_updated_price_logged');
            } else {
                $success_msg = t('products_product_updated_success');
            }

            // إعادة جلب البيانات (استخدام نفس الاتصال)
            $product = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $product->execute([$id]);
            $product = $product->fetch();

            $additional_units = [];
            try {
                $auRes = $pdo->prepare("SELECT * FROM additional_units WHERE product_id = ?");
                $auRes->execute([$id]);
                $additional_units = $auRes->fetchAll();
            } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:refetch_additional_units'); }

            $purchase_additional_units = [];
            try {
                $pauRes = $pdo->prepare("SELECT * FROM purchase_additional_units WHERE product_id = ?");
                $pauRes->execute([$id]);
                $purchase_additional_units = $pauRes->fetchAll();
            } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:refetch_purchase_additional_units'); }

            $price_history = [];
            try {
                $phRes = $pdo->prepare("SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT 20");
                $phRes->execute([$id]);
                $price_history = $phRes->fetchAll();
            } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:refetch_price_history'); }

            $product_batches = [];
            try {
                $pbRes = $pdo->prepare("SELECT pb.* FROM purchase_batches pb WHERE pb.product_id = ? ORDER BY pb.created_at DESC, pb.id DESC");
                $pbRes->execute([$id]);
                $product_batches = $pbRes->fetchAll();
            } catch (Exception $e) { ErrorHandler::logException($e, 'edit-product:refetch_purchase_batches'); }

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Product Edit Error: ' . $e->getMessage());
            $errors[] = t('products_error_updating');
        }
    }
}

// === استجابة AJAX لحفظ المنتج ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    if ($success) {
        echo json_encode(['success' => true, 'product_id' => intval($id), 'message' => $success_msg], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// تحضير بيانات الوحدات الإضافية للعرض (تحديد ما إذا كانت مخصصة أو من القائمة)
$additional_units_prepared = [];
foreach ($additional_units as $au) {
    $is_custom = true;
    foreach ($units as $u) {
        if ($u['abbreviation'] === $au['unit_name']) {
            $is_custom = false;
            break;
        }
    }
    $additional_units_prepared[] = [
        'unit_name' => $au['unit_name'],
        'conversion_factor' => $au['conversion_factor'],
        'selling_price' => $au['selling_price'],
        'is_custom' => $is_custom,
    ];
}

// تحضير بيانات وحدات الشراء الإضافية للعرض
$purchase_additional_units_prepared = [];
foreach ($purchase_additional_units as $pau) {
    $is_custom = true;
    foreach ($units as $u) {
        if ($u['abbreviation'] === $pau['unit_name']) {
            $is_custom = false;
            break;
        }
    }
    $purchase_additional_units_prepared[] = [
        'unit_name' => $pau['unit_name'],
        'conversion_factor' => $pau['conversion_factor'],
        'is_custom' => $is_custom,
    ];
}

// === جلب صور المنتج ===
$productImages = [];
try {
    require_once __DIR__ . '/../includes/ProductImageService.php';
    $productImages = ProductImageService::getActiveImages($id);
} catch (Exception $e) {
    ErrorHandler::logException($e, 'edit-product:fetch_images');
}

?>

<style>
/* === حقل الوحدة المخصصة === */
.custom-unit-section {
    display: none;
    animation: fadeSlideDown 0.3s ease;
}
.custom-unit-section.active {
    display: block;
}
@keyframes fadeSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.custom-unit-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
    padding: 14px 16px;
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fde68a;
    border-radius: 10px;
    margin-top: 8px;
}
.custom-unit-row .form-group {
    margin-bottom: 0;
}
.custom-unit-row .form-group.cu-name {
    flex: 1;
    min-width: 150px;
}
.custom-unit-row .form-group.cu-type {
    min-width: 150px;
}
.custom-unit-row .form-group.cu-conversion {
    min-width: 180px;
}
.custom-unit-row .form-group.cu-price {
    min-width: 140px;
}
.custom-unit-row label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #92400e;
    margin-bottom: 4px;
    display: block;
}
.custom-unit-row .form-control {
    border-color: #fbbf24;
    background: white;
}
.custom-unit-row .form-control:focus {
    border-color: #d97706;
    box-shadow: 0 0 0 3px rgba(217,119,6,0.1);
}
.conversion-hint-custom {
    font-size: 0.75rem;
    color: #92400e;
    margin-top: 3px;
}
.conversion-hint-custom strong {
    color: #78350f;
}
.custom-unit-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fef3c7;
    color: #92400e;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1px solid #fde68a;
    margin-bottom: 4px;
}
/* === حقل اسم مخصص داخل صف الوحدة الإضافية === */
.au-custom-name-wrap {
    display: none;
    margin-top: 4px;
    animation: fadeSlideDown 0.3s ease;
}
.au-custom-name-wrap.active {
    display: block;
}
.au-custom-name-wrap input {
    border-color: #fbbf24;
    background: white;
}
/* === منطقة رفع الصور === */
.image-drop-zone {
    border: 2px dashed var(--gray-200);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.25s;
    background: var(--gray-50);
}
.image-drop-zone:hover, .image-drop-zone.drag-over {
    border-color: var(--primary);
    background: rgba(37, 99, 235, 0.04);
}
.image-drop-zone.drag-over {
    transform: scale(1.01);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}
.product-image-item:hover {
    border-color: var(--primary) !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
    transform: translateY(-2px);
}
.product-image-item {
    transition: all 0.2s;
}

</style>

<div class="container">
    <div class="page-header">
        <h1><?php echo t('products_edit'); ?>: <?php echo htmlspecialchars($product['name']); ?></h1>
        <a href="list-products.php" class="btn btn-outline"><?php echo t('back'); ?></a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
        <div class="alert alert-success">
            تم إنشاء المنتج بنجاح!
            <?php $imgCount = intval($_GET['img_count'] ?? 0); ?>
            <?php if ($imgCount > 0): ?>
                — تم رفع <?php echo $imgCount; ?> صورة تلقائياً
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <form method="POST">
        <div class="card">
            <div class="card-header"><h2>معلومات المنتج الأساسية</h2></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>كود المنتج <span class="required">*</span></label>
                        <input type="text" name="code" class="form-control" value="<?php echo htmlspecialchars($product['code']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>اسم المنتج <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>سعر الشراء <span class="required">*</span></label>
                        <input type="number" name="purchase_price" id="purchase_price_input" class="form-control" step="0.01" value="<?php echo $product['purchase_price']; ?>" required oninput="checkPriceChange()">
                        <div class="form-text" id="purchase_price_hint" style="display:none; color:#d97706;">تم تغيير سعر الشراء! سيتم تسجيل التغيير في السجل.</div>
                    </div>
                    <div class="form-group">
                        <label>سعر البيع <span class="required">*</span></label>
                        <input type="number" name="selling_price" id="selling_price_input" class="form-control" step="0.01" value="<?php echo $product['selling_price']; ?>" required oninput="checkPriceChange()">
                        <div class="form-text" id="selling_price_hint" style="display:none; color:#d97706;">تم تغيير سعر البيع! سيتم تسجيل التغيير في السجل.</div>
                    </div>
                    <div class="form-group">
                        <label>وحدة القياس الأساسية <span class="required">*</span></label>
                        <?php if (!empty($units)): ?>
                        <select name="unit" id="unitSelect" class="form-control" required>
                            <option value="">-- اختر وحدة القياس --</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['abbreviation']); ?>" <?php echo ($product['unit'] == $u['abbreviation']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="unit" id="unitSelect" class="form-control" value="<?php echo htmlspecialchars($product['unit']); ?>" required>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>وحدة القياس الأساسية للشراء</label>
                        <div class="form-text" style="margin-bottom:4px;">اتركه فارغاً لاستخدام وحدة البيع الأساسية</div>
                        <?php if (!empty($units)): ?>
                        <select name="purchase_unit" id="purchaseUnitSelect" class="form-control" onchange="onPurchaseUnitChange()">
                            <option value="">-- وحدة البيع (<?php echo htmlspecialchars($product['unit']); ?>) --</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['abbreviation']); ?>" <?php echo (isset($product['purchase_unit']) && $product['purchase_unit'] == $u['abbreviation']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="purchase_unit" id="purchaseUnitSelect" class="form-control" placeholder="اتركه فارغاً لاستخدام وحدة البيع" value="<?php echo htmlspecialchars($product['purchase_unit'] ?? ''); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="form-group" id="purchaseUnitFactorGroup" style="<?php echo empty($product['purchase_unit']) ? 'display:none;' : ''; ?>">
                        <label>معامل التحويل <span class="form-text" id="purchaseUnitFactorHint" style="font-weight:400; font-size:0.78rem;"></span></label>
                        <input type="number" name="purchase_unit_factor" id="purchaseUnitFactor" class="form-control" step="0.0001" min="0.0001" value="<?php echo floatval($product['purchase_unit_factor'] ?? 1); ?>">
                        <div class="form-text">1 وحدة شراء = X وحدة بيع أساسية</div>
                    </div>

                    <?php if (!empty($categories)): ?>
                    <div class="form-group">
                        <label>التصنيف</label>
                        <select name="category_id" class="form-control">
                            <option value="">-- بدون تصنيف --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>الكمية الحالية</label>
                        <input type="number" name="quantity" class="form-control" step="0.01" value="<?php echo $product['quantity']; ?>">
                    </div>
                    <div class="form-group">
                        <label>الحد الأدنى للمخزون</label>
                        <input type="number" name="min_quantity" class="form-control" step="0.01" value="<?php echo $product['min_quantity']; ?>">
                    </div>

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="has_expiry" id="has_expiry" value="1" onchange="toggleExpirySectionEdit()" style="width:18px; height:18px; cursor:pointer;" <?php echo !empty($product['has_expiry']) ? 'checked' : ''; ?>>
                            <span style="font-weight:600;">يتطلب تاريخ انتهاء الصلاحية</span>
                        </label>
                        <div class="form-text">فعّل لتتبع تواريخ انتهاء الصلاحية عند الشراء والبيع (FEFO)</div>
                    </div>
                </div>

                <!-- سبب تغيير السعر -->
                <div id="price_change_section" style="display:none; margin-top:1.25rem;">
                    <div class="form-group">
                        <label>سبب تغيير السعر (اختياري)</label>
                        <input type="text" name="change_reason" class="form-control" placeholder="مثال: ارتفاع سعر السوق، خصم من المورد...">
                    </div>
                </div>
            </div>
        </div>

        <!-- وحدات القياس الإضافية للشراء -->
        <div class="card">
            <div class="card-header">
                <h2>وحدات قياس إضافية للشراء</h2>
                <button type="button" class="btn btn-sm btn-outline" onclick="addPuUnitRow()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                    إضافة وحدة شراء
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 1rem;">وحدات شراء إضافية مرتبطة بوحدة الشراء الأساسية (مثال: 1 صندوق = 10 كراتين)</p>

                <div id="purchase-additional-units-container">
                    <?php if (!empty($purchase_additional_units_prepared)):
                        $pu_counter = 0;
                        foreach ($purchase_additional_units_prepared as $pau):
                            $pu_counter++;
                            $pau_sel_val = $pau['is_custom'] ? '__custom__' : $pau['unit_name'];
                    ?>
                    <div class="unit-row" id="pu-row-<?php echo $pu_counter; ?>">
                        <div class="form-group" style="min-width:160px;">
                            <label>اسم الوحدة</label>
                            <select name="pau_name[]" class="form-control" onchange="handlePuUnitChange('pu-row-<?php echo $pu_counter; ?>')">
                                <?php echo buildEditPuUnitOptions($units, $product['purchase_unit'] ?? $product['unit'], $pau_sel_val); ?>
                            </select>
                            <?php if ($pau['is_custom']): ?>
                            <div class="au-custom-name-wrap active" id="pu-custom-<?php echo $pu_counter; ?>">
                                <input type="text" name="pau_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;" value="<?php echo htmlspecialchars($pau['unit_name']); ?>">
                            </div>
                            <?php else: ?>
                            <div class="au-custom-name-wrap" id="pu-custom-<?php echo $pu_counter; ?>">
                                <input type="text" name="pau_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>معامل التحويل <span class="form-text pu-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
                            <input type="number" name="pau_factor[]" class="form-control" step="0.0001" min="0.0001" value="<?php echo $pau['conversion_factor']; ?>">
                        </div>
                        <button type="button" class="btn-remove-unit" onclick="removePuUnitRow('pu-row-<?php echo $pu_counter; ?>')">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div id="no-pu-units-msg" class="text-muted" style="text-align:center; padding:1rem; <?php echo !empty($purchase_additional_units) ? 'display:none;' : ''; ?>">لم تتم إضافة وحدات شراء إضافية بعد</div>
            </div>
        </div>

        <!-- وحدات القياس الإضافية للبيع -->
        <div class="card">
            <div class="card-header">
                <h2>وحدات قياس إضافية</h2>
                <button type="button" class="btn btn-sm btn-outline" onclick="addUnitRow()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                    إضافة وحدة
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 1rem;">أضف وحدات قياس بديلة للمنتج (مثال: كيس يحتوي على 5 كيلوغرامات)</p>

                <!-- وحدة مخصصة (معامل تحويل) -->
                <div class="custom-unit-section" id="customUnitSection">
                    <div class="custom-unit-badge" style="cursor:pointer;" onclick="toggleCustomUnitSection()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        وحدة مخصصة (معامل تحويل)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" id="customUnitArrow" style="transition:transform 0.2s;"><path d="M6 9l6 6 6-6"/></svg>
                    </div>
                    <div id="customUnitBody" style="display:none;">
                        <div class="custom-unit-row">
                            <div class="form-group cu-name">
                                <label>اسم الوحدة <span class="required">*</span></label>
                                <input type="text" name="custom_unit_name" id="customUnitName" class="form-control" placeholder="مثال: كيس، صندوق، طرد" value="<?php echo htmlspecialchars($custom_unit_name); ?>">
                            </div>
                            <div class="form-group cu-type">
                                <label>نوع الوحدة</label>
                                <select name="custom_unit_type" id="customUnitType" class="form-control">
                                    <?php foreach ($unit_types_for_custom as $ck => $cl): ?>
                                    <option value="<?php echo $ck; ?>" <?php echo ($custom_unit_type == $ck) ? 'selected' : ''; ?>>
                                        <?php echo $cl; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group cu-conversion">
                                <label>معامل التحويل</label>
                                <input type="number" name="custom_unit_conversion" id="customUnitConversion" class="form-control" step="0.0001" min="0.0001" value="<?php echo $custom_unit_conversion; ?>" placeholder="1">
                                <div class="conversion-hint-custom" id="customConversionHint">
                                    1 وحدة مخصصة = <strong><?php echo htmlspecialchars($custom_unit_conversion); ?></strong> كيلوغرام (كلغ)
                                </div>
                            </div>
                            <div class="form-group cu-price">
                                <label>سعر البيع</label>
                                <input type="number" name="custom_unit_selling_price" id="customUnitSellingPrice" class="form-control" step="0.01" min="0" value="<?php echo $custom_unit_selling_price; ?>" placeholder="0.00">
                                <div class="conversion-hint-custom">سعر البيع لـ 1 وحدة مخصصة</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="additional-units-container">
                    <?php if (!empty($additional_units_prepared)):
                        $edit_counter = 0;
                        foreach ($additional_units_prepared as $au):
                            $edit_counter++;
                            $au_sel_val = $au['is_custom'] ? '__custom__' : $au['unit_name'];
                    ?>
                    <div class="unit-row" id="unit-row-<?php echo $edit_counter; ?>">
                        <div class="form-group" style="min-width:160px;">
                            <label>اسم الوحدة</label>
                            <select name="au_name[]" class="form-control" onchange="handleAuUnitChange('unit-row-<?php echo $edit_counter; ?>')">
                                <?php echo buildEditUnitOptions($units, $product['unit'], $au_sel_val); ?>
                            </select>
                            <?php if ($au['is_custom']): ?>
                            <div class="au-custom-name-wrap active" id="au-custom-<?php echo $edit_counter; ?>">
                                <input type="text" name="au_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;" value="<?php echo htmlspecialchars($au['unit_name']); ?>">
                            </div>
                            <?php else: ?>
                            <div class="au-custom-name-wrap" id="au-custom-<?php echo $edit_counter; ?>">
                                <input type="text" name="au_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>معامل التحويل <span class="form-text au-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
                            <input type="number" name="au_factor[]" class="form-control" step="0.0001" min="0.0001" value="<?php echo $au['conversion_factor']; ?>">
                        </div>
                        <div class="form-group">
                            <label>سعر البيع</label>
                            <input type="number" name="au_price[]" class="form-control" step="0.01" value="<?php echo $au['selling_price']; ?>">
                        </div>
                        <button type="button" class="btn-remove-unit" onclick="removeUnitRow('unit-row-<?php echo $edit_counter; ?>')">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div id="no-units-msg" class="text-muted" style="text-align:center; padding:1rem; <?php echo !empty($additional_units) ? 'display:none;' : ''; ?>">لم تتم إضافة وحدات قياس إضافية بعد</div>
            </div>
        </div>

        <!-- صور المنتج — اختيار الصور قبل التعديل -->
        <div class="card" id="productImagesCard">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align:middle; margin-left:4px;">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
                    </svg>
                    صور المنتج
                </h2>
                <div style="display:flex; gap:8px; align-items:center;">
                    <label class="btn btn-sm btn-outline" style="cursor:pointer; margin:0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        إضافة صورة
                        <input type="file" id="productImageInput" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none;" onchange="handleImageSelect(this.files)">
                    </label>
                </div>
            </div>
            <div class="card-body">
                <div class="image-drop-zone" id="imageDropZone" onclick="document.getElementById('productImageInput').click()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40" style="color:var(--gray-300); margin-bottom:8px;">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <p style="color:var(--gray-400); margin:0; font-weight:600;">اسحب الصور هنا أو انقر للاختيار</p>
                    <p style="color:var(--gray-300); margin:4px 0 0; font-size:0.78rem;">JPEG, PNG, GIF, WebP — الحد الأقصى 5 ميغابايت</p>
                    <p style="color:var(--primary); margin:6px 0 0; font-size:0.75rem; font-weight:600;">سيتم رفع الصور تلقائياً بعد حفظ التعديلات</p>
                </div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.85rem;">
                        <input type="checkbox" id="removeBgCheckbox" value="1" style="width:16px; height:16px; cursor:pointer;">
                        <span style="font-weight:600;">إزالة الخلفية</span>
                    </label>
                    <select id="bgToleranceSelect" class="form-control" style="width:auto; min-width:120px; padding:4px 8px; font-size:0.82rem; display:none;">
                        <option value="low">منخفض (دقيق)</option>
                        <option value="medium" selected>متوسط</option>
                        <option value="high">عالي (قوي)</option>
                    </select>
                </div>
                <div id="imageUploadProgress" style="display:none; margin-top:12px;">
                    <div style="background:var(--gray-100); border-radius:8px; overflow:hidden; height:8px;">
                        <div id="imageProgressBar" style="background:var(--primary); height:100%; width:0%; transition:width 0.3s;"></div>
                    </div>
                    <p id="imageUploadStatus" style="font-size:0.8rem; color:var(--gray-500); margin-top:4px;">جاري الرفع...</p>
                </div>
                <div id="pendingImageGallery" style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;"></div>

                <!-- معرض الصور الموجودة -->
                <div id="imageGallery" style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;">
                    <?php if (!empty($productImages)):
                        $_editImgBase = (!empty($base_url) ? $base_url . '/' : '/');
                        foreach ($productImages as $img):
                            $thumbWeb = !empty($img['thumbnail_path']) ? $_editImgBase . ProductImageService::getWebPath($img['thumbnail_path']) : '';
                            $mediumWeb = !empty($img['medium_path']) ? $_editImgBase . ProductImageService::getWebPath($img['medium_path']) : $thumbWeb;
                            $isPrimary = !empty($img['is_primary']);
                    ?>
                    <div class="product-image-item" data-image-id="<?php echo $img['id']; ?>" style="width:120px; position:relative; border-radius:10px; overflow:hidden; border:2px solid <?php echo $isPrimary ? 'var(--primary)' : 'var(--gray-200)'; ?>; background:#fff; cursor:pointer;">
                        <div style="width:120px; height:90px; overflow:hidden; display:flex; align-items:center; justify-content:center; background:var(--gray-50);" onclick="viewImage('<?php echo htmlspecialchars($mediumWeb); ?>')">
                            <img src="<?php echo htmlspecialchars($thumbWeb); ?>" alt="صورة" style="max-width:100%; max-height:100%; object-fit:contain;">
                        </div>
                        <div style="padding:4px 6px; font-size:0.7rem; display:flex; justify-content:space-between; align-items:center;">
                            <?php if ($isPrimary): ?>
                            <span style="background:var(--primary); color:#fff; padding:1px 5px; border-radius:4px; font-weight:600;">رئيسية</span>
                            <?php endif; ?>
                            <div style="display:flex; gap:4px; margin-<?php echo $isPrimary ? '' : 'right:auto; margin-left:auto;'; ?>">
                                <button type="button" onclick="event.stopPropagation(); setImagePrimary(<?php echo $img['id']; ?>)" title="تعيين كرئيسية" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--gray-400)'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </button>
                                <button type="button" onclick="event.stopPropagation(); deleteImage(<?php echo $img['id']; ?>)" title="حذف" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='var(--gray-400)'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p id="noImagesMsg" style="text-align:center; color:var(--gray-400); margin-top:16px; <?php echo !empty($productImages) ? 'display:none;' : ''; ?>">لا توجد صور بعد — اختر صورة أو اسحبها هنا</p>
            </div>
        </div>


        <!-- دفعات الشراء -->
        <div class="card" id="purchaseBatchesCard">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align:middle; margin-left:4px;">
                        <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                        <line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                    دفعات الشراء
                    <span style="font-size:0.78rem; font-weight:400; color:var(--gray-400); margin-right:6px;">(<?php echo count($product_batches); ?> دفعة)</span>
                </h2>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="syncBatchesQuantity()" title="مزامنة كمية المنتج مع مجموع الدفعات">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align:middle; margin-left:4px;"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                        مزامنة الكمية
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($product_batches)): ?>
                    <div style="text-align:center; padding:1.5rem; color:var(--gray-400);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="36" height="36" style="color:var(--gray-300); margin-bottom:8px; display:inline-block;"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                        <p style="margin:0;">لا توجد دفعات شراء مسجلة لهذا المنتج بعد</p>
                        <p style="margin:4px 0 0; font-size:0.8rem;">تتم إضافة الدفعات تلقائياً عند تسجيل عملية شراء</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="batchesTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>رقم الشراء</th>
                                    <th>سعر الشراء</th>
                                    <th>الكمية الأصلية</th>
                                    <th>الكمية المتبقية</th>
                                    <th>تاريخ الشراء</th>
                                    <th>تاريخ الانتهاء</th>
                                    <th>الحالة</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $_batchIdx = 0;
                            $_today = new DateTime();
                            $_today->setTime(0, 0, 0);
                            foreach ($product_batches as $_batch):
                                $_batchIdx++;
                                $_bRemaining = floatval($_batch['remaining_quantity'] ?? 0);
                                $_bOriginal = floatval($_batch['original_quantity'] ?? 0);
                                $_bPrice = floatval($_batch['purchase_price'] ?? 0);
                                $_bDate = $_batch['purchase_date'] ?? '';
                                $_bExpiry = $_batch['expiry_date'] ?? '';
                                $_bRef = $_batch['purchase_id'] ?? '';

                                $_statusHtml = '';
                                $_rowBg = '';
                                if ($_bRemaining <= 0) {
                                    $_statusHtml = '<span style="background:#f1f5f9; color:#64748b; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">مستهلكة</span>';
                                    $_rowBg = 'opacity:0.55;';
                                } elseif (!$_bExpiry) {
                                    $_statusHtml = '<span style="background:#f8fafc; color:#475569; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">بدون تاريخ</span>';
                                } else {
                                    $_expDate = new DateTime($_bExpiry);
                                    $_expDate->setTime(0, 0, 0);
                                    $_diffDays = (int) $_today->diff($_expDate)->format('%r%a');
                                    if ($_diffDays < 0) {
                                        $_statusHtml = '<span style="background:#fef2f2; color:#dc2626; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:700;">منتهي الصلاحية</span>';
                                        $_rowBg = 'background:#fef2f2;';
                                    } elseif ($_diffDays <= 30) {
                                        $_statusHtml = '<span style="background:#fffbeb; color:#d97706; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">قارب الانتهاء (' . $_diffDays . ' يوم)</span>';
                                        $_rowBg = 'background:#fffbeb;';
                                    } else {
                                        $_statusHtml = '<span style="background:#f0fdf4; color:#059669; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">صالح (' . $_diffDays . ' يوم)</span>';
                                    }
                                }
                            ?>
                            <tr id="batch-row-<?php echo $_batch['id']; ?>" style="<?php echo $_rowBg; ?>">
                                <td style="font-weight:600; color:var(--gray-500);"><?php echo $_batchIdx; ?></td>
                                <td><?php echo $_bRef ? ('شراء #' . intval($_bRef)) : '<span style="color:var(--gray-400);">— تعديل يدوي —</span>'; ?></td>
                                <td style="font-weight:700; color:#1e40af;"><?php echo number_format($_bPrice, 2); ?></td>
                                <td><?php echo number_format($_bOriginal, 2); ?></td>
                                <td>
                                    <span id="batch-qty-display-<?php echo $_batch['id']; ?>" style="font-weight:800; color:<?php echo $_bRemaining > 0 ? '#059669' : '#94a3b8'; ?>;"><?php echo number_format($_bRemaining, 2); ?></span>
                                    <input type="number" id="batch-qty-input-<?php echo $_batch['id']; ?>" class="form-control" step="0.01" min="0" value="<?php echo $_bRemaining; ?>" style="display:none; width:90px; font-weight:700; border-color:#059669;">
                                </td>
                                <td style="font-size:0.85rem;"><?php echo $_bDate ? htmlspecialchars($_bDate) : '—'; ?></td>
                                <td>
                                    <span id="batch-expiry-display-<?php echo $_batch['id']; ?>" style="font-size:0.85rem;"><?php echo $_bExpiry ? htmlspecialchars($_bExpiry) : '<span style="color:var(--gray-400);">—</span>'; ?></span>
                                    <input type="date" id="batch-expiry-input-<?php echo $_batch['id']; ?>" class="form-control" value="<?php echo $_bExpiry ? htmlspecialchars($_bExpiry) : ''; ?>" style="display:none; width:140px; font-weight:600; border-color:#059669; font-size:0.85rem;">
                                </td>
                                <td><?php echo $_statusHtml; ?></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" id="batch-edit-btn-<?php echo $_batch['id']; ?>" onclick="startEditBatch(<?php echo $_batch['id']; ?>)" title="تعديل الكمية وتاريخ الانتهاء" style="background:none; border:none; cursor:pointer; color:var(--primary); padding:4px 6px; border-radius:6px; font-size:0.8rem; font-weight:600;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='none'">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align:middle;"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button type="button" id="batch-save-btn-<?php echo $_batch['id']; ?>" onclick="saveBatchEdit(<?php echo $_batch['id']; ?>)" title="حفظ" style="display:none; background:#059669; color:#fff; border:none; cursor:pointer; padding:4px 10px; border-radius:6px; font-size:0.78rem; font-weight:700; margin:0 2px;">حفظ</button>
                                    <button type="button" id="batch-cancel-btn-<?php echo $_batch['id']; ?>" onclick="cancelBatchEdit(<?php echo $_batch['id']; ?>)" title="إلغاء" style="display:none; background:none; border:1px solid var(--gray-300); cursor:pointer; padding:4px 10px; border-radius:6px; font-size:0.78rem; font-weight:600; margin:0 2px;">إلغاء</button>
                                    <button type="button" onclick="deleteBatch(<?php echo $_batch['id']; ?>)" title="حذف الدفعة" style="background:none; border:none; cursor:pointer; color:#dc2626; padding:4px 6px; border-radius:6px; font-size:0.8rem;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='none'">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align:middle;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:var(--gray-50); font-weight:700;">
                                    <td colspan="3" style="text-align:center;">الإجمالي</td>
                                    <td style="text-align:center;"><?php echo number_format(array_sum(array_map(function($b){ return floatval($b['original_quantity']); }, $product_batches)), 2); ?></td>
                                    <td style="text-align:center; color:#059669; font-size:1.05rem;" id="batchesTotalRemaining"><?php echo number_format(array_sum(array_map(function($b){ return floatval($b['remaining_quantity']); }, $product_batches)), 2); ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- تواريخ انتهاء الصلاحية -->
        <div class="card" id="expiryDatesCard" style="<?php echo empty($product['has_expiry']) ? 'display:none;' : ''; ?>">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align:middle; margin-left:4px;">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                        <path d="M9 16l2 2 4-4"/>
                    </svg>
                    تواريخ انتهاء الصلاحية
                </h2>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:0.85rem; margin-bottom:0.75rem;">
                <strong>ملاحظة:</strong> يمكنك عرض وإدارة الدفعات في قسم <strong>"دفعات الشراء"</strong> أعلاه. هذا القسم مخصص لإضافة تواريخ انتهاء عند زيادة الكمية.
            </div>

                <!-- قسم إضافة تواريخ جديدة (يظهر عند تغيير الكمية) -->
                <div id="new-expiry-section" style="display:none;">
                    <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:0.5rem; margin-bottom:0.75rem;">
                        <h3 style="margin:0; font-size:0.95rem; color:var(--gray-700);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;">
                                <path d="M12 4v16m8-8H4"/>
                            </svg>
                            إضافة تواريخ انتهاء للكمية الجديدة
                        </h3>
                        <button type="button" onclick="addExpiryRowEdit()"
                                style="background:#eff6ff; color:#2563eb; border:1px solid #93c5fd; padding:6px 14px; border-radius:8px; cursor:pointer; font-weight:700; font-size:0.85rem;">
                            + إضافة تاريخ
                        </button>
                    </div>
                    <div class="alert alert-info" id="newExpiryInfoAlert" style="font-size:0.85rem;">
                        <strong>ملاحظة:</strong> مجموع كميات التواريخ الجديدة يجب أن يساوي الزيادة في الكمية
                        (<span id="newExpiryQtyDiff" style="font-weight:800; color:#2563eb;">0</span>)
                    </div>
                    <div id="new-expiry-rows-container"></div>
                    <div id="new-expiry-summary" style="display:none; margin-top:0.75rem;">
                        <div style="display:flex; gap:12px; flex-wrap:wrap;">
                            <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">مجموع كميات التواريخ الجديدة</div>
                                <div id="newExpiryTotalQty" style="font-weight:800; color:#2563eb; font-size:1.1rem;">0</div>
                            </div>
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">الزيادة المطلوبة</div>
                                <div id="newExpiryRequiredQty" style="font-weight:800; color:#1e40af; font-size:1.1rem;">0</div>
                            </div>
                            <div id="newExpiryDiffBox" style="border-radius:8px; padding:8px 14px; text-align:center; display:none;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">الفارق</div>
                                <div id="newExpiryDiff" style="font-weight:800; font-size:1.1rem;">0</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- رسالة عند عدم تغيير الكمية -->
                <div id="expiry-no-change-msg" style="display:none; text-align:center; padding:1rem; color:var(--gray-400); font-size:0.9rem;">
                    قم بتغيير حقل <strong>الكمية الحالية</strong> أعلاه لإضافة تواريخ انتهاء صلاحية جديدة.
                </div>
            </div>
        </div>

        <div style="margin-top:1.5rem;" class="btn-group">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">حفظ التعديلات</button>
            <a href="list-products.php" class="btn btn-outline btn-lg">إلغاء</a>
        </div>
    </form>

    <!-- سجل تغيير الأسعار -->
    <div class="card" style="margin-top:2rem;">
        <div class="card-header"><h2>سجل تغيير الأسعار</h2></div>
        <div class="card-body">
            <?php if (empty($price_history)): ?>
                <p class="text-muted" style="text-align:center; padding:1.5rem;">لم يتم تسجيل أي تغيير في الأسعار بعد</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ والوقت</th>
                            <th>سعر الشراء القديم</th>
                            <th>سعر الشراء الجديد</th>
                            <th>سعر البيع القديم</th>
                            <th>سعر البيع الجديد</th>
                            <th>السبب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($price_history as $ph): ?>
                        <tr>
                            <td><?php echo $ph['changed_at']; ?></td>
                            <td><?php echo formatMoney($ph['old_purchase_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($ph['new_purchase_price']); ?></td>
                            <td><?php echo formatMoney($ph['old_selling_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($ph['new_selling_price']); ?></td>
                            <td><?php echo htmlspecialchars($ph['change_reason'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// دالة مساعدة لبناء قائمة الوحدات المنسدلة حسب نوع الوحدة الأساسية
function buildEditUnitOptions($allUnits, $baseUnitAbbr, $selectedVal) {
    $baseType = '';
    foreach ($allUnits as $u) {
        if ($u['abbreviation'] === $baseUnitAbbr) {
            $baseType = $u['unit_type'] ?? '';
            break;
        }
    }
    $html = '<option value="">-- اختر الوحدة --</option>';
    foreach ($allUnits as $u) {
        $utype = $u['unit_type'] ?? '';
        if ($baseType && $utype !== $baseType) continue;
        $sel = ($selectedVal === $u['abbreviation'] && $selectedVal !== '__custom__') ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($u['abbreviation']) . '"' . $sel . '>' . htmlspecialchars($u['name']) . ' (' . htmlspecialchars($u['abbreviation']) . ')</option>';
    }
    $selCustom = ($selectedVal === '__custom__') ? ' selected' : '';
    $html .= '<option value="__custom__"' . $selCustom . '>────────── أخرى ──────────</option>';
    return $html;
}

function buildEditPuUnitOptions($allUnits, $baseUnitAbbr, $selectedVal) {
    $baseType = '';
    foreach ($allUnits as $u) {
        if ($u['abbreviation'] === $baseUnitAbbr) {
            $baseType = $u['unit_type'] ?? '';
            break;
        }
    }
    $html = '<option value="">-- اختر الوحدة --</option>';
    foreach ($allUnits as $u) {
        $utype = $u['unit_type'] ?? '';
        if ($baseType && $utype !== $baseType) continue;
        // استبعاد وحدة البيع الأساسية نفسها (لأنها ليست وحدة شراء إضافية)
        $sel = ($selectedVal === $u['abbreviation'] && $selectedVal !== '__custom__') ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($u['abbreviation']) . '"' . $sel . '>' . htmlspecialchars($u['name']) . ' (' . htmlspecialchars($u['abbreviation']) . ')</option>';
    }
    $selCustom = ($selectedVal === '__custom__') ? ' selected' : '';
    $html .= '<option value="__custom__"' . $selCustom . '>────────── أخرى ──────────</option>';
    return $html;
}
?>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === بيانات الوحدات (من PHP) ===
var unitsList = <?php echo json_encode(array_map(function($u) { return ['name' => $u['name'], 'abbr' => $u['abbreviation'], 'type' => isset($u['unit_type']) ? $u['unit_type'] : 'other', 'conv' => isset($u['conversion_to_base']) ? floatval($u['conversion_to_base']) : 1, 'isBase' => isset($u['is_base_unit']) ? intval($u['is_base_unit']) : 0]; }, $units)); ?>;
var baseUnitsByType = <?php echo json_encode($base_units_by_type); ?>;

// === القيم الأصلية للأسعار ===
var originalPurchasePrice = <?php echo $product['purchase_price']; ?>;
var originalSellingPrice = <?php echo $product['selling_price']; ?>;

function checkPriceChange() {
    var currentPurchase = parseFloat(document.getElementById('purchase_price_input').value) || 0;
    var currentSelling = parseFloat(document.getElementById('selling_price_input').value) || 0;
    var purchaseChanged = currentPurchase !== originalPurchasePrice;
    var sellingChanged = currentSelling !== originalSellingPrice;
    document.getElementById('purchase_price_hint').style.display = purchaseChanged ? 'block' : 'none';
    document.getElementById('selling_price_hint').style.display = sellingChanged ? 'block' : 'none';
    document.getElementById('price_change_section').style.display = (purchaseChanged || sellingChanged) ? 'block' : 'none';
}

// === تحديد نوع الوحدة الأساسية ===
function getSelectedBaseUnitType() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return '';
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].type;
    }
    return '';
}

// === بناء قائمة الوحدات المناسبة لنوع الوحدة الأساسية ===
function buildUnitOptionsHTML(selectedVal) {
    var baseType = getSelectedBaseUnitType();
    var html = '<option value="">-- اختر الوحدة --</option>';
    for (var i = 0; i < unitsList.length; i++) {
        var u = unitsList[i];
        if (baseType && u.type !== baseType) continue;
        var sel = (selectedVal === u.abbr) ? ' selected' : '';
        html += '<option value="' + u.abbr + '"' + sel + '>' + u.name + ' (' + u.abbr + ')</option>';
    }
    var selCustom = (selectedVal === '__custom__') ? ' selected' : '';
    html += '<option value="__custom__"' + selCustom + '>────────── أخرى ──────────</option>';
    return html;
}

// === تحديث جميع قوائم الوحدات الإضافية عند تغيير الوحدة الأساسية ===
function refreshAllAdditionalUnitDropdowns() {
    var rows = document.querySelectorAll('#additional-units-container .unit-row');
    rows.forEach(function(row) {
        var rowId = row.id;
        var sel = row.querySelector('select[name="au_name[]"]');
        var factorInput = row.querySelector('input[name="au_factor[]"]');
        var customWrap = row.querySelector('.au-custom-name-wrap');
        if (!sel) return;
        var prevVal = sel.value;
        sel.innerHTML = buildUnitOptionsHTML('');
        if (prevVal && prevVal !== '__custom__') {
            var opts = sel.querySelectorAll('option');
            var found = false;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === prevVal) { found = true; break; }
            }
            if (found) {
                sel.value = prevVal;
                // تحديث معامل التحويل من unitsList بعد تغيير الوحدة الأساسية
                handleAuUnitChange(rowId);
            } else {
                sel.value = '';
                if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
            }
        } else {
            sel.value = '';
            if (customWrap) customWrap.classList.remove('active');
            var customInput = row.querySelector('input[name="au_custom_name[]"]');
            if (customInput) customInput.value = '';
        }
    });
}

// === وحدات القياس الإضافية ===
var unitCounter = <?php echo count($additional_units_prepared); ?>;

function addUnitRow() {
    unitCounter++;
    var container = document.getElementById('additional-units-container');
    document.getElementById('no-units-msg').style.display = 'none';
    var row = document.createElement('div');
    row.className = 'unit-row';
    row.id = 'unit-row-' + unitCounter;
    row.innerHTML = `
        <div class="form-group" style="min-width:160px;">
            <label>اسم الوحدة</label>
            <select name="au_name[]" class="form-control" onchange="handleAuUnitChange('unit-row-${unitCounter}')">
                ${buildUnitOptionsHTML('')}
            </select>
            <div class="au-custom-name-wrap" id="au-custom-${unitCounter}">
                <input type="text" name="au_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
            </div>
        </div>
        <div class="form-group">
            <label>معامل التحويل <span class="form-text au-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
            <input type="number" name="au_factor[]" class="form-control" step="0.0001" min="0.0001" value="1" placeholder="1">
        </div>
        <div class="form-group">
            <label>سعر البيع</label>
            <input type="number" name="au_price[]" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
        </div>
        <button type="button" class="btn-remove-unit" onclick="removeUnitRow('unit-row-${unitCounter}')">✕</button>
    `;
    container.appendChild(row);
}

function getBaseUnitConv() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return 1;
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].conv;
    }
    return 1;
}

function getBaseUnitAbbr() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return '';
    return sel.value;
}

function handleAuUnitChange(rowId) {
    var row = document.getElementById(rowId);
    if (!row) return;
    var sel = row.querySelector('select[name="au_name[]"]');
    var customWrap = row.querySelector('.au-custom-name-wrap');
    var customInput = row.querySelector('input[name="au_custom_name[]"]');
    var factorInput = row.querySelector('input[name="au_factor[]"]');
    if (!sel || !customWrap) return;

    if (sel.value === '__custom__') {
        customWrap.classList.add('active');
        if (customInput) customInput.focus();
        if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
    } else {
        customWrap.classList.remove('active');
        if (customInput) customInput.value = '';
        // حساب معامل التحويل النسبي بين الوحدة الإضافية ووحدة المنتج الأساسية
        // المعامل = conversion_to_base للوحدة الإضافية / conversion_to_base للوحدة الأساسية
        // مثال: إذا الوحدة الأساسية هي أوقية (conv=0.05) والإضافية كلغ (conv=1)
        // المعامل = 1 / 0.05 = 20  أي 1 كلغ = 20 أوقية
        if (factorInput) {
            var matched = false;
            var baseConv = getBaseUnitConv();
            for (var i = 0; i < unitsList.length; i++) {
                if (unitsList[i].abbr === sel.value) {
                    matched = true;
                    var auConv = unitsList[i].conv;
                    var relativeConv = (baseConv > 0) ? (auConv / baseConv) : 1;
                    if (relativeConv > 0) {
                        factorInput.value = relativeConv;
                    } else {
                        factorInput.value = '1';
                    }
                    // تحديث التلميح ليوضح الوحدة الأساسية الفعلية
                    var hintSpan = row.querySelector('.au-conv-hint');
                    if (hintSpan) {
                        var baseAbbr = getBaseUnitAbbr();
                        hintSpan.textContent = '(1 ' + sel.value + ' = ? ' + baseAbbr + ')';
                    }
                    if (relativeConv === 1) {
                        factorInput.setAttribute('readonly', true);
                        factorInput.style.opacity = '0.5';
                    } else {
                        factorInput.removeAttribute('readonly');
                        factorInput.style.opacity = '1';
                    }
                    break;
                }
            }
            if (!matched) {
                factorInput.value = '1';
                factorInput.removeAttribute('readonly');
                factorInput.style.opacity = '1';
            }
        }
    }
}

function removeUnitRow(id) {
    var row = document.getElementById(id);
    if (row) {
        row.remove();
        var container = document.getElementById('additional-units-container');
        if (container.children.length === 0) {
            document.getElementById('no-units-msg').style.display = 'block';
        }
    }
}

// === فتح/إغلاق قسم الوحدة المخصصة ===
function toggleCustomUnitSection() {
    var section = document.getElementById('customUnitSection');
    var body = document.getElementById('customUnitBody');
    var arrow = document.getElementById('customUnitArrow');
    if (!section.classList.contains('active')) {
        section.classList.add('active');
        body.style.display = '';
        arrow.style.transform = '';
        document.getElementById('customUnitName').focus();
    } else {
        body.style.display = 'none';
        arrow.style.transform = 'rotate(-90deg)';
    }
}

// === تحديث معامل التحويل المخصص ===
var customUnitType = document.getElementById('customUnitType');
var customUnitConversion = document.getElementById('customUnitConversion');
var customConversionHint = document.getElementById('customConversionHint');

function updateCustomConversionHint() {
    if (!customUnitType || !customUnitConversion || !customConversionHint) return;
    var type = customUnitType.value;
    var bu = baseUnitsByType[type];
    var val = parseFloat(customUnitConversion.value) || 1;
    if (bu) {
        customConversionHint.innerHTML = '1 وحدة مخصصة = <strong>' + val + '</strong> ' + bu.name + ' (' + bu.abbreviation + ')';
    } else {
        customConversionHint.innerHTML = '1 وحدة مخصصة = <strong>' + val + '</strong> وحدة أساسية';
    }
}

if (customUnitType) customUnitType.addEventListener('change', updateCustomConversionHint);
if (customUnitConversion) customUnitConversion.addEventListener('input', updateCustomConversionHint);

// === تهيئة عند التحميل ===
document.addEventListener('DOMContentLoaded', function() {
    // عند تغيير الوحدة الأساسية، حدّث قوائم الوحدات الإضافية
    var unitSelectMain = document.getElementById('unitSelect');
    if (unitSelectMain) {
        unitSelectMain.addEventListener('change', function() {
            refreshAllAdditionalUnitDropdowns();
        });
    }

    // تحديث معاملات التحويل للوحدات الإضافية الموجودة عند تحميل الصفحة
    var existingRows = document.querySelectorAll('#additional-units-container .unit-row');
    existingRows.forEach(function(row) {
        var sel = row.querySelector('select[name="au_name[]"]');
        if (sel && sel.value && sel.value !== '' && sel.value !== '__custom__') {
            handleAuUnitChange(row.id);
        }
    });

    // التحقق من الوحدات المخصصة قبل الإرسال
    var form = document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            var customName = document.getElementById('customUnitName');
            var customSection = document.getElementById('customUnitSection');
            if (customSection && customSection.classList.contains('active') && customName && customName.value.trim()) {
                var customConv = document.getElementById('customUnitConversion');
                if (parseFloat(customConv.value) <= 0) {
                    e.preventDefault();
                    customConv.style.borderColor = 'var(--danger)';
                    customConv.focus();
                    alert('معامل التحويل يجب أن يكون أكبر من صفر');
                    return;
                }
            }
            var rows = document.querySelectorAll('#additional-units-container .unit-row');
            rows.forEach(function(row) {
                var sel = row.querySelector('select[name="au_name[]"]');
                var customInput = row.querySelector('input[name="au_custom_name[]"]');
                if (sel && sel.value === '__custom__' && customInput && !customInput.value.trim()) {
                    e.preventDefault();
                    customInput.style.borderColor = 'var(--danger)';
                    customInput.focus();
                    alert('يرجى إدخال اسم الوحدة المخصصة');
                }
            });
            // التحقق من وحدات الشراء المخصصة
            var puRows = document.querySelectorAll('#purchase-additional-units-container .unit-row');
            puRows.forEach(function(row) {
                var sel = row.querySelector('select[name="pau_name[]"]');
                var customInput = row.querySelector('input[name="pau_custom_name[]"]');
                if (sel && sel.value === '__custom__' && customInput && !customInput.value.trim()) {
                    e.preventDefault();
                    customInput.style.borderColor = 'var(--danger)';
                    customInput.focus();
                    alert('يرجى إدخال اسم وحدة الشراء المخصصة');
                }
            });
        });
    }

    // تهيئة وحدات الشراء الإضافية عند تحميل الصفحة
    initPurchaseUnitSystem();

    // التحقق الأولي من تغيير الكمية
    checkQuantityChange();

    // مراقبة تغيير الكمية لفتح قسم التواريخ
    var qtyInputField = document.querySelector('input[name="quantity"]');
    if (qtyInputField) {
        qtyInputField.addEventListener('input', function() {
            var hasExpiry = document.getElementById('has_expiry');
            if (hasExpiry && hasExpiry.checked) {
                checkQuantityChange();
            }
        });
    }
});

// === نظام وحدات الشراء ===
var purchaseUnitCounter = <?php echo count($purchase_additional_units_prepared); ?>;

function onPurchaseUnitChange() {
    var puSel = document.getElementById('purchaseUnitSelect');
    var factorGroup = document.getElementById('purchaseUnitFactorGroup');
    var factorInput = document.getElementById('purchaseUnitFactor');
    var hintSpan = document.getElementById('purchaseUnitFactorHint');
    if (!puSel || !factorGroup) return;

    if (puSel.value) {
        factorGroup.style.display = '';
        // حساب معامل التحويل النسبي (كما في handleAuUnitChange)
        var baseConv = getBaseUnitConv();
        for (var i = 0; i < unitsList.length; i++) {
            if (unitsList[i].abbr === puSel.value) {
                var auConv = unitsList[i].conv;
                var relativeConv = (baseConv > 0) ? (auConv / baseConv) : 1;
                factorInput.value = relativeConv;
                if (hintSpan) {
                    var baseAbbr = getBaseUnitAbbr();
                    hintSpan.textContent = '(1 ' + puSel.value + ' = ? ' + baseAbbr + ')';
                }
                break;
            }
        }
        refreshAllPurchaseUnitDropdowns();
    } else {
        factorGroup.style.display = 'none';
        factorInput.value = '1';
        refreshAllPurchaseUnitDropdowns();
    }
}

function getPurchaseBaseUnitConv() {
    var sel = document.getElementById('purchaseUnitSelect');
    if (!sel || !sel.value) return getBaseUnitConv();
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].conv;
    }
    return 1;
}

function getPurchaseBaseUnitAbbr() {
    var sel = document.getElementById('purchaseUnitSelect');
    if (!sel || !sel.value) return getBaseUnitAbbr();
    return sel.value;
}

function getSelectedPurchaseBaseUnitType() {
    var sel = document.getElementById('purchaseUnitSelect');
    if (!sel || !sel.value) return getSelectedBaseUnitType();
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].type;
    }
    return '';
}

function buildPuUnitOptionsHTML(selectedVal) {
    var puBaseType = getSelectedPurchaseBaseUnitType();
    var html = '<option value="">-- اختر الوحدة --</option>';
    for (var i = 0; i < unitsList.length; i++) {
        var u = unitsList[i];
        if (puBaseType && u.type !== puBaseType) continue;
        var sel = (selectedVal === u.abbr) ? ' selected' : '';
        html += '<option value="' + u.abbr + '"' + sel + '>' + u.name + ' (' + u.abbr + ')</option>';
    }
    html += '<option value="__custom__">────────── أخرى ──────────</option>';
    return html;
}

function refreshAllPurchaseUnitDropdowns() {
    var rows = document.querySelectorAll('#purchase-additional-units-container .unit-row');
    rows.forEach(function(row) {
        var rowId = row.id;
        var sel = row.querySelector('select[name="pau_name[]"]');
        var factorInput = row.querySelector('input[name="pau_factor[]"]');
        var customWrap = row.querySelector('.au-custom-name-wrap');
        if (!sel) return;
        var prevVal = sel.value;
        sel.innerHTML = buildPuUnitOptionsHTML('');
        if (prevVal && prevVal !== '__custom__') {
            var opts = sel.querySelectorAll('option');
            var found = false;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === prevVal) { found = true; break; }
            }
            if (found) {
                sel.value = prevVal;
                handlePuUnitChange(rowId);
            } else {
                sel.value = '';
                if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
            }
        } else {
            sel.value = '';
            if (customWrap) customWrap.classList.remove('active');
            var customInput = row.querySelector('input[name="pau_custom_name[]"]');
            if (customInput) customInput.value = '';
        }
    });
}

function addPuUnitRow() {
    purchaseUnitCounter++;
    var container = document.getElementById('purchase-additional-units-container');
    document.getElementById('no-pu-units-msg').style.display = 'none';
    var row = document.createElement('div');
    row.className = 'unit-row';
    row.id = 'pu-row-' + purchaseUnitCounter;
    row.innerHTML = `
        <div class="form-group" style="min-width:160px;">
            <label>اسم الوحدة</label>
            <select name="pau_name[]" class="form-control" onchange="handlePuUnitChange('pu-row-${purchaseUnitCounter}')">
                ${buildPuUnitOptionsHTML('')}
            </select>
            <div class="au-custom-name-wrap" id="pu-custom-${purchaseUnitCounter}">
                <input type="text" name="pau_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
            </div>
        </div>
        <div class="form-group">
            <label>معامل التحويل <span class="form-text pu-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
            <input type="number" name="pau_factor[]" class="form-control" step="0.0001" min="0.0001" value="1" placeholder="1">
        </div>
        <button type="button" class="btn-remove-unit" onclick="removePuUnitRow('pu-row-${purchaseUnitCounter}')">✕</button>
    `;
    container.appendChild(row);
}

function handlePuUnitChange(rowId) {
    var row = document.getElementById(rowId);
    if (!row) return;
    var sel = row.querySelector('select[name="pau_name[]"]');
    var customWrap = row.querySelector('.au-custom-name-wrap');
    var customInput = row.querySelector('input[name="pau_custom_name[]"]');
    var factorInput = row.querySelector('input[name="pau_factor[]"]');
    if (!sel || !customWrap) return;

    if (sel.value === '__custom__') {
        customWrap.classList.add('active');
        if (customInput) customInput.focus();
        if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
    } else {
        customWrap.classList.remove('active');
        if (customInput) customInput.value = '';
        if (factorInput) {
            var puBaseConv = getPurchaseBaseUnitConv();
            for (var i = 0; i < unitsList.length; i++) {
                if (unitsList[i].abbr === sel.value) {
                    var auConv = unitsList[i].conv;
                    var relativeConv = (puBaseConv > 0) ? (auConv / puBaseConv) : 1;
                    factorInput.value = relativeConv;
                    var hintSpan = row.querySelector('.pu-conv-hint');
                    if (hintSpan) {
                        var puBaseAbbr = getPurchaseBaseUnitAbbr();
                        hintSpan.textContent = '(1 ' + sel.value + ' = ? ' + puBaseAbbr + ')';
                    }
                    if (relativeConv === 1) {
                        factorInput.setAttribute('readonly', true);
                        factorInput.style.opacity = '0.5';
                    } else {
                        factorInput.removeAttribute('readonly');
                        factorInput.style.opacity = '1';
                    }
                    break;
                }
            }
        }
    }
}

function removePuUnitRow(id) {
    var row = document.getElementById(id);
    if (row) {
        row.remove();
        var container = document.getElementById('purchase-additional-units-container');
        if (container.children.length === 0) {
            document.getElementById('no-pu-units-msg').style.display = 'block';
        }
    }
}

function initPurchaseUnitSystem() {
    // تهيئة معامل التحويل لوحدة الشراء الأساسية
    var puSel = document.getElementById('purchaseUnitSelect');
    if (puSel && puSel.value) {
        onPurchaseUnitChange();
    }
    // تهيئة تلميحات وحدات الشراء الإضافية
    var puRows = document.querySelectorAll('#purchase-additional-units-container .unit-row');
    puRows.forEach(function(row) {
        var sel = row.querySelector('select[name="pau_name[]"]');
        if (sel && sel.value && sel.value !== '' && sel.value !== '__custom__') {
            handlePuUnitChange(row.id);
        }
    });
}

// === تواريخ انتهاء الصلاحية (صفحة التعديل) ===
var productId = <?php echo $id; ?>;
var editExpiryCounter = 0;
var newExpiryCounter = 0;
var originalQuantity = <?php echo floatval($product['quantity']); ?>;

function toggleExpirySectionEdit() {
    var checkbox = document.getElementById('has_expiry');
    var card = document.getElementById('expiryDatesCard');
    if (checkbox.checked) {
        card.style.display = '';
        checkQuantityChange();
    } else {
        card.style.display = 'none';
    }
}

// === مراقبة تغيير الكمية ===
function checkQuantityChange() {
    var qtyInput = document.querySelector('input[name="quantity"]');
    if (!qtyInput) return;
    var newQty = parseFloat(qtyInput.value) || 0;
    var diff = newQty - originalQuantity;
    var newSection = document.getElementById('new-expiry-section');
    var noChangeMsg = document.getElementById('expiry-no-change-msg');
    var diffHint = document.getElementById('newExpiryQtyDiff');

    if (diff > 0.001) {
        // الكمية زادت → إظهار قسم إضافة تواريخ
        if (newSection) newSection.style.display = '';
        if (noChangeMsg) noChangeMsg.style.display = 'none';
        if (diffHint) diffHint.textContent = diff.toFixed(2);
        document.getElementById('newExpiryRequiredQty').textContent = diff.toFixed(2);
        updateNewExpirySummary();
    } else {
        // الكمية لم تتغير أو نقصت → إخفاء قسم الإضافة
        if (newSection) newSection.style.display = 'none';
        if (noChangeMsg) {
            if (diff < -0.001) {
                noChangeMsg.innerHTML = 'الكمية تناقصت بمقدار <strong style="color:#dc2626;">' + Math.abs(diff).toFixed(2) + '</strong>. سيتم خصم الكمية من أقدم الدفعات عند الحفظ.';
                noChangeMsg.style.display = '';
            } else {
                noChangeMsg.innerHTML = 'قم بزيادة حقل <strong>الكمية الحالية</strong> أعلاه لإضافة تواريخ انتهاء صلاحية جديدة.';
                noChangeMsg.style.display = '';
            }
        }
    }
}

// === إضافة صف تاريخ انتهاء جديد (قابل للتعديل) ===
function addExpiryRowEdit() {
    newExpiryCounter++;
    var container = document.getElementById('new-expiry-rows-container');
    var row = document.createElement('div');
    row.className = 'new-expiry-row';
    row.id = 'new-expiry-row-' + newExpiryCounter;
    row.style.cssText = 'display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:10px 14px; background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #93c5fd; border-radius:10px; margin-bottom:8px; animation: fadeSlideDown 0.3s ease;';
    row.innerHTML = '\
        <div style="flex:1; min-width:160px;">\
            <label style="font-size:0.78rem; font-weight:600; color:#1e40af; display:block; margin-bottom:3px;">تاريخ الانتهاء</label>\
            <input type="date" name="new_expiry_date[]" class="form-control" style="border-color:#93c5fd; font-weight:600;" onchange="updateNewExpirySummary()">\
        </div>\
        <div style="min-width:140px;">\
            <label style="font-size:0.78rem; font-weight:600; color:#1e40af; display:block; margin-bottom:3px;">الكمية</label>\
            <input type="number" name="new_expiry_quantity[]" class="form-control" step="0.01" min="0.01" value="0" style="border-color:#93c5fd; font-weight:700;" oninput="updateNewExpirySummary()">\
        </div>\
        <button type="button" onclick="removeNewExpiryRow(\'new-expiry-row-' + newExpiryCounter + '\')" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding:6px;" title="حذف">✕</button>\
    ';
    container.appendChild(row);
    // ضبط الكمية تلقائياً على الزيادة المتبقية إذا كان هناك تاريخ واحد فقط
    var allNewRows = container.querySelectorAll('.new-expiry-row');
    if (allNewRows.length === 1) {
        var qtyInput = document.querySelector('input[name="quantity"]');
        var diff = (parseFloat(qtyInput.value) || 0) - originalQuantity;
        if (diff > 0) {
            row.querySelector('input[name="new_expiry_quantity[]"]').value = diff.toFixed(2);
        }
    }
    updateNewExpirySummary();
}

function removeNewExpiryRow(id) {
    var row = document.getElementById(id);
    if (row) row.remove();
    updateNewExpirySummary();
}

function updateNewExpirySummary() {
    var totalQty = 0;
    var rows = document.querySelectorAll('.new-expiry-row');
    rows.forEach(function(row) {
        var qtyInput = row.querySelector('input[name="new_expiry_quantity[]"]');
        if (qtyInput) totalQty += parseFloat(qtyInput.value) || 0;
    });

    var qtyInput = document.querySelector('input[name="quantity"]');
    var newQty = parseFloat(qtyInput.value) || 0;
    var diff = newQty - originalQuantity;

    var totalEl = document.getElementById('newExpiryTotalQty');
    var requiredEl = document.getElementById('newExpiryRequiredQty');
    var diffBox = document.getElementById('newExpiryDiffBox');
    var diffEl = document.getElementById('newExpiryDiff');
    var summary = document.getElementById('newExpirySummary');
    var diffHint = document.getElementById('newExpiryQtyDiff');

    if (!totalEl) return;

    requiredEl.textContent = diff.toFixed(2);
    if (diffHint) diffHint.textContent = diff.toFixed(2);
    totalEl.textContent = totalQty.toFixed(2);

    if (totalQty > 0 || diff > 0) {
        summary.style.display = '';
        diffBox.style.display = '';
        var diffVal = totalQty - diff;
        diffEl.textContent = (diffVal >= 0 ? '+' : '') + diffVal.toFixed(2);
        if (Math.abs(diffVal) < 0.01) {
            diffBox.style.background = '#f0fdf4';
            diffBox.style.borderColor = '#86efac';
            diffBox.style.color = '#059669';
        } else {
            diffBox.style.background = '#fef2f2';
            diffBox.style.borderColor = '#fca5a5';
            diffBox.style.color = '#dc2626';
        }
    } else {
        summary.style.display = 'none';
    }
}

// === إدارة الدفعات (تعديل، حذف، مزامنة) ===
var BATCH_API_URL = '../api/manage-batches.php';

function getBatchCsrfToken() {
    var csrfField = document.querySelector('input[name="csrf_token"]');
    return csrfField ? csrfField.value : '';
}

function startEditBatch(batchId) {
    var qtyDisplayEl = document.getElementById('batch-qty-display-' + batchId);
    var qtyInputEl = document.getElementById('batch-qty-input-' + batchId);
    var expDisplayEl = document.getElementById('batch-expiry-display-' + batchId);
    var expInputEl = document.getElementById('batch-expiry-input-' + batchId);
    var editBtn = document.getElementById('batch-edit-btn-' + batchId);
    var saveBtn = document.getElementById('batch-save-btn-' + batchId);
    var cancelBtn = document.getElementById('batch-cancel-btn-' + batchId);
    if (!qtyDisplayEl || !qtyInputEl) return;

    qtyDisplayEl.style.display = 'none';
    qtyInputEl.style.display = '';
    qtyInputEl.focus();
    qtyInputEl.select();
    if (expDisplayEl) expDisplayEl.style.display = 'none';
    if (expInputEl) expInputEl.style.display = '';
    if (editBtn) editBtn.style.display = 'none';
    if (saveBtn) saveBtn.style.display = '';
    if (cancelBtn) cancelBtn.style.display = '';
}

function cancelBatchEdit(batchId) {
    var qtyDisplayEl = document.getElementById('batch-qty-display-' + batchId);
    var qtyInputEl = document.getElementById('batch-qty-input-' + batchId);
    var expDisplayEl = document.getElementById('batch-expiry-display-' + batchId);
    var expInputEl = document.getElementById('batch-expiry-input-' + batchId);
    var editBtn = document.getElementById('batch-edit-btn-' + batchId);
    var saveBtn = document.getElementById('batch-save-btn-' + batchId);
    var cancelBtn = document.getElementById('batch-cancel-btn-' + batchId);
    if (!qtyDisplayEl || !qtyInputEl) return;

    // استعادة القيم الأصلية
    qtyInputEl.value = qtyDisplayEl.textContent.trim();
    qtyDisplayEl.style.display = '';
    qtyInputEl.style.display = 'none';
    if (expDisplayEl && expInputEl) {
        var originalExpiry = expDisplayEl.textContent.trim();
        expInputEl.value = (originalExpiry === '—') ? '' : originalExpiry;
        expDisplayEl.style.display = '';
        expInputEl.style.display = 'none';
    }
    if (editBtn) editBtn.style.display = '';
    if (saveBtn) saveBtn.style.display = 'none';
    if (cancelBtn) cancelBtn.style.display = 'none';
}

function saveBatchEdit(batchId) {
    var qtyInputEl = document.getElementById('batch-qty-input-' + batchId);
    var qtyDisplayEl = document.getElementById('batch-qty-display-' + batchId);
    var expInputEl = document.getElementById('batch-expiry-input-' + batchId);
    var expDisplayEl = document.getElementById('batch-expiry-display-' + batchId);
    if (!qtyInputEl) return;

    var newQty = parseFloat(qtyInputEl.value);
    if (isNaN(newQty) || newQty < 0) {
        alert('الكمية يجب أن تكون عدداً أكبر من أو يساوي صفر');
        qtyInputEl.focus();
        return;
    }

    var oldQty = parseFloat(qtyDisplayEl.textContent.trim());
    var oldExpiry = expDisplayEl ? expDisplayEl.textContent.trim() : '';
    var newExpiry = expInputEl ? expInputEl.value : '';
    var expiryChanged = (newExpiry !== '' && oldExpiry === '—') || (newExpiry === '' && oldExpiry !== '—' && oldExpiry !== '') || (newExpiry !== '' && oldExpiry === newExpiry ? false : (newExpiry !== oldExpiry));
    var qtyChanged = Math.abs(newQty - oldQty) >= 0.001;

    if (!qtyChanged && !expiryChanged) {
        cancelBatchEdit(batchId);
        return;
    }

    var saveBtn = document.getElementById('batch-save-btn-' + batchId);
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'جاري الحفظ...'; }

    var csrfToken = getBatchCsrfToken();
    var requestsLeft = (qtyChanged ? 1 : 0) + (expiryChanged ? 1 : 0);
    var hasError = false;
    var savedQty = oldQty, savedExpiry = oldExpiry;

    function onReqDone() {
        requestsLeft--;
        if (requestsLeft <= 0) {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'حفظ'; }
            if (!hasError) {
                // تحديث عرض الكمية
                if (qtyChanged) {
                    qtyDisplayEl.textContent = savedQty.toFixed(2);
                    qtyDisplayEl.style.color = savedQty > 0 ? '#059669' : '#94a3b8';
                }
                // تحديث عرض تاريخ الانتهاء
                if (expiryChanged && expDisplayEl) {
                    expDisplayEl.innerHTML = savedExpiry ? savedExpiry : '<span style="color:var(--gray-400);">—</span>';
                }
                // تحديث شارة الحالة
                updateBatchStatusBadge(batchId, savedExpiry, savedQty);
                cancelBatchEdit(batchId);
                // تحديث الإجماليات
                if (qtyChanged) {
                    updateBatchesTotal();
                    updateProductQuantityField();
                }
                var msgs = [];
                if (qtyChanged) msgs.push('الكمية');
                if (expiryChanged) msgs.push('تاريخ الانتهاء');
                alert('تم تعديل ' + msgs.join(' و') + ' بنجاح');
            }
        }
    }

    function sendRequest(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        for (var k in params) { fd.append(k, params[k]); }
        if (csrfToken) fd.append('csrf_token', csrfToken);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BATCH_API_URL, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var result = JSON.parse(xhr.responseText);
                    if (!result.success) {
                        hasError = true;
                        alert('خطأ: ' + (result.message || 'فشل الحفظ'));
                    }
                } catch(e) {
                    hasError = true;
                    alert('خطأ في استجابة الخادم');
                }
            } else {
                hasError = true;
                alert('خطأ في الاتصال بالخادم');
            }
            onReqDone();
        };
        xhr.onerror = function() {
            hasError = true;
            alert('خطأ في الاتصال');
            onReqDone();
        };
        xhr.send(fd);
    }

    // حفظ الكمية إذا تغيرت
    if (qtyChanged) {
        savedQty = newQty;
        sendRequest('update_batch_quantity', { batch_id: batchId, remaining_quantity: newQty });
    }

    // حفظ تاريخ الانتهاء إذا تغير
    if (expiryChanged) {
        savedExpiry = newExpiry;
        sendRequest('update_batch_expiry', { batch_id: batchId, expiry_date: newExpiry });
    }
}

function updateBatchStatusBadge(batchId, expiryDate, remainingQty) {
    // البحث عن خلية الحالة في نفس الصف
    var row = document.getElementById('batch-row-' + batchId);
    if (!row) return;
    var cells = row.querySelectorAll('td');
    // خلية الحالة هي قبل الأخيرة (قبل الإجراءات مباشرة)
    var statusCell = cells[cells.length - 2];
    if (!statusCell) return;

    // إزالة اللون من الصف
    row.style.opacity = '';
    row.style.background = '';

    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var statusHtml = '';

    if (remainingQty <= 0) {
        statusHtml = '<span style="background:#f1f5f9; color:#64748b; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">مستهلكة</span>';
        row.style.opacity = '0.55';
    } else if (!expiryDate) {
        statusHtml = '<span style="background:#f8fafc; color:#475569; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">بدون تاريخ</span>';
    } else {
        var expDate = new Date(expiryDate);
        expDate.setHours(0, 0, 0, 0);
        var diffDays = Math.round((expDate - today) / (1000 * 60 * 60 * 24));
        if (diffDays < 0) {
            statusHtml = '<span style="background:#fef2f2; color:#dc2626; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:700;">منتهي الصلاحية</span>';
            row.style.background = '#fef2f2';
        } else if (diffDays <= 30) {
            statusHtml = '<span style="background:#fffbeb; color:#d97706; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">قارب الانتهاء (' + diffDays + ' يوم)</span>';
            row.style.background = '#fffbeb';
        } else {
            statusHtml = '<span style="background:#f0fdf4; color:#059669; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600;">صالح (' + diffDays + ' يوم)</span>';
        }
    }
    statusCell.innerHTML = statusHtml;
}

function deleteBatch(batchId) {
    var row = document.getElementById('batch-row-' + batchId);
    var qtyDisplay = document.getElementById('batch-qty-display-' + batchId);
    var remainingQty = qtyDisplay ? parseFloat(qtyDisplay.textContent.trim()) : 0;

    var msg = 'هل تريد حذف هذه الدفعة؟';
    if (remainingQty > 0) {
        msg += '\n\nتحذير: الكمية المتبقية (' + remainingQty.toFixed(2) + ') سيتم خصمها من مخزون المنتج.';
    }

    if (!confirm(msg)) return;

    var fd = new FormData();
    fd.append('action', 'delete_batch');
    fd.append('batch_id', batchId);
    var csrfToken = getBatchCsrfToken();
    if (csrfToken) {
        fd.append('csrf_token', csrfToken);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BATCH_API_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);

    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    if (row) {
                        row.style.transition = 'all 0.3s';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(20px)';
                        setTimeout(function() {
                            row.remove();
                            updateBatchesTotal();
                            updateProductQuantityField();
                            // إظهار رسالة "لا توجد دفعات" إذا لم يبق شيء
                            var tbody = document.querySelector('#batchesTable tbody');
                            if (tbody && tbody.children.length === 0) {
                                var card = document.getElementById('purchaseBatchesCard');
                                card.querySelector('.card-body').innerHTML =
                                    '<div style="text-align:center; padding:1.5rem; color:var(--gray-400);">' +
                                    '<p>لا توجد دفعات شراء مسجلة لهذا المنتج بعد</p></div>';
                            }
                        }, 300);
                    }
                } else {
                    alert('خطأ: ' + (result.message || 'فشل حذف الدفعة'));
                }
            } catch(e) {
                alert('خطأ في استجابة الخادم');
            }
        }
    };

    xhr.onerror = function() {
        alert('خطأ في الاتصال');
    };

    xhr.send(fd);
}

function syncBatchesQuantity() {
    if (!confirm('هل تريد مزامنة كمية المنتج لتساوي مجموع الكميات المتبقية في الدفعات؟')) return;

    var fd = new FormData();
    fd.append('action', 'sync_product_quantity');
    fd.append('product_id', productId);
    var csrfToken = getBatchCsrfToken();
    if (csrfToken) {
        fd.append('csrf_token', csrfToken);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BATCH_API_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);

    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    updateProductQuantityField();
                    alert('تمت مزامنة كمية المنتج بنجاح');
                } else {
                    alert('خطأ: ' + (result.message || 'فشلت المزامنة'));
                }
            } catch(e) {
                alert('خطأ في استجابة الخادم');
            }
        }
    };

    xhr.onerror = function() { alert('خطأ في الاتصال'); };
    xhr.send(fd);
}

function updateBatchesTotal() {
    var totalEl = document.getElementById('batchesTotalRemaining');
    if (!totalEl) return;
    var rows = document.querySelectorAll('#batchesTable tbody tr');
    var total = 0;
    rows.forEach(function(row) {
        var qtySpan = row.querySelector('span[id^="batch-qty-display-"]');
        if (qtySpan) total += parseFloat(qtySpan.textContent.trim()) || 0;
    });
    totalEl.textContent = total.toFixed(2);
}

function updateProductQuantityField() {
    // تحديث حقل الكمية في النموذج من إجمالي الدفعات
    var qtyInput = document.querySelector('input[name="quantity"]');
    var totalEl = document.getElementById('batchesTotalRemaining');
    if (qtyInput && totalEl) {
        qtyInput.value = totalEl.textContent.trim();
        originalQuantity = parseFloat(totalEl.textContent.trim()) || 0;
    }
}

// السماح بالضغط على Enter للحفظ أثناء التعديل
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        var target = e.target;
        if (target && target.id && target.id.startsWith('batch-qty-input-')) {
            var batchId = target.id.replace('batch-qty-input-', '');
            saveBatchEdit(parseInt(batchId));
            e.preventDefault();
        }
    }
    if (e.key === 'Escape') {
        var target = e.target;
        if (target && target.id && target.id.startsWith('batch-qty-input-')) {
            var batchId = target.id.replace('batch-qty-input-', '');
            cancelBatchEdit(parseInt(batchId));
            e.preventDefault();
        }
    }
});
</script>


<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === نظام صور المنتج (تعديل) — رفع مباشر + مرجع: add-product.php ===
var IMAGE_UPLOAD_URL = '../api/upload-product-image.php';
var IMAGE_ACTION_URL = '../api/product-image-action.php';
var PRODUCT_ID = <?php echo intval($id); ?>;
var BASE_URL = '<?php echo (!empty($base_url) ? rtrim($base_url, '/') : ''); ?>';
var pendingImages = [];
var isUploading = false;

// === مستمع إزالة الخلفية ===
document.getElementById('removeBgCheckbox').addEventListener('change', function() {
    document.getElementById('bgToleranceSelect').style.display = this.checked ? 'inline-block' : 'none';
});

// === السحب والإفلات (Drag & Drop) ===
var imageDropZone = document.getElementById('imageDropZone');
if (imageDropZone) {
    ['dragenter', 'dragover'].forEach(function(ev) {
        imageDropZone.addEventListener(ev, function(e) { e.preventDefault(); e.stopPropagation(); imageDropZone.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(function(ev) {
        imageDropZone.addEventListener(ev, function(e) { e.preventDefault(); e.stopPropagation(); imageDropZone.classList.remove('drag-over'); });
    });
    imageDropZone.addEventListener('drop', function(e) { handleImageSelect(e.dataTransfer.files); });
}

// === اختيار الصور ورفعها مباشرة ===
function handleImageSelect(files) {
    if (!files || files.length === 0) return;

    var removeBg = document.getElementById('removeBgCheckbox').checked;
    var bgTolerance = document.getElementById('bgToleranceSelect').value;
    var progressDiv = document.getElementById('imageUploadProgress');
    var progressBar = document.getElementById('imageProgressBar');
    var statusText = document.getElementById('imageUploadStatus');
    var noMsg = document.getElementById('noImagesMsg');

    progressDiv.style.display = 'block';
    progressBar.style.width = '0%';
    noMsg.style.display = 'none';
    isUploading = true;

    var total = files.length;
    var uploaded = 0;
    var successfullyUploaded = 0;
    var csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    for (var i = 0; i < files.length; i++) {
        (function(file) {
            var fd = new FormData();
            fd.append('image', file);
            fd.append('product_id', PRODUCT_ID);
            if (csrfToken) fd.append('csrf_token', csrfToken);
            if (removeBg) { fd.append('remove_bg', '1'); fd.append('bg_tolerance', bgTolerance); }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', IMAGE_UPLOAD_URL, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);

            xhr.onload = function() {
                uploaded++;
                progressBar.style.width = Math.round(uploaded / total * 100) + '%';
                statusText.textContent = 'جاري رفع الصور... ' + uploaded + ' من ' + total;
                if (xhr.status === 200) {
                    try {
                        var result = JSON.parse(xhr.responseText);
                        if (result.success) {
                            successfullyUploaded++;
                            addImageToGallery(result);
                        } else {
                            console.error('Upload failed:', result);
                        }
                    } catch(e) {
                        console.error('Parse error:', e);
                    }
                }
                if (uploaded === total) {
                    isUploading = false;
                    setTimeout(function() {
                        progressDiv.style.display = 'none';
                        progressBar.style.width = '0%';
                        statusText.textContent = 'جاري الرفع...';
                        var gallery = document.getElementById('imageGallery');
                        if (gallery && gallery.querySelectorAll('.product-image-item').length > 0) {
                            noMsg.style.display = 'none';
                        } else {
                            noMsg.style.display = '';
                        }
                    }, 600);
                }
            };

            xhr.onerror = function() {
                uploaded++;
                progressBar.style.width = Math.round(uploaded / total * 100) + '%';
                statusText.textContent = 'فشل رفع صورة (' + uploaded + '/' + total + ')';
                if (uploaded === total) {
                    isUploading = false;
                    setTimeout(function() {
                        progressDiv.style.display = 'none';
                        progressBar.style.width = '0%';
                    }, 2000);
                }
            };

            xhr.send(fd);
        })(files[i]);
    }
}

// === حذف صورة معلقة (احتياطي) ===
function removePendingImage(idx) {
    pendingImages.splice(idx, 1);
    renderPendingImages();
}

// === عرض الصور المعلقة (احتياطي) ===
function renderPendingImages() {
    var gallery = document.getElementById('pendingImageGallery');
    var noMsg = document.getElementById('noImagesMsg');
    gallery.innerHTML = '';
    if (pendingImages.length === 0) { noMsg.style.display = ''; return; }
    noMsg.style.display = 'none';
    pendingImages.forEach(function(img, idx) {
        var div = document.createElement('div');
        div.style.cssText = 'width:100px; height:100px; border-radius:10px; overflow:hidden; border:2px solid var(--gray-200); background:#fff; display:flex; align-items:center; justify-content:center; position:relative;';
        div.innerHTML = '<img src="' + img.dataUrl + '" style="max-width:100%; max-height:100%; object-fit:contain;">' +
            '<button type="button" onclick="removePendingImage(' + idx + ')" style="position:absolute; top:2px; left:2px; background:rgba(220,38,38,0.8); color:#fff; border:none; border-radius:50%; width:22px; height:22px; cursor:pointer; font-size:0.7rem; display:flex; align-items:center; justify-content:center;">&times;</button>';
        gallery.appendChild(div);
    });
}

// === دالة مساعدة: الحصول على رمز CSRF ===
function getImageCsrfToken() {
    var csrfField = document.querySelector('input[name="csrf_token"]');
    return csrfField ? csrfField.value : '';
}

// === إضافة صورة مرفوعة حديثاً إلى المعرض ===
function addImageToGallery(result) {
    var gallery = document.getElementById('imageGallery');
    var noMsg = document.getElementById('noImagesMsg');
    if (noMsg) noMsg.style.display = 'none';
    var imageId = result.image_id || result.id;
    var thumbWeb = result.thumbnail_path_web ? BASE_URL + '/' + result.thumbnail_path_web : '';
    var mediumWeb = result.medium_path_web ? BASE_URL + '/' + result.medium_path_web : thumbWeb;
    var isPrimary = gallery.querySelectorAll('.product-image-item').length === 0;
    var div = document.createElement('div');
    div.className = 'product-image-item';
    div.setAttribute('data-image-id', imageId);
    div.style.cssText = 'width:120px; position:relative; border-radius:10px; overflow:hidden; border:2px solid ' + (isPrimary ? 'var(--primary)' : 'var(--gray-200)') + '; background:#fff; cursor:pointer;';
    div.onclick = function() { viewImage(mediumWeb); };
    var html = '<div style="width:120px; height:90px; overflow:hidden; display:flex; align-items:center; justify-content:center; background:var(--gray-50);"><img src="' + thumbWeb + '" alt="صورة" style="max-width:100%; max-height:100%; object-fit:contain;"></div>';
    html += '<div style="padding:4px 6px; font-size:0.7rem; display:flex; justify-content:space-between; align-items:center;">';
    if (isPrimary) html += '<span style="background:var(--primary); color:#fff; padding:1px 5px; border-radius:4px; font-weight:600;">رئيسية</span>';
    html += '<div style="display:flex; gap:4px; margin-' + (isPrimary ? '' : 'right:auto; margin-left:auto;') + '">';
    html += '<button type="button" onclick="event.stopPropagation(); setImagePrimary(' + imageId + ')" title="تعيين كرئيسية" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color=\'var(--primary)\'" onmouseout="this.style.color=\'var(--gray-400)\'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>';
    html += '<button type="button" onclick="event.stopPropagation(); deleteImage(' + imageId + ')" title="حذف" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color=\'#dc2626\'" onmouseout="this.style.color=\'var(--gray-400)\'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button>';
    html += '</div></div>';
    div.innerHTML = html;
    gallery.appendChild(div);
}

// === حذف صورة موجودة ===
function deleteImage(imageId) {
    if (!confirm('هل تريد حذف هذه الصورة؟')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('image_id', imageId);
    var csrfToken = getImageCsrfToken();
    if (csrfToken) fd.append('csrf_token', csrfToken);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', IMAGE_ACTION_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    var item = document.querySelector('.product-image-item[data-image-id="' + imageId + '"]');
                    if (item) item.remove();
                    var gallery = document.getElementById('imageGallery');
                    if (gallery && gallery.querySelectorAll('.product-image-item').length === 0) {
                        document.getElementById('noImagesMsg').style.display = '';
                    }
                }
            } catch(e) {}
        }
    };
    xhr.onerror = function() {
        alert('خطأ في الاتصال أثناء حذف الصورة');
    };
    xhr.send(fd);
}

// === تعيين صورة كرئيسية ===
function setImagePrimary(imageId) {
    var fd = new FormData();
    fd.append('action', 'set_primary');
    fd.append('image_id', imageId);
    fd.append('product_id', PRODUCT_ID);
    var csrfToken = getImageCsrfToken();
    if (csrfToken) fd.append('csrf_token', csrfToken);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', IMAGE_ACTION_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    document.querySelectorAll('.product-image-item').forEach(function(el) {
                        el.style.borderColor = 'var(--gray-200)';
                        var badge = el.querySelector('span');
                        if (badge) badge.remove();
                    });
                    var item = document.querySelector('.product-image-item[data-image-id="' + imageId + '"]');
                    if (item) {
                        item.style.borderColor = 'var(--primary)';
                        var actionsDiv = item.querySelector('div[style*="display:flex"]');
                        if (actionsDiv && !actionsDiv.querySelector('span')) {
                            var s = document.createElement('span');
                            s.style.cssText = 'background:var(--primary); color:#fff; padding:1px 5px; border-radius:4px; font-weight:600;';
                            s.textContent = 'رئيسية';
                            actionsDiv.insertBefore(s, actionsDiv.firstChild);
                        }
                    }
                }
            } catch(e) {}
        }
    };
    xhr.onerror = function() {
        alert('خطأ في الاتصال أثناء تعيين الصورة الرئيسية');
    };
    xhr.send(fd);
}

// === عرض الصورة مكبرة (Lightbox) ===
function viewImage(src) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:10000; display:flex; align-items:center; justify-content:center; cursor:pointer; backdrop-filter:blur(4px);';
    overlay.onclick = function() { document.body.removeChild(overlay); document.body.style.overflow = ''; };
    var img = document.createElement('img');
    img.src = src;
    img.style.cssText = 'max-width:90vw; max-height:85vh; object-fit:contain; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,0.5);';
    img.onclick = function(e) { e.stopPropagation(); };
    overlay.appendChild(img);
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', function handler(e) {
        if (e.key === 'Escape') { if (document.body.contains(overlay)) document.body.removeChild(overlay); document.body.style.overflow = ''; document.removeEventListener('keydown', handler); }
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
