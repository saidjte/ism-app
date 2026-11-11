<?php
$page_title = 'تسجيل مشتريات';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();
require_once '../includes/header.php';

// === إنشاء جدول التخفيضات ===
try {
    $pdo = getDBConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_remises (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purchase_id INT NOT NULL,
        type ENUM('material', 'financial') NOT NULL COMMENT 'material=تخفيض مادي (سلع), financial=تخفيض مالي',
        product_id INT NULL COMMENT 'المنتج المعطى كتخفيض مادي',
        quantity DECIMAL(12,2) NULL COMMENT 'كمية التخفيض المادي',
        unit_price DECIMAL(12,2) NULL COMMENT 'سعر الشراء للمنتج المعطى (لتحديد قيمة المخزون)',
        amount DECIMAL(12,2) NULL COMMENT 'مبلغ التخفيض المالي',
        description VARCHAR(255) NULL COMMENT 'وصف التخفيض',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    // وحدات الشراء
    $purchase_unit_factor = floatval($_POST['purchase_unit_factor'] ?? 1);

    // === بيانات التخفيضات ===
    $remise_types = $_POST['remise_type'] ?? [];
    $remise_product_ids = $_POST['remise_product_id'] ?? [];
    $remise_quantities = $_POST['remise_quantity'] ?? [];
    $remise_amounts = $_POST['remise_amount'] ?? [];
    $remise_descriptions = $_POST['remise_description'] ?? [];

    if ($product_id <= 0) $errors[] = 'يرجى اختيار المنتج';
    if ($quantity <= 0) $errors[] = 'الكمية يجب أن تكون أكبر من صفر';
    if ($purchase_price <= 0) $errors[] = 'سعر الشراء يجب أن يكون أكبر من صفر';
    if (empty($purchase_date)) $errors[] = 'تاريخ الشراء مطلوب';

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $total = $quantity * $purchase_price;

            // تحويل الكمية إلى وحدة البيع الأساسية
            $base_quantity = $quantity;
            if ($purchase_unit_factor > 0 && $purchase_unit_factor != 1) {
                $base_quantity = $quantity * $purchase_unit_factor;
            }

            // حساب إجمالي التخفيضات المالية
            $total_financial_remise = 0;
            foreach ($remise_types as $i => $type) {
                if ($type === 'financial') {
                    $total_financial_remise += floatval($remise_amounts[$i] ?? 0);
                }
            }

            // المبلغ الصافي = الإجمالي - التخفيض المالي
            $net_total = max(0, $total - $total_financial_remise);

            // جلب الأسعار الحالية للمنتج قبل التحديث
            $oldPricesStmt = $pdo->prepare("SELECT purchase_price, selling_price FROM products WHERE id = ?");
            $oldPricesStmt->execute([$product_id]);
            $old_prices = $oldPricesStmt->fetch(PDO::FETCH_ASSOC);

            // إدراج سجل الشراء (الكمية بوحدة البيع الأساسية)
            $stmt = $pdo->prepare("INSERT INTO purchases (product_id, quantity, purchase_price, total, purchase_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $base_quantity, $purchase_price, $net_total, $purchase_date, $notes]);
            $purchase_id = $pdo->lastInsertId();

            // إنشاء دفعة شراء (بكمية وحدة البيع الأساسية)
            try {
                $batchStmt = $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?, ?)");
                $batchStmt->execute([$product_id, $purchase_id, $base_quantity, $base_quantity, $purchase_price, $purchase_date]);
            } catch (Exception $e) {}

            // تحديث كمية المنتج وسعر الشراء (بكمية وحدة البيع الأساسية)
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ?, purchase_price = ? WHERE id = ?");
            $stmt->execute([$base_quantity, $purchase_price, $product_id]);

            // تسجيل تغيير السعر
            try {
                $old_purchase = floatval($old_prices['purchase_price']);
                $old_selling = floatval($old_prices['selling_price']);
                if ($purchase_price != $old_purchase) {
                    $historyStmt = $pdo->prepare("INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason) VALUES (?, ?, ?, ?, ?, ?)");
                    $historyStmt->execute([$product_id, $old_purchase, $purchase_price, $old_selling, $old_selling, 'تسجيل عملية شراء جديدة - سعر الشراء: ' . number_format($purchase_price, 2)]);
                }
            } catch (Exception $e) {}

            // === معالجة التخفيضات ===
            foreach ($remise_types as $i => $type) {
                if ($type === 'material') {
                    $remise_prod_id = intval($remise_product_ids[$i] ?? 0);
                    $remise_qty = floatval($remise_quantities[$i] ?? 0);
                    $remise_desc = trim($remise_descriptions[$i] ?? '');

                    if ($remise_prod_id > 0 && $remise_qty > 0) {
                        // تسجيل التخفيض المادي — سعر الوحدة = 0 (سلعة مجانية)
                        $pdo->prepare("INSERT INTO purchase_remises (purchase_id, type, product_id, quantity, unit_price, description) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$purchase_id, 'material', $remise_prod_id, $remise_qty, 0, $remise_desc]);

                        // إضافة الكمية إلى مخزون المنتج المعطى
                        $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                            ->execute([$remise_qty, $remise_prod_id]);

                        // إنشاء دفعة شراء بسعر 0 درهم للمنتج المعطى (تخفيض مادي = مجاني)
                        try {
                            $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$remise_prod_id, $purchase_id, $remise_qty, $remise_qty, 0, $purchase_date]);
                        } catch (Exception $e) {}
                    }

                } elseif ($type === 'financial') {
                    $remise_amount = floatval($remise_amounts[$i] ?? 0);
                    $remise_desc = trim($remise_descriptions[$i] ?? '');

                    if ($remise_amount > 0) {
                        $pdo->prepare("INSERT INTO purchase_remises (purchase_id, type, amount, description) VALUES (?, ?, ?, ?)")
                            ->execute([$purchase_id, 'financial', $remise_amount, $remise_desc]);
                    }
                }
            }

            $pdo->commit();
            $success = true;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ErrorHandler::logException($e, 'save_purchase');
            $errors[] = 'حدث خطأ: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ المشتريات');
        }
    }
}

