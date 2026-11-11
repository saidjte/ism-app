<?php
/**
 * تقرير الأرباح الشهري/السنوي المفصّل
 * صافي الربح = المبيعات - تكلفة البضاعة المباعة - المصاريف - الضرائب
 */
require_once '../includes/bootstrap.php';
$page_title = t('nav_profit_report');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('reports.profits');

// === الفلاتر ===
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_category = intval($_GET['category'] ?? 0);
$view_mode = $_GET['view'] ?? 'monthly'; // monthly | yearly

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

// === جلب التصنيفات ===
$categories = [];
try {
    $categories = fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC");
} catch (Exception $e) {}

// === بناء شروط الفلترة ===
$salesWhere = ["s.sale_date BETWEEN ? AND ?"];
$salesParams = [$date_from, $date_to];

$purchaseWhere = ["pur.purchase_date BETWEEN ? AND ?"];
$purchaseParams = [$date_from, $date_to];

$expenseWhere = ["e.expense_date BETWEEN ? AND ?"];
$expenseParams = [$date_from, $date_to];

if ($filter_category > 0) {
    $salesWhere[] = "p.category_id = ?";
    $salesParams[] = $filter_category;
    $purchaseWhere[] = "pr.category_id = ?";
    $purchaseParams[] = $filter_category;
}

$salesWhereClause = implode(' AND ', $salesWhere);
$purchaseWhereClause = implode(' AND ', $purchaseWhere);
$expenseWhereClause = implode(' AND ', $expenseWhere);

// === بناء JOIN شرطي ===
$joinProducts = '';
if ($filter_category > 0) {
    $joinProducts = ' JOIN products p ON p.id = s.product_id';
}
$purchaseJoinProducts = '';
if ($filter_category > 0) {
    $purchaseJoinProducts = ' JOIN products pr ON pr.id = pur.product_id';
}

// =============================================
//  1. ملخص الأرباح (البطاقات العلوية)
// =============================================

// إجمالي المبيعات
$salesSummary = fetchOne(
    "SELECT
        COALESCE(SUM(s.total), 0) as total_sales,
        COALESCE(SUM(s.cost_total), 0) as total_cogs,
        COALESCE(SUM(s.profit), 0) as gross_profit,
        COUNT(DISTINCT s.id) as total_transactions,
        COALESCE(SUM(s.quantity), 0) as total_qty
     FROM sales s
     $joinProducts
     WHERE $salesWhereClause",
    $salesParams
);

$total_sales = floatval($salesSummary['total_sales']);
$total_cogs = floatval($salesSummary['total_cogs']);
$gross_profit = floatval($salesSummary['gross_profit']);

// إجمالي المشتريات في الفترة
$purchaseSummary = fetchOne(
    "SELECT
        COALESCE(SUM(pur.total), 0) as total_purchases,
        COALESCE(SUM(pur.quantity), 0) as total_purchase_qty
     FROM purchases pur
     $purchaseJoinProducts
     WHERE $purchaseWhereClause",
    $purchaseParams
);
$total_purchases = floatval($purchaseSummary['total_purchases']);

// إجمالي الضرائب على المشتريات في الفترة
$taxSummary = fetchOne(
    "SELECT
        COALESCE(SUM(pur.tax_amount), 0) as total_taxes
     FROM purchases pur
     $purchaseJoinProducts
     WHERE $purchaseWhereClause",
    $purchaseParams
);
$total_taxes = floatval($taxSummary['total_taxes']);

// المصاريف في الفترة
$total_expenses = 0;
$expense_by_category = [];
$expenses_table_exists = true;
try {
    $total_expenses = floatval(fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?",
        [$date_from, $date_to]
    )['total']);

    $expense_by_category = fetchAll(
        "SELECT category, COALESCE(SUM(amount), 0) as total, COUNT(*) as count
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         GROUP BY category
         ORDER BY total DESC",
        [$date_from, $date_to]
    );
} catch (Exception $e) {
    $expenses_table_exists = false;
}

