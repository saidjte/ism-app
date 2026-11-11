<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            جرد المخزون
        </h1>
        <span style="font-size:0.85rem; color:var(--gray-500);">عدد المنتجات: <?php echo count($products); ?></span>
    </div>

    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($err); ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if ($last_inventory): ?>
    <div class="card" style="margin-bottom: 1rem; border-right: 4px solid #6366f1;">
        <div class="card-body" style="padding: 12px 16px;">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <span style="font-size:0.85rem; color:var(--gray-600);">
                    📋 آخر جرد: <strong><?php echo $last_inventory['count_date']; ?></strong>
                </span>
                <span style="font-size:0.85rem; color:var(--gray-500);">
                    | عدد الأصناف: <strong><?php echo $last_inventory['total_items']; ?></strong>
                </span>
                <span style="font-size:0.85rem; color:var(--gray-500);">
                    | إجمالي الفروقات: <strong style="color:#d97706;"><?php echo number_format($last_inventory['total_diff'], 2); ?></strong>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($products)): ?>
    <form method="POST" id="inventoryForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="save_inventory" value="1">

        <div class="card">
            <div class="card-header">
                <h2>المنتجات - إدخال الكميات الفعلية</h2>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-size:0.85rem; color:var(--gray-600); font-weight:600;">تاريخ الجرد:</label>
                        <input type="date" name="count_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" style="padding:4px 8px; font-size:0.85rem;">
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-size:0.85rem; color:var(--gray-600); font-weight:600;">ملاحظات:</label>
                        <input type="text" name="notes" placeholder="ملاحظات اختيارية..." class="form-control" style="max-width:200px; padding:4px 8px; font-size:0.85rem;">
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- ملخص مباشر -->
                <div id="inventorySummary" style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap; padding:10px 14px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="font-size:0.85rem; color:var(--gray-600);">
                        إجمالي المنتجات: <strong id="totalItems"><?php echo count($products); ?></strong>
                    </div>
                    <div style="font-size:0.85rem; color:var(--gray-600);">
                        مطابق (فرق = 0): <strong id="matchedCount" style="color:#059669;">0</strong>
                    </div>
                    <div style="font-size:0.85rem; color:var(--gray-600);">
                        عجز (فرق < 0): <strong id="deficitCount" style="color:#dc2626;">0</strong>
                    </div>
                    <div style="font-size:0.85rem; color:var(--gray-600);">
                        زيادة (فرق > 0): <strong id="surplusCount" style="color:#d97706;">0</strong>
                    </div>
                    <div style="font-size:0.85rem; color:var(--gray-600);">
                        إجمالي الفرق: <strong id="totalDifference" style="color:#6366f1;">0</strong>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>الكود</th>
                                <th>اسم المنتج</th>
                                <th>الوحدة</th>
                                <th style="width:120px; text-align:center;">كمية النظام</th>
                                <th style="width:140px; text-align:center;">الكمية الفعلية</th>
                                <th style="width:100px; text-align:center;">الفرق</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; foreach ($products as $product):
                                $system_qty = floatval($product['quantity']);
                            ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <input type="hidden" name="products[]" value="<?php echo $product['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($product['code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo htmlspecialchars($product['unit']); ?></td>
                                <td style="text-align:center; font-weight:600;" class="system-qty" id="sys_<?php echo $product['id']; ?>">
                                    <?php echo number_format($system_qty, 2); ?>
                                </td>
                                <td style="text-align:center;">
                                    <input type="number"
                                           name="actual_qty[]"
                                           class="actual-qty-input"
                                           data-product-id="<?php echo $product['id']; ?>"
                                           data-system-qty="<?php echo $system_qty; ?>"
                                           value=""
                                           placeholder="<?php echo number_format($system_qty, 2); ?>"
                                           step="0.01"
                                           min="0"
                                           style="width:100px; padding:6px 8px; border:1.5px solid #e2e8f0; border-radius:6px; font-size:0.85rem; text-align:center; font-weight:600;"
                                           oninput="updateInventorySummary()">
                                </td>
                                <td style="text-align:center; font-weight:700;" id="diff_<?php echo $product['id']; ?>">
                                    <span style="color:#94a3b8;">-</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 32px; font-size:1rem;" onclick="return confirm('هل أنت متأكد من حفظ الجرد؟ سيتم تحديث كميات المنتجات إلى الكميات الفعلية.')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        حفظ الجرد
                    </button>
                    <button type="button" class="btn btn-outline" onclick="fillWithSystemQtys()">
                        تعبئة بكميات النظام
                    </button>
                    <button type="button" class="btn btn-outline" onclick="clearAllQtys()">
                        مسح الكل
                    </button>
                </div>
            </div>
        </div>
    </form>
    <?php else: ?>
    <div class="card">
        <div class="card-body" style="text-align:center; padding:3rem;">
            <p class="text-muted">لا توجد منتجات. <a href="add-product.php">أضف منتجك الأول</a></p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
function updateInventorySummary() {
    const inputs = document.querySelectorAll('.actual-qty-input');
    let matched = 0, deficit = 0, surplus = 0, totalDiff = 0;

    inputs.forEach(input => {
        const pid = input.dataset.productId;
        const sysQty = parseFloat(input.dataset.systemQty);
        const actualVal = input.value.trim();
        const diffEl = document.getElementById('diff_' + pid);

        if (actualVal === '') {
            diffEl.innerHTML = '<span style="color:#94a3b8;">-</span>';
            input.style.borderColor = '#e2e8f0';
            return;
        }

        const actualQty = parseFloat(actualVal);
        const diff = actualQty - sysQty;

        if (diff === 0) {
            diffEl.innerHTML = '<span style="color:#059669;">0</span>';
            input.style.borderColor = '#059669';
            matched++;
        } else if (diff < 0) {
            diffEl.innerHTML = '<span style="color:#dc2626;">' + diff.toFixed(2) + '</span>';
            input.style.borderColor = '#dc2626';
            deficit++;
        } else {
            diffEl.innerHTML = '<span style="color:#d97706;">+' + diff.toFixed(2) + '</span>';
            input.style.borderColor = '#d97706';
            surplus++;
        }

        totalDiff += diff;
    });

    document.getElementById('matchedCount').textContent = matched;
    document.getElementById('deficitCount').textContent = deficit;
    document.getElementById('surplusCount').textContent = surplus;
    document.getElementById('totalDifference').textContent = totalDiff.toFixed(2);

    const totalDiffEl = document.getElementById('totalDifference');
    if (totalDiff < 0) totalDiffEl.style.color = '#dc2626';
    else if (totalDiff > 0) totalDiffEl.style.color = '#d97706';
    else totalDiffEl.style.color = '#059669';
}

function fillWithSystemQtys() {
    document.querySelectorAll('.actual-qty-input').forEach(input => {
        input.value = input.dataset.systemQty;
    });
    updateInventorySummary();
}

function clearAllQtys() {
    document.querySelectorAll('.actual-qty-input').forEach(input => {
        input.value = '';
        input.style.borderColor = '#e2e8f0';
    });
    updateInventorySummary();
}
</script>