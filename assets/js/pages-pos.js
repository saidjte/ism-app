/* ============================================================================
 * pages-pos.js — منطق واجهة نقطة البيع (مستخرج من pos.php في المرحلة 3 — M3)
 * الإصدار: v=6.3.5
 * ملاحظات:
 *  - يُحمَّل بعد سكربت الجسر المضمّن في pos.php (ثوابت POS_* / THERMAL_* + كائن PTXT للنصوص المترجمة).
 *  - أي تعديل هنا يتطلب رفع رقم الإصدار ($css_version في includes/header.php) لكسر الكاش.
 * ============================================================================ */

// ===================================
//  منطق نقطة البيع (POS Logic)
// ===================================

// v6.1.4 (W6 إصلاح): طريقة الدفع الحالية — cash (افتراضي) / card / split
let POS_PAYMENT_METHOD = 'cash';

// === إعدادات الطابعة الحرارية ===
// v6.1.6 (W18 إصلاح): IP والمنفذ لا يظهران في source إلا إذا كانت الطابعة مُفعَّلة

// تهيئة الطابعة الحرارية
ThermalPrinter.configure({
    paperWidth: THERMAL_WIDTH,
    charsPerLine: THERMAL_WIDTH === '58mm' ? 32 : 48,
    printerIp: THERMAL_IP,
    printerPort: parseInt(THERMAL_PORT) || 9100,
    autoCut: THERMAL_AUTO_CUT,
    cashDrawer: THERMAL_CASH_DRAWER,
});

// بيانات آخر إيصال (للطباعة الحرارية)
let lastReceiptData = null;

// === التبديل بين تبويبات الهاتف ===
let currentMobileTab = 'catalog';

function switchMobileTab(tab) {
    currentMobileTab = tab;
    const layout = document.getElementById('posLayout');
    const tabs = document.querySelectorAll('.pos-mobile-tab');
    const floatBar = document.getElementById('mobileFloatBar');

    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tab));

    if (tab === 'cart') {
        layout.classList.add('pos-show-cart');
        // إظهار الشريط العائم فقط إذا كانت السلة غير فارغة
        if (floatBar && cart.length > 0) floatBar.style.display = 'flex';
    } else {
        layout.classList.remove('pos-show-cart');
        if (floatBar) floatBar.style.display = 'none';
    }
}

function updateMobileCartBadge() {
    const badge = document.getElementById('mobileCartBadge');
    if (!badge) return;
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// === حالة السلة ===
let cart = [];
let currentCategory = 0;
let currentPage = 1;
let searchTimeout = null;
let searchHighlightIndex = -1;
let searchResults = [];
let discount = 0;  // cart-level absolute discount amount (computed from type+value)
let discountType = 'amount';  // v6.1.6 (W11): 'amount' | 'percent' — type of cart-level discount
let discountValue = 0;  // v6.1.6 (W11): raw value entered by user (amount or percent)
let editingItemDiscountId = null;  // v6.1.6 (W12): cart item id being edited in the modal
let itemDiscountType = 'amount';  // v6.1.6 (W12): type selected in the modal
let heldCarts = [];
let heldCartCounter = 0;

// === تهيئة ===
document.addEventListener('DOMContentLoaded', function() {
    loadHeldCartsFromStorage();
    loadProducts();
    startClock();
    setupSearch();
    setupKeyboardShortcuts();
    // v6.1.6 (W15): تسجيل Service Worker + مؤشر حالة الاتصال
    registerPosServiceWorker();
    setupOfflineDetection();
});

// === ساعة POS ===
function startClock() {
    function updateClock() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('posClock').textContent = h + ':' + m + ':' + s;
    }
    updateClock();
    setInterval(updateClock, 1000);
}

// === تحميل المنتجات ===
async function loadProducts(page, category) {
    page = page || currentPage;
    category = (category !== undefined) ? category : currentCategory;

    const grid = document.getElementById('posProductsGrid');
    grid.innerHTML = '<div class="pos-loading"><div class="spinner" style="width:32px;height:32px;"></div><p>جاري تحميل المنتجات...</p></div>';

    try {
        let url = POS_BASE_URL + '/api/search_products.php?per_page=40&page=' + page;
        if (category > 0) url += '&category=' + category;

        const res = await fetch(url);

        // معالجة خاصة للردود غير JSON (مثل صفحات خطأ HTML أو تحذيرات PHP)
        let data;
        try {
            const responseText = await res.text();
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            // الخادم أرجع HTML أو شيئاً غير JSON — غالباً خطأ PHP أو إعادة توجيه
            const statusHint = res.status === 401 ? 'انتهت صلاحية الجلسة' :
                               res.status === 403 ? 'ليس لديك صلاحية' :
                               'خطأ في الخادم (HTTP ' + res.status + ')';
            grid.innerHTML = '<div class="pos-loading"><p>' + statusHint + '</p></div>';
            return;
        }

        // استخراج المصفوفة بأمان — نتأكد أنها مصفوفة حقيقية
        let products = data.products || data.data || [];
        if (!Array.isArray(products)) {
            // قد يكون data.data كائناً يحيط بمصفوفة (مثل ApiResponse)
            if (products && typeof products === 'object' && Array.isArray(products.data)) {
                products = products.data;
            } else {
                products = [];
            }
        }
        const totalPages = (data.pagination && data.pagination.total_pages) ? data.pagination.total_pages : 1;

        if (data.success === false && data.message && products.length === 0) {
            // الخادم أرجع خطأ مع رسالة
            grid.innerHTML = '<div class="pos-loading"><p>' + App.escapeHtml(data.message) + '</p></div>';
            return;
        }

        if (products.length === 0) {
            grid.innerHTML = '<div class="pos-loading"><p>لا توجد منتجات</p></div>';
            return;
        }

        // === جلب صور المنتجات — كل منتج يأخذ صورة أو /uploads/products.png ===
        await fetchProductImages(products);

        grid.innerHTML = '';
        products.forEach(p => {
            const card = createProductCard(p);
            grid.appendChild(card);
        });

        renderPagination(page, totalPages);

    } catch (err) {
        grid.innerHTML = '<div class="pos-loading"><p>خطأ في تحميل المنتجات</p></div>';
    }
}

// === الصورة الافتراضية (SVG placeholder) ===
var POS_PLACEHOLDER_IMG = 'data:image/svg+xml;base64,' + btoa('<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" rx="8" fill="#f3f4f6"/><rect x="20" y="16" width="40" height="36" rx="4" fill="#d1d5db"/><circle cx="34" cy="30" r="5" fill="#9ca3af"/><path d="M22 48 L34 38 L44 46 L50 40 L58 48 L58 52 L22 52 Z" fill="#9ca3af"/></svg>');

// === جلب صور المنتجات من pos-product-images.php ===
// API يُرجع مسار مثل "/uploads/products/photo.jpg" أو "/uploads/products/5/products.png"
async function fetchProductImages(products) {
    if (!products || products.length === 0) return;

    try {
        const ids = products.map(p => p.id).join(',');
        const res = await fetch(POS_BASE_URL + '/api/pos-product-images.php?ids=' + ids, {
            credentials: 'same-origin'
        });
        if (!res.ok) {
            products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
            return;
        }

        const responseText = await res.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
            return;
        }

        if (!data || !data.images) {
            products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
            return;
        }

        // كل منتج يأخذ صورته
        // API يُرجع: { "images": { "1": "/uploads/photo.jpg", "2": "/uploads/products/2/products.png" } }
        products.forEach(function(p) {
            var imgPath = data.images[p.id];
            if (imgPath) {
                p.image = (imgPath.startsWith('http') || imgPath.startsWith('data:')) ? imgPath : (POS_BASE_URL + imgPath);
            } else {
                p.image = POS_PLACEHOLDER_IMG;
            }
        });
    } catch (e) {
        products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
    }
}

// === بطاقة منتج ===
function createProductCard(product) {
    var card = document.createElement('div');
    card.className = 'pos-product-card' + (parseFloat(product.quantity) <= 0 ? ' out-of-stock' : '');
    card.onclick = function() { addToCart(product); };

    var qty = parseFloat(product.quantity || 0);
    var minQty = parseFloat(product.min_quantity || 5);
    var stockClass = 'in-stock';
    var stockText = qty + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'no-stock'; stockText = 'نفد'; }
    else if (qty <= minQty) { stockClass = 'low-stock'; stockText = qty + ' ' + (product.unit || ''); }

    var catColor = product.category_color || '#6366f1';

    // === صورة المنتج — ضمان: دائماً صورة (حقيقية أو placeholder) ===
    var imgUrl = product.image || POS_PLACEHOLDER_IMG;
    var imgHtml = '<div class="pos-pimg-wrap"><img src="' + imgUrl + '" alt="' + App.escapeHtml(product.name || '') + '" loading="lazy"></div>';

    card.innerHTML =
        '<div class="pos-pcat-bar" style="background:' + catColor + '"></div>' +
        imgHtml +
        '<div class="pos-product-card-body">' +
            '<div class="pos-product-name">' + App.escapeHtml(product.name || '') + '</div>' +
            '<div class="pos-product-code">' + App.escapeHtml(product.code || '') + '</div>' +
        '</div>' +
        '<div class="pos-product-bottom">' +
            '<span class="pos-product-stock ' + stockClass + '">' + stockText + '</span>' +
            '<span class="pos-product-price">' + parseFloat(product.selling_price || 0).toFixed(2) + '</span>' +
        '</div>';

    return card;
}

