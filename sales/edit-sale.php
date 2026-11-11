<?php
require_once '../includes/bootstrap.php';
$page_title = t('sales_edit_sale_title');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('sales.edit');

// التحقق من المعرف قبل أي شيء
$sale_id = intval($_GET['id'] ?? 0);
if (!$sale_id) {
    header("Location: add-sale.php");
    exit;
}

// جلب بيانات البيع مع المنتج
$sale = fetchOne(
    "SELECT s.*, p.name as product_name, p.code, p.unit, p.quantity as current_stock, p.has_expiry
     FROM sales s
     JOIN products p ON s.product_id = p.id
     WHERE s.id = ?",
    [$sale_id]
);

if (!$sale) {
    header("Location: add-sale.php");
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $new_quantity = floatval($_POST['quantity'] ?? 0);
    $new_selling_price = floatval($_POST['selling_price'] ?? 0);
    $new_sale_date = trim($_POST['sale_date'] ?? '');
    $new_notes = trim($_POST['notes'] ?? '');

    // التحقق من البيانات
    if ($new_quantity <= 0) {
        $errors[] = t('common_quantity_must_be_positive');
    }
    if ($new_selling_price <= 0) {
        $errors[] = t('common_selling_price_must_be_positive');
    }
    if (empty($new_sale_date)) {
        $errors[] = t('sales_date_required');
    }

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $product_id = intval($sale['product_id']);
            $old_quantity = floatval($sale['quantity']);

            // ===== المرحلة 1: عكس البيع القديم =====

            // استرجاع كمية المنتج
            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([
                $old_quantity,
                $product_id
            ]);

            // Restore batch quantities from sale_batch_items
            $oldBatchItems = $pdo->prepare("SELECT batch_id, quantity_taken FROM sale_batch_items WHERE sale_id = ?");
            $oldBatchItems->execute([$sale_id]);
            $batchRestoreStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity + ? WHERE id = ?");
            while ($bi = $oldBatchItems->fetch(PDO::FETCH_ASSOC)) {
                $batchRestoreStmt->execute([floatval($bi['quantity_taken']), intval($bi['batch_id'])]);
            }
            // Delete old sale_batch_items
            $pdo->prepare("DELETE FROM sale_batch_items WHERE sale_id = ?")->execute([$sale_id]);

            // ===== المرحلة 2: التحقق من المخزون المتاح (بعد الاسترجاع) =====
            $productCheck = $pdo->prepare("SELECT quantity FROM products WHERE id = ? FOR UPDATE");
            $productCheck->execute([$product_id]);
            $product_after_restore = $productCheck->fetch(PDO::FETCH_ASSOC);

            if (!$product_after_restore) {
                throw new Exception(t('sales_product_not_found'));
            }

            $available_stock = floatval($product_after_restore['quantity']);

            if ($new_quantity > $available_stock) {
                throw new Exception('الكمية المطلوبة (' . number_format($new_quantity, 2) . ') أكبر من المخزون المتوفر (' . number_format($available_stock, 2) . ')');
            }

            // ===== المرحلة 3: حساب تكلفة FEFO للكمية الجديدة =====
            $cost_total = 0;
            $weighted_purchase_price = 0;
            $batch_allocations = [];
            $has_expiry = intval($sale['has_expiry']);

            $currentProductInfo = $pdo->prepare("SELECT purchase_price FROM products WHERE id = ?");
            $currentProductInfo->execute([$product_id]);
            $pInfo = $currentProductInfo->fetch(PDO::FETCH_ASSOC);
            $fallback_price = floatval($pInfo['purchase_price'] ?? 0);

            if ($has_expiry) {
                $batch_allocations = SaleService::deductFromBatches($product_id, $new_quantity, 'fefo');
            }

            if (!empty($batch_allocations)) {
                $total_quantity_from_batches = 0;
                foreach ($batch_allocations as $alloc) {
                    $cost_total += $alloc['quantity'] * $alloc['cost_price'];
                    $total_quantity_from_batches += $alloc['quantity'];
                }
                $weighted_purchase_price = $total_quantity_from_batches > 0 ? $cost_total / $total_quantity_from_batches : $fallback_price;
            } else {
                $cost_total = $new_quantity * $fallback_price;
                $weighted_purchase_price = $fallback_price;
            }

            $total = $new_quantity * $new_selling_price;
            $profit = $total - $cost_total;

            // ===== المرحلة 4: تحديث سجل البيع =====
            $updateSaleStmt = $pdo->prepare(
                "UPDATE sales SET quantity = ?, selling_price = ?, total = ?, cost_total = ?, profit = ?, purchase_price_at_sale = ?, sale_date = ?, notes = ? WHERE id = ?"
            );
            $updateSaleStmt->execute([
                $new_quantity,
                $new_selling_price,
                $total,
                $cost_total,
                $profit,
                $weighted_purchase_price,
                $new_sale_date,
                $new_notes,
                $sale_id
            ]);

            // If batches were used, update batches and create sale_batch_items
            if (!empty($batch_allocations)) {
                $batchUpdateStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity - ? WHERE id = ?");
                $batchItemStmt = $pdo->prepare("INSERT INTO sale_batch_items (sale_id, batch_id, quantity_taken, purchase_price, cost_total) VALUES (?, ?, ?, ?, ?)");
                foreach ($batch_allocations as $alloc) {
                    $batchUpdateStmt->execute([$alloc['quantity'], $alloc['batch_id']]);
                    $batchItemStmt->execute([$sale_id, $alloc['batch_id'], $alloc['quantity'], $alloc['cost_price'], $alloc['quantity'] * $alloc['cost_price']]);
                }
            }

            // ===== المرحلة 5: خصم كمية المنتج =====

            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$new_quantity, $product_id]);

            $pdo->commit();

             // إشعار تعديل عملية بيع
            try {
                $productName = $sale['product_name'];
                $productUnit = $sale['unit'];
                AppNotification::add(NOTIF_SALE, t('notif_title_sale_edited'), t('notif_msg_sale_edited', $productName, $old_quantity, $new_quantity, $productUnit), null, null, 'notif_title_sale_edited', 'notif_msg_sale_edited', ['product' => $productName, 'old_qty' => $old_quantity, 'new_qty' => $new_quantity, 'unit' => $productUnit]);
            } catch (Exception $e) {
                ErrorHandler::logException($e, 'edit-sale::notification');
            }

            header("Location: add-sale.php?msg=sale_edited");
            exit;

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Sale Edit Error: ' . $e->getMessage());
            $errors[] = t('sales_error_updating');
        }
    }
}

