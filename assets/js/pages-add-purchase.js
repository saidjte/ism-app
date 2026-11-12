/* ============================================================================
 * pages-add-purchase.js — منطق الصفحة (مستخرج من purchases/add-purchase.php في المرحلة 3 — M3)
 * الإصدار: v=6.3.5
 * يُحمَّل بعد سكربت الجسر المضمّن في add-purchase.php (الثوابت + كائن PTXT).
 * ============================================================================ */

let productRowCounter = 0;
let remiseCounter = 0;

// بيانات صف المنتجات
let productRowsData = {};

// حالة البحث لكل صف
let rowSearchTimeouts = {};
let rowSearchResults = {};
let rowHighlightedIndex = {};
let rowDropdownOpen = {};

// حالة التخفيض

// === كشف المنتج المكرر ===
function isDuplicateProduct(productId, currentIdx) {
    for (const [idx, state] of Object.entries(productRowsData)) {
        if (parseInt(idx) === currentIdx) continue;
        if (state.id === productId) return parseInt(idx);
    }
    return null;
}

function getRowPosition(idx) {
    const container = document.getElementById('productRows');
    const rows = container.querySelectorAll('.product-row');
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].dataset.idx === String(idx)) return i;
    }
    return -1;
}
let remiseSearchTimeouts = {};
let remiseHighlightedIndex = {};
let remiseDropdownOpen = {};

// حالة تخفيضات المنتجات (لكل منتج)
let prodRemiseCounters = {};
let prmSearchTimeouts = {};
let prmHighlightedIndex = {};
let prmDropdownOpen = {};

// ============================================================
// === إضافة صف منتج جديد ===
// ============================================================
function addProductRow() {
    productRowCounter++;
    const idx = productRowCounter;
    productRowsData[idx] = { id: null, name: '', code: '', unit: '', basePrice: 0, hasExpiry: 0, factor: 1, unitName: '' };

    const container = document.getElementById('productRows');
    const row = document.createElement('div');
    row.id = 'productRow_' + idx;
    row.className = 'product-row';

    row.innerHTML = `
        <div class="product-row-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="product-row-number" id="rowNum_${idx}">منتج #1</span>
                <button type="button" onclick="toggleProdRemise(${idx})" 
                        style="background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; padding: 3px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.75rem; display: flex; align-items: center; gap: 4px;" title="تخفيضات هذا المنتج">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                    </svg>
                    تخفيضات
                </button>
            </div>
            <button type="button" class="product-row-remove" onclick="removeProductRow(${idx})" title="حذف المنتج">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                حذف
            </button>
        </div>

        <!-- حقل البحث عن المنتج -->
        <div class="product-row-grid">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>المنتج <span class="required">*</span></label>
                <div class="product-search-wrapper" id="prodSearchWrapper_${idx}">
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="prod_search_${idx}" class="form-control search-input" 
                               placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openRowSearch(${idx})" oninput="searchRowProduct(${idx})" onkeydown="handleRowKeydown(event, ${idx})">
                        <button type="button" class="search-clear-btn" id="prod_clear_${idx}" onclick="clearRowProduct(${idx})" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                        <button type="button" class="search-arrow-btn" id="prod_arrow_${idx}" onclick="toggleRowSearch(${idx})" style="display:none;">
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
                    <div class="selected-product" id="prod_selected_${idx}" style="display:none;">
                        <div class="selected-product-info">
                            <span class="selected-product-code" id="prod_sel_code_${idx}"></span>
                            <span class="selected-product-name" id="prod_sel_name_${idx}"></span>
                            <span class="selected-product-stock" id="prod_sel_stock_${idx}"></span>
                        </div>
                        <button type="button" class="selected-product-remove" onclick="clearRowProduct(${idx})" title="إزالة المنتج">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="search-dropdown" id="prod_dropdown_${idx}" style="display:none;">
                        <div class="search-results" id="prod_results_${idx}">
                            <div class="search-loading" id="prod_loading_${idx}" style="display:none;"><div class="spinner"></div>جاري البحث...</div>
                            <div class="search-no-results" id="prod_noresults_${idx}" style="display:none;">لا توجد نتائج مطابقة</div>
                            <div class="search-hint" id="prod_hint_${idx}">اكتب للبحث عن منتج (كود أو اسم)</div>
                        </div>
                    </div>
                    <input type="hidden" name="product_ids[${idx}]" id="prod_id_${idx}" value="">
                    <input type="hidden" id="prod_base_price_${idx}" value="">
                    <input type="hidden" id="prod_unit_name_${idx}" value="">
                </div>
            </div>
        </div>

        <!-- حقول الكمية والسعر والوحدة -->
        <div class="product-row-fields" id="prod_fields_${idx}" style="display:none;">
            <div class="form-group">
                <label>الكمية <span class="required">*</span></label>
                <input type="number" name="quantities[${idx}]" id="prod_qty_${idx}" class="form-control" step="0.01" min="0.01" value="" placeholder="الكمية" oninput="calculateLineTotal(${idx})" style="font-weight: 600;">
                <div class="form-text" id="prod_unit_label_${idx}"></div>
            </div>
            <div class="form-group" id="prod_unit_group_${idx}" style="display:none;">
                <label>وحدة الشراء</label>
                <select id="prod_unit_select_${idx}" class="form-control" onchange="onRowUnitChange(${idx})">
                    <option value="base">الوحدة الأساسية</option>
                </select>
                <div class="form-text" id="prod_unit_info_${idx}"></div>
            </div>
            <div class="form-group">
                <label>سعر الشراء للوحدة <span class="required">*</span></label>
                <input type="number" name="purchase_prices[${idx}]" id="prod_price_${idx}" class="form-control" step="0.01" min="0.01" value="" placeholder="0.00" oninput="calculateLineTotal(${idx})" style="font-weight: 600;">
            </div>
        </div>

        <!-- حقول خفية -->
        <input type="hidden" name="purchase_unit_factors[${idx}]" id="prod_factor_${idx}" value="1">

        <!-- إجمالي الصف -->
        <div class="product-row-summary" id="prod_summary_${idx}" style="display:none;">
            <div class="form-group">
                <label>إجمالي الصف</label>
                <input type="text" id="prod_line_total_${idx}" class="form-control purchase-line-total-input" value="0.00 ${CURRENCY_SYMBOL}" readonly>
            </div>
            <div class="form-group purchase-align-end"></div>
        </div>

        <!-- قسم تواريخ انتهاء الصلاحية (لكل منتج) -->
        <div id="prod_expiry_section_${idx}" style="display:none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 2px dashed #93c5fd;">
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.95rem; color: #1e40af;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" width="16" height="16" style="vertical-align: middle; margin-left: 3px;">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/>
                    </svg>
                    تواريخ انتهاء الصلاحية
                </h4>
                <button type="button" onclick="addExpiryRow(${idx})"
                        style="background: #eff6ff; color: #2563eb; border: 1px solid #93c5fd; padding: 5px 12px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.8rem;">
                    + إضافة تاريخ
                </button>
            </div>
            <div class="alert alert-info" style="font-size:0.78rem; padding:6px 10px;">
                <strong>ملاحظة:</strong> مجموع كميات جميع التواريخ يجب أن يساوي الكمية بعد تحويل وحدة الشراء
            </div>
            <div id="prod_expiry_container_${idx}"></div>
            <div id="prod_expiry_summary_${idx}" style="display:none; margin-top:0.5rem;">
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:6px; padding:5px 10px; text-align:center;">
                        <div style="font-size:0.7rem; color:var(--gray-500);">مجموع كميات التواريخ</div>
                        <div id="prod_expiry_total_${idx}" style="font-weight:800; color:#2563eb;">0</div>
                    </div>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:5px 10px; text-align:center;">
                        <div style="font-size:0.7rem; color:var(--gray-500);">الكمية المطلوبة</div>
                        <div id="prod_expiry_required_${idx}" style="font-weight:800; color:#1e40af;">0</div>
                    </div>
                    <div id="prod_expiry_diff_box_${idx}" style="border-radius:6px; padding:5px 10px; text-align:center; display:none;">
                        <div style="font-size:0.7rem; color:var(--gray-500);">الفارق</div>
                        <div id="prod_expiry_diff_${idx}" style="font-weight:800; font-size:1rem;">0</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- قسم تخفيضات المنتج (لكل منتج) -->
        <div id="prod_remise_section_${idx}" style="display:none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 2px dashed #fcd34d;">
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.9rem; color: #92400e;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="15" height="15" style="vertical-align: middle; margin-left: 3px;">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                    </svg>
                    تخفيضات هذا المنتج
                </h4>
                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                    <button type="button" onclick="addProdRemiseRow(${idx}, 'material')" 
                            style="background: #f0fdf4; color: #059669; border: 1px solid #86efac; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.75rem;">
                        + تخفيض مادي
                    </button>
                    <button type="button" onclick="addProdRemiseRow(${idx}, 'financial')" 
                            style="background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.75rem;">
                        + تخفيض مالي
                    </button>
                </div>
            </div>
            <div id="prod_remise_container_${idx}"></div>
        </div>
    `;

    container.appendChild(row);
    recalculateTotals();
}

