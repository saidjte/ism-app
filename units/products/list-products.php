<?php
$page_title = 'قائمة المنتجات';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();

// حذف منتج (POST فقط) - يجب أن يكون قبل تضمين header.php لتجنب مشكلة headers already sent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    requireCSRF();
    $delete_id = intval($_POST['product_id'] ?? 0);
    try {
        executeQuery("DELETE FROM products WHERE id = ?", [$delete_id]);
        header("Location: list-products.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        $error_msg = 'حدث خطأ أثناء حذف المنتج';
    }
}

require_once '../includes/header.php';

// رسائل النجاح
$success_msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $success_msg = 'تم حذف المنتج بنجاح';
    if ($_GET['msg'] == 'updated') $success_msg = 'تم تحديث المنتج بنجاح';
}

// جلب التصنيفات للتصفية
$categories = [];
try {
    $categories = fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
} catch (Exception $e) {}

// فلاتر البحث والتصفية
$search = trim($_GET['search'] ?? '');
$filter_category = intval($_GET['category'] ?? 0);

// بناء الفلاتر لـ SearchService
$searchFilters = [];
if (!empty($search)) {
    $searchFilters['search'] = $search;
}
if ($filter_category > 0) {
    $searchFilters['category_id'] = $filter_category;
}

// جلب المنتجات مع اسم التصنيف
$products = [];
try {
    $searchResult = SearchService::searchProducts($searchFilters, 1, 1000, [
        'select_columns' => 'p.*, c.name as category_name, c.color as category_color, (SELECT COUNT(*) FROM additional_units WHERE product_id = p.id) as units_count',
    ]);
    $products = $searchResult['products'];
} catch (Exception $e) {
    ErrorHandler::logException($e, 'units-list-products:fetch');
    $products = [];
}
?>
<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            قائمة المنتجات
        </h1>
        <a href="add-product.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
            إضافة منتوج جديد
        </a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <!-- شريط البحث والتصفية المحسّن -->
    <form method="GET" class="search-bar" id="searchForm">
        <div class="product-search-wrapper" id="productSearchWrapper" style="flex:1; min-width:200px;">
            <div class="search-input-container">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" 
                       name="search" 
                       id="product_search_input" 
                       class="form-control search-input" 
                       placeholder="ابحث بالكود أو اسم المنتج..." 
                       autocomplete="off"
                       value="<?php echo htmlspecialchars($search); ?>"
                       onfocus="openSearchDropdown()" 
                       oninput="searchProducts()">
                <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearProductSearch()" style="<?php echo !empty($search) ? 'display:flex;' : 'display:none;'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <button type="button" class="search-scan-btn" onclick="openBarcodeScanner()" title="مسح الباركود بالكاميرا">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                        <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                        <line x1="7" y1="12" x2="17" y2="12"/>
                    </svg>
                </button>
                <button type="button" class="search-arrow-btn" id="searchArrowBtn" onclick="toggleSearchDropdown()" style="<?php echo empty($search) ? 'display:flex;' : 'display:none;'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="6 9 12 15 18 9"/></svg>
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
        </div>
        <?php if (!empty($categories)): ?>
        <select name="category" class="form-control" style="max-width: 200px;">
            <option value="">كل التصنيفات</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($cat['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            بحث
        </button>
        <?php if (!empty($search) || $filter_category > 0): ?>
            <a href="list-products.php" class="btn btn-outline">مسح الفلاتر</a>
        <?php endif; ?>
    </form>

    <div class="card">
        <div class="card-header">
            <h2>المنتجات (<?php echo count($products); ?>)</h2>
        </div>
        <div class="card-body">
            <?php if (empty($products)): ?>
                <p class="text-muted" style="text-align:center; padding: 3rem;">
                    <?php if (!empty($search) || $filter_category > 0): ?>
                        لا توجد نتائج مطابقة للبحث
                    <?php else: ?>
                        لا توجد منتجات. <a href="add-product.php">أضف منتجك الأول</a>
                    <?php endif; ?>
                </p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>اسم المنتج</th>
                            <th>التصنيف</th>
                            <th>سعر الشراء</th>
                            <th>سعر البيع</th>
                            <th>الوحدة</th>
                            <th>الكمية</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($products as $product):
                            $is_low = $product['min_quantity'] > 0 && $product['quantity'] <= $product['min_quantity'];
                        ?>
                        <tr class="<?php echo $is_low ? 'low-stock' : ''; ?>">
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($product['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td>
                                <?php if (!empty($product['category_name'])): ?>
                                    <span style="display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; color: #fff; background: <?php echo $product['category_color'] ?? '#3b82f6'; ?>;">
                                        <?php echo htmlspecialchars($product['category_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatMoney($product['purchase_price']); ?></td>
                            <td><?php echo formatMoney($product['selling_price']); ?></td>
                            <td><?php echo htmlspecialchars($product['unit']); ?></td>
                            <td>
                                <?php echo number_format($product['quantity'], 2); ?>
                                <?php if ($is_low): ?>
                                    <span class="badge badge-danger">منخفض</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-primary">تعديل</a>
                                    <a href="view-product.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline">تفاصيل</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا المنتج؟')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                    </form>
                                </div>
                            </td>
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
let searchTimeout = null;
let searchResultsData = [];
let highlightedIndex = -1;
let isDropdownOpen = false;

function openSearchDropdown() {
    const input = document.getElementById('product_search_input');
    if (input.value.trim()) {
        document.getElementById('searchClearBtn').style.display = 'flex';
        document.getElementById('searchArrowBtn').style.display = 'none';
    }
    const dropdown = document.getElementById('searchDropdown');
    dropdown.style.display = 'flex';
    isDropdownOpen = true;
    if (!input.value.trim()) searchProducts();
    else {
        document.getElementById('searchClearBtn').style.display = input.value.trim() ? 'flex' : 'none';
        document.getElementById('searchArrowBtn').style.display = input.value.trim() ? 'none' : 'flex';
    }
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
    document.getElementById('searchArrowBtn').style.display = query ? 'none' : 'flex';

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
                        item.addEventListener('mouseenter', () => { clearHighlight(); item.classList.add('keyboard-nav'); highlightedIndex = index; });
                        item.addEventListener('mouseleave', () => item.classList.remove('keyboard-nav'));
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
            .catch(err => { loading.style.display = 'none'; noResults.style.display = 'block'; noResults.textContent = 'حدث خطأ أثناء البحث'; });
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
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    else if (qty <= parseFloat(product.min_quantity || 0)) { stockClass = 'low-stock'; stockText = 'منخفض: ' + qty.toFixed(2) + ' ' + product.unit; }

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
                <span>بيع: ${parseFloat(product.selling_price).toFixed(2)}</span>
                <span>الوحدة: ${escapeHtml(product.unit)}</span>
            </div>
        </div>
        <span class="item-stock ${stockClass}">${stockText}</span>
    `;
    return item;
}

function selectProduct(product) {
    document.getElementById('product_search_input').value = product.name;
    document.getElementById('searchClearBtn').style.display = 'flex';
    document.getElementById('searchArrowBtn').style.display = 'none';
    closeSearchDropdown();
    // إرسال النموذج لإجراء البحث
    document.getElementById('searchForm').submit();
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
    if (e.key === 'ArrowDown') { e.preventDefault(); highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1); updateHighlight(items); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlightedIndex = Math.max(highlightedIndex - 1, 0); updateHighlight(items); }
    else if (e.key === 'Enter' && highlightedIndex >= 0) { e.preventDefault(); items[highlightedIndex].click(); }
    else if (e.key === 'Escape') { closeSearchDropdown(); document.getElementById('product_search_input').blur(); }
});

function updateHighlight(items) {
    items.forEach((item, i) => item.classList.toggle('keyboard-nav', i === highlightedIndex));
    if (items[highlightedIndex]) items[highlightedIndex].scrollIntoView({ block: 'nearest' });
}

document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('productSearchWrapper');
    if (wrapper && !wrapper.contains(e.target)) closeSearchDropdown();
});

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function escapeRegex(string) { return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

// عند مسح باركود - يملأ حقل البحث ويُرسل النموذج
window.onBarcodeScanned = function(code) {
    if (!code) return;
    document.getElementById('product_search_input').value = code;
    document.getElementById('searchClearBtn').style.display = 'flex';
    document.getElementById('searchArrowBtn').style.display = 'none';
    closeSearchDropdown();
    document.getElementById('searchForm').submit();
};
</script>

<?php require_once '../includes/barcode-scanner.php'; ?>
<?php require_once '../includes/footer.php'; ?>
