<?php
require_once '../includes/bootstrap.php';
$page_title = t('purchases_edit_title');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('purchases.edit');

// التحقق من المعرف قبل أي شيء
$purchase_id = intval($_GET['id'] ?? 0);
if (!$purchase_id) {
    header("Location: add-purchase.php");
    exit;
}

// جلب بيانات الشراء مع المنتج
$purchase = fetchOne(
    "SELECT p.*, pr.name as product_name, pr.code, pr.unit, pr.has_expiry, pr.selling_price as current_selling_price
     FROM purchases p
     JOIN products pr ON p.product_id = pr.id
     WHERE p.id = ?",
    [$purchase_id]
);

if (!$purchase) {
    header("Location: add-purchase.php");
    exit;
}

// جلب سياق الفاتورة (purchase_order) إن وجد
$order_context = null;
$order_siblings = [];
$order_remises = [];
if (!empty($purchase['order_id'])) {
    $order_id = intval($purchase['order_id']);
    try {
        $order_context = fetchOne("SELECT * FROM purchase_orders WHERE id = ?", [$order_id]);
    } catch (Exception $e) {}
    if ($order_context) {
        try {
            $order_siblings = fetchAll(
                "SELECT p.id, p.product_id, p.quantity, p.purchase_price, p.total, p.tax_amount, p.grand_total,
                        pr.name as product_name, pr.code, pr.unit
                 FROM purchases p
                 JOIN products pr ON p.product_id = pr.id
                 WHERE p.order_id = ?
                 ORDER BY p.id", [$order_id]
            );
        } catch (Exception $e) { $order_siblings = []; }
        try {
            $order_remises = fetchAll(
                "SELECT pr.*, p2.name as remise_product_name, p2.code as remise_product_code
                 FROM purchase_remises pr
                 LEFT JOIN products p2 ON pr.product_id = p2.id
                 WHERE (pr.order_id = ? OR (pr.order_id IS NULL AND pr.purchase_id IN (SELECT id FROM purchases WHERE order_id = ?)))
                 ORDER BY pr.id", [$order_id, $order_id]
            );
        } catch (Exception $e) { $order_remises = []; }
    }
}


// جلب الدفعات المرتبطة
$batches = [];
$total_original = 0;
$total_remaining = 0;
$consumed_quantity = 0;
try {
    $batches = fetchAll(
        "SELECT * FROM purchase_batches WHERE purchase_id = ? ORDER BY id",
        [$purchase_id]
    );
    foreach ($batches as $b) {
        $total_original += floatval($b['original_quantity']);
        $total_remaining += floatval($b['remaining_quantity']);
    }
    $consumed_quantity = $total_original - $total_remaining;
} catch (Exception $e) {
    $batches = [];
}

// جلب التخفيضات المرتبطة
$remises = [];
try {
    $remises = fetchAll(
        "SELECT pr.*, p2.name as remise_product_name, p2.code as remise_product_code
         FROM purchase_remises pr
         LEFT JOIN products p2 ON pr.product_id = p2.id
         WHERE pr.purchase_id = ?
         ORDER BY pr.id",
        [$purchase_id]
    );
} catch (Exception $e) {
    $remises = [];
}