// ============================================================
// === حذف صف منتج ===
// ============================================================
function removeProductRow(idx) {
    const row = document.getElementById('productRow_' + idx);
    if (row) row.remove();
    delete productRowsData[idx];
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

// ============================================================
// === البحث عن المنتجات لكل صف ===
// ============================================================
function openRowSearch(idx) {
    const pidInput = document.getElementById('prod_id_' + idx);
    if (pidInput && pidInput.value) return;
    const dropdown = document.getElementById('prod_dropdown_' + idx);
    if (dropdown) { dropdown.style.display = 'flex'; rowDropdownOpen[idx] = true; }
    const input = document.getElementById('prod_search_' + idx);
    if (input && !input.value.trim()) searchRowProduct(idx);
}

function closeRowSearch(idx) {
    const dropdown = document.getElementById('prod_dropdown_' + idx);
    if (dropdown) dropdown.style.display = 'none';
    rowDropdownOpen[idx] = false;
    rowHighlightedIndex[idx] = -1;
}

function searchRowProduct(idx) {
    const input = document.getElementById('prod_search_' + idx);
    if (!input) return;
    const query = input.value.trim();
    const dropdown = document.getElementById('prod_dropdown_' + idx);
    const resultsDiv = document.getElementById('prod_results_' + idx);
    const clearBtn = document.getElementById('prod_clear_' + idx);
    const arrowBtn = document.getElementById('prod_arrow_' + idx);
    if (clearBtn) clearBtn.style.display = query ? 'flex' : 'none';
    if (arrowBtn) arrowBtn.style.display = 'none';
    if (rowSearchTimeouts[idx]) clearTimeout(rowSearchTimeouts[idx]);
    if (dropdown) { dropdown.style.display = 'flex'; rowDropdownOpen[idx] = true; }
    rowHighlightedIndex[idx] = -1;
    rowSearchTimeouts[idx] = setTimeout(function() {
        if (!resultsDiv) return;
        const loading = document.getElementById('prod_loading_' + idx);
        const hint = document.getElementById('prod_hint_' + idx);
        const noResults = document.getElementById('prod_noresults_' + idx);
        if (loading) loading.style.display = 'flex';
        if (hint) hint.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        // إزالة العناصر القديمة
        resultsDiv.querySelectorAll('.row-product-item, .search-dropdown-footer').forEach(el => el.remove());
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (loading) loading.style.display = 'none';
                resultsDiv.querySelectorAll('.row-product-item, .search-dropdown-footer').forEach(el => el.remove());
                var _products = data.products || data.data || [];
                if (_products.length > 0) {
                    rowSearchResults[idx] = _products;
                    _products.forEach(function(product, i) {
                        const item = createRowProductItem(product, query, idx);
                        item.dataset.index = i;
                        item.addEventListener('mouseenter', function() {
                            clearRowHighlight(idx);
                            item.classList.add('keyboard-nav');
                            rowHighlightedIndex[idx] = i;
                        });
                        item.addEventListener('mouseleave', function() {
                            item.classList.remove('keyboard-nav');
                        });
                        resultsDiv.appendChild(item);
                    });
                    // إضافة عداد النتائج
                    if (data.total) {
                        const footer = document.createElement('div');
                        footer.className = 'search-dropdown-footer';
                        footer.textContent = data.total + ' نتيجة';
                        resultsDiv.appendChild(footer);
                    }
                } else {
                    var errorMsg = '';
                    if (data.success === false) errorMsg = data.message || 'خطأ في البحث';
                    if (noResults) { noResults.style.display = 'block'; noResults.textContent = errorMsg || 'لا توجد نتائج مطابقة'; }
                    else if (hint) { hint.style.display = 'block'; hint.textContent = errorMsg || 'لا توجد نتائج مطابقة'; }
                    rowSearchResults[idx] = [];
                }
            }).catch(function(err) {
                if (loading) loading.style.display = 'none';
                if (noResults) { noResults.style.display = 'block'; noResults.textContent = 'خطأ في الاتصال: ' + (err.message || ''); }
            });
    }, 300);
}

