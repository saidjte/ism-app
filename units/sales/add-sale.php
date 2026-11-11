<?php
$page_title = 'تسجيل مبيعات';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();
require_once '../includes/header.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $display_quantity = floatval($_POST['display_quantity'] ?? 0);
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    $unit_type = $_POST['unit_type'] ?? 'base';
    $additional_unit_id = intval($_POST['additional_unit_id'] ?? 0);

    if ($product_id <= 0) $errors[] = 'يرجى اختيار المنتج';
    if ($quantity <= 0) $errors[] = 'الكمية يجب أن تكون أكبر من صفر';
    if ($selling_price <= 0) $errors[] = 'سعر البيع يجب أن يكون أكبر من صفر';
    if (empty($sale_date)) $errors[] = 'تاريخ البيع مطلوب';

    $product = fetchOne("SELECT quantity, purchase_price FROM products WHERE id = ?", [$product_id]);

    $actual_quantity = $quantity;

    if (empty($errors) && $product) {
        if ($actual_quantity > $product['quantity']) {
            $errors[] = 'الكمية المطلوبة (' . number_format($actual_quantity, 2) . ') أكبر من المتوفر في المخزون (' . number_format($product['quantity'], 2) . ')';
        }
    }

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $cost_total = 0;
            $weighted_purchase_price = 0;
            $sale_batch_data = [];

            $batchStmt = $pdo->prepare("SELECT id, remaining_quantity, purchase_price FROM purchase_batches WHERE product_id = ? AND remaining_quantity > 0 ORDER BY purchase_date ASC, id ASC");
            $batchStmt->execute([$product_id]);
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

            $total_quantity_from_batches = 0;

            if (!empty($batches)) {
                $remaining_to_sell = $actual_quantity;

                foreach ($batches as $batch) {
                    if ($remaining_to_sell <= 0) break;

                    $take = min($remaining_to_sell, floatval($batch['remaining_quantity']));
                    $batch_cost = $take * floatval($batch['purchase_price']);
                    $cost_total += $batch_cost;
                    $total_quantity_from_batches += $take;

                    $sale_batch_data[] = [
                        'batch_id' => $batch['id'],
                        'quantity_taken' => $take,
                        'purchase_price' => floatval($batch['purchase_price']),
                        'cost_total' => $batch_cost
                    ];

                    $remaining_to_sell -= $take;
                }

                if ($remaining_to_sell > 0) {
                    throw new Exception('الدفعات المتوفرة لا تكفي لتغطية الكمية المطلوبة. الناقص: ' . number_format($remaining_to_sell, 2));
                }

                $weighted_purchase_price = $total_quantity_from_batches > 0 ? $cost_total / $total_quantity_from_batches : 0;
            } else {
                $cost_total = $actual_quantity * $product['purchase_price'];
                $weighted_purchase_price = $product['purchase_price'];
                $total_quantity_from_batches = $actual_quantity;
            }

            // الإجمالي = الكمية بالوحدة المعروضة × سعر الوحدة المعروضة
            // (وليس الكمية بالوحدة الأساسية × السعر)
            if ($display_quantity > 0) {
                $total = $display_quantity * $selling_price;
            } else {
                $total = $actual_quantity * $selling_price;
            }
            $profit = $total - $cost_total;

            $saleStmt = $pdo->prepare("INSERT INTO sales (product_id, quantity, selling_price, purchase_price_at_sale, total, cost_total, profit, sale_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $saleStmt->execute([$product_id, $actual_quantity, $selling_price, $weighted_purchase_price, $total, $cost_total, $profit, $sale_date, $notes]);
            $sale_id = $pdo->lastInsertId();

            if (!empty($sale_batch_data)) {
                $sbiStmt = $pdo->prepare("INSERT INTO sale_batch_items (sale_id, batch_id, quantity_taken, purchase_price, cost_total) VALUES (?, ?, ?, ?, ?)");
                $batchUpdateStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity - ? WHERE id = ?");
                foreach ($sale_batch_data as $sbd) {
                    $sbiStmt->execute([$sale_id, $sbd['batch_id'], $sbd['quantity_taken'], $sbd['purchase_price'], $sbd['cost_total']]);
                    $batchUpdateStmt->execute([$sbd['quantity_taken'], $sbd['batch_id']]);
                }
            }

            $deduct_quantity = !empty($sale_batch_data) ? $total_quantity_from_batches : $actual_quantity;
            $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
            $updateStmt->execute([$deduct_quantity, $product_id]);

            $pdo->commit();
            $success = true;

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            ErrorHandler::logException($e, 'save_sale');
            $errors[] = 'حدث خطأ: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ البيع');
        }
    }
}

