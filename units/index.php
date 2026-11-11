<?php
$page_title = 'الرئيسية - إدارة المخزون';
require_once 'config/database.php';
require_once 'config/upgrades.php';
require_once 'includes/auth.php';
requireLogin();

// التحقق من النسخ التلقائي (للمدير فقط)
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    require_once 'config/backup.php';
    checkAutoBackup();
}

require_once 'includes/header.php';

// جلب رقم إصدار قاعدة البيانات الحالي
try {
    $pdo_conn = getDBConnection();
    $db_version = getCurrentDBVersion($pdo_conn);
    $needs_upgrade = $pdo_conn ? (compareVersions($db_version, APP_VERSION) < 0) : false;
} catch (\Throwable $e) {
    $db_version = '0.0.0';
    $needs_upgrade = false;
}

// إحصائيات عامة
$total_products = fetchOne("SELECT COUNT(*) as count FROM products")['count'] ?? 0;
$total_stock_value = fetchOne("SELECT COALESCE(SUM(quantity * purchase_price), 0) as total FROM products")['total'] ?? 0;
$total_purchases = fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM purchases WHERE YEAR(purchase_date) = YEAR(CURRENT_DATE())")['total'] ?? 0;
$total_sales = fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE YEAR(sale_date) = YEAR(CURRENT_DATE())")['total'] ?? 0;
$low_stock_count = fetchOne("SELECT COUNT(*) as count FROM products WHERE quantity <= min_quantity AND min_quantity > 0")['count'] ?? 0;
// الربح من المبيعات لهذا الشهر
$total_sales_month = fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURRENT_DATE()) AND YEAR(sale_date) = YEAR(CURRENT_DATE())")['total'] ?? 0;
$total_cost_month = fetchOne("SELECT COALESCE(SUM(cost_total), 0) as total FROM sales WHERE MONTH(sale_date) = MONTH(CURRENT_DATE()) AND YEAR(sale_date) = YEAR(CURRENT_DATE())")['total'] ?? 0;
$gross_profit_month = $total_sales_month - $total_cost_month;

// التحقق من وجود جدول المصاريف
try {
    $expenses_table_exists = fetchOne("SHOW TABLES LIKE 'expenses'") ? true : false;
} catch (Exception $e) {
    $expenses_table_exists = false;
}

try {
    $total_expenses = fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE())")['total'] ?? 0;
} catch (Exception $e) {
    $total_expenses = 0;
    $expenses_table_exists = false;
}

// صافي الربح = الربح الإجمالي - المصاريف
$net_profit_month = $gross_profit_month - $total_expenses;

// آخر المنتجات المضافة
$recent_products = fetchAll("SELECT * FROM products ORDER BY created_at DESC LIMIT 5");

// آخر المشتريات
$recent_purchases = fetchAll("SELECT p.*, pr.name as product_name, pr.code FROM purchases p JOIN products pr ON p.product_id = pr.id ORDER BY p.created_at DESC LIMIT 5");

// آخر المصاريف
try {
    $recent_expenses = fetchAll("SELECT * FROM expenses ORDER BY created_at DESC LIMIT 5");
} catch (Exception $e) {
    $recent_expenses = [];
}