function createRowProductItem(product, query, idx) {
    const item = document.createElement('div');
    item.className = 'search-product-item row-product-item';
    item.onclick = function() { selectRowProduct(idx, product); };

    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock', stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    else if (qty <= parseFloat(product.min_quantity || 0)) { stockClass = 'low-stock'; stockText = 'منخفض: ' + qty.toFixed(2) + ' ' + product.unit; }

    let nameHtml = escapeHtml(product.name), codeHtml = escapeHtml(product.code);
    if (query) {
        const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi');
        nameHtml = nameHtml.replace(regex, '<mark>$1</mark>');
        codeHtml = codeHtml.replace(regex, '<mark>$1</mark>');
    }

    // كشف المنتج المكرر
    const dupIdx = isDuplicateProduct(product.id, idx);
    if (dupIdx !== null) {
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
                <span>شراء: ${parseFloat(product.purchase_price).toFixed(2) + ' ' + CURRENCY_SYMBOL}</span>
                <span>الوحدة: ${escapeHtml(product.unit || '')}</span>
            </div>
        </div>
        <span class="item-stock ${stockClass}">${stockText}</span>
    `;
    return item;
}

function selectRowProduct(idx, product) {
    // كشف المنتج المكرر
    const dupIdx = isDuplicateProduct(product.id, idx);
    if (dupIdx !== null) {
        alert('هذا المنتج مضاف بالفعل في صف آخر (#' + (getRowPosition(dupIdx) + 1) + ')');
        return;
    }

    document.getElementById('prod_id_' + idx).value = product.id;
    document.getElementById('prod_base_price_' + idx).value = product.purchase_price;
    document.getElementById('prod_unit_name_' + idx).value = product.unit;

    const input = document.getElementById('prod_search_' + idx);
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('prod_clear_' + idx);
    if (clearBtn) clearBtn.style.display = 'none';
    const arrowBtn = document.getElementById('prod_arrow_' + idx);
    if (arrowBtn) arrowBtn.style.display = 'none';

    const selectedDiv = document.getElementById('prod_selected_' + idx);
    selectedDiv.style.display = 'flex';
    document.getElementById('prod_sel_code_' + idx).textContent = product.code;
    document.getElementById('prod_sel_name_' + idx).textContent = product.name;

    const qty = parseFloat(product.quantity);
    const stockSpan = document.getElementById('prod_sel_stock_' + idx);
    if (qty <= 0) { stockSpan.style.background = '#fee2e2'; stockSpan.style.color = '#dc2626'; stockSpan.textContent = 'غير متوفر'; }
    else { stockSpan.style.background = '#fef3c7'; stockSpan.style.color = '#92400e'; stockSpan.textContent = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit; }

    closeRowSearch(idx);

    // إظهار حقول الكمية والسعر
    document.getElementById('prod_fields_' + idx).style.display = 'grid';
    document.getElementById('prod_summary_' + idx).style.display = 'grid';
    document.getElementById('productRow_' + idx).classList.add('has-product');

    // تعبئة سعر الشراء
    document.getElementById('prod_price_' + idx).value = product.purchase_price;
    document.getElementById('prod_unit_label_' + idx).textContent = 'الوحدة: ' + product.unit;
    document.getElementById('prod_factor_' + idx).value = '1';

    // تخزين بيانات المنتج
    productRowsData[idx] = { id: product.id, name: product.name, code: product.code, unit: product.unit, basePrice: parseFloat(product.purchase_price), hasExpiry: parseInt(product.has_expiry) || 0, factor: 1, unitName: product.unit };

    // تحميل وحدات الشراء
    loadRowPurchaseUnits(idx, product.id);

    // إظهار/إخفاء قسم انتهاء الصلاحية
    if (productRowsData[idx].hasExpiry) {
        showRowExpirySection(idx, true);
    } else {
        showRowExpirySection(idx, false);
    }

    calculateLineTotal(idx);
}

function clearRowProduct(idx) {
    document.getElementById('prod_id_' + idx).value = '';
    document.getElementById('prod_base_price_' + idx).value = '';
    document.getElementById('prod_unit_name_' + idx).value = '';
    document.getElementById('prod_factor_' + idx).value = '1';

    const input = document.getElementById('prod_search_' + idx);
    if (input) { input.style.display = 'block'; input.value = ''; }
    const clearBtn = document.getElementById('prod_clear_' + idx);
    if (clearBtn) clearBtn.style.display = 'none';
    const arrowBtn = document.getElementById('prod_arrow_' + idx);
    if (arrowBtn) arrowBtn.style.display = 'flex';
    const selectedDiv = document.getElementById('prod_selected_' + idx);
    if (selectedDiv) selectedDiv.style.display = 'none';

    // إخفاء حقول الكمية والسعر
    document.getElementById('prod_fields_' + idx).style.display = 'none';
    document.getElementById('prod_summary_' + idx).style.display = 'none';
    document.getElementById('productRow_' + idx).classList.remove('has-product');

    document.getElementById('prod_price_' + idx).value = '';
    document.getElementById('prod_qty_' + idx).value = '';
    document.getElementById('prod_unit_label_' + idx).textContent = '';
    document.getElementById('prod_line_total_' + idx).value = '0.00 ' + CURRENCY_SYMBOL;
    document.getElementById('prod_unit_group_' + idx).style.display = 'none';

    productRowsData[idx] = { id: null, name: '', code: '', unit: '', basePrice: 0, hasExpiry: 0, factor: 1, unitName: '' };
    showRowExpirySection(idx, false);

    if (input) input.focus();
    searchRowProduct(idx);
    recalculateTotals();
}

// ============================================================
// === وحدات الشراء لكل صف ===
// ============================================================
function loadRowPurchaseUnits(idx, productId) {
    const group = document.getElementById('prod_unit_group_' + idx);
    const select = document.getElementById('prod_unit_select_' + idx);
    if (!group || !select) return;

    fetch('../api/get_purchase_units.php?product_id=' + productId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            select.innerHTML = '';

            const hasBase = data.purchase_unit && data.purchase_unit !== data.selling_unit;
            const hasAdditional = data.units && data.units.length > 0;

            if (!hasBase && !hasAdditional) {
                group.style.display = 'none';
                document.getElementById('prod_factor_' + idx).value = '1';
                document.getElementById('prod_unit_info_' + idx).textContent = '';
                return;
            }

            group.style.display = '';

            // وحدة البيع الأساسية
            const optSelling = document.createElement('option');
            optSelling.value = 'base';
            optSelling.dataset.factor = 1;
            optSelling.textContent = data.selling_unit + ' (الوحدة الأساسية)';
            select.appendChild(optSelling);

            // وحدة الشراء الأساسية
            if (hasBase) {
                const optBase = document.createElement('option');
                optBase.value = 'purchase_base';
                optBase.dataset.factor = data.purchase_unit_factor;
                optBase.textContent = data.purchase_unit + ' — شراء (1 ' + data.purchase_unit + ' = ' + data.purchase_unit_factor + ' ' + data.selling_unit + ')';
                select.appendChild(optBase);
            }

            // وحدات شراء إضافية
            if (hasAdditional) {
                data.units.forEach(function(u) {
                    const opt = document.createElement('option');
                    const totalFactor = parseFloat(u.conversion_factor) * (data.purchase_unit_factor || 1);
                    opt.value = 'additional_' + u.id;
                    opt.dataset.factor = totalFactor;
                    opt.textContent = u.unit_name + ' — شراء (1 ' + u.unit_name + ' = ' + totalFactor + ' ' + data.selling_unit + ')';
                    select.appendChild(opt);
                });
            }

            onRowUnitChange(idx);
        })
        .catch(function() {
            group.style.display = 'none';
            document.getElementById('prod_factor_' + idx).value = '1';
        });
}

function onRowUnitChange(idx) {
    const select = document.getElementById('prod_unit_select_' + idx);
    if (!select) return;

    const selectedOpt = select.options[select.selectedIndex];
    const newFactor = parseFloat(selectedOpt.dataset.factor) || 1;

    // تحويل سعر الشراء حسب الوحدة الجديدة
    const priceInput = document.getElementById('prod_price_' + idx);
    const currentPrice = parseFloat(priceInput.value) || 0;
    if (currentPrice > 0 && productRowsData[idx]) {
        const oldFactor = productRowsData[idx].factor || 1;
        // السعر الجديد = السعر الحالي × (معامل الوحدة الجديدة / معامل الوحدة القديمة)
        const newPrice = currentPrice * (newFactor / oldFactor);
        priceInput.value = newPrice.toFixed(2);
    }

    document.getElementById('prod_factor_' + idx).value = newFactor;

    const infoDiv = document.getElementById('prod_unit_info_' + idx);
    if (infoDiv) {
        const unitName = selectedOpt.textContent.split(' — ')[0] || selectedOpt.textContent.split(' (')[0];
        infoDiv.textContent = 'المعامل: 1 ' + unitName + ' = ' + newFactor + ' وحدة بيع أساسية';
    }

    // تحديث اسم الوحدة
    const unitLabel = document.getElementById('prod_unit_label_' + idx);
    if (unitLabel) {
        const unitName2 = selectedOpt.textContent.split(' — ')[0] || selectedOpt.textContent.split(' (')[0];
        unitLabel.textContent = 'الوحدة: ' + unitName2;
    }

    if (productRowsData[idx]) {
        productRowsData[idx].factor = newFactor;
        productRowsData[idx].unitName = selectedOpt.textContent.split(' — ')[0] || selectedOpt.textContent.split(' (')[0];
    }

    calculateLineTotal(idx);
}

// ============================================================
// === حساب الإجمالي ===
// ============================================================
function calculateLineTotal(idx) {
    const qty = parseFloat(document.getElementById('prod_qty_' + idx).value) || 0;
    const price = parseFloat(document.getElementById('prod_price_' + idx).value) || 0;
    const factor = parseFloat(document.getElementById('prod_factor_' + idx).value) || 1;
    const total = qty * price;

    // حساب تخفيض المنتج المالي
    let prodFinRemise = 0;
    const prContainer = document.getElementById('prod_remise_container_' + idx);
    if (prContainer) {
        prContainer.querySelectorAll('input[name="prod_remise_type[' + idx + '][]"]').forEach(function(t) {
            if (t.value === 'financial') {
                const r = t.closest('div[id^="prod_remise_row_' + idx + '_"]');
                if (r) {
                    const a = r.querySelector('input[name="prod_remise_amount[' + idx + '][]"]');
                    if (a) prodFinRemise += parseFloat(a.value) || 0;
                }
            }
        });
    }
    const netTotal = Math.max(0, total - prodFinRemise);

    const totalEl = document.getElementById('prod_line_total_' + idx);
    if (totalEl) {
        if (prodFinRemise > 0) {
            totalEl.value = netTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL + '  (قبل الخصم: ' + total.toFixed(2) + ')';
        } else {
            totalEl.value = total.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        }
    }

    // تحديث ملخص انتهاء الصلاحية إن وجد
    if (productRowsData[idx] && productRowsData[idx].hasExpiry) {
        updateExpirySummary(idx);
    }

    recalculateTotals();
}

function recalculateTotals() {
    let grandTotal = 0;
    let productCount = 0;

    document.querySelectorAll('.product-row').forEach(function(row) {
        const id = row.id.replace('productRow_', '');
        const pid = document.getElementById('prod_id_' + id);
        if (!pid || !pid.value) return;

        const qty = parseFloat(document.getElementById('prod_qty_' + id).value) || 0;
        const price = parseFloat(document.getElementById('prod_price_' + id).value) || 0;
        if (qty > 0 && price > 0) {
            grandTotal += qty * price;
            productCount++;
        }
    });

    // حساب إجمالي التخفيض المالي (عام)
    let totalFinRemise = 0;
    const remiseContainer = document.getElementById('remisesContainer');
    if (remiseContainer) {
        remiseContainer.querySelectorAll('input[name="remise_type[]"]').forEach(function(t) {
            if (t.value === 'financial') {
                const r = t.closest('div[id^="remise_row_"]');
                if (r) {
                    const a = r.querySelector('input[name="remise_amount[]"]');
                    if (a) totalFinRemise += parseFloat(a.value) || 0;
                }
            }
        });
    }

    // حساب إجمالي تخفيضات المنتجات المالية
    document.querySelectorAll('.product-row').forEach(function(row) {
        const id = row.id.replace('productRow_', '');
        const prContainer = document.getElementById('prod_remise_container_' + id);
        if (prContainer) {
            prContainer.querySelectorAll('input[name="prod_remise_type[' + id + '][]"]').forEach(function(t) {
                if (t.value === 'financial') {
                    const r = t.closest('div[id^="prod_remise_row_' + id + '_"]');
                    if (r) {
                        const a = r.querySelector('input[name="prod_remise_amount[' + id + '][]"]');
                        if (a) totalFinRemise += parseFloat(a.value) || 0;
                    }
                }
            });
        }
    });

    const netTotal = Math.max(0, grandTotal - totalFinRemise);

    // حساب الضريبة
    let taxAmount = 0;
    let taxEnabled = document.getElementById('tax_enabled_checkbox') && document.getElementById('tax_enabled_checkbox').checked;
    let taxName = document.getElementById('tax_name_input') ? document.getElementById('tax_name_input').value.trim() : '';
    let taxType = document.getElementById('tax_type_select') ? document.getElementById('tax_type_select').value : 'percentage';
    let taxValue = document.getElementById('tax_value_input') ? (parseFloat(document.getElementById('tax_value_input').value) || 0) : 0;

    if (taxEnabled && taxValue > 0 && netTotal > 0) {
        if (taxType === 'percentage') {
            taxAmount = netTotal * (taxValue / 100);
        } else {
            taxAmount = taxValue;
        }
    }

    const grandTotalWithTax = netTotal + taxAmount;

    const summary = document.getElementById('purchaseSummary');
    if (productCount > 0) {
        summary.style.display = 'block';
        summary.classList.add('visible');
        document.getElementById('summaryProductCount').textContent = productCount;
        document.getElementById('summaryGrossTotal').textContent = grandTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        if (totalFinRemise > 0) {
            document.getElementById('summaryRemiseDiv').style.display = '';
            document.getElementById('summaryFinancialRemise').textContent = '-' + totalFinRemise.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        } else {
            document.getElementById('summaryRemiseDiv').style.display = 'none';
        }
        document.getElementById('summaryNetTotal').textContent = netTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;

        // عرض الضريبة
        if (taxEnabled && taxAmount > 0) {
            document.getElementById('summaryTaxDiv').style.display = '';
            document.getElementById('summaryTaxName').textContent = taxName || 'الضريبة';
            document.getElementById('summaryTaxAmount').textContent = '+' + taxAmount.toFixed(2) + ' ' + CURRENCY_SYMBOL;
            document.getElementById('summaryGrandTotalDiv').style.display = '';
            document.getElementById('summaryGrandTotal').textContent = grandTotalWithTax.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        } else {
            document.getElementById('summaryTaxDiv').style.display = 'none';
            document.getElementById('summaryGrandTotalDiv').style.display = 'none';
        }
    } else {
        summary.style.display = 'none';
        summary.classList.remove('visible');
    }

    // تحديث عرض الضريبة المحسوبة
    const taxCalc = document.getElementById('taxCalculated');
    if (taxEnabled && taxAmount > 0) {
        taxCalc.style.display = '';
        document.getElementById('taxCalculatedLabel').textContent = (taxName || 'الضريبة') + ':';
        document.getElementById('taxCalculatedAmount').textContent = '+' + taxAmount.toFixed(2);
    } else {
        taxCalc.style.display = 'none';
    }
}

// ============================================================
// === تواريخ انتهاء الصلاحية لكل منتج ===
// ============================================================
let expiryCounters = {};

function showRowExpirySection(idx, show) {
    const section = document.getElementById('prod_expiry_section_' + idx);
    if (show) {
        section.style.display = '';
        if (!expiryCounters[idx] || expiryCounters[idx] === 0) {
            addExpiryRow(idx);
        }
    } else {
        section.style.display = 'none';
        // مسح صفوف الانتهاء
        const container = document.getElementById('prod_expiry_container_' + idx);
        if (container) container.innerHTML = '';
        expiryCounters[idx] = 0;
    }
}

function addExpiryRow(prodIdx) {
    if (!expiryCounters[prodIdx]) expiryCounters[prodIdx] = 0;
    expiryCounters[prodIdx]++;

    const eIdx = expiryCounters[prodIdx];
    const container = document.getElementById('prod_expiry_container_' + prodIdx);
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'expiry-row';
    row.id = 'expiry_row_' + prodIdx + '_' + eIdx;
    row.style.cssText = 'display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:8px 12px; background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #93c5fd; border-radius:8px; margin-bottom:6px;';
    row.innerHTML = `
        <div style="flex:1; min-width:150px;">
            <label style="font-size:0.75rem; font-weight:600; color:#1e40af; display:block; margin-bottom:2px;">تاريخ الانتهاء</label>
            <input type="date" name="expiry_dates[${prodIdx}][]" class="form-control" style="border-color:#93c5fd; font-weight:600; font-size:0.85rem; padding:6px 8px;" onchange="updateExpirySummary(${prodIdx})">
        </div>
        <div style="min-width:120px;">
            <label style="font-size:0.75rem; font-weight:600; color:#1e40af; display:block; margin-bottom:2px;">الكمية (بالوحدة الأساسية)</label>
            <input type="number" name="expiry_quantities[${prodIdx}][]" class="form-control" step="0.01" min="0.01" value="0" style="border-color:#93c5fd; font-weight:700; font-size:0.85rem; padding:6px 8px;" oninput="updateExpirySummary(${prodIdx})">
        </div>
        <button type="button" onclick="removeExpiryRowById(${prodIdx}, ${eIdx})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem; padding:4px;" title="حذف">✕</button>
    `;
    container.appendChild(row);
    updateExpirySummary(prodIdx);
}

function removeExpiryRowById(prodIdx, eIdx) {
    const row = document.getElementById('expiry_row_' + prodIdx + '_' + eIdx);
    if (row) row.remove();
    updateExpirySummary(prodIdx);
}

function getRowBaseQuantity(idx) {
    const qty = parseFloat(document.getElementById('prod_qty_' + idx).value) || 0;
    const factor = parseFloat(document.getElementById('prod_factor_' + idx).value) || 1;
    if (factor > 0 && factor != 1) return qty * factor;
    return qty;
}

function updateExpirySummary(prodIdx) {
    const container = document.getElementById('prod_expiry_container_' + prodIdx);
    if (!container) return;

    let totalQty = 0;
    container.querySelectorAll('input[name="expiry_quantities[' + prodIdx + '][]"]').forEach(function(input) {
        totalQty += parseFloat(input.value) || 0;
    });

    const totalEl = document.getElementById('prod_expiry_total_' + prodIdx);
    const requiredEl = document.getElementById('prod_expiry_required_' + prodIdx);
    const diffBox = document.getElementById('prod_expiry_diff_box_' + prodIdx);
    const diffEl = document.getElementById('prod_expiry_diff_' + prodIdx);
    const summaryBox = document.getElementById('prod_expiry_summary_' + prodIdx);

    if (!totalEl) return;

    const requiredQty = getRowBaseQuantity(prodIdx);

    totalEl.textContent = totalQty.toFixed(2);
    requiredEl.textContent = requiredQty.toFixed(2);

    if (totalQty > 0 || requiredQty > 0) {
        summaryBox.style.display = '';
        diffBox.style.display = '';
        const diff = totalQty - requiredQty;
        diffEl.textContent = (diff >= 0 ? '+' : '') + diff.toFixed(2);
        if (Math.abs(diff) < 0.01) {
            diffBox.style.background = '#f0fdf4';
            diffBox.style.border = '1px solid #86efac';
            diffBox.style.color = '#059669';
        } else {
            diffBox.style.background = '#fef2f2';
            diffBox.style.border = '1px solid #fca5a5';
            diffBox.style.color = '#dc2626';
        }
    } else {
        summaryBox.style.display = 'none';
    }
}

// ============================================================
// === التخفيضات (Remises) ===
// ============================================================
function addRemiseRow(type) {
    remiseCounter++;
    const container = document.getElementById('remisesContainer');
    const row = document.createElement('div');
    row.id = 'remise_row_' + remiseCounter;
    row.style.cssText = 'background: ' + (type === 'material' ? '#f0fdf4' : '#fef3c7') + '; border: 1px solid ' + (type === 'material' ? '#86efac' : '#fcd34d') + '; border-radius: 10px; padding: 0.75rem; margin-bottom: 0.5rem; position: relative;';

    if (type === 'material') {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 700; font-size: 0.9rem; color: #059669;">🎁 تخفيض مادي — إعطاء سلع مجانية <span style="font-size:0.75rem; color:var(--gray-400);">(سعر الدفعة = 0.00)</span></span>
                <button type="button" onclick="removeRemiseRow(${remiseCounter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr; gap: 0.5rem; align-items: end;">
                <div style="position:relative; min-width:0;" class="remise-search-wrapper">
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">المنتج المعطى *</label>
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" class="form-control remise-search-input" placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openRemiseSearch(${remiseCounter})" oninput="searchRemiseProduct(${remiseCounter})"
                               style="font-size:0.85rem; padding:8px 32px 8px 40px; width:100%;">
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
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem;">
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
    recalculateTotals();
}

function removeRemiseRow(id) {
    const row = document.getElementById('remise_row_' + id);
    if (row) row.remove();
    updateRemiseSummary();
    recalculateTotals();
}

function updateRemiseSummary() {
    const container = document.getElementById('remisesContainer');
    const summary = document.getElementById('remiseSummary');
    const rows = container.querySelectorAll('div[id^="remise_row_"]');

    if (rows.length === 0) {
        summary.style.display = 'none';
        recalculateTotals();
        return;
    }
    summary.style.display = 'block';

    let matCount = 0;
    let finTotal = 0;

    rows.forEach(function(row) {
        const type = row.querySelector('input[name="remise_type[]"]');
        if (!type) return;
        if (type.value === 'material') {
            const qty = parseFloat(row.querySelector('input[name="remise_quantity[]"]').value) || 0;
            if (qty > 0) matCount++;
        } else {
            const amount = parseFloat(row.querySelector('input[name="remise_amount[]"]').value) || 0;
            finTotal += amount;
        }
    });

    document.getElementById('totalMaterialRemise').textContent = matCount > 0 ? matCount + ' منتج(ات)' : '0';
    document.getElementById('totalFinancialRemise').textContent = finTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;

    recalculateTotals();
}

// === بحث المنتجات في التخفيض المادي ===
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
    const resultsDiv = document.getElementById('remise_results_' + counter);
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';
    if (remiseSearchTimeouts[counter]) clearTimeout(remiseSearchTimeouts[counter]);
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; remiseDropdownOpen[counter] = true; }
    remiseHighlightedIndex[counter] = -1;
    remiseSearchTimeouts[counter] = setTimeout(function() {
        if (!resultsDiv) return;
        resultsDiv.innerHTML = '<div style="padding:8px; text-align:center; color:#94a3b8; font-size:0.8rem;"><div class="spinner" style="margin:0 auto 4px; width:14px; height:14px; border-width:2px;"></div>جاري البحث...</div>';
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (!resultsDiv) return;
                resultsDiv.querySelectorAll('.remise-product-item').forEach(el => el.remove());
                var _remiseProducts = data.products || data.data || [];
                if (_remiseProducts.length > 0) {
                    _remiseProducts.forEach(function(product, index) {
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
    item.className = 'search-product-item remise-product-item';
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

// ============================================================
// === تخفيضات المنتجات (لكل منتج) ===
// ============================================================
function toggleProdRemise(idx) {
    const section = document.getElementById('prod_remise_section_' + idx);
    if (!section) return;
    if (section.style.display === 'none') {
        section.style.display = '';
    } else {
        section.style.display = 'none';
    }
}

function addProdRemiseRow(idx, type) {
    if (!prodRemiseCounters[idx]) prodRemiseCounters[idx] = 0;
    prodRemiseCounters[idx]++;
    const counter = prodRemiseCounters[idx];
    const container = document.getElementById('prod_remise_container_' + idx);
    if (!container) return;

    const row = document.createElement('div');
    row.id = 'prod_remise_row_' + idx + '_' + counter;
    row.style.cssText = 'background: ' + (type === 'material' ? '#f0fdf4' : '#fef3c7') + '; border: 1px solid ' + (type === 'material' ? '#86efac' : '#fcd34d') + '; border-radius: 8px; padding: 0.6rem; margin-bottom: 0.4rem; position: relative; animation: fadeSlideDown 0.3s ease;';

    if (type === 'material') {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-weight: 700; font-size: 0.82rem; color: #059669;">🎁 تخفيض مادي — إعطاء سلع مجانية</span>
                <button type="button" onclick="removeProdRemiseRow(${idx}, ${counter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr; gap: 0.4rem;">
                <div style="position:relative;" class="prm-search-wrapper" id="pr_${idx}_search_wrapper_${counter}">
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">المنتج المعطى *</label>
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" class="form-control prm-search-input" id="pr_${idx}_search_${counter}" placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openPrmSearch(${idx}, ${counter})" oninput="searchPrmProduct(${idx}, ${counter})"
                               style="font-size:0.82rem; padding:6px 30px 6px 36px; width:100%;">
                        <button type="button" class="prm-clear-btn" id="pr_${idx}_clear_${counter}" onclick="clearPrmSelection(${idx}, ${counter})" style="display:none; position:absolute; left:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#9ca3af; padding:2px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="prm-search-dropdown" id="pr_${idx}_dropdown_${counter}" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); max-height:200px; overflow-y:auto; z-index:55; margin-top:2px;">
                        <div class="prm-search-results" id="pr_${idx}_results_${counter}"></div>
                    </div>
                    <div class="prm-selected-product" id="pr_${idx}_selected_${counter}" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:5px 8px; margin-top:3px; font-size:0.8rem; align-items:center; gap:5px;">
                        <span style="font-family:monospace; background:#dcfce7; padding:1px 5px; border-radius:4px; font-weight:700; color:#166534;" id="pr_${idx}_sel_code_${counter}"></span>
                        <span style="font-weight:600; color:#0f172a;" id="pr_${idx}_sel_name_${counter}"></span>
                    </div>
                    <input type="hidden" name="prod_remise_product_id[${idx}][]" id="pr_${idx}_pid_${counter}" value="">
                </div>
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">الكمية *</label>
                    <input type="number" name="prod_remise_quantity[${idx}][]" class="form-control" step="0.01" min="0.01" required placeholder="0" style="font-size:0.85rem; padding:6px;">
                </div>
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="prod_remise_description[${idx}][]" class="form-control" placeholder="اختياري" style="font-size:0.8rem; padding:6px;">
                </div>
            </div>
            <input type="hidden" name="prod_remise_type[${idx}][]" value="material">
        `;
    } else {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-weight: 700; font-size: 0.82rem; color: #d97706;">💰 تخفيض مالي — خصم من هذا المنتج</span>
                <button type="button" onclick="removeProdRemiseRow(${idx}, ${counter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem;">
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">مبلغ الخصم *</label>
                    <input type="number" name="prod_remise_amount[${idx}][]" class="form-control" step="0.01" min="0.01" required placeholder="0.00" oninput="calculateLineTotal(${idx})" style="font-size:0.85rem; padding:6px; font-weight:800; border-color:#f59e0b;">
                </div>
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="prod_remise_description[${idx}][]" class="form-control" placeholder="اختياري" style="font-size:0.8rem; padding:6px;">
                </div>
            </div>
            <input type="hidden" name="prod_remise_type[${idx}][]" value="financial">
        `;
    }

    container.appendChild(row);
    calculateLineTotal(idx);
}

function removeProdRemiseRow(idx, counter) {
    const row = document.getElementById('prod_remise_row_' + idx + '_' + counter);
    if (row) row.remove();
    calculateLineTotal(idx);
}

// === بحث المنتجات في تخفيض المنتج المادي ===
function openPrmSearch(idx, counter) {
    const prmKey = idx + '_' + counter;
    const pidInput = document.getElementById('pr_' + idx + '_pid_' + counter);
    if (pidInput && pidInput.value) return;
    const dropdown = document.getElementById('pr_' + idx + '_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; prmDropdownOpen[prmKey] = true; }
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (input && !input.value.trim()) searchPrmProduct(idx, counter);
}

function closePrmSearch(idx, counter) {
    const prmKey = idx + '_' + counter;
    const dropdown = document.getElementById('pr_' + idx + '_dropdown_' + counter);
    if (dropdown) dropdown.style.display = 'none';
    prmDropdownOpen[prmKey] = false;
    prmHighlightedIndex[prmKey] = -1;
}

function searchPrmProduct(idx, counter) {
    const prmKey = idx + '_' + counter;
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (!input) return;
    const query = input.value.trim();
    const resultsDiv = document.getElementById('pr_' + idx + '_results_' + counter);
    const clearBtn = document.getElementById('pr_' + idx + '_clear_' + counter);
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';
    if (prmSearchTimeouts[prmKey]) clearTimeout(prmSearchTimeouts[prmKey]);
    const dropdown = document.getElementById('pr_' + idx + '_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; prmDropdownOpen[prmKey] = true; }
    prmHighlightedIndex[prmKey] = -1;
    prmSearchTimeouts[prmKey] = setTimeout(function() {
        if (!resultsDiv) return;
        resultsDiv.innerHTML = '<div style="padding:8px; text-align:center; color:#94a3b8; font-size:0.8rem;"><div class="spinner" style="margin:0 auto 4px; width:14px; height:14px; border-width:2px;"></div>جاري البحث...</div>';
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (!resultsDiv) return;
                resultsDiv.querySelectorAll('.prm-product-item').forEach(el => el.remove());
                var _prmProducts = data.products || data.data || [];
                if (_prmProducts.length > 0) {
                    _prmProducts.forEach(function(product, index) {
                        const item = createPrmProductItem(product, query, idx, counter);
                        item.dataset.index = index;
                        item.dataset.prmkey = prmKey;
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

function createPrmProductItem(product, query, idx, counter) {
    const prmKey = idx + '_' + counter;
    const item = document.createElement('div');
    item.className = 'search-product-item prm-product-item';
    item.style.cssText = 'padding:8px 12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; transition:background 0.15s; font-size:0.85rem;';
    item.onclick = function() { selectPrmProduct(idx, counter, product); };
    item.addEventListener('mouseenter', function() {
        document.querySelectorAll('.prm-product-item[data-prmkey="' + prmKey + '"]').forEach(function(el) { el.style.background = ''; });
        item.style.background = '#f0fdf4';
        prmHighlightedIndex[prmKey] = parseInt(item.dataset.index);
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

function selectPrmProduct(idx, counter, product) {
    document.getElementById('pr_' + idx + '_pid_' + counter).value = product.id;
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('pr_' + idx + '_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'block';
    const selectedDiv = document.getElementById('pr_' + idx + '_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'flex';
    document.getElementById('pr_' + idx + '_sel_code_' + counter).textContent = product.code;
    document.getElementById('pr_' + idx + '_sel_name_' + counter).textContent = product.name;
    closePrmSearch(idx, counter);
}

function clearPrmSelection(idx, counter) {
    document.getElementById('pr_' + idx + '_pid_' + counter).value = '';
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (input) { input.style.display = 'block'; input.value = ''; input.focus(); }
    const clearBtn = document.getElementById('pr_' + idx + '_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'none';
    const selectedDiv = document.getElementById('pr_' + idx + '_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'none';
    searchPrmProduct(idx, counter);
}

// ============================================================
// === أدوات مساعدة ===
// ============================================================
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function escapeRegex(string) { return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

// ============================================================
// === لوحة المفاتيح: قوائم البحث لكل صف منتج ===
// ============================================================
document.addEventListener('keydown', function(e) {
    // البحث في صفوف المنتجات
    for (var idx in rowDropdownOpen) {
        if (!rowDropdownOpen[idx]) continue;
        const items = document.querySelectorAll('.row-product-item[data-idx="' + idx + '"]');
        if (items.length === 0) continue;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            rowHighlightedIndex[idx] = Math.min((rowHighlightedIndex[idx] || -1) + 1, items.length - 1);
            items.forEach(function(item, i) { item.style.background = i === rowHighlightedIndex[idx] ? '#f0fdf4' : ''; });
            if (items[rowHighlightedIndex[idx]]) items[rowHighlightedIndex[idx]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            rowHighlightedIndex[idx] = Math.max((rowHighlightedIndex[idx] || 0) - 1, 0);
            items.forEach(function(item, i) { item.style.background = i === rowHighlightedIndex[idx] ? '#f0fdf4' : ''; });
            if (items[rowHighlightedIndex[idx]]) items[rowHighlightedIndex[idx]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && rowHighlightedIndex[idx] >= 0) {
            e.preventDefault();
            items[rowHighlightedIndex[idx]].click();
        } else if (e.key === 'Escape') {
            closeRowSearch(idx);
        }
        return;
    }

    // البحث في قوائم التخفيض
    for (var counter in remiseDropdownOpen) {
        if (!remiseDropdownOpen[counter]) continue;
        const items = document.querySelectorAll('.remise-product-item[data-counter="' + counter + '"]');
        if (items.length === 0) continue;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            remiseHighlightedIndex[counter] = Math.min((remiseHighlightedIndex[counter] || -1) + 1, items.length - 1);
            items.forEach(function(item, i) { item.style.background = i === remiseHighlightedIndex[counter] ? '#f0fdf4' : ''; });
            if (items[remiseHighlightedIndex[counter]]) items[remiseHighlightedIndex[counter]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            remiseHighlightedIndex[counter] = Math.max((remiseHighlightedIndex[counter] || 0) - 1, 0);
            items.forEach(function(item, i) { item.style.background = i === remiseHighlightedIndex[counter] ? '#f0fdf4' : ''; });
            if (items[remiseHighlightedIndex[counter]]) items[remiseHighlightedIndex[counter]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && remiseHighlightedIndex[counter] >= 0) {
            e.preventDefault();
            items[remiseHighlightedIndex[counter]].click();
        } else if (e.key === 'Escape') {
            closeRemiseSearch(counter);
        }
        return;
    }

    // البحث في قوائم تخفيضات المنتجات
    for (var prmKey in prmDropdownOpen) {
        if (!prmDropdownOpen[prmKey]) continue;
        const parts = prmKey.split('_');
        const pIdx = parts[0];
        const pCounter = parts[1];
        const items = document.querySelectorAll('.prm-product-item[data-prmkey="' + prmKey + '"]');
        if (items.length === 0) continue;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            prmHighlightedIndex[prmKey] = Math.min((prmHighlightedIndex[prmKey] || -1) + 1, items.length - 1);
            items.forEach(function(item, i) { item.style.background = i === prmHighlightedIndex[prmKey] ? '#f0fdf4' : ''; });
            if (items[prmHighlightedIndex[prmKey]]) items[prmHighlightedIndex[prmKey]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            prmHighlightedIndex[prmKey] = Math.max((prmHighlightedIndex[prmKey] || 0) - 1, 0);
            items.forEach(function(item, i) { item.style.background = i === prmHighlightedIndex[prmKey] ? '#f0fdf4' : ''; });
            if (items[prmHighlightedIndex[prmKey]]) items[prmHighlightedIndex[prmKey]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && prmHighlightedIndex[prmKey] >= 0) {
            e.preventDefault();
            items[prmHighlightedIndex[prmKey]].click();
        } else if (e.key === 'Escape') {
            closePrmSearch(pIdx, pCounter);
        }
        return;
    }
});

// إغلاق القوائم عند النقر خارجها
document.addEventListener('click', function(e) {
    // إغلاق قوائم صفوف المنتجات
    document.querySelectorAll('[id^="prodSearchWrapper_"]').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const idx = wrapper.id.replace('prodSearchWrapper_', '');
            closeRowSearch(idx);
        }
    });
    // إغلاق قوائم التخفيض
    document.querySelectorAll('.remise-search-wrapper').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const row = wrapper.closest('div[id^="remise_row_"]');
            if (row) {
                const id = row.id.replace('remise_row_', '');
                closeRemiseSearch(id);
            }
        }
    });
    // إغلاق قوائم تخفيضات المنتجات
    document.querySelectorAll('.prm-search-wrapper').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const row = wrapper.closest('div[id^="prod_remise_row_"]');
            if (row) {
                const idParts = row.id.replace('prod_remise_row_', '').split('_');
                closePrmSearch(idParts[0], idParts[1]);
            }
        }
    });
});

// ============================================================
// === باركود ===
// ============================================================

// target: قيمة data-scan-target من زر المسح (رقم الصف) أو null
window.onBarcodeScanned = function(code, target) {
    if (!code) return;
    var scanTarget = target !== undefined ? target : null;

    // إذا تم تحديد صف منتج عبر data-scan-target
    if (scanTarget !== null && scanTarget !== '' && productRowsData[scanTarget] && !productRowsData[scanTarget].id) {
        var rIdx = parseInt(scanTarget);
        fetch('../api/search_products.php?q=' + encodeURIComponent(code))
            .then(res => res.json()).then(data => {
                var _scanProducts2 = data.products || data.data || [];
                if (_scanProducts2.length > 0) {
                    selectRowProduct(rIdx, _scanProducts2.find(function(p) { return p.code === code; }) || _scanProducts2[0]);
                }
            });
        return;
    }
    // السلوك الافتراضي: إضافة صف جديد وإسناد الباركود
    addProductRow();
    var newIdx = productRowCounter;
    document.getElementById('prod_search_' + newIdx).value = code;
    fetch('../api/search_products.php?q=' + encodeURIComponent(code))
        .then(res => res.json()).then(data => {
            var _scanProducts3 = data.products || data.data || [];
            if (_scanProducts3.length > 0) {
                selectRowProduct(newIdx, _scanProducts3.find(function(p) { return p.code === code; }) || _scanProducts3[0]);
            }
        });
};

// ============================================================
// === التحقق قبل الإرسال ===
// ============================================================
function validateForm() {
    var rows = document.querySelectorAll('.product-row');
    var hasValidProduct = false;
    var errors = [];

    rows.forEach(function(row) {
        var idx = row.id.replace('productRow_', '');
        var pid = document.getElementById('prod_id_' + idx).value;
        if (!pid) return;

        var qty = parseFloat(document.getElementById('prod_qty_' + idx).value) || 0;
        var price = parseFloat(document.getElementById('prod_price_' + idx).value) || 0;

        if (qty <= 0) {
            errors.push('يرجى إدخال الكمية للمنتج: ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx));
        }
        if (price <= 0) {
            errors.push('يرجى إدخال سعر الشراء للمنتج: ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx));
        }

        // التحقق من تواريخ الانتهاء
        if (productRowsData[idx] && productRowsData[idx].hasExpiry) {
            var container = document.getElementById('prod_expiry_container_' + idx);
            if (container) {
                var totalExpQty = 0;
                container.querySelectorAll('input[name="expiry_quantities[' + idx + '][]"]').forEach(function(input) {
                    totalExpQty += parseFloat(input.value) || 0;
                });
                var hasAnyDate = false;
                container.querySelectorAll('input[name="expiry_dates[' + idx + '][]"]').forEach(function(input) {
                    if (input.value) hasAnyDate = true;
                });
                if (!hasAnyDate) {
                    errors.push('يرجى إضافة تواريخ انتهاء للمنتج: ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx));
                } else {
                    var baseQty = getRowBaseQuantity(idx);
                    if (Math.abs(totalExpQty - baseQty) > 0.01) {
                        errors.push('منتج ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx) + ': مجموع كميات الانتهاء (' + totalExpQty.toFixed(2) + ') لا يساوي الكمية (' + baseQty.toFixed(2) + ')');
                    }
                }
            }
        }

        if (qty > 0 && price > 0) hasValidProduct = true;
    });

    if (!hasValidProduct) {
        errors.unshift('يرجى إضافة منتج واحد على الأقل مع الكمية وسعر الشراء');
    }

    if (errors.length > 0) {
        alert('يرجى تصحيح الأخطاء التالية:\n\n' + errors.join('\n'));
        return false;
    }

    return true;
}

// ============================================================
// === إضافة صف منتج أولي عند تحميل الصفحة ===
// ============================================================
function toggleTaxFields() {
    var checked = document.getElementById('tax_enabled_checkbox').checked;
    var container = document.getElementById('taxFieldsContainer');
    var label = document.getElementById('taxToggleLabel');
    if (checked) {
        container.style.display = 'block';
        label.style.borderColor = '#6366f1';
        label.style.background = '#eef2ff';
    } else {
        container.style.display = 'none';
        label.style.borderColor = '#e2e8f0';
        label.style.background = '#fff';
    }
    recalculateTotals();
}

// تحديث نوع الضريبة
function onTaxTypeChange() {
    var taxType = document.getElementById('tax_type_select').value;
    var valueLabel = document.getElementById('tax_value_label');
    var valueHint = document.getElementById('tax_value_hint');
    if (taxType === 'percentage') {
        valueLabel.textContent = 'النسبة (%)';
        valueHint.textContent = 'مثال: 0.50 يعني 0.50%';
    } else {
        valueLabel.textContent = 'المبلغ';
        valueHint.textContent = 'المبلغ الإجمالي للضريبة';
    }
    recalculateTotals();
}

// ربط حدث onchange لاختيار نوع الضريبة
document.getElementById('tax_type_select').addEventListener('change', onTaxTypeChange);

document.addEventListener('DOMContentLoaded', function() {
    addProductRow();
    recalculateTotals();

    // التركيز على حقل البحث في الصف الأول (إذا لم يكن أثناء مسح باركود)
    if (!window._barcodeProcessing) {
        var firstInput = document.getElementById('prod_search_' + Object.keys(productRowsData)[0]);
        if (firstInput) firstInput.focus();
    }
});