// سجل المشتريات الأخيرة مع التخفيضات
$recent_purchases = fetchAll(
    "SELECT p.*, pr.name as product_name, pr.code, pr.unit 
     FROM purchases p JOIN products pr ON p.product_id = pr.id 
     ORDER BY p.created_at DESC LIMIT 20"
);

// جلب التخفيضات لكل عملية شراء
$purchase_remises = [];
if (!empty($recent_purchases)) {
    $pids = array_column($recent_purchases, 'id');
    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    try {
        $remise_rows = fetchAll(
            "SELECT pr.*, p2.name as remise_product_name, p2.code as remise_product_code
             FROM purchase_remises pr 
             LEFT JOIN products p2 ON pr.product_id = p2.id
             WHERE pr.purchase_id IN ($placeholders) 
             ORDER BY pr.id", $pids
        );
        foreach ($remise_rows as $r) {
            $purchase_remises[$r['purchase_id']][] = $r;
        }
    } catch (Exception $e) {
        // جدول purchase_remises قد لا يكون موجوداً بعد
        $purchase_remises = [];
    }
}
?>
<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            تسجيل مشتريات
        </h1>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">تم تسجيل الشراء بنجاح وتم تحديث المخزون!</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>تسجيل عملية شراء جديدة</h2></div>
        <div class="card-body">
            <form method="POST" action="" id="purchaseForm">
                <div class="form-grid">

                    <!-- حقل البحث عن المنتج -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>المنتج <span class="required">*</span></label>
                        <div class="product-search-wrapper" id="productSearchWrapper">
                            <div class="search-input-container">
                                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" id="product_search_input" class="form-control search-input" 
                                       placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                                       onfocus="openSearchDropdown()" oninput="searchProducts()">
                                <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearProductSearch()" style="display:none;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                                <button type="button" class="search-scan-btn" onclick="openBarcodeScanner()" title="مسح الباركود بالكاميرا">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                        <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                        <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                        <line x1="7" y1="12" x2="17" y2="12"/>
                                    </svg>
                                </button>
                                <button type="button" class="search-arrow-btn" id="searchArrowBtn" onclick="toggleSearchDropdown()" style="display:none;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </div>
                            <div class="selected-product" id="selectedProduct" style="display:none;">
                                <div class="selected-product-info">
                                    <span class="selected-product-code" id="selectedProductCode"></span>
                                    <span class="selected-product-name" id="selectedProductName"></span>
                                    <span class="selected-product-stock" id="selectedProductStock"></span>
                                </div>
                                <button type="button" class="selected-product-remove" onclick="clearProductSelection()" title="إزالة المنتج">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <div class="search-dropdown" id="searchDropdown" style="display:none;">
                                <div class="search-results" id="searchResults">
                                    <div class="search-loading" id="searchLoading" style="display:none;"><div class="spinner"></div>جاري البحث...</div>
                                    <div class="search-no-results" id="searchNoResults" style="display:none;">لا توجد نتائج مطابقة</div>
                                    <div class="search-hint" id="searchHint">اكتب للبحث عن منتج (كود أو اسم)</div>
                                </div>
                            </div>
                            <input type="hidden" name="product_id" id="product_id_hidden" value="">
                            <input type="hidden" id="product_data_purchase" value="">
                            <input type="hidden" id="product_data_unit" value="">
                            <input type="hidden" id="purchase_unit_factor_hidden" name="purchase_unit_factor" value="1">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>الكمية <span class="required">*</span></label>
                        <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" required oninput="calculateTotal()">
                        <div class="form-text" id="unit_label"></div>
                    </div>

                    <div class="form-group" id="purchaseUnitDropdownGroup" style="display:none;">
                        <label>وحدة الشراء</label>
                        <select name="purchase_unit_select" id="purchase_unit_select" class="form-control" onchange="onPurchaseUnitSelectChange()">
                            <option value="base">الوحدة الأساسية</option>
                        </select>
                        <div class="form-text" id="purchase_unit_info"></div>
                    </div>

                    <div class="form-group">
                        <label>سعر الشراء للوحدة <span class="required">*</span></label>
                        <input type="number" name="purchase_price" id="purchase_price" class="form-control" step="0.01" min="0.01" required oninput="calculateTotal()">
                    </div>

                    <div class="form-group">
                        <label>تاريخ الشراء <span class="required">*</span></label>
                        <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>الإجمالي</label>
                        <input type="text" id="total_amount" class="form-control" value="0.00" readonly style="background:#f8fafc; font-weight:700; font-size:1.1rem;">
                    </div>

                    <div class="form-group">
                        <label>ملاحظات</label>
                        <input type="text" name="notes" class="form-control" placeholder="ملاحظات إضافية (اختياري)">
                    </div>
                </div>

                <!-- ===== قسم التخفيضات (Remise) ===== -->
                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px dashed #e5e7eb;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3 style="margin: 0; font-size: 1.1rem; color: var(--gray-700);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;">
                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                            </svg>
                            التخفيضات (Remises) — <span style="font-size: 0.8rem; color: var(--gray-400);">اختياري</span>
                        </h3>
                        <button type="button" onclick="addRemiseRow('material')" 
                                style="background: #f0fdf4; color: #059669; border: 1px solid #86efac; padding: 6px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 0.85rem; margin-left: 6px;">
                            + تخفيض مادي (سلع)
                        </button>
                        <button type="button" onclick="addRemiseRow('financial')" 
                                style="background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; padding: 6px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 0.85rem;">
                            + تخفيض مالي
                        </button>
                    </div>

                    <div id="remisesContainer">
                        <!-- صفوف التخفيضات تُضاف هنا ديناميكياً -->
                    </div>

                    <!-- ملخص التخفيضات -->
                    <div id="remiseSummary" style="display: none; margin-top: 0.75rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 0.6rem 0.75rem; text-align: center;">
                                <div style="font-size: 0.75rem; color: var(--gray-500);">🎁 إجمالي التخفيضات المادية</div>
                                <div id="totalMaterialRemise" style="font-weight: 800; color: #059669;">0</div>
                            </div>
                            <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 0.6rem 0.75rem; text-align: center;">
                                <div style="font-size: 0.75rem; color: var(--gray-500);">💰 إجمالي التخفيض المالي</div>
                                <div id="totalFinancialRemise" style="font-weight: 800; color: #d97706;">0.00</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-secondary btn-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                        تسجيل الشراء
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- سجل المشتريات -->
    <div class="card">
        <div class="card-header"><h2>سجل المشتريات الأخيرة</h2></div>
        <div class="card-body">
            <?php if (empty($recent_purchases)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;">لا توجد مشتريات مسجلة بعد</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>التخفيضات</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($recent_purchases as $p): 
                            $p_remises = $purchase_remises[$p['id']] ?? [];
                            $has_material = false;
                            $has_financial = false;
                            $fin_total = 0;
                            $mat_items = [];
                            foreach ($p_remises as $r) {
                                if ($r['type'] === 'material') { $has_material = true; $mat_items[] = $r; }
                                else { $has_financial = true; $fin_total += floatval($r['amount']); }
                            }
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($p['code']); ?></td>
                            <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                            <td><?php echo number_format($p['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($p['unit']); ?></td>
                            <td><?php echo formatMoney($p['purchase_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($p['total']); ?></td>
                            <td style="font-size: 0.8rem;">
                                <?php if (empty($p_remises)): ?>
                                    <span style="color: var(--gray-300);">—</span>
                                <?php else: ?>
                                    <?php foreach ($mat_items as $mi): ?>
                                    <div style="background: #f0fdf4; border-radius: 4px; padding: 2px 6px; margin-bottom: 2px;">
                                        🎁 <strong><?php echo number_format($mi['quantity'], 2); ?></strong> 
                                        <?php echo htmlspecialchars($mi['remise_product_name'] ?? ''); ?>
                                        <?php if ($mi['description']): ?>
                                            <span style="color: var(--gray-400); font-size: 0.7rem;">(<?php echo htmlspecialchars($mi['description']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if ($has_financial): ?>
                                    <div style="background: #fef3c7; border-radius: 4px; padding: 2px 6px;">
                                        💰 <strong>-<?php echo formatMoney($fin_total); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $p['purchase_date']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
const CURRENCY_SYMBOL = '<?php echo getCurrencySymbol(); ?>';
let searchTimeout = null;
let searchResultsData = [];
let highlightedIndex = -1;
let isDropdownOpen = false;
let remiseCounter = 0;
let lastActiveRemiseCounter = null; // لتتبع حقل التخفيض النشط عند المسح

// === إضافة صف تخفيض جديد ===
function addRemiseRow(type) {
    remiseCounter++;
    const container = document.getElementById('remisesContainer');
    const row = document.createElement('div');
    row.id = 'remise_row_' + remiseCounter;
    row.style.cssText = 'background: ' + (type === 'material' ? '#f0fdf4' : '#fef3c7') + '; border: 1px solid ' + (type === 'material' ? '#86efac' : '#fcd34d') + '; border-radius: 10px; padding: 0.75rem; margin-bottom: 0.5rem; position: relative;';

    if (type === 'material') {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 700; font-size: 0.9rem; color: #059669;">🎁 تخفيض مادي — إعطاء سلع مجانية <span style="font-size:0.75rem; color:var(--gray-400);">(سعر الدفعة = 0.00 د.م.)</span></span>
                <button type="button" onclick="removeRemiseRow(${remiseCounter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr auto 1fr 1fr; gap: 0.5rem; align-items: end;">
                <div style="position:relative; min-width:200px;" class="remise-search-wrapper">
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">المنتج المعطى *</label>
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" class="form-control remise-search-input" placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openRemiseSearch(${remiseCounter})" oninput="searchRemiseProduct(${remiseCounter})"
                               style="font-size:0.85rem; padding:8px 32px 8px 65px; width:100%;">
                        <button type="button" class="remise-scan-btn" onclick="lastActiveRemiseCounter=${remiseCounter}; openBarcodeScanner()" title="مسح الباركود بالكاميرا" style="position:absolute; left:28px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; color:var(--primary); border-radius:4px; display:flex; align-items:center; z-index:2; opacity:0.7;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                <line x1="7" y1="12" x2="17" y2="12"/>
                            </svg>
                        </button>
                        <button type="button" class="remise-clear-btn" id="remise_clear_${remiseCounter}" onclick="clearRemiseSelection(${remiseCounter})" style="display:none; position:absolute; left:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#9ca3af; padding:2px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="remise-search-dropdown" id="remise_dropdown_${remiseCounter}" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); max-height:220px; overflow-y:auto; z-index:50; margin-top:2px;">
                        <div class="remise-search-results" id="remise_results_${remiseCounter}"></div>
                    </div>
                    <div class="remise-selected-product" id="remise_selected_${remiseCounter}" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:6px 10px; margin-top:4px; font-size:0.82rem; align-items:center; gap:6px;">
                        <span style="font-family:monospace; background:#dcfce7; padding:1px 6px; border-radius:4px; font-weight:700; color:#166534;" id="remise_sel_code_${remiseCounter}"></span>
                        <span style="font-weight:600; color:#0f172a;" id="remise_sel_name_${remiseCounter}"></span>
                    </div>
                    <input type="hidden" name="remise_product_id[]" id="remise_pid_${remiseCounter}" value="">
                </div>
                <div style="padding-bottom:1px;">
                    <button type="button" onclick="setRemiseSameProduct(${remiseCounter})" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:8px 12px; border-radius:6px; cursor:pointer; font-size:0.78rem; font-weight:600; white-space:nowrap; transition:var(--transition);" title="نفس المنتج المشترى">← نفس المنتج</button>
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">الكمية *</label>
                    <input type="number" name="remise_quantity[]" class="form-control" step="0.01" min="0.01" required placeholder="0" oninput="updateRemiseSummary()" style="font-size:0.9rem; padding:8px;">
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="remise_description[]" class="form-control" placeholder="اختياري" style="font-size:0.85rem; padding:8px;">
                </div>
            </div>
            <input type="hidden" name="remise_type[]" value="material">
        `;
    } else {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 700; font-size: 0.9rem; color: #d97706;">💰 تخفيض مالي — خصم من المبلغ</span>
                <button type="button" onclick="removeRemiseRow(${remiseCounter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">مبلغ الخصم *</label>
                    <input type="number" name="remise_amount[]" class="form-control" step="0.01" min="0.01" required placeholder="0.00" oninput="updateRemiseSummary()" style="font-size:0.9rem; padding:8px; font-weight:800; text-align:center; border-color:#f59e0b;">
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="remise_description[]" class="form-control" placeholder="اختياري" style="font-size:0.85rem; padding:8px;">
                </div>
            </div>
            <input type="hidden" name="remise_type[]" value="financial">
        `;
    }

    container.appendChild(row);
    updateRemiseSummary();
    updateNetTotal();
}

// === بحث المنتجات في التخفيض المادي (مثل البحث الرئيسي) ===
let remiseSearchTimeouts = {};
let remiseHighlightedIndex = {};
let remiseDropdownOpen = {};

function openRemiseSearch(counter) {
    const pidInput = document.getElementById('remise_pid_' + counter);
    if (pidInput && pidInput.value) return;
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; remiseDropdownOpen[counter] = true; }
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input && !input.value.trim()) searchRemiseProduct(counter);
}

function closeRemiseSearch(counter) {
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    if (dropdown) dropdown.style.display = 'none';
    remiseDropdownOpen[counter] = false;
    remiseHighlightedIndex[counter] = -1;
}

function searchRemiseProduct(counter) {
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (!input) return;
    const query = input.value.trim();
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    const resultsDiv = document.getElementById('remise_results_' + counter);
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';
    if (remiseSearchTimeouts[counter]) clearTimeout(remiseSearchTimeouts[counter]);
    if (dropdown) { dropdown.style.display = 'block'; remiseDropdownOpen[counter] = true; }
    remiseHighlightedIndex[counter] = -1;
    remiseSearchTimeouts[counter] = setTimeout(function() {
        if (!resultsDiv) return;
        resultsDiv.innerHTML = '<div style="padding:8px; text-align:center; color:#94a3b8; font-size:0.8rem;"><div class="spinner" style="margin:0 auto 4px; width:14px; height:14px; border-width:2px;"></div>جاري البحث...</div>';
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (!resultsDiv) return;
                resultsDiv.querySelectorAll('.remise-product-item').forEach(el => el.remove());
                if (data.products && data.products.length > 0) {
                    data.products.forEach(function(product, index) {
                        const item = createRemiseProductItem(product, query, counter);
                        item.dataset.index = index;
                        item.dataset.counter = counter;
                        resultsDiv.appendChild(item);
                    });
                } else {
                    resultsDiv.innerHTML = '<div style="padding:10px; text-align:center; color:#94a3b8; font-size:0.8rem;">لا توجد نتائج مطابقة</div>';
                }
            }).catch(function() {
                if (resultsDiv) resultsDiv.innerHTML = '<div style="padding:10px; text-align:center; color:#ef4444; font-size:0.8rem;">خطأ في البحث</div>';
            });
    }, 300);
}

function createRemiseProductItem(product, query, counter) {
    const item = document.createElement('div');
    item.className = 'search-product-item';
    item.style.cssText = 'padding:8px 12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; transition:background 0.15s; font-size:0.85rem;';
    item.onclick = function() { selectRemiseProduct(counter, product); };
    item.addEventListener('mouseenter', function() {
        document.querySelectorAll('.remise-product-item[data-counter="' + counter + '"]').forEach(function(el) { el.style.background = ''; });
        item.style.background = '#f0fdf4';
        remiseHighlightedIndex[counter] = parseInt(item.dataset.index);
    });
    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock', stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    let nameHtml = escapeHtml(product.name), codeHtml = escapeHtml(product.code);
    if (query) { const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi'); nameHtml = nameHtml.replace(regex, '<mark>$1</mark>'); codeHtml = codeHtml.replace(regex, '<mark>$1</mark>'); }
    item.innerHTML = '<div style="flex:1;"><span style="font-family:monospace; font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:1px 6px; border-radius:4px; margin-left:6px;">' + codeHtml + '</span><span style="font-weight:600; color:#0f172a;">' + nameHtml + '</span></div>' +
        '<div style="text-align:left; font-size:0.72rem; color:#64748b;"><div>سعر البيع: <strong>' + parseFloat(product.selling_price).toFixed(2) + ' ' + CURRENCY_SYMBOL + '</strong></div><div class="' + stockClass + '" style="font-weight:600; margin-top:1px;">' + stockText + '</div></div>';
    return item;
}

function selectRemiseProduct(counter, product) {
    document.getElementById('remise_pid_' + counter).value = product.id;
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'block';
    const selectedDiv = document.getElementById('remise_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'flex';
    document.getElementById('remise_sel_code_' + counter).textContent = product.code;
    document.getElementById('remise_sel_name_' + counter).textContent = product.name;
    closeRemiseSearch(counter);
}

function clearRemiseSelection(counter) {
    document.getElementById('remise_pid_' + counter).value = '';
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input) { input.style.display = 'block'; input.value = ''; input.focus(); }
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'none';
    const selectedDiv = document.getElementById('remise_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'none';
    searchRemiseProduct(counter);
}

function setRemiseSameProduct(counter) {
    const mainProductId = document.getElementById('product_id_hidden').value;
    const mainProductName = document.getElementById('selectedProductName').textContent;
    const mainProductCode = document.getElementById('selectedProductCode').textContent;
    if (!mainProductId) {
        alert('يرجى اختيار المنتج الرئيسي أولاً');
        return;
    }
    document.getElementById('remise_pid_' + counter).value = mainProductId;
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'block';
    const selectedDiv = document.getElementById('remise_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'flex';
    document.getElementById('remise_sel_code_' + counter).textContent = mainProductCode;
    document.getElementById('remise_sel_name_' + counter).textContent = mainProductName + ' (← نفس المنتج)';
    closeRemiseSearch(counter);
}

// إغلاق قوائم التخفيض عند النقر خارجها
document.addEventListener('click', function(e) {
    document.querySelectorAll('.remise-search-wrapper').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const counter = wrapper.closest('div[id^="remise_row_"]');
            if (counter) {
                const id = counter.id.replace('remise_row_', '');
                closeRemiseSearch(id);
            }
        }
    });
});

// دعم لوحة المفاتيح لقوائم التخفيض
document.addEventListener('keydown', function(e) {
    for (var counter in remiseDropdownOpen) {
        if (!remiseDropdownOpen[counter]) continue;
        const items = document.querySelectorAll('.remise-product-item[data-counter="' + counter + '"]');
        if (items.length === 0) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); remiseHighlightedIndex[counter] = Math.min((remiseHighlightedIndex[counter] || -1) + 1, items.length - 1); items.forEach(function(item, i) { item.style.background = i === remiseHighlightedIndex[counter] ? '#f0fdf4' : ''; }); if (items[remiseHighlightedIndex[counter]]) items[remiseHighlightedIndex[counter]].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); remiseHighlightedIndex[counter] = Math.max((remiseHighlightedIndex[counter] || 0) - 1, 0); items.forEach(function(item, i) { item.style.background = i === remiseHighlightedIndex[counter] ? '#f0fdf4' : ''; }); if (items[remiseHighlightedIndex[counter]]) items[remiseHighlightedIndex[counter]].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'Enter' && remiseHighlightedIndex[counter] >= 0) { e.preventDefault(); items[remiseHighlightedIndex[counter]].click(); }
        else if (e.key === 'Escape') { closeRemiseSearch(counter); }
        break;
    }
});