// === ترقيم الصفحات ===
function renderPagination(current, total) {
    const container = document.getElementById('posPagination');
    if (total <= 1) { container.innerHTML = ''; return; }

    let html = '';
    html += `<button ${current <= 1 ? 'disabled' : ''} onclick="loadProducts(${current - 1})">&#8594;</button>`;

    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);

    for (let i = start; i <= end; i++) {
        html += `<button class="${i === current ? 'active' : ''}" onclick="loadProducts(${i})">${i}</button>`;
    }

    html += `<button ${current >= total ? 'disabled' : ''} onclick="loadProducts(${current + 1})">&#8592;</button>`;
    container.innerHTML = html;
}

// === فلتر التصنيفات ===
function filterCategory(catId) {
    currentCategory = catId;
    currentPage = 1;

    document.querySelectorAll('.pos-cat-btn').forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.category) === catId);
    });

    loadProducts(1, catId);
}

// === بحث المنتجات ===
function setupSearch() {
    const input = document.getElementById('posSearchInput');
    const dropdown = document.getElementById('posSearchDropdown');

    input.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const q = this.value.trim();

        if (q.length < 1) {
            dropdown.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(() => searchProducts(q), 200);
    });

    input.addEventListener('keydown', function(e) {
        if (!dropdown.style.display || dropdown.style.display === 'none') return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            searchHighlightIndex = Math.min(searchHighlightIndex + 1, searchResults.length - 1);
            updateSearchHighlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            searchHighlightIndex = Math.max(searchHighlightIndex - 1, -1);
            updateSearchHighlight();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // إذا كان هناك نتيجة مُحددة في القائمة — أضفها
            if (searchHighlightIndex >= 0 && searchResults[searchHighlightIndex]) {
                addToCart(searchResults[searchHighlightIndex]);
                input.value = '';
                dropdown.style.display = 'none';
            }
            // إذا كانت القائمة مخفية لكن هناك نتيجة واحدة (باركود) — أضفها مباشرة
            else if ((dropdown.style.display === 'none' || !dropdown.style.display) && searchResults.length === 1) {
                addToCart(searchResults[0]);
                input.value = '';
                input.focus();
            }
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('posSearchWrapper').contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
}

async function searchProducts(query) {
    const dropdown = document.getElementById('posSearchDropdown');
    const resultsDiv = document.getElementById('posSearchResults');

    try {
        const url = POS_BASE_URL + '/api/search_products.php?q=' + encodeURIComponent(query) + '&per_page=15';
        const res = await fetch(url);

        // معالجة خاصة للردود غير JSON (مثل صفحات خطأ HTML أو تحذيرات PHP)
        let data;
        try {
            const responseText = await res.text();
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            // الخادم أرجع HTML أو شيئاً غير JSON — غالباً خطأ PHP أو إعادة توجيه
            const statusHint = res.status === 401 ? 'انتهت صلاحية الجلسة' :
                               res.status === 403 ? 'ليس لديك صلاحية' :
                               'خطأ في البحث (HTTP ' + res.status + ')';
            resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#dc2626;">' + statusHint + '</div>';
            dropdown.style.display = 'flex';
            return;
        }

        // استخراج المصفوفة بأمان — نتأكد أنها مصفوفة حقيقية
        let products = data.products || data.data || [];
        if (!Array.isArray(products)) {
            if (products && typeof products === 'object' && Array.isArray(products.data)) {
                products = products.data;
            } else {
                products = [];
            }
        }
        searchResults = products;
        searchHighlightIndex = -1;

        // جلب صور نتائج البحث
        await fetchProductImages(products);

        // إذا أرجع الخادم رسالة خطأ مع عدم وجود نتائج
        if (data.success === false && data.message && products.length === 0) {
            resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#dc2626;">' + App.escapeHtml(data.message) + '</div>';
            dropdown.style.display = 'flex';
            return;
        }

        if (products.length === 0) {
            resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#94a3b8;">لا توجد نتائج</div>';
            dropdown.style.display = 'flex';
            return;
        }

        let html = '';
        products.forEach((p, i) => {
            const qty = parseFloat(p.quantity || 0);
            const stockColor = qty > 0 ? (qty <= 5 ? '#d97706' : '#059669') : '#dc2626';
            const pImgUrl = p.image || POS_PLACEHOLDER_IMG;
            let thumbHtml = '';
            if (pImgUrl) {
                thumbHtml = `<div class="pos-search-result-thumb"><img src="${App.escapeHtml(pImgUrl)}" alt="" loading="lazy"></div>`;
            } else {
                thumbHtml = `<div class="pos-search-result-thumb"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20" style="color:var(--gray-300);"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`;
            }
            html += `
                <div class="pos-search-result-item" data-index="${i}" onclick="selectSearchResult(${i})" style="gap:10px;">
                    ${thumbHtml}
                    <div style="flex:1; min-width:0;">
                        <div class="pos-search-result-name">${App.escapeHtml(p.name)}</div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span class="pos-search-result-code">${App.escapeHtml(p.code || '')}</span>
                            <span class="pos-search-result-stock" style="color:${stockColor}; font-size:0.75rem;">مخزون: ${qty}</span>
                        </div>
                    </div>
                    <span class="pos-search-result-price">${parseFloat(p.selling_price || 0).toFixed(2)} ${POS_CURRENCY}</span>
                </div>
            `;
        });

        resultsDiv.innerHTML = html;
        dropdown.style.display = 'flex';

    } catch (err) {
        resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#dc2626;">خطأ في البحث</div>';
        dropdown.style.display = 'flex';
    }
}

function selectSearchResult(index) {
    if (searchResults[index]) {
        addToCart(searchResults[index]);
        document.getElementById('posSearchInput').value = '';
        document.getElementById('posSearchDropdown').style.display = 'none';
    }
}

function updateSearchHighlight() {
    document.querySelectorAll('.pos-search-result-item').forEach((item, i) => {
        item.classList.toggle('highlighted', i === searchHighlightIndex);
    });
}

// === إدارة السلة ===
function addToCart(product) {
    const qty = parseFloat(product.quantity || 0);
    if (qty <= 0) {
        App.toast('المنتج غير متوفر في المخزون', 'error');
        return;
    }

    const existing = cart.find(item => item.id === product.id);
    if (existing) {
        if (existing.quantity + 1 > qty) {
            App.toast('الكمية المطلوبة تتجاوز المخزون المتوفر', 'warning');
            return;
        }
        existing.quantity += 1;
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            code: product.code || '',
            unit: product.unit || '',
            price: parseFloat(product.selling_price || 0),
            quantity: 1,
            maxStock: qty,
            image: product.image || POS_PLACEHOLDER_IMG,
            itemDiscount: { type: 'amount', value: 0 },  // v6.1.6 (W12): خصم فردي على هذا الصنف
        });
    }

    playSound('beep');
    renderCart();

    if (POS_AUTO_COMPLETE) {
        document.getElementById('paidAmount').focus();
    } else {
        document.getElementById('posSearchInput').focus();
    }
}

function removeFromCart(productId) {
    cart = cart.filter(item => item.id !== productId);
    renderCart();
}

// v6.1.6 (W12 إصلاح): فتح نافذة خصم الصنف الفردي
function openItemDiscountModal(productId) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    editingItemDiscountId = productId;
    document.getElementById('itemDiscountItemName').textContent = item.name + ' (' + item.price.toFixed(2) + ' × ' + item.quantity + ')';
    // تهيئة النوع من قيمة الصنف الحالية
    itemDiscountType = (item.itemDiscount && item.itemDiscount.type) ? item.itemDiscount.type : 'amount';
    setItemDiscountType(itemDiscountType);
    // ملء الحقل بالقيمة الحالية (إن وجدت)
    document.getElementById('itemDiscountValue').value = (item.itemDiscount && item.itemDiscount.value > 0) ? item.itemDiscount.value : '';
    document.getElementById('itemDiscountModal').classList.add('open');
    setTimeout(() => document.getElementById('itemDiscountValue').focus(), 50);
}

function closeItemDiscountModal() {
    document.getElementById('itemDiscountModal').classList.remove('open');
    editingItemDiscountId = null;
}

function setItemDiscountType(type) {
    if (type !== 'amount' && type !== 'percent') return;
    itemDiscountType = type;
    document.getElementById('itemDiscountTypeAmount').classList.toggle('active', type === 'amount');
    document.getElementById('itemDiscountTypePercent').classList.toggle('active', type === 'percent');
    const input = document.getElementById('itemDiscountValue');
    input.placeholder = type === 'percent'
        ? PTXT.t_pos_item_discount_modal_ph_percent
        : PTXT.t_pos_item_discount_modal_ph_amount;
}

// v6.1.6 (W12 إصلاح): حفظ خصم الصنف
function saveItemDiscount() {
    if (editingItemDiscountId === null) return;
    const item = cart.find(i => i.id === editingItemDiscountId);
    if (!item) { closeItemDiscountModal(); return; }

    const val = parseFloat(document.getElementById('itemDiscountValue').value || 0);
    if (isNaN(val) || val < 0) {
        App.toast(PTXT.t_pos_discount_invalid, 'error');
        return;
    }
    if (itemDiscountType === 'percent' && val > 100) {
        App.toast(PTXT.t_pos_discount_invalid, 'error');
        return;
    }
    if (itemDiscountType === 'amount' && val > (item.price * item.quantity)) {
        App.toast(PTXT.t_pos_discount_exceeds_total, 'error');
        return;
    }
    // إذا كانت القيمة 0، أزل الخصم
    if (val === 0) {
        item.itemDiscount = { type: 'amount', value: 0 };
    } else {
        item.itemDiscount = { type: itemDiscountType, value: val };
    }
    closeItemDiscountModal();
    renderCart();
    App.toast(PTXT.t_pos_discount_applied, 'success');
}

// v6.1.6 (W12 إصلاح): إزالة خصم الصنف
function removeItemDiscount(productId) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    item.itemDiscount = { type: 'amount', value: 0 };
    renderCart();
}