// حساب صافي الربح
$net_profit = $gross_profit - $total_expenses - $total_taxes;

// هوامش الربح
$gross_margin = $total_sales > 0 ? ($gross_profit / $total_sales) * 100 : 0;
$net_margin = $total_sales > 0 ? ($net_profit / $total_sales) * 100 : 0;

// =============================================
//  2. الأرباح الشهرية (للرسم البياني)
// =============================================
$monthly_profits = fetchAll(
    "SELECT
        DATE_FORMAT(s.sale_date, '%Y-%m') as month_key,
        COALESCE(SUM(s.total), 0) as month_sales,
        COALESCE(SUM(s.cost_total), 0) as month_cogs,
        COALESCE(SUM(s.profit), 0) as month_gross_profit,
        COUNT(DISTINCT s.id) as month_transactions
     FROM sales s
     $joinProducts
     WHERE $salesWhereClause
     GROUP BY month_key
     ORDER BY month_key ASC",
    $salesParams
);

// المصاريف الشهرية
$monthly_expenses = [];
try {
    $monthly_exp_raw = fetchAll(
        "SELECT
            DATE_FORMAT(expense_date, '%Y-%m') as month_key,
            COALESCE(SUM(amount), 0) as month_expenses
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         GROUP BY month_key
         ORDER BY month_key ASC",
        [$date_from, $date_to]
    );
    foreach ($monthly_exp_raw as $me) {
        $monthly_expenses[$me['month_key']] = floatval($me['month_expenses']);
    }
} catch (Exception $e) {}

// الضرائب الشهرية
$monthly_taxes = [];
try {
    $monthly_tax_raw = fetchAll(
        "SELECT
            DATE_FORMAT(purchase_date, '%Y-%m') as month_key,
            COALESCE(SUM(tax_amount), 0) as month_taxes
         FROM purchases
         WHERE purchase_date BETWEEN ? AND ? AND tax_amount > 0
         GROUP BY month_key
         ORDER BY month_key ASC",
        [$date_from, $date_to]
    );
    foreach ($monthly_tax_raw as $mt) {
        $monthly_taxes[$mt['month_key']] = floatval($mt['month_taxes']);
    }
} catch (Exception $e) {}

// دمج الأرباح الشهرية مع المصاريف والضرائب
$all_months = [];
foreach ($monthly_profits as $mp) {
    $key = $mp['month_key'];
    $exp = $monthly_expenses[$key] ?? 0;
    $tax = $monthly_taxes[$key] ?? 0;
    $gp = floatval($mp['month_gross_profit']);
    $all_months[$key] = [
        'month_key' => $key,
        'month_sales' => floatval($mp['month_sales']),
        'month_cogs' => floatval($mp['month_cogs']),
        'month_gross_profit' => $gp,
        'month_expenses' => $exp,
        'month_taxes' => $tax,
        'month_net_profit' => $gp - $exp - $tax,
        'month_transactions' => intval($mp['month_transactions']),
    ];
}
// إضافة أشهر المصاريف التي ليس فيها مبيعات
foreach ($monthly_expenses as $key => $exp) {
    if (!isset($all_months[$key])) {
        $tax = $monthly_taxes[$key] ?? 0;
        $all_months[$key] = [
            'month_key' => $key,
            'month_sales' => 0,
            'month_cogs' => 0,
            'month_gross_profit' => 0,
            'month_expenses' => $exp,
            'month_taxes' => $tax,
            'month_net_profit' => -$exp - $tax,
            'month_transactions' => 0,
        ];
    }
}
ksort($all_months);
$all_months = array_values($all_months);

