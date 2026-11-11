<?php
require_once '../includes/bootstrap.php';
$page_title = t('purchases_title');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('purchases.view');

// === رسائل النجاح ===
$success_msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') $success_msg = t('purchases_deleted_success');
    if ($_GET['msg'] === 'updated') $success_msg = t('purchases_updated_success');
}

// === الفلاتر ===
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_supplier = intval($_GET['supplier'] ?? 0);
$view_mode = $_GET['view'] ?? 'grouped'; // grouped | flat

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = '';

// === جلب الموردين ===
$suppliers = [];
try {
    $suppliers = fetchAll("SELECT * FROM suppliers ORDER BY name ASC");
} catch (Exception $e) {}

// === بناء الاستعلام ===
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(pr.name LIKE ? OR pr.code LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if (!empty($date_from)) {
    $where[] = "p.purchase_date >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "p.purchase_date <= ?";
    $params[] = $date_to;
}
if ($filter_supplier > 0) {
    $where[] = "p.supplier_id = ?";
    $params[] = $filter_supplier;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// === العدد الإجمالي ===
$totalPurchases = 0;
try {
    $countResult = fetchOne("SELECT COUNT(*) as total FROM purchases p JOIN products pr ON pr.id = p.product_id LEFT JOIN purchase_orders po ON po.id = p.order_id {$whereClause}", $params);
    $totalPurchases = $countResult ? intval($countResult['total']) : 0;
} catch (Exception $e) { $totalPurchases = 0; }

// === ترقيم الصفحات ===
$pagination = Pagination::fromRequest($totalPurchases, 25);
$offset = $pagination->getOffset();
$limit = $pagination->getLimit();

// === جلب المشتريات ===
$purchases = [];
try {
    $allParams = $params;
    $purchases = fetchAll(
        "SELECT p.*, pr.name as product_name, pr.code, pr.unit, pr.has_expiry,
                po.id as order_id, po.grand_total as order_grand_total,
                po.financial_discount as order_discount, po.tax_amount as order_tax,
                po.tax_name as order_tax_name
         FROM purchases p
         JOIN products pr ON pr.id = p.product_id
         LEFT JOIN purchase_orders po ON po.id = p.order_id
         {$whereClause}
         ORDER BY p.purchase_date DESC, p.id DESC
         LIMIT {$limit} OFFSET {$offset}",
        $allParams
    );
} catch (Exception $e) { $purchases = []; }

// === جلب التخفيضات لكل عملية شراء ===
$purchase_remises_map = [];
if (!empty($purchases)) {
    $pids = array_column($purchases, 'id');
    $ph = implode(',', array_fill(0, count($pids), '?'));
    try {
        $remise_rows = fetchAll(
            "SELECT pr.*, p2.name as remise_product_name, p2.code as remise_product_code
             FROM purchase_remises pr
             LEFT JOIN products p2 ON pr.product_id = p2.id
             WHERE pr.purchase_id IN ($ph)
             ORDER BY pr.id", $pids
        );
        foreach ($remise_rows as $r) {
            $purchase_remises_map[$r['purchase_id']][] = $r;
        }
    } catch (Exception $e) {}
}

// === تجميع حسب الفاتورة (للعرض المجمّع) ===
$grouped = [];
foreach ($purchases as $p) {
    if (!empty($p['order_id'])) {
        $key = 'order_' . $p['order_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'order_id' => $p['order_id'],
                'order_grand_total' => $p['order_grand_total'],
                'order_discount' => $p['order_discount'],
                'order_tax' => $p['order_tax'],
                'order_tax_name' => $p['order_tax_name'],
                'items' => [],
            ];
        }
        $grouped[$key]['items'][] = $p;
    } else {
        $key = 'single_' . $p['id'];
        $grouped[$key] = [
            'order_id' => null,
            'order_grand_total' => null,
            'order_discount' => null,
            'order_tax' => null,
            'order_tax_name' => null,
            'items' => [$p],
        ];
    }
}

// === ملخص الفترة ===
$summary = [];
try {
    $sumParams = [];
    $sumWhere = [];
    if (!empty($date_from)) { $sumWhere[] = "p.purchase_date >= ?"; $sumParams[] = $date_from; }
    if (!empty($date_to)) { $sumWhere[] = "p.purchase_date <= ?"; $sumParams[] = $date_to; }
    $sumClause = !empty($sumWhere) ? 'WHERE ' . implode(' AND ', $sumWhere) : '';
    $summary = fetchOne(
        "SELECT COUNT(*) as total_count, COALESCE(SUM(p.total), 0) as total_amount,
                COALESCE(SUM(p.grand_total), 0) as total_grand,
                COALESCE(SUM(p.tax_amount), 0) as total_tax
         FROM purchases p {$sumClause}",
        $sumParams
    );
} catch (Exception $e) { $summary = ['total_count' => 0, 'total_amount' => 0, 'total_grand' => 0, 'total_tax' => 0]; }

require_once '../includes/header.php';
?>
<style><?php echo Pagination::getStyles(); ?></style>
<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            سجل المشتريات
        </h1>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="add-purchase.php" class="btn btn-primary">تسجيل شراء جديد</a>
            <a href="../reports/purchases-report.php" class="btn btn-outline">التقرير التفصيلي</a>
            <div style="display:flex; gap:4px;">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'grouped'])); ?>" class="btn <?php echo $view_mode === 'grouped' ? 'btn-primary' : 'btn-outline'; ?>" style="font-size:0.85rem; padding:6px 14px;">مجمّع</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'flat'])); ?>" class="btn <?php echo $view_mode === 'flat' ? 'btn-primary' : 'btn-outline'; ?>" style="font-size:0.85rem; padding:6px 14px;">مسطح</a>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <!-- ملخص سريع -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
        <div style="background: #f5f3ff; border: 1px solid #c4b5fd; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.75rem; color: #6d28d9;">عدد العمليات</div>
            <div style="font-weight: 800; font-size: 1.05rem; color: #4338ca;"><?php echo number_format(intval($summary['total_count'] ?? 0)); ?></div>
        </div>
        <div style="background: #fef2f2; border: 1px solid #fca5a5; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.75rem; color: #991b1b;">إجمالي المشتريات</div>
            <div style="font-weight: 800; font-size: 1.05rem; color: #dc2626;"><?php echo formatMoney($summary['total_amount'] ?? 0); ?></div>
        </div>
        <div style="background: #faf5ff; border: 1px solid #d8b4fe; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.75rem; color: #7c3aed;">إجمالي الضرائب</div>
            <div style="font-weight: 800; font-size: 1.05rem; color: #7c3aed;"><?php echo formatMoney($summary['total_tax'] ?? 0); ?></div>
        </div>
        <div style="background: #eff6ff; border: 1px solid #93c5fd; padding: 0.6rem 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.75rem; color: #1d4ed8;">المبلغ النهائي</div>
            <div style="font-weight: 900; font-size: 1.15rem; color: #1e40af;"><?php echo formatMoney($summary['total_grand'] ?? 0); ?></div>
        </div>
    </div>

    <!-- شريط البحث والتصفية -->
    <form method="GET" class="search-bar" id="searchForm">
        <div class="product-search-wrapper" id="purchaseSearchWrapper" style="flex:1; min-width:200px;">
            <div class="search-input-container">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="search" id="purchase_search_input" class="form-control search-input" placeholder="ابحث بالمنتج أو الكود..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off" onfocus="openSearchDropdown()" oninput="searchPurchases()">
                <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearPurchaseSearch()" style="<?php echo !empty($search) ? 'display:flex;' : 'display:none;'; ?>">
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
        <div style="display:flex; gap:6px; align-items:center;">
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control" style="max-width:150px;">
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control" style="max-width:150px;">
        </div>
        <button type="submit" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            بحث
        </button>
        <?php if (!empty($search) || !empty($date_from) || !empty($date_to)): ?>
            <a href="list-purchases.php" class="btn btn-outline">مسح الفلاتر</a>
        <?php endif; ?>
    </form>

    <?php if (empty($purchases)): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-muted" style="text-align:center; padding:2rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40" style="display:block; margin:0 auto 10px; color:var(--gray-300);"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <?php echo t('purchases_no_results'); ?>
                </p>
            </div>
        </div>
    <?php else: ?>

    <?php if ($view_mode === 'flat'): ?>
    <!-- === عرض مسطح === -->
    <div class="card">
        <div class="card-header">
            <h2>تفاصيل المشتريات (<?php echo $totalPurchases; ?> عملية)</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>الضريبة</th>
                            <th>التخفيضات</th>
                            <th>تاريخ الشراء</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($purchases as $p):
                            $p_remises = $purchase_remises_map[$p['id']] ?? [];
                            $has_material = false; $has_financial = false; $fin_total = 0; $mat_items = [];
                            foreach ($p_remises as $r) {
                                if ($r['type'] === 'material') { $has_material = true; $mat_items[] = $r; }
                                else { $has_financial = true; $fin_total += floatval($r['amount']); }
                            }
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><span style="font-family:monospace; background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:0.82rem;"><?php echo htmlspecialchars($p['code']); ?></span></td>
                            <td>
                                <?php echo htmlspecialchars($p['product_name']); ?>
                                <?php if (!empty($p['order_id'])): ?>
                                <a href="edit-purchase.php?id=<?php echo intval($p['id']); ?>" style="display:inline-flex; align-items:center; gap:3px; background:#eef2ff; color:#4338ca; padding:1px 8px; border-radius:8px; font-size:0.7rem; font-weight:600; text-decoration:none; margin-right:6px;" title="فاتورة #<?php echo intval($p['order_id']); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="10" height="10"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    فاتورة #<?php echo intval($p['order_id']); ?>
                                </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($p['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($p['unit']); ?></td>
                            <td><?php echo formatMoney($p['purchase_price']); ?></td>
                            <td style="font-weight:700;"><?php echo formatMoney($p['total']); ?></td>
                            <td style="font-size:0.8rem;">
                                <?php $p_tax = floatval($p['tax_amount'] ?? 0); if ($p_tax > 0): ?>
                                <div style="background:#eef2ff; border-radius:4px; padding:2px 6px;">
                                    <span style="font-size:0.7rem; color:#6366f1;"><?php echo htmlspecialchars($p['tax_name'] ?? 'ضريبة'); ?></span>
                                    <strong style="color:#4f46e5;">+<?php echo formatMoney($p_tax); ?></strong>
                                </div>
                                <?php else: ?><span style="color:var(--gray-300);">—</span><?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem;">
                                <?php if (empty($p_remises)): ?>
                                    <span style="color:var(--gray-300);">—</span>
                                <?php else: ?>
                                    <?php foreach ($mat_items as $mi): ?>
                                    <div style="background:#f0fdf4; border-radius:4px; padding:2px 6px; margin-bottom:2px;">
                                        <strong><?php echo number_format($mi['quantity'], 2); ?></strong> <?php echo htmlspecialchars($mi['remise_product_name'] ?? ''); ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if ($has_financial): ?>
                                    <div style="background:#fef3c7; border-radius:4px; padding:2px 6px;">
                                        <strong>-<?php echo formatMoney($fin_total); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars($p['purchase_date']); ?></td>
                            <td class="purchase-actions-cell">
                                <a href="edit-purchase.php?id=<?php echo $p['id']; ?>" class="purchase-action-btn--edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    تعديل
                                </a>
                                <a href="delete-purchase.php?id=<?php echo $p['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف عملية الشراء؟ سيتم تقليل المخزون.')" class="purchase-action-btn--delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    حذف
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- === عرض مجمّع حسب الفاتورة === -->
    <?php foreach ($grouped as $gkey => $group): ?>
    <?php $isOrder = !empty($group['order_id']); ?>
    <div class="card" style="margin-bottom:1rem; <?php echo $isOrder ? 'border: 2px solid #6366f1;' : ''; ?>">
        <?php if ($isOrder): ?>
        <div class="card-header" style="background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
            <h2 style="color:#4338ca; display:flex; align-items:center; gap:8px; margin:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                فاتورة شراء #<?php echo intval($group['order_id']); ?>
            </h2>
            <div style="display:flex; gap:12px; align-items:center;">
                <?php if (floatval($group['order_discount'] ?? 0) > 0): ?>
                <span style="font-size:0.8rem; color:#d97706; font-weight:700;">خصم: -<?php echo formatMoney($group['order_discount']); ?></span>
                <?php endif; ?>
                <?php if (floatval($group['order_tax'] ?? 0) > 0): ?>
                <span style="font-size:0.8rem; color:#7c3aed; font-weight:700;">ضريبة: +<?php echo formatMoney($group['order_tax']); ?></span>
                <?php endif; ?>
                <span style="font-size:0.85rem; color:#1e40af; font-weight:900; background:#dbeafe; padding:4px 12px; border-radius:8px;">المبلغ النهائي: <?php echo formatMoney($group['order_grand_total']); ?></span>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>الضريبة</th>
                            <th>التخفيضات</th>
                            <th>التاريخ</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['items'] as $idx => $p):
                            $p_remises = $purchase_remises_map[$p['id']] ?? [];
                            $has_material = false; $has_financial = false; $fin_total = 0; $mat_items = [];
                            foreach ($p_remises as $r) {
                                if ($r['type'] === 'material') { $has_material = true; $mat_items[] = $r; }
                                else { $has_financial = true; $fin_total += floatval($r['amount']); }
                            }
                        ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><span style="font-family:monospace; background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:0.82rem;"><?php echo htmlspecialchars($p['code']); ?></span></td>
                            <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                            <td><?php echo number_format($p['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($p['unit']); ?></td>
                            <td><?php echo formatMoney($p['purchase_price']); ?></td>
                            <td style="font-weight:700;"><?php echo formatMoney($p['total']); ?></td>
                            <td style="font-size:0.8rem;">
                                <?php $p_tax = floatval($p['tax_amount'] ?? 0); if ($p_tax > 0): ?>
                                <div style="background:#eef2ff; border-radius:4px; padding:2px 6px;">
                                    <strong style="color:#4f46e5;">+<?php echo formatMoney($p_tax); ?></strong>
                                </div>
                                <?php else: ?><span style="color:var(--gray-300);">—</span><?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem;">
                                <?php if (empty($p_remises)): ?>
                                    <span style="color:var(--gray-300);">—</span>
                                <?php else: ?>
                                    <?php foreach ($mat_items as $mi): ?>
                                    <div style="background:#f0fdf4; border-radius:4px; padding:2px 6px; margin-bottom:2px;">
                                        <strong><?php echo number_format($mi['quantity'], 2); ?></strong> <?php echo htmlspecialchars($mi['remise_product_name'] ?? ''); ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if ($has_financial): ?>
                                    <div style="background:#fef3c7; border-radius:4px; padding:2px 6px;">
                                        <strong>-<?php echo formatMoney($fin_total); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars($p['purchase_date']); ?></td>
                            <td class="purchase-actions-cell">
                                <a href="edit-purchase.php?id=<?php echo $p['id']; ?>" class="purchase-action-btn--edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    تعديل
                                </a>
                                <a href="delete-purchase.php?id=<?php echo $p['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف عملية الشراء؟ سيتم تقليل المخزون.')" class="purchase-action-btn--delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    حذف
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($isOrder && count($group['items']) > 1): ?>
                    <tfoot>
                        <tr style="background:#f5f3ff; font-weight:700;">
                            <td colspan="5">إجمالي الفاتورة</td>
                            <td colspan="2" style="text-align:center; color:#4338ca;"><?php echo formatMoney($group['order_grand_total']); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>

    <?php echo $pagination->renderInfo('عملية شراء'); ?>
    <?php echo $pagination->renderHtml(); ?>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
let searchTimeout = null;
let searchResultsData = [];
let highlightedIndex = -1;
let isDropdownOpen = false;

function openSearchDropdown() {
    const input = document.getElementById('purchase_search_input');
    document.getElementById('searchClearBtn').style.display = input.value.trim() ? 'flex' : 'none';
    document.getElementById('searchArrowBtn').style.display = input.value.trim() ? 'none' : 'flex';
    document.getElementById('searchDropdown').style.display = 'flex';
    isDropdownOpen = true;
    if (!input.value.trim()) searchPurchases();
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

function searchPurchases() {
    const input = document.getElementById('purchase_search_input');
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
                var _products = data.products || data.data || [];
                if (_products.length > 0) {
                    searchResultsData = _products;
                    _products.forEach((product, index) => {
                        const item = createProductItem(product, query);
                        item.dataset.index = index;
                        item.addEventListener('mouseenter', () => { clearHighlight(); item.classList.add('keyboard-nav'); highlightedIndex = index; });
                        item.addEventListener('mouseleave', () => item.classList.remove('keyboard-nav'));
                        resultsDiv.appendChild(item);
                    });
                    if (data.total) {
                        const footer = document.createElement('div');
                        footer.className = 'search-dropdown-footer';
                        footer.textContent = data.total + ' نتيجة';
                        resultsDiv.appendChild(footer);
                    }
                } else {
                    noResults.style.display = 'block';
                    noResults.textContent = 'لا توجد نتائج مطابقة';
                    searchResultsData = [];
                }
            })
            .catch(err => { loading.style.display = 'none'; noResults.style.display = 'block'; noResults.textContent = 'خطأ: ' + (err.message || 'فشل الاتصال'); });
    }, 300);
}

