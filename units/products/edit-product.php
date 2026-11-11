<?php
$page_title = 'تعديل المنتج';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();

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
}
$purchase_additional_units = [];
try {
    $purchase_additional_units = fetchAll("SELECT * FROM purchase_additional_units WHERE product_id = ?", [$id]);
} catch (Exception $e) {
    // جدول purchase_additional_units قد لا يكون موجوداً بعد
}

// جلب التصنيفات النشطة
$categories = [];
try {
    $categories = fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
} catch (Exception $e) {
    // جدول التصنيفات قد لا يكون موجوداً بعد
}

// جلب وحدات القياس النشطة
$units = [];
try {
    $units = fetchAll("SELECT * FROM measurement_units WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
} catch (Exception $e) {
    // جدول الوحدات قد لا يكون موجوداً بعد
}

// جلب سجل تغيير الأسعار
$price_history = [];
try {
    $price_history = fetchAll("SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT 20", [$id]);
} catch (Exception $e) {
    // جدول price_history قد لا يكون موجوداً بعد
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

    if (empty($code)) $errors[] = 'كود المنتج مطلوب';
    if (empty($name)) $errors[] = 'اسم المنتج مطلوب';
    if ($purchase_price <= 0) $errors[] = 'سعر الشراء يجب أن يكون أكبر من صفر';
    if ($selling_price <= 0) $errors[] = 'سعر البيع يجب أن يكون أكبر من صفر';
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
                }
            }

            $updateStmt = $pdo->prepare("UPDATE products SET code=?, name=?, purchase_price=?, selling_price=?, unit=?, purchase_unit=?, purchase_unit_factor=?, category_id=?, quantity=?, min_quantity=? WHERE id=?");
            $updateStmt->execute([$code, $name, $purchase_price, $selling_price, $unit, $purchase_unit ?: null, $purchase_unit_factor, $category_id, $quantity, $min_quantity, $id]);

            // حذف الوحدات القديمة وإضافة الجديدة
            try {
                $pdo->prepare("DELETE FROM additional_units WHERE product_id = ?")->execute([$id]);
                $auStmt = $pdo->prepare("INSERT INTO additional_units (product_id, unit_name, conversion_factor, selling_price) VALUES (?, ?, ?, ?)");
                foreach ($new_units as $au) {
                    $auStmt->execute([$id, $au['name'], $au['factor'], $au['price']]);
                }
            } catch (Exception $e) {
                // جدول additional_units قد لا يكون موجوداً بعد
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
            }

            $pdo->commit();
            $success = true;

            if ($price_changed) {
                $success_msg = 'تم تحديث المنتج بنجاح! تم تسجيل تغيير الأسعار في السجل.';
            } else {
                $success_msg = 'تم تحديث المنتج بنجاح!';
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
            } catch (Exception $e) {}

            $purchase_additional_units = [];
            try {
                $pauRes = $pdo->prepare("SELECT * FROM purchase_additional_units WHERE product_id = ?");
                $pauRes->execute([$id]);
                $purchase_additional_units = $pauRes->fetchAll();
            } catch (Exception $e) {}

            $price_history = [];
            try {
                $phRes = $pdo->prepare("SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT 20");
                $phRes->execute([$id]);
                $price_history = $phRes->fetchAll();
            } catch (Exception $e) {}

        } catch (Exception $e) {
            $pdo->rollBack();
            ErrorHandler::logException($e, 'update_product');
            $errors[] = 'حدث خطأ أثناء التحديث: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء التحديث');
        }
    }
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
</style>

<div class="container">
    <div class="page-header">
        <h1>تعديل المنتج: <?php echo htmlspecialchars($product['name']); ?></h1>
        <a href="list-products.php" class="btn btn-outline">العودة للقائمة</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
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

        <div style="margin-top:1.5rem;" class="btn-group">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn btn-primary btn-lg">حفظ التعديلات</button>
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
</script>

<?php require_once '../includes/footer.php'; ?>