// === معالجة النموذج ===
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $new_quantity = floatval($_POST['quantity'] ?? 0);
    $new_price = floatval($_POST['purchase_price'] ?? 0);
    $new_date = trim($_POST['purchase_date'] ?? '');
    $new_notes = trim($_POST['notes'] ?? '');
    $tax_enabled = !empty($_POST['tax_enabled']);
    $new_tax_name = trim($_POST['tax_name'] ?? '');
    $new_tax_type = trim($_POST['tax_type'] ?? 'percentage');
    $new_tax_value = floatval($_POST['tax_value'] ?? 0);
    $delete_remise_ids = array_map('intval', $_POST['delete_remise_ids'] ?? []);
    $new_remise_amount = floatval($_POST['new_remise_amount'] ?? 0);
    $new_remise_desc = trim($_POST['new_remise_desc'] ?? '');

    // التحقق من البيانات
    if ($new_quantity <= 0) {
        $errors[] = t('common_quantity_must_be_positive');
    }
    if ($new_price <= 0) {
        $errors[] = t('common_purchase_price_must_be_positive');
    }
    if (empty($new_date)) {
        $errors[] = t('purchases_date_required');
    }

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // إعادة جلب بيانات الشراء داخل المعاملة
            $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? FOR UPDATE");
            $stmt->execute([$purchase_id]);
            $old_purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$old_purchase) {
                throw new Exception(t('purchases_product_not_found_id'));
            }

            $old_quantity = floatval($old_purchase['quantity']);
            $old_price = floatval($old_purchase['purchase_price']);
            $old_date = $old_purchase['purchase_date'];
            $old_notes = $old_purchase['notes'];
            $product_id = intval($old_purchase['product_id']);
            $new_total = $new_quantity * $new_price;

            // جلب إجمالي الخصومات المالية الحالية (بعد حذف المحددة)
            $financial_discount = 0;
            try {
                $finRow = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_fin FROM purchase_remises WHERE purchase_id = ? AND type = 'financial'");
                $finRow->execute([$purchase_id]);
                $financial_discount = floatval($finRow->fetch(PDO::FETCH_ASSOC)['total_fin']);
            } catch (\Throwable $e) {}

            // حساب الضريبة
            $new_tax_rate = 0;
            $new_tax_amount = 0;
            $new_tax_name_db = null;
            if ($tax_enabled && $new_tax_value > 0 && !empty($new_tax_name)) {
                $net_total = max(0, $new_total - $financial_discount);
                if ($new_tax_type === 'percentage') {
                    $new_tax_rate = $new_tax_value;
                    $new_tax_amount = $net_total * ($new_tax_value / 100);
                } else {
                    $new_tax_rate = $net_total > 0 ? ($new_tax_value / $net_total) * 100 : 0;
                    $new_tax_amount = $new_tax_value;
                }
                $new_tax_name_db = $new_tax_name;
            }
            $new_grand_total = ($new_total - $financial_discount) + $new_tax_amount;

            $quantity_changed = abs($new_quantity - $old_quantity) > 0.001;

            if ($quantity_changed) {
                $quantity_diff = $new_quantity - $old_quantity;
                $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                    ->execute([$quantity_diff, $product_id]);
            }

            // ─────────────────────────────────────
            // 2. تغيير السعر
            // ─────────────────────────────────────
            $price_changed = abs($new_price - $old_price) > 0.001;

            if ($price_changed) {
                // تحديث سعر شراء المنتج
                $pdo->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")
                    ->execute([$new_price, $product_id]);

                // تسجيل تغيير السعر في السجل
                try {
                    $currentProduct = $pdo->prepare("SELECT purchase_price, selling_price FROM products WHERE id = ?");
                    $currentProduct->execute([$product_id]);
                    $prodPrices = $currentProduct->fetch(PDO::FETCH_ASSOC);

                    $old_selling = floatval($prodPrices['selling_price']);

                    $pdo->prepare(
                        "INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $product_id,
                        $old_price,
                        $new_price,
                        $old_selling,
                        $old_selling,
                        'تعديل عملية شراء #' . $purchase_id . ' — سعر الشراء: ' . number_format($new_price, 2)
                    ]);
                } catch (Exception $e) {
                    // جدول price_history قد لا يكون موجوداً
                    ErrorHandler::logException($e, 'edit-purchase::priceHistory');
                }
            }

            // ─────────────────────────────────────
            // 2.5 حذف/إضافة التخفيضات المالية
            // ─────────────────────────────────────

            // حذف التخفيضات المالية المحددة
            foreach ($delete_remise_ids as $del_id) {
                if ($del_id > 0) {
                    try {
                        $pdo->prepare("DELETE FROM purchase_remises WHERE id = ? AND purchase_id = ? AND type = 'financial'")
                            ->execute([$del_id, $purchase_id]);
                    } catch (\Throwable $e) {}
                }
            }

            // إضافة تخفيض مالي جديد
            if ($new_remise_amount > 0) {
                try {
                    $pdo->prepare("INSERT INTO purchase_remises (purchase_id, type, amount, description) VALUES (?, 'financial', ?, ?)")
                        ->execute([$purchase_id, $new_remise_amount, $new_remise_desc]);
                    $financial_discount += $new_remise_amount;
                    // إعادة حساب grand_total مع الخصم الجديد
                    $net_after_discount = max(0, $new_total - $financial_discount);
                    if ($tax_enabled && $new_tax_value > 0 && !empty($new_tax_name)) {
                        if ($new_tax_type === 'percentage') {
                            $new_tax_amount = $net_after_discount * ($new_tax_value / 100);
                        } else {
                            $new_tax_amount = $new_tax_value;
                        }
                        $new_tax_rate = $net_after_discount > 0 ? ($new_tax_amount / $net_after_discount) * 100 : 0;
                    }
                    $new_grand_total = $net_after_discount + $new_tax_amount;
                } catch (\Throwable $e) {}
            }

            // ─────────────────────────────────────
            // 3. تحديث سجل الشراء الرئيسي
            // ─────────────────────────────────────
            $pdo->prepare(
                "UPDATE purchases SET quantity = ?, purchase_price = ?, total = ?, tax_rate = ?, tax_amount = ?, tax_name = ?, grand_total = ?, purchase_date = ?, notes = ? WHERE id = ?"
            )->execute([$new_quantity, $new_price, $new_total, $new_tax_rate, $new_tax_amount, $new_tax_name_db, $new_grand_total, $new_date, $new_notes, $purchase_id]);

            $pdo->commit();
            $success = true;

            // إشعار تعديل عملية شراء
            try {
                $productName = $purchase['product_name'];
                $title_key = 'notif_title_purchase_edited';
                $message_key = 'notif_msg_purchase_edited';
                $message_params = ['product' => $productName];
                AppNotification::add(NOTIF_PURCHASE, t($title_key), t($message_key, $productName), null, null, $title_key, $message_key, $message_params);
            } catch (Exception $e) {
                ErrorHandler::logException($e, 'edit-purchase::notification');
            }

            // إعادة جلب البيانات المحدّثة للعرض
            $purchase = fetchOne(
                "SELECT p.*, pr.name as product_name, pr.code, pr.unit, pr.has_expiry, pr.selling_price as current_selling_price
                 FROM purchases p
                 JOIN products pr ON p.product_id = pr.id
                 WHERE p.id = ?",
                [$purchase_id]
            );

            // Batch subsystem removed: no batch data to re-fetch.

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Purchase Edit Error: ' . $e->getMessage());
            $errors[] = t('purchases_error_updating');
        }
    }
}