function removeRemiseRow(id) {
    const row = document.getElementById('remise_row_' + id);
    if (row) row.remove();
    updateRemiseSummary();
    updateNetTotal();
}

function updateRemiseSummary() {
    const container = document.getElementById('remisesContainer');
    const summary = document.getElementById('remiseSummary');
    const rows = container.querySelectorAll('div[id^="remise_row_"]');

    if (rows.length === 0) {
        summary.style.display = 'none';
        updateNetTotal();
        return;
    }
    summary.style.display = 'block';

    let matCount = 0;
    let finTotal = 0;

    rows.forEach(row => {
        const type = row.querySelector('input[name="remise_type[]"]').value;
        if (type === 'material') {
            const qty = parseFloat(row.querySelector('input[name="remise_quantity[]"]').value) || 0;
            if (qty > 0) matCount++;
        } else {
            const amount = parseFloat(row.querySelector('input[name="remise_amount[]"]').value) || 0;
            finTotal += amount;
        }
    });

    document.getElementById('totalMaterialRemise').textContent = matCount > 0 ? matCount + ' منتج(ات)' : '0';
    document.getElementById('totalFinancialRemise').textContent = finTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;

    updateNetTotal();
}

function updateNetTotal() {
    const qty = parseFloat(document.getElementById('quantity').value) || 0;
    const price = parseFloat(document.getElementById('purchase_price').value) || 0;
    const gross = qty * price;

    let finTotal = 0;
    const container = document.getElementById('remisesContainer');
    container.querySelectorAll('input[name="remise_type[]"]').forEach((input, idx) => {
        const typeInputs = container.querySelectorAll('input[name="remise_type[]"]');
        typeInputs.forEach((t, i) => {
            if (t.value === 'financial') {
                const amountInput = t.closest('div[id^="remise_row_"]').querySelector('input[name="remise_amount[]"]');
                if (amountInput) finTotal += parseFloat(amountInput.value) || 0;
            }
        });
    });

    // Remove duplicates by using Set approach
    const allTypeInputs = container.querySelectorAll('input[name="remise_type[]"]');
    let totalFin = 0;
    allTypeInputs.forEach(t => {
        if (t.value === 'financial') {
            const row = t.closest('div[id^="remise_row_"]');
            if (row) {
                const amountInput = row.querySelector('input[name="remise_amount[]"]');
                if (amountInput) totalFin += parseFloat(amountInput.value) || 0;
            }
        }
    });

    const net = Math.max(0, gross - totalFin);
    const netEl = document.getElementById('total_amount');

    if (totalFin > 0) {
        netEl.style.color = '#d97706';
        netEl.value = gross.toFixed(2) + ' - ' + totalFin.toFixed(2) + ' = ' + net.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    } else {
        netEl.style.color = '';
        netEl.value = gross.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    }
}

