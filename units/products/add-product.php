<?php
$page_title = 'إضافة منتوج جديد';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();
require_once '../includes/header.php';

$errors = [];
$success = false;

// تهيئة متغيرات الوحدة المخصصة (لمنع تحذير Undefined variable)
$custom_unit_name = '';
$custom_unit_type = 'other';
$custom_unit_conversion = 1;
$custom_unit_selling_price = 0;

// تهيئة متغيرات وحدة الشراء (لمنع تحذير Undefined variable)
$purchase_unit = '';
$purchase_unit_factor = 1;

// أنواع الوحدات (للاستخدام في الحقل المخصص)
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

// إنشاء كود المنتج التلقائي (مع التأكد من عدم التكرار)
$next_code = 'PRD-001';
try {
    $max_num = fetchOne("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num FROM products WHERE code LIKE 'PRD-%'");
    if ($max_num && $max_num['max_num'] !== null) {
        $next_num = intval($max_num['max_num']) + 1;
        $next_code = 'PRD-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    }
    // التأكد من عدم وجود الكود (حالة نادرة)
    while (fetchOne("SELECT id FROM products WHERE code = ?", [$next_code])) {
        $next_num = intval(substr($next_code, 4)) + 1;
        $next_code = 'PRD-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    }
} catch (Exception $e) {
    // في حالة عدم وجود منتجات بعد
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    // جمع البيانات - كود المنتج يتم إنشاؤه تلقائياً
    $code = trim($_POST['code'] ?? '');
    if (empty($code)) $code = $next_code;
    $name = trim($_POST['name'] ?? '');
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $unit = trim($_POST['unit'] ?? '');
    $purchase_unit = trim($_POST['purchase_unit'] ?? '');
    $purchase_unit_factor = floatval($_POST['purchase_unit_factor'] ?? 1);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $min_quantity = floatval($_POST['min_quantity'] ?? 0);
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;

    // وحدة مخصصة (للوحدات الإضافية)
    $custom_unit_name = trim($_POST['custom_unit_name'] ?? '');
    $custom_unit_type = $_POST['custom_unit_type'] ?? 'other';
    $custom_unit_conversion = floatval($_POST['custom_unit_conversion'] ?? 1);
    $custom_unit_selling_price = floatval($_POST['custom_unit_selling_price'] ?? 0);

    // وحدات القياس الإضافية
    $additional_units = [];
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
                $additional_units[] = [
                    'name' => $au_name,
                    'factor' => $au_factor,
                    'price' => $au_price
                ];
            }
        }
    }

    // وحدات الشراء الإضافية
    $purchase_additional_units = [];
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
                $purchase_additional_units[] = ['name' => $pau_name, 'factor' => $pau_factor];
            }
        }
    }

    // إضافة الوحدة المخصصة كوحدة إضافية
    if (!empty($custom_unit_name) && $custom_unit_conversion > 0) {
        $additional_units[] = [
            'name' => $custom_unit_name,
            'factor' => $custom_unit_conversion,
            'price' => $custom_unit_selling_price
        ];
    }

    // التحقق من البيانات
    if (empty($name)) $errors[] = 'اسم المنتج مطلوب';
    if ($purchase_price <= 0) $errors[] = 'سعر الشراء يجب أن يكون أكبر من صفر';
    if ($selling_price <= 0) $errors[] = 'سعر البيع يجب أن يكون أكبر من صفر';
    if ($purchase_price > 0 && $selling_price > 0 && $selling_price < $purchase_price) $errors[] = 'سعر البيع (' . number_format($selling_price, 2) . ') لا يمكن أن يكون أقل من سعر الشراء (' . number_format($purchase_price, 2) . ')';
    if (empty($unit)) $errors[] = 'وحدة القياس مطلوبة';
    if ($quantity < 0) $errors[] = 'الكمية لا يمكن أن تكون سالبة';
    if (!empty($custom_unit_name) && $custom_unit_conversion <= 0) $errors[] = 'معامل التحويل يجب أن يكون أكبر من صفر';

    // التحقق من عدم تكرار الكود - إذا كان الكود التلقائي مكرراً، نولّد كوداً جديداً
    $existing = fetchOne("SELECT id FROM products WHERE code = ?", [$code]);
    if ($existing) {
        // توليد كود فريد تلقائياً
        if (preg_match('/PRD-(\d+)/', $code, $matches)) {
            $num = intval($matches[1]);
            do {
                $num++;
                $code = 'PRD-' . str_pad($num, 3, '0', STR_PAD_LEFT);
            } while (fetchOne("SELECT id FROM products WHERE code = ?", [$code]));
        } else {
            $errors[] = 'كود المنتج موجود مسبقاً';
        }
    }

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // إدراج المنتج
            $stmt = $pdo->prepare("INSERT INTO products (code, name, purchase_price, selling_price, unit, purchase_unit, purchase_unit_factor, category_id, quantity, min_quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name, $purchase_price, $selling_price, $unit, $purchase_unit ?: null, $purchase_unit_factor, $category_id, $quantity, $min_quantity]);
            $product_id = $pdo->lastInsertId();

            // إدراج وحدات القياس الإضافية
            try {
                $auStmt = $pdo->prepare("INSERT INTO additional_units (product_id, unit_name, conversion_factor, selling_price) VALUES (?, ?, ?, ?)");
                foreach ($additional_units as $au) {
                    $auStmt->execute([$product_id, $au['name'], $au['factor'], $au['price']]);
                }
            } catch (Exception $e) {
                // جدول additional_units قد لا يكون موجوداً بعد
            }

            // إدراج وحدات الشراء الإضافية
            try {
                $pauStmt = $pdo->prepare("INSERT INTO purchase_additional_units (product_id, unit_name, conversion_factor) VALUES (?, ?, ?)");
                foreach ($purchase_additional_units as $pau) {
                    $pauStmt->execute([$product_id, $pau['name'], $pau['factor']]);
                }
            } catch (Exception $e) {
                // جدول purchase_additional_units قد لا يكون موجوداً بعد
            }

            // إنشاء دفعة شراء أولية إذا كانت الكمية أكبر من صفر
            if ($quantity > 0) {
                try {
                    $batchStmt = $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, NULL, ?, ?, ?, CURDATE())");
                    $batchStmt->execute([$product_id, $quantity, $quantity, $purchase_price]);
                } catch (Exception $e) {
                    // جدول الدفعات قد لا يكون موجوداً بعد - لا مشكلة
                }
            }

            $pdo->commit();
            $success = true;

            // مسح البيانات بعد النجاح وحساب الكود التالي
            $name = $unit = '';
            $purchase_price = $selling_price = $quantity = $min_quantity = 0;
            $additional_units = [];
            $custom_unit_name = '';
            $custom_unit_type = 'other';
            $custom_unit_conversion = 1;
            $custom_unit_selling_price = 0;
            $purchase_unit = '';
            $purchase_unit_factor = 1;
            // حساب الكود التلقائي للمنتج القادم
            $new_code = $code;
            if (preg_match('/PRD-(\d+)/', $new_code, $matches)) {
                $next_num = intval($matches[1]) + 1;
                $next_code = 'PRD-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ErrorHandler::logException($e, 'save_product');
            $errors[] = 'حدث خطأ أثناء حفظ المنتج: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ المنتج');
        }
    }
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
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
            إضافة منتوج جديد
        </h1>
        <a href="list-products.php" class="btn btn-outline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            عرض المنتجات
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>يرجى تصحيح الأخطاء التالية:</strong>
            <ul style="margin: 5px 20px 0;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            تم إضافة المنتج بنجاح! الكود: <strong><?php echo htmlspecialchars($new_code ?? ''); ?></strong> <a href="add-product.php" style="font-weight:bold; margin-right:10px;">إضافة منتج آخر</a>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="card">
            <div class="card-header">
                <h2>معلومات المنتج الأساسية</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>كود المنتج <span class="required">*</span></label>
                        <div class="code-mode-toggle">
                            <button type="button" class="code-mode-btn active" id="codeModeAuto" onclick="setCodeMode('auto')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                كود تلقائي
                            </button>
                            <button type="button" class="code-mode-btn" id="codeModeBarcode" onclick="setCodeMode('barcode')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/><path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
                                مسح باركود
                            </button>
                        </div>
                        <!-- وضع الكود التلقائي -->
                        <div id="codeAutoSection" class="code-mode-section">
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($next_code); ?>" readonly style="background:#f1f5f9; color:#64748b; cursor:not-allowed; font-family:monospace; font-weight:700; font-size:1.05rem; letter-spacing:0.5px;">
                            <div class="form-text">يتم إنشاؤه تلقائياً</div>
                        </div>
                        <!-- وضع مسح الباركود -->
                        <div id="codeBarcodeSection" class="code-mode-section" style="display:none;">
                            <div class="barcode-input-group">
                                <input type="text" id="barcodeCodeInput" class="form-control" placeholder="أدخل أو امسح كود الباركود" style="font-family:monospace; font-weight:600;">
                                <button type="button" class="btn-scan-barcode" onclick="openBarcodeScanner()" title="مسح بالكاميرا">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                </button>
                            </div>
                            <div class="form-text">امسح كود الباركود بالكاميرا أو أدخله يدوياً</div>
                        </div>
                        <input type="hidden" name="code" id="productCodeInput" value="<?php echo htmlspecialchars($success ? $next_code : ($code ?? $next_code)); ?>">
                    </div>

                    <div class="form-group">
                        <label>اسم المنتج <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="مثال: أرز بسمتي" value="<?php echo htmlspecialchars($name ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>سعر الشراء الحالي <span class="required">*</span></label>
                        <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" placeholder="0.00" value="<?php echo $purchase_price ?? 0; ?>" required>
                        <div class="form-text">السعر الذي تشتري به المنتج</div>
                    </div>

                    <div class="form-group">
                        <label>سعر البيع الحالي <span class="required">*</span></label>
                        <input type="number" name="selling_price" class="form-control" step="0.01" min="0" placeholder="0.00" value="<?php echo $selling_price ?? 0; ?>" required>
                        <div class="form-text">السعر الذي تبيع به المنتج</div>
                    </div>

                    <div class="form-group">
                        <label>وحدة القياس الأساسية <span class="required">*</span></label>
                        <select name="unit" id="unitSelect" class="form-control" required>
                            <option value="">-- اختر وحدة القياس --</option>
                            <?php foreach ($units as $u):
                                $is_selected = (isset($unit) && $unit == $u['abbreviation']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo htmlspecialchars($u['abbreviation']); ?>" <?php echo $is_selected; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>وحدة القياس الأساسية للشراء</label>
                        <div class="form-text" style="margin-bottom:4px;">اتركه فارغاً لاستخدام وحدة البيع الأساسية</div>
                        <select name="purchase_unit" id="purchaseUnitSelect" class="form-control" onchange="onPurchaseUnitChange()">
                            <option value="">-- وحدة البيع --</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['abbreviation']); ?>" <?php echo (isset($purchase_unit) && $purchase_unit == $u['abbreviation']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="purchaseUnitFactorGroup" style="<?php echo empty($purchase_unit) ? 'display:none;' : ''; ?>">
                        <label>معامل التحويل <span class="form-text" id="purchaseUnitFactorHint" style="font-weight:400; font-size:0.78rem;"></span></label>
                        <input type="number" name="purchase_unit_factor" id="purchaseUnitFactor" class="form-control" step="0.0001" min="0.0001" value="<?php echo floatval($purchase_unit_factor ?? 1); ?>">
                        <div class="form-text">1 وحدة شراء = X وحدة بيع أساسية</div>
                    </div>

                    <?php if (!empty($categories)): ?>
                    <div class="form-group">
                        <label>التصنيف</label>
                        <select name="category_id" class="form-control">
                            <option value="">-- بدون تصنيف --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo (isset($category_id) && $category_id == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>الكمية الحالية <span class="required">*</span></label>
                        <input type="number" name="quantity" class="form-control" step="0.01" min="0" placeholder="0" value="<?php echo $quantity ?? 0; ?>" required>
                    </div>

                    <div class="form-group">
                        <label>الحد الأدنى للمخزون</label>
                        <input type="number" name="min_quantity" class="form-control" step="0.01" min="0" placeholder="0" value="<?php echo $min_quantity ?? 0; ?>">
                        <div class="form-text">سيتم التنبيه عندما تصل الكمية إلى هذا الحد</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- وحدات القياس الإضافية -->
        <div class="card">
            <div class="card-header">
                <h2>وحدات قياس إضافية للبيع</h2>
                <button type="button" class="btn btn-sm btn-outline" onclick="addUnitRow()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                    إضافة وحدة
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 1rem;">أضف وحدات قياس بديلة للمنتج (مثال: كيس يحتوي على 5 كيلوغرامات)</p>

                <!-- وحدة مخصصة (مع معامل تحويل) -->
                <div class="custom-unit-section <?php echo !empty($custom_unit_name) ? 'active' : ''; ?>" id="customUnitSection">
                    <div class="custom-unit-badge" style="cursor:pointer;" onclick="toggleCustomUnitSection()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        وحدة مخصصة (معامل تحويل)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" id="customUnitArrow" style="transition:transform 0.2s; <?php echo !empty($custom_unit_name) ? '' : 'transform:rotate(-90deg);'; ?>"><path d="M6 9l6 6 6-6"/></svg>
                    </div>
                    <div id="customUnitBody" style="<?php echo !empty($custom_unit_name) ? '' : 'display:none;'; ?>">
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
                    <!-- وحدات إضافية ستضاف هنا -->
                </div>

                <div id="no-units-msg" class="text-muted" style="text-align:center; padding: 1rem; color: var(--gray-400);">
                    لم تتم إضافة وحدات قياس إضافية بعد
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
                <div id="purchase-additional-units-container"></div>
                <div id="no-pu-units-msg" class="text-muted" style="text-align:center; padding: 1rem; color: var(--gray-400);">لم تتم إضافة وحدات شراء إضافية بعد</div>
            </div>
        </div>

        <div style="margin-top: 1.5rem; display: flex; gap: 10px;">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                حفظ المنتج
            </button>
            <a href="list-products.php" class="btn btn-outline btn-lg">إلغاء</a>
        </div>
    </form>
</div>

<?php require_once '../includes/barcode-scanner.php'; ?>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === بيانات الوحدات (من PHP) ===
var unitsList = <?php echo json_encode(array_map(function($u) { return ['name' => $u['name'], 'abbr' => $u['abbreviation'], 'type' => isset($u['unit_type']) ? $u['unit_type'] : 'other', 'conv' => isset($u['conversion_to_base']) ? floatval($u['conversion_to_base']) : 1, 'isBase' => isset($u['is_base_unit']) ? intval($u['is_base_unit']) : 0]; }, $units)); ?>;
var baseUnitsByType = <?php echo json_encode($base_units_by_type); ?>;

// === وضع كود المنتج ===
var currentCodeMode = 'auto';
var autoCodeValue = <?php echo json_encode($next_code); ?>;

function setCodeMode(mode) {
    currentCodeMode = mode;
    var autoSection = document.getElementById('codeAutoSection');
    var barcodeSection = document.getElementById('codeBarcodeSection');
    var autoBtn = document.getElementById('codeModeAuto');
    var barcodeBtn = document.getElementById('codeModeBarcode');
    var hiddenInput = document.getElementById('productCodeInput');

    if (mode === 'auto') {
        autoSection.style.display = 'block';
        barcodeSection.style.display = 'none';
        autoBtn.classList.add('active');
        barcodeBtn.classList.remove('active');
        hiddenInput.value = autoCodeValue;
    } else {
        autoSection.style.display = 'none';
        barcodeSection.style.display = 'block';
        autoBtn.classList.remove('active');
        barcodeBtn.classList.add('active');
        hiddenInput.value = document.getElementById('barcodeCodeInput').value;
    }
}

// تحديث القيمة المخفية عند الكتابة يدوياً
document.addEventListener('DOMContentLoaded', function() {
    var barcodeInput = document.getElementById('barcodeCodeInput');
    if (barcodeInput) {
        barcodeInput.addEventListener('input', function() {
            if (currentCodeMode === 'barcode') {
                document.getElementById('productCodeInput').value = this.value;
            }
        });
    }

    // التحقق قبل الإرسال
    var form = document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (currentCodeMode === 'barcode') {
                var barcodeVal = document.getElementById('barcodeCodeInput').value.trim();
                if (!barcodeVal) {
                    e.preventDefault();
                    document.getElementById('barcodeCodeInput').style.borderColor = 'var(--danger)';
                    document.getElementById('barcodeCodeInput').focus();
                    alert('يرجى إدخال كود الباركود أو مسحه بالكاميرا');
                    return;
                }
            }
            // التحقق من الوحدة المخصصة
            var customName = document.getElementById('customUnitName');
            var customSection = document.getElementById('customUnitSection');
            if (customSection && customSection.classList.contains('active') && customName.value.trim()) {
                var customConv = document.getElementById('customUnitConversion');
                if (parseFloat(customConv.value) <= 0) {
                    e.preventDefault();
                    customConv.style.borderColor = 'var(--danger)';
                    customConv.focus();
                    alert('معامل التحويل يجب أن يكون أكبر من صفر');
                    return;
                }
            }
            // التحقق من الوحدات الإضافية
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

    // عند تغيير الوحدة الأساسية، حدّث قوائم الوحدات الإضافية
    var unitSelectMain = document.getElementById('unitSelect');
    if (unitSelectMain) {
        unitSelectMain.addEventListener('change', function() {
            refreshAllAdditionalUnitDropdowns();
        });
    }

    // تهيئة وحدات الشراء الإضافية عند تحميل الصفحة
    initPurchaseUnitSystem();
});

// === رد اتصال ماسح الباركود ===
window.onBarcodeScanned = function(scannedCode) {
    var barcodeInput = document.getElementById('barcodeCodeInput');
    if (barcodeInput) {
        barcodeInput.value = scannedCode;
        document.getElementById('productCodeInput').value = scannedCode;
        barcodeInput.style.borderColor = '#22c55e';
        barcodeInput.style.backgroundColor = '#f0fdf4';
        setTimeout(function() {
            barcodeInput.style.borderColor = '';
            barcodeInput.style.backgroundColor = '';
        }, 2000);
    }
    var nameField = document.querySelector('input[name="name"]');
    if (nameField) nameField.focus();
};

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

// === وحدات القياس الإضافية ===
let unitCounter = 0;

// === نوع الوحدة الأساسية المحددة ===
function getSelectedBaseUnitType() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return '';
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].type;
    }
    return '';
}

function buildUnitOptionsHTML(selectedVal) {
    var baseType = getSelectedBaseUnitType();
    var html = '<option value="">-- اختر الوحدة --</option>';
    for (var i = 0; i < unitsList.length; i++) {
        var u = unitsList[i];
        // تصفية حسب نوع الوحدة الأساسية
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
        // إذا كانت القيمة السابقة لا تزال موجودة في القائمة، حافظ عليها
        if (prevVal && prevVal !== '__custom__') {
            var opts = sel.querySelectorAll('option');
            var found = false;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === prevVal) { found = true; break; }
            }
            if (found) {
                sel.value = prevVal;
                // إعادة حساب معامل التحويل النسبي بعد تغيير الوحدة الأساسية
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
            for (var i = 0; i < unitsList.length; i++) {
                if (unitsList[i].abbr === sel.value) {
                    var baseConv = getBaseUnitConv();
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

if (customUnitType) {
    customUnitType.addEventListener('change', updateCustomConversionHint);
}
if (customUnitConversion) {
    customUnitConversion.addEventListener('input', updateCustomConversionHint);
}

// === نظام وحدات الشراء ===
var purchaseUnitCounter = 0;

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