// سجل المبيعات الأخيرة
$recent_sales = fetchAll(
    "SELECT s.*, pr.name as product_name, pr.code, pr.unit
     FROM sales s JOIN products pr ON s.product_id = pr.id 
     ORDER BY s.created_at DESC LIMIT 20"
);
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            تسجيل مبيعات
        </h1>
        <a href="calculate-profits.php" class="btn btn-outline">حساب الأرباح</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">تم تسجيل البيع بنجاح وتم تحديث المخزون!</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>تسجيل عملية بيع جديدة</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>ملاحظة:</strong> يتم حساب التكلفة بطريقة FIFO (أول ما دخل أول ما خرج) - كل كمية مباعة تُحسب بسعر الشراء الأصلي للدفعة التي جاءت منها.
            </div>
            <form method="POST" action="" id="saleForm">
                <div class="form-grid">

                    <!-- ===== حقل البحث عن المنتج ===== -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>المنتج <span class="required">*</span></label>
                        <div class="product-search-wrapper" id="productSearchWrapper">
                            <div class="search-input-container">
                                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" 
                                       id="product_search_input" 
                                       class="form-control search-input" 
                                       placeholder="ابحث بالكود أو اسم المنتج..." 
                                       autocomplete="off"
                                       onfocus="openSearchDropdown()" 
                                       oninput="searchProducts()">
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

                            <!-- المنتج المختار -->
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

                            <!-- قائمة نتائج البحث -->
                            <div class="search-dropdown" id="searchDropdown" style="display:none;">
                                <div class="search-results" id="searchResults">
                                    <div class="search-loading" id="searchLoading" style="display:none;">
                                        <div class="spinner"></div>
                                        جاري البحث...
                                    </div>
                                    <div class="search-no-results" id="searchNoResults" style="display:none;">
                                        لا توجد نتائج مطابقة
                                    </div>
                                    <div class="search-hint" id="searchHint">
                                        اكتب للبحث عن منتج (كود أو اسم)
                                    </div>
                                </div>
                            </div>

                            <!-- حقول مخفية -->
                            <input type="hidden" name="product_id" id="product_id_hidden" value="">
                            <input type="hidden" id="product_data_price" value="">
                            <input type="hidden" id="product_data_unit" value="">
                            <input type="hidden" id="product_data_qty" value="">
                            <input type="hidden" id="product_data_purchase" value="">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>وحدة القياس</label>
                        <select name="unit_type" id="unit_type" class="form-control" onchange="onUnitChange()">
                            <option value="base">الوحدة الأساسية</option>
                        </select>
                        <input type="hidden" name="additional_unit_id" id="additional_unit_id" value="">
                    </div>

                    <div class="form-group">
                        <label>طريقة الإدخال</label>
                        <div style="display: flex; gap: 20px; align-items: center;">
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="radio" name="input_mode" value="sold" checked onchange="toggleInputMode()"> الكمية المباعة
                            </label>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="radio" name="input_mode" value="remaining" onchange="toggleInputMode()"> الكمية المتبقية في المخزن
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="sold_group">
                        <label>الكمية المباعة <span class="required">*</span></label>
                        <input type="number" name="quantity_sold_display" id="quantity_sold_display" class="form-control" step="0.01" min="0.01" oninput="syncQuantityFromSold()">
                        <div class="form-text" id="stock_info"></div>
                    </div>

                    <div class="form-group" id="remaining_group" style="display: none;">
                        <label>الكمية المتبقية بعد البيع <span class="required">*</span></label>
                        <input type="number" name="remaining_quantity_display" id="remaining_quantity_display" class="form-control" step="0.01" min="0" oninput="syncQuantityFromRemaining()">
                        <div class="form-text">سيتم حساب الكمية المباعة = (المتوفر الحالي - المتبقي)</div>
                    </div>

                    <input type="hidden" name="quantity" id="quantity_hidden" value="0">
                    <input type="hidden" name="display_quantity" id="display_quantity_hidden" value="0">

                    <div class="form-group">
                        <label>سعر البيع للوحدة <span class="required">*</span></label>
                        <input type="number" name="selling_price" id="selling_price" class="form-control" step="0.01" min="0.01" required oninput="calculateTotal()">
                    </div>

                    <input type="hidden" id="purchase_price_display" value="0">

                    <div class="form-group">
                        <label>إجمالي تكلفة الشراء (FIFO)</label>
                        <input type="text" id="fifo_cost_display" class="form-control" value="0.00" readonly style="background:#fef3c7; color:#92400e; font-weight:700;">
                        <div class="form-text">مجموع تكلفة الكميات من كل دفعة بسعرها الأصلي</div>
                    </div>

                    <div class="form-group">
                        <label>تاريخ البيع <span class="required">*</span></label>
                        <input type="date" name="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>الإجمالي</label>
                        <input type="text" id="total_amount" class="form-control" value="0.00" readonly style="background:#f8fafc; font-weight:700; font-size:1.1rem;">
                    </div>

                    <div class="form-group">
                        <label>الربح المتوقع</label>
                        <input type="text" id="expected_profit" class="form-control" value="0.00" readonly style="background:#f0fdf4; font-weight:700; font-size:1.1rem; color: #059669;">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>ملاحظات</label>
                        <input type="text" name="notes" class="form-control" placeholder="ملاحظات إضافية (اختياري)">
                    </div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-warning btn-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                        تسجيل البيع
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- سجل المبيعات -->
    <div class="card">
        <div class="card-header"><h2>سجل المبيعات الأخيرة</h2></div>
        <div class="card-body">
            <?php if (empty($recent_sales)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;">لا توجد مبيعات مسجلة بعد</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>سعر البيع</th>
                            <th>الإجمالي</th>
                            <th>تكلفة الشراء (FIFO)</th>
                            <th>الربح</th>
                            <th>التاريخ</th>
                            <th>تفاصيل الدفعات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($recent_sales as $s): 
                            $cost = $s['cost_total'] ?? ($s['quantity'] * ($s['purchase_price_at_sale'] ?? 0));
                            $profit = $s['profit'] ?? ($s['total'] - $cost);
                            
                            $sale_batches = [];
                            try {
                                $pdo_tmp = getDBConnection();
                                $sbStmt = $pdo_tmp->prepare("SELECT sbi.*, pb.purchase_date as batch_date FROM sale_batch_items sbi LEFT JOIN purchase_batches pb ON sbi.batch_id = pb.id WHERE sbi.sale_id = ? ORDER BY pb.purchase_date ASC");
                                $sbStmt->execute([$s['id']]);
                                $sale_batches = $sbStmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Exception $e) {}
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($s['code']); ?></td>
                            <td><?php echo htmlspecialchars($s['product_name']); ?></td>
                            <td><?php echo number_format($s['quantity'], 2); ?></td>
                            <td><?php echo formatMoney($s['selling_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($s['total']); ?></td>
                            <td class="text-muted"><?php echo formatMoney($cost); ?></td>
                            <td class="<?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?> text-bold"><?php echo formatMoney($profit); ?></td>
                            <td><?php echo $s['sale_date']; ?></td>
                            <td>
                                <?php if (!empty($sale_batches)): ?>
                                    <button type="button" onclick="toggleBatches(<?php echo $s['id']; ?>)" class="btn btn-sm" style="padding:4px 10px; font-size:0.75rem; background:#dbeafe; color:#1d4ed8; border:none; border-radius:4px; cursor:pointer;">
                                        عرض الدفعات (<?php echo count($sale_batches); ?>)
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($sale_batches)): ?>
                        <tr id="batches-<?php echo $s['id']; ?>" style="display:none; background:#f8fafc;">
                            <td colspan="10" style="padding: 10px 20px;">
                                <div style="font-size:0.85rem;">
                                    <strong>تفاصيل استهلاك الدفعات (FIFO):</strong>
                                    <table style="width:100%; margin-top:6px; border:1px solid #e2e8f0; border-radius:6px;">
                                        <thead>
                                            <tr style="background:#e2e8f0;">
                                                <th style="padding:4px 8px; font-size:0.8rem;">تاريخ الدفعة</th>
                                                <th style="padding:4px 8px; font-size:0.8rem;">الكمية المأخوذة</th>
                                                <th style="padding:4px 8px; font-size:0.8rem;">سعر الشراء</th>
                                                <th style="padding:4px 8px; font-size:0.8rem;">التكلفة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sale_batches as $sb): ?>
                                            <tr>
                                                <td style="padding:4px 8px; font-size:0.8rem;"><?php echo $sb['batch_date'] ?? '-'; ?></td>
                                                <td style="padding:4px 8px; font-size:0.8rem;"><?php echo number_format($sb['quantity_taken'], 2); ?></td>
                                                <td style="padding:4px 8px; font-size:0.8rem;"><?php echo formatMoney($sb['purchase_price']); ?></td>
                                                <td style="padding:4px 8px; font-size:0.8rem; font-weight:600;"><?php echo formatMoney($sb['cost_total']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background:#f1f5f9; font-weight:700;">
                                                <td style="padding:4px 8px; font-size:0.8rem;">الإجمالي</td>
                                                <td style="padding:4px 8px; font-size:0.8rem;"><?php echo number_format(array_sum(array_column($sale_batches, 'quantity_taken')), 2); ?></td>
                                                <td style="padding:4px 8px; font-size:0.8rem;">-</td>
                                                <td style="padding:4px 8px; font-size:0.8rem;"><?php echo formatMoney(array_sum(array_column($sale_batches, 'cost_total'))); ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CSS موجود في style.css المشترك -->

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
const CURRENCY_SYMBOL = '<?php echo getCurrencySymbol(); ?>';
let searchTimeout = null;
let searchResultsData = [];
let highlightedIndex = -1;
let isDropdownOpen = false;

let currentStockBase = 0;
let conversionFactor = 1;
let currentProductId = null;
let currentUnitName = '';

function openSearchDropdown() {
    if (document.getElementById('product_id_hidden').value) return;
    
    const dropdown = document.getElementById('searchDropdown');
    dropdown.style.display = 'flex';
    isDropdownOpen = true;
    
    if (!document.getElementById('product_search_input').value.trim()) {
        searchProducts();
    }
    
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'flex';
}

function closeSearchDropdown() {
    document.getElementById('searchDropdown').style.display = 'none';
    isDropdownOpen = false;
    highlightedIndex = -1;
}

function toggleSearchDropdown() {
    if (isDropdownOpen) closeSearchDropdown();
    else openSearchDropdown();
}

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
    
    dropdown.style.display = 'flex';
    isDropdownOpen = true;
    highlightedIndex = -1;
    
    searchTimeout = setTimeout(() => {
        loading.style.display = 'flex';
        noResults.style.display = 'none';
        hint.style.display = 'none';
        
        resultsDiv.querySelectorAll('.search-product-item, .search-dropdown-footer').forEach(el => el.remove());
        
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                loading.style.display = 'none';
                resultsDiv.querySelectorAll('.search-product-item, .search-dropdown-footer').forEach(el => el.remove());
                
                if (data.products && data.products.length > 0) {
                    searchResultsData = data.products;
                    
                    data.products.forEach((product, index) => {
                        const item = createProductItem(product, query);
                        item.dataset.index = index;
                        item.addEventListener('mouseenter', () => {
                            clearHighlight();
                            item.classList.add('keyboard-nav');
                            highlightedIndex = index;
                        });
                        item.addEventListener('mouseleave', () => {
                            item.classList.remove('keyboard-nav');
                        });
                        resultsDiv.appendChild(item);
                    });
                    
                    if (data.query) {
                        const footer = document.createElement('div');
                        footer.className = 'search-dropdown-footer';
                        footer.textContent = data.total + ' نتيجة';
                        resultsDiv.appendChild(footer);
                    }
                } else {
                    noResults.style.display = 'block';
                    searchResultsData = [];
                }
            })
            .catch(err => {
                loading.style.display = 'none';
                noResults.style.display = 'block';
                noResults.textContent = 'حدث خطأ أثناء البحث';
            });
    }, 300);
}