// إعادة جلب بيانات المبيعات بعد كل شيء (في حالة الخطأ لضمان البيانات محدثة)
$old_batch_items = [];

// القيم المعروضة في النموذج (القيم المرسلة أو القيم الأصلية)
$form_quantity = isset($_POST['quantity']) ? $_POST['quantity'] : $sale['quantity'];
$form_selling_price = isset($_POST['selling_price']) ? $_POST['selling_price'] : $sale['selling_price'];
$form_sale_date = isset($_POST['sale_date']) ? $_POST['sale_date'] : $sale['sale_date'];
$form_notes = isset($_POST['notes']) ? $_POST['notes'] : ($sale['notes'] ?? '');

$currency = getCurrencySymbol();

require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            <?php echo t('sales_edit_sale_title'); ?>
        </h1>
        <a href="add-sale.php" class="btn btn-outline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?php echo t('back'); ?>
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin: 5px 20px;">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- بطاقة ملخص البيع الحالي -->
    <div class="card" style="border: 2px solid #dbeafe;">
        <div class="card-header" style="background: #eff6ff;">
            <h2 style="color: #1e40af;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/>
                    <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
                </svg>
                بيانات البيع الحالية
            </h2>
        </div>
        <div class="card-body">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.25rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.75rem;">
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">المنتج</div>
                        <div style="font-weight: 700;"><?php echo htmlspecialchars($sale['product_name']); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">الكود</div>
                        <div style="font-weight: 700; font-family: monospace; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; display: inline-block;">
                            <?php echo htmlspecialchars($sale['code']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">الكمية القديمة</div>
                        <div style="font-weight: 700;">
                            <?php echo number_format($sale['quantity'], 2) . ' ' . htmlspecialchars($sale['unit']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">سعر البيع القديم</div>
                        <div style="font-weight: 700;"><?php echo formatMoney($sale['selling_price']); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">الإجمالي القديم</div>
                        <div style="font-weight: 800; font-size: 1.1rem; color: #1e40af;">
                            <?php echo formatMoney($sale['total']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">تكلفة الشراء (FIFO)</div>
                        <div style="font-weight: 700; color: #92400e;">
                            <?php echo formatMoney($sale['cost_total']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">الربح القديم</div>
                        <div style="font-weight: 800; font-size: 1.1rem; color: <?php echo ($sale['profit'] ?? 0) >= 0 ? '#059669' : '#dc2626'; ?>">
                            <?php echo formatMoney($sale['profit']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">التاريخ</div>
                        <div style="font-weight: 700;"><?php echo htmlspecialchars($sale['sale_date']); ?></div>
                    </div>
                    <?php if (!empty($sale['notes'])): ?>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">ملاحظات</div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($sale['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- نموذج التعديل -->
    <div class="card" style="border: 2px solid #fef3c7;">
        <div class="card-header" style="background: #fffbeb;">
            <h2 style="color: #92400e;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                تعديل بيانات البيع
            </h2>
        </div>
        <div class="card-body">

            <!-- تنبيه مهم حول إعادة حساب FIFO -->
            <div class="alert" style="background: #fef3c7; border: 2px solid #fcd34d; color: #92400e; display: flex; align-items: flex-start; gap: 10px; margin-bottom: 1.25rem; padding: 14px 16px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" style="color: #d97706; flex-shrink: 0; margin-top: 2px;">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <div style="font-size: 0.88rem; font-weight: 600; line-height: 1.6;">
                    <strong>تنبيه مهم:</strong> عند تغيير الكمية، سيتم استرجاع المخزون أولاً ثم إعادة تطبيق البيع بالكمية الجديدة. هذا يعني أن
                    <strong>تكلفة الشراء (FIFO/FEFO) سيتم إعادة حسابها بالكامل</strong> وقد تختلف عن التكلفة الأصلية لأن الدفعات المتاحة حالياً قد تكون مختلفة.
                </div>
            </div>

            <!-- تنبيه إعلامي حول FIFO -->
            <div class="alert alert-info" style="margin-bottom: 1.25rem;">
                <strong>ملاحظة:</strong> المنتج لا يمكن تغييره. يمكنك فقط تعديل الكمية، سعر البيع، التاريخ، والملاحظات. المخزون الحالي للمنتج:
                <strong id="currentStockDisplay">
                    <?php
                        // نحسب المخزون الفعلي = المخزون الحالي + كمية البيع القديمة (لأنها ستُسترجع أولاً)
                        $effective_stock = floatval($sale['current_stock']) + floatval($sale['quantity']);
                        echo number_format($effective_stock, 2) . ' ' . htmlspecialchars($sale['unit']);
                    ?>
                </strong>
                (بعد استرجاع الكمية القديمة)
            </div>

            <form method="POST" action="" id="editSaleForm">
                <div class="form-grid">
                    <!-- المنتج (للقراءة فقط) -->
                    <div class="form-group">
                        <label><?php echo t('product'); ?></label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($sale['code'] . ' - ' . $sale['product_name']); ?>" readonly
                               style="background: #f8fafc; color: var(--gray-600); font-weight: 700;">
                    </div>

                    <!-- المخزون المتاح (للقراءة فقط) -->
                    <div class="form-group">
                        <label>المخزون المتاح (بعد الاسترجاع)</label>
                        <input type="text" class="form-control"
                               value="<?php echo number_format($effective_stock, 2) . ' ' . htmlspecialchars($sale['unit']); ?>"
                               readonly style="background: #f0fdf4; color: #059669; font-weight: 700;">
                    </div>

                    <!-- الكمية -->
                    <div class="form-group">
                        <label><?php echo t('quantity'); ?> <span class="required">*</span></label>
                        <input type="number" name="quantity" id="edit_quantity" class="form-control"
                               step="0.01" min="0.01" max="<?php echo number_format($effective_stock, 4, '.', ''); ?>"
                               value="<?php echo htmlspecialchars($form_quantity); ?>" required
                               oninput="calculateEditTotals()">
                        <div class="form-text" id="quantityHint"></div>
                    </div>

                    <!-- سعر البيع -->
                    <div class="form-group">
                        <label><?php echo t('sales_price'); ?> <span class="required">*</span></label>
                        <input type="number" name="selling_price" id="edit_selling_price" class="form-control"
                               step="0.01" min="0.01" value="<?php echo htmlspecialchars($form_selling_price); ?>" required
                               oninput="calculateEditTotals()">
                    </div>

                    <!-- تاريخ البيع -->
                    <div class="form-group">
                        <label><?php echo t('sales_date'); ?> <span class="required">*</span></label>
                        <input type="date" name="sale_date" class="form-control"
                               value="<?php echo htmlspecialchars($form_sale_date); ?>" required>
                    </div>

                    <!-- ملاحظات -->
                    <div class="form-group">
                        <label><?php echo t('notes'); ?></label>
                        <input type="text" name="notes" class="form-control"
                               value="<?php echo htmlspecialchars($form_notes); ?>"
                               placeholder="ملاحظات إضافية (اختياري)">
                    </div>
                </div>

                <!-- ملخص الحساب الجديد -->
                <div style="margin-top: 1.25rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">الإجمالي الجديد</div>
                            <div id="edit_total" style="font-weight: 800; font-size: 1.15rem; color: #1e40af;">
                                <?php echo formatMoney(floatval($form_quantity) * floatval($form_selling_price)); ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;">الفرق عن الإجمالي القديم</div>
                            <div id="edit_total_diff" style="font-weight: 700; font-size: 1rem;">
                                <?php
                                    $old_total = floatval($sale['total']);
                                    $new_total_preview = floatval($form_quantity) * floatval($form_selling_price);
                                    $diff = $new_total_preview - $old_total;
                                    $diff_color = $diff > 0 ? '#059669' : ($diff < 0 ? '#dc2626' : '#6b7280');
                                    $diff_sign = $diff > 0 ? '+' : '';
                                ?>
                                <span style="color: <?php echo $diff_color; ?>;">
                                    <?php echo $diff_sign . formatMoney($diff); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 0.75rem; padding: 8px 12px; background: #fef3c7; border-radius: 6px; font-size: 0.82rem; color: #92400e;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align: middle; margin-left: 4px;">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="16" x2="12" y2="12"/>
                            <line x1="12" y1="8" x2="12.01" y2="8"/>
                        </svg>
                        تكلفة الشراء والربح الفعلي سيتم حسابهما تلقائياً عند الحفظ بناءً على نظام FIFO/FEFO
                    </div>
                </div>

                <!-- أزرار الإجراءات -->
                <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-warning btn-lg" onclick="return confirmEditSale();">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <?php echo t('save'); ?>
                    </button>
                    <a href="add-sale.php" class="btn btn-outline btn-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php echo t('cancel'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
const CURRENCY_SYMBOL = '<?php echo $currency; ?>';
const OLD_TOTAL = <?php echo floatval($sale['total']); ?>;
const AVAILABLE_STOCK = <?php echo $effective_stock; ?>;

function calculateEditTotals() {
    const qty = parseFloat(document.getElementById('edit_quantity').value) || 0;
    const price = parseFloat(document.getElementById('edit_selling_price').value) || 0;
    const total = qty * price;
    const diff = total - OLD_TOTAL;

    document.getElementById('edit_total').textContent = total.toFixed(2) + ' ' + CURRENCY_SYMBOL;

    const diffEl = document.getElementById('edit_total_diff');
    const sign = diff > 0 ? '+' : '';
    diffEl.innerHTML = '<span style="color: ' + (diff > 0 ? '#059669' : (diff < 0 ? '#dc2626' : '#6b7280')) + ';">' + sign + diff.toFixed(2) + ' ' + CURRENCY_SYMBOL + '</span>';

    // تنبيه الكمية
    const hintEl = document.getElementById('quantityHint');
    if (qty > AVAILABLE_STOCK) {
        hintEl.style.color = '#dc2626';
        hintEl.textContent = '⚠️ الكمية تتجاوز المخزون المتوفر (' + AVAILABLE_STOCK.toFixed(2) + ')';
    } else if (qty > 0) {
        hintEl.style.color = '#059669';
        hintEl.textContent = '✓ الكمية متوفرة في المخزون';
    } else {
        hintEl.textContent = '';
    }
}

function confirmEditSale() {
    const qty = parseFloat(document.getElementById('edit_quantity').value) || 0;
    const oldQty = <?php echo floatval($sale['quantity']); ?>;
    const price = parseFloat(document.getElementById('edit_selling_price').value) || 0;
    const oldPrice = <?php echo floatval($sale['selling_price']); ?>;

    if (qty === oldQty && price === oldPrice) {
        return confirm('لم تقم بتغيير الكمية أو سعر البيع.\nهل تريد المتابعة بحفظ التعديل؟');
    }

    if (qty !== oldQty) {
        return confirm('أنت بصدد تغيير الكمية من ' + oldQty.toFixed(2) + ' إلى ' + qty.toFixed(2) + '.\n\nسيتم:\n1. استرجاع الكمية القديمة للمخزون\n2. إعادة حساب التكلفة بنظام FIFO/FEFO\n3. تطبيق البيع الجديد\n\nهل أنت متأكد؟');
    }

    return true;
}

// حساب أولي عند تحميل الصفحة
calculateEditTotals();
</script>

<?php require_once '../includes/footer.php'; ?>
