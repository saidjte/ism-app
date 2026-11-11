<?php
require_once 'includes/bootstrap.php';
$page_title = t('dashboard_home_title');
require_once 'config/upgrades.php'; // APP_VERSION, getCurrentDBVersion, compareVersions
requireLogin();

// التحقق من النسخ التلقائي (للمدير فقط)
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    require_once 'config/backup.php';
    checkAutoBackup();
}

$load_chartjs = true;

// جلب رقم إصدار قاعدة البيانات الحالي
try {
    $pdo_conn = getDBConnection();
    $db_version = getCurrentDBVersion($pdo_conn);
    $needs_upgrade = $pdo_conn ? (compareVersions($db_version, APP_VERSION) < 0) : false;
} catch (\Throwable $e) {
    $db_version = '0.0.0';
    $needs_upgrade = false;
}

// === فلاتر التاريخ ===
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// === جلب إحصائيات لوحة التحكم من DashboardData ===
require_once __DIR__ . '/includes/DashboardData.php';
$stats = DashboardData::getStats($date_from, $date_to);

// استخراج المتغيرات من مصفوفة الإحصائيات
$total_products = $stats['total_products'];
$total_stock_value = $stats['total_stock_value'];
$total_stock_value_retail = $stats['total_stock_value_retail'];
$total_purchases = $stats['total_purchases'];
$total_sales = $stats['total_sales'];
$low_stock_count = $stats['low_stock_count'];
$gross_profit = $stats['gross_profit'];
$net_profit = $stats['net_profit'];
$total_expenses = $stats['total_expenses'];
$expenses_table_exists = $stats['expenses_table_exists'];
$prev_sales = $stats['prev_sales'];
$prev_purchases = $stats['prev_purchases'];
$prev_net_profit = $stats['prev_net_profit'];
$prev_expenses = $stats['prev_expenses'];
$monthly_stats = $stats['monthly_stats'];
$recent_products = $stats['recent_products'];
$recent_purchases = $stats['recent_purchases'];
$recent_sales = $stats['recent_sales'];
$recent_expenses = $stats['recent_expenses'];

// منتجات منتهية الصلاحية
$expired_products_count = 0;
$expired_products_list = [];
$expiring_soon_count = 0;
$expiring_soon_list = [];
$expired_financial_loss = 0;
$expiry_alert_enabled = true;
$expiry_alert_days_setting = 30;
$expiry_tracked_count = 0; // عدد المنتجات المفعّل عليها تتبع الصلاحية
$expiry_batches_count = 0; // عدد الدفعات التي لها تاريخ صلاحية
try {
    $expiry_alert_enabled = (getSetting('expiry_alert_enabled', '1') === '1');
    $expiry_alert_days_setting = intval(getSetting('expiry_alert_days', '30'));
    if ($expiry_alert_days_setting < 1) $expiry_alert_days_setting = 30;
} catch (Exception $e) {}

// إحصائيات تتبع الصلاحية (❌ بدون كاش — بيانات حقيقية دائماً)
try {
    $expiry_tracked_count = intval(fetchOne("SELECT COUNT(*) as c FROM products WHERE has_expiry = 1")['c'] ?? 0);
    $expiry_batches_count = intval(fetchOne("SELECT COUNT(*) as c FROM purchase_batches WHERE expiry_date IS NOT NULL AND remaining_quantity > 0")['c'] ?? 0);
} catch (Exception $e) {}

if ($expiry_alert_enabled) {
    $today = date('Y-m-d');
    $expiry_days = date('Y-m-d', strtotime('+' . $expiry_alert_days_setting . ' days'));

    // ❌ بدون كاش — بيانات حقيقية دائماً
    // منتجات منتهية الصلاحية - بحد أقصى 50 منتج
    try {
    $expired_products = fetchAll(
        "SELECT p.id, p.code, p.name, p.unit, p.quantity,
                COALESCE(SUM(CASE WHEN pb.expiry_date < ? AND pb.remaining_quantity > 0 THEN pb.remaining_quantity ELSE 0 END), 0) as expired_qty,
                COALESCE(SUM(CASE WHEN pb.expiry_date < ? AND pb.remaining_quantity > 0 THEN pb.remaining_quantity * pb.purchase_price ELSE 0 END), 0) as expired_value
         FROM products p
         LEFT JOIN purchase_batches pb ON pb.product_id = p.id
         WHERE p.has_expiry = 1 AND p.quantity > 0
         GROUP BY p.id
         HAVING expired_qty > 0
         ORDER BY expired_value DESC
         LIMIT 50", [$today, $today]
    );
    $expired_products_count = count($expired_products);
    $expired_products_list = $expired_products;
    $expired_financial_loss = array_sum(array_column($expired_products, 'expired_value'));
} catch (Exception $e) {}

    // منتجات قاربت على الانتهاء
    try {
    $expiring_products = fetchAll(
        "SELECT p.id, p.code, p.name, p.unit, p.quantity,
                COALESCE(SUM(CASE WHEN pb.expiry_date >= ? AND pb.expiry_date <= ? AND pb.remaining_quantity > 0 THEN pb.remaining_quantity ELSE 0 END), 0) as expiring_qty,
                COALESCE(SUM(CASE WHEN pb.expiry_date >= ? AND pb.expiry_date <= ? AND pb.remaining_quantity > 0 THEN pb.remaining_quantity * pb.purchase_price ELSE 0 END), 0) as expiring_value,
                MIN(CASE WHEN pb.expiry_date >= ? AND pb.expiry_date <= ? AND pb.remaining_quantity > 0 THEN pb.expiry_date END) as nearest_expiry
         FROM products p
         JOIN purchase_batches pb ON pb.product_id = p.id
         WHERE p.has_expiry = 1 AND p.quantity > 0
         GROUP BY p.id
         HAVING expiring_qty > 0
         ORDER BY nearest_expiry ASC, expiring_qty DESC
         LIMIT 50", [$today, $expiry_days, $today, $expiry_days, $today, $expiry_days]
    );
    $expiring_soon_count = count($expiring_products);
    $expiring_soon_list = $expiring_products;
} catch (Exception $e) {}

} // end if expiry_alert_enabled