function clearHighlight() {
    document.querySelectorAll('.search-product-item').forEach(el => el.classList.remove('keyboard-nav'));
}

function createProductItem(product, query) {
    const item = document.createElement('div');
    item.className = 'search-product-item';
    item.onclick = () => selectProduct(product);
    
    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock';
    let stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit;
    if (qty <= 0) {
        stockClass = 'out-of-stock';
        stockText = 'غير متوفر';
    } else if (qty <= parseFloat(product.min_quantity || 0)) {
        stockClass = 'low-stock';
        stockText = 'منخفض: ' + qty.toFixed(2) + ' ' + product.unit;
    }
    
    let nameHtml = escapeHtml(product.name);
    let codeHtml = escapeHtml(product.code);
    if (query) {
        const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi');
        nameHtml = nameHtml.replace(regex, '<mark>$1</mark>');
        codeHtml = codeHtml.replace(regex, '<mark>$1</mark>');
    }
    
    item.innerHTML = `
        <span class="item-code">${codeHtml}</span>
        <div class="item-details">
            <div class="item-name">${nameHtml}</div>
            <div class="item-meta">
                <span>سعر البيع: ${parseFloat(product.selling_price).toFixed(2) + ' ' + CURRENCY_SYMBOL}</span>
                <span>الوحدة: ${escapeHtml(product.unit)}</span>
            </div>
        </div>
        <span class="item-stock ${stockClass}">${stockText}</span>
    `;
    
    return item;
}