// === البحث عن المنتج (نفس الكود السابق) ===
function openSearchDropdown() {
    if (document.getElementById('product_id_hidden').value) return;
    document.getElementById('searchDropdown').style.display = 'flex';
    isDropdownOpen = true;
    if (!document.getElementById('product_search_input').value.trim()) searchProducts();
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'flex';
}
function closeSearchDropdown() { document.getElementById('searchDropdown').style.display = 'none'; isDropdownOpen = false; highlightedIndex = -1; }
function toggleSearchDropdown() { if (isDropdownOpen) closeSearchDropdown(); else openSearchDropdown(); }

function searchProducts() {
    const input = document.getElementById('product_search_input');
    const query = input.value.trim();
    const dropdown = document.getElementById('searchDropdown');
    const resultsDiv = document.getElementById('searchResults');
    const loading = document.getElementById('searchLoading');
    const noResults = document.getElementById('searchNoResults');
    const hint = document.getElementById('searchHint');
    document.getElementById('searchClearBtn').style.display = query ? 'flex' : 'none';
    document.getElementById('searchArrowBtn').style.display = 'none';
    if (searchTimeout) clearTimeout(searchTimeout);
    dropdown.style.display = 'flex'; isDropdownOpen = true; highlightedIndex = -1;
    searchTimeout = setTimeout(() => {
        loading.style.display = 'flex'; noResults.style.display = 'none'; hint.style.display = 'none';
        resultsDiv.querySelectorAll('.search-product-item, .search-dropdown-footer').forEach(el => el.remove());
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                loading.style.display = 'none';
                resultsDiv.querySelectorAll('.search-product-item, .search-dropdown-footer').forEach(el => el.remove());
                if (data.products && data.products.length > 0) {
                    searchResultsData = data.products;
                    data.products.forEach((product, index) => {
                        const item = createProductItem(product, query); item.dataset.index = index;
                        item.addEventListener('mouseenter', () => { clearHighlight(); item.classList.add('keyboard-nav'); highlightedIndex = index; });
                        item.addEventListener('mouseleave', () => item.classList.remove('keyboard-nav'));
                        resultsDiv.appendChild(item);
                    });
                } else { noResults.style.display = 'block'; searchResultsData = []; }
            }).catch(() => { loading.style.display = 'none'; noResults.style.display = 'block'; });
    }, 300);
}
function clearHighlight() { document.querySelectorAll('.search-product-item').forEach(el => el.classList.remove('keyboard-nav')); }

