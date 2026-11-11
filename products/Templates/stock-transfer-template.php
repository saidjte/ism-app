<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <polyline points="17 1 21 5 17 9"/>
                <path d="M3 11V9a4 4 0 014-4h14"/>
                <polyline points="7 23 3 19 7 15"/>
                <path d="M21 13v2a4 4 0 01-4 4H3"/>
            </svg>
            <?php echo t('products_stock_transfer'); ?>
        </h1>
        <a href="../products/list-products.php" class="btn btn-outline"><?php echo t('products_list'); ?></a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success && $transfer_info): ?>
        <div class="alert alert-success" style="font-weight: 700; font-size: 1rem;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" width="20" height="20"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                تم التحويل بنجاح!
            </div>
            <div style="background: rgba(255,255,255,0.5); border-radius: 8px; padding: 10px; margin-top: 6px; font-size: 0.9rem;">
                تحويل <strong><?php echo number_format($transfer_info['quantity'], 2) . ' ' . htmlspecialchars($transfer_info['unit']); ?></strong>
                من <strong><?php echo htmlspecialchars($transfer_info['from_code']) . ' - ' . htmlspecialchars($transfer_info['from_name']); ?></strong>
                إلى <strong><?php echo htmlspecialchars($transfer_info['to_code']) . ' - ' . htmlspecialchars($transfer_info['to_name']); ?></strong>
                <?php if (!empty($transfer_info['notes'])): ?>
                    <br><span style="color: var(--gray-500);">ملاحظات: <?php echo htmlspecialchars($transfer_info['notes']); ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>تحويل كمية بين منتجين</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>ملاحظة:</strong> هذا التحويل ينقل الكمية من مخزون منتج إلى آخر. تأكد من صحة المنتجين قبل التأكيد.
            </div>
            <form method="POST" action="" id="transferForm">
                <div class="form-grid">

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #dc2626;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="16"/>
                                <line x1="8" y1="12" x2="16" y2="12"/>
                            </svg>
                            المنتج المصدر (من) <span class="required">*</span>
                        </label>
                        <select name="from_product_id" id="from_product_id" class="form-control" required onchange="updateFromInfo()" style="border-color: #fca5a5;">
                            <option value="">-- اختر المنتج المصدر --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>"
                                        data-qty="<?php echo $p['quantity']; ?>"
                                        data-unit="<?php echo htmlspecialchars($p['unit']); ?>">
                                    <?php echo htmlspecialchars($p['code'] . ' - ' . $p['name'] . ' (متوفر: ' . number_format($p['quantity'], 2) . ' ' . $p['unit'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="from_stock_info"></div>
                    </div>

                    <div style="grid-column: 1 / -1; text-align: center; padding: 0.5rem 0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2" width="32" height="32" style="vertical-align: middle;">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <polyline points="19 12 12 19 5 12"/>
                        </svg>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #059669;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M8 12h8"/>
                            </svg>
                            المنتج الوجهة (إلى) <span class="required">*</span>
                        </label>
                        <select name="to_product_id" id="to_product_id" class="form-control" required onchange="updateToInfo()" style="border-color: #86efac;">
                            <option value="">-- اختر المنتج الوجهة --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>"
                                        data-qty="<?php echo $p['quantity']; ?>"
                                        data-unit="<?php echo htmlspecialchars($p['unit']); ?>">
                                    <?php echo htmlspecialchars($p['code'] . ' - ' . $p['name'] . ' (متوفر: ' . number_format($p['quantity'], 2) . ' ' . $p['unit'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="to_stock_info"></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('quantity'); ?> <span class="required">*</span></label>
                        <input type="number" name="quantity" id="quantity" class="form-control" step="0.01" min="0.01" required
                               placeholder="0.00" oninput="validateQuantity()"
                               style="font-size: 1.1rem; font-weight: 700; text-align: center;">
                        <div class="form-text" id="quantity_info"></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo t('unit'); ?></label>
                        <input type="text" id="unit_display" class="form-control" readonly
                               value="" style="background: #f8fafc; text-align: center; font-weight: 600;">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label><?php echo t('notes'); ?></label>
                        <input type="text" name="notes" class="form-control" placeholder="<?php echo t('notes'); ?>">
                    </div>
                </div>

                <div style="margin-top: 1.5rem;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-warning btn-lg" id="submitBtn" onclick="return validateForm();">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <polyline points="17 1 21 5 17 9"/>
                            <path d="M3 11V9a4 4 0 014-4h14"/>
                            <polyline points="7 23 3 19 7 15"/>
                            <path d="M21 13v2a4 4 0 01-4 4H3"/>
                        </svg>
                        تنفيذ التحويل
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Transfer summary card -->
    <div class="card" id="previewCard" style="display: none; margin-top: 1rem;">
        <div class="card-header" style="background: #eff6ff;">
            <h2 style="color: #1d4ed8; font-size: 0.95rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                معاينة التحويل
            </h2>
        </div>
        <div class="card-body" style="padding: 1rem;">
            <div id="previewContent" style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: center; text-align: center;"></div>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
function updateFromInfo() {
    const sel = document.getElementById('from_product_id');
    const opt = sel.options[sel.selectedIndex];
    const infoDiv = document.getElementById('from_stock_info');
    const toSelect = document.getElementById('to_product_id');

    if (opt && sel.value) {
        const qty = parseFloat(opt.dataset.qty);
        const unit = opt.dataset.unit;
        infoDiv.innerHTML = '<strong style="color: #dc2626;">' + '<?php echo t('transfer_available_stock'); ?>' + ' ' + qty.toFixed(2) + ' ' + unit + '</strong>';
        document.getElementById('unit_display').value = unit;

        // Remove selected product from destination dropdown
        updatePreview();
    } else {
        infoDiv.innerHTML = '';
    }
}

function updateToInfo() {
    const sel = document.getElementById('to_product_id');
    const opt = sel.options[sel.selectedIndex];
    const infoDiv = document.getElementById('to_stock_info');

    if (opt && sel.value) {
        const qty = parseFloat(opt.dataset.qty);
        const unit = opt.dataset.unit;
        infoDiv.innerHTML = '<strong style="color: #059669;">' + '<?php echo t('transfer_available_stock'); ?>' + ' ' + qty.toFixed(2) + ' ' + unit + '</strong>';
        updatePreview();
    } else {
        infoDiv.innerHTML = '';
    }
}

function validateQuantity() {
    const sel = document.getElementById('from_product_id');
    const opt = sel.options[sel.selectedIndex];
    const qtyInput = document.getElementById('quantity');
    const infoDiv = document.getElementById('quantity_info');
    const btn = document.getElementById('submitBtn');

    if (opt && sel.value) {
        const max = parseFloat(opt.dataset.qty);
        const val = parseFloat(qtyInput.value) || 0;

        if (val > max) {
            infoDiv.innerHTML = '<span style="color: #dc2626; font-weight: 700;">⚠ <?php echo t('transfer_qty_exceeds'); ?> (' + max.toFixed(2) + ')</span>';
            btn.style.opacity = '0.5';
        } else if (val > 0) {
            infoDiv.innerHTML = '<span style="color: #059669;">✓ <?php echo t('transfer_qty_correct'); ?></span>';
            btn.style.opacity = '1';
        } else {
            infoDiv.innerHTML = '';
            btn.style.opacity = '0.5';
        }
        updatePreview();
    }
}

function updatePreview() {
    const card = document.getElementById('previewCard');
    const content = document.getElementById('previewContent');
    const fromSel = document.getElementById('from_product_id');
    const toSel = document.getElementById('to_product_id');
    const qtyInput = document.getElementById('quantity');

    const fromOpt = fromSel.options[fromSel.selectedIndex];
    const toOpt = toSel.options[toSel.selectedIndex];
    const qty = parseFloat(qtyInput.value) || 0;

    if (fromSel.value && toSel.value && fromSel.value !== toSel.value && qty > 0) {
        card.style.display = 'block';
        const fromQty = parseFloat(fromOpt.dataset.qty);
        const toQty = parseFloat(toOpt.dataset.qty);
        const unit = fromOpt.dataset.unit;

        content.innerHTML = `
            <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 0.75rem;">
                <div style="font-size: 0.75rem; color: #dc2626; font-weight: 700;">المصدر</div>
                <div style="font-weight: 700; font-size: 0.9rem; margin: 4px 0;">${fromOpt.textContent.split('(')[0].trim()}</div>
                <div style="font-size: 0.85rem;">${(fromQty - qty).toFixed(2)} / ${fromQty.toFixed(2)} ${unit}</div>
            </div>
            <div style="text-align: center;">
                <div style="background: #dbeafe; color: #1d4ed8; font-weight: 800; font-size: 1.2rem; padding: 0.5rem 1rem; border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                    ${qty.toFixed(2)} ${unit}
                </div>
            </div>
            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 0.75rem;">
                <div style="font-size: 0.75rem; color: #059669; font-weight: 700;">الوجهة</div>
                <div style="font-weight: 700; font-size: 0.9rem; margin: 4px 0;">${toOpt.textContent.split('(')[0].trim()}</div>
                <div style="font-size: 0.85rem;">${(toQty + qty).toFixed(2)} / ${toQty.toFixed(2)} ${unit}</div>
            </div>
        `;
    } else {
        card.style.display = 'none';
    }
}

function validateForm() {
    const from = document.getElementById('from_product_id').value;
    const to = document.getElementById('to_product_id').value;
    const qty = parseFloat(document.getElementById('quantity').value) || 0;

    if (!from || !to) { alert('يرجى اختيار المنتجين'); return false; }
    if (from === to) { alert('لا يمكن التحويل لنفس المنتج'); return false; }
    if (qty <= 0) { alert('يرجى إدخال كمية صحيحة'); return false; }

    const fromSel = document.getElementById('from_product_id');
    const fromOpt = fromSel.options[fromSel.selectedIndex];
    const max = parseFloat(fromOpt.dataset.qty);
    if (qty > max) { alert('الكمية (' + qty.toFixed(2) + ') أكبر من المتوفر (' + max.toFixed(2) + ')'); return false; }

    return confirm('هل أنت متأكد من تحويل ' + qty.toFixed(2) + ' ' + fromOpt.dataset.unit + '؟');
}
</script>