// === تحديد ما إذا كان يجب إظهار نافذة التنبيه المرئي ===
$show_expiry_popup = false;
$expiry_popup_signature = '';
if ($expiry_alert_enabled && ($expired_products_count > 0 || $expiring_soon_count > 0)) {
    $expiry_popup_signature = md5($expired_products_count . ':' . $expiring_soon_count . ':' . round($expired_financial_loss));
    $dismissed_at = intval($_SESSION['expiry_popup_dismissed_at'] ?? 0);
    $old_signature = $_SESSION['expiry_popup_signature'] ?? '';

    // إظهار النافذة إذا:
    // 1. لم يتم إخفاؤها أبداً
    // 2. مرّ أكثر من ساعتين منذ آخر إخفاء
    // 3. تغيّرت بيانات التنبيه (منتجات جديدة منتهية/قاربت)
    if ($dismissed_at === 0 || (time() - $dismissed_at) >= 7200 || $old_signature !== $expiry_popup_signature) {
        $show_expiry_popup = true;
    }
}

// بيانات أفضل المنتجات مبيعاً للرسم البياني (❌ بدون كاش)
$top_selling_products = [];
try {
    $top_selling_products = fetchAll(
        "SELECT p.name, COALESCE(c.color, '#64748b') as color, SUM(s.total) as total_sales
         FROM sales s JOIN products p ON p.id = s.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE s.sale_date BETWEEN ? AND ?
         GROUP BY s.product_id ORDER BY total_sales DESC LIMIT 8",
        [$date_from, $date_to]
    );
} catch (Exception $e) {}

