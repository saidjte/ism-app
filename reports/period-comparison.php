<?php
/**
 * نظام مقارنة الفترات المتقدم
 * مقارنة بين فترتين زمنيتين مع رسوم بيانية تفاعلية
 */
require_once '../includes/bootstrap.php';
$page_title = t('reports_period_comparison');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('reports.view');

// === معالجة الفترتين ===
$compare_mode = $_GET['mode'] ?? 'custom'; // custom, month, quarter, year

if ($compare_mode === 'month') {
    // مقارنة شهرين
    $p1_month = $_GET['p1_month'] ?? date('Y-m');
    $p2_month = $_GET['p2_month'] ?? date('Y-m', strtotime('-1 month'));
    $date_from_1 = $p1_month . '-01';
    $date_to_1 = date('Y-m-t', strtotime($date_from_1));
    $date_from_2 = $p2_month . '-01';
    $date_to_2 = date('Y-m-t', strtotime($date_from_2));
    $label_1 = date('F Y', strtotime($date_from_1));
    $label_2 = date('F Y', strtotime($date_from_2));
} elseif ($compare_mode === 'quarter') {
    // مقارنة ربع سنوي
    $p1_quarter = $_GET['p1_quarter'] ?? ceil(date('n') / 3);
    $p1_year = $_GET['p1_year'] ?? date('Y');
    $p2_quarter = $p1_quarter > 1 ? $p1_quarter - 1 : 4;
    $p2_year = $p1_quarter > 1 ? $p1_year : $p1_year - 1;
    $q1_start = ($p1_quarter - 1) * 3 + 1;
    $q1_end = $p1_quarter * 3;
    $q2_start = ($p2_quarter - 1) * 3 + 1;
    $q2_end = $p2_quarter * 3;
    $date_from_1 = sprintf('%04d-%02d-01', $p1_year, $q1_start);
    $date_to_1 = sprintf('%04d-%02d-t', $p1_year, $q1_end);
    $date_to_1 = date('Y-m-t', strtotime($date_to_1));
    $date_from_2 = sprintf('%04d-%02d-01', $p2_year, $q2_start);
    $date_to_2 = sprintf('%04d-%02d-t', $p2_year, $q2_end);
    $date_to_2 = date('Y-m-t', strtotime($date_to_2));
    $label_1 = "الربع {$p1_quarter} {$p1_year}";
    $label_2 = "الربع {$p2_quarter} {$p2_year}";
} elseif ($compare_mode === 'year') {
    // مقارنة سنتين
    $p1_year = intval($_GET['p1_year'] ?? date('Y'));
    $p2_year = $p1_year - 1;
    $date_from_1 = "{$p1_year}-01-01";
    $date_to_1 = "{$p1_year}-12-31";
    $date_from_2 = "{$p2_year}-01-01";
    $date_to_2 = "{$p2_year}-12-31";
    $label_1 = "سنة {$p1_year}";
    $label_2 = "سنة {$p2_year}";
} else {
    // فترات مخصصة
    $date_from_1 = $_GET['date_from_1'] ?? date('Y-m-01');
    $date_to_1 = $_GET['date_to_1'] ?? date('Y-m-d');
    $date_from_2 = $_GET['date_from_2'] ?? date('Y-m-01', strtotime('-1 month'));
    $date_to_2 = $_GET['date_to_2'] ?? date('Y-m-t', strtotime('-1 month'));
    $label_1 = formatDate($date_from_1) . ' — ' . formatDate($date_to_1);
    $label_2 = formatDate($date_from_2) . ' — ' . formatDate($date_to_2);
}

// التحقق من التواريخ
foreach ([$date_from_1, $date_to_1, $date_from_2, $date_to_2] as &$d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = date('Y-m-d');
}
unset($d);