function createProductItem(product, query) {
    const item = document.createElement('div'); item.className = 'search-product-item'; item.onclick = () => selectProduct(product);
    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock', stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit;
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    else if (qty <= parseFloat(product.min_quantity || 0)) { stockClass = 'low-stock'; stockText = 'منخفض: ' + qty.toFixed(2) + ' ' + product.unit; }
    let nameHtml = escapeHtml(product.name), codeHtml = escapeHtml(product.code);
    if (query) { const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi'); nameHtml = nameHtml.replace(regex, '<mark>$1</mark>'); codeHtml = codeHtml.replace(regex, '<mark>$1</mark>'); }
    item.innerHTML = `<span class="item-code">${codeHtml}</span><div class="item-details"><div class="item-name">${nameHtml}</div><div class="item-meta"><span>سعر الشراء: ${parseFloat(product.purchase_price).toFixed(2) + ' ' + CURRENCY_SYMBOL}</span><span>الوحدة: ${escapeHtml(product.unit)}</span></div></div><span class="item-stock ${stockClass}">${stockText}</span>`;
    return item;
}

function selectProduct(product) {
    document.getElementById('product_id_hidden').value = product.id;
    document.getElementById('product_data_purchase').value = product.purchase_price;
    document.getElementById('product_data_unit').value = product.unit;
    document.getElementById('product_search_input').style.display = 'none';
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'none';
    const selectedDiv = document.getElementById('selectedProduct'); selectedDiv.style.display = 'flex';
    document.getElementById('selectedProductCode').textContent = product.code;
    document.getElementById('selectedProductName').textContent = product.name;
    const qty = parseFloat(product.quantity);
    const stockSpan = document.getElementById('selectedProductStock');
    if (qty <= 0) { stockSpan.style.background = '#fee2e2'; stockSpan.style.color = '#dc2626'; stockSpan.textContent = 'غير متوفر'; }
    else { stockSpan.style.background = '#fef3c7'; stockSpan.style.color = '#92400e'; stockSpan.textContent = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit; }
    closeSearchDropdown();
    document.getElementById('purchase_price').value = product.purchase_price;
    document.getElementById('unit_label').textContent = 'الوحدة: ' + product.unit;
    calculateTotal();
    // تحميل وحدات الشراء المتاحة
    loadPurchaseUnits(product.id);
}

function clearProductSelection() {
    document.getElementById('product_id_hidden').value = '';
    document.getElementById('product_data_purchase').value = '';
    document.getElementById('product_data_unit').value = '';
    document.getElementById('purchase_unit_factor_hidden').value = '1';
    document.getElementById('purchaseUnitDropdownGroup').style.display = 'none';
    document.getElementById('purchase_unit_select').innerHTML = '<option value="base">الوحدة الأساسية</option>';
    document.getElementById('selectedProduct').style.display = 'none';
    document.getElementById('product_search_input').style.display = 'block';
    document.getElementById('product_search_input').value = '';
    document.getElementById('product_search_input').focus();
    document.getElementById('purchase_price').value = '';
    document.getElementById('unit_label').textContent = '';
    document.getElementById('total_amount').value = '0.00';
}

function clearProductSearch() {
    const input = document.getElementById('product_search_input'); input.value = '';
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'flex'; input.focus(); searchProducts();
}

document.addEventListener('keydown', function(e) {
    if (!isDropdownOpen) return;
    const items = document.querySelectorAll('.search-product-item');
    if (items.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1); updateHighlight(items); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlightedIndex = Math.max(highlightedIndex - 1, 0); updateHighlight(items); }
    else if (e.key === 'Enter' && highlightedIndex >= 0) { e.preventDefault(); items[highlightedIndex].click(); }
    else if (e.key === 'Escape') { closeSearchDropdown(); document.getElementById('product_search_input').blur(); }
});
function updateHighlight(items) { items.forEach((item, i) => item.classList.toggle('keyboard-nav', i === highlightedIndex)); if (items[highlightedIndex]) items[highlightedIndex].scrollIntoView({ block: 'nearest' }); }
document.addEventListener('click', function(e) { const wrapper = document.getElementById('productSearchWrapper'); if (wrapper && !wrapper.contains(e.target)) closeSearchDropdown(); });
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function escapeRegex(string) { return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function calculateTotal() { updateNetTotal(); }

// === نظام وحدات الشراء ===
var currentPurchaseUnitData = null;

function loadPurchaseUnits(productId) {
    var dropdownGroup = document.getElementById('purchaseUnitDropdownGroup');
    var dropdownSel = document.getElementById('purchase_unit_select');
    if (!dropdownGroup || !dropdownSel) return;
    
    fetch('../api/get_purchase_units.php?product_id=' + productId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            currentPurchaseUnitData = data;
            // إعادة بناء القائمة المنسدلة
            dropdownSel.innerHTML = '';
            
            if (!data.purchase_unit || data.purchase_unit === data.selling_unit) {
                // لا توجد وحدة شراء منفصلة
                dropdownGroup.style.display = 'none';
                document.getElementById('purchase_unit_factor_hidden').value = '1';
                document.getElementById('purchase_unit_info').textContent = '';
                return;
            }
            
            dropdownGroup.style.display = '';
            
            // خيار وحدة الشراء الأساسية
            var optBase = document.createElement('option');
            optBase.value = 'base';
            optBase.dataset.factor = data.purchase_unit_factor;
            optBase.textContent = data.purchase_unit + ' (1 ' + data.purchase_unit + ' = ' + data.purchase_unit_factor + ' ' + data.selling_unit + ')';
            dropdownSel.appendChild(optBase);
            
            // خيارات وحدات الشراء الإضافية
            if (data.units && data.units.length > 0) {
                data.units.forEach(function(u) {
                    var opt = document.createElement('option');
                    var totalFactor = parseFloat(u.conversion_factor) * data.purchase_unit_factor;
                    opt.value = 'additional_' + u.id;
                    opt.dataset.factor = totalFactor;
                    opt.textContent = u.unit_name + ' (1 ' + u.unit_name + ' = ' + totalFactor + ' ' + data.selling_unit + ')';
                    dropdownSel.appendChild(opt);
                });
            }
            
            onPurchaseUnitSelectChange();
        })
        .catch(function() {
            dropdownGroup.style.display = 'none';
            document.getElementById('purchase_unit_factor_hidden').value = '1';
        });
}

