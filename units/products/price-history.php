<?php
$page_title = 'سجل تغيير الأسعار';
require_once '../config/database.php';
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/header.php';

// فلاتر البحث والتصفية
$search_product = trim($_GET['search'] ?? '');
$filter_type = $_GET['type'] ?? 'all'; // all, purchase, selling
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

// بناء استعلام البحث
$where_clauses = ["1=1"];
$params = [];

if (!empty($search_product)) {
    $where_clauses[] = "(p.code LIKE ? OR p.name LIKE ?)";
    $params[] = "%$search_product%";
    $params[] = "%$search_product%";
}

if ($filter_type === 'purchase') {
    $where_clauses[] = "(ph.old_purchase_price != ph.new_purchase_price)";
} elseif ($filter_type === 'selling') {
    $where_clauses[] = "(ph.old_selling_price != ph.new_selling_price)";
}

if (!empty($filter_date_from)) {
    $where_clauses[] = "DATE(ph.changed_at) >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where_clauses[] = "DATE(ph.changed_at) <= ?";
    $params[] = $filter_date_to;
}

$where_sql = implode(' AND ', $where_clauses);

// إحصائيات وسجلات الأسعار (مع حماية في حالة عدم وجود الجدول)
$price_logs = [];
$total_records = 0;
$total_pages = 0;
$offset = ($page - 1) * $per_page;
$total_changes = 0;
$purchase_price_changes = 0;
$selling_price_changes = 0;
$last_change = null;