function selectProduct(product) {
    document.getElementById('product_id_hidden').value = product.id;
    document.getElementById('product_data_price').value = product.selling_price;
    document.getElementById('product_data_unit').value = product.unit;
    document.getElementById('product_data_qty').value = product.quantity;
    document.getElementById('product_data_purchase').value = product.purchase_price;
    
    document.getElementById('product_search_input').style.display = 'none';
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'none';
    
    const selectedDiv = document.getElementById('selectedProduct');
    selectedDiv.style.display = 'flex';
    document.getElementById('selectedProductCode').textContent = product.code;
    document.getElementById('selectedProductName').textContent = product.name;
    
    const qty = parseFloat(product.quantity);
    const stockSpan = document.getElementById('selectedProductStock');
    if (qty <= 0) {
        stockSpan.style.background = '#fee2e2';
        stockSpan.style.color = '#dc2626';
        stockSpan.textContent = 'غير متوفر';
    } else {
        stockSpan.style.background = '#fef3c7';
        stockSpan.style.color = '#92400e';
        stockSpan.textContent = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit;
    }
    
    closeSearchDropdown();
    
    currentProductId = product.id;
    currentStockBase = parseFloat(product.quantity);
    document.getElementById('selling_price').value = product.selling_price;
    currentUnitName = product.unit;
    
    loadAdditionalUnits(product.id);
    loadBatchInfo(product.id);
    
    document.getElementById('quantity_sold_display').value = '';
    document.getElementById('remaining_quantity_display').value = '';
    document.getElementById('quantity_hidden').value = '0';
    document.getElementById('display_quantity_hidden').value = '0';
    document.querySelector('input[name="input_mode"][value="sold"]').checked = true;
    toggleInputMode();
    updateStockInfoDisplay();
}