function onPurchaseUnitSelectChange() {
    var dropdownSel = document.getElementById('purchase_unit_select');
    var factorHidden = document.getElementById('purchase_unit_factor_hidden');
    var infoDiv = document.getElementById('purchase_unit_info');
    if (!dropdownSel) return;
    
    var selectedOpt = dropdownSel.options[dropdownSel.selectedIndex];
    var factor = parseFloat(selectedOpt.dataset.factor) || 1;
    factorHidden.value = factor;
    
    if (infoDiv) {
        infoDiv.textContent = 'المعامل: 1 وحدة محددة = ' + factor + ' وحدة بيع أساسية';
    }
}

window.onBarcodeScanned = function(code) {
    if (!code) return;
    // إذا كان هناك حقل تخفيض نشط، وجّه المسح إليه
    if (typeof lastActiveRemiseCounter !== 'undefined' && lastActiveRemiseCounter !== null) {
        var counter = lastActiveRemiseCounter;
        lastActiveRemiseCounter = null;
        fetch('../api/search_products.php?q=' + encodeURIComponent(code))
            .then(res => res.json()).then(data => {
                if (data.products && data.products.length > 0) {
                    var exactMatch = data.products.find(function(p) { return p.code === code; });
                    selectRemiseProduct(counter, exactMatch || data.products[0]);
                } else {
                    var input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
                    if (input) { input.value = code; searchRemiseProduct(counter); }
                }
            });
        return;
    }
    // السلوك الافتراضي — البحث الرئيسي عن المنتج
    document.getElementById('product_search_input').value = code;
    document.getElementById('product_search_input').style.display = 'block';
    document.getElementById('searchClearBtn').style.display = 'flex';
    document.getElementById('searchArrowBtn').style.display = 'none';
    fetch('../api/search_products.php?q=' + encodeURIComponent(code))
        .then(res => res.json()).then(data => {
            if (data.products && data.products.length > 0) { selectProduct(data.products.find(p => p.code === code) || data.products[0]); }
            else { document.getElementById('product_search_input').value = code; document.getElementById('product_search_input').focus(); searchProducts(); }
        });
};
</script>

