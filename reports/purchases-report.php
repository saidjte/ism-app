<?php
/**
 * تقرير المشتريات التفصيلي
 * حسب منتج، فترة زمنية، تصنيف
 */
require_once '../includes/bootstrap.php';
$page_title = t('reports_detailed_purchases');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('reports.view');

// === الفلاتر ===
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_category = intval($_GET['category'] ?? 0);
$filter_search = trim($_GET['search'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

// === جلب التصنيفات ===
$categories = [];
try {
    $categories = fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC");
} catch (Exception $e) {}

// === ملخص الفواتير (purchase_orders) ===
$order_summary = [];
try {
    $order_summary = fetchOne(
        "SELECT\n            COUNT(DISTINCT po.id) as total_orders,\n            COALESCE(SUM(po.total), 0) as orders_gross_total,\n            COALESCE(SUM(po.financial_discount), 0) as orders_total_discount,\n            COALESCE(SUM(po.tax_amount), 0) as orders_total_tax,\n            COALESCE(SUM(po.grand_total), 0) as orders_grand_total\n         FROM purchase_orders po\n         WHERE po.purchase_date BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
} catch (Exception $e) { $order_summary = ['total_orders' => 0, 'orders_gross_total' => 0, 'orders_total_discount' => 0, 'orders_total_tax' => 0, 'orders_grand_total' => 0]; }

// === بناء استعلامات المشتريات ===
$where = ["p.purchase_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_category > 0) {
    $where[] = "pr.category_id = ?";
    $params[] = $filter_category;
}
if (!empty($filter_search)) {
    $searchWhere = SearchService::buildSearchWhere($filter_search, 'pr', false);
    $where[] = $searchWhere['where'];
    $params = array_merge($params, $searchWhere['params']);
}

$whereClause = implode(' AND ', $where);

// === ملخص المشتريات ===
$summary = fetchOne(
    "SELECT
        COUNT(p.id) as total_transactions,
        COUNT(DISTINCT p.product_id) as products_purchased,
        COALESCE(SUM(p.quantity), 0) as total_qty,
        COALESCE(SUM(p.total), 0) as total_purchases,
        COALESCE(SUM(p.tax_amount), 0) as total_tax,
        COALESCE(SUM(p.grand_total), 0) as total_grand,
        COALESCE(SUM(pr_fin_s.financial_discount), 0) as total_discount,
        AVG(p.purchase_price) as avg_price
     FROM purchases p
     JOIN products pr ON pr.id = p.product_id
     LEFT JOIN (SELECT purchase_id, COALESCE(SUM(amount), 0) as financial_discount FROM purchase_remises WHERE type = 'financial' GROUP BY purchase_id) pr_fin_s ON pr_fin_s.purchase_id = p.id
     LEFT JOIN purchase_orders po ON po.id = p.order_id
     WHERE $whereClause",
    $params
);

// === المشتريات حسب المنتج ===
$products_purchases = fetchAll(
    "SELECT
        pr.id, pr.code, pr.name, pr.unit, pr.category_id,
        COALESCE(c.name, '—') as category_name,
        COALESCE(c.color, '#64748b') as category_color,
        SUM(p.quantity) as qty_purchased,
        SUM(p.total) as purchase_total,
        COALESCE(SUM(p.tax_amount), 0) as total_tax,
        COALESCE(SUM(p.grand_total), 0) as total_grand,
        COALESCE(SUM(pr_fin.financial_discount), 0) as total_discount,
        AVG(p.purchase_price) as avg_price,
        MIN(p.purchase_price) as min_price,
        MAX(p.purchase_price) as max_price,
        COUNT(p.id) as transactions
     FROM purchases p
     JOIN products pr ON pr.id = p.product_id
     LEFT JOIN categories c ON c.id = pr.category_id
     LEFT JOIN (SELECT purchase_id, COALESCE(SUM(amount), 0) as financial_discount FROM purchase_remises WHERE type = 'financial' GROUP BY purchase_id) pr_fin ON pr_fin.purchase_id = p.id
     WHERE $whereClause
     GROUP BY p.product_id
     ORDER BY purchase_total DESC",
    $params
);

// === المشتريات حسب التصنيف ===
$category_purchases = fetchAll(
    "SELECT
        COALESCE(c.name, 'بدون تصنيف') as category_name,
        COALESCE(c.color, '#64748b') as category_color,
        COALESCE(c.id, 0) as category_id,
        COUNT(DISTINCT p.product_id) as products_count,
        SUM(p.quantity) as qty_purchased,
        SUM(p.total) as purchase_total,
        COALESCE(SUM(p.tax_amount), 0) as total_tax,
        COALESCE(SUM(p.grand_total), 0) as total_grand,
        COALESCE(SUM(pr_fin.financial_discount), 0) as total_discount,
        AVG(p.purchase_price) as avg_price
     FROM purchases p
     JOIN products pr ON pr.id = p.product_id
     LEFT JOIN categories c ON c.id = pr.category_id
     LEFT JOIN (SELECT purchase_id, COALESCE(SUM(amount), 0) as financial_discount FROM purchase_remises WHERE type = 'financial' GROUP BY purchase_id) pr_fin ON pr_fin.purchase_id = p.id
     WHERE $whereClause
     GROUP BY COALESCE(c.id, 0)
     ORDER BY purchase_total DESC",
    $params
);

// === المشتريات اليومية (للرسم البياني) ===
$daily_purchases = fetchAll(
    "SELECT
        DATE(p.purchase_date) as purchase_date,
        SUM(p.total) as daily_total,
        COALESCE(SUM(p.grand_total), 0) as daily_grand_total,
        SUM(p.quantity) as daily_qty,
        COUNT(p.id) as daily_transactions
     FROM purchases p
     JOIN products pr ON pr.id = p.product_id
     WHERE $whereClause
     GROUP BY DATE(p.purchase_date)
     ORDER BY purchase_date ASC",
    $params
);

// === المشتريات الشهرية ===
$monthly_purchases = fetchAll(
    "SELECT
        DATE_FORMAT(p.purchase_date, '%Y-%m') as month_key,
        SUM(p.total) as month_total,
        COALESCE(SUM(p.grand_total), 0) as month_grand_total,
        SUM(p.quantity) as month_qty,
        COUNT(p.id) as month_transactions
     FROM purchases p
     JOIN products pr ON pr.id = p.product_id
     WHERE $whereClause
     GROUP BY month_key
     ORDER BY month_key ASC",
    $params
);

// === أفضل 10 منتجات ===
$top_products = array_slice($products_purchases, 0, 10);

// === تحليل أسعار الشراء (تغير الأسعار) ===
$price_changes = [];
try {
    $price_changes = fetchAll(
        "SELECT pr.code, pr.name, pr.unit,
               ph.old_purchase_price, ph.new_purchase_price,
               ph.change_reason, ph.changed_at
         FROM price_history ph
         JOIN products pr ON pr.id = ph.product_id
         WHERE DATE(ph.changed_at) BETWEEN ? AND ?
         ORDER BY ph.changed_at DESC
         LIMIT 50",
        [$date_from, $date_to]
    );
} catch (Exception $e) {}

// === فواتير الشراء التفصيلية (purchase_orders) ===
$purchase_orders_list = [];
try {
    $purchase_orders_list = fetchAll(
        "SELECT po.id, po.supplier_id, s.name as supplier_name, po.total, po.financial_discount,
                po.net_total, po.tax_rate, po.tax_amount, po.tax_name, po.grand_total,
                po.notes, po.purchase_date,
                (SELECT COUNT(*) FROM purchases p WHERE p.order_id = po.id) as products_count
         FROM purchase_orders po
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.purchase_date BETWEEN ? AND ?
         ORDER BY po.purchase_date DESC, po.id DESC",
        [$date_from, $date_to]
    );
} catch (Exception $e) { $purchase_orders_list = []; }

$load_chartjs = true;
require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
            </svg>
            <?php echo t('reports_detailed_purchases'); ?>
        </h1>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="../api/export.php?type=purchases&format=csv&start_date=<?php echo urlencode($date_from); ?>&end_date=<?php echo urlencode($date_to); ?><?php echo $filter_category ? '&category='.$filter_category : ''; ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
                📥 <?php echo t('reports_export_csv'); ?>
            </a>
            <a href="../api/export.php?type=purchases&format=pdf&start_date=<?php echo urlencode($date_from); ?>&end_date=<?php echo urlencode($date_to); ?><?php echo $filter_category ? '&category='.$filter_category : ''; ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;" target="_blank">
                📄 <?php echo t('reports_export_pdf'); ?>
            </a>
        </div>
    </div>

    <!-- الفلاتر -->
    <div class="report-filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label>من تاريخ</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>إلى تاريخ</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="form-control">
                </div>
                <?php if (!empty($categories)): ?>
                <div class="filter-group">
                    <label>التصنيف</label>
                    <select name="category" class="form-control">
                        <option value="">كل التصنيفات</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group" style="flex:1;">
                    <label>بحث عن منتج</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" class="form-control" placeholder="كود أو اسم المنتج...">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        عرض
                    </button>
                    <a href="purchases-report.php" class="btn btn-outline">مسح</a>
                </div>
            </div>
            <div style="margin-top:10px;">
                <div class="quick-periods">
                    <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn <?php echo ($date_from == date('Y-m-01') && $date_to == date('Y-m-d')) ? 'active' : ''; ?>">هذا الشهر</a>
                    <a href="?date_from=<?php echo date('Y-01-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn <?php echo ($date_from == date('Y-01-01') && $date_to == date('Y-m-d')) ? 'active' : ''; ?>">هذا العام</a>
                    <a href="?date_from=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('-1 month')); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">الشهر الماضي</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 30 يوم</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-90 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 90 يوم</a>
                </div>
            </div>
        </form>
    </div>

    <!-- ملخص المشتريات -->
    <div class="report-summary">
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef2f2; color:#dc2626;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('dashboard_total_purchases'); ?></h4>
                <div class="summary-value" style="color:#dc2626;"><?php echo formatMoney($summary['total_purchases']); ?></div>
                <div class="summary-sub"><?php echo number_format($summary['total_transactions']); ?> عملية شراء</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#f0fdf4; color:#16a34a;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div class="summary-info">
                <h4>الكمية المشتراة</h4>
                <div class="summary-value" style="color:#16a34a;"><?php echo number_format($summary['total_qty'], 0); ?></div>
                <div class="summary-sub"><?php echo number_format($summary['products_purchased']); ?> منتج مختلف</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#eff6ff; color:#2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="summary-info">
                <h4>متوسط سعر الشراء</h4>
                <div class="summary-value" style="color:#2563eb;"><?php echo formatMoney($summary['avg_price']); ?></div>
                <div class="summary-sub">لكل وحدة</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef3c7; color:#d97706;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="summary-info">
                <h4>متوسط العملية</h4>
                <div class="summary-value" style="color:#d97706;"><?php echo formatMoney($summary['total_transactions'] > 0 ? $summary['total_purchases'] / $summary['total_transactions'] : 0); ?></div>
                <div class="summary-sub">لكل عملية شراء</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#ede9fe; color:#7c3aed;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
            </div>
            <div class="summary-info">
                <h4>الضريبة</h4>
                <div class="summary-value" style="color:#7c3aed;"><?php echo formatMoney($summary['total_tax']); ?></div>
                <div class="summary-sub">إجمالي الضرائب</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef2f2; color:#991b1b;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div class="summary-info">
                <h4>الإجمالي مع الضريبة</h4>
                <div class="summary-value" style="color:#991b1b;"><?php echo formatMoney($summary['total_grand']); ?></div>
                <div class="summary-sub">التكلفة الحقيقية</div>
            </div>
        </div>
    </div>

    <!-- ملخص فواتير الشراء -->
    <div class="report-summary" style="margin-bottom: 1.5rem; border: 2px solid #6366f1; border-radius: 12px; padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e0e7ff;">
            <svg viewBox="0 0 24 24" fill="none" stroke="#4338ca" stroke-width="2" width="20" height="20"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span style="font-weight: 700; color: #4338ca; font-size: 0.95rem;">ملخص فواتير الشراء</span>
            <span style="background: #e0e7ff; color: #4338ca; padding: 1px 10px; border-radius: 10px; font-size: 0.78rem; font-weight: 600;"><?php echo number_format($order_summary['total_orders'] ?? 0); ?> فاتورة</span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem;">
            <div style="background: #f5f3ff; border-radius: 8px; padding: 0.5rem 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #7c3aed;">إجمالي الفواتير</div>
                <div style="font-weight: 800; font-size: 1rem; color: #4338ca;"><?php echo formatMoney($order_summary['orders_gross_total'] ?? 0); ?></div>
            </div>
            <?php if (floatval($order_summary['orders_total_discount'] ?? 0) > 0): ?>
            <div style="background: #fffbeb; border-radius: 8px; padding: 0.5rem 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #b45309;">إجمالي التخفيضات</div>
                <div style="font-weight: 800; font-size: 1rem; color: #d97706;">-<?php echo formatMoney($order_summary['orders_total_discount']); ?></div>
            </div>
            <?php endif; ?>
            <div style="background: #faf5ff; border-radius: 8px; padding: 0.5rem 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #7c3aed;">الضرائب</div>
                <div style="font-weight: 800; font-size: 1rem; color: #7c3aed;"><?php echo formatMoney($order_summary['orders_total_tax'] ?? 0); ?></div>
            </div>
            <div style="background: #eff6ff; border-radius: 8px; padding: 0.5rem 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #1d4ed8;">صافي الفواتير</div>
                <div style="font-weight: 900; font-size: 1.1rem; color: #1e40af;"><?php echo formatMoney($order_summary['orders_grand_total'] ?? 0); ?></div>
            </div>
        </div>
    </div>

    <!-- جدول فواتير الشراء التفصيلي -->
    <?php if (!empty($purchase_orders_list)): ?>
    <div class="card" style="margin-bottom: 1.5rem; border: 2px solid #6366f1;">
        <div class="card-header" style="background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);">
            <h2 style="color: #4338ca; display: flex; align-items: center; gap: 8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                فواتير الشراء (<?php echo count($purchase_orders_list); ?> فاتورة)
            </h2>
        </div>
        <div class="card-body">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>رقم الفاتورة</th>
                            <th>المورّد</th>
                            <th>التاريخ</th>
                            <th>المنتجات</th>
                            <th>الإجمالي</th>
                            <th>الخصم</th>
                            <th>الضريبة</th>
                            <th>المبلغ النهائي</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchase_orders_list as $oi => $ord): ?>
                        <tr>
                            <td><?php echo $oi + 1; ?></td>
                            <td><span style="font-family: monospace; background: #eef2ff; color: #4338ca; padding: 2px 10px; border-radius: 8px; font-weight: 700; font-size: 0.85rem;">#<?php echo intval($ord['id']); ?></span></td>
                            <td><?php echo htmlspecialchars($ord['supplier_name'] ?? '—'); ?></td>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars($ord['purchase_date']); ?></td>
                            <td style="text-align:center;"><span style="background: #f0fdf4; color: #059669; padding: 2px 10px; border-radius: 8px; font-weight: 600; font-size: 0.82rem;"><?php echo intval($ord['products_count']); ?></span></td>
                            <td style="font-weight:700;"><?php echo formatMoney($ord['total']); ?></td>
                            <td style="color:#d97706;"><?php echo floatval($ord['financial_discount'] ?? 0) > 0 ? '-' . formatMoney($ord['financial_discount']) : '—'; ?></td>
                            <td style="color:#7c3aed;"><?php echo floatval($ord['tax_amount'] ?? 0) > 0 ? formatMoney($ord['tax_amount']) : '—'; ?></td>
                            <td style="font-weight:900; color:#1e40af;"><?php echo formatMoney($ord['grand_total']); ?></td>
                            <td style="font-size:0.78rem; color:var(--gray-500); max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($ord['notes'] ?? ''); ?>"><?php echo htmlspecialchars($ord['notes'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--gray-50); font-weight: 800;">
                            <td colspan="4">المجموع</td>
                            <td style="text-align:center;"><?php echo count($purchase_orders_list); ?> فاتورة</td>
                            <td><?php echo formatMoney($order_summary['orders_gross_total'] ?? 0); ?></td>
                            <td style="color:#d97706;"><?php echo floatval($order_summary['orders_total_discount'] ?? 0) > 0 ? '-' . formatMoney($order_summary['orders_total_discount']) : '—'; ?></td>
                            <td style="color:#7c3aed;"><?php echo formatMoney($order_summary['orders_total_tax'] ?? 0); ?></td>
                            <td style="color:#1e40af; font-weight:900;"><?php echo formatMoney($order_summary['orders_grand_total'] ?? 0); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- الرسوم البيانية -->
    <div class="charts-grid">
        <!-- منحنى المشتريات اليومية -->
        <div class="chart-card" style="grid-column: span 2;">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    منحنى المشتريات اليومية
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 280px;">
                    <canvas id="purchasesTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- المشتريات حسب التصنيف (دائري) -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0110 10h-10z"/></svg>
                    حسب التصنيف
                </h3>
            </div>
            <div class="chart-card-body pie-body">
                <div style="width:100%; max-width:320px;">
                    <canvas id="categoryPieChart"></canvas>
                </div>
            </div>
        </div>

        <!-- الكميات الشهرية -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 20V10M6 20V4M18 20v-6"/></svg>
                    الكميات (شهري)
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 280px;">
                    <canvas id="qtyMonthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- أفضل المنتجات + التصنيفات -->
    <?php if (!empty($top_products)): ?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 15l-3-3m0 0l3-3m-3 3h12M3 19V5"/></svg>
                    أعلى 10 منتجات (بالإنفاق)
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 300px;">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M4 7h16M4 12h16M4 17h10"/><circle cx="18" cy="17" r="2"/></svg>
                    تفصيل التصنيفات
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="category-breakdown">
                    <?php foreach ($category_purchases as $cs):
                        $cs_pct = $summary['total_purchases'] > 0 ? round(($cs['purchase_total'] / $summary['total_purchases']) * 100, 1) : 0;
                    ?>
                    <div class="category-item">
                        <div class="category-color-dot" style="background:<?php echo $cs['category_color']; ?>"></div>
                        <div class="category-item-info">
                            <div class="category-item-name"><?php echo htmlspecialchars($cs['category_name']); ?></div>
                            <div class="category-item-stats"><?php echo number_format($cs['products_count']); ?> منتج &bull; <?php echo number_format($cs['qty_purchased'], 0); ?> وحدة</div>
                        </div>
                        <div class="category-item-value">
                            <div><?php echo formatMoney($cs['purchase_total']); ?></div>
                            <div style="font-size:0.68rem; color:var(--gray-400);"><?php echo $cs_pct; ?>%</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- جدول المشتريات التفصيلي -->
    <div class="card">
        <div class="card-header">
            <h2>تفاصيل المشتريات حسب المنتج (<?php echo number_format(count($products_purchases)); ?> منتج)</h2>
            <span class="badge badge-danger"><?php echo formatMoney($summary['total_grand']); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($products_purchases)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;"><?php echo t('reports_no_data'); ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('code'); ?></th>
                            <th><?php echo t('product'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th>الكمية المشتراة</th>
                            <th>إجمالي المشتريات</th>
                            <th>الضريبة</th>
                            <th>الخصومات</th>
                            <th>الإجمالي النهائي</th>
                            <th>متوسط السعر</th>
                            <th>أقل سعر</th>
                            <th>أعلى سعر</th>
                            <th>العمليات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products_purchases as $i => $pp): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($pp['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($pp['name']); ?></td>
                            <td>
                                <?php if ($pp['category_id'] > 0): ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:12px; font-size:0.78rem; font-weight:600; color:#fff; background:<?php echo $pp['category_color']; ?>">
                                    <?php echo htmlspecialchars($pp['category_name']); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?php echo number_format($pp['qty_purchased'], 2); ?> <?php echo htmlspecialchars($pp['unit']); ?></td>
                            <td style="font-weight:700; color:#dc2626;"><?php echo formatMoney($pp['purchase_total']); ?></td>
                            <td style="font-weight:600; color:#7c3aed;"><?php echo formatMoney($pp['total_tax']); ?></td>
                            <td style="font-weight:600; color:#d97706;"><?php echo formatMoney($pp['total_discount']); ?></td>
                            <td style="font-weight:800; color:#991b1b;"><?php echo formatMoney($pp['total_grand']); ?></td>
                            <td><?php echo formatMoney($pp['avg_price']); ?></td>
                            <td style="color:#059669;"><?php echo formatMoney($pp['min_price']); ?></td>
                            <td style="color:#d97706;"><?php echo formatMoney($pp['max_price']); ?></td>
                            <td style="font-size:0.8rem; color:var(--gray-500);"><?php echo number_format($pp['transactions']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:800;">
                            <td colspan="4">المجموع</td>
                            <td><?php echo number_format($summary['total_qty'], 0); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($summary['total_purchases']); ?></td>
                            <td style="color:#7c3aed;"><?php echo formatMoney($summary['total_tax']); ?></td>
                            <td style="color:#d97706;"><?php echo formatMoney($summary['total_discount']); ?></td>
                            <td style="color:#991b1b; font-weight:900;"><?php echo formatMoney($summary['total_grand']); ?></td>
                            <td colspan="3"><?php echo formatMoney($summary['avg_price']); ?></td>
                            <td><?php echo number_format($summary['total_transactions']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- تغيرات الأسعار -->
    <?php if (!empty($price_changes)): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header">
            <h2>تغيرات أسعار الشراء في الفترة (<?php echo count($price_changes); ?> تغيير)</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive" style="max-height:400px; overflow-y:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>المنتج</th>
                            <th>السعر القديم</th>
                            <th>السعر الجديد</th>
                            <th>التغيير</th>
                            <th>السبب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($price_changes as $pc):
                            $price_diff = $pc['new_purchase_price'] - $pc['old_purchase_price'];
                            $price_diff_pct = $pc['old_purchase_price'] > 0 ? round(($price_diff / $pc['old_purchase_price']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($pc['changed_at'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($pc['code']); ?></strong> — <?php echo htmlspecialchars($pc['name']); ?></td>
                            <td><?php echo formatMoney($pc['old_purchase_price']); ?></td>
                            <td style="font-weight:700;"><?php echo formatMoney($pc['new_purchase_price']); ?></td>
                            <td>
                                <?php if ($price_diff > 0): ?>
                                <span class="change-badge up">+<?php echo formatMoney($price_diff); ?> (+<?php echo $price_diff_pct; ?>%)</span>
                                <?php elseif ($price_diff < 0): ?>
                                <span class="change-badge down"><?php echo formatMoney($price_diff); ?> (<?php echo $price_diff_pct; ?>%)</span>
                                <?php else: ?>
                                <span class="change-badge neutral">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.82rem; color:var(--gray-500);"><?php echo htmlspecialchars($pc['change_reason'] ?: '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
(function() {
    Chart.defaults.font.family = 'Tajawal, Segoe UI, sans-serif';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;
    const currency = window.CURRENCY || '';

    // === 1. منحنى المشتريات اليومية ===
    <?php if (!empty($daily_purchases)): ?>
    new Chart(document.getElementById('purchasesTrendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return date('d/m', strtotime($d['purchase_date'])); }, $daily_purchases)); ?>,
            datasets: [{
                label: 'المشتريات',
                data: <?php echo json_encode(array_map(function($d) { return floatval($d['daily_total']); }, $daily_purchases)); ?>,
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220,38,38,0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                borderWidth: 2.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') + ' ' + currency } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('ar-MA') + ' ' + currency } }
            }
        }
    });
    <?php endif; ?>

    // === 2. التصنيفات (دائري) ===
    <?php if (!empty($category_purchases)): ?>
    new Chart(document.getElementById('categoryPieChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(function($c) { return $c['category_name']; }, $category_purchases)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map(function($c) { return floatval($c['purchase_total']); }, $category_purchases)); ?>,
                backgroundColor: <?php echo json_encode(array_map(function($c) { return $c['category_color']; }, $category_purchases)); ?>,
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'bottom', rtl: true },
                tooltip: { callbacks: { label: ctx => { const t = ctx.dataset.data.reduce((a,b)=>a+b,0); return ctx.label + ': ' + parseFloat(ctx.parsed).toLocaleString('ar-MA') + ' ' + currency + ' (' + ((ctx.parsed/t)*100).toFixed(1) + '%)'; } } }
            }
        }
    });
    <?php endif; ?>

    // === 3. الكميات الشهرية ===
    <?php if (!empty($monthly_purchases)): ?>
    new Chart(document.getElementById('qtyMonthlyChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($m) { return $m['month_key']; }, $monthly_purchases)); ?>,
            datasets: [{
                label: 'الكمية',
                data: <?php echo json_encode(array_map(function($m) { return floatval($m['month_qty']); }, $monthly_purchases)); ?>,
                backgroundColor: 'rgba(220,38,38,0.7)',
                borderRadius: 4
            }, {
                label: 'المبلغ',
                data: <?php echo json_encode(array_map(function($m) { return floatval($m['month_total']); }, $monthly_purchases)); ?>,
                backgroundColor: 'rgba(37,99,235,0.7)',
                borderRadius: 4,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') } }
            },
            scales: {
                y: { beginAtZero: true, position: 'right', title: { display: true, text: 'الكمية' } },
                y1: { beginAtZero: true, position: 'left', grid: { drawOnChartArea: false }, ticks: { callback: v => v.toLocaleString('ar-MA') + ' ' + currency }, title: { display: true, text: 'المبلغ' } }
            }
        }
    });
    <?php endif; ?>

    // === 4. أفضل 10 منتجات ===
    <?php if (!empty($top_products)): ?>
    new Chart(document.getElementById('topProductsChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($p) { return mb_substr($p['name'], 0, 25) . (mb_strlen($p['name']) > 25 ? '...' : ''); }, $top_products)); ?>,
            datasets: [{
                label: 'الإنفاق',
                data: <?php echo json_encode(array_map(function($p) { return floatval($p['purchase_total']); }, $top_products)); ?>,
                backgroundColor: <?php echo json_encode(array_map(function($p) { return $p['category_color']; }, $top_products)); ?>,
                borderRadius: 6,
                barThickness: 24
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => parseFloat(ctx.parsed.x).toLocaleString('ar-MA') + ' ' + currency } } },
            scales: { x: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('ar-MA') } } }
        }
    });
    <?php endif; ?>
})();
</script>

<?php require_once '../includes/footer.php'; ?>
