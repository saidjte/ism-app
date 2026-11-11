<?php
/**
 * حساب الأرباح — النسخة المحسّنة (v4.41.0)
 *
 * تحسينات الأداء:
 *   - نقل header.php بعد معالجة البيانات (مثل profit-report.php)
 *   - استعلامات SQL مباشرة بدلاً من SaleService (إزالة overhead الـ Materialized View)
 *   - دمج استعلامات ضرائب + مصاريف في UNION ALL
 *   - استعلام واحد موحّد لتفصيل المنتجات + المخزون (بدل استعلامين منفصلين)
 *   - إزالة الاستعلامات المكررة (كانت المصاريف والضرائب تُستعلم مرتين)
 */
require_once '../includes/bootstrap.php';
$page_title = t('nav_calculate_profits');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('reports.profits');

// === فلترة التاريخ ===
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = date('Y-m-d');

// حد المنتجات
$product_limit_raw = $_GET['limit'] ?? getSetting('product_limit', 100);
$product_limit = intval($product_limit_raw);
if ($product_limit === 0) {
    $product_limit = 999999;
} else {
    $product_limit = max(10, min(5000, $product_limit));
}

// =============================================
//  1. ملخص المبيعات (استعلام واحد مدمج)
// =============================================
$salesSummary = fetchOne(
    "SELECT
        COALESCE(SUM(s.total), 0) as total_sales,
        COALESCE(SUM(s.cost_total), 0) as total_cost,
        COALESCE(SUM(s.profit), 0) as gross_profit,
        COUNT(DISTINCT s.id) as total_transactions
     FROM sales s
     WHERE s.sale_date BETWEEN ? AND ?",
    [$start_date, $end_date]
);

$total_sales = floatval($salesSummary['total_sales']);
$total_cost = floatval($salesSummary['total_cost']);
$gross_profit = floatval($salesSummary['gross_profit']);

// =============================================
//  2. ضرائب + مصاريف (UNION ALL — استعلام واحد)
// =============================================
$total_taxes = 0;
$total_expenses = 0;
$expenses_table_exists = false;
$expense_by_category = [];

try {
    $summaryRows = fetchAll(
        "SELECT 'taxes' as type, COALESCE(SUM(tax_amount), 0) as total
         FROM purchases WHERE purchase_date BETWEEN ? AND ?
         UNION ALL
         SELECT 'expenses' as type, COALESCE(SUM(amount), 0) as total
         FROM expenses WHERE expense_date BETWEEN ? AND ?",
        [$start_date, $end_date, $start_date, $end_date]
    );
    foreach ($summaryRows as $row) {
        if ($row['type'] === 'taxes') {
            $total_taxes = floatval($row['total']);
        } elseif ($row['type'] === 'expenses') {
            $total_expenses = floatval($row['total']);
        }
    }
} catch (Exception $e) {
    // تراجع: استعلامات منفصلة
    try {
        $total_taxes = floatval(fetchOne(
            "SELECT COALESCE(SUM(tax_amount), 0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?",
            [$start_date, $end_date]
        )['total'] ?? 0);
    } catch (Exception $e2) {}
    try {
        $total_expenses = floatval(fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?",
            [$start_date, $end_date]
        )['total'] ?? 0);
    } catch (Exception $e2) {}
}

// المصاريف حسب التصنيف (فقط إن وُجدت مصاريف)
if ($total_expenses > 0) {
    $expenses_table_exists = true;
    try {
        $expense_by_category = fetchAll(
            "SELECT category, COALESCE(SUM(amount), 0) as total, COUNT(*) as count
             FROM expenses
             WHERE expense_date BETWEEN ? AND ?
             GROUP BY category
             ORDER BY total DESC",
            [$start_date, $end_date]
        );
    } catch (Exception $e) {
        $expenses_table_exists = false;
    }
}

// إجمالي المشتريات في الفترة
$total_purchases = 0;
try {
    $total_purchases = floatval(fetchOne(
        "SELECT COALESCE(SUM(total), 0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?",
        [$start_date, $end_date]
    )['total']);
} catch (Exception $e) {}

// حساب صافي الربح
$net_profit = $gross_profit - $total_expenses - $total_taxes;

