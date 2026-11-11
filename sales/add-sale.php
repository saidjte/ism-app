<?php
require_once '../includes/bootstrap.php';
$page_title = t('sales_register_new_sale');
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('sales.add');
require_once '../includes/header.php';

// Handle redirect messages
$delete_msg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'sale_deleted') {
    $delete_msg = t('sale_deleted_stock_restored');
}
$edit_msg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'sale_edited') {
    $edit_msg = t('sale_updated_fifo');
}

$errors = [];
$success = false;
$success_summary = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $selling_prices = $_POST['selling_prices'] ?? [];
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    // Validate at least one product
    if (empty($product_ids) || count($product_ids) == 0) {
        $errors[] = 'يرجى إضافة منتج واحد على الأقل';
    }

    if (empty($sale_date)) {
        $errors[] = t('sales_date_required');
    }

    // Track processed product IDs for duplicate detection
    $seen_product_ids = [];

    $expiry_block_sale = false;
    try {
        $expiry_block_sale = (getSetting('expiry_block_sale', '0') === '1');
    } catch (Exception $e) {}

    // فحص هل جداول الدفعات موجودة
    $batches_table_exists = false;
    $sale_batch_items_exists = false;
    try {
        $pdo_check = AppDatabase::getInstance()->getConnection();
        $check_batches = $pdo_check->query("SHOW TABLES LIKE 'purchase_batches'");
        $batches_table_exists = ($check_batches && $check_batches->rowCount() > 0);
        $check_sbi = $pdo_check->query("SHOW TABLES LIKE 'sale_batch_items'");
        $sale_batch_items_exists = ($check_sbi && $check_sbi->rowCount() > 0);
    } catch (\Throwable $e) {
        $batches_table_exists = false;
        $sale_batch_items_exists = false;
    }

    $today = date('Y-m-d');

    // Validate each product row
    $valid_rows = [];
    for ($i = 0; $i < count($product_ids); $i++) {
        $pid = intval($product_ids[$i] ?? 0);
        $qty = floatval($quantities[$i] ?? 0);
        $price = floatval($selling_prices[$i] ?? 0);

        if ($pid <= 0) continue; // skip empty rows
        if ($qty <= 0) {
            $errors[] = t('sales_qty_must_be_positive_product') . ' ' . ($i + 1) . ')';
            continue;
        }
        if ($price <= 0) {
            $errors[] = t('sales_selling_price_must_be_positive_product') . ' ' . ($i + 1) . ')';
            continue;
        }

        // Duplicate product detection
        if (in_array($pid, $seen_product_ids)) {
            $errors[] = t('sales_product_duplicate');
            continue;
        }
        $seen_product_ids[] = $pid;

        // Check product exists and has stock
        $product = fetchOne("SELECT quantity, purchase_price, name, unit FROM products WHERE id = ?", [$pid]);
        if (!$product) {
            $errors[] = t('sales_product_not_found');
            continue;
        }
        if ($qty > $product['quantity']) {
            $errors[] = 'الكمية المطلوبة (' . number_format($qty, 2) . ') أكبر من المتوفر في المخزون (' . number_format($product['quantity'], 2) . ') للمنتج "' . $product['name'] . '"';
            continue;
        }

        // Expiry check per product — فقط إذا جدول الدفعات موجود
        if ($expiry_block_sale && $batches_table_exists) {
            try {
                $expired_batches = fetchAll(
                    "SELECT pb.id, pb.remaining_quantity, pb.expiry_date
                     FROM purchase_batches pb
                     WHERE pb.product_id = ? AND pb.expiry_date < ? AND pb.remaining_quantity > 0
                     ORDER BY pb.expiry_date ASC", [$pid, $today]
                );

                if (!empty($expired_batches)) {
                    $all_batches = fetchAll(
                        "SELECT id, remaining_quantity, expiry_date FROM purchase_batches
                         WHERE product_id = ? AND remaining_quantity > 0
                         ORDER BY CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE purchase_date END ASC, purchase_date ASC, id ASC",
                        [$pid]
                    );
                    $remaining_check = $qty;
                    $expired_qty_to_sell = 0;
                    foreach ($all_batches as $abc) {
                        if ($remaining_check <= 0) break;
                        $take_check = min($remaining_check, floatval($abc['remaining_quantity']));
                        if (!empty($abc['expiry_date']) && $abc['expiry_date'] < $today) {
                            $expired_qty_to_sell += $take_check;
                        }
                        $remaining_check -= $take_check;
                    }

                    if ($expired_qty_to_sell > 0) {
                        $errors[] = '🚫 لا يمكن البيع: المنتج "' . $product['name'] . '" يحتوي على دفعات منتهية الصلاحية. يجب التخلص من الدفعات المنتهية أولاً أو إلغاء خيار "منع بيع المنتجات المنتهية" من الإعدادات.';
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // جدول الدفعات غير متاح — تجاوز فحص الصلاحية
            }
        }

        $valid_rows[] = [
            'index' => $i,
            'product_id' => $pid,
            'quantity' => $qty,
            'selling_price' => $price,
            'product' => $product
        ];
    }

    // Process all valid rows in one transaction
    if (empty($errors) && !empty($valid_rows)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $grand_total = 0;
            $grand_cost = 0;
            $grand_profit = 0;
            $sale_count = count($valid_rows);
            $sale_details = [];
            $all_sale_ids = [];

            // تحضير استعلامات الدفعات — فقط إذا الجداول موجودة
            $sbiStmt = null;
            $batchUpdateStmt = null;
            if ($batches_table_exists && $sale_batch_items_exists) {
                try {
                    $sbiStmt = $pdo->prepare("INSERT INTO sale_batch_items (sale_id, batch_id, quantity_taken, purchase_price, cost_total) VALUES (?, ?, ?, ?, ?)");
                    $batchUpdateStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity - ? WHERE id = ?");
                } catch (\Throwable $e) {
                    // جداول الدفعات غير متاحة — الاستمرار بدونها
                    $sbiStmt = null;
                    $batchUpdateStmt = null;
                }
            }
            $saleStmt = $pdo->prepare("INSERT INTO sales (product_id, quantity, selling_price, purchase_price_at_sale, total, cost_total, profit, sale_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");

            foreach ($valid_rows as $row) {
                $pid = $row['product_id'];
                $qty = $row['quantity'];
                $price = $row['selling_price'];
                $product = $row['product'];

                $cost_total = 0;
                $weighted_purchase_price = 0;
                $sale_batch_data = [];
                $total_quantity_from_batches = 0;

                // محاولة استخدام نظام الدفعات (FIFO/FEFO) — فقط إذا الجدول موجود
                $batches = [];
                if ($batches_table_exists) {
                    try {
                        $batchQuery = $pdo->prepare("SELECT id, remaining_quantity, purchase_price, expiry_date FROM purchase_batches WHERE product_id = ? AND remaining_quantity > 0 ORDER BY CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE purchase_date END ASC, purchase_date ASC, id ASC");
                        $batchQuery->execute([$pid]);
                        $batches = $batchQuery->fetchAll(PDO::FETCH_ASSOC);
                    } catch (\Throwable $e) {
                        // جدول الدفعات غير متاح — استخدام سعر الشراء من جدول المنتجات
                        $batches = [];
                    }
                }

                if (!empty($batches)) {
                    $remaining_to_sell = $qty;

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
                        throw new Exception('الدفعات المتوفرة لا تكفي لتغطية الكمية المطلوبة للمنتج "' . $product['name'] . '". الناقص: ' . number_format($remaining_to_sell, 2));
                    }

                    $weighted_purchase_price = $total_quantity_from_batches > 0 ? $cost_total / $total_quantity_from_batches : 0;
                } else {
                    $cost_total = $qty * $product['purchase_price'];
                    $weighted_purchase_price = $product['purchase_price'];
                    $total_quantity_from_batches = $qty;
                }

                $total = $qty * $price;
                $profit = $total - $cost_total;

                $saleStmt->execute([$pid, $qty, $price, $weighted_purchase_price, $total, $cost_total, $profit, $sale_date, $notes]);
                $sale_id = $pdo->lastInsertId();
                $all_sale_ids[] = $sale_id;

                if (!empty($sale_batch_data) && $sbiStmt !== null) {
                    foreach ($sale_batch_data as $sbd) {
                        try {
                            $sbiStmt->execute([$sale_id, $sbd['batch_id'], $sbd['quantity_taken'], $sbd['purchase_price'], $sbd['cost_total']]);
                            if ($batchUpdateStmt !== null) {
                                $batchUpdateStmt->execute([$sbd['quantity_taken'], $sbd['batch_id']]);
                            }
                        } catch (\Throwable $e) {
                            // فشل تسجيل الدفعات — المتابعة بدونها (البيع مسجل بالفعل)
                        }
                    }
                }

                $deduct_quantity = !empty($sale_batch_data) ? $total_quantity_from_batches : $qty;
                $updateStmt->execute([$deduct_quantity, $pid]);

                $grand_total += $total;
                $grand_cost += $cost_total;
                $grand_profit += $profit;

                $sale_details[] = $qty . ' ' . $product['unit'] . ' من ' . $product['name'];
            }

            $pdo->commit();
            $success = true;
            $success_summary = 'تم تسجيل ' . $sale_count . ' عمليات بيع بنجاح - الإجمالي: ' . formatMoney($grand_total) . ' - الربح: ' . formatMoney($grand_profit);

                        // إشعار عملية بيع جديدة (إشعار واحد يجمل كل المبيعات)
            try {
                $currencySymbol = getCurrencySymbol();
                $totalFormatted = $currencySymbol . number_format($grand_total, 2);
                $profitFormatted = $currencySymbol . number_format($grand_profit, 2);
                $detailText = implode(' | ', $sale_details);
                $notifMessage = "تم بيع {$sale_count} منتجات بمبلغ {$totalFormatted} (ربح: {$profitFormatted})\n{$detailText}";
                AppNotification::add(NOTIF_SALE, 'عملية بيع جديدة - ' . $sale_count . ' منتجات', $notifMessage, ['url' => '../sales/add-sale.php', 'sale_ids' => $all_sale_ids]);
            } catch (Exception $e) {}

        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $errors[] = t('sales_error_saving');
        }
    }
}