function clearProductSelection() {
    document.getElementById('product_id_hidden').value = '';
    document.getElementById('product_data_price').value = '';
    document.getElementById('product_data_unit').value = '';
    document.getElementById('product_data_qty').value = '';
    document.getElementById('product_data_purchase').value = '';
    
    document.getElementById('selectedProduct').style.display = 'none';
    document.getElementById('product_search_input').style.display = 'block';
    document.getElementById('product_search_input').value = '';
    document.getElementById('product_search_input').focus();
    
    currentStockBase = 0;
    currentProductId = null;
    document.getElementById('selling_price').value = '';
    document.getElementById('stock_info').innerHTML = '';
    document.getElementById('purchase_price_display').value = '0';
    resetUnitSelect();
    document.getElementById('quantity_hidden').value = '0';
    document.getElementById('display_quantity_hidden').value = '0';
    document.getElementById('quantity_sold_display').value = '';
    document.getElementById('remaining_quantity_display').value = '';
    calculateTotal();
}

function clearProductSearch() {
    const input = document.getElementById('product_search_input');
    input.value = '';
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'flex';
    input.focus();
    searchProducts();
}

// التنقل بلوحة المفاتيح
document.addEventListener('keydown', function(e) {
    if (!isDropdownOpen) return;
    
    const items = document.querySelectorAll('.search-product-item');
    if (items.length === 0) return;
    
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
        updateHighlight(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightedIndex = Math.max(highlightedIndex - 1, 0);
        updateHighlight(items);
    } else if (e.key === 'Enter' && highlightedIndex >= 0) {
        e.preventDefault();
        items[highlightedIndex].click();
    } else if (e.key === 'Escape') {
        closeSearchDropdown();
        document.getElementById('product_search_input').blur();
    }
});