<?php require_once '../includes/barcode-scanner.php'; ?>

<style>
/* === تحسين الوافقية مع الهواتف لصفحة المشتريات === */
@media (max-width: 768px) {
    /* قسم التخفيضات */
    div[style*="grid-template-columns: 1fr auto 1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
    
    /* أزرار التخفيض */
    div[style*="justify-content: space-between"] button[onclick*="addRemiseRow"] {
        padding: 6px 10px !important;
        font-size: 0.78rem !important;
        white-space: nowrap !important;
    }
    
    /* أزرار التخفيض في صف */
    .remise-search-wrapper {
        min-width: 0 !important;
    }
    
    /* حجم الخط للنماذج في الهاتف */
    .remise-search-wrapper input,
    .remise-search-wrapper select {
        font-size: 16px !important;
    }
}

@media (max-width: 480px) {
    /* ملخص التخفيضات */
    div#remiseSummary [style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
    
    /* أزرار التخفيض */
    div[style*="justify-content: space-between"] {
        flex-wrap: wrap !important;
        gap: 6px !important;
    }
    
    div[style*="justify-content: space-between"] button[onclick*="addRemiseRow"] {
        flex: 1 !important;
        min-width: 0 !important;
        text-align: center !important;
        justify-content: center !important;
    }
}
/* تأثير hover لزر مسح الباركود في التخفيض */
.remise-scan-btn:hover { opacity: 1 !important; background: var(--primary-light, #eff6ff) !important; }
</style>

<?php require_once '../includes/footer.php'; ?>