// === دالة جلب إحصائيات فترة ===
function getPeriodStats($from, $to) {
    $stats = [];

    // مبيعات
    $s = fetchOne(
        "SELECT COALESCE(SUM(total),0) as total_sales, COALESCE(SUM(cost_total),0) as total_cost,
                COALESCE(SUM(profit),0) as total_profit, COUNT(*) as sales_count, COUNT(DISTINCT product_id) as products_sold
         FROM sales WHERE sale_date BETWEEN ? AND ?", [$from, $to]);
    $stats['sales_total'] = floatval($s['total_sales']);
    $stats['sales_cost'] = floatval($s['total_cost']);
    $stats['sales_profit'] = floatval($s['total_profit']);
    $stats['sales_count'] = intval($s['sales_count']);
    $stats['products_sold'] = intval($s['products_sold']);
    $stats['profit_margin'] = $stats['sales_total'] > 0 ? round(($stats['sales_profit'] / $stats['sales_total']) * 100, 1) : 0;

    // مشتريات
    $p = fetchOne(
        "SELECT COALESCE(SUM(total),0) as total_purchases, COUNT(*) as purchase_count, COUNT(DISTINCT product_id) as products_purchased
         FROM purchases WHERE purchase_date BETWEEN ? AND ?", [$from, $to]);
    $stats['purchases_total'] = floatval($p['total_purchases']);
    $stats['purchase_count'] = intval($p['purchase_count']);
    $stats['products_purchased'] = intval($p['products_purchased']);

    // مصاريف
    $e = fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?", [$from, $to]);
    $stats['expenses_total'] = floatval($e['total']);

    // ضرائب المشتريات
    $t = fetchOne("SELECT COALESCE(SUM(tax_amount),0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?", [$from, $to]);
    $stats['taxes_total'] = floatval($t['total']);

    // صافي الربح = ربح إجمالي - مصاريف - ضرائب (مُوحَّد مع SaleService و profit-report)
    $stats['net_profit'] = $stats['sales_profit'] - $stats['expenses_total'] - $stats['taxes_total'];

    // متوسط قيمة العملية
    $stats['avg_sale'] = $stats['sales_count'] > 0 ? $stats['sales_total'] / $stats['sales_count'] : 0;
    $stats['avg_purchase'] = $stats['purchase_count'] > 0 ? $stats['purchases_total'] / $stats['purchase_count'] : 0;

    return $stats;
}

// === جلب إحصائيات الفترتين ===
$stats1 = getPeriodStats($date_from_1, $date_to_1);
$stats2 = getPeriodStats($date_from_2, $date_to_2);

// === دالة حساب نسبة التغيير ===
function getChange($current, $previous) {
    if ($previous == 0 && $current == 0) return ['pct' => 0, 'dir' => 'neutral'];
    if ($previous == 0) return ['pct' => 100, 'dir' => 'up'];
    $pct = round((($current - $previous) / $previous) * 100, 1);
    if ($pct > 0) return ['pct' => $pct, 'dir' => 'up'];
    if ($pct < 0) return ['pct' => abs($pct), 'dir' => 'down'];
    return ['pct' => 0, 'dir' => 'neutral'];
}

function renderChangeBadge($change) {
    if ($change['dir'] === 'up') return '<span class="change-badge up">↑ +' . $change['pct'] . '%</span>';
    if ($change['dir'] === 'down') return '<span class="change-badge down">↓ -' . $change['pct'] . '%</span>';
    return '<span class="change-badge neutral">→ 0%</span>';
}

// === المبيعات حسب التصنيف لكل فترة ===
function getCategorySales($from, $to) {
    return fetchAll(
        "SELECT COALESCE(c.name,'بدون تصنيف') as name, COALESCE(c.color,'#64748b') as color,
                SUM(s.total) as total, SUM(s.profit) as profit
         FROM sales s JOIN products p ON p.id = s.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE s.sale_date BETWEEN ? AND ?
         GROUP BY COALESCE(c.id,0) ORDER BY total DESC LIMIT 10",
        [$from, $to]
    );
}

$cat1 = getCategorySales($date_from_1, $date_to_1);
$cat2 = getCategorySales($date_from_2, $date_to_2);

// === أفضل 10 منتجات لكل فترة ===
function getTopProducts($from, $to) {
    return fetchAll(
        "SELECT p.code, p.name, p.category_id, COALESCE(c.color,'#64748b') as color,
                SUM(s.quantity) as qty, SUM(s.total) as sales_total, SUM(s.profit) as profit
         FROM sales s JOIN products p ON p.id = s.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE s.sale_date BETWEEN ? AND ?
         GROUP BY s.product_id ORDER BY sales_total DESC LIMIT 10",
        [$from, $to]
    );
}

$top1 = getTopProducts($date_from_1, $date_to_1);
$top2 = getTopProducts($date_from_2, $date_to_2);

// === المبيعات اليومية لكل فترة (للمقارنة) ===
function getDailySales($from, $to) {
    return fetchAll(
        "SELECT DATE(sale_date) as day, SUM(total) as total, SUM(profit) as profit
         FROM sales WHERE sale_date BETWEEN ? AND ?
         GROUP BY DATE(sale_date) ORDER BY day ASC",
        [$from, $to]
    );
}

$daily1 = getDailySales($date_from_1, $date_to_1);
$daily2 = getDailySales($date_from_2, $date_to_2);

$load_chartjs = true;
require_once '../includes/header.php';

// === عناصر المقارنة ===
$metrics = [
    ['label' => 'إجمالي المبيعات', 'k1' => 'sales_total', 'k2' => 'sales_total', 'color' => '#2563eb'],
    ['label' => 'تكلفة المبيعات', 'k1' => 'sales_cost', 'k2' => 'sales_cost', 'color' => '#dc2626'],
    ['label' => 'ربح المبيعات', 'k1' => 'sales_profit', 'k2' => 'sales_profit', 'color' => '#059669'],
    ['label' => 'هامش الربح', 'k1' => 'profit_margin', 'k2' => 'profit_margin', 'color' => '#059669', 'suffix' => '%'],
    ['label' => 'عدد عمليات البيع', 'k1' => 'sales_count', 'k2' => 'sales_count', 'color' => '#0891b2'],
    ['label' => 'متوسط قيمة البيع', 'k1' => 'avg_sale', 'k2' => 'avg_sale', 'color' => '#7c3aed'],
    ['label' => 'إجمالي المشتريات', 'k1' => 'purchases_total', 'k2' => 'purchases_total', 'color' => '#dc2626'],
    ['label' => 'عدد عمليات الشراء', 'k1' => 'purchase_count', 'k2' => 'purchase_count', 'color' => '#f59e0b'],
    ['label' => 'إجمالي المصاريف', 'k1' => 'expenses_total', 'k2' => 'expenses_total', 'color' => '#be123c'],
    ['label' => 'ضرائب المشتريات', 'k1' => 'taxes_total', 'k2' => 'taxes_total', 'color' => '#7c3aed'],
    ['label' => 'صافي الربح', 'k1' => 'net_profit', 'k2' => 'net_profit', 'color' => '#059669'],
    ['label' => 'المنتجات المباعة', 'k1' => 'products_sold', 'k2' => 'products_sold', 'color' => '#64748b'],
    ['label' => 'المنتجات المشتراة', 'k1' => 'products_purchased', 'k2' => 'products_purchased', 'color' => '#64748b'],
];
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
            </svg>
            <?php echo t('reports_period_comparison'); ?>
        </h1>
    </div>

    <!-- اختيار نوع المقارنة -->
    <div class="report-filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label>نوع المقارنة</label>
                    <select name="mode" class="form-control" onchange="this.form.submit()">
                        <option value="custom" <?php echo $compare_mode == 'custom' ? 'selected' : ''; ?>>فترات مخصصة</option>
                        <option value="month" <?php echo $compare_mode == 'month' ? 'selected' : ''; ?>>مقارنة شهرين</option>
                        <option value="quarter" <?php echo $compare_mode == 'quarter' ? 'selected' : ''; ?>>مقارنة ربعين سنويين</option>
                        <option value="year" <?php echo $compare_mode == 'year' ? 'selected' : ''; ?>>مقارنة سنتين</option>
                    </select>
                </div>
            </div>

            <?php if ($compare_mode === 'custom'): ?>
            <div style="display:grid; grid-template-columns:1fr auto 1fr; gap:12px; margin-top:12px; align-items:end;">
                <div style="border:2px solid #3b82f6; border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:#2563eb; font-size:0.85rem;">🔵 الفترة الأولى (الحالية)</h4>
                    <div class="filter-group"><label>من</label><input type="date" name="date_from_1" value="<?php echo $date_from_1; ?>" class="form-control"></div>
                    <div class="filter-group" style="margin-top:8px;"><label>إلى</label><input type="date" name="date_to_1" value="<?php echo $date_to_1; ?>" class="form-control"></div>
                </div>
                <div style="font-size:1.5rem; font-weight:900; color:var(--gray-300); padding-bottom:20px;">VS</div>
                <div style="border:2px solid var(--gray-300); border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:var(--gray-500); font-size:0.85rem;">⚪ الفترة الثانية (سابقة)</h4>
                    <div class="filter-group"><label>من</label><input type="date" name="date_from_2" value="<?php echo $date_from_2; ?>" class="form-control"></div>
                    <div class="filter-group" style="margin-top:8px;"><label>إلى</label><input type="date" name="date_to_2" value="<?php echo $date_to_2; ?>" class="form-control"></div>
                </div>
            </div>
            <?php elseif ($compare_mode === 'month'): ?>
            <div style="display:grid; grid-template-columns:1fr auto 1fr; gap:12px; margin-top:12px; align-items:end;">
                <div style="border:2px solid #3b82f6; border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:#2563eb; font-size:0.85rem;">🔵 الشهر الأول</h4>
                    <div class="filter-group"><label>الشهر</label><input type="month" name="p1_month" value="<?php echo $p1_month; ?>" class="form-control"></div>
                </div>
                <div style="font-size:1.5rem; font-weight:900; color:var(--gray-300); padding-bottom:20px;">VS</div>
                <div style="border:2px solid var(--gray-300); border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:var(--gray-500); font-size:0.85rem;">⚪ الشهر الثاني</h4>
                    <div class="filter-group"><label>الشهر</label><input type="month" name="p2_month" value="<?php echo $p2_month; ?>" class="form-control"></div>
                </div>
            </div>
            <?php elseif ($compare_mode === 'quarter'): ?>
            <div style="display:grid; grid-template-columns:1fr auto 1fr; gap:12px; margin-top:12px; align-items:end;">
                <div style="border:2px solid #3b82f6; border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:#2563eb; font-size:0.85rem;">🔵 الربع الأول</h4>
                    <div style="display:flex; gap:8px;">
                        <div class="filter-group"><label>السنة</label><input type="number" name="p1_year" value="<?php echo $p1_year; ?>" min="2020" max="2030" class="form-control"></div>
                        <div class="filter-group"><label>الربع</label><select name="p1_quarter" class="form-control">
                            <option value="1" <?php echo $p1_quarter==1?'selected':''; ?>>الربع 1</option>
                            <option value="2" <?php echo $p1_quarter==2?'selected':''; ?>>الربع 2</option>
                            <option value="3" <?php echo $p1_quarter==3?'selected':''; ?>>الربع 3</option>
                            <option value="4" <?php echo $p1_quarter==4?'selected':''; ?>>الربع 4</option>
                        </select></div>
                    </div>
                </div>
                <div style="font-size:1.5rem; font-weight:900; color:var(--gray-300); padding-bottom:20px;">VS</div>
                <div style="border:2px solid var(--gray-300); border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:var(--gray-500); font-size:0.85rem;">⚪ الربع السابق (تلقائي)</h4>
                    <div style="padding:12px; background:var(--gray-50); border-radius:8px; text-align:center; color:var(--gray-500); font-size:0.85rem;">
                        الربع <?php echo $p2_quarter; ?> — <?php echo $p2_year; ?>
                    </div>
                </div>
            </div>
            <?php elseif ($compare_mode === 'year'): ?>
            <div style="display:grid; grid-template-columns:1fr auto 1fr; gap:12px; margin-top:12px; align-items:end;">
                <div style="border:2px solid #3b82f6; border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:#2563eb; font-size:0.85rem;">🔵 السنة الأولى</h4>
                    <div class="filter-group"><label>السنة</label><input type="number" name="p1_year" value="<?php echo $p1_year; ?>" min="2020" max="2030" class="form-control"></div>
                </div>
                <div style="font-size:1.5rem; font-weight:900; color:var(--gray-300); padding-bottom:20px;">VS</div>
                <div style="border:2px solid var(--gray-300); border-radius:var(--radius-lg); padding:16px;">
                    <h4 style="margin:0 0 12px; color:var(--gray-500); font-size:0.85rem;">⚪ السنة السابقة (تلقائي)</h4>
                    <div style="padding:12px; background:var(--gray-50); border-radius:8px; text-align:center; color:var(--gray-500); font-size:0.85rem;">
                        <?php echo $p2_year; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div style="margin-top:16px; text-align:center;">
                <button type="submit" class="btn btn-primary" style="padding:10px 40px; font-size:1rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    مقارنة الآن
                </button>
            </div>
        </form>
    </div>

    <!-- بطاقات ملخص المقارنة -->
    <div class="report-summary">
        <?php foreach ($metrics as $m):
            $change = getChange($stats1[$m['k1']], $stats2[$m['k2']]);
            $suffix = $m['suffix'] ?? '';
            $is_money = !in_array($m['k1'], ['sales_count', 'purchase_count', 'products_sold', 'products_purchased', 'profit_margin']);
        ?>
        <div class="summary-card">
            <div class="summary-icon" style="background:<?php echo $m['color']; ?>15; color:<?php echo $m['color']; ?>;">
                <?php if ($m['k1'] === 'net_profit'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                <?php elseif ($m['k1'] === 'sales_total'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2z"/></svg>
                <?php endif; ?>
            </div>
            <div class="summary-info">
                <h4><?php echo $m['label']; ?></h4>
                <div class="summary-value"><?php echo $is_money ? formatMoney($stats1[$m['k1']]) : number_format($stats1[$m['k1']]) . $suffix; ?></div>
                <div style="margin-top:4px;"><?php echo renderChangeBadge($change); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- جدول المقارنة التفصيلي -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <h2>جدول المقارنة التفصيلي</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th style="width:30%;">المؤشر</th>
                            <th style="width:20%; text-align:center;">
                                <span style="color:#2563eb;">🔵 <?php echo htmlspecialchars($label_1); ?></span>
                            </th>
                            <th style="width:20%; text-align:center;">
                                <span style="color:var(--gray-500);">⚪ <?php echo htmlspecialchars($label_2); ?></span>
                            </th>
                            <th style="width:15%; text-align:center;">الفارق</th>
                            <th style="width:15%; text-align:center;">التغيير</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metrics as $m):
                            $change = getChange($stats1[$m['k1']], $stats2[$m['k2']]);
                            $suffix = $m['suffix'] ?? '';
                            $is_money = !in_array($m['k1'], ['sales_count', 'purchase_count', 'products_sold', 'products_purchased', 'profit_margin']);
                            $v1 = $is_money ? formatMoney($stats1[$m['k1']]) : number_format($stats1[$m['k1']]) . $suffix;
                            $v2 = $is_money ? formatMoney($stats2[$m['k2']]) : number_format($stats2[$m['k2']]) . $suffix;
                            $diff = $stats1[$m['k1']] - $stats2[$m['k2']];
                            $diff_display = $is_money ? formatMoney($diff) : number_format($diff) . $suffix;
                            $change_color = $change['dir'] === 'up' ? '#059669' : ($change['dir'] === 'down' ? '#dc2626' : 'var(--gray-400)');
                        ?>
                        <tr>
                            <td class="metric-name"><?php echo $m['label']; ?></td>
                            <td class="current-val"><?php echo $v1; ?></td>
                            <td class="previous-val"><?php echo $v2; ?></td>
                            <td style="text-align:center; font-weight:600; color:<?php echo $change_color; ?>;"><?php echo $diff_display; ?></td>
                            <td class="change-val" style="color:<?php echo $change_color; ?>;"><?php echo renderChangeBadge($change); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- الرسوم البيانية -->
    <div class="charts-grid">
        <!-- مقارنة المبيعات اليومية -->
        <div class="chart-card" style="grid-column: span 2;">
            <div class="chart-card-header">
                <h3>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    مقارنة المبيعات اليومية
                </h3>
                <div style="display:flex; gap:16px; font-size:0.78rem;">
                    <span style="display:flex; align-items:center; gap:4px;"><span style="width:12px; height:4px; background:#2563eb; border-radius:2px;"></span> <?php echo htmlspecialchars($label_1); ?></span>
                    <span style="display:flex; align-items:center; gap:4px;"><span style="width:12px; height:4px; background:#94a3b8; border-radius:2px;"></span> <?php echo htmlspecialchars($label_2); ?></span>
                </div>
            </div>
            <div class="chart-card-body">
                <div class="chart-container" style="height: 280px;">
                    <canvas id="dailyComparisonChart"></canvas>
                </div>
            </div>
        </div>

        <!-- مقارنة حسب التصنيف (رادار) -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>مقارنة التصنيفات</h3>
            </div>
            <div class="chart-card-body pie-body">
                <div style="width:100%; max-width:350px;">
                    <canvas id="categoryRadarChart"></canvas>
                </div>
            </div>
        </div>

        <!-- مقارنة المؤشرات الرئيسية (رادار) -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>مقارنة المؤشرات</h3>
            </div>
            <div class="chart-card-body pie-body">
                <div style="width:100%; max-width:350px;">
                    <canvas id="metricsRadarChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- مقارنة أفضل المنتجات -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 style="color:#2563eb;">🔵 أفضل 10 منتجات — <?php echo htmlspecialchars($label_1); ?></h3>
            </div>
            <div class="chart-card-body">
                <?php if (empty($top1)): ?>
                    <p class="text-muted" style="text-align:center; padding:1rem;"><?php echo t('reports_no_data'); ?></p>
                <?php else: ?>
                <ul class="top-products-list">
                    <?php foreach ($top1 as $i => $tp):
                        $rank_class = $i == 0 ? 'gold' : ($i == 1 ? 'silver' : ($i == 2 ? 'bronze' : 'default'));
                        $max_sales = $top1[0]['sales_total'] > 0 ? $top1[0]['sales_total'] : 1;
                    ?>
                    <li>
                        <div class="top-rank <?php echo $rank_class; ?>"><?php echo $i + 1; ?></div>
                        <div class="top-product-info">
                            <div class="top-product-name"><?php echo htmlspecialchars($tp['name']); ?></div>
                            <div class="top-product-bar">
                                <div class="top-product-bar-fill" style="width:<?php echo round(($tp['sales_total'] / $max_sales) * 100); ?>%; background:<?php echo $tp['color']; ?>"></div>
                            </div>
                        </div>
                        <div class="top-product-value"><?php echo formatMoney($tp['sales_total']); ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3 style="color:var(--gray-500);">⚪ أفضل 10 منتجات — <?php echo htmlspecialchars($label_2); ?></h3>
            </div>
            <div class="chart-card-body">
                <?php if (empty($top2)): ?>
                    <p class="text-muted" style="text-align:center; padding:1rem;"><?php echo t('reports_no_data'); ?></p>
                <?php else: ?>
                <ul class="top-products-list">
                    <?php foreach ($top2 as $i => $tp):
                        $rank_class = $i == 0 ? 'gold' : ($i == 1 ? 'silver' : ($i == 2 ? 'bronze' : 'default'));
                        $max_sales = $top2[0]['sales_total'] > 0 ? $top2[0]['sales_total'] : 1;
                    ?>
                    <li>
                        <div class="top-rank <?php echo $rank_class; ?>"><?php echo $i + 1; ?></div>
                        <div class="top-product-info">
                            <div class="top-product-name"><?php echo htmlspecialchars($tp['name']); ?></div>
                            <div class="top-product-bar">
                                <div class="top-product-bar-fill" style="width:<?php echo round(($tp['sales_total'] / $max_sales) * 100); ?>%; background:<?php echo $tp['color']; ?>"></div>
                            </div>
                        </div>
                        <div class="top-product-value"><?php echo formatMoney($tp['sales_total']); ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
(function() {
    Chart.defaults.font.family = 'Tajawal, Segoe UI, sans-serif';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;
    const C = window.CURRENCY || '';

    const label1 = <?php echo json_encode($label_1); ?>;
    const label2 = <?php echo json_encode($label_2); ?>;

    // === 1. مقارنة المبيعات اليومية ===
    <?php if (!empty($daily1) || !empty($daily2)): ?>
    // دمج كل التواريخ
    const allDays1 = <?php echo json_encode(array_column($daily1, 'day')); ?>;
    const allDays2 = <?php echo json_encode(array_column($daily2, 'day')); ?>;
    const vals1 = <?php echo json_encode(array_column($daily1, 'total')); ?>;
    const vals2 = <?php echo json_encode(array_column($daily2, 'total')); ?>;

    const dayMap1 = {};
    allDays1.forEach((d, i) => dayMap1[d] = vals1[i]);
    const dayMap2 = {};
    allDays2.forEach((d, i) => dayMap2[d] = vals2[i]);

    const allDays = [...new Set([...allDays1, ...allDays2])].sort();
    const data1 = allDays.map(d => dayMap1[d] || 0);
    const data2 = allDays.map(d => dayMap2[d] || 0);

    new Chart(document.getElementById('dailyComparisonChart'), {
        type: 'line',
        data: {
            labels: allDays.map(d => d.substring(5)), // MM-DD
            datasets: [
                {
                    label: label1,
                    data: data1,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.1)',
                    fill: true, tension: 0.4, pointRadius: 2, borderWidth: 2.5
                },
                {
                    label: label2,
                    data: data2,
                    borderColor: '#94a3b8',
                    backgroundColor: 'rgba(148,163,184,0.08)',
                    fill: true, tension: 0.4, pointRadius: 2, borderWidth: 2.5, borderDash: [5,3]
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: { tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') + ' ' + C } } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('ar-MA') } } }
        }
    });
    <?php endif; ?>

    // === 2. رادار التصنيفات ===
    <?php
    $all_cats = [];
    $cat1_map = []; foreach ($cat1 as $c) $cat1_map[$c['name']] = floatval($c['total']);
    $cat2_map = []; foreach ($cat2 as $c) $cat2_map[$c['name']] = floatval($c['total']);
    $all_cats = array_unique(array_merge(array_keys($cat1_map), array_keys($cat2_map)));
    ?>
    <?php if (!empty($all_cats)): ?>
    new Chart(document.getElementById('categoryRadarChart'), {
        type: 'radar',
        data: {
            labels: <?php echo json_encode(array_values($all_cats)); ?>,
            datasets: [
                {
                    label: label1,
                    data: <?php echo json_encode(array_map(function($c) use ($cat1_map) { return $cat1_map[$c] ?? 0; }, $all_cats)); ?>,
                    borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.15)', pointBackgroundColor: '#2563eb', borderWidth: 2
                },
                {
                    label: label2,
                    data: <?php echo json_encode(array_map(function($c) use ($cat2_map) { return $cat2_map[$c] ?? 0; }, $all_cats)); ?>,
                    borderColor: '#94a3b8', backgroundColor: 'rgba(148,163,184,0.1)', pointBackgroundColor: '#94a3b8', borderWidth: 2, borderDash: [4,3]
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.r).toLocaleString('ar-MA') + ' ' + C } } },
            scales: { r: { beginAtZero: true, ticks: { callback: v => v >= 1000 ? (v/1000).toFixed(0) + 'K' : v } } }
        }
    });
    <?php endif; ?>

    // === 3. رادار المؤشرات ===
    <?php
    $radar_labels = [];
    $radar_v1 = [];
    $radar_v2 = [];
    // نأخذ 6 مؤشرات رئيسية مع تسوية القيم
    $radar_metrics = [
        ['label' => 'المبيعات', 'v1' => $stats1['sales_total'], 'v2' => $stats2['sales_total']],
        ['label' => 'المشتريات', 'v1' => $stats1['purchases_total'], 'v2' => $stats2['purchases_total']],
        ['label' => 'الربح', 'v1' => $stats1['sales_profit'], 'v2' => $stats2['sales_profit']],
        ['label' => 'المصاريف', 'v1' => $stats1['expenses_total'], 'v2' => $stats2['expenses_total']],
        ['label' => 'عمليات البيع', 'v1' => $stats1['sales_count'], 'v2' => $stats2['sales_count']],
        ['label' => 'عمليات الشراء', 'v1' => $stats1['purchase_count'], 'v2' => $stats2['purchase_count']],
    ];
    $max_vals = [];
    foreach ($radar_metrics as $rm) {
        $radar_labels[] = $rm['label'];
        $max_vals[] = max(abs($rm['v1']), abs($rm['v2']), 1);
    }
    foreach ($radar_metrics as $i => $rm) {
        $radar_v1[] = round(($rm['v1'] / $max_vals[$i]) * 100);
        $radar_v2[] = round(($rm['v2'] / $max_vals[$i]) * 100);
    }
    ?>
    new Chart(document.getElementById('metricsRadarChart'), {
        type: 'radar',
        data: {
            labels: <?php echo json_encode($radar_labels); ?>,
            datasets: [
                {
                    label: label1,
                    data: <?php echo json_encode($radar_v1); ?>,
                    borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.15)', pointBackgroundColor: '#059669', borderWidth: 2.5
                },
                {
                    label: label2,
                    data: <?php echo json_encode($radar_v2); ?>,
                    borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.1)', pointBackgroundColor: '#f59e0b', borderWidth: 2.5, borderDash: [4,3]
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { r: { beginAtZero: true, max: 100, ticks: { display: false }, pointLabels: { font: { size: 11 } } } }
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