// =============================================
//  3. الأرباح حسب التصنيف
// =============================================
$category_profits = fetchAll(
    "SELECT
        COALESCE(c.name, 'بدون تصنيف') as category_name,
        COALESCE(c.color, '#64748b') as category_color,
        COALESCE(c.id, 0) as category_id,
        COUNT(DISTINCT s.product_id) as products_count,
        SUM(s.quantity) as qty_sold,
        SUM(s.total) as sales_total,
        SUM(s.cost_total) as cogs_total,
        SUM(s.profit) as profit_total
     FROM sales s
     JOIN products p ON p.id = s.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $salesWhereClause
     GROUP BY COALESCE(c.id, 0)
     ORDER BY profit_total DESC",
    $salesParams
);

// =============================================
//  4. أفضل وأسوأ المنتجات ربحية
// =============================================
$product_profit_details = fetchAll(
    "SELECT
        p.id, p.code, p.name, p.unit,
        COALESCE(c.name, '—') as category_name,
        COALESCE(c.color, '#64748b') as category_color,
        SUM(s.quantity) as qty_sold,
        SUM(s.total) as sales_total,
        SUM(s.cost_total) as cogs_total,
        SUM(s.profit) as profit_total,
        COUNT(s.id) as transactions
     FROM sales s
     JOIN products p ON p.id = s.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $salesWhereClause
     GROUP BY s.product_id
     ORDER BY profit_total DESC",
    $salesParams
);

$top_products = array_slice($product_profit_details, 0, 10);
$worst_products = array_slice(array_reverse($product_profit_details), 0, 5);

// =============================================
//  5. الأرباح اليومية
// =============================================
$daily_profits = fetchAll(
    "SELECT
        DATE(s.sale_date) as sale_date,
        SUM(s.total) as daily_sales,
        SUM(s.cost_total) as daily_cogs,
        SUM(s.profit) as daily_gross_profit,
        COUNT(s.id) as daily_transactions
     FROM sales s
     $joinProducts
     WHERE $salesWhereClause
     GROUP BY DATE(s.sale_date)
     ORDER BY sale_date ASC",
    $salesParams
);

// قيمة المخزون الحالي
$stockRow = fetchOne(
    "SELECT
        COALESCE(SUM(quantity * purchase_price), 0) as cost_total,
        COALESCE(SUM(quantity * selling_price), 0) as retail_total
     FROM products"
);
$stock_value = floatval($stockRow['cost_total']);
$stock_value_retail = floatval($stockRow['retail_total']);

// العائد على الاستثمار (ROI)
$roi = ($total_cogs > 0) ? (($net_profit / $total_cogs) * 100) : 0;