// سجل المبيعات الأخيرة
$recent_sales = fetchAll(
    "SELECT s.*, pr.name as product_name, pr.code, pr.unit
     FROM sales s JOIN products pr ON s.product_id = pr.id
     ORDER BY s.created_at DESC LIMIT 20"
);

// === جلب كل الدفعات لجميع المبيعات باستعلام واحد (بدل N+1) ===
$all_batch_items = [];
if (!empty($recent_sales)) {
    $sale_ids = array_column($recent_sales, 'id');
    $placeholders = implode(',', array_fill(0, count($sale_ids), '?'));
    try {
        $all_rows = fetchAll(
            "SELECT sbi.*, pb.purchase_date as batch_date, pb.expiry_date
             FROM sale_batch_items sbi
             LEFT JOIN purchase_batches pb ON sbi.batch_id = pb.id
             WHERE sbi.sale_id IN ($placeholders)
             ORDER BY pb.purchase_date ASC",
            $sale_ids
        );
        foreach ($all_rows as $row) {
            $all_batch_items[$row['sale_id']][] = $row;
        }
    } catch (Exception $e) {}
}
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php echo t('sales_register_new_sale'); ?>
        </h1>
        <a href="calculate-profits.php" class="btn btn-outline"><?php echo t('nav_calculate_profits'); ?></a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_summary); ?></div>
    <?php endif; ?>

    <?php if (!empty($delete_msg)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($delete_msg); ?></div>
    <?php endif; ?>

    <?php if (!empty($edit_msg)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($edit_msg); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2><?php echo t('sales_register_new_sale'); ?></h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>ملاحظة:</strong> يمكنك إضافة عدة منتجات في عملية بيع واحدة. يتم حساب التكلفة بطريقة FIFO/FEFO (أول ما دخل أو يقترب من الانتهاء يُباع أولاً).
            </div>
            <form method="POST" action="" id="saleForm">

                <!-- زر المسح بالباركود بارز -->
                <div class="sale-buttons-row">
                    <button type="button" onclick="openBarcodeScanner()" class="btn btn-lg sale-barcode-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                            <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                            <line x1="7" y1="12" x2="17" y2="12"/>
                        </svg>
                        <?php echo t('barcode_scanning'); ?>
                    </button>
                    <button type="button" onclick="addProductRow()" class="btn btn-lg sale-add-manual-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        إضافة منتج يدوياً
                    </button>
                </div>

                <!-- الحقول المشتركة: التاريخ والملاحظات -->
                <div class="form-grid" style="margin-bottom: 1rem;">
                    <div class="form-group">
                        <label><?php echo t('sales_date'); ?> <span class="required">*</span></label>
                        <input type="date" name="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('notes'); ?></label>
                        <input type="text" name="notes" class="form-control" placeholder="ملاحظات إضافية (اختياري)">
                    </div>
                </div>

                <!-- حاوية صفوف المنتجات -->
                <div id="productRows"></div>

                <!-- زر إضافة صف منتج (أصغر) -->
                <div style="margin: 1rem 0;">
                    <button type="button" onclick="addProductRow()" class="btn sale-add-row-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        + إضافة صف منتج آخر
                    </button>
                </div>

                <!-- ملخص البيع -->
                <div id="saleSummary" class="sale-summary-box">
                    <div class="sale-summary-grid">
                        <div class="sale-summary-item">
                            <div class="sale-summary-label">عدد المنتجات</div>
                            <div id="summaryCount" class="sale-summary-value">0</div>
                        </div>
                        <div class="sale-summary-item">
                            <div class="sale-summary-label">الإجمالي</div>
                            <div id="summaryTotal" class="sale-summary-value sale-summary-value--total">0.00</div>
                        </div>
                        <div class="sale-summary-item">
                            <div class="sale-summary-label">تكلفة الشراء (FIFO)</div>
                            <div id="summaryCost" class="sale-summary-value sale-summary-value--cost">0.00</div>
                        </div>
                        <div class="sale-summary-item">
                            <div class="sale-summary-label">الربح المتوقع</div>
                            <div id="summaryProfit" class="sale-summary-value sale-summary-value--profit">0.00</div>
                        </div>
                    </div>
                </div>

                <!-- زر التسجيل -->
                <div style="margin-top: 1rem;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-warning btn-lg sale-submit-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                        <?php echo t('sales_register'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- سجل المبيعات -->
    <div class="card">
        <div class="card-header"><h2><?php echo t('sales_recent'); ?></h2></div>
        <div class="card-body">
            <?php if (empty($recent_sales)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;"><?php echo t('sales_no_results'); ?></p>

            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('code'); ?></th>
                            <th><?php echo t('product'); ?></th>
                            <th><?php echo t('quantity'); ?></th>
                            <th><?php echo t('sales_price'); ?></th>
                            <th><?php echo t('total'); ?></th>
                            <th>تكلفة الشراء (FIFO)</th>
                            <th><?php echo t('profit'); ?></th>
                            <th><?php echo t('date'); ?></th>
                            <th>تفاصيل الدفعات</th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($recent_sales as $s):
                            $cost = $s['cost_total'] ?? ($s['quantity'] * ($s['purchase_price_at_sale'] ?? 0));
                            $profit = $s['profit'] ?? ($s['total'] - $cost);

                            $sale_batches = $all_batch_items[$s['id']] ?? [];
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
                                    <button type="button" onclick="toggleBatches(<?php echo $s['id']; ?>)" class="btn btn-sm sale-batches-btn">
                                        عرض الدفعات (<?php echo count($sale_batches); ?>)
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted sale-muted-small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="sale-actions-cell">
                                <a href="edit-sale.php?id=<?php echo $s['id']; ?>" class="sale-action-btn--edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    تعديل
                                </a>
                                <a href="delete-sale.php?id=<?php echo $s['id']; ?>" onclick="return confirm('<?php echo t('sales_confirm_delete_sale'); ?>')" class="sale-action-btn--delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    حذف
                                </a>
                            </td>
                        </tr>
                        <?php if (!empty($sale_batches)): ?>
                        <tr id="batches-<?php echo $s['id']; ?>" class="sale-batch-row">
                            <td colspan="11" class="sale-batch-row">
                                <div style="font-size:0.85rem;">
                                    <strong>تفاصيل استهلاك الدفعات (FIFO/FEFO):</strong>
                                    <div class="table-responsive">
                                    <table class="sale-batch-table">
                                        <thead>
                                            <tr>
                                                <th>تاريخ الدفعة</th>
                                                <th>تاريخ الانتهاء</th>
                                                <th>الكمية المأخوذة</th>
                                                <th>سعر الشراء</th>
                                                <th>التكلفة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sale_batches as $sb): ?>
                                            <tr>
                                                <td><?php echo $sb['batch_date'] ?? '-'; ?></td>
                                                <td><?php echo !empty($sb['expiry_date']) ? '<span style="color:#2563eb; font-weight:600;">'.$sb['expiry_date'].'</span>' : '<span style="color:var(--gray-300);">—</span>'; ?></td>
                                                <td><?php echo number_format($sb['quantity_taken'], 2); ?></td>
                                                <td><?php echo formatMoney($sb['purchase_price']); ?></td>
                                                <td style="font-weight:600;"><?php echo formatMoney($sb['cost_total']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="2">الإجمالي</td>
                                                <td><?php echo number_format(array_sum(array_column($sale_batches, 'quantity_taken')), 2); ?></td>
                                                <td>-</td>
                                                <td><?php echo formatMoney(array_sum(array_column($sale_batches, 'cost_total'))); ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    </div>
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



<script>
const CURRENCY_SYMBOL = '<?php echo getCurrencySymbol(); ?>';

// حالة عامة
let rowCounter = 0;
let productRows = {}; // index => { productId, productData, searchTimeout, searchResults, highlightIndex, isDropdownOpen, batchData }

// === إضافة صف منتج جديد ===
function addProductRow() {
    const idx = rowCounter++;
    const container = document.getElementById('productRows');
    const row = document.createElement('div');
    row.className = 'product-row';
    row.id = 'productRow_' + idx;

    row.innerHTML = `
        <div class="product-row-header">
            <span class="product-row-number">منتج #${idx + 1}</span>
            <button type="button" class="product-row-remove" onclick="removeProductRow(${idx})">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                حذف
            </button>
        </div>
        <div class="product-row-grid">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>المنتج <span class="required">*</span></label>
                <div class="product-search-wrapper" id="psw_${idx}">
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text"
                               id="psi_${idx}"
                               class="form-control search-input"
                               placeholder="ابحث بالكود أو اسم المنتج..."
                               autocomplete="off"
                               onfocus="openRowDropdown(${idx})"
                               oninput="searchRowProducts(${idx})"
                               onkeydown="handleRowKeydown(event, ${idx})">
                        <button type="button" class="search-clear-btn" id="pscb_${idx}" onclick="clearRowSearch(${idx})" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                        <button type="button" class="search-arrow-btn" id="psab_${idx}" onclick="toggleRowDropdown(${idx})" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <button type="button" class="search-scan-btn" data-scan-target="${idx}" onclick="openBarcodeScanner(this)" title="مسح الباركود بالكاميرا">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                <line x1="7" y1="12" x2="17" y2="12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="selected-product" id="spp_${idx}" style="display:none;">
                        <div class="selected-product-info">
                            <span class="selected-product-code" id="spc_${idx}"></span>
                            <span class="selected-product-name" id="spn_${idx}"></span>
                            <span class="selected-product-stock" id="sps_${idx}"></span>
                        </div>
                        <button type="button" class="selected-product-remove" onclick="clearRowSelection(${idx})" title="إزالة المنتج">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="search-dropdown" id="psd_${idx}" style="display:none;">
                        <div class="search-results" id="psr_${idx}">
                            <div class="search-loading" id="psl_${idx}" style="display:none;">
                                <div class="spinner"></div>
                                جاري البحث...
                            </div>
                            <div class="search-no-results" id="psnr_${idx}" style="display:none;">
                                لا توجد نتائج مطابقة
                            </div>
                            <div class="search-hint" id="psh_${idx}">
                                اكتب للبحث عن منتج (كود أو اسم)
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="product_ids[]" id="pid_${idx}" value="">
                </div>
            </div>
        </div>
        <div class="product-row-fields" id="prf_${idx}" style="display:none;">
            <div class="form-group">
                <label>الكمية <span class="required">*</span></label>
                <input type="number" id="pqty_${idx}" class="form-control" step="0.01" min="0.01" placeholder="الكمية" oninput="calculateLineTotal(${idx})">
                <div class="form-text" id="pstock_${idx}"></div>
            </div>
            <div class="form-group">
                <label>سعر البيع <span class="required">*</span></label>
                <input type="number" name="selling_prices[]" id="pprice_${idx}" class="form-control" step="0.01" min="0.01" placeholder="سعر البيع" oninput="calculateLineTotal(${idx})">
            </div>
            <div class="form-group">
                <label>الإجمالي</label>
                <input type="text" id="pline_total_${idx}" class="form-control sale-line-total-input" value="0.00" readonly>
            </div>
        </div>
        <div class="product-row-summary" id="prs_${idx}" style="display:none;">
            <div class="form-group">
                <label>تكلفة الشراء (FIFO)</label>
                <input type="text" id="pfifo_${idx}" class="form-control sale-fifo-input" value="0.00" readonly>
            </div>
            <div class="form-group">
                <label>الربح المتوقع</label>
                <input type="text" id="pprofit_${idx}" class="form-control sale-profit-input" value="0.00" readonly>
            </div>
            <div class="form-group sale-align-end">
                <input type="hidden" name="quantities[]" id="pqtyh_${idx}" value="0">
            </div>
        </div>
    `;

    container.appendChild(row);

    // Initialize state
    productRows[idx] = {
        productId: null,
        productData: null,
        searchTimeout: null,
        searchResults: [],
        highlightIndex: -1,
        isDropdownOpen: false,
        batchData: { batches: [], weighted_price: 0, total_remaining: 0 }
    };

    // Focus the search input (only if not during barcode processing)
    if (!window._barcodeProcessing) {
        document.getElementById('psi_' + idx).focus();
    }
    updateRowNumbers();
}

// === حذف صف منتج ===
function removeProductRow(idx) {
    const row = document.getElementById('productRow_' + idx);
    if (row) {
        row.remove();
        delete productRows[idx];
    }
    recalculateTotals();
    updateRowNumbers();
}

// === تحديث أرقام الصفوف ===
function updateRowNumbers() {
    const rows = document.querySelectorAll('.product-row');
    rows.forEach((row, i) => {
        const numEl = row.querySelector('.product-row-number');
        if (numEl) numEl.textContent = 'منتج #' + (i + 1);
    });
}

// === بحث المنتجات لصف معين ===
function searchRowProducts(idx) {
    const input = document.getElementById('psi_' + idx);
    const query = input.value.trim();
    const state = productRows[idx];
    if (!state) return;

    document.getElementById('pscb_' + idx).style.display = query ? 'flex' : 'none';
    document.getElementById('psab_' + idx).style.display = 'none';

    if (state.searchTimeout) clearTimeout(state.searchTimeout);

    document.getElementById('psd_' + idx).style.display = 'flex';
    state.isDropdownOpen = true;
    state.highlightIndex = -1;

    state.searchTimeout = setTimeout(() => {
        const loading = document.getElementById('psl_' + idx);
        const noResults = document.getElementById('psnr_' + idx);
        const hint = document.getElementById('psh_' + idx);
        const resultsDiv = document.getElementById('psr_' + idx);

        loading.style.display = 'flex';
        noResults.style.display = 'none';
        hint.style.display = 'none';
        resultsDiv.querySelectorAll('.search-product-item, .search-dropdown-footer').forEach(el => el.remove());

        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                loading.style.display = 'none';
                resultsDiv.querySelectorAll('.search-product-item, .search-dropdown-footer').forEach(el => el.remove());

                var _products = data.products || data.data || [];
                if (_products.length > 0) {
                    state.searchResults = _products;

                    _products.forEach((product, index) => {
                        const item = createRowProductItem(product, query, idx);
                        item.dataset.index = index;
                        item.addEventListener('mouseenter', () => {
                            clearRowHighlight(idx);
                            item.classList.add('keyboard-nav');
                            state.highlightIndex = index;
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
                    var errMsg = '';
                    if (data.success === false) errMsg = data.message || '';
                    noResults.style.display = 'block';
                    noResults.textContent = errMsg || 'لا توجد نتائج';
                    state.searchResults = [];
                }
            })
            .catch(err => {
                loading.style.display = 'none';
                noResults.style.display = 'block';
                noResults.textContent = 'خطأ: ' + (err.message || 'اتصال فاشل');
            });
    }, 300);
}

// === إنشاء عنصر منتج في القائمة ===
function createRowProductItem(product, query, idx) {
    const item = document.createElement('div');
    item.className = 'search-product-item';
    item.onclick = () => selectRowProduct(idx, product);

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

    // Check if product is already selected in another row
    const duplicateId = isDuplicateProduct(product.id, idx);
    if (duplicateId !== null) {
        item.style.opacity = '0.5';
        item.style.pointerEvents = 'none';
        stockText += ' (مضاف مسبقاً)';
        stockClass = 'out-of-stock';
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

// === فحص المنتج المكرر ===
function isDuplicateProduct(productId, currentIdx) {
    for (const [idx, state] of Object.entries(productRows)) {
        if (parseInt(idx) === currentIdx) continue;
        if (state.productId === productId) return parseInt(idx);
    }
    return null;
}

// === اختيار منتج لصف معين ===
function selectRowProduct(idx, product) {
    // Check duplicate
    const dupIdx = isDuplicateProduct(product.id, idx);
    if (dupIdx !== null) {
        alert('هذا المنتج مضاف بالفعل في صف آخر (#' + (getRowPosition(dupIdx) + 1) + ')');
        return;
    }

    const state = productRows[idx];
    state.productId = product.id;
    state.productData = product;

    document.getElementById('pid_' + idx).value = product.id;
    document.getElementById('psi_' + idx).style.display = 'none';
    document.getElementById('pscb_' + idx).style.display = 'none';
    document.getElementById('psab_' + idx).style.display = 'none';

    const selectedDiv = document.getElementById('spp_' + idx);
    selectedDiv.style.display = 'flex';
    document.getElementById('spc_' + idx).textContent = product.code;
    document.getElementById('spn_' + idx).textContent = product.name;

    const qty = parseFloat(product.quantity);
    const stockSpan = document.getElementById('sps_' + idx);
    if (qty <= 0) {
        stockSpan.style.background = '#fee2e2';
        stockSpan.style.color = '#dc2626';
        stockSpan.textContent = 'غير متوفر';
    } else {
        stockSpan.style.background = '#fef3c7';
        stockSpan.style.color = '#92400e';
        stockSpan.textContent = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit;
    }

    closeRowDropdown(idx);

    // Show fields
    document.getElementById('prf_' + idx).style.display = 'grid';
    document.getElementById('prs_' + idx).style.display = 'grid';
    document.getElementById('pprice_' + idx).value = product.selling_price;
    document.getElementById('pqty_' + idx).value = '';
    document.getElementById('pqtyh_' + idx).value = '0';
    document.getElementById('pstock_' + idx).innerHTML = '<strong>المتوفر:</strong> ' + qty.toFixed(2) + ' ' + product.unit;

    // Mark row
    document.getElementById('productRow_' + idx).classList.add('has-product');

    // Load batch info for FIFO
    loadRowBatchInfo(idx, product.id);
}

// === تحميل بيانات الدفعات ===
function loadRowBatchInfo(idx, productId) {
    fetch('../api/get_batches.php?product_id=' + productId)
        .then(res => res.json())
        .then(data => {
            productRows[idx].batchData = data;
            calculateLineTotal(idx);
        })
        .catch(err => {
            productRows[idx].batchData = { batches: [], weighted_price: 0, total_remaining: 0 };
            calculateLineTotal(idx);
        });
}

// === حساب إجمالي الصف ===
function calculateLineTotal(idx) {
    const state = productRows[idx];
    if (!state || !state.productId) return;

    const qty = parseFloat(document.getElementById('pqty_' + idx).value) || 0;
    const price = parseFloat(document.getElementById('pprice_' + idx).value) || 0;

    // Stock validation
    if (state.productData && qty > parseFloat(state.productData.quantity)) {
        document.getElementById('pqty_' + idx).style.borderColor = '#ef4444';
        document.getElementById('pstock_' + idx).innerHTML = '<span style="color:#ef4444; font-weight:600;">⚠️ الكمية أكبر من المتوفر (' + parseFloat(state.productData.quantity).toFixed(2) + ')</span>';
    } else {
        document.getElementById('pqty_' + idx).style.borderColor = '';
        if (state.productData) {
            document.getElementById('pstock_' + idx).innerHTML = '<strong>المتوفر:</strong> ' + parseFloat(state.productData.quantity).toFixed(2) + ' ' + state.productData.unit;
        }
    }

    // FIFO cost calculation
    let fifoCostTotal = 0;
    let remainingToSell = qty;
    const batchData = state.batchData;

    if (batchData.batches && batchData.batches.length > 0) {
        for (let i = 0; i < batchData.batches.length && remainingToSell > 0; i++) {
            const batch = batchData.batches[i];
            const batchRemaining = parseFloat(batch.remaining_quantity);
            const batchPrice = parseFloat(batch.purchase_price);
            const take = Math.min(remainingToSell, batchRemaining);
            fifoCostTotal += take * batchPrice;
            remainingToSell -= take;
        }
    } else if (state.productData) {
        fifoCostTotal = qty * parseFloat(state.productData.purchase_price || 0);
    }

    const lineTotal = qty * price;
    const lineProfit = lineTotal - fifoCostTotal;

    document.getElementById('pline_total_' + idx).value = lineTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('pfifo_' + idx).value = fifoCostTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('pprofit_' + idx).value = lineProfit.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('pqtyh_' + idx).value = qty.toFixed(2);

    const profitField = document.getElementById('pprofit_' + idx);
    if (lineProfit >= 0) {
        profitField.style.color = '#059669';
        profitField.style.background = '#f0fdf4';
    } else {
        profitField.style.color = '#dc2626';
        profitField.style.background = '#fef2f2';
    }

    recalculateTotals();
}

// === إعادة حساب الإجماليات ===
function recalculateTotals() {
    let grandTotal = 0;
    let grandCost = 0;
    let grandProfit = 0;
    let productCount = 0;

    for (const [idx, state] of Object.entries(productRows)) {
        if (!state.productId) continue;
        productCount++;

        const qty = parseFloat(document.getElementById('pqtyh_' + idx).value) || 0;
        const price = parseFloat(document.getElementById('pprice_' + idx).value) || 0;
        const lineTotal = qty * price;

        let fifoCost = 0;
        let remainingToSell = qty;
        const batchData = state.batchData;

        if (batchData.batches && batchData.batches.length > 0) {
            for (let i = 0; i < batchData.batches.length && remainingToSell > 0; i++) {
                const batch = batchData.batches[i];
                const take = Math.min(remainingToSell, parseFloat(batch.remaining_quantity));
                fifoCost += take * parseFloat(batch.purchase_price);
                remainingToSell -= take;
            }
        } else if (state.productData) {
            fifoCost = qty * parseFloat(state.productData.purchase_price || 0);
        }

        grandTotal += lineTotal;
        grandCost += fifoCost;
        grandProfit += (lineTotal - fifoCost);
    }

    const summary = document.getElementById('saleSummary');
    if (productCount > 0) {
        summary.classList.add('visible');
    } else {
        summary.classList.remove('visible');
    }

    document.getElementById('summaryCount').textContent = productCount;
    document.getElementById('summaryTotal').textContent = grandTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('summaryCost').textContent = grandCost.toFixed(2) + ' ' + CURRENCY_SYMBOL;
    document.getElementById('summaryProfit').textContent = grandProfit.toFixed(2) + ' ' + CURRENCY_SYMBOL;

    const profitEl = document.getElementById('summaryProfit');
    if (grandProfit >= 0) {
        profitEl.style.color = '#059669';
    } else {
        profitEl.style.color = '#dc2626';
    }
}

// === إزالة اختيار المنتج من صف ===
function clearRowSelection(idx) {
    const state = productRows[idx];
    if (!state) return;

    state.productId = null;
    state.productData = null;
    state.batchData = { batches: [], weighted_price: 0, total_remaining: 0 };

    document.getElementById('pid_' + idx).value = '';
    document.getElementById('spp_' + idx).style.display = 'none';
    document.getElementById('psi_' + idx).style.display = 'block';
    document.getElementById('psi_' + idx).value = '';
    document.getElementById('psi_' + idx).focus();
    document.getElementById('prf_' + idx).style.display = 'none';
    document.getElementById('prs_' + idx).style.display = 'none';
    document.getElementById('productRow_' + idx).classList.remove('has-product');

    document.getElementById('pprice_' + idx).value = '';
    document.getElementById('pqty_' + idx).value = '';
    document.getElementById('pqtyh_' + idx).value = '0';
    document.getElementById('pline_total_' + idx).value = '0.00 ' + CURRENCY_SYMBOL;
    document.getElementById('pfifo_' + idx).value = '0.00 ' + CURRENCY_SYMBOL;
    document.getElementById('pprofit_' + idx).value = '0.00 ' + CURRENCY_SYMBOL;

    recalculateTotals();
}

// === مسح البحث في صف ===
function clearRowSearch(idx) {
    const input = document.getElementById('psi_' + idx);
    input.value = '';
    document.getElementById('pscb_' + idx).style.display = 'none';
    document.getElementById('psab_' + idx).style.display = 'flex';
    input.focus();
    searchRowProducts(idx);
}

// === فتح القائمة المنسدلة ===
function openRowDropdown(idx) {
    const state = productRows[idx];
    if (!state) return;
    if (state.productId) return;

    document.getElementById('psd_' + idx).style.display = 'flex';
    state.isDropdownOpen = true;

    if (!document.getElementById('psi_' + idx).value.trim()) {
        searchRowProducts(idx);
    }

    document.getElementById('pscb_' + idx).style.display = 'none';
    document.getElementById('psab_' + idx).style.display = 'flex';
}

// === إغلاق القائمة المنسدلة ===
function closeRowDropdown(idx) {
    const state = productRows[idx];
    if (!state) return;
    document.getElementById('psd_' + idx).style.display = 'none';
    state.isDropdownOpen = false;
    state.highlightIndex = -1;
}

// === تبديل القائمة المنسدلة ===
function toggleRowDropdown(idx) {
    const state = productRows[idx];
    if (!state) return;
    if (state.isDropdownOpen) closeRowDropdown(idx);
    else openRowDropdown(idx);
}

// === مسح التظليل ===
function clearRowHighlight(idx) {
    const resultsDiv = document.getElementById('psr_' + idx);
    resultsDiv.querySelectorAll('.search-product-item').forEach(el => el.classList.remove('keyboard-nav'));
}

// === تحديث التظليل ===
function updateRowHighlight(idx) {
    const state = productRows[idx];
    const items = document.querySelectorAll('#psr_' + idx + ' .search-product-item');
    items.forEach((item, i) => {
        item.classList.toggle('keyboard-nav', i === state.highlightIndex);
    });
    if (items[state.highlightIndex]) {
        items[state.highlightIndex].scrollIntoView({ block: 'nearest' });
    }
}

// === التنقل بلوحة المفاتيح ===
function handleRowKeydown(e, idx) {
    const state = productRows[idx];
    if (!state || !state.isDropdownOpen) return;

    const items = document.querySelectorAll('#psr_' + idx + ' .search-product-item');
    if (items.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        state.highlightIndex = Math.min(state.highlightIndex + 1, items.length - 1);
        updateRowHighlight(idx);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        state.highlightIndex = Math.max(state.highlightIndex - 1, 0);
        updateRowHighlight(idx);
    } else if (e.key === 'Enter' && state.highlightIndex >= 0) {
        e.preventDefault();
        items[state.highlightIndex].click();
    } else if (e.key === 'Escape') {
        closeRowDropdown(idx);
        document.getElementById('psi_' + idx).blur();
    }
}

// === إغلاق القوائم عند النقر خارجها ===
document.addEventListener('click', function(e) {
    for (const idx of Object.keys(productRows)) {
        const wrapper = document.getElementById('psw_' + parseInt(idx));
        if (wrapper && !wrapper.contains(e.target)) {
            closeRowDropdown(parseInt(idx));
        }
    }
});

// === أدوات مساعدة ===
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeRegex(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function getRowPosition(idx) {
    const rows = document.querySelectorAll('.product-row');
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].id === 'productRow_' + idx) return i;
    }
    return 0;
}

// === عرض/إخفاء تفاصيل الدفعات في الجدول ===
function toggleBatches(saleId) {
    const row = document.getElementById('batches-' + saleId);
    if (row) row.classList.toggle('visible');
}

// === التحقق من صحة النموذج عند الإرسال ===
document.addEventListener('DOMContentLoaded', function() {
    // Add one empty row on load
    addProductRow();

    // Form validation before submit
    document.getElementById('saleForm').addEventListener('submit', function(e) {
        let hasProduct = false;
        let valid = true;

        for (const [idx, state] of Object.entries(productRows)) {
            if (!state.productId) continue;
            hasProduct = true;

            const qty = parseFloat(document.getElementById('pqtyh_' + parseInt(idx)).value) || 0;
            const price = parseFloat(document.getElementById('pprice_' + parseInt(idx)).value) || 0;

            if (qty <= 0) {
                valid = false;
                document.getElementById('pqty_' + parseInt(idx)).style.borderColor = '#ef4444';
                alert('يرجى إدخال الكمية في صف المنتج #' + (getRowPosition(parseInt(idx)) + 1));
                e.preventDefault();
                return;
            }
            if (price <= 0) {
                valid = false;
                document.getElementById('pprice_' + parseInt(idx)).style.borderColor = '#ef4444';
                alert('يرجى إدخال سعر البيع في صف المنتج #' + (getRowPosition(parseInt(idx)) + 1));
                e.preventDefault();
                return;
            }

            // Check stock
            if (state.productData && qty > parseFloat(state.productData.quantity)) {
                valid = false;
                alert('الكمية المطلوبة في صف المنتج "' + state.productData.name + '" أكبر من المتوفر في المخزون');
                e.preventDefault();
                return;
            }
        }

        if (!hasProduct) {
            alert('يرجى إضافة منتج واحد على الأقل');
            e.preventDefault();
            return;
        }
    });
});

// === دعم مسح الباركود ===
// إغلاق جميع القوائم المنسدلة (مطلوب من barcode-scanner.php)
function closeSearchDropdown() {
    for (var k in productRows) {
        if (productRows.hasOwnProperty(k)) {
            closeRowDropdown(parseInt(k));
        }
    }
}

// callback المسمى من barcode-scanner.php عند نجاح المسح
// target: قيمة data-scan-target من زر المسح (رقم الصف) أو null
window.onBarcodeScanned = function(code, target) {
    if (!code) return;
    code = code.trim();
    console.log('[بيع] باركود مسح:', code, '| الهدف:', target);

    // منع التركيز التلقائي أثناء المعالجة
    window._barcodeProcessing = true;

    // الخطوة 1: تحديد الصف المستهدف
    var targetIdx = null;

    // إذا تم تحديد صف معين (من زر المسح في الصف عبر data-scan-target)
    var scanTarget = target !== undefined ? target : null;
    if (scanTarget !== null && productRows[scanTarget] && !productRows[scanTarget].productId) {
        targetIdx = parseInt(scanTarget);
    } else {
        // ابحث عن أول صف فارغ
        var keys = Object.keys(productRows);
        for (var i = 0; i < keys.length; i++) {
            var k = parseInt(keys[i]);
            if (productRows[k] && !productRows[k].productId) {
                targetIdx = k;
                break;
            }
        }
    }

    // إذا لم يوجد صف فارغ، أنشئ صف جديد
    if (targetIdx === null) {
        addProductRow();
        var allKeys = Object.keys(productRows);
        targetIdx = parseInt(allKeys[allKeys.length - 1]);
    }

    console.log('[بيع] الصف المستهدف:', targetIdx);

    // الخطوة 2: البحث عن المنتج بالباركود
    fetch('../api/search_products.php?q=' + encodeURIComponent(code))
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(data) {
            var products = data.products || data.data || [];
            console.log('[بيع] نتائج البحث:', products.length, 'منتج');

            if (products.length === 0) {
                // المنتج غير موجود - إظهار رسالة
                alert('لم يتم العثور على منتج بالكود: ' + code);
                var inp = document.getElementById('psi_' + targetIdx);
                if (inp) { inp.value = ''; inp.focus(); }
                return;
            }

            // البحث عن تطابق دقيق (code أو barcode)
            var product = products.find(function(p) { return p.code === code || p.barcode === code; });
            if (!product) product = products[0]; // أول نتيجة

            console.log('[بيع] المنتج المختار:', product.name, '(كود:', product.code, ')');

            // تحقق من التكرار
            var dupIdx = isDuplicateProduct(product.id, targetIdx);
            if (dupIdx !== null) {
                alert('هذا المنتج مضاف بالفعل في صف آخر (#' + (getRowPosition(dupIdx) + 1) + ')');
                return;
            }

            // اختيار المنتج في الصف
            selectRowProduct(targetIdx, product);

            // إنشاء صف فارغ جديد للمسح التالي
            setTimeout(function() {
                window._barcodeProcessing = false;
                addProductRow();
            }, 100);
        })
        .catch(function(err) {
            window._barcodeProcessing = false;
            console.error('[بيع] خطأ في البحث:', err);
            alert('حدث خطأ أثناء البحث عن المنتج: ' + err.message);
        });
};
</script>

<?php require_once '../includes/barcode-scanner.php'; ?>
<?php require_once '../includes/footer.php'; ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            