// v6.1.6 (W12 إصلاح): إغلاق النافذة عند النقر خارجها
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('itemDiscountModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeItemDiscountModal();
        });
    }
    // إغلاق بمفتاح Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('open')) {
            closeItemDiscountModal();
        }
    });
});

function updateQuantity(productId, delta) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;

    const newQty = item.quantity + delta;
    if (newQty <= 0) {
        removeFromCart(productId);
        return;
    }
    if (newQty > item.maxStock) {
        App.toast('الكمية تتجاوز المخزون المتوفر', 'warning');
        return;
    }

    item.quantity = newQty;
    renderCart();
}

function setQuantity(productId, qty) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;

    if (qty <= 0) {
        removeFromCart(productId);
        return;
    }
    if (qty > item.maxStock) {
        App.toast('الكمية تتجاوز المخزون المتوفر', 'warning');
        return;
    }

    item.quantity = qty;
    renderCart();
}

function handleQtyInput(productId, value) {
    let qty = parseFloat(value);
    if (isNaN(qty) || qty <= 0) {
        // إعادة القيمة السابقة
        const item = cart.find(i => i.id === productId);
        if (item) {
            const input = document.querySelector(`.pos-cart-item-qty-input`);
            // نعيد الرندر لاستعادة القيمة الصحيحة
            renderCart();
        }
        return;
    }
    // تقريب إلى رقمين عشريين
    qty = Math.round(qty * 100) / 100;
    setQuantity(productId, qty);
}