// آخر المبيعات
$recent_sales = fetchAll("SELECT s.*, pr.name as product_name, pr.code FROM sales s JOIN products pr ON s.product_id = pr.id ORDER BY s.created_at DESC LIMIT 5");
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            لوحة التحكم
            <span style="font-size:0.75rem; background:#dbeafe; color:#1d4ed8; padding:2px 8px; border-radius:4px; font-family:monospace;">v<?php echo $db_version; ?></span>
        </h1>
        <div class="btn-group">
            <?php if ($needs_upgrade): ?>
            <a href="upgrade.php" class="btn btn-danger" style="animation: pulse 2s infinite;">
                🔄 تحديث متاح (v<?php echo APP_VERSION; ?>)
            </a>
            <?php endif; ?>
            <a href="products/add-product.php" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                إضافة منتوج
            </a>
            <a href="purchases/add-purchase.php" class="btn btn-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                تسجيل شراء
            </a>
            <a href="sales/add-sale.php" class="btn btn-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                تسجيل بيع
            </a>
            <?php if ($expenses_table_exists): ?>
            <a href="expenses/add-expense.php" class="btn btn-danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                تسجيل مصروف
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div class="stat-info">
                <h3>عدد المنتجات</h3>
                <div class="stat-value"><?php echo number_format($total_products); ?></div>
            </div>
        </div>

        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3>قيمة المخزون</h3>
                <div class="stat-value"><?php echo formatMoney($total_stock_value); ?></div>
            </div>
        </div>

        <div class="stat-card cyan">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            </div>
            <div class="stat-info">
                <h3>إجمالي المشتريات</h3>
                <div class="stat-value"><?php echo formatMoney($total_purchases); ?></div>
            </div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            </div>
            <div class="stat-info">
                <h3>إجمالي المبيعات</h3>
                <div class="stat-value"><?php echo formatMoney($total_sales); ?></div>
            </div>
        </div>

        <?php if ($low_stock_count > 0): ?>
        <div class="stat-card red">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="stat-info">
                <h3>منتجات منخفضة المخزون</h3>
                <div class="stat-value text-danger"><?php echo number_format($low_stock_count); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($expenses_table_exists): ?>
        <div class="stat-card" style="border-right-color: #be123c;">
            <div class="stat-icon" style="background: #ffe4e6; color: #be123c;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3>مصاريف الشهر</h3>
                <div class="stat-value" style="color:#be123c;">- <?php echo formatMoney($total_expenses); ?></div>
            </div>
        </div>

        <div class="stat-card <?php echo $net_profit_month >= 0 ? 'green' : 'red'; ?>" style="border-right-color: <?php echo $net_profit_month >= 0 ? '#059669' : '#dc2626'; ?>;">
            <div class="stat-icon" style="background: <?php echo $net_profit_month >= 0 ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $net_profit_month >= 0 ? '#059669' : '#dc2626'; ?>;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <div class="stat-info">
                <h3>صافي الربح <span style="font-size:0.7rem; color:var(--gray-400); font-weight:400;">(بعد المصاريف)</span></h3>
                <div class="stat-value <?php echo $net_profit_month >= 0 ? 'text-success' : 'text-danger'; ?>" style="font-size:1.3rem;">
                    <?php echo formatMoney($net_profit_month); ?>
                </div>
                <div style="font-size:0.7rem; color:var(--gray-400); margin-top:2px;">
                    ربح المبيعات: <?php echo formatMoney($gross_profit_month); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- آخر المنتجات -->
    <div class="card">
        <div class="card-header">
            <h2>آخر المنتجات المضافة</h2>
            <a href="products/list-products.php" class="btn btn-sm btn-outline">عرض الكل</a>
        </div>
        <div class="card-body">
            <?php if (empty($recent_products)): ?>
                <p class="text-muted" style="text-align:center; padding: 2rem;">لا توجد منتجات بعد. <a href="products/add-product.php">أضف منتجك الأول</a></p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>الكود</th>
                            <th>اسم المنتج</th>
                            <th>سعر الشراء</th>
                            <th>سعر البيع</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
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
                <h2>آخر المشتريات</h2>
                <a href="purchases/add-purchase.php" class="btn btn-sm btn-secondary">شراء جديد</a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_purchases)): ?>
                    <p class="text-muted" style="text-align:center; padding: 1rem;">لا توجد مشتريات بعد</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المبلغ</th>
                                <th>التاريخ</th>
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
                <h2>آخر المبيعات</h2>
                <a href="sales/add-sale.php" class="btn btn-sm btn-warning">بيع جديد</a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_sales)): ?>
                    <p class="text-muted" style="text-align:center; padding: 1rem;">لا توجد مبيعات بعد</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المبلغ</th>
                                <th>التاريخ</th>
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
            <h2>آخر المصاريف</h2>
            <a href="expenses/list-expenses.php" class="btn btn-sm btn-danger">عرض الكل</a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>التصنيف</th>
                            <th>المبلغ</th>
                            <th>الوصف</th>
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

<?php require_once 'includes/footer.php'; ?>