// بعد نجاح التعديل — إعادة التوجيه
if ($success) {
    header("Location: add-purchase.php?msg=purchase_edited");
    exit;
}

require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            <?php echo t('purchases_edit_title'); ?>
        </h1>
        <a href="add-purchase.php" class="btn btn-outline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?php echo t('back'); ?>
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin: 5px 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- بطاقة ملخص الشراء الحالي -->
    <div class="card" style="border: 2px solid #bfdbfe;">
        <div class="card-header" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
            <h2 style="color: #1e40af;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
                </svg>
                بيانات الشراء الحالية
                <span style="font-size: 0.8rem; color: var(--gray-400); font-weight: 400;">#<?php echo $purchase_id; ?></span>
            </h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">المنتج</div>
                    <div style="font-weight: 700;">
                        <span style="font-family: monospace; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 0.85rem; margin-left: 6px;"><?php echo htmlspecialchars($purchase['code']); ?></span>
                        <?php echo htmlspecialchars($purchase['product_name']); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">الكمية الحالية</div>
                    <div style="font-weight: 700;">
                        <?php echo number_format(floatval($purchase['quantity']), 2); ?>
                        <span style="color: var(--gray-400); font-size: 0.85rem;"><?php echo htmlspecialchars($purchase['unit']); ?></span>
                    </div>
                </div>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">سعر الشراء</div>
                    <div style="font-weight: 700;"><?php echo formatMoney($purchase['purchase_price']); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">الإجمالي</div>
                    <div style="font-weight: 800; font-size: 1.1rem; color: #1e40af;"><?php echo formatMoney($purchase['total']); ?></div>
                </div>
                <?php if (floatval($purchase['tax_amount'] ?? 0) > 0): ?>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">
                        الضريبة
                        <?php if (!empty($purchase['tax_name'])): ?>
                            (<?php echo htmlspecialchars($purchase['tax_name']); ?> — <?php echo number_format(floatval($purchase['tax_rate']), 2); ?>%)
                        <?php endif; ?>
                    </div>
                    <div style="font-weight: 700; font-size: 1rem; color: #7c3aed;"><?php echo formatMoney($purchase['tax_amount']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (floatval($purchase['grand_total'] ?? 0) > 0 && floatval($purchase['grand_total']) != floatval($purchase['total'])): ?>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">المبلغ الإجمالي النهائي</div>
                    <div style="font-weight: 800; font-size: 1.15rem; color: #991b1b;"><?php echo formatMoney($purchase['grand_total']); ?></div>
                </div>
                <?php endif; ?>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">تاريخ الشراء</div>
                    <div style="font-weight: 700;"><?php echo htmlspecialchars($purchase['purchase_date']); ?></div>
                </div>
                <?php if (!empty($purchase['notes'])): ?>
                <div>
                    <div style="font-size: 0.78rem; color: var(--gray-400); margin-bottom: 2px;">ملاحظات</div>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($purchase['notes']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($order_context): ?>
    <div class="card" style="margin-top: 1rem; border: 2px solid #6366f1;">
        <div class="card-header" style="background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);">
            <h2 style="color: #4338ca; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                سياق الفاتورة
                <span style="font-size: 0.8rem; color: #6366f1; font-weight: 400; background: #e0e7ff; padding: 2px 10px; border-radius: 12px;">#<?php echo $order_context['id']; ?></span>
            </h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin-bottom: 1rem;">
                <div style="background: #f5f3ff; border: 1px solid #c4b5fd; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #6d28d9;">إجمالي الفاتورة (قبل الخصم)</div>
                    <div style="font-weight: 800; font-size: 1.05rem; color: #4338ca;"><?php echo formatMoney($order_context['total']); ?></div>
                </div>
                <?php if (floatval($order_context['financial_discount'] ?? 0) > 0): ?>
                <div style="background: #fffbeb; border: 1px solid #fcd34d; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #b45309;">التخفيض المالي</div>
                    <div style="font-weight: 800; font-size: 1.05rem; color: #d97706;">-<?php echo formatMoney($order_context['financial_discount']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (floatval($order_context['tax_amount'] ?? 0) > 0): ?>
                <div style="background: #faf5ff; border: 1px solid #d8b4fe; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #7c3aed;">الضريبة<?php if (!empty($order_context['tax_name'])): ?> (<?php echo htmlspecialchars($order_context['tax_name']); ?>)<?php endif; ?></div>
                    <div style="font-weight: 800; font-size: 1.05rem; color: #7c3aed;"><?php echo formatMoney($order_context['tax_amount']); ?></div>
                </div>
                <?php endif; ?>
                <div style="background: #eff6ff; border: 1px solid #93c5fd; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #1d4ed8;">المبلغ الإجمالي النهائي</div>
                    <div style="font-weight: 900; font-size: 1.15rem; color: #1e40af;"><?php echo formatMoney($order_context['grand_total']); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--gray-400); margin-bottom: 2px;">تاريخ الفاتورة</div>
                    <div style="font-weight: 700;"><?php echo htmlspecialchars($order_context['purchase_date']); ?></div>
                </div>
                <?php if (!empty($order_context['notes'])): ?>
                <div>
                    <div style="font-size: 0.75rem; color: var(--gray-400); margin-bottom: 2px;">ملاحظات الفاتورة</div>
                    <div style="font-weight: 600;"><?php echo htmlspecialchars($order_context['notes']); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (count($order_siblings) > 1): ?>
            <div style="margin-bottom: 0.75rem;">
                <div style="font-size: 0.85rem; font-weight: 700; color: var(--gray-600); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 6px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    منتجات هذه الفاتورة (<?php echo count($order_siblings); ?> منتج)
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>#</th><th>الكود</th><th>المنتج</th><th>الكمية</th><th>سعر الشراء</th><th>الإجمالي</th></tr></thead>
                        <tbody>
                            <?php foreach ($order_siblings as $si => $sib): ?>
                            <tr style="<?php echo ($sib['id'] == $purchase_id) ? 'background: #fef9c3; font-weight: 700;' : ''; ?>">
                                <td><?php echo $si + 1; ?></td>
                                <td><span style="font-family: monospace; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 0.82rem;"><?php echo htmlspecialchars($sib['code']); ?></span></td>
                                <td><?php echo htmlspecialchars($sib['product_name']); ?><?php if ($sib['id'] == $purchase_id): ?><span style="background: #fbbf24; color: #78350f; font-size: 0.7rem; padding: 1px 8px; border-radius: 10px; margin-right: 6px;">المنتج الحالي</span><?php endif; ?></td>
                                <td><?php echo number_format(floatval($sib['quantity']), 2); ?> <?php echo htmlspecialchars($sib['unit']); ?></td>
                                <td><?php echo formatMoney($sib['purchase_price']); ?></td>
                                <td style="font-weight: 700;"><?php echo formatMoney($sib['total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php
            $order_financial_remises = array_filter($order_remises, fn($r) => $r['type'] === 'financial');
            $order_material_remises = array_filter($order_remises, fn($r) => $r['type'] === 'material');
            ?>
            <?php if (!empty($order_financial_remises) || !empty($order_material_remises)): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem 1rem;">
                <div style="font-size: 0.82rem; font-weight: 700; color: var(--gray-600); margin-bottom: 0.4rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="15" height="15" style="vertical-align: middle; margin-left: 4px;"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    تخفيضات عامة على الفاتورة
                </div>
                <?php foreach ($order_financial_remises as $or): ?>
                <div style="font-size: 0.8rem; padding: 2px 0;">
                    <span style="background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 6px; font-weight: 600;">💰 <?php echo formatMoney(floatval($or['amount'])); ?><?php if (!empty($or['description'])): ?> <span style="color: var(--gray-400);">(<?php echo htmlspecialchars($or['description']); ?>)</span><?php endif; ?></span>
                </div>
                <?php endforeach; ?>
                <?php foreach ($order_material_remises as $or): ?>
                <div style="font-size: 0.8rem; padding: 2px 0;">
                    <span style="background: #f0fdf4; color: #059669; padding: 2px 8px; border-radius: 6px; font-weight: 600;">🎁 تخفيض مادي: <?php echo number_format(floatval($or['quantity']), 2); ?> وحدة <?php echo htmlspecialchars($or['remise_product_name'] ?? ''); ?><?php if (!empty($or['description'])): ?> <span style="color: var(--gray-400);">(<?php echo htmlspecialchars($or['description']); ?>)</span><?php endif; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- === قسم الدفعات (Batches) === -->
    <?php if (!empty($batches)): ?>
    <div class="card" style="margin-top: 1rem; border: 2px solid #93c5fd;">
        <div class="card-header" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
            <h2 style="color: #1e40af;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
                </svg>
                الدفعات (Batches)
                <span style="font-size: 0.8rem; color: var(--gray-400); font-weight: 400;"><?php echo count($batches); ?> دفعة</span>
            </h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 1rem;">
                <div style="background: #f0fdf4; border: 1px solid #86efac; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #059669;">الكمية الأصلية</div>
                    <div style="font-weight: 800; font-size: 1.1rem; color: #059669;"><?php echo number_format($total_original, 2); ?> <?php echo htmlspecialchars($purchase['unit']); ?></div>
                </div>
                <div style="background: #fef3c7; border: 1px solid #fcd34d; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #d97706;">المباع</div>
                    <div style="font-weight: 800; font-size: 1.1rem; color: #d97706;"><?php echo number_format($consumed_quantity, 2); ?> <?php echo htmlspecialchars($purchase['unit']); ?></div>
                </div>
                <div style="background: #eff6ff; border: 1px solid #93c5fd; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: #2563eb;">المتبقي</div>
                    <div style="font-weight: 800; font-size: 1.1rem; color: #2563eb;"><?php echo number_format($total_remaining, 2); ?> <?php echo htmlspecialchars($purchase['unit']); ?></div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكمية الأصلية</th>
                            <th>المتبقي</th>
                            <th>سعر الشراء</th>
                            <th>تاريخ الانتهاء</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $i => $batch): ?>
                        <tr id="batch_row_<?php echo $batch['id']; ?>">
                            <td><?php echo $i + 1; ?></td>
                            <td style="font-weight: 700;"><?php echo number_format(floatval($batch['original_quantity']), 2); ?></td>
                            <td>
                                <span style="font-weight: 700; color: <?php echo floatval($batch['remaining_quantity']) > 0 ? '#059669' : '#dc2626'; ?>;">
                                    <?php echo number_format(floatval($batch['remaining_quantity']), 2); ?>
                                </span>
                            </td>
                            <td><?php echo formatMoney(floatval($batch['purchase_price'])); ?></td>
                            <td>
                                <?php if (!empty($batch['expiry_date'])): ?>
                                    <span style="font-family: monospace; background: <?php echo (strtotime($batch['expiry_date']) < time()) ? '#fee2e2' : '#f0fdf4'; ?>; padding: 2px 8px; border-radius: 4px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($batch['expiry_date']); ?>
                                        <?php if (strtotime($batch['expiry_date']) < time()): ?>
                                            <span style="color: #dc2626; font-weight: 700;"> (منتهي)</span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <?php if (floatval($batch['remaining_quantity']) == floatval($batch['original_quantity'])): ?>
                                <button type="button" onclick="deleteBatch(<?php echo $batch['id']; ?>, <?php echo $purchase_id; ?>, this)"
                                        style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"
                                        onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    حذف
                                </button>
                                <?php else: ?>
                                <span style="font-size: 0.75rem; color: var(--gray-400);">تم البيع جزئياً</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-top: 1rem;">
        <div class="card-header" style="background: linear-gradient(135deg, #fefce8 0%, #fef3c7 100%);">
            <h2 style="color: #92400e;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                تعديل تفاصيل الشراء
            </h2>
        </div>
        <div class="card-body">
            <!-- تحذيرات -->
            <div class="alert alert-info" style="font-size: 0.85rem; margin-bottom: 1rem;">
                <strong>ملاحظة:</strong> يمكنك تعديل السعر والتاريخ والملاحظات في أي وقت.
            </div>

            <div class="alert alert-info" style="font-size: 0.85rem; margin-bottom: 1rem;">
                يمكنك تعديل الكمية والسعر والتاريخ والملاحظات دون الاعتماد على نظام الدفعات القديم.
            </div>

            <!-- التخفيضات المرتبطة -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; margin-bottom: 1rem;">
                <div style="font-size: 0.85rem; font-weight: 700; color: var(--gray-600); margin-bottom: 0.5rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="16" height="16" style="vertical-align: middle; margin-left: 4px;">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                    </svg>
                    إدارة التخفيضات
                </div>

                <!-- التخفيضات المادية (للعرض فقط) -->
                <?php
                $material_remises = array_filter($remises, fn($r) => $r['type'] === 'material');
                $financial_remises = array_filter($remises, fn($r) => $r['type'] === 'financial');
                ?>
                <?php if (!empty($material_remises)): ?>
                <div style="margin-bottom: 0.5rem;">
                    <?php foreach ($material_remises as $remise): ?>
                    <div style="font-size: 0.82rem; padding: 4px 0;">
                        <span style="background: #f0fdf4; color: #059669; padding: 2px 8px; border-radius: 6px; font-weight: 600;">
                            🎁 تخفيض مادي: <?php echo number_format(floatval($remise['quantity']), 2); ?> وحدة
                            <?php echo htmlspecialchars($remise['remise_product_name'] ?? ''); ?>
                            <?php if (!empty($remise['description'])): ?>
                                <span style="color: var(--gray-400);">(<?php echo htmlspecialchars($remise['description']); ?>)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- التخفيضات المالية (قابلة للحذف) -->
                <?php if (!empty($financial_remises)): ?>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.78rem; color: var(--gray-500); margin-bottom: 4px;">التخفيضات المالية الحالية (حدد للحذف):</div>
                    <?php foreach ($financial_remises as $remise): ?>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.82rem; padding: 3px 0; cursor: pointer;">
                        <input type="checkbox" name="delete_remise_ids[]" value="<?php echo intval($remise['id']); ?>"
                               style="accent-color: #dc2626; width: 16px; height: 16px;"
                               onchange="calculateTotal()">
                        <span style="background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 6px; font-weight: 600;">
                            💰 <?php echo formatMoney(floatval($remise['amount'])); ?>
                            <?php if (!empty($remise['description'])): ?>
                                <span style="color: var(--gray-400);">(<?php echo htmlspecialchars($remise['description']); ?>)</span>
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- إضافة تخفيض مالي جديد -->
                <div style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 140px;">
                        <label style="font-size: 0.78rem; color: var(--gray-500); display: block; margin-bottom: 2px;">مبلغ الخصم الجديد</label>
                        <input type="number" name="new_remise_amount" id="new_remise_amount" class="form-control" step="0.01" min="0"
                               value="0" placeholder="0.00" style="font-size: 0.85rem;"
                               oninput="calculateTotal()">
                    </div>
                    <div style="flex: 1.5; min-width: 180px;">
                        <label style="font-size: 0.78rem; color: var(--gray-500); display: block; margin-bottom: 2px;">وصف الخصم</label>
                        <input type="text" name="new_remise_desc" class="form-control" placeholder="اختياري" style="font-size: 0.85rem;">
                    </div>
                </div>
            </div>

            <form method="POST" action="" id="editPurchaseForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label><?php echo t('quantity'); ?> <span class="required">*</span></label>
                        <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" required
                               value="<?php echo number_format(floatval($purchase['quantity']), 2, '.', ''); ?>"
                               oninput="calculateTotal()">
                        <div class="form-text"><?php echo htmlspecialchars($purchase['unit']); ?></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('purchases_price'); ?> <span class="required">*</span></label>
                        <input type="number" name="purchase_price" id="purchase_price" class="form-control" step="0.01" min="0.01" required
                               value="<?php echo number_format(floatval($purchase['purchase_price']), 2, '.', ''); ?>"
                               oninput="calculateTotal()">
                    </div>

                    <div class="form-group">
                        <label><?php echo t('purchases_date'); ?> <span class="required">*</span></label>
                        <input type="date" name="purchase_date" class="form-control" value="<?php echo htmlspecialchars($purchase['purchase_date']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('total'); ?></label>
                        <input type="text" id="total_display" class="form-control" readonly
                               style="background: #f8fafc; font-weight: 700; font-size: 1.1rem; cursor: default;"
                               value="<?php echo formatMoney(floatval($purchase['quantity']) * floatval($purchase['purchase_price'])); ?>">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label><?php echo t('notes'); ?></label>
                        <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars($purchase['notes'] ?? ''); ?>" placeholder="ملاحظات إضافية (اختياري)">
                    </div>

                    <!-- قسم الضريبة -->
                    <div class="form-group" style="grid-column: 1 / -1; background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 10px; padding: 0.75rem 1rem; margin-top: 0.25rem;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.5rem;">
                            <label style="font-size: 0.85rem; font-weight: 700; color: #7c3aed; margin: 0; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                <input type="checkbox" name="tax_enabled" id="tax_enabled" value="1"
                                       <?php echo (!empty($purchase['tax_name']) && floatval($purchase['tax_amount'] ?? 0) > 0) ? 'checked' : ''; ?>
                                       onchange="toggleTaxSection(); calculateTotal();"
                                       style="accent-color: #7c3aed; width: 16px; height: 16px;">
                                تفعيل الضريبة
                            </label>
                        </div>
                        <div id="tax_section" style="display: <?php echo (!empty($purchase['tax_name']) && floatval($purchase['tax_amount'] ?? 0) > 0) ? 'grid' : 'none'; ?>; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; align-items: flex-end;">
                            <div>
                                <label style="font-size: 0.78rem; color: var(--gray-500); display: block; margin-bottom: 2px;">اسم الضريبة</label>
                                <input type="text" name="tax_name" id="tax_name" class="form-control"
                                       value="<?php echo htmlspecialchars($purchase['tax_name'] ?? 'TVA'); ?>"
                                       placeholder="مثال: TVA" style="font-size: 0.85rem;"
                                       oninput="calculateTotal()">
                            </div>
                            <div>
                                <label style="font-size: 0.78rem; color: var(--gray-500); display: block; margin-bottom: 2px;">نوع الضريبة</label>
                                <select name="tax_type" id="tax_type" class="form-control" style="font-size: 0.85rem;" onchange="calculateTotal()">
                                    <option value="percentage" <?php echo (floatval($purchase['tax_rate'] ?? 0) <= 100) ? 'selected' : ''; ?>>نسبة مئوية (%)</option>
                                    <option value="fixed" <?php echo (floatval($purchase['tax_rate'] ?? 0) > 100) ? 'selected' : ''; ?>>مبلغ ثابت</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.78rem; color: var(--gray-500); display: block; margin-bottom: 2px;">القيمة</label>
                                <input type="number" name="tax_value" id="tax_value" class="form-control" step="0.01" min="0"
                                       value="<?php echo number_format(floatval($purchase['tax_rate'] ?? 0) <= 100 ? floatval($purchase['tax_rate'] ?? 0) : floatval($purchase['tax_amount'] ?? 0), 2, '.', ''); ?>"
                                       placeholder="0.00" style="font-size: 0.85rem;"
                                       oninput="calculateTotal()">
                            </div>
                        </div>
                    </div>

                    <!-- معاينة المبلغ الإجمالي النهائي -->
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #991b1b;">المبلغ الإجمالي النهائي (المعاينة)</label>
                        <input type="text" id="grand_total_display" class="form-control" readonly
                               style="background: #fef2f2; font-weight: 800; font-size: 1.15rem; color: #991b1b; cursor: default; border-color: #fca5a5;"
                               value="">
                    </div>
                </div>

                <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-secondary btn-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                        <?php echo t('save'); ?>
                    </button>
                    <a href="add-purchase.php" class="btn btn-outline btn-lg" style="display: inline-flex; align-items: center; gap: 6px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php echo t('cancel'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();
});

function calculateTotal() {
    const qtyInput = document.getElementById('quantity');
    const priceInput = document.getElementById('purchase_price');
    const totalDisplay = document.getElementById('total_display');
    const grandTotalDisplay = document.getElementById('grand_total_display');

    if (!qtyInput || !priceInput || !totalDisplay) return;

    const qty = parseFloat(qtyInput.value) || 0;
    const price = parseFloat(priceInput.value) || 0;
    const total = qty * price;

    const currency = '<?php echo getCurrencySymbol(); ?>';
    totalDisplay.value = total.toLocaleString('ar-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;

    // حساب الخصومات المالية
    let financialDiscount = 0;
    // المبالغ المحددة للحذف
    document.querySelectorAll('input[name="delete_remise_ids[]"]:checked').forEach(function(cb) {
        const amountText = cb.parentElement.querySelector('span');
        if (amountText) {
            const match = amountText.textContent.match(/[\d\s.,]+/);
            if (match) {
                financialDiscount += parseFloat(match[0].replace(/[\s,]/g, '')) || 0;
            }
        }
    });

    // مبلغ الخصم الجديد
    const newRemiseInput = document.getElementById('new_remise_amount');
    if (newRemiseInput) {
        financialDiscount += parseFloat(newRemiseInput.value) || 0;
    }

    // حساب الضريبة
    let taxAmount = 0;
    const taxEnabled = document.getElementById('tax_enabled');
    if (taxEnabled && taxEnabled.checked) {
        const taxName = (document.getElementById('tax_name')?.value || '').trim();
        const taxType = document.getElementById('tax_type')?.value || 'percentage';
        const taxValue = parseFloat(document.getElementById('tax_value')?.value) || 0;

        if (taxValue > 0 && taxName) {
            const netTotal = Math.max(0, total - financialDiscount);
            if (taxType === 'percentage') {
                taxAmount = netTotal * (taxValue / 100);
            } else {
                taxAmount = taxValue;
            }
        }
    }

    // حساب المبلغ الإجمالي النهائي
    const grandTotal = (total - financialDiscount) + taxAmount;
    if (grandTotalDisplay) {
        grandTotalDisplay.value = grandTotal.toLocaleString('ar-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;
    }
}

function toggleTaxSection() {
    const taxEnabled = document.getElementById('tax_enabled');
    const taxSection = document.getElementById('tax_section');
    if (taxEnabled && taxSection) {
        taxSection.style.display = taxEnabled.checked ? 'grid' : 'none';
    }
}

function deleteBatch(batchId, purchaseId, btn) {
    if (!confirm('هل أنت متأكد من حذف هذه الدفعة؟\nسيتم خصم الكمية من المخزون.')) return;
    btn.disabled = true;
    btn.textContent = 'جاري الحذف...';

    const formData = new FormData();
    formData.append('batch_id', batchId);
    formData.append('purchase_id', purchaseId);
    formData.append('csrf_token', <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>);

    fetch('delete-batch.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('batch_row_' + batchId);
                if (row) row.remove();
                const tbody = document.querySelector('.table tbody');
                if (tbody && tbody.children.length === 0) {
                    const batchCard = tbody.closest('.card');
                    if (batchCard) batchCard.remove();
                }
            } else {
                alert(data.message || 'فشل حذف الدفعة');
                btn.disabled = false;
                btn.textContent = 'حذف';
            }
        })
        .catch(() => {
            alert('خطأ في الاتصال');
            btn.disabled = false;
            btn.textContent = 'حذف';
        });
}
</script>

<?php require_once '../includes/footer.php'; ?>