function clearCart() {
    if (cart.length === 0) return;
    cart = [];
    discount = 0;
    discountType = 'amount';  // v6.1.6 (W11): إعادة تعيين نوع خصم السلة
    discountValue = 0;  // v6.1.6 (W11): إعادة تعيين قيمة خصم السلة
    setDiscountType('amount');  // v6.1.6 (W11): إعادة واجهة المُبدّل للحالة الافتراضية
    document.getElementById('discountValue').value = '';
    document.getElementById('discountInput').style.display = 'none';
    document.getElementById('discountRow').style.display = 'none';
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    const summary = document.getElementById('cartSummary');
    const payBtn = document.getElementById('payBtn');
    const floatBar = document.getElementById('mobileFloatBar');
    const floatTotal = document.getElementById('mobileFloatTotal');
    const floatPayBtn = document.getElementById('mobileFloatPayBtn');

    // تحديث عداد السلة دائماً (حتى عند الإفراغ)
    document.getElementById('cartCount').textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
    updateMobileCartBadge();

    if (cart.length === 0) {
        container.innerHTML = `
            <div class="pos-cart-empty" id="cartEmpty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="64" height="64">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
                </svg>
                <p>${PTXT.t_pos_cart_empty}</p>
                <span>${PTXT.t_pos_cart_empty_hint}</span>
            </div>
        `;
        summary.style.display = 'none';
        payBtn.disabled = true;
        if (floatBar) floatBar.style.display = 'none';
        if (floatPayBtn) floatPayBtn.disabled = true;
        return;
    }

    summary.style.display = 'block';
    payBtn.disabled = false;
    if (floatPayBtn) floatPayBtn.disabled = false;

    let html = '';
    let subtotal = 0;

    cart.forEach(item => {
        // v6.1.6 (W12 إصلاح): lineTotal يحسب بعد خصم الصنف الفردي
        const lineTotal = computeLineTotal(item);
        const lineTotalRaw = item.price * item.quantity;
        const itemDiscAmt = computeItemDiscountAmount(item);
        subtotal += lineTotal;

        // === صورة مصغرة للمنتج في السلة ===
        let cartThumbHtml = '';
        if (item.image) {
            cartThumbHtml = `<div class="pos-cart-item-thumb"><img src="${App.escapeHtml(item.image)}" alt="" loading="lazy"></div>`;
        } else {
            cartThumbHtml = `<div class="pos-cart-item-thumb"><div class="pos-cart-thumb-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div></div>`;
        }

        // v6.1.6 (W12 إصلاح): شارة خصم الصنف + أزرار تعديل/إزالة
        let itemDiscountHtml = '';
        if (itemDiscAmt > 0) {
            const discLabel = item.itemDiscount.type === 'percent'
                ? '-' + item.itemDiscount.value + '%'
                : '-' + itemDiscAmt.toFixed(2) + ' ' + POS_CURRENCY;
            itemDiscountHtml = `
                <div class="pos-cart-item-discount-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    ${discLabel}
                </div>
                <div class="pos-cart-item-discount-actions">
                    <button class="pos-cart-item-discount-btn" onclick="openItemDiscountModal(${item.id})" title="${PTXT.t_pos_item_discount_edit}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        ${PTXT.t_pos_item_discount_edit}
                    </button>
                    <button class="pos-cart-item-discount-btn" onclick="removeItemDiscount(${item.id})" title="${PTXT.t_pos_item_discount_remove}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        ${PTXT.t_pos_item_discount_remove}
                    </button>
                </div>
            `;
        }

        // v6.1.6 (W12 إصلاح): زر "إضافة خصم" إن لم يكن هناك خصم
        let addItemDiscountBtnHtml = '';
        if (itemDiscAmt === 0) {
            addItemDiscountBtnHtml = `
                <button class="pos-cart-item-discount-btn" onclick="openItemDiscountModal(${item.id})" title="${PTXT.t_pos_item_discount_add}" style="font-size:0.7rem; color:#92400e; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-top:2px;">
                    + ${PTXT.t_pos_item_discount_add}
                </button>
            `;
        }

        html += `
            <div class="pos-cart-item">
                ${cartThumbHtml}
                <div class="pos-cart-item-info">
                    <div class="pos-cart-item-name">${App.escapeHtml(item.name)}</div>
                    <div class="pos-cart-item-detail">
                        <span class="pos-cart-item-price">${item.price.toFixed(2)} × ${Number.isInteger(item.quantity) ? item.quantity : item.quantity.toFixed(2)}</span>
                        ${item.unit ? '<span class="pos-cart-item-unit">' + App.escapeHtml(item.unit) + '</span>' : ''}
                    </div>
                    ${itemDiscountHtml}
                    ${addItemDiscountBtnHtml}
                </div>
                <div class="pos-cart-item-total-section">
                    <span class="pos-cart-item-total">${lineTotal.toFixed(2)}</span>
                    ${itemDiscAmt > 0 ? '<span style="text-decoration:line-through; color:#9ca3af; font-size:0.75rem;">' + lineTotalRaw.toFixed(2) + '</span>' : ''}
                    <button class="pos-cart-item-delete" onclick="removeFromCart(${item.id})" title="حذف">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                        </svg>
                    </button>
                </div>
                <div class="pos-cart-item-actions">
                    <button class="pos-cart-item-qty-btn pos-qty-remove" onclick="updateQuantity(${item.id}, -1)">−</button>
                    <input type="number" class="pos-cart-item-qty-input" value="${item.quantity}" min="0.01" step="0.01" onchange="handleQtyInput(${item.id}, this.value)" onblur="handleQtyInput(${item.id}, this.value)">
                    <button class="pos-cart-item-qty-btn" onclick="updateQuantity(${item.id}, 1)">+</button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;

    // حساب الإجمالي
    let tax = 0;
    let total = subtotal;

    if (POS_TAX_ENABLED) {
        tax = subtotal * (POS_TAX_RATE / 100);
        total = subtotal + tax;
    }

    const finalTotal = total - discount;

    document.getElementById('subtotalAmount').textContent = subtotal.toFixed(2) + ' ' + POS_CURRENCY;
    document.getElementById('totalAmount').textContent = finalTotal.toFixed(2) + ' ' + POS_CURRENCY;
    document.getElementById('payBtnTotal').textContent = finalTotal.toFixed(2);

    // تحديث الشريط العائم
    if (floatTotal) floatTotal.textContent = finalTotal.toFixed(2) + ' ' + POS_CURRENCY;
    if (floatBar) floatBar.style.display = 'flex';

    if (POS_TAX_ENABLED) {
        document.getElementById('taxAmount').textContent = tax.toFixed(2) + ' ' + POS_CURRENCY;
    }

    if (discount > 0) {
        document.getElementById('discountAmount').textContent = '-' + discount.toFixed(2) + ' ' + POS_CURRENCY;
        document.getElementById('discountRow').style.display = 'flex';
    }

    calculateChange();
}

// === الخصم ===
function toggleDiscount() {
    const input = document.getElementById('discountInput');
    input.style.display = input.style.display === 'none' ? 'block' : 'none';
    if (input.style.display === 'block') {
        document.getElementById('discountValue').focus();
    }
}

// v6.1.6 (W11 إصلاح): تبديل نوع خصم السلة (مبلغ/نسبة)
function setDiscountType(type) {
    if (type !== 'amount' && type !== 'percent') return;
    discountType = type;
    // تحديث الأزرار النشطة
    document.getElementById('discountTypeAmount').classList.toggle('active', type === 'amount');
    document.getElementById('discountTypePercent').classList.toggle('active', type === 'percent');
    // تحديث placeholder الحقل
    const input = document.getElementById('discountValue');
    input.placeholder = type === 'percent'
        ? PTXT.t_pos_discount_value_ph_percent
        : PTXT.t_pos_discount_value_ph_amount;
    input.focus();
}

// v6.1.6 (W11 إصلاح): تطبيق الخصم على السلة — يحسب المبلغ المطلق من النوع والقيمة
function applyDiscount() {
    const val = parseFloat(document.getElementById('discountValue').value || 0);
    if (isNaN(val) || val < 0) {
        App.toast(PTXT.t_pos_discount_invalid, 'error');
        return;
    }
    // حساب المجموع الفرعي الحالي (بعد خصم الأصناف الفردية إن وجدت)
    let subtotal = cart.reduce((sum, item) => sum + computeLineTotal(item), 0);
    let computed;
    if (discountType === 'percent') {
        if (val > 100) {
            App.toast(PTXT.t_pos_discount_invalid, 'error');
            return;
        }
        computed = subtotal * (val / 100);
    } else {
        if (val > subtotal) {
            App.toast(PTXT.t_pos_discount_exceeds_total, 'error');
            return;
        }
        computed = val;
    }
    discountValue = val;
    discount = Math.round(computed * 100) / 100;  // تقريب لسنتين
    renderCart();
    App.toast(PTXT.t_pos_discount_applied, 'success');
}

// v6.1.6 (W12 إصلاح): حساب إجمالي الصنف بعد خصمه الفردي (إن وجد)
function computeLineTotal(item) {
    const raw = item.price * item.quantity;
    if (!item.itemDiscount || !item.itemDiscount.value || item.itemDiscount.value <= 0) {
        return raw;
    }
    let disc;
    if (item.itemDiscount.type === 'percent') {
        disc = raw * (item.itemDiscount.value / 100);
    } else {
        disc = Math.min(item.itemDiscount.value, raw);  // لا يمكن أن يتجاوز إجمالي الصنف
    }
    return Math.max(0, raw - disc);
}

// v6.1.6 (W12 إصلاح): حساب مبلغ خصم الصنف المطلق (للعرض في الشارة)
function computeItemDiscountAmount(item) {
    if (!item.itemDiscount || !item.itemDiscount.value || item.itemDiscount.value <= 0) return 0;
    const raw = item.price * item.quantity;
    if (item.itemDiscount.type === 'percent') {
        return Math.round(raw * (item.itemDiscount.value / 100) * 100) / 100;
    }
    return Math.min(item.itemDiscount.value, raw);
}

// === حساب الباقي ===
function calculateChange() {
    const paid = parseFloat(document.getElementById('paidAmount').value || 0);
    const cardPaid = parseFloat(document.getElementById('cardAmount')?.value || 0);
    // v6.1.6 (W12 إصلاح): subtotal بعد خصم الأصناف الفردية
    let subtotal = cart.reduce((sum, item) => sum + computeLineTotal(item), 0);
    let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    let total = subtotal + tax - discount;

    const changeDisplay = document.getElementById('changeDisplay');
    const changeAmount = document.getElementById('changeAmount');

    // v6.1.4 (W6 إصلاح): حساب الباقي حسب طريقة الدفع
    let totalPaid = paid;
    if (POS_PAYMENT_METHOD === 'split') {
        totalPaid = paid + cardPaid;
    } else if (POS_PAYMENT_METHOD === 'card') {
        // بطاقة: لا يوجد باقٍ (المدفوع = الإجمالي تلقائياً)
        changeDisplay.style.display = 'none';
        return;
    }

    if (totalPaid > 0 && totalPaid >= total) {
        const change = totalPaid - total;
        changeDisplay.style.display = 'flex';
        changeAmount.textContent = change.toFixed(2) + ' ' + POS_CURRENCY;
    } else {
        changeDisplay.style.display = 'none';
    }
}

// === v6.1.4 (W6 إصلاح): اختيار طريقة الدفع ===
function selectPaymentMethod(method) {
    if (!['cash', 'card', 'split'].includes(method)) return;
    POS_PAYMENT_METHOD = method;

    // تحديث الأزرار البصرية
    document.querySelectorAll('.pos-pm-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.pm === method);
    });

    const cashField = document.getElementById('cashField');
    const cardField = document.getElementById('cardField');
    const paidInput = document.getElementById('paidAmount');
    const cardInput = document.getElementById('cardAmount');
    const quickAmounts = document.getElementById('quickAmounts');

    if (method === 'cash') {
        cashField.style.display = '';
        cardField.style.display = 'none';
        quickAmounts.style.display = '';
        paidInput.value = '';
        if (cardInput) cardInput.value = '';
    } else if (method === 'card') {
        // بطاقة: المبلغ = الإجمالي تلقائياً، لا حاجة لإدخال يدوي
        let subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
        let total = subtotal + tax - discount;
        paidInput.value = total.toFixed(2);
        cashField.style.display = '';
        cardField.style.display = 'none';
        quickAmounts.style.display = 'none';
    } else if (method === 'split') {
        cashField.style.display = '';
        cardField.style.display = '';
        quickAmounts.style.display = '';
        paidInput.value = '';
        if (cardInput) cardInput.value = '';
    }

    calculateChange();
}

// === مبالغ سريعة ===
function setQuickAmount(type) {
    let subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    let total = subtotal + tax - discount;

    const paidInput = document.getElementById('paidAmount');

    if (type === 'exact') {
        paidInput.value = total.toFixed(2);
    } else if (type === 'round') {
        paidInput.value = Math.ceil(total).toFixed(2);
    } else {
        paidInput.value = parseFloat(type).toFixed(2);
    }

    calculateChange();
}

// === تعليق الطلب ===
function holdCart() {
    if (cart.length === 0) return;

    heldCartCounter++;
    const now = new Date();
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    const total = subtotal + tax - discount;

    heldCarts.push({
        id: heldCartCounter,
        items: JSON.parse(JSON.stringify(cart)),
        discount: discount,
        discountType: discountType,  // v6.1.6 (W11): حفظ نوع خصم السلة
        discountValue: discountValue,  // v6.1.6 (W11): حفظ قيمة خصم السلة الخام
        subtotal: subtotal,
        tax: tax,
        total: total,
        time: now.toLocaleTimeString('ar-MA', { hour: '2-digit', minute: '2-digit' }),
        date: now.toLocaleDateString('ar-MA', { day: '2-digit', month: '2-digit', year: 'numeric' }),
        timestamp: now.getTime(),
        itemCount: cart.reduce((sum, item) => sum + item.quantity, 0),
    });

    saveHeldCartsToStorage();
    clearCart();
    updateHeldCartsBadge();
    App.toast('تم تعليق الطلب رقم #' + heldCartCounter, 'info');
}

// === حفظ/تحميل الطلبات المعلقة من localStorage ===
function saveHeldCartsToStorage() {
    try {
        localStorage.setItem('pos_heldCarts', JSON.stringify(heldCarts));
        localStorage.setItem('pos_heldCartCounter', heldCartCounter.toString());
    } catch (e) {}
}

function loadHeldCartsFromStorage() {
    try {
        const saved = localStorage.getItem('pos_heldCarts');
        const savedCounter = localStorage.getItem('pos_heldCartCounter');
        if (saved) {
            heldCarts = JSON.parse(saved);
        }
        if (savedCounter) {
            heldCartCounter = parseInt(savedCounter, 10) || 0;
        }
    } catch (e) {
        heldCarts = [];
        heldCartCounter = 0;
    }
    updateHeldCartsBadge();
}

// === تحديث عداد الطلبات المعلقة ===
function updateHeldCartsBadge() {
    const badge = document.getElementById('heldBadge');
    if (!badge) return;
    const count = heldCarts.length;
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// === عرض الطلبات المعلقة ===
function showHeldCarts() {
    const overlay = document.getElementById('heldOverlay');
    const body = document.getElementById('heldModalBody');
    const footer = document.getElementById('heldModalFooter');
    const countEl = document.getElementById('heldCount');

    countEl.textContent = heldCarts.length;

    if (heldCarts.length === 0) {
        body.innerHTML = '<div class="pos-held-empty">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="56" height="56">' +
                '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>' +
            '</svg>' +
            '<p>لا توجد طلبات معلقة</p>' +
            '<span>يمكنك تعليق طلب من زر "تعليق" في السلة</span>' +
        '</div>';
        footer.style.display = 'none';
    } else {
        let html = '';
        heldCarts.forEach(function(held, index) {
            const itemsHtml = held.items.map(function(item) {
                return '<div class="pos-held-item-row">' +
                    '<span class="pos-held-item-name">' + App.escapeHtml(item.name) + '</span>' +
                    '<span class="pos-held-item-qty">x' + item.quantity + '</span>' +
                    '<span class="pos-held-item-price">' + (item.price * item.quantity).toFixed(2) + ' ' + POS_CURRENCY + '</span>' +
                '</div>';
            }).join('');

            const discountHtml = held.discount > 0
                ? '<div class="pos-held-card-discount">' +
                      '<span>الخصم</span>' +
                      '<span>-' + held.discount.toFixed(2) + ' ' + POS_CURRENCY + '</span>' +
                  '</div>'
                : '';

            html += '<div class="pos-held-card">' +
                '<div class="pos-held-card-header">' +
                    '<div class="pos-held-card-info">' +
                        '<span class="pos-held-card-num">#' + held.id + '</span>' +
                        '<div class="pos-held-card-meta">' +
                            '<span>' + held.time + '</span>' +
                            '<span>' + held.itemCount + ' منتج</span>' +
                        '</div>' +
                    '</div>' +
                    '<span class="pos-held-card-total">' + held.total.toFixed(2) + ' ' + POS_CURRENCY + '</span>' +
                '</div>' +
                discountHtml +
                '<div class="pos-held-card-items">' + itemsHtml + '</div>' +
                '<div class="pos-held-card-actions">' +
                    '<button type="button" class="pos-held-restore-btn" onclick="restoreHeldCart(' + index + ')">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">' +
                            '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>' +
                        '</svg>' +
                        'استعادة' +
                    '</button>' +
                    '<button type="button" class="pos-held-delete-btn" onclick="deleteHeldCart(' + index + ')">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">' +
                            '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>' +
                        '</svg>' +
                        'حذف' +
                    '</button>' +
                '</div>' +
            '</div>';
        });
        body.innerHTML = html;
        footer.style.display = 'flex';
    }

    overlay.style.display = 'flex';
}

// === إغلاق نافذة الطلبات المعلقة ===
function closeHeldCarts(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('heldOverlay').style.display = 'none';
}

// === استعادة طلب معلق ===
function restoreHeldCart(index) {
    if (index < 0 || index >= heldCarts.length) return;

    const held = heldCarts[index];

    // إذا كانت السلة غير فارغة، اطلب تأكيد الاستبدال
    if (cart.length > 0) {
        showConfirmDialog(
            'warning',
            'السلة غير فارغة',
            'السلة الحالية تحتوي على ' + cart.reduce((s, i) => s + i.quantity, 0) + ' منتج. هل تريد استبدالها بالطلب المعلق #' + held.id + '؟',
            'استبدال',
            function() {
                doRestoreHeldCart(index);
            }
        );
    } else {
        doRestoreHeldCart(index);
    }
}

function doRestoreHeldCart(index) {
    const held = heldCarts[index];

    // استعادة السلة
    cart = JSON.parse(JSON.stringify(held.items));
    discount = held.discount || 0;
    // v6.1.6 (W11 إصلاح): استعادة نوع وقيمة خصم السلة
    discountType = held.discountType || 'amount';
    discountValue = held.discountValue || 0;

    // حذف الطلب المعلق
    heldCarts.splice(index, 1);
    saveHeldCartsToStorage();
    updateHeldCartsBadge();

    // إغلاق النافذة
    document.getElementById('heldOverlay').style.display = 'none';

    // تحديث واجهة السلة
    if (discount > 0) {
        document.getElementById('discountValue').value = discountValue;
        setDiscountType(discountType);  // v6.1.6 (W11): تحديث المُبدّل والأزرار
        document.getElementById('discountRow').style.display = 'flex';
        document.getElementById('discountAmount').textContent = '-' + discount.toFixed(2);
    }

    renderCart();

    // في الهاتف، انتقل تلقائياً لتبويب السلة
    if (window.innerWidth <= 768) {
        switchMobileTab('cart');
    }

    App.toast('تم استعادة الطلب المعلق #' + held.id, 'success');
}

// === حذف طلب معلق ===
function deleteHeldCart(index) {
    if (index < 0 || index >= heldCarts.length) return;

    const held = heldCarts[index];
    showConfirmDialog(
        'danger',
        'حذف الطلب المعلق',
        'هل أنت متأكد من حذف الطلب المعلق #' + held.id + '؟ لا يمكن التراجع عن هذا الإجراء.',
        'حذف',
        function() {
            heldCarts.splice(index, 1);
            saveHeldCartsToStorage();
            updateHeldCartsBadge();
            showHeldCarts(); // تحديث العرض
            App.toast('تم حذف الطلب المعلق', 'info');
        }
    );
}

// === حذف كل الطلبات المعلقة ===
function deleteAllHeldCarts() {
    if (heldCarts.length === 0) return;

    showConfirmDialog(
        'danger',
        'حذف جميع الطلبات المعلقة',
        'هل أنت متأكد من حذف جميع الطلبات المعلقة (' + heldCarts.length + ' طلب)؟ لا يمكن التراجع عن هذا الإجراء.',
        'حذف الكل',
        function() {
            heldCarts = [];
            saveHeldCartsToStorage();
            updateHeldCartsBadge();
            showHeldCarts(); // تحديث العرض
            App.toast('تم حذف جميع الطلبات المعلقة', 'info');
        }
    );
}

// === نافذة تأكيد مخصصة ===
function showConfirmDialog(type, title, message, okText, onConfirm) {
    // إزالة أي نافذة تأكيد سابقة
    const existing = document.getElementById('posConfirmOverlay');
    if (existing) existing.remove();

    const iconSvg = type === 'warning'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';

    const overlay = document.createElement('div');
    overlay.id = 'posConfirmOverlay';
    overlay.className = 'pos-confirm-overlay';
    overlay.innerHTML =
        '<div class="pos-confirm-dialog">' +
            '<div class="pos-confirm-icon ' + type + '">' + iconSvg + '</div>' +
            '<h4 class="pos-confirm-title">' + App.escapeHtml(title) + '</h4>' +
            '<p class="pos-confirm-msg">' + App.escapeHtml(message) + '</p>' +
            '<div class="pos-confirm-actions">' +
                '<button type="button" class="pos-confirm-cancel" id="posConfirmCancel">إلغاء</button>' +
                '<button type="button" class="pos-confirm-ok ' + type + '" id="posConfirmOk">' + App.escapeHtml(okText) + '</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    document.getElementById('posConfirmCancel').addEventListener('click', function() {
        overlay.remove();
    });

    document.getElementById('posConfirmOk').addEventListener('click', function() {
        overlay.remove();
        if (typeof onConfirm === 'function') onConfirm();
    });

    // إغلاق بالنقر خارج النافذة
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.remove();
    });
}

// === معالجة الدفع ===
async function processPayment() {
    if (cart.length === 0) return;

    const paid = parseFloat(document.getElementById('paidAmount').value || 0);
    const cardPaid = parseFloat(document.getElementById('cardAmount')?.value || 0);
    // v6.1.6 (W12 إصلاح): subtotal بعد خصم الأصناف الفردية
    let subtotal = cart.reduce((sum, item) => sum + computeLineTotal(item), 0);
    let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    let total = subtotal + tax - discount;

    // v6.1.4 (W6 إصلاح): التحقق من صحة الدفع حسب الطريقة المختارة
    let totalPaid = paid;
    if (POS_PAYMENT_METHOD === 'split') {
        totalPaid = paid + cardPaid;
        if (paid <= 0 || cardPaid <= 0) {
            App.toast(PTXT.t_pos_split_required, 'error');
            return;
        }
    } else if (POS_PAYMENT_METHOD === 'card') {
        totalPaid = paid;  // paidAmount = total تلقائياً
    }

    if (totalPaid < total) {
        App.toast(PTXT.t_pos_paid_less_than_total, 'error');
        return;
    }

    const payBtn = document.getElementById('payBtn');
    payBtn.disabled = true;
    payBtn.innerHTML = '<div class="spinner" style="width:20px;height:20px;border-top-color:#fff;"></div> ' + PTXT.t_pos_processing;

    // v6.1.6 (W12 إصلاح): إرسال خصم الصنف الفردي + نوعه في الـ payload
    const items = cart.map(item => ({
        product_id: item.id,
        quantity: item.quantity,
        selling_price: item.price,
        item_discount: computeItemDiscountAmount(item),  // المبلغ المطلق المحسوب في JS (للتحقق جانب الخادم)
        item_discount_type: (item.itemDiscount && item.itemDiscount.value > 0) ? item.itemDiscount.type : 'amount',
        item_discount_value: (item.itemDiscount && item.itemDiscount.value > 0) ? item.itemDiscount.value : 0,
    }));

    try {
        const res = await fetch(POS_BASE_URL + '/api/pos-process-sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': POS_CSRF,
            },
            body: JSON.stringify({
                items: items,
                sale_date: new Date().toISOString().split('T')[0],
                notes: '',
                discount: discount,  // v6.1.6 (W11): المبلغ المطلق للخصم على مستوى السلة (محسوب من type+value)
                discount_type: discountType,  // v6.1.6 (W11): 'amount' | 'percent' — للتحقق جانب الخادم
                discount_value: discountValue,  // v6.1.6 (W11): القيمة الخام التي أدخلها المستخدم
                tax_amount: tax,  // W14 إصلاح: إرسال الضريبة للخادم للمقارنة والتحقق
                paid_amount: totalPaid,  // v6.1.4 (W6): المبلغ الكلي المدفوع
                payment_method: POS_PAYMENT_METHOD,  // v6.1.4 (W6): طريقة الدفع
                cash_amount: POS_PAYMENT_METHOD === 'split' ? paid : totalPaid,  // للدفع المقسّم: النقد فقط
                card_amount: POS_PAYMENT_METHOD === 'split' ? cardPaid : 0,  // للدفع المقسّم: البطاقة فقط
                csrf_token: POS_CSRF,
            }),
        });

        // معالجة خاصة للردود غير JSON (مثل صفحات خطأ HTML)
        let data;
        try {
            const responseText = await res.text();
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            // الخادم أرجع HTML أو شيئاً غير JSON — غالباً خطأ PHP أو إعادة توجيه
            if (res.status === 401) {
                App.toast(PTXT.t_pos_session_expired, 'error');
            } else if (res.status === 403) {
                App.toast(PTXT.t_pos_no_permission, 'error');
            } else {
                App.toast(PTXT.t_pos_server_error.replace('{status}', res.status), 'error');
            }
            payBtn.disabled = false;
            payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> ${PTXT.t_pos_pay_btn} (<span id="payBtnTotal">${total.toFixed(2)}</span> ${POS_CURRENCY})`;
            return;
        }

        if (data.success) {
            playSound('success');

            // تحديث CSRF
            if (data.data && data.data.csrf_token) {
                POS_CSRF = data.data.csrf_token;
            }

            // عرض الإيصال
            showReceipt(data.data, paid);

            // نجاح
            payBtn.classList.add('success');
            setTimeout(() => {
                payBtn.classList.remove('success');
                payBtn.disabled = false;
                payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> ${PTXT.t_pos_pay_btn} (<span id="payBtnTotal">0.00</span> ${POS_CURRENCY})`;
            }, 1000);
        } else {
            // رسالة خطأ مفصلة من الخادم
            // دعم تنسيقين: posJsonError ترسل {message} و ErrorHandler يرسل {error:{message}}
            let errorMsg = data.message || (data.error && data.error.message) || PTXT.t_pos_sale_failed;
            // إذا كانت الرسالة تحتوي على أسطر جديدة، اعرض السطر الأول فقط مع تفاصيل
            if (errorMsg.includes('\n')) {
                errorMsg = errorMsg.split('\n')[0];
            }
            App.toast(errorMsg, 'error');
            payBtn.disabled = false;
            payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> ${PTXT.t_pos_pay_btn} (<span id="payBtnTotal">${total.toFixed(2)}</span> ${POS_CURRENCY})`;
        }
    } catch (err) {
        App.toast(PTXT.t_pos_connection_error, 'error');
        payBtn.disabled = false;
        payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> ${PTXT.t_pos_pay_btn} (<span id="payBtnTotal">${total.toFixed(2)}</span> ${POS_CURRENCY})`;
    }
}

// === الإيصال ===
function showReceipt(data, paid) {
    // v6.1.6 (W12 إصلاح): عرض خصم الصنف الفردي في الإيصال لو وجد
    const itemsHtml = data.items.map(item => {
        // إن وُجد خصم على الصنف، اعرض السعر قبل/بعد + سطر خصم
        const hasItemDisc = (typeof item.item_discount === 'number') && item.item_discount > 0;
        const lineGross = (typeof item.line_gross === 'number') ? item.line_gross : (item.price * item.quantity);
        const discType = item.item_discount_type || 'amount';
        const discValue = item.item_discount_value || 0;
        const discLabel = hasItemDisc
            ? (discType === 'percent' ? '-' + discValue + '%' : '-' + item.item_discount.toFixed(2) + ' ' + POS_CURRENCY)
            : '';
        return `
            <div class="pos-receipt-item">
                <span class="pos-receipt-item-name">${App.escapeHtml(item.name)}</span>
                <span class="pos-receipt-item-qty">${item.quantity} ${App.escapeHtml(item.unit || '')}</span>
                <span class="pos-receipt-item-total">${item.total.toFixed(2)}</span>
                ${hasItemDisc ? `
                    <span class="pos-receipt-item-discount" style="grid-column:1/-1; font-size:0.75em; color:#92400e; padding-top:2px; border-top:1px dashed #fcd34d; margin-top:2px;">
                        ${PTXT.t_pos_item_discount}: ${discLabel} (${lineGross.toFixed(2)} → ${item.total.toFixed(2)})
                    </span>
                ` : ''}
            </div>
        `;
    }).join('');
    document.getElementById('receiptItems').innerHTML = itemsHtml;

    let totalsHtml = `
        <div class="pos-receipt-total-row">
            <span>${PTXT.t_pos_subtotal_label}</span>
            <span>${data.subtotal.toFixed(2)} ${POS_CURRENCY}</span>
        </div>
    `;

    if (POS_TAX_ENABLED) {
        // W14 إصلاح: استخدم مبلغ الضريبة المحسوبة في الخادم (data.tax_amount) بدل إعادة حسابها في JS
        const taxAmt = (typeof data.tax_amount === 'number') ? data.tax_amount : (data.subtotal * POS_TAX_RATE / 100);
        if (taxAmt > 0) {
            totalsHtml += `
                <div class="pos-receipt-total-row">
                    <span>${PTXT.t_pos_tax_label}</span>
                    <span>${taxAmt.toFixed(2)} ${POS_CURRENCY}</span>
                </div>
            `;
        }
    }

    if (data.discount > 0) {
        totalsHtml += `
            <div class="pos-receipt-total-row">
                <span>${PTXT.t_pos_discount_label}</span>
                <span>-${data.discount.toFixed(2)} ${POS_CURRENCY}</span>
            </div>
        `;
    }

    totalsHtml += `
        <div class="pos-receipt-total-row grand-total">
            <span>${PTXT.t_pos_total_label}</span>
            <span>${data.total.toFixed(2)} ${POS_CURRENCY}</span>
        </div>
        <div class="pos-receipt-total-row">
            <span>${PTXT.t_pos_paid_label}</span>
            <span>${data.paid.toFixed(2)} ${POS_CURRENCY}</span>
        </div>
    `;

    // v6.1.4 (W6 إصلاح): عرض طريقة الدفع ومكوناتها في الإيصال
    if (data.payment_method) {
        const pmLabel = data.payment_method === 'cash' ? PTXT.t_pos_pm_cash
                      : data.payment_method === 'card' ? PTXT.t_pos_pm_card
                      : PTXT.t_pos_pm_split;
        totalsHtml += `
            <div class="pos-receipt-total-row" style="font-size:0.85em; color:#6b7280;">
                <span>${PTXT.t_pos_payment_method_label}</span>
                <span>${pmLabel}</span>
            </div>
        `;
        if (data.payment_method === 'split' && data.cash_amount !== undefined && data.card_amount !== undefined) {
            totalsHtml += `
                <div class="pos-receipt-total-row" style="font-size:0.8em; color:#6b7280;">
                    <span>${PTXT.t_pos_pm_cash}</span>
                    <span>${data.cash_amount.toFixed(2)} ${POS_CURRENCY}</span>
                </div>
                <div class="pos-receipt-total-row" style="font-size:0.8em; color:#6b7280;">
                    <span>${PTXT.t_pos_pm_card}</span>
                    <span>${data.card_amount.toFixed(2)} ${POS_CURRENCY}</span>
                </div>
            `;
        }
    }

    if (data.change > 0) {
        totalsHtml += `
            <div class="pos-receipt-total-row" style="font-weight:700; color:#059669;">
                <span>${PTXT.t_pos_change_label}</span>
                <span>${data.change.toFixed(2)} ${POS_CURRENCY}</span>
            </div>
        `;
    }

    document.getElementById('receiptTotals').innerHTML = totalsHtml;
    document.getElementById('receiptDateTime').textContent = formatDateStr(data.date) + ' ' + data.time;

    // v6.1.6: عرض رقم الإيصال
    const receiptNoEl = document.getElementById('receiptNo');
    const rNo = data.receipt_no || (data.sale_ids && data.sale_ids.length > 0 ? '#' + data.sale_ids[0] : '');
    if (receiptNoEl && rNo) {
        receiptNoEl.textContent = PTXT.t_pos_receipt_number + ': ' + rNo;
        receiptNoEl.style.display = 'block';
    } else if (receiptNoEl) {
        receiptNoEl.style.display = 'none';
    }

    document.getElementById('receiptOverlay').style.display = 'flex';

    // حفظ بيانات الإيصال للطباعة الحرارية
    lastReceiptData = data;

    // طباعة تلقائية إذا مفعّلة
    if (THERMAL_ENABLED && THERMAL_AUTO_PRINT) {
        setTimeout(function() { printReceiptThermal(); }, 500);
    }
}

function formatDateStr(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    var fmt = window.DATE_FORMAT || 'Y-m-d';
    var yyyy = d.getFullYear();
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var dd = String(d.getDate()).padStart(2, '0');
    switch (fmt) {
        case 'd/m/Y': return dd + '/' + mm + '/' + yyyy;
        case 'm/d/Y': return mm + '/' + dd + '/' + yyyy;
        default: return yyyy + '-' + mm + '-' + dd;
    }
}

function printReceipt() {
    window.print();
}

// === طباعة حرارية (ESC/POS) ===
async function printReceiptThermal() {
    if (!lastReceiptData) {
        App.toast(PTXT.t_pos_no_receipt_data, 'error');
        return;
    }

    const data = lastReceiptData;

    // بناء بيانات الإيصال للطابعة الحرارية
    const receiptData = {
        storeName: document.getElementById('receiptStoreName') ? document.getElementById('receiptStoreName').textContent : PTXT.x_htmlspecialchars_addslas,
        storeAddress: PTXT.x_htmlspecialchars_addslas_2,
        storePhone: PTXT.x_htmlspecialchars_addslas_3,
        dateTime: document.getElementById('receiptDateTime') ? document.getElementById('receiptDateTime').textContent : (data.date + ' ' + data.time),
        cashier: PTXT.x_htmlspecialchars_addslas_4,
        receiptNo: data.receipt_no || (data.sale_ids && data.sale_ids.length > 0 ? '#' + data.sale_ids[0] : ''),  // v6.1.6: رقم الإيصال (بديل: sale_id إذا لم يوجد)
        items: data.items || [],
        subtotal: data.subtotal || 0,
        // W14 إصلاح: استخدم مبلغ الضريبة من الخادم
        tax: (typeof data.tax_amount === 'number') ? data.tax_amount : (POS_TAX_ENABLED ? (data.subtotal || 0) * (POS_TAX_RATE / 100) : 0),
        discount: data.discount || 0,
        total: data.total || 0,
        paid: data.paid || 0,
        change: data.change || 0,
        payment_method: data.payment_method || 'cash',  // v6.1.4 (W6): طريقة الدفع في الإيصال الحراري
        cash_amount: data.cash_amount || 0,  // v6.1.4 (W6): للدفع المقسّم
        card_amount: data.card_amount || 0,  // v6.1.4 (W6): للدفع المقسّم
        footer: THERMAL_FOOTER || PTXT.t_pos_receipt_thanks,
        appName: PTXT.x_htmlspecialchars_addslas_5,
    };

    try {
        // تحديد وضع الطباعة
        let mode = 'browser';
        if (THERMAL_TYPE === 'serial') {
            mode = 'thermal-serial';
        } else if (THERMAL_TYPE === 'network') {
            mode = 'thermal-network';
        } else {
            mode = 'html'; // طباعة HTML حرارية عبر window.print()
        }

        await ThermalPrinter.print(receiptData, mode);
        App.toast(PTXT.t_pos_thermal_sent, 'success');
    } catch (err) {
        console.error('Thermal print error:', err);
        let errorMsg = PTXT.t_pos_thermal_failed;
        if (err.message) {
            if (err.message.includes('Web Serial')) {
                errorMsg = PTXT.t_pos_thermal_no_usb;
            } else if (err.message.includes('الاتصال')) {
                errorMsg = PTXT.t_pos_thermal_connection_failed;
            } else {
                errorMsg = PTXT.t_pos_thermal_error.replace('{msg}', err.message);
            }
        }
        App.toast(errorMsg, 'error');

        // كبديل، افتح طباعة المتصفح
        if (confirm(PTXT.t_pos_thermal_fallback_confirm)) {
            window.print();
        }
    }
}

function newSale() {
    document.getElementById('receiptOverlay').style.display = 'none';
    cart = [];
    discount = 0;
    discountType = 'amount';  // v6.1.6 (W11): إعادة تعيين نوع خصم السلة
    discountValue = 0;  // v6.1.6 (W11): إعادة تعيين قيمة خصم السلة
    setDiscountType('amount');  // v6.1.6 (W11): إعادة واجهة المُبدّل
    document.getElementById('paidAmount').value = '';
    // v6.1.4 (W6 إصلاح): إعادة تعيين طريقة الدفع إلى نقداً + إخفاء حقل البطاقة
    if (document.getElementById('cardAmount')) {
        document.getElementById('cardAmount').value = '';
    }
    selectPaymentMethod('cash');
    document.getElementById('discountValue').value = '';
    document.getElementById('discountInput').style.display = 'none';
    renderCart();
    document.getElementById('posSearchInput').focus();

    // العودة لتبويب المنتجات على الهاتف
    switchMobileTab('catalog');

    // إعادة تحميل المنتجات لتحديث المخزون
    loadProducts();
}

// === تبديل السمة ===
document.getElementById('posThemeToggle').addEventListener('click', function() {
    const layout = document.getElementById('posLayout');
    layout.classList.toggle('pos-dark');

    const isDark = layout.classList.contains('pos-dark');
    const icon = document.getElementById('themeIcon');

    if (isDark) {
        icon.innerHTML = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
    } else {
        icon.innerHTML = '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>';
    }

    // حفظ التفضيل محلياً
    localStorage.setItem('pos_theme', isDark ? 'dark' : 'light');
});

// تحميل السمة المحفوظة
(function() {
    const saved = localStorage.getItem('pos_theme');
    if (saved === 'dark' && PTXT.x_pos_theme === 'light') {
        document.getElementById('posLayout').classList.add('pos-dark');
    }
})();

// === أصوات ===
// v6.1.6 (W17 إصلاح): AudioContext واحد يُعاد استخدامه — المتصفحات تحدّ من عدد Contexts
let _audioCtx = null;
function playSound(type) {
    if (!POS_SOUND_ENABLED) return;
    try {
        if (!_audioCtx) {
            _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (_audioCtx.state === 'suspended') {
            _audioCtx.resume();
        }
        const oscillator = _audioCtx.createOscillator();
        const gainNode = _audioCtx.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(_audioCtx.destination);

        if (type === 'beep') {
            oscillator.frequency.value = 800;
            gainNode.gain.value = 0.1;
            oscillator.start();
            oscillator.stop(_audioCtx.currentTime + 0.1);
        } else if (type === 'success') {
            oscillator.frequency.value = 1000;
            gainNode.gain.value = 0.1;
            oscillator.start();
            setTimeout(() => { oscillator.frequency.value = 1200; }, 100);
            oscillator.stop(_audioCtx.currentTime + 0.25);
        }
    } catch (e) {}
}

// === اختصارات لوحة المفاتيح ===
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // F2: تركيز البحث
        if (e.key === 'F2') {
            e.preventDefault();
            document.getElementById('posSearchInput').focus();
        }
        // F4: دفع
        if (e.key === 'F4' && cart.length > 0) {
            e.preventDefault();
            processPayment();
        }
        // F8: عملية جديدة
        if (e.key === 'F8') {
            e.preventDefault();
            const overlay = document.getElementById('receiptOverlay');
            if (overlay.style.display !== 'none') {
                newSale();
            }
        }
        // Escape: إغلاق الإيصال أو الطلبات المعلقة
        if (e.key === 'Escape') {
            const confirmOverlay = document.getElementById('posConfirmOverlay');
            if (confirmOverlay) {
                confirmOverlay.remove();
                return;
            }
            const heldOverlay = document.getElementById('heldOverlay');
            if (heldOverlay && heldOverlay.style.display !== 'none') {
                heldOverlay.style.display = 'none';
                return;
            }
            const receiptOverlay = document.getElementById('receiptOverlay');
            if (receiptOverlay.style.display !== 'none') {
                newSale();
            }
        }
    });
}

// === مسح الباركود بالكاميرا ===
document.getElementById('posScanBtn').addEventListener('click', function() {
    if (typeof openBarcodeScanner === 'function') {
        openBarcodeScanner();
    } else {
        App.toast('ماسح الباركود غير متاح', 'warning');
    }
});

// === ردّ مسح الباركود: عند مسح باركود بالكاميرا، ابحث عن المنتج وأضفه ===
window.onBarcodeScanned = function(scannedCode, target) {
    var searchInput = document.getElementById('posSearchInput');
    searchInput.value = scannedCode;
    searchProducts(scannedCode).then(function() {
        // إذا وُجد منتج واحد فقط، أضفه مباشرة
        if (searchResults.length === 1) {
            addToCart(searchResults[0]);
            searchInput.value = '';
            document.getElementById('posSearchDropdown').style.display = 'none';
        } else if (searchResults.length > 1) {
            // عدة نتائج: اعرض القائمة المنسدلة
            App.toast('تم العثور على ' + searchResults.length + ' منتجات — اختر من القائمة', 'info');
            searchInput.focus();
        } else {
            // لا نتائج
            App.toast('لم يتم العثور على منتج بالباركود: ' + scannedCode, 'warning');
            searchInput.value = '';
        }
    });
};

// === مستمع ماسح الباركود بالأجهزة (Hardware Scanner) ===
// ماسحات USB/Bluetooth تُحاكي إدخال لوحة مفاتيح سريع + Enter
(function() {
    var hwBuffer = '';
    var hwTimeout = null;
    var HW_MIN_LENGTH = 3;     // أقل طول للباركود
    var HW_MAX_GAP = 80;       // أقصى فترة بين ضغطتين (ms) — ماسح الأجهزة سريع جداً

    document.addEventListener('keypress', function(e) {
        // تجاهل إذا كان التركيز في حقل إدخال (عدا البحث)
        var activeEl = document.activeElement;
        var isSearchInput = activeEl && activeEl.id === 'posSearchInput';
        var isOtherInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT') && !isSearchInput;

        // إذا كان التركيز في حقل آخر (مثل المبلغ المدفوع)، لا نعترض
        if (isOtherInput) return;

        // تجاهل المفاتيح الوظيفية والاختصارات
        if (e.ctrlKey || e.altKey || e.metaKey) return;
        if (e.key === 'F2' || e.key === 'F4' || e.key === 'F8' || e.key === 'Escape') return;

        // إذا كان حقل البحث نشطاً وكان فارغاً، ابدأ تجميع الباركود
        if (e.key === 'Enter') {
            if (hwBuffer.length >= HW_MIN_LENGTH) {
                e.preventDefault();
                e.stopPropagation();
                var code = hwBuffer.trim();
                hwBuffer = '';
                clearTimeout(hwTimeout);

                // بحث وإضافة المنتج
                searchProducts(code).then(function() {
                    if (searchResults.length === 1) {
                        addToCart(searchResults[0]);
                        searchInput.value = '';
                        document.getElementById('posSearchDropdown').style.display = 'none';
                    } else if (searchResults.length > 1) {
                        searchInput.value = code;
                        searchInput.focus();
                    } else {
                        App.toast('لم يتم العثور على منتج بالباركود: ' + code, 'warning');
                    }
                });
                return;
            }
            // إذا البافر قصير، لا نعترض (Enter عادي)
            hwBuffer = '';
            clearTimeout(hwTimeout);
            return;
        }

        // تجميع الأحرف
        hwBuffer += e.key;
        clearTimeout(hwTimeout);
        hwTimeout = setTimeout(function() {
            // إذا انقضت الفترة بدون إكمال، أفرغ البافر
            hwBuffer = '';
        }, HW_MAX_GAP);
    }, true); // capture phase لاعتراض الحدث مبكراً
})();

// ==============================================================
// v6.1.6 (W15): PWA — Service Worker + Offline Detection
// ==============================================================
function registerPosServiceWorker() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./pos-sw.js', { scope: './' })
            .then(function(reg) {
                console.log('[POS] Service Worker مسجل:', reg.scope);
            })
            .catch(function(err) {
                console.warn('[POS] فشل تسجيل Service Worker:', err);
            });
    }
}

function setupOfflineDetection() {
    const banner = document.getElementById('posOfflineBanner');
    function update() {
        if (banner) banner.style.display = navigator.onLine ? 'none' : 'flex';
    }
    window.addEventListener('online', function() {
        update();
        // إعادة تحميل المنتجات عند عودة الاتصال
        loadProducts();
        App.toast(PTXT.t_pos_back_online, 'success');
    });
    window.addEventListener('offline', update);
    update(); // فحص أولي
}

// ==============================================================
// v6.1.6 (W16): المرتجعات من POS
// ==============================================================
let returnOriginalData = null; // بيانات العملية الأصلية

function openReturnModal() {
    document.getElementById('returnOverlay').style.display = 'flex';
    document.getElementById('returnStep1').style.display = 'block';
    document.getElementById('returnStep2').style.display = 'none';
    document.getElementById('returnStep3').style.display = 'none';
    document.getElementById('returnSaleIds').value = '';
    document.getElementById('returnSearchResult').innerHTML = '';
    returnOriginalData = null;
    setTimeout(function() { document.getElementById('returnReceiptDate').focus(); }, 100);
}

/**
 * تبديل تبويب البحث في نافذة المرتجعات (رقم إيصال / أرقام أصناف) — v6.3.1
 */
function switchReturnSearchTab(mode) {
    var tabReceipt = document.getElementById('tabReceipt');
    var tabIds = document.getElementById('tabIds');
    var searchReceipt = document.getElementById('returnSearchReceipt');
    var searchIds = document.getElementById('returnSearchIds');

    if (mode === 'receipt') {
        tabReceipt.style.background = '#2563eb';
        tabReceipt.style.color = '#fff';
        tabIds.style.background = '#f1f5f9';
        tabIds.style.color = '#64748b';
        searchReceipt.style.display = 'flex';
        searchIds.style.display = 'none';
    } else {
        tabIds.style.background = '#2563eb';
        tabIds.style.color = '#fff';
        tabReceipt.style.background = '#f1f5f9';
        tabReceipt.style.color = '#64748b';
        searchReceipt.style.display = 'none';
        searchIds.style.display = 'block';
    }
    // مسح نتائج البحث السابقة
    document.getElementById('returnSearchResult').innerHTML = '';
}

function closeReturnModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('returnOverlay').style.display = 'none';
}

function backToReturnStep1() {
    document.getElementById('returnStep1').style.display = 'block';
    document.getElementById('returnStep2').style.display = 'none';
    returnOriginalData = null;
}

function searchOriginalSale() {
    // تحديد نوع البحث من التبويب النشط
    var searchMode = document.getElementById('returnSearchReceipt').style.display !== 'none' ? 'receipt' : 'ids';
    var resultDiv = document.getElementById('returnSearchResult');
    resultDiv.innerHTML = '<div style="text-align:center;padding:20px;color:#6b7280;">' + PTXT.t_pos_return_searching + '...</div>';

    var url;
    if (searchMode === 'receipt') {
        // بناء رقم الإيصال من التاريخ + الرقم
        var dateVal = document.getElementById('returnReceiptDate').value; // YYYY-MM-DD
        var numVal = document.getElementById('returnReceiptNum').value.trim();
        if (!dateVal) { App.toast(PTXT.t_pos_return_select_date_fb, 'warning'); resultDiv.innerHTML = ''; return; }
        if (!numVal || !/^\d{1,4}$/.test(numVal)) { App.toast(PTXT.t_pos_return_enter_num_fb, 'warning'); resultDiv.innerHTML = ''; return; }
        // تحويل YYYY-MM-DD إلى YYYYMMDD
        var datePart = dateVal.replace(/-/g, '');
        var numPart = numVal.padStart(4, '0');
        var receiptNo = datePart + '-' + numPart;
        url = POS_BASE_URL + '/api/pos-search-sale.php?receipt_no=' + encodeURIComponent(receiptNo);
    } else {
        // بحث بأرقام الأصناف
        var query = document.getElementById('returnSaleIds').value.trim();
        if (!query) { App.toast(PTXT.t_pos_return_enter_value, 'warning'); resultDiv.innerHTML = ''; return; }
        url = POS_BASE_URL + '/api/pos-search-sale.php?ids=' + encodeURIComponent(query);
    }

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                resultDiv.innerHTML = '<div style="color:#dc2626;padding:10px;">' + App.escapeHtml(data.message) + '</div>';
                return;
            }
            returnOriginalData = data.data;
            showReturnStep2(data.data);
        })
        .catch(function(err) {
            resultDiv.innerHTML = '<div style="color:#dc2626;padding:10px;">' + PTXT.t_pos_return_connection_error + '</div>';
        });
}

function showReturnStep2(saleData) {
    document.getElementById('returnStep1').style.display = 'none';
    document.getElementById('returnStep2').style.display = 'block';

    var html = '<div style="margin-bottom:12px; font-size:0.85rem; color:#6b7280;">' +
        PTXT.t_pos_return_original_info +
        ' ' + saleData.sale_date + ' | ' + saleData.item_count + ' ' + PTXT.t_pos_return_items +
        ' | ' + PTXT.t_pos_total_label + ': ' + saleData.total.toFixed(2) + ' ' + POS_CURRENCY;
    if (saleData.receipt_no) {
        html += ' | ' + PTXT.t_pos_receipt_number + ': ' + saleData.receipt_no;
    }
    html += '</div>';

    saleData.items.forEach(function(item) {
        html += '<div class="pos-return-item-row" data-sale-id="' + item.id + '" data-product-id="' + item.product_id + '">' +
            '<input type="checkbox" class="pos-return-item-check" checked>' +
            '<div class="pos-return-item-info">' +
                '<div class="pos-return-item-name">' + App.escapeHtml(item.product_name) + '</div>' +
                '<div class="pos-return-item-meta">' +
                    item.quantity + ' ' + (item.unit || '') + ' &times; ' + parseFloat(item.selling_price).toFixed(2) + ' ' + POS_CURRENCY +
                    ' = ' + parseFloat(item.total).toFixed(2) + ' ' + POS_CURRENCY +
                '</div>' +
            '</div>' +
            '<input type="number" class="pos-return-qty-input" value="' + item.quantity + '" min="0.01" max="' + item.quantity + '" step="0.01">' +
        '</div>';
    });

    html += '<div class="pos-return-total-row">' +
        '<span>' + PTXT.t_pos_return_total_label + '</span>' +
        '<span id="returnTotalPreview">0.00 ' + POS_CURRENCY + '</span>' +
    '</div>';

    document.getElementById('returnOriginalItems').innerHTML = html;

    // حساب الإجمالي عند التغيير
    document.querySelectorAll('.pos-return-item-row').forEach(function(row) {
        row.querySelector('.pos-return-item-check').addEventListener('change', updateReturnTotal);
        row.querySelector('.pos-return-qty-input').addEventListener('input', updateReturnTotal);
    });
    updateReturnTotal();
}

function updateReturnTotal() {
    var total = 0;
    document.querySelectorAll('.pos-return-item-row').forEach(function(row) {
        var checked = row.querySelector('.pos-return-item-check').checked;
        if (!checked) return;
        var price = parseFloat(returnOriginalData.items.find(function(it) { return it.id == row.dataset.saleId; }).selling_price) || 0;
        var qty = parseFloat(row.querySelector('.pos-return-qty-input').value) || 0;
        total += price * qty;
    });
    var preview = document.getElementById('returnTotalPreview');
    if (preview) preview.textContent = total.toFixed(2) + ' ' + POS_CURRENCY;
}

function processReturn() {
    if (!returnOriginalData) return;

    var items = [];
    var errors = [];

    document.querySelectorAll('.pos-return-item-row').forEach(function(row) {
        var checked = row.querySelector('.pos-return-item-check').checked;
        if (!checked) return;

        var saleId = parseInt(row.dataset.saleId);
        var productId = parseInt(row.dataset.productId);
        var qty = parseFloat(row.querySelector('.pos-return-qty-input').value) || 0;
        var maxQty = parseFloat(returnOriginalData.items.find(function(it) { return it.id == saleId; }).quantity) || 0;

        if (qty <= 0) return;
        if (qty > maxQty) {
            errors.push(PTXT.t_pos_return_qty_exceed);
            return;
        }

        items.push({
            original_sale_id: saleId,
            product_id: productId,
            return_quantity: qty
        });
    });

    if (errors.length > 0) {
        App.toast(errors.join('\n'), 'error');
        return;
    }
    if (items.length === 0) {
        App.toast(PTXT.t_pos_return_select_one, 'warning');
        return;
    }

    var reason = document.getElementById('returnReason').value;

    // تأكيد
    showConfirmDialog('danger',
        PTXT.t_pos_return_confirm_title,
        PTXT.t_pos_return_confirm_msg + ' ' + items.length + ' ' + PTXT.t_pos_return_confirm_products,
        PTXT.t_pos_return_confirm_btn,
        function() { doProcessReturn(items, reason); }
    );
}

function doProcessReturn(items, reason) {
    fetch(POS_BASE_URL + '/api/pos-process-return.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': POS_CSRF },
        body: JSON.stringify({
            csrf_token: POS_CSRF,
            sale_ids: returnOriginalData.sale_ids,
            items: items,
            reason: reason
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            document.getElementById('returnOverlay').style.display = 'none';
            App.toast(data.message || PTXT.t_pos_return_failed, 'error');
            return;
        }
        // تحديث CSRF
        if (data.data && data.data.csrf_token) POS_CSRF = data.data.csrf_token;

        // عرض النتيجة
        document.getElementById('returnStep2').style.display = 'none';
        document.getElementById('returnStep3').style.display = 'block';

        var html = '<svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" width="48" height="48">' +
            '<polyline points="20 6 9 17 4 12"/>' +
            '</svg>' +
            '<h3 style="color:#16a34a; margin:12px 0 8px;">' + PTXT.t_pos_return_success + '</h3>' +
            '<div style="font-size:0.9rem; color:#6b7280;">';
        data.data.items.forEach(function(item) {
            html += item.product_name + ' &times; ' + item.return_quantity + ' = ' + item.refund_total.toFixed(2) + '<br>';
        });
        html += '</div>' +
            '<div style="font-size:1.1rem; font-weight:700; margin-top:12px; color:#dc2626;">' +
            PTXT.t_pos_return_total_label + ': ' + data.data.total_refund.toFixed(2) + ' ' + POS_CURRENCY +
            '</div>';

        document.getElementById('returnResult').innerHTML = html;

        // إعادة تحميل المنتجات (المخزون تغيّر)
        loadProducts();
        playSound('success');
    })
    .catch(function(err) {
        document.getElementById('returnOverlay').style.display = 'none';
        App.toast(PTXT.t_pos_return_connection_error, 'error');
    });
}