function updateHighlight(items) {
    items.forEach((item, i) => {
        item.classList.toggle('keyboard-nav', i === highlightedIndex);
    });
    if (items[highlightedIndex]) {
        items[highlightedIndex].scrollIntoView({ block: 'nearest' });
    }
}

document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('productSearchWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        closeSearchDropdown();
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeRegex(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function updateStockInfoDisplay() {
    if (!currentProductId) {
        document.getElementById('stock_info').innerHTML = '';
        return;
    }
    const availableInSelectedUnit = currentStockBase / conversionFactor;
    let unitName = (conversionFactor === 1) ? currentUnitName : 
                   (document.getElementById('unit_type').selectedOptions[0]?.textContent.split(' ')[0] || currentUnitName);
    document.getElementById('stock_info').innerHTML = `<strong>المتوفر حالياً:</strong> ${availableInSelectedUnit.toFixed(2)} ${unitName}`;
}

function toggleInputMode() {
    const mode = document.querySelector('input[name="input_mode"]:checked').value;
    const soldGroup = document.getElementById('sold_group');
    const remainingGroup = document.getElementById('remaining_group');
    const soldInput = document.getElementById('quantity_sold_display');
    const remainingInput = document.getElementById('remaining_quantity_display');

    if (mode === 'sold') {
        soldGroup.style.display = 'block';
        remainingGroup.style.display = 'none';
        soldInput.disabled = false;
        remainingInput.disabled = true;
        remainingInput.value = '';
        if (soldInput.value) syncQuantityFromSold();
        else calculateTotal();
    } else {
        soldGroup.style.display = 'none';
        remainingGroup.style.display = 'block';
        soldInput.disabled = true;
        remainingInput.disabled = false;
        if (remainingInput.value) syncQuantityFromRemaining();
        else calculateTotal();
    }
    updateStockInfoDisplay();
}

function syncQuantityFromSold() {
    const soldDisplay = parseFloat(document.getElementById('quantity_sold_display').value) || 0;
    const actualSold = soldDisplay * conversionFactor;
    document.getElementById('quantity_hidden').value = actualSold.toFixed(2);
    document.getElementById('display_quantity_hidden').value = soldDisplay.toFixed(2);
    if (actualSold > currentStockBase) {
        alert(`الكمية المباعة (${actualSold.toFixed(2)} ${getCurrentUnitName(true)}) أكبر من المتوفر (${currentStockBase.toFixed(2)} ${getCurrentUnitName(true)})`);
        document.getElementById('quantity_hidden').value = currentStockBase;
        document.getElementById('quantity_sold_display').value = (currentStockBase / conversionFactor).toFixed(2);
    }
    calculateTotal();
    updateStockInfoDisplay();
}

function syncQuantityFromRemaining() {
    const remainingDisplay = parseFloat(document.getElementById('remaining_quantity_display').value) || 0;
    const remainingBase = remainingDisplay * conversionFactor;
    let soldBase = currentStockBase - remainingBase;
    
    if (soldBase < 0) {
        const maxRemainingDisplay = (currentStockBase / conversionFactor).toFixed(2);
        alert(`الكمية المتبقية لا يمكن أن تتجاوز المتوفر الحالي (${maxRemainingDisplay} ${getCurrentUnitName()})`);
        document.getElementById('remaining_quantity_display').value = maxRemainingDisplay;
        document.getElementById('quantity_hidden').value = '0';
        document.getElementById('quantity_sold_display').value = '0';
        calculateTotal();
        updateStockInfoDisplay();
        return;
    }
    
    document.getElementById('quantity_hidden').value = soldBase.toFixed(2);
    document.getElementById('display_quantity_hidden').value = (soldBase / conversionFactor).toFixed(2);
    document.getElementById('quantity_sold_display').value = (soldBase / conversionFactor).toFixed(2);
    calculateTotal();
    updateStockInfoDisplay();
}

function getCurrentUnitName(baseUnit = false) {
    if (!currentProductId) return '';
    if (baseUnit) return currentUnitName;
    if (conversionFactor === 1) return currentUnitName;
    const unitSel = document.getElementById('unit_type');
    const selectedOption = unitSel.options[unitSel.selectedIndex];
    return selectedOption?.textContent.split(' ')[0] || currentUnitName;
}

let batchData = { batches: [], weighted_price: 0, total_remaining: 0 };

function loadBatchInfo(productId) {
    fetch('../api/get_batches.php?product_id=' + productId)
        .then(res => res.json())
        .then(data => {
            batchData = data;
            if (data.weighted_price !== undefined) {
                document.getElementById('purchase_price_display').value = parseFloat(data.weighted_price).toFixed(2) + ' ' + CURRENCY_SYMBOL;
            } else {
                const purchasePrice = document.getElementById('product_data_purchase').value;
                if (purchasePrice) {
                    document.getElementById('purchase_price_display').value = parseFloat(purchasePrice).toFixed(2) + ' ' + CURRENCY_SYMBOL;
                }
            }
            calculateTotal();
        })
        .catch(err => {
            batchData = { batches: [], weighted_price: 0, total_remaining: 0 };
            const purchasePrice = document.getElementById('product_data_purchase').value;
            if (purchasePrice) {
                document.getElementById('purchase_price_display').value = parseFloat(purchasePrice).toFixed(2) + ' ' + CURRENCY_SYMBOL;
            }
            calculateTotal();
        });
}

function loadAdditionalUnits(productId) {
    fetch('../api/get_units.php?product_id=' + productId)
        .then(res => res.json())
        .then(data => {
            const unitSel = document.getElementById('unit_type');
            unitSel.innerHTML = '<option value="base">الوحدة الأساسية</option>';
            
            if (data.units && data.units.length > 0) {
                data.units.forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = 'additional';
                    opt.dataset.unitId = u.id;
                    opt.dataset.factor = u.conversion_factor;
                    opt.dataset.price = u.selling_price;
                    const baseName = currentUnitName || 'وحدة أساسية';
                    opt.textContent = u.unit_name + ' (1 ' + u.unit_name + ' = ' + u.conversion_factor + ' ' + baseName + ')';
                    unitSel.appendChild(opt);
                });
            }
            updateConversionFactor();
            if (currentProductId) {
                updateStockInfoDisplay();
                const mode = document.querySelector('input[name="input_mode"]:checked').value;
                if (mode === 'sold') syncQuantityFromSold();
                else syncQuantityFromRemaining();
            }
        })
        .catch(err => {
            console.log('Error loading units:', err);
            resetUnitSelect();
        });
}