function clearHighlight() {
    document.querySelectorAll('.search-product-item').forEach(el => el.classList.remove('keyboard-nav'));
}

function createProductItem(product, query) {
    const item = document.createElement('div');
    item.className = 'search-product-item';
    item.onclick = () => selectPurchaseSearch(product);

    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock';
    let stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }

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
                <span>شراء: ${parseFloat(product.purchase_price || product.selling_price || 0).toFixed(2)}</span>
                <span>الوحدة: ${escapeHtml(product.unit || '')}</span>
            </div>
        </div>
        <span class="item-stock ${stockClass}">${stockText}</span>
    `;
    return item;
}

function selectPurchaseSearch(product) {
    document.getElementById('purchase_search_input').value = product.name;
    document.getElementById('searchClearBtn').style.display = 'flex';
    document.getElementById('searchArrowBtn').style.display = 'none';
    closeSearchDropdown();
    document.getElementById('searchForm').submit();
}

function clearPurchaseSearch() {
    const input = document.getElementById('purchase_search_input');
    input.value = '';
    document.getElementById('searchClearBtn').style.display = 'none';
    document.getElementById('searchArrowBtn').style.display = 'flex';
    input.focus();
    searchPurchases();
}

document.addEventListener('keydown', function(e) {
    if (!isDropdownOpen) return;
    const items = document.querySelectorAll('.search-product-item');
    if (items.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1); updateHighlight(items); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlightedIndex = Math.max(highlightedIndex - 1, 0); updateHighlight(items); }
    else if (e.key === 'Enter' && highlightedIndex >= 0) { e.preventDefault(); items[highlightedIndex].click(); }
    else if (e.key === 'Escape') { closeSearchDropdown(); document.getElementById('purchase_search_input').blur(); }
});

function updateHighlight(items) {
    items.forEach((item, i) => item.classList.toggle('keyboard-nav', i === highlightedIndex));
    if (items[highlightedIndex]) items[highlightedIndex].scrollIntoView({ block: 'nearest' });
}

document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('purchaseSearchWrapper');
    if (wrapper && !wrapper.contains(e.target)) closeSearchDropdown();
});

function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function escapeRegex(string) { return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

// عند مسح باركود - يملأ حقل البحث ويُرسل النموذج
window.onBarcodeScanned = function(code) {
    if (!code) return;
    document.getElementById('purchase_search_input').value = code;
    document.getElementById('searchClearBtn').style.display = 'flex';
    document.getElementById('searchArrowBtn').style.display = 'none';
    closeSearchDropdown();
    document.getElementById('searchForm').submit();
};
</script>

<?php require_once '../includes/barcode-scanner.php'; ?>
<?php require_once '../includes/footer.php'; ?>