$load_chartjs = true;
require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <?php echo t('nav_profit_report'); ?>
        </h1>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="../api/export.php?type=profit_report&format=csv&start_date=<?php echo urlencode($date_from); ?>&end_date=<?php echo urlencode($date_to); ?><?php echo $filter_category ? '&category_id='.$filter_category : ''; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
                📥 <?php echo t('reports_export_csv'); ?>
            </a>
            <a href="../api/export.php?type=profit_report&format=pdf&start_date=<?php echo urlencode($date_from); ?>&end_date=<?php echo urlencode($date_to); ?><?php echo $filter_category ? '&category_id='.$filter_category : ''; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;" target="_blank">
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
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        عرض
                    </button>
                    <a href="profit-report.php" class="btn btn-outline">مسح</a>
                </div>
            </div>
            <div style="margin-top:10px;">
                <div class="quick-periods">
                    <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn <?php echo ($date_from == date('Y-m-01') && $date_to == date('Y-m-d')) ? 'active' : ''; ?>">هذا الشهر</a>
                    <a href="?date_from=<?php echo date('Y-01-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn <?php echo ($date_from == date('Y-01-01') && $date_to == date('Y-m-d')) ? 'active' : ''; ?>">هذا العام</a>
                    <a href="?date_from=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('-1 month')); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">الشهر الماضي</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 3 أشهر</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-6 months')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر 6 أشهر</a>
                    <a href="?date_from=<?php echo date('Y-m-d', strtotime('-1 year')); ?>&date_to=<?php echo date('Y-m-d'); ?>&category=<?php echo $filter_category; ?>" class="qp-btn">آخر سنة</a>
                </div>
            </div>
        </form>
    </div>

    <!-- بطاقات ملخص الأرباح -->
    <div class="report-summary">
        <div class="summary-card">
            <div class="summary-icon" style="background:#eff6ff; color:#2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('dashboard_total_sales'); ?></h4>
                <div class="summary-value" style="color:#2563eb;"><?php echo formatMoney($total_sales); ?></div>
                <div class="summary-sub"><?php echo number_format($salesSummary['total_transactions']); ?> عملية | <?php echo number_format($salesSummary['total_qty'], 0); ?> وحدة</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef2f2; color:#dc2626;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('reports_export_cogs'); ?></h4>
                <div class="summary-value" style="color:#dc2626;"><?php echo formatMoney($total_cogs); ?></div>
                <div class="summary-sub">هامش إجمالي: <?php echo number_format($gross_margin, 1); ?>%</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#d1fae5; color:#059669;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('reports_gross_profit'); ?></h4>
                <div class="summary-value" style="color:#059669;"><?php echo formatMoney($gross_profit); ?></div>
                <div class="summary-sub">مبيعات - تكلفة البضاعة</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef3c7; color:#d97706;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('expenses'); ?></h4>
                <div class="summary-value" style="color:#d97706;"><?php echo formatMoney($total_expenses); ?></div>
                <div class="summary-sub"><?php echo $expenses_table_exists ? count($expense_by_category) . ' تصنيف' : 'جدول غير متوفر'; ?></div>
            </div>
        </div>
        <?php if ($total_taxes > 0): ?>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fce7f3; color:#be185d;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
            </div>
            <div class="summary-info">
                <h4>الضرائب</h4>
                <div class="summary-value" style="color:#be185d;"><?php echo formatMoney($total_taxes); ?></div>
                <div class="summary-sub">ضرائب المشتريات</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="summary-card" style="border: 2px solid <?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>; background: <?php echo $net_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>;">
            <div class="summary-icon" style="background:<?php echo $net_profit >= 0 ? '#d1fae5' : '#fee2e2'; ?>; color:<?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
            </div>
            <div class="summary-info">
                <h4 style="font-size:1.05rem;">صافي الربح</h4>
                <div class="summary-value" style="color:<?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>; font-size:1.4rem; font-weight:800;">
                    <?php echo formatMoney($net_profit); ?>
                </div>
                <div class="summary-sub">هامش صافي: <?php echo number_format($net_margin, 1); ?>% | ROI: <?php echo number_format($roi, 1); ?>%</div>
            </div>
        </div>
    </div>

    <!-- حساب الربح التفصيلي (Waterfall) -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                حساب صافي الربح
            </h2>
        </div>
        <div class="card-body">
            <div class="profit-summary">
                <div class="profit-item">
                    <h4>➕ إجمالي المبيعات</h4>
                    <div class="value" style="color: #2563eb; font-weight:700;"><?php echo formatMoney($total_sales); ?></div>
                </div>
                <div class="profit-item">
                    <h4>➖ تكلفة البضاعة المباعة (COGS)</h4>
                    <div class="value" style="color: #dc2626;">- <?php echo formatMoney($total_cogs); ?></div>
                </div>
                <div class="profit-item" style="background: <?php echo $gross_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 10px 14px; border-radius: 8px;">
                    <h4 style="font-weight:700;">= الربح الإجمالي (Gross Profit)</h4>
                    <div class="value <?php echo $gross_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-weight:700;">
                        <?php echo formatMoney($gross_profit); ?>
                    </div>
                </div>
                <?php if ($expenses_table_exists && $total_expenses > 0): ?>
                <div class="profit-item">
                    <h4>➖ المصاريف التشغيلية</h4>
                    <div class="value" style="color: #d97706;">- <?php echo formatMoney($total_expenses); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($total_taxes > 0): ?>
                <div class="profit-item">
                    <h4>➖ الضرائب على المشتريات</h4>
                    <div class="value" style="color: #be185d;">- <?php echo formatMoney($total_taxes); ?></div>
                </div>
                <?php endif; ?>
                <div class="profit-item" style="background: <?php echo $net_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 12px 14px; border-radius: 8px; border: 2px solid <?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <h4 style="font-weight:700; font-size: 1.05rem;">= صافي الربح (Net Profit)</h4>
                    <div class="value <?php echo $net_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-weight:800; font-size: 1.2rem;">
                        <?php echo formatMoney($net_profit); ?>
                        <span style="font-size:0.8rem; font-weight:400;">(<?php echo number_format($net_margin, 1); ?>%)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- الرسوم البيانية -->
    <div class="charts-grid">
        <!-- منحنى الأرباح الشهرية -->
        <div class="chart-card" style="grid-column: span 2;">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    تطور الأرباح الشهرية
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 320px;">
                    <canvas id="monthlyProfitChart"></canvas>
                </div>
            </div>
        </div>

        <!-- الأرباح حسب التصنيف -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0110 10h-10z"/></svg>
                    الربح حسب التصنيف
                </h3>
            </div>
            <div class="chart-card-body pie-body">
                <div style="width:100%; max-width:320px;">
                    <canvas id="categoryProfitChart"></canvas>
                </div>
            </div>
        </div>

        <!-- مصاريف vs أرباح (شريطي) -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    المبيعات vs التكلفة vs الربح
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 300px;">
                    <canvas id="salesVsCostChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- أفضل المنتجات + تفصيل المصاريف -->
    <?php if (!empty($top_products)): ?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
        <!-- أفضل 10 منتجات ربحية -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    أفضل 10 منتجات ربحية
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 300px;">
                    <canvas id="topProfitChart"></canvas>
                </div>
            </div>
        </div>

        <!-- تفصيل المصاريف -->
        <?php if ($expenses_table_exists && !empty($expense_by_category)): ?>
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    تفصيل المصاريف
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="category-breakdown">
                    <?php foreach ($expense_by_category as $ec):
                        $ec_pct = $total_expenses > 0 ? round(($ec['total'] / $total_expenses) * 100, 1) : 0;
                    ?>
                    <div class="category-item">
                        <div class="category-color-dot" style="background:#d97706;"></div>
                        <div class="category-item-info">
                            <div class="category-item-name"><?php echo htmlspecialchars($ec['category']); ?></div>
                            <div class="category-item-stats"><?php echo number_format($ec['count']); ?> عملية</div>
                        </div>
                        <div class="category-item-value">
                            <div style="color:#d97706; font-weight:600;"><?php echo formatMoney($ec['total']); ?></div>
                            <div style="font-size:0.68rem; color:var(--gray-400);"><?php echo $ec_pct; ?>%</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    الأرباح حسب التصنيف
                </h3>
            </div>
            <div class="chart-card-body">
                <div class="category-breakdown">
                    <?php foreach ($category_profits as $cp):
                        $cp_pct = $total_sales > 0 ? round(($cp['sales_total'] / $total_sales) * 100, 1) : 0;
                    ?>
                    <div class="category-item">
                        <div class="category-color-dot" style="background:<?php echo $cp['category_color']; ?>"></div>
                        <div class="category-item-info">
                            <div class="category-item-name"><?php echo htmlspecialchars($cp['category_name']); ?></div>
                            <div class="category-item-stats"><?php echo number_format($cp['products_count']); ?> منتج</div>
                        </div>
                        <div class="category-item-value">
                            <div style="color:<?php echo $cp['profit_total'] >= 0 ? '#059669' : '#dc2626'; ?>; font-weight:600;"><?php echo formatMoney($cp['profit_total']); ?></div>
                            <div style="font-size:0.68rem; color:var(--gray-400);"><?php echo $cp_pct; ?>%</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- جدول الأرباح الشهرية -->
    <?php if (!empty($all_months)): ?>
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>ملخص الأرباح الشهرية</h2>
            <span class="badge badge-info"><?php echo count($all_months); ?> شهر</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>الشهر</th>
                            <th>المبيعات</th>
                            <th>تكلفة البضاعة</th>
                            <th>الربح الإجمالي</th>
                            <th>المصاريف</th>
                            <?php if ($total_taxes > 0): ?><th>الضرائب</th><?php endif; ?>
                            <th>صافي الربح</th>
                            <th>هامش صافي</th>
                            <th>العمليات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_months as $m):
                            $m_net_margin = $m['month_sales'] > 0 ? ($m['month_net_profit'] / $m['month_sales']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo $m['month_key']; ?></strong></td>
                            <td style="font-weight:600; color:#2563eb;"><?php echo formatMoney($m['month_sales']); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($m['month_cogs']); ?></td>
                            <td style="font-weight:600; color:<?php echo $m['month_gross_profit'] >= 0 ? '#059669' : '#dc2626'; ?>;"><?php echo formatMoney($m['month_gross_profit']); ?></td>
                            <td style="color:#d97706;"><?php echo formatMoney($m['month_expenses']); ?></td>
                            <?php if ($total_taxes > 0): ?><td style="color:#be185d;"><?php echo formatMoney($m['month_taxes']); ?></td><?php endif; ?>
                            <td style="font-weight:800; color:<?php echo $m['month_net_profit'] >= 0 ? '#059669' : '#dc2626'; ?>;">
                                <?php echo formatMoney($m['month_net_profit']); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $m_net_margin >= 15 ? 'badge-success' : ($m_net_margin >= 5 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo number_format($m_net_margin, 1); ?>%
                                </span>
                            </td>
                            <td style="font-size:0.8rem; color:var(--gray-500);"><?php echo number_format($m['month_transactions']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:800;">
                            <td>المجموع</td>
                            <td style="color:#2563eb;"><?php echo formatMoney($total_sales); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($total_cogs); ?></td>
                            <td style="color:#059669;"><?php echo formatMoney($gross_profit); ?></td>
                            <td style="color:#d97706;"><?php echo formatMoney($total_expenses); ?></td>
                            <?php if ($total_taxes > 0): ?><td style="color:#be185d;"><?php echo formatMoney($total_taxes); ?></td><?php endif; ?>
                            <td style="color:<?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>; font-size:1.05rem;"><?php echo formatMoney($net_profit); ?></td>
                            <td><span class="badge <?php echo $net_margin >= 15 ? 'badge-success' : ($net_margin >= 5 ? 'badge-warning' : 'badge-danger'); ?>"><?php echo number_format($net_margin, 1); ?>%</span></td>
                            <td><?php echo number_format($salesSummary['total_transactions']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- جدول تفاصيل الأرباح حسب المنتج -->
    <div class="card">
        <div class="card-header">
            <h2>تفاصيل الأرباح حسب المنتج (<?php echo number_format(count($product_profit_details)); ?> منتج)</h2>
            <span class="badge badge-info"><?php echo formatMoney($gross_profit); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($product_profit_details)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;"><?php echo t('reports_no_data'); ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>التصنيف</th>
                            <th>الكمية المباعة</th>
                            <th>إجمالي المبيعات</th>
                            <th>التكلفة</th>
                            <th>الربح</th>
                            <th>هامش الربح</th>
                            <th>العمليات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($product_profit_details as $i => $pp):
                            $pp_margin = $pp['sales_total'] > 0 ? round(($pp['profit_total'] / $pp['sales_total']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($pp['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($pp['name']); ?></td>
                            <td>
                                <?php if (isset($pp['category_id']) && $pp['category_id'] > 0): ?>
                                <span style="display:inline-block; padding:2px 10px; border-radius:12px; font-size:0.78rem; font-weight:600; color:#fff; background:<?php echo $pp['category_color']; ?>">
                                    <?php echo htmlspecialchars($pp['category_name']); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?php echo number_format($pp['qty_sold'], 2); ?> <?php echo htmlspecialchars($pp['unit']); ?></td>
                            <td style="font-weight:700; color:#2563eb;"><?php echo formatMoney($pp['sales_total']); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($pp['cogs_total']); ?></td>
                            <td style="font-weight:700; color:<?php echo $pp['profit_total'] >= 0 ? '#059669' : '#dc2626'; ?>"><?php echo formatMoney($pp['profit_total']); ?></td>
                            <td>
                                <span class="badge <?php echo $pp_margin >= 20 ? 'badge-success' : ($pp_margin >= 10 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo $pp_margin; ?>%
                                </span>
                            </td>
                            <td style="font-size:0.8rem; color:var(--gray-500);"><?php echo number_format($pp['transactions']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:800;">
                            <td colspan="4">المجموع</td>
                            <td><?php echo number_format($salesSummary['total_qty'], 2); ?></td>
                            <td style="color:#2563eb;"><?php echo formatMoney($total_sales); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($total_cogs); ?></td>
                            <td style="color:#059669;"><?php echo formatMoney($gross_profit); ?></td>
                            <td><span class="badge badge-success"><?php echo number_format($gross_margin, 1); ?>%</span></td>
                            <td><?php echo number_format($salesSummary['total_transactions']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ملخص إضافي -->
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; margin-top:1.5rem;">
        <div class="card">
            <div class="card-header"><h2>مؤشرات الأداء</h2></div>
            <div class="card-body">
                <div style="display:grid; gap:12px;">
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">هامش الربح الإجمالي</span>
                        <span style="font-weight:700; color:<?php echo $gross_margin >= 20 ? '#059669' : '#d97706'; ?>;"><?php echo number_format($gross_margin, 1); ?>%</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">هامش صافي الربح</span>
                        <span style="font-weight:700; color:<?php echo $net_margin >= 10 ? '#059669' : '#d97706'; ?>;"><?php echo number_format($net_margin, 1); ?>%</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">العائد على الاستثمار (ROI)</span>
                        <span style="font-weight:700; color:<?php echo $roi >= 15 ? '#059669' : '#d97706'; ?>;"><?php echo number_format($roi, 1); ?>%</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">نسبة المصاريف من المبيعات</span>
                        <span style="font-weight:700;"><?php echo $total_sales > 0 ? number_format(($total_expenses / $total_sales) * 100, 1) : 0; ?>%</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">متوسط الربح لكل عملية</span>
                        <span style="font-weight:700;"><?php echo formatMoney($salesSummary['total_transactions'] > 0 ? $gross_profit / $salesSummary['total_transactions'] : 0); ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">قيمة المخزون الحالي (سعر الشراء)</span>
                        <span style="font-weight:700; color:#2563eb;"><?php echo formatMoney($stock_value); ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">قيمة المخزون الحالي (سعر البيع)</span>
                        <span style="font-weight:700; color:#059669;"><?php echo formatMoney($stock_value_retail); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>ملخص المشتريات</h2></div>
            <div class="card-body">
                <div style="display:grid; gap:12px;">
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">إجمالي المشتريات في الفترة</span>
                        <span style="font-weight:700; color:#dc2626;"><?php echo formatMoney($total_purchases); ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">إجمالي تكلفة البضاعة المباعة</span>
                        <span style="font-weight:700; color:#dc2626;"><?php echo formatMoney($total_cogs); ?></span>
                    </div>
                    <?php if ($total_taxes > 0): ?>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">إجمالي الضرائب</span>
                        <span style="font-weight:700; color:#be185d;"><?php echo formatMoney($total_taxes); ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">نسبة الضرائب من المشتريات</span>
                        <span style="font-weight:700;"><?php echo $total_purchases > 0 ? number_format(($total_taxes / $total_purchases) * 100, 1) : 0; ?>%</span>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                        <span style="color:var(--gray-600);">إجمالي المصاريف التشغيلية</span>
                        <span style="font-weight:700; color:#d97706;"><?php echo formatMoney($total_expenses); ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 12px; background:<?php echo $net_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; border-radius:8px; border:1px solid <?php echo $net_profit >= 0 ? '#bbf7d0' : '#fecaca'; ?>;">
                        <span style="font-weight:700; color:<?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;">صافي الربح</span>
                        <span style="font-weight:800; font-size:1.1rem; color:<?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;"><?php echo formatMoney($net_profit); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
(function() {
    Chart.defaults.font.family = 'Tajawal, Segoe UI, sans-serif';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;

    const currency = window.CURRENCY || '';

    // === 1. تطور الأرباح الشهرية ===
    <?php if (!empty($all_months)): ?>
    new Chart(document.getElementById('monthlyProfitChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($all_months, 'month_key')); ?>,
            datasets: [
                {
                    label: 'المبيعات',
                    data: <?php echo json_encode(array_column($all_months, 'month_sales')); ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.08)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    borderWidth: 2.5
                },
                {
                    label: 'الربح الإجمالي',
                    data: <?php echo json_encode(array_column($all_months, 'month_gross_profit')); ?>,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,0.08)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    borderWidth: 2.5
                },
                {
                    label: 'صافي الربح',
                    data: <?php echo json_encode(array_column($all_months, 'month_net_profit')); ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124,58,237,0.08)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    borderWidth: 2.5,
                    borderDash: [5, 3]
                },
                {
                    label: 'المصاريف',
                    data: <?php echo json_encode(array_column($all_months, 'month_expenses')); ?>,
                    borderColor: '#d97706',
                    backgroundColor: 'rgba(217,119,6,0.05)',
                    fill: false,
                    tension: 0.4,
                    pointRadius: 3,
                    borderWidth: 2,
                    borderDash: [3, 3]
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

    // === 2. الربح حسب التصنيف (دائري) ===
    <?php if (!empty($category_profits)): ?>
    new Chart(document.getElementById('categoryProfitChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($category_profits, 'category_name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map(function($c) { return max(0, floatval($c['profit_total'])); }, $category_profits)); ?>,
                backgroundColor: <?php echo json_encode(array_column($category_profits, 'category_color')); ?>,
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

    // === 3. المبيعات vs التكلفة vs الربح (شريطي شهري) ===
    <?php if (!empty($all_months)): ?>
    new Chart(document.getElementById('salesVsCostChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($all_months, 'month_key')); ?>,
            datasets: [
                {
                    label: 'المبيعات',
                    data: <?php echo json_encode(array_column($all_months, 'month_sales')); ?>,
                    backgroundColor: 'rgba(37,99,235,0.8)',
                    borderRadius: 4
                },
                {
                    label: 'التكلفة',
                    data: <?php echo json_encode(array_column($all_months, 'month_cogs')); ?>,
                    backgroundColor: 'rgba(220,38,38,0.7)',
                    borderRadius: 4
                },
                {
                    label: 'صافي الربح',
                    data: <?php echo json_encode(array_column($all_months, 'month_net_profit')); ?>,
                    backgroundColor: <?php echo json_encode(array_map(function($m) { return $m['month_net_profit'] >= 0 ? 'rgba(5,150,105,0.8)' : 'rgba(220,38,38,0.4)'; }, $all_months)); ?>,
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

    // === 4. أفضل 10 منتجات ربحية ===
    <?php if (!empty($top_products)): ?>
    new Chart(document.getElementById('topProfitChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($p) { return mb_substr($p['name'], 0, 25) . (mb_strlen($p['name']) > 25 ? '...' : ''); }, $top_products)); ?>,
            datasets: [{
                label: 'الربح',
                data: <?php echo json_encode(array_map(function($p) { return floatval($p['profit_total']); }, $top_products)); ?>,
                backgroundColor: <?php echo json_encode(array_map(function($p) { return $p['profit_total'] >= 0 ? $p['category_color'] : '#dc2626'; }, $top_products)); ?>,
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