function resetUnitSelect() {
    const unitSel = document.getElementById('unit_type');
    unitSel.innerHTML = '<option value="base">الوحدة الأساسية</option>';
    document.getElementById('additional_unit_id').value = '';
    conversionFactor = 1;
}

function updateConversionFactor() {
    const unitSel = document.getElementById('unit_type');
    const opt = unitSel.options[unitSel.selectedIndex];
    if (opt.value === 'additional') {
        conversionFactor = parseFloat(opt.dataset.factor);
    } else {
        conversionFactor = 1;
    }
}

function onUnitChange() {
    const unitSel = document.getElementById('unit_type');
    const opt = unitSel.options[unitSel.selectedIndex];
    
    if (opt.value === 'additional') {
        document.getElementById('additional_unit_id').value = opt.dataset.unitId;
        conversionFactor = parseFloat(opt.dataset.factor);
        if (opt.dataset.price) {
            document.getElementById('selling_price').value = opt.dataset.price;
        }
    } else {
        document.getElementById('additional_unit_id').value = '';
        conversionFactor = 1;
        const price = document.getElementById('product_data_price').value;
        if (currentProductId && price) {
            document.getElementById('selling_price').value = price;
        }
    }
    
    const mode = document.querySelector('input[name="input_mode"]:checked').value;
    if (mode === 'sold') syncQuantityFromSold();
    else syncQuantityFromRemaining();
    updateStockInfoDisplay();
    calculateTotal();
}