// هوامش الربح
$gross_margin = $total_sales > 0 ? ($gross_profit / $total_sales) * 100 : 0;
$net_margin = $total_sales > 0 ? ($net_profit / $total_sales) * 100 : 0;

// =============================================
//  3. تفصيل المنتجات + المخزون (استعلام واحد موحّد)
//     يدمج product_profits و inventory_profit_data
// =============================================
$inventory_profit_data = fetchAll(
    "SELECT p.id, p.code, p.name, p.unit, p.quantity as current_stock, p.purchase_price, p.selling_price,
            COALESCE(pur_stats.total_purchased, 0) as total_purchased,
            COALESCE(pur_stats.total_purchase_amount, 0) as total_purchase_amount,
            COALESCE(sale_stats.total_sold, 0) as total_sold,
            COALESCE(sale_stats.total_sales_amount, 0) as total_sales_amount,
            COALESCE(sale_stats.total_cost_amount, 0) as total_cost_amount,
            COALESCE(sale_stats.total_profit, 0) as total_profit
     FROM products p
     LEFT JOIN (
         SELECT product_id,
                SUM(quantity) as total_purchased,
                SUM(total) as total_purchase_amount
         FROM purchases
         WHERE purchase_date BETWEEN ? AND ?
         GROUP BY product_id
     ) pur_stats ON p.id = pur_stats.product_id
     LEFT JOIN (
         SELECT product_id,
                SUM(quantity) as total_sold,
                SUM(total) as total_sales_amount,
                SUM(cost_total) as total_cost_amount,
                SUM(profit) as total_profit
         FROM sales
         WHERE sale_date BETWEEN ? AND ?
         GROUP BY product_id
     ) sale_stats ON p.id = sale_stats.product_id
     WHERE pur_stats.product_id IS NOT NULL OR sale_stats.product_id IS NOT NULL
     ORDER BY total_profit DESC
     LIMIT ?",
    [$start_date, $end_date, $start_date, $end_date, $product_limit]
);

// بناء product_profits من inventory_profit_data (بدون استعلام إضافي)
$product_profits = [];
foreach ($inventory_profit_data as $item) {
    if ($item['total_sold'] > 0) {
        $product_profits[] = [
            'id' => $item['id'],
            'code' => $item['code'],
            'name' => $item['name'],
            'unit' => $item['unit'],
            'current_stock' => $item['current_stock'],
            'current_purchase_price' => $item['purchase_price'],
            'current_selling_price' => $item['selling_price'],
            'total_sold' => floatval($item['total_sold']),
            'total_sales_amount' => floatval($item['total_sales_amount']),
            'total_cost_amount' => floatval($item['total_cost_amount']),
            'total_profit' => floatval($item['total_profit']),
        ];
    }
}

// إجماليات جدول تفصيل المنتجات (من الصفوف المعروضة)
$pp_total_sales = array_sum(array_column($product_profits, 'total_sales_amount'));
$pp_total_cost = array_sum(array_column($product_profits, 'total_cost_amount'));
$pp_total_profit = array_sum(array_column($product_profits, 'total_profit'));
$pp_gross_margin = $pp_total_sales > 0 ? ($pp_total_profit / $pp_total_sales) * 100 : 0;

// إجماليات جدول المخزون
$inv_total_purchases = 0;
$inv_total_sales = 0;
$inv_total_cost = 0;
$inv_total_profit = 0;
$inv_total_purchased_qty = 0;
$inv_total_sold_qty = 0;
foreach ($inventory_profit_data as $inv_item) {
    if ($inv_item['total_purchased'] == 0 && $inv_item['total_sold'] == 0) continue;
    $inv_total_purchases += floatval($inv_item['total_purchase_amount']);
    $inv_total_sales += floatval($inv_item['total_sales_amount']);
    $inv_total_cost += floatval($inv_item['total_cost_amount']);
    $inv_total_profit += floatval($inv_item['total_profit']);
    $inv_total_purchased_qty += floatval($inv_item['total_purchased']);
    $inv_total_sold_qty += floatval($inv_item['total_sold']);
}

