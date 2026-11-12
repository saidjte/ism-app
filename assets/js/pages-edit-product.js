/* ============================================================================
 * pages-edit-product.js — منطق الصفحة (مستخرج من products/edit-product.php في المرحلة 3 — M3)
 * الإصدار: v=6.3.5
 * يُحمَّل بعد سكربت الجسر المضمّن في edit-product.php (الثوابت + كائن PTXT).
 * ============================================================================ */

// === بيانات الوحدات (من PHP) ===

// === القيم الأصلية للأسعار ===

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
var editExpiryCounter = 0;
var newExpiryCounter = 0;

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