function calculateTotal() {
    const actualSold = parseFloat(document.getElementById('quantity_hidden').value) || 0;
    const soldDisplay = parseFloat(document.getElementById('quantity_sold_display').value) || 0;
    const price = parseFloat(document.getElementById('selling_price').value) || 0;
    
    // FIFO التكلفة دائماً بالوحدة الأساسية (لأن الدفعات بالوحدة الأساسية)
    let fifoCostTotal = 0;
    let remainingToSell = actualSold;
    
    if (batchData.batches && batchData.batches.length > 0) {
        for (let i = 0; i < batchData.batches.length && remainingToSell > 0; i++) {
            const batch = batchData.batches[i];
            const batchRemaining = parseFloat(batch.remaining_quantity);
            const batchPrice = parseFloat(batch.purchase_price);
            const take = Math.min(remainingToSell, batchRemaining);
            fifoCostTotal += take * batchPrice;
            remainingToSell -= take;
        }
    } else {
        const purchasePrice = parseFloat(document.getElementById('purchase_price_display').value) || 0;
        fifoCostTotal = actualSold * purchasePrice;
    }
    
    // الإجمالي = الكمية بالوحدة المختارة × سعر الوحدة المختارة
    // وليس الكمية بالوحدة الأساسية × السعر
    const total = soldDisplay * price;
    const profit = total - fifoCostTotal;
    
    document.getElementById('total_amount').value = total.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('fifo_cost_display').value = fifoCostTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('expected_profit').value = profit.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    
    const profitField = document.getElementById('expected_profit');
    if (profit >= 0) {
        profitField.style.color = '#059669';
        profitField.style.background = '#f0fdf4';
    } else {
        profitField.style.color = '#dc2626';
        profitField.style.background = '#fef2f2';
    }
}

function toggleBatches(saleId) {
    const row = document.getElementById('batches-' + saleId);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    toggleInputMode();
});

// عند مسح باركود - يبحث عن المنتج ويختاره تلقائياً
window.onBarcodeScanned = function(code) {
    if (!code) return;
    document.getElementById('product_search_input').value = code;
    document.getElementById('product_search_input').style.display = 'block';
    document.getElementById('searchClearBtn').style.display = 'flex';
    document.getElementById('searchArrowBtn').style.display = 'none';
    fetch('../api/search_products.php?q=' + encodeURIComponent(code))
        .then(res => res.json())
        .then(data => {
            if (data.products && data.products.length > 0) {
                const product = data.products.find(p => p.code === code) || data.products[0];
                selectProduct(product);
            } else {
                const input = document.getElementById('product_search_input');
                input.value = code;
                input.focus();
                searchProducts();
            }
        });
};
</script>

<style>
/* === تحسين الوافقية مع الهواتف لصفحة المبيعات === */
@media (max-width: 768px) {
    /* تحسين عرض FIFO وتفاصيل الدفعات */
    table .table {
        font-size: 0.78rem !important;
    }
    
    /* تحسين عرض معلومات المنتج */
    .selected-product-info {
        flex-wrap: wrap !important;
        gap: 6px !important;
    }
    
    /* تحسين أزرار الدفعات */
    .card-body button[onclick*="toggleBatches"] {
        padding: 4px 8px !important;
        font-size: 0.7rem !important;
    }
}

@media (max-width: 480px) {
    /* تحسين عرض الإشعارات */
    .alert {
        padding: 10px 12px !important;
        font-size: 0.82rem !important;
    }
    .alert svg {
        display: none !important;
    }
    
    /* تحسين عرض جدول الدفعات الداخلية */
    table table {
        font-size: 0.75rem !important;
    }
    table table th,
    table table td {
        padding: 3px 5px !important;
        font-size: 0.72rem !important;
    }
}
</style>

<?php require_once '../includes/barcode-scanner.php'; ?>
<?php require_once '../includes/footer.php'; ?>