// =============================================
//  4. قيمة المخزون الحالي
// =============================================
$stock_value = floatval(fetchOne("SELECT COALESCE(SUM(quantity * purchase_price), 0) as total FROM products")['total']);

// العائد على الاستثمار (ROI)
$roi = ($total_cost > 0) ? (($net_profit / $total_cost) * 100) : 0;

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            <?php echo t('nav_calculate_profits'); ?>
        </h1>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="add-sale.php" class="btn btn-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                <?php echo t('dashboard_register_sale'); ?>
            </a>
            <a href="../api/export.php?type=profits&format=csv&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
                📥 تصدير CSV
            </a>
            <a href="../api/export.php?type=profits&format=pdf&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;" target="_blank">
                📄 تصدير PDF
            </a>
        </div>
    </div>

    <!-- فلتر التاريخ -->
    <div class="report-filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label>من تاريخ</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-control">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        عرض
                    </button>
                    <a href="calculate-profits.php" class="btn btn-outline">مسح</a>
                </div>
            </div>
            <div style="margin-top:10px;">
                <div class="quick-periods">
                    <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="qp-btn <?php echo ($start_date == date('Y-m-01') && $end_date == date('Y-m-d')) ? 'active' : ''; ?>">هذا الشهر</a>
                    <a href="?start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="qp-btn <?php echo ($start_date == date('Y-01-01') && $end_date == date('Y-m-d')) ? 'active' : ''; ?>">هذا العام</a>
                    <a href="?start_date=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&end_date=<?php echo date('Y-m-t', strtotime('-1 month')); ?>" class="qp-btn">الشهر الماضي</a>
                    <a href="?start_date=<?php echo date('Y-m-d', strtotime('-3 months')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="qp-btn">آخر 3 أشهر</a>
                    <a href="?start_date=<?php echo date('Y-m-d', strtotime('-6 months')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="qp-btn">آخر 6 أشهر</a>
                    <a href="?start_date=<?php echo date('Y-m-d', strtotime('-1 year')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="qp-btn">آخر سنة</a>
                </div>
            </div>
        </form>
    </div>

    <!-- بطاقات ملخص الأرباح -->
    <div class="report-summary">
        <div class="summary-card">
            <div class="summary-icon" style="background:#eff6ff; color:#2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('dashboard_total_sales'); ?></h4>
                <div class="summary-value" style="color:#2563eb;"><?php echo formatMoney($total_sales); ?></div>
                <div class="summary-sub"><?php echo number_format($salesSummary['total_transactions']); ?> عملية</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef2f2; color:#dc2626;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
            </div>
            <div class="summary-info">
                <h4>تكلفة البضاعة (COGS)</h4>
                <div class="summary-value" style="color:#dc2626;"><?php echo formatMoney($total_cost); ?></div>
                <div class="summary-sub">هامش إجمالي: <?php echo number_format($gross_margin, 1); ?>%</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:#d1fae5; color:#059669;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="summary-info">
                <h4>الربح الإجمالي</h4>
                <div class="summary-value" style="color:#059669;"><?php echo formatMoney($gross_profit); ?></div>
                <div class="summary-sub">مبيعات - تكلفة البضاعة</div>
            </div>
        </div>
        <?php if ($expenses_table_exists && $total_expenses > 0): ?>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fef3c7; color:#d97706;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('expenses'); ?></h4>
                <div class="summary-value" style="color:#d97706;">- <?php echo formatMoney($total_expenses); ?></div>
                <div class="summary-sub"><a href="../expenses/list-expenses.php?date_from=<?php echo urlencode($start_date); ?>&date_to=<?php echo urlencode($end_date); ?>" style="color:var(--gray-500);">عرض التفاصيل ←</a></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($total_taxes > 0): ?>
        <div class="summary-card">
            <div class="summary-icon" style="background:#fce7f3; color:#be185d;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
            </div>
            <div class="summary-info">
                <h4>الضرائب</h4>
                <div class="summary-value" style="color:#be185d;">- <?php echo formatMoney($total_taxes); ?></div>
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
        <div class="summary-card">
            <div class="summary-icon" style="background:#eff6ff; color:#2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4"/></svg>
            </div>
            <div class="summary-info">
                <h4><?php echo t('dashboard_stock_value'); ?></h4>
                <div class="summary-value"><?php echo formatMoney($stock_value); ?></div>
                <div class="summary-sub">قيمة المخزون الحالي</div>
            </div>
        </div>
    </div>

    <!-- حساب صافي الربح التفصيلي (Waterfall) -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                ملخص حساب صافي الربح
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
                    <div class="value" style="color: #dc2626;">- <?php echo formatMoney($total_cost); ?></div>
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

            <?php if ($expenses_table_exists && !empty($expense_by_category)): ?>
            <div style="margin-top: 1.5rem;">
                <h3 style="font-size: 1rem; margin-bottom: 0.75rem; color: var(--gray-700);">تفصيل المصاريف في هذه الفترة:</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>التصنيف</th>
                                <th>عدد العمليات</th>
                                <th>المبلغ الإجمالي</th>
                                <th>النسبة من المصاريف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expense_by_category as $ec): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ec['category']); ?></strong></td>
                                <td><?php echo number_format($ec['count']); ?></td>
                                <td class="text-danger"><?php echo formatMoney($ec['total']); ?></td>
                                <td><?php echo $total_expenses > 0 ? round(($ec['total'] / $total_expenses) * 100, 1) . '%' : '0%'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:var(--gray-50); font-weight:700;">
                                <td>المجموع</td>
                                <td><?php echo array_sum(array_column($expense_by_category, 'count')); ?></td>
                                <td class="text-danger"><?php echo formatMoney($total_expenses); ?></td>
                                <td>100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- تفصيل الأرباح حسب المنتج -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>تفصيل الأرباح حسب المنتج (<?php echo number_format(count($product_profits)); ?> منتج)</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>طريقة الحساب (FIFO):</strong> يتم حساب تكلفة كل عملية بيع بناءً على سعر الشراء الأصلي لكل دفعة. عند البيع تُستهلك الدفعات بالترتيب (أول ما دخل أول ما خرج)، فإذا اشتريت 10 وحدات بسعر 100 ثم 20 وحدة بسعر 120 وبعت 15 وحدة، فالتكلفة = (10 × 100) + (5 × 120) = 1600 وليس 15 × 120 = 1800.
            </div>
            <?php if (empty($product_profits)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;">لا توجد مبيعات في الفترة المحددة</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>اسم المنتج</th>
                            <th>الوحدة</th>
                            <th>الكمية المباعة</th>
                            <th>إجمالي المبيعات</th>
                            <th>تكلفة الشراء</th>
                            <th>الربح</th>
                            <th>هامش الربح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($product_profits as $i => $pp):
                            $margin = $pp['total_sales_amount'] > 0 ? ($pp['total_profit'] / $pp['total_sales_amount']) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($pp['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($pp['name']); ?></td>
                            <td><?php echo htmlspecialchars($pp['unit']); ?></td>
                            <td style="font-weight:600;"><?php echo number_format($pp['total_sold'], 2); ?></td>
                            <td style="font-weight:700; color:#2563eb;"><?php echo formatMoney($pp['total_sales_amount']); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($pp['total_cost_amount']); ?></td>
                            <td class="<?php echo $pp['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?> text-bold" style="font-weight:700;">
                                <?php echo formatMoney($pp['total_profit']); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $margin >= 20 ? 'badge-success' : ($margin >= 10 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo number_format($margin, 1); ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:800;">
                            <td colspan="5">الإجمالي</td>
                            <td style="color:#2563eb;"><?php echo formatMoney($pp_total_sales); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($pp_total_cost); ?></td>
                            <td class="<?php echo $pp_total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatMoney($pp_total_profit); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $pp_gross_margin >= 20 ? 'badge-success' : ($pp_gross_margin >= 10 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo number_format($pp_gross_margin, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- حساب الأرباح من خلال المخزون المتبقي -->
    <div class="card">
        <div class="card-header">
            <h2>حساب الأرباح من خلال المخزون المتبقي</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>طريقة الحساب (FIFO):</strong> تكلفة البضاعة المباعة = مجموع (الكمية من كل دفعة × سعر شراء تلك الدفعة). قيمة المخزون المتبقي محسوبة من الدفعات المتوفرة بسعرها الأصلي.
            </div>

            <div class="profit-summary">
                <div class="profit-item">
                    <h4>إجمالي المشتريات</h4>
                    <div class="value" style="color:#7c3aed;"><?php echo formatMoney($total_purchases); ?></div>
                </div>
                <div class="profit-item">
                    <h4>إجمالي المبيعات</h4>
                    <div class="value" style="color:#2563eb; font-weight:700;"><?php echo formatMoney($total_sales); ?></div>
                </div>
                <div class="profit-item">
                    <h4>تكلفة البضاعة المباعة</h4>
                    <div class="value" style="color:#dc2626;"><?php echo formatMoney($total_cost); ?></div>
                </div>
                <div class="profit-item" style="background: <?php echo $gross_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 10px 14px; border-radius: 8px;">
                    <h4 style="font-weight:700;">الربح الإجمالي</h4>
                    <div class="value <?php echo $gross_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <?php echo formatMoney($gross_profit); ?>
                    </div>
                </div>
                <?php if ($expenses_table_exists && $total_expenses > 0): ?>
                <div class="profit-item">
                    <h4>المصاريف</h4>
                    <div class="value" style="color: #d97706;">- <?php echo formatMoney($total_expenses); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($total_taxes > 0): ?>
                <div class="profit-item">
                    <h4>ضرائب المشتريات</h4>
                    <div class="value" style="color: #be185d;">- <?php echo formatMoney($total_taxes); ?></div>
                </div>
                <?php endif; ?>
                <div class="profit-item" style="background: <?php echo $net_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 12px 14px; border-radius: 8px; border: 2px solid <?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <h4 style="font-weight:700;">صافي الربح</h4>
                    <div class="value <?php echo $net_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-weight:800;">
                        <?php echo formatMoney($net_profit); ?>
                    </div>
                </div>
                <div class="profit-item">
                    <h4>قيمة المخزون المتبقي</h4>
                    <div class="value"><?php echo formatMoney($stock_value); ?></div>
                </div>
            </div>

            <?php if (!empty($inventory_profit_data)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>المشتريات (كمية)</th>
                            <th>المبيعات (كمية)</th>
                            <th>المخزون المتبقي</th>
                            <th>إجمالي المشتريات</th>
                            <th>إجمالي المبيعات</th>
                            <th>تكلفة المبيعات</th>
                            <th>الربح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $row_num = 0; ?>
                        <?php foreach ($inventory_profit_data as $item):
                            if ($item['total_purchased'] == 0 && $item['total_sold'] == 0) continue;
                            $row_num++;
                        ?>
                        <tr>
                            <td><?php echo $row_num; ?></td>
                            <td><strong><?php echo htmlspecialchars($item['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td><?php echo number_format($item['total_purchased'], 2); ?></td>
                            <td style="font-weight:600;"><?php echo number_format($item['total_sold'], 2); ?></td>
                            <td>
                                <?php echo number_format($item['current_stock'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>
                            </td>
                            <td style="color:#7c3aed;"><?php echo formatMoney($item['total_purchase_amount']); ?></td>
                            <td style="color:#2563eb; font-weight:600;"><?php echo formatMoney($item['total_sales_amount']); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($item['total_cost_amount']); ?></td>
                            <td class="<?php echo $item['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?> text-bold">
                                <?php echo formatMoney($item['total_profit']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:800;">
                            <td colspan="3">المجموع</td>
                            <td><?php echo number_format($inv_total_purchased_qty, 2); ?></td>
                            <td><?php echo number_format($inv_total_sold_qty, 2); ?></td>
                            <td>—</td>
                            <td style="color:#7c3aed;"><?php echo formatMoney($inv_total_purchases); ?></td>
                            <td style="color:#2563eb;"><?php echo formatMoney($inv_total_sales); ?></td>
                            <td style="color:#dc2626;"><?php echo formatMoney($inv_total_cost); ?></td>
                            <td class="<?php echo $inv_total_profit >= 0 ? 'text-success' : 'text-danger'; ?> text-bold"><?php echo formatMoney($inv_total_profit); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
                <p class="text-muted" style="text-align:center; padding:2rem;">لا توجد بيانات في الفترة المحددة</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
