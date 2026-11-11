<?php
/**
 * تقرير المبيعات التفصيلي
 * حسب منتج، فترة زمنية، تصنيف
 */
require_once '../includes/bootstrap.php';
$page_title = t('reports_detailed_sales');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('reports.view');

// === الفلاتر ===
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_category = intval($_GET['category'] ?? 0);
$filter_search = trim($_GET['search'] ?? '');
$chart_type = $_GET['chart'] ?? 'bar';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

// === جلب التصنيفات ===
$categories = [];
try {
    $categories = fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC");
} catch (Exception $e) {}

// === بناء استعلامات المبيعات ===
$where = ["s.sale_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_category > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $filter_category;
}
if (!empty($filter_search)) {
    $searchWhere = SearchService::buildSearchWhere($filter_search, 'p', false);
    $where[] = $searchWhere['where'];
    $params = array_merge($params, $searchWhere['params']);
}

$whereClause = implode(' AND ', $where);

// === بناء JOIN شرطي (يُستخدم فقط مع فلاتر التصنيف والبحث) ===
$joinProducts = '';
if ($filter_category > 0 || !empty($filter_search)) {
    $joinProducts = ' JOIN products p ON p.id = s.product_id';
}

// === ملخص المبيعات ===
$summary = fetchOne(
    "SELECT
        COUNT(DISTINCT s.id) as total_transactions,
        COUNT(DISTINCT s.product_id) as products_sold,
        COALESCE(SUM(s.quantity), 0) as total_qty,
        COALESCE(SUM(s.total), 0) as total_sales,
        COALESCE(SUM(s.cost_total), 0) as total_cost,
        COALESCE(SUM(s.profit), 0) as total_profit
     FROM sales s
     $joinProducts
     WHERE $whereClause",
    $params
);

$profit_margin = $summary['total_sales'] > 0
    ? round(($summary['total_profit'] / $summary['total_sales']) * 100, 1)
    : 0;

// === المبيعات حسب المنتج (ترتيب بالإيرادات) ===
$products_sales = fetchAll(
    "SELECT
        p.id, p.code, p.name, p.unit, p.category_id,
        COALESCE(c.name, '—') as category_name,
        COALESCE(c.color, '#64748b') as category_color,
        SUM(s.quantity) as qty_sold,
        SUM(s.total) as sales_total,
        SUM(s.cost_total) as cost_total,
        SUM(s.profit) as profit,
        COUNT(s.id) as transactions
     FROM sales s
     JOIN products p ON p.id = s.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereClause
     GROUP BY s.product_id
     ORDER BY sales_total DESC",
    $params
);

// === المبيعات حسب التصنيف ===
$category_sales = fetchAll(
    "SELECT
        COALESCE(c.name, 'بدون تصنيف') as category_name,
        COALESCE(c.color, '#64748b') as category_color,
        COALESCE(c.id, 0) as category_id,
        COUNT(DISTINCT s.product_id) as products_count,
        SUM(s.quantity) as qty_sold,
        SUM(s.total) as sales_total,
        SUM(s.cost_total) as cost_total,
        SUM(s.profit) as profit
     FROM sales s
     JOIN products p ON p.id = s.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereClause
     GROUP BY COALESCE(c.id, 0)
     ORDER BY sales_total DESC",
    $params
);

// === المبيعات اليومية (للرسم البياني) ===
$daily_sales = fetchAll(
    "SELECT
        DATE(s.sale_date) as sale_date,
        SUM(s.total) as daily_total,
        SUM(s.cost_total) as daily_cost,
        SUM(s.profit) as daily_profit,
        COUNT(s.id) as daily_transactions
     FROM sales s
     $joinProducts
     WHERE $whereClause
     GROUP BY DATE(s.sale_date)
     ORDER BY sale_date ASC",
    $params
);

// === المبيعات حسب الشهر (للرسم البياني) ===
$monthly_sales = fetchAll(
    "SELECT
        DATE_FORMAT(s.sale_date, '%Y-%m') as month_key,
        SUM(s.total) as month_total,
        SUM(s.cost_total) as month_cost,
        SUM(s.profit) as month_profit,
        COUNT(s.id) as month_transactions
     FROM sales s
     $joinProducts
     WHERE $whereClause
     GROUP BY month_key
     ORDER BY month_key ASC",
    $params
);

// === أفضل 10 منتجات ===
$top_products = array_slice($products_sales, 0, 10);