// تنسيق الفترة للعرض
$period_label_from = formatDate($date_from);
$period_label_to = formatDate($date_to);

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once 'includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <?php echo t('dashboard_title'); ?>
            <span class="dashboard-version-badge">v<?php echo $db_version; ?></span>
        </h1>
        <div class="btn-group">
            <?php if ($needs_upgrade): ?>
            <a href="upgrade.php" class="btn btn-danger dashboard-upgrade-btn">
                🔄 <?php echo t('dashboard_upgrade_available'); ?> (v<?php echo APP_VERSION; ?>)
            </a>
            <?php endif; ?>
            <a href="products/add-product.php" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                <?php echo t('dashboard_add_product'); ?>
            </a>
            <a href="purchases/add-purchase.php" class="btn btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <?php echo t('dashboard_register_purchase'); ?>
            </a>
            <a href="sales/add-sale.php" class="btn btn-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?php echo t('dashboard_register_sale'); ?>
            </a>
            <?php if ($expenses_table_exists): ?>
            <a href="expenses/add-expense.php" class="btn btn-danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <?php echo t('dashboard_register_expense'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- فلاتر التاريخ -->
    <div class="card dashboard-date-filter">
        <div class="card-body">
            <form method="GET" class="dashboard-filter-form">
                <span class="dashboard-filter-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php echo t('dashboard_stats_period'); ?>
                </span>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="form-control dashboard-date-input">
                <span class="dashboard-date-separator">←</span>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="form-control dashboard-date-input">
                <button type="submit" class="btn btn-primary dashboard-filter-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                    <?php echo t('update'); ?>
                </button>
                <div class="dashboard-filter-quick-btns">
                    <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline dashboard-filter-btn <?php echo ($date_from == date('Y-m-01') && $date_to == date('Y-m-d')) ? 'dashboard-filter-btn--active' : ''; ?>">
                        <?php echo t('this_month'); ?>
                    </a>
                    <a href="?date_from=<?php echo date('Y-01-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-outline dashboard-filter-btn <?php echo ($date_from == date('Y-01-01') && $date_to == date('Y-m-d')) ? 'dashboard-filter-btn--active' : ''; ?>">
                        <?php echo t('this_year'); ?>
                    </a>
                    <a href="?date_from=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('-1 month')); ?>" class="btn btn-outline dashboard-filter-btn">
                        <?php echo t('last_month'); ?>
                    </a>
                </div>
                <span class="dashboard-period-label">
                    <?php echo t('from'); ?> <?php echo $period_label_from; ?> <?php echo t('to'); ?> <?php echo $period_label_to; ?>
                </span>
            </form>
        </div>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_product_count'); ?></h3>
                <div class="stat-value"><?php echo number_format($total_products); ?></div>
            </div>
        </div>

        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_stock_value'); ?></h3>
                <div class="stat-value" style="font-size:1.15rem;">
                    <span style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <span style="display:flex; flex-direction:column; align-items:center;">
                            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t('stock_value_at_cost'); ?></span>
                            <span style="font-size:1.1rem;"><?php echo formatMoney($total_stock_value); ?></span>
                        </span>
                        <span style="display:flex; flex-direction:column; align-items:center;">
                            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t('stock_value_at_retail'); ?></span>
                            <span style="font-size:1.1rem; color:#059669;"><?php echo formatMoney($total_stock_value_retail); ?></span>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <div class="stat-card cyan">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_total_purchases'); ?></h3>
                <div class="stat-value"><?php echo formatMoney($total_purchases); ?></div>
                <?php echo DashboardData::renderChangeBadge($total_purchases, $prev_purchases); ?>
            </div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_total_sales'); ?></h3>
                <div class="stat-value"><?php echo formatMoney($total_sales); ?></div>
                <?php echo DashboardData::renderChangeBadge($total_sales, $prev_sales); ?>
            </div>
        </div>

        <?php if ($expenses_table_exists): ?>
        <div class="stat-card dashboard-stat-card--expense">
            <div class="stat-icon dashboard-stat-icon--expense">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_period_expenses'); ?></h3>
                <div class="stat-value dashboard-stat-value--expense">- <?php echo formatMoney($total_expenses); ?></div>
                <?php echo DashboardData::renderChangeBadge($total_expenses, $prev_expenses); ?>
            </div>
        </div>

        <div class="stat-card <?php echo $net_profit >= 0 ? 'green' : 'red'; ?>" <?php echo 'style="border-right-color: ' . ($net_profit >= 0 ? '#059669' : '#dc2626') . ';"'; ?>>
            <div class="stat-icon" <?php echo 'style="background: ' . ($net_profit >= 0 ? '#d1fae5' : '#fee2e2') . '; color: ' . ($net_profit >= 0 ? '#059669' : '#dc2626') . ';"'; ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_net_profit'); ?></h3>
                <div class="stat-value <?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?> dashboard-stat-value--profit">
                    <?php echo formatMoney($net_profit); ?>
                </div>
                <?php echo DashboardData::renderChangeBadge($net_profit, $prev_net_profit); ?>
                <div class="dashboard-stat-note">
                    <?php echo t('dashboard_sales_profit'); ?>: <?php echo formatMoney($gross_profit); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($low_stock_count > 0): ?>
        <div class="stat-card red">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('dashboard_low_stock'); ?></h3>
                <div class="stat-value text-danger"><?php echo number_format($low_stock_count); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($expiry_alert_enabled && $expired_products_count > 0): ?>
        <div class="stat-card dashboard-stat-card--expired">
            <div class="stat-icon dashboard-stat-icon--expired">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="stat-info">
                <h3>🚨 <?php echo t('dashboard_expired'); ?></h3>
                <div class="stat-value text-danger"><?php echo number_format($expired_products_count); ?></div>
                <div class="dashboard-stat-note--loss"><?php echo t('dashboard_financial_loss'); ?>: <?php echo formatMoney($expired_financial_loss); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($expiry_alert_enabled && $expiring_soon_count > 0): ?>
        <div class="stat-card dashboard-stat-card--expiring">
            <div class="stat-icon dashboard-stat-icon--expiring">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="stat-info">
                <h3>⚠️ <?php echo t('dashboard_expiring_soon'); ?> (<?php echo $expiry_alert_days_setting; ?> <?php echo t('days'); ?>)</h3>
                <div class="stat-value dashboard-stat-value--expiring"><?php echo number_format($expiring_soon_count); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- === تنبيهات الصلاحية المتقدمة (فوق الرسم البياني) === -->
    <?php if ($expiry_alert_enabled && ($expired_products_count > 0 || $expiring_soon_count > 0)): ?>
    <div class="card" style="border: 2px solid <?php echo $expired_products_count > 0 ? '#fca5a5' : '#fcd34d'; ?>; margin-bottom: 1rem;">
        <div class="card-header" style="background:<?php echo $expired_products_count > 0 ? '#fee2e2' : '#fef3c7'; ?>; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
            <h2 style="margin:0;">
                <?php if ($expired_products_count > 0): ?>
                    🚨 <?php echo t('dashboard_urgent_alert'); ?>: <?php echo number_format($expired_products_count); ?> <?php echo t('dashboard_expired_products'); ?>
                <?php else: ?>
                    ⚠️ <?php echo t('dashboard_expiry_alert'); ?>: <?php echo number_format($expiring_soon_count); ?> <?php echo t('dashboard_expiring_products'); ?> (<?php echo t('dashboard_within_days'); ?> <?php echo $expiry_alert_days_setting; ?> <?php echo t('days'); ?>)
                <?php endif; ?>
            </h2>
            <div style="display:flex; gap:8px; align-items:center;">
                <?php if ($expired_financial_loss > 0): ?>
                <span style="background:#dc2626; color:#fff; padding:4px 12px; border-radius:6px; font-size:0.82rem; font-weight:700;">
                    💰 <?php echo t('dashboard_financial_loss'); ?>: <?php echo formatMoney($expired_financial_loss); ?>
                </span>
                <?php endif; ?>
                <a href="products/expiry-report.php" class="btn btn-sm" style="background:<?php echo $expired_products_count > 0 ? '#dc2626' : '#d97706'; ?>; color:#fff; border:none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <?php echo t('dashboard_detailed_report'); ?>
                </a>

            </div>
        </div>
        <div class="card-body" style="padding:12px 16px;">
            <!-- بطاقة ملخص سريع -->
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:12px; padding:10px 14px; background:<?php echo $expired_products_count > 0 ? '#fef2f2' : '#fffbeb'; ?>; border-radius:10px; border:1px solid <?php echo $expired_products_count > 0 ? '#fecaca' : '#fde68a'; ?>;">
                <?php if ($expired_products_count > 0): ?>
                <div style="text-align:center;">
                    <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;"><?php echo t('dashboard_expired_count'); ?></div>
                    <div style="font-size:1.4rem; font-weight:900; color:#dc2626;"><?php echo number_format($expired_products_count); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($expiring_soon_count > 0): ?>
                <div style="text-align:center;">
                    <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;"><?php echo t('dashboard_expiring_count'); ?></div>
                    <div style="font-size:1.4rem; font-weight:900; color:#d97706;"><?php echo number_format($expiring_soon_count); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($expired_financial_loss > 0): ?>
                <div style="text-align:center;">
                    <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;"><?php echo t('loss'); ?></div>
                    <div style="font-size:1.4rem; font-weight:900; color:#dc2626;"><?php echo formatMoney($expired_financial_loss); ?></div>
                </div>
                <?php endif; ?>
                <div style="text-align:center;">
                    <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;"><?php echo t('dashboard_alert_days'); ?></div>
                    <div style="font-size:1.4rem; font-weight:900; color:#64748b;"><?php echo $expiry_alert_days_setting; ?></div>
                </div>
            </div>

            <?php if (!empty($expired_products_list)): ?>
            <!-- جدول المنتجات المنتهية -->
            <div style="margin-bottom:<?php echo !empty($expiring_soon_list) ? '16px' : '0'; ?>;">
                <h4 style="color:#dc2626; margin:0 0 8px; font-size:0.9rem;">
                    🚨 <?php echo t('dashboard_expired'); ?> (<?php echo number_format($expired_products_count); ?> <?php echo t('product'); ?>):
                </h4>
                <div class="table-responsive mobile-card-view" style="max-height:280px; overflow-y:auto; border:1px solid #fecaca; border-radius:8px;">
                    <table>
                        <thead style="position:sticky; top:0; z-index:1;">
                            <tr style="background:#dc2626; color:#fff;">
                                <th><?php echo t('code'); ?></th>
                                <th><?php echo t('product'); ?></th>
                                <th><?php echo t('dashboard_expired_qty'); ?></th>
                                <th><?php echo t('loss'); ?></th>
                                <th><?php echo t('common_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expired_products_list as $ep): ?>
                            <tr style="background:#fef2f2; border-bottom:1px solid #fecaca;">
                                <td data-label="<?php echo t('code'); ?>"><strong><?php echo htmlspecialchars($ep['code']); ?></strong></td>
                                <td data-label="<?php echo t('product'); ?>"><?php echo htmlspecialchars($ep['name']); ?></td>
                                <td data-label="<?php echo t('dashboard_expired_qty'); ?>" class="text-danger" style="font-weight:800;"><?php echo number_format($ep['expired_qty'], 2); ?> <?php echo htmlspecialchars($ep['unit']); ?></td>
                                <td data-label="<?php echo t('loss'); ?>" style="font-weight:700; color:#dc2626;"><?php echo formatMoney($ep['expired_value'] ?? 0); ?></td>
                                <td data-label="<?php echo t('common_action'); ?>"><a href="products/view-product.php?id=<?php echo $ep['id']; ?>" class="btn btn-sm" style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5;"><?php echo t('dashboard_view_batches'); ?></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($expiring_soon_list)): ?>
            <!-- جدول المنتجات القاربة على الانتهاء -->
            <div>
                <h4 style="color:#d97706; margin:0 0 8px; font-size:0.9rem;">
                    ⚠️ <?php echo t('dashboard_expiring_soon'); ?> <?php echo t('dashboard_within_days'); ?> <?php echo $expiry_alert_days_setting; ?> <?php echo t('days'); ?> (<?php echo number_format($expiring_soon_count); ?> <?php echo t('product'); ?>):
                </h4>
                <div class="table-responsive mobile-card-view" style="max-height:280px; overflow-y:auto; border:1px solid #fde68a; border-radius:8px;">
                    <table>
                        <thead style="position:sticky; top:0; z-index:1;">
                            <tr style="background:#d97706; color:#fff;">
                                <th><?php echo t('code'); ?></th>
                                <th><?php echo t('product'); ?></th>
                                <th><?php echo t('quantity'); ?></th>
                                <th><?php echo t('dashboard_nearest_expiry'); ?></th>
                                <th><?php echo t('dashboard_days_left'); ?></th>
                                <th><?php echo t('common_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiring_soon_list as $es): 
                                $nearest = $es['nearest_expiry'] ?? '';
                                $days_left = $nearest ? (strtotime($nearest) - strtotime(date('Y-m-d'))) / 86400 : 0;
                                $urgency_color = $days_left <= 7 ? '#dc2626' : ($days_left <= 14 ? '#d97706' : '#ca8a04');
                            ?>
                            <tr style="background:#fffbeb; border-bottom:1px solid #fde68a;">
                                <td data-label="<?php echo t('code'); ?>"><strong><?php echo htmlspecialchars($es['code']); ?></strong></td>
                                <td data-label="<?php echo t('product'); ?>"><?php echo htmlspecialchars($es['name']); ?></td>
                                <td data-label="<?php echo t('quantity'); ?>" style="font-weight:700; color:#d97706;"><?php echo number_format($es['expiring_qty'], 2); ?> <?php echo htmlspecialchars($es['unit']); ?></td>
                                <td data-label="<?php echo t('dashboard_nearest_expiry'); ?>" style="font-weight:600;"><?php echo $nearest ? $nearest : '—'; ?></td>
                                <td data-label="<?php echo t('dashboard_days_left'); ?>" style="font-weight:800; color:<?php echo $urgency_color; ?>;">
                                    <?php if ($days_left <= 0): ?>
                                        🚨 <?php echo t('dashboard_already_expired'); ?>
                                    <?php elseif ($days_left <= 7): ?>
                                        🚨 <?php echo intval($days_left); ?> <?php echo t('days'); ?>
                                    <?php elseif ($days_left <= 14): ?>
                                        ⚠️ <?php echo intval($days_left); ?> <?php echo t('days'); ?>
                                    <?php else: ?>
                                        📅 <?php echo intval($days_left); ?> <?php echo t('days'); ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php echo t('common_action'); ?>"><a href="products/view-product.php?id=<?php echo $es['id']; ?>" class="btn btn-sm" style="background:#fef3c7; color:#d97706; border:1px solid #fcd34d;"><?php echo t('dashboard_view_batches'); ?></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- === رسالة توضيحية: نظام الصلاحية مفعّل لكن لا توجد بيانات === -->
    <?php if ($expiry_alert_enabled && $expired_products_count == 0 && $expiring_soon_count == 0): ?>
    <?php
        $expiry_needs_setup = false;
        $expiry_setup_msg = '';
        $expiry_setup_icon = '';
        if ($expiry_tracked_count == 0) {
            $expiry_needs_setup = true;
            $expiry_setup_msg = t('dashboard_no_expiry_tracked');
            $expiry_setup_icon = '📌';
        } elseif ($expiry_batches_count == 0) {
            $expiry_needs_setup = true;
            $expiry_setup_msg = t('dashboard_no_expiry_batches');
            $expiry_setup_icon = '📅';
        }
    ?>
    <?php if ($expiry_needs_setup): ?>
    <div class="card" style="margin-bottom: 1rem; border: 2px dashed #93c5fd; background: linear-gradient(135deg, #eff6ff, #f0f9ff);">
        <div class="card-body" style="padding: 16px 20px; display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
            <div style="font-size:2.2rem; line-height:1;"><?php echo $expiry_setup_icon; ?></div>
            <div style="flex:1; min-width:250px;">
                <div style="font-weight:700; font-size:0.92rem; color:#1e40af; margin-bottom:6px;">
                    <?php echo t('dashboard_expiry_enabled_no_data'); ?>
                </div>
                <div style="font-size:0.84rem; color:#3b82f6; line-height:1.7; margin-bottom:10px;">
                    <?php echo htmlspecialchars($expiry_setup_msg); ?>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <!-- خطوات الإعداد -->
                    <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:6px 12px; border-radius:8px; border:1px solid #bfdbfe; font-size:0.8rem;">
                        <span style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:0.75rem;">1</span>
                        <a href="products/list-products.php" style="color:#1e40af; font-weight:600; text-decoration:none;"><?php echo t('dashboard_edit_product'); ?></a>
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="#93c5fd" stroke-width="2" width="18" height="18"><path d="M9 5l7 7-7 7"/></svg>
                    <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:6px 12px; border-radius:8px; border:1px solid #bfdbfe; font-size:0.8rem;">
                        <span style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:0.75rem;">2</span>
                        <span style="color:#1e40af; font-weight:600;"><?php echo t('dashboard_enable_tracking'); ?></span>
                    </div>
                    <svg viewBox="0 0 24 24" fill="none" stroke="#93c5fd" stroke-width="2" width="18" height="18"><path d="M9 5l7 7-7 7"/></svg>
                    <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:6px 12px; border-radius:8px; border:1px solid #bfdbfe; font-size:0.8rem;">
                        <span style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:0.75rem;">3</span>
                        <a href="purchases/add-purchase.php" style="color:#1e40af; font-weight:600; text-decoration:none;"><?php echo t('dashboard_enter_dates'); ?></a>
                    </div>
                </div>
            </div>
            <!-- إحصائيات صغيرة -->
            <div style="display:flex; flex-direction:column; gap:6px; align-items:center;">
                <div style="background:#fff; padding:8px 14px; border-radius:8px; border:1px solid #bfdbfe; text-align:center;">
                    <div style="font-size:0.68rem; color:#94a3b8; font-weight:600;"><?php echo t('dashboard_tracked_products'); ?></div>
                    <div style="font-size:1.1rem; font-weight:900; color:#1e40af;"><?php echo number_format($expiry_tracked_count); ?></div>
                </div>
                <div style="background:#fff; padding:8px 14px; border-radius:8px; border:1px solid #bfdbfe; text-align:center;">
                    <div style="font-size:0.68rem; color:#94a3b8; font-weight:600;"><?php echo t('dashboard_batches_with_dates'); ?></div>
                    <div style="font-size:1.1rem; font-weight:900; color:<?php echo $expiry_batches_count > 0 ? '#059669' : '#dc2626'; ?>;"><?php echo number_format($expiry_batches_count); ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- === رسوم بيانية متقدمة (Chart.js) === -->
    <div class="charts-grid dashboard-chart-grid">
        <!-- رسم بياني: المبيعات والمشتريات والأرباح الشهرية -->
        <div class="chart-card chart-card--wide">
            <div class="chart-card-header">
                <h2>📊 <?php echo t('dashboard_6months_stats'); ?></h2>
                <div class="dashboard-chart-legend">
                    <span class="dashboard-chart-legend-item"><span class="dashboard-chart-legend-dot dashboard-chart-legend-dot--sales"></span> <?php echo t('sales'); ?></span>
                    <span class="dashboard-chart-legend-item"><span class="dashboard-chart-legend-dot dashboard-chart-legend-dot--purchases"></span> <?php echo t('purchases'); ?></span>
                    <span class="dashboard-chart-legend-item"><span class="dashboard-chart-legend-dot dashboard-chart-legend-dot--profits"></span> <?php echo t('profits'); ?></span>
                </div>
            </div>
            <div class="chart-card-body">
                <div class="chart-container dashboard-chart-container">
                    <canvas id="monthlyOverviewChart"></canvas>
                </div>
            </div>
        </div>
        <?php if (!empty($top_selling_products)): ?>
        <!-- رسم بياني: أفضل المنتجات مبيعاً -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h2>🏆 <?php echo t('dashboard_top_products'); ?></h2>
            </div>
            <div class="chart-card-body">
                <div class="chart-container dashboard-chart-container">
                    <canvas id="topProductsDashChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    (function() {
        Chart.defaults.font.family = 'Tajawal, Segoe UI, sans-serif';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        const C = window.CURRENCY || '';

        // === رسم بياني شهري ===
        new Chart(document.getElementById('monthlyOverviewChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($ms) { return $ms['month_label'] . ' ' . $ms['year_label']; }, $monthly_stats)); ?>,
                datasets: [
                    {
                        label: '<?php echo t('sales'); ?>',
                        data: <?php echo json_encode(array_column($monthly_stats, 'sales')); ?>,
                        backgroundColor: 'rgba(37,99,235,0.8)',
                        borderRadius: 6, barPercentage: 0.7
                    },
                    {
                        label: '<?php echo t('purchases'); ?>',
                        data: <?php echo json_encode(array_column($monthly_stats, 'purchases')); ?>,
                        backgroundColor: 'rgba(220,38,38,0.7)',
                        borderRadius: 6, barPercentage: 0.7
                    },
                    {
                        label: '<?php echo t('profits'); ?>',
                        data: <?php echo json_encode(array_column($monthly_stats, 'profit')); ?>,
                        backgroundColor: 'rgba(5,150,105,0.8)',
                        borderRadius: 6, barPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: { tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') + ' ' + C } } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => v >= 1000 ? (v/1000).toFixed(0) + 'K' : v } } }
            }
        });

        <?php if (!empty($top_selling_products)): ?>
        // === أفضل المنتجات ===
        new Chart(document.getElementById('topProductsDashChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($p) { return mb_substr($p['name'], 0, 20) . (mb_strlen($p['name']) > 20 ? '...' : ''); }, $top_selling_products)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(function($p) { return floatval($p['total_sales']); }, $top_selling_products)); ?>,
                    backgroundColor: <?php echo json_encode(array_map(function($p) { return $p['color']; }, $top_selling_products)); ?>,
                    borderWidth: 2, borderColor: '#fff', hoverOffset: 8
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', rtl: true, labels: { font: { size: 11 }, padding: 10 } },
                    tooltip: { callbacks: { label: ctx => { const t = ctx.dataset.data.reduce((a,b)=>a+b,0); return ctx.label + ': ' + parseFloat(ctx.parsed).toLocaleString('ar-MA') + ' ' + C + ' (' + ((ctx.parsed/t)*100).toFixed(1) + '%)'; } } }
                }
            }
        });
        <?php endif; ?>
    })();
    </script>

    <!-- آخر المنتجات -->
    <div class="card">
        <div class="card-header">
            <h2><?php echo t('dashboard_recent_products'); ?></h2>
            <a href="products/list-products.php" class="btn btn-sm btn-outline"><?php echo t('nav_show_all'); ?></a>
        </div>
        <div class="card-body">
            <?php if (empty($recent_products)): ?>
                <p class="text-muted" style="text-align:center; padding: 2rem;"><?php echo t('dashboard_no_products'); ?> <a href="products/add-product.php"><?php echo t('dashboard_add_first_product'); ?></a></p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('code'); ?></th>
                            <th><?php echo t('products_name'); ?></th>
                            <th><?php echo t('products_purchase_price'); ?></th>
                            <th><?php echo t('products_selling_price'); ?></th>
                            <th><?php echo t('quantity'); ?></th>
                            <th><?php echo t('unit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_products as $product): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($product['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td><?php echo formatMoney($product['purchase_price']); ?></td>
                            <td><?php echo formatMoney($product['selling_price']); ?></td>
                            <td><?php echo number_format($product['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($product['unit']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="two-col-grid">
        <!-- آخر المشتريات -->
        <div class="card">
            <div class="card-header">
                <h2><?php echo t('dashboard_recent_purchases'); ?></h2>
                <a href="purchases/add-purchase.php" class="btn btn-sm btn-secondary"><?php echo t('purchases_register'); ?></a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_purchases)): ?>
                    <p class="text-muted" style="text-align:center; padding: 1rem;"><?php echo t('purchases_no_results'); ?></p>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo t('product'); ?></th>
                                <th><?php echo t('quantity'); ?></th>
                                <th><?php echo t('amount'); ?></th>
                                <th><?php echo t('date'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_purchases as $purchase): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($purchase['product_name']); ?></td>
                                <td><?php echo number_format($purchase['quantity'], 2); ?></td>
                                <td><?php echo formatMoney($purchase['total']); ?></td>
                                <td><?php echo $purchase['purchase_date']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- آخر المبيعات -->
        <div class="card">
            <div class="card-header">
                <h2><?php echo t('dashboard_recent_sales'); ?></h2>
                <a href="sales/add-sale.php" class="btn btn-sm btn-warning"><?php echo t('sales_register'); ?></a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_sales)): ?>
                    <p class="text-muted" style="text-align:center; padding: 1rem;"><?php echo t('sales_no_results'); ?></p>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo t('product'); ?></th>
                                <th><?php echo t('quantity'); ?></th>
                                <th><?php echo t('amount'); ?></th>
                                <th><?php echo t('date'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_sales as $sale): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                                <td><?php echo number_format($sale['quantity'], 2); ?></td>
                                <td><?php echo formatMoney($sale['total']); ?></td>
                                <td><?php echo $sale['sale_date']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- آخر المصاريف -->
    <?php if (!empty($recent_expenses)): ?>
    <div class="card">
        <div class="card-header">
            <h2><?php echo t('dashboard_recent_expenses'); ?></h2>
            <a href="expenses/list-expenses.php" class="btn btn-sm btn-danger"><?php echo t('nav_show_all'); ?></a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('amount'); ?></th>
                            <th><?php echo t('description'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_expenses as $expense): ?>
                        <tr>
                            <td><?php echo $expense['expense_date']; ?></td>
                            <td><span class="badge badge-danger"><?php echo htmlspecialchars($expense['category']); ?></span></td>
                            <td><strong class="text-danger"><?php echo formatMoney($expense['amount']); ?></strong></td>
                            <td style="font-size:0.85rem; color:var(--gray-600); max-width:300px; white-space:pre-line;"><?php echo htmlspecialchars($expense['description'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($show_expiry_popup): ?>
<!-- === نافذة تنبيه الصلاحية عند تسجيل الدخول === -->
<link rel="stylesheet" href="assets/css/popup.css">

<div class="expiry-popup-overlay" id="expiryPopupOverlay" onclick="if(event.target===this)dismissExpiryPopup()">
    <div class="expiry-popup-card">
        <!-- الرأس -->
        <div class="expiry-popup-header <?php echo $expired_products_count > 0 ? 'critical' : 'warning'; ?>">
            <span class="expiry-popup-icon"><?php echo $expired_products_count > 0 ? '🚨' : '⚠️'; ?></span>
            <h2 class="expiry-popup-title">
                <?php if ($expired_products_count > 0): ?>
                    <?php echo t('expiry_popup_urgent_title'); ?>
                <?php else: ?>
                    <?php echo t('expiry_popup_warning_title'); ?>
                <?php endif; ?>
            </h2>
            <p class="expiry-popup-subtitle">
                <?php echo t('expiry_popup_review_message'); ?>
            </p>
        </div>

        <!-- الإحصائيات -->
        <div class="expiry-popup-body">
            <div class="expiry-popup-stats">
                <?php if ($expired_products_count > 0): ?>
                <div class="expiry-popup-stat">
                    <div class="expiry-popup-stat-value danger"><?php echo number_format($expired_products_count); ?></div>
                    <div class="expiry-popup-stat-label"><?php echo t('dashboard_expired'); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($expiring_soon_count > 0): ?>
                <div class="expiry-popup-stat">
                    <div class="expiry-popup-stat-value warning"><?php echo number_format($expiring_soon_count); ?></div>
                    <div class="expiry-popup-stat-label"><?php echo t('dashboard_expiring_soon'); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($expired_financial_loss > 0): ?>
                <div class="expiry-popup-stat">
                    <div class="expiry-popup-stat-value loss"><?php echo formatMoney($expired_financial_loss); ?></div>
                    <div class="expiry-popup-stat-label"><?php echo t('dashboard_financial_loss'); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($expired_products_count == 0 && $expired_financial_loss == 0): ?>
                <div class="expiry-popup-stat">
                    <div class="expiry-popup-stat-value" style="color:#64748b;"><?php echo $expiry_alert_days_setting; ?></div>
                    <div class="expiry-popup-stat-label"><?php echo t('dashboard_alert_days'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($expired_products_list)): ?>
            <!-- قائمة المنتجات المنتهية -->
            <div class="expiry-popup-section">
                <div class="expiry-popup-section-title danger">
                    🚨 <?php echo t('expiry_expired'); ?> (<?php echo number_format($expired_products_count); ?>)
                </div>
                <ul class="expiry-popup-list">
                    <?php
                    $show_count = min(count($expired_products_list), 5);
                    for ($i = 0; $i < $show_count; $i++):
                        $ep = $expired_products_list[$i];
                    ?>
                    <li>
                        <span class="product-name"><?php echo htmlspecialchars($ep['name']); ?></span>
                        <span class="product-badge danger">
                            <?php echo number_format($ep['expired_qty'], 1); ?> <?php echo htmlspecialchars($ep['unit']); ?>
                            — <?php echo formatMoney($ep['expired_value'] ?? 0); ?>
                        </span>
                    </li>
                    <?php endfor; ?>
                </ul>
                <?php if (count($expired_products_list) > 5): ?>
                <div class="expiry-popup-more">+ <?php echo number_format(count($expired_products_list) - 5); ?> <?php echo t('expiry_popup_more_products'); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($expiring_soon_list)): ?>
            <!-- قائمة المنتجات القاربة -->
            <div class="expiry-popup-section">
                <div class="expiry-popup-section-title warning">
                    ⚠️ <?php echo t('expiry_popup_expiring_in_days', $expiry_alert_days_setting); ?> (<?php echo number_format($expiring_soon_count); ?>)
                </div>
                <ul class="expiry-popup-list">
                    <?php
                    $show_count2 = min(count($expiring_soon_list), 5);
                    for ($i = 0; $i < $show_count2; $i++):
                        $es = $expiring_soon_list[$i];
                        $nearest = $es['nearest_expiry'] ?? '';
                        $days_left = $nearest ? intval((strtotime($nearest) - strtotime(date('Y-m-d'))) / 86400) : 0;
                    ?>
                    <li>
                        <span class="product-name"><?php echo htmlspecialchars($es['name']); ?></span>
                        <span class="product-badge <?php echo $days_left <= 7 ? 'danger' : 'warning'; ?>">
                            <?php echo intval($days_left); ?> <?php echo t('expiry_popup_days_left'); ?>
                        </span>
                    </li>
                    <?php endfor; ?>
                </ul>
                <?php if (count($expiring_soon_list) > 5): ?>
                <div class="expiry-popup-more">+ <?php echo number_format(count($expiring_soon_list) - 5); ?> <?php echo t('expiry_popup_more_products'); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- الأزرار -->
        <div class="expiry-popup-footer">
            <a href="products/expiry-report.php" class="expiry-popup-btn expiry-popup-btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <?php echo t('dashboard_detailed_report'); ?>
            </a>
            <button type="button" class="expiry-popup-btn expiry-popup-btn-secondary" onclick="dismissExpiryPopup()">
                <?php echo t('expiry_popup_got_it'); ?>
            </button>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
function dismissExpiryPopup() {
    var overlay = document.getElementById('expiryPopupOverlay');
    if (overlay) {
        overlay.style.animation = 'expiryFadeIn 0.2s ease-out reverse';
        setTimeout(function() {
            overlay.remove();
        }, 200);
    }
    // تسجيل الإخفاء في الجلسة (مع توقيع التنبيه الحالي)
    var sig = '<?php echo $expiry_popup_signature ?? ""; ?>';
    fetch('<?php echo $base_url; ?>/api/dismiss-expiry-popup.php?sig=' + encodeURIComponent(sig), { method: 'GET' }).catch(function(){});
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