try {
    // إجمالي السجلات
    $total_records = fetchOne("SELECT COUNT(*) as total FROM price_history ph JOIN products p ON ph.product_id = p.id WHERE $where_sql", $params)['total'] ?? 0;
    $total_pages = ceil($total_records / $per_page);

    // جلب سجلات الأسعار
    $price_logs = fetchAll(
        "SELECT ph.*, p.code, p.name as product_name, p.unit
         FROM price_history ph
         JOIN products p ON ph.product_id = p.id
         WHERE $where_sql
         ORDER BY ph.changed_at DESC
         LIMIT $per_page OFFSET $offset",
        $params
    );

    // إحصائيات سريعة
    $total_changes = fetchOne("SELECT COUNT(*) as total FROM price_history ph JOIN products p ON ph.product_id = p.id", [])['total'] ?? 0;
    $purchase_price_changes = fetchOne("SELECT COUNT(*) as total FROM price_history WHERE old_purchase_price != new_purchase_price", [])['total'] ?? 0;
    $selling_price_changes = fetchOne("SELECT COUNT(*) as total FROM price_history WHERE old_selling_price != new_selling_price", [])['total'] ?? 0;
    $last_change = fetchOne("SELECT MAX(changed_at) as last FROM price_history", [])['last'] ?? null;
} catch (Exception $e) {
    // جدول price_history غير موجود
    $price_logs = [];
    $total_records = 0;
    $total_pages = 0;
    $total_changes = 0;
    $purchase_price_changes = 0;
    $selling_price_changes = 0;
    $last_change = null;
}
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                <path d="M12 9v4m0 0v4m0-4h4m-4 0H8"/>
            </svg>
            سجل تغيير الأسعار
        </h1>
        <a href="list-products.php" class="btn btn-outline">العودة للمنتجات</a>
    </div>

    <!-- إحصائيات سريعة -->
    <div class="stats-grid">
        <div class="stat-card cyan">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="stat-info">
                <h3>إجمالي التغييرات</h3>
                <div class="stat-value"><?php echo number_format($total_changes); ?></div>
            </div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
            </div>
            <div class="stat-info">
                <h3>تغييرات سعر الشراء</h3>
                <div class="stat-value"><?php echo number_format($purchase_price_changes); ?></div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/></svg>
            </div>
            <div class="stat-info">
                <h3>تغييرات سعر البيع</h3>
                <div class="stat-value"><?php echo number_format($selling_price_changes); ?></div>
            </div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-icon" style="color: white;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            </div>
            <div class="stat-info">
                <h3 style="color: rgba(255,255,255,0.85);">آخر تغيير</h3>
                <div class="stat-value" style="color: white; font-size: 0.95rem;"><?php echo $last_change ? date('Y/m/d H:i', strtotime($last_change)) : 'لا يوجد'; ?></div>
            </div>
        </div>
    </div>

    <!-- فلاتر البحث -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header"><h2>بحث وتصفية</h2></div>
        <div class="card-body">
            <form method="GET" action="" id="searchForm" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label>بحث بالمنتج (كود أو اسم)</label>
                    <div class="product-search-wrapper" id="productSearchWrapper">
                        <div class="search-input-container">
                            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" 
                                   name="search" 
                                   id="product_search_input" 
                                   class="form-control search-input" 
                                   placeholder="ابحث بالكود أو اسم المنتج..." 
                                   autocomplete="off"
                                   value="<?php echo htmlspecialchars($search_product); ?>"
                                   onfocus="openSearchDropdown()" 
                                   oninput="searchProducts()">
                            <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearProductSearch()" style="<?php echo !empty($search_product) ? 'display:flex;' : 'display:none;'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                            <button type="button" class="search-scan-btn" onclick="openBarcodeScanner()" title="مسح الباركود بالكاميرا">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                    <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                    <line x1="7" y1="12" x2="17" y2="12"/>
                                </svg>
                            </button>
                            <button type="button" class="search-arrow-btn" id="searchArrowBtn" onclick="toggleSearchDropdown()" style="<?php echo empty($search_product) ? 'display:flex;' : 'display:none;'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                        </div>
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
                </div>
                <div class="form-group" style="min-width: 180px;">
                    <label>نوع التغيير</label>
                    <select name="type" class="form-control">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <option value="purchase" <?php echo $filter_type === 'purchase' ? 'selected' : ''; ?>>تغييرات سعر الشراء</option>
                        <option value="selling" <?php echo $filter_type === 'selling' ? 'selected' : ''; ?>>تغييرات سعر البيع</option>
                    </select>
                </div>
                <div class="form-group" style="min-width: 160px;">
                    <label>من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="form-group" style="min-width: 160px;">
                    <label>إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        بحث
                    </button>
                    <a href="price-history.php" class="btn btn-outline">مسح الفلاتر</a>
                </div>
            </form>
        </div>
    </div>

    <!-- سجل الأسعار -->
    <div class="card">
        <div class="card-header">
            <h2>السجل الكامل لتغيير الأسعار</h2>
            <span class="badge badge-info" style="font-size: 0.85rem;"><?php echo number_format($total_records); ?> سجل</span>
        </div>
        <div class="card-body">
            <?php if (empty($price_logs)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;">
                    <?php if (!empty($search_product) || $filter_type !== 'all' || !empty($filter_date_from) || !empty($filter_date_to)): ?>
                        لا توجد نتائج مطابقة للفلاتر المحددة
                    <?php else: ?>
                        لم يتم تسجيل أي تغيير في الأسعار بعد.<br>
                        <small>يتم تسجيل التغييرات تلقائياً عند: تعديل المنتج، تسجيل عملية شراء بسعر جديد.</small>
                    <?php endif; ?>
                </p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ والوقت</th>
                            <th>المنتج</th>
                            <th>سعر الشراء القديم</th>
                            <th>سعر الشراء الجديد</th>
                            <th>تغيير الشراء</th>
                            <th>سعر البيع القديم</th>
                            <th>سعر البيع الجديد</th>
                            <th>تغيير البيع</th>
                            <th>السبب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = $offset + 1; foreach ($price_logs as $ph):
                            $purchase_diff = floatval($ph['new_purchase_price']) - floatval($ph['old_purchase_price']);
                            $selling_diff = floatval($ph['new_selling_price']) - floatval($ph['old_selling_price']);
                            $purchase_changed = $purchase_diff != 0;
                            $selling_changed = $selling_diff != 0;
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td style="white-space: nowrap; font-size: 0.85rem;">
                                <?php echo date('Y/m/d', strtotime($ph['changed_at'])); ?>
                                <br>
                                <span class="text-muted"><?php echo date('H:i', strtotime($ph['changed_at'])); ?></span>
                            </td>
                            <td>
                                <a href="view-product.php?id=<?php echo $ph['product_id']; ?>" style="text-decoration: none; color: var(--primary);">
                                    <strong><?php echo htmlspecialchars($ph['product_name']); ?></strong>
                                </a>
                                <br>
                                <span class="text-muted" style="font-size: 0.8rem;"><?php echo htmlspecialchars($ph['code']); ?></span>
                            </td>
                            <td style="<?php echo $purchase_changed ? 'text-decoration: line-through; opacity: 0.7;' : ''; ?>">
                                <?php echo formatMoney($ph['old_purchase_price']); ?>
                            </td>
                            <td style="<?php echo $purchase_changed ? 'font-weight: 700; color: var(--danger);' : ''; ?>">
                                <?php echo formatMoney($ph['new_purchase_price']); ?>
                            </td>
                            <td>
                                <?php if ($purchase_changed): ?>
                                    <span class="badge <?php echo $purchase_diff > 0 ? 'badge-danger' : 'badge-success'; ?>" style="font-size: 0.8rem;">
                                        <?php echo ($purchase_diff > 0 ? '+' : '') . formatMoney($purchase_diff); ?>
                                        <?php if ($ph['old_purchase_price'] > 0): ?>
                                            (<?php echo number_format(($purchase_diff / $ph['old_purchase_price']) * 100, 1); ?>%)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="<?php echo $selling_changed ? 'text-decoration: line-through; opacity: 0.7;' : ''; ?>">
                                <?php echo formatMoney($ph['old_selling_price']); ?>
                            </td>
                            <td style="<?php echo $selling_changed ? 'font-weight: 700; color: var(--success);' : ''; ?>">
                                <?php echo formatMoney($ph['new_selling_price']); ?>
                            </td>
                            <td>
                                <?php if ($selling_changed): ?>
                                    <span class="badge <?php echo $selling_diff > 0 ? 'badge-success' : 'badge-danger'; ?>" style="font-size: 0.8rem;">
                                        <?php echo ($selling_diff > 0 ? '+' : '') . formatMoney($selling_diff); ?>
                                        <?php if ($ph['old_selling_price'] > 0): ?>
                                            (<?php echo number_format(($selling_diff / $ph['old_selling_price']) * 100, 1); ?>%)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 200px; font-size: 0.85rem;">
                                <?php if (!empty($ph['change_reason'])): ?>
                                    <span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; display: inline-block;">
                                        <?php echo htmlspecialchars($ph['change_reason']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ترقيم الصفحات -->
            <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                    <a href="?page=1&search=<?php echo urlencode($search_product); ?>&type=<?php echo $filter_type; ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" class="btn btn-sm btn-outline">الأولى</a>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_product); ?>&type=<?php echo $filter_type; ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" class="btn btn-sm btn-outline">السابق</a>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 3);
                $end_page = min($total_pages, $page + 3);
                for ($p = $start_page; $p <= $end_page; $p++):
                ?>
                    <a href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search_product); ?>&type=<?php echo $filter_type; ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>"
                       class="btn btn-sm <?php echo $p == $page ? 'btn-primary' : 'btn-outline'; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_product); ?>&type=<?php echo $filter_type; ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" class="btn btn-sm btn-outline">التالي</a>
                    <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search_product); ?>&type=<?php echo $filter_type; ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" class="btn btn-sm btn-outline">الأخيرة</a>
                <?php endif; ?>

                <span class="text-muted" style="font-size: 0.85rem; margin-right: 10px;">
                    صفحة <?php echo $page; ?> من <?php echo $total_pages; ?>
                </span>
            </div>
            <?php endif; ?>
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