$load_chartjs = true;
require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                <path d="M12 13v4m-2-2h4"/>
            </svg>
            <?php echo t('reports_detailed_sales'); ?>
        </h1>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="../api/export.php?type=sales&format=csv&start_date=<?php echo urlencode($date_from); ?>&end_date=<?php echo urlencode($date_to); ?><?php echo $filter_category ? '&category='.$filter_category : ''; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
                📥 <?php echo t('reports_export_csv'); ?>
            </a>
            <a href="../api/export.php?type=sales&format=pdf&start_date=<?php echo urlencode($date_from); ?>&end_date=<?php echo urlencode($date_to); ?><?php echo $filter_category ? '&category='.$filter_category : ''; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;" target="_blank">
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
                    <a href="sales-report.php" class="btn btn-outline">مسح</a>
                </div>
            </div>
            <div style="margin-top:10px;">
                <div class="quick-periods">
                    <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn <?php echo ($date_from == date('Y-m-01') && $date_to == date('Y-m-d')) ? 'active' : ''; ?>">هذا الشهر</a>
                    <a href="?date_from=<?php echo date('Y-01-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn <?php echo ($date_from == date('Y-01-01') && $date_to == date('Y-m-d')) ? 'active' : ''; ?>">هذا العام</a>
                    <a href="?date_from=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('-1 month')); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">الشهر الماضي</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 7 أيام</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 30 يوم</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-90 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 90 يوم</a>
                </div>
            </div>
        </form>
    </div>

    <!-- ملخص المبيعات -->
    <div class="report-summary">
        <div class="summary-card">
            <div class="summary-icon" style="background:#eff6ff; color:#2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('dashboard_total_sales'); ?></h4>
                <div class="summary-value" style="color:#2563eb;"><?php echo formatMoney($summary['total_sales']); ?></div>
                <div class="summary-sub"><?php echo number_format($summary['total_transactions']); ?> عملية بيع</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef2f2; color:#dc2626;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('reports_sales_cost'); ?></h4>
                <div class="summary-value" style="color:#dc2626;"><?php echo formatMoney($summary['total_cost']); ?></div>
                <div class="summary-sub"><?php echo number_format($summary['total_qty'], 2); ?> وحدة مباعة</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#d1fae5; color:#059669;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('reports_gross_profit'); ?></h4>
                <div class="summary-value" style="color:#059669;"><?php echo formatMoney($summary['total_profit']); ?></div>
                <div class="summary-sub">هامش: <?php echo $profit_margin; ?>%</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef3c7; color:#d97706;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('reports_products_sold'); ?></h4>
                <div class="summary-value" style="color:#d97706;"><?php echo number_format($summary['products_sold']); ?></div>
                <div class="summary-sub">متوسط العملية: <?php echo formatMoney($summary['total_transactions'] > 0 ? $summary['total_sales'] / $summary['total_transactions'] : 0); ?></div>
            </div>
        </div>
    </div>

    <!-- الرسوم البيانية -->
    <div class="charts-grid">
        <!-- منحنى المبيعات اليومية -->
        <div class="chart-card" style="grid-column: span 2;">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    منحنى المبيعات
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 280px;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- المبيعات حسب التصنيف (دائري) -->
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

        <!-- أرباح vs تكاليف (شريطي) -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    أرباح vs تكاليف (شهري)
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 280px;">
                    <canvas id="profitVsCostChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- أفضل المنتجات مبيعاً -->
    <?php if (!empty($top_products)): ?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 15l-3-3m0 0l3-3m-3 3h12M3 19V5"/></svg>
                    أفضل 10 منتجات (بالإيرادات)
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
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 20V10M6 20V4M18 20v-6"/></svg>
                    تصنيف المبيعات (تفصيلي)
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="category-breakdown">
                    <?php foreach ($category_sales as $i => $cs):
                        $cs_pct = $summary['total_sales'] > 0 ? round(($cs['sales_total'] / $summary['total_sales']) * 100, 1) : 0;
                    ?>
                    <div class="category-item">
                        <div class="category-color-dot" style="background:<?php echo $cs['category_color']; ?>"></div>
                        <div class="category-item-info">
                            <div class="category-item-name"><?php echo htmlspecialchars($cs['category_name']); ?></div>
                            <div class="category-item-stats"><?php echo number_format($cs['products_count']); ?> منتج &bull; <?php echo number_format($cs['qty_sold'], 0); ?> وحدة</div>
                        </div>
                        <div class="category-item-value">
                            <div><?php echo formatMoney($cs['sales_total']); ?></div>
                            <div style="font-size:0.68rem; color:var(--gray-400);"><?php echo $cs_pct; ?>%</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- جدول المبيعات التفصيلي -->
    <div class="card">
        <div class="card-header">
            <h2>تفاصيل المبيعات حسب المنتج (<?php echo number_format(count($products_sales)); ?> منتج)</h2>
            <span class="badge badge-info"><?php echo formatMoney($summary['total_sales']); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($products_sales)): ?>
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
                            <th><?php echo t('reports_export_qty_sold'); ?></th>
                            <th><?php echo t('dashboard_total_sales'); ?></th>
                            <th><?php echo t('reports_cost_label'); ?></th>
                            <th><?php echo t('profit'); ?></th>
                            <th><?php echo t('sales_margin'); ?></th>
                            <th><?php echo t('reports_count'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products_sales as $i => $ps):
                            $ps_margin = $ps['sales_total'] > 0 ? round(($ps['profit'] / $ps['sales_total']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($ps['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ps['name']); ?></td>
                            <td>
                                <?php if ($ps['category_id'] > 0): ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:12px; font-size:0.78rem; font-weight:600; color:#fff; background:<?php echo $ps['category_color']; ?>">
                                    <?php echo htmlspecialchars($ps['category_name']); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?php echo number_format($ps['qty_sold'], 2); ?> <?php echo htmlspecialchars($ps['unit']); ?></td>
                            <td style="font-weight:700; color:#2563eb;"><?php echo formatMoney($ps['sales_total']); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($ps['cost_total']); ?></td>
                            <td style="font-weight:700; color:<?php echo $ps['profit'] >= 0 ? '#059669' : '#dc2626'; ?>"><?php echo formatMoney($ps['profit']); ?></td>
                            <td>
                                <span class="badge <?php echo $ps_margin >= 20 ? 'badge-success' : ($ps_margin >= 10 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo $ps_margin; ?>%
                                </span>
                            </td>
                            <td style="font-size:0.8rem; color:var(--gray-500);"><?php echo number_format($ps['transactions']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:800;">
                            <td colspan="4">المجموع</td>
                            <td><?php echo number_format($summary['total_qty'], 2); ?></td>
                            <td style="color:#2563eb;"><?php echo formatMoney($summary['total_sales']); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($summary['total_cost']); ?></td>
                            <td style="color:#059669;"><?php echo formatMoney($summary['total_profit']); ?></td>
                            <td><span class="badge badge-success"><?php echo $profit_margin; ?>%</span></td>
                            <td><?php echo number_format($summary['total_transactions']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
(function() {
    // === إعدادات عامة لـ Chart.js ===
    Chart.defaults.font.family = 'Tajawal, Segoe UI, sans-serif';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;

    const currency = window.CURRENCY || '';

    // === 1. منحنى المبيعات اليومية ===
    <?php if (!empty($daily_sales)): ?>
    new Chart(document.getElementById('salesTrendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return date('d/m', strtotime($d['sale_date'])); }, $daily_sales)); ?>,
            datasets: [
                {
                    label: 'المبيعات',
                    data: <?php echo json_encode(array_column($daily_sales, 'daily_total')); ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    borderWidth: 2.5
                },
                {
                    label: 'الربح',
                    data: <?php echo json_encode(array_column($daily_sales, 'daily_profit')); ?>,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,0.08)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    borderWidth: 2.5,
                    borderDash: [5, 3]
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') + ' ' + currency
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: v => v.toLocaleString('ar-MA') + ' ' + currency }
                }
            }
        }
    });
    <?php endif; ?>

    // === 2. المبيعات حسب التصنيف (دائري) ===
    <?php if (!empty($category_sales)): ?>
    new Chart(document.getElementById('categoryPieChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(function($c) { return $c['category_name']; }, $category_sales)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map(function($c) { return floatval($c['sales_total']); }, $category_sales)); ?>,
                backgroundColor: <?php echo json_encode(array_map(function($c) { return $c['category_color']; }, $category_sales)); ?>,
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
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = ((ctx.parsed / total) * 100).toFixed(1);
                            return ctx.label + ': ' + parseFloat(ctx.parsed).toLocaleString('ar-MA') + ' ' + currency + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>

    // === 3. أرباح vs تكاليف (شهري) ===
    <?php if (!empty($monthly_sales)): ?>
    new Chart(document.getElementById('profitVsCostChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($m) { return $m['month_key']; }, $monthly_sales)); ?>,
            datasets: [
                {
                    label: 'المبيعات',
                    data: <?php echo json_encode(array_map(function($m) { return floatval($m['month_total']); }, $monthly_sales)); ?>,
                    backgroundColor: 'rgba(37,99,235,0.8)',
                    borderRadius: 4
                },
                {
                    label: 'التكلفة',
                    data: <?php echo json_encode(array_map(function($m) { return floatval($m['month_cost']); }, $monthly_sales)); ?>,
                    backgroundColor: 'rgba(220,38,38,0.7)',
                    borderRadius: 4
                },
                {
                    label: 'الربح',
                    data: <?php echo json_encode(array_map(function($m) { return floatval($m['month_profit']); }, $monthly_sales)); ?>,
                    backgroundColor: 'rgba(5,150,105,0.8)',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') + ' ' + currency
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('ar-MA') } },
                x: { stacked: false }
            }
        }
    });
    <?php endif; ?>

    // === 4. أفضل 10 منتجات ===
    <?php if (!empty($top_products)): ?>
    const topCtx = document.getElementById('topProductsChart').getContext('2d');
    new Chart(topCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($p) { return mb_substr($p['name'], 0, 25) . (mb_strlen($p['name']) > 25 ? '...' : ''); }, $top_products)); ?>,
            datasets: [{
                label: 'الإيرادات',
                data: <?php echo json_encode(array_map(function($p) { return floatval($p['sales_total']); }, $top_products)); ?>,
                backgroundColor: <?php echo json_encode(array_map(function($p) { return $p['category_color']; }, $top_products)); ?>,
                borderRadius: 6,
                barThickness: 24
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => parseFloat(ctx.parsed.x).toLocaleString('ar-MA') + ' ' + currency
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('ar-MA') } }
            }
        }
    });
    <?php endif; ?>
})();
</script>

<?php require_once '../includes/footer.php'; ?>
