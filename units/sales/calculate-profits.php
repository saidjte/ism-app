<?php
$page_title = 'حساب الأرباح';
require_once '../config/database.php';
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/header.php';

// فلترة التاريخ
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// حساب إجمالي المبيعات
$total_sales = fetchOne(
    "SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE sale_date BETWEEN ? AND ?",
    [$start_date, $end_date]
)['total'];

// حساب إجمالي التكلفة من البيانات المحفوظة وقت البيع
$total_cost = fetchOne(
    "SELECT COALESCE(SUM(cost_total), 0) as total FROM sales WHERE sale_date BETWEEN ? AND ?",
    [$start_date, $end_date]
)['total'];

// حساب الربح الإجمالي (من المبيعات فقط)
$gross_profit = fetchOne(
    "SELECT COALESCE(SUM(profit), 0) as total FROM sales WHERE sale_date BETWEEN ? AND ?",
    [$start_date, $end_date]
)['total'];

// إجمالي المشتريات في الفترة
$total_purchases = fetchOne(
    "SELECT COALESCE(SUM(total), 0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?",
    [$start_date, $end_date]
)['total'];

// المصاريف في الفترة (مع try-catch في حالة عدم وجود الجدول)
$expenses_table_exists = false;
$total_expenses = 0;
$expense_by_category = [];
try {
    $expenses_table_exists = true;
    $total_expenses = fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?",
        [$start_date, $end_date]
    )['total'];

    // تفصيل المصاريف حسب التصنيف
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
    $total_expenses = 0;
}

// صافي الربح = الربح الإجمالي - المصاريف
$net_profit = $gross_profit - $total_expenses;

// هوامش الربح
$gross_margin = $total_sales > 0 ? ($gross_profit / $total_sales) * 100 : 0;
$net_margin = $total_sales > 0 ? ($net_profit / $total_sales) * 100 : 0;

// تفصيل الأرباح حسب المنتج (باستخدام الأسعار المحفوظة وقت البيع)
$product_profits = fetchAll(
    "SELECT p.id, p.code, p.name, p.unit, p.quantity as current_stock, p.purchase_price as current_purchase_price, p.selling_price as current_selling_price,
            COALESCE(SUM(s.quantity), 0) as total_sold,
            COALESCE(SUM(s.total), 0) as total_sales_amount,
            COALESCE(SUM(s.cost_total), 0) as total_cost_amount,
            COALESCE(SUM(s.profit), 0) as total_profit
     FROM products p
     LEFT JOIN sales s ON p.id = s.product_id AND s.sale_date BETWEEN ? AND ?
     GROUP BY p.id
     HAVING total_sold > 0
     ORDER BY total_profit DESC",
    [$start_date, $end_date]
);

// قيمة المخزون الحالي (محسوبة من الدفعات المتوفرة بسعرها الأصلي)
try {
    $pdo_tmp = getDBConnection();
    $stock_value_result = $pdo_tmp->query("SELECT COALESCE(SUM(remaining_quantity * purchase_price), 0) as total FROM purchase_batches WHERE remaining_quantity > 0");
    $stock_value = floatval($stock_value_result->fetchColumn());
    // إذا لم تكن هناك دفعات، نستخدم الطريقة القديمة
    if ($stock_value == 0) {
        $stock_value = floatval(fetchOne("SELECT COALESCE(SUM(quantity * purchase_price), 0) as total FROM products")['total']);
    }
} catch (Exception $e) {
    $stock_value = floatval(fetchOne("SELECT COALESCE(SUM(quantity * purchase_price), 0) as total FROM products")['total']);
}

// تفصيل حسب المنتج مع المخزون
$inventory_profit_data = fetchAll(
    "SELECT p.id, p.code, p.name, p.unit, p.quantity as current_stock, p.purchase_price, p.selling_price,
            COALESCE(SUM(pur.quantity), 0) as total_purchased,
            COALESCE(SUM(pur.total), 0) as total_purchase_amount,
            COALESCE(SUM(s.quantity), 0) as total_sold,
            COALESCE(SUM(s.total), 0) as total_sales_amount,
            COALESCE(SUM(s.cost_total), 0) as total_cost_amount,
            COALESCE(SUM(s.profit), 0) as total_profit
     FROM products p
     LEFT JOIN purchases pur ON p.id = pur.product_id AND pur.purchase_date BETWEEN ? AND ?
     LEFT JOIN sales s ON p.id = s.product_id AND s.sale_date BETWEEN ? AND ?
     GROUP BY p.id
     ORDER BY p.name",
    [$start_date, $end_date, $start_date, $end_date]
);
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            حساب الأرباح
        </h1>
        <a href="add-sale.php" class="btn btn-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 4v16m8-8H4"/></svg>
            بيع جديد
        </a>
    </div>

    <!-- فلتر التاريخ -->
    <div class="card">
        <div class="card-header"><h2>تحديد الفترة</h2></div>
        <div class="card-body">
            <form method="GET" class="form-grid date-filter-grid">
                <div class="form-group">
                    <label>من تاريخ</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="form-group">
                    <label>إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <button type="submit" class="btn btn-primary">تحديث</button>
            </form>
        </div>
    </div>

    <!-- ملخص الأرباح -->
    <div class="stats-grid">
        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/></svg>
            </div>
            <div class="stat-info">
                <h3>إجمالي المبيعات</h3>
                <div class="stat-value"><?php echo formatMoney($total_sales); ?></div>
            </div>
        </div>

        <div class="stat-card cyan">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
            </div>
            <div class="stat-info">
                <h3>تكلفة البضاعة المباعة</h3>
                <div class="stat-value"><?php echo formatMoney($total_cost); ?></div>
            </div>
        </div>

        <div class="stat-card <?php echo $gross_profit >= 0 ? 'green' : 'red'; ?>">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="stat-info">
                <h3>الربح الإجمالي</h3>
                <div class="stat-value <?php echo $gross_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo formatMoney($gross_profit); ?>
                </div>
                <div style="font-size:0.75rem; color:var(--gray-500); margin-top:2px;">
                    هامش: <?php echo number_format($gross_margin, 1); ?>%
                </div>
            </div>
        </div>

        <?php if ($expenses_table_exists): ?>
        <div class="stat-card" style="border-right-color: #be123c;">
            <div class="stat-icon" style="background: #ffe4e6; color: #be123c;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3>المصاريف</h3>
                <div class="stat-value" style="color: #be123c;">
                    - <?php echo formatMoney($total_expenses); ?>
                </div>
                <a href="../expenses/list-expenses.php?date_from=<?php echo urlencode($start_date); ?>&date_to=<?php echo urlencode($end_date); ?>" style="font-size:0.75rem; color:var(--gray-500); margin-top:2px; display:block;">عرض التفاصيل ←</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="stat-card <?php echo $net_profit >= 0 ? 'green' : 'red'; ?>" style="<?php echo $net_profit >= 0 ? 'border-right-color: #059669;' : 'border-right-color: #dc2626;'; ?>">
            <div class="stat-icon" style="<?php echo $net_profit >= 0 ? 'background: #d1fae5; color: #059669;' : 'background: #fee2e2; color: #dc2626;'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <div class="stat-info">
                <h3>صافي الربح <span style="font-size:0.7rem; color:var(--gray-400); font-weight:400;">(بعد المصاريف)</span></h3>
                <div class="stat-value <?php echo $net_profit >= 0 ? 'text-success' : 'text-danger'; ?>" style="font-size:1.6rem;">
                    <?php echo formatMoney($net_profit); ?>
                </div>
                <div style="font-size:0.75rem; color:var(--gray-500); margin-top:2px;">
                    هامش صافي: <?php echo number_format($net_margin, 1); ?>%
                </div>
            </div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4"/></svg>
            </div>
            <div class="stat-info">
                <h3>قيمة المخزون الحالي</h3>
                <div class="stat-value"><?php echo formatMoney($stock_value); ?></div>
            </div>
        </div>
    </div>

    <!-- حساب الأرباح - ملخص بصري -->
    <div class="card">
        <div class="card-header"><h2>ملخص حساب الربح</h2></div>
        <div class="card-body">
            <div class="profit-summary">
                <div class="profit-item">
                    <h4>➕ إجمالي المبيعات</h4>
                    <div class="value" style="color: #059669;"><?php echo formatMoney($total_sales); ?></div>
                </div>
                <div class="profit-item">
                    <h4>➖ تكلفة البضاعة المباعة</h4>
                    <div class="value" style="color: #dc2626;">- <?php echo formatMoney($total_cost); ?></div>
                </div>
                <div class="profit-item" style="background: <?php echo $gross_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 10px 14px; border-radius: 8px;">
                    <h4 style="font-weight:700;">= الربح الإجمالي</h4>
                    <div class="value <?php echo $gross_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-weight:700;">
                        <?php echo formatMoney($gross_profit); ?>
                    </div>
                </div>
                <?php if ($expenses_table_exists && $total_expenses > 0): ?>
                <div class="profit-item">
                    <h4>➖ المصاريف</h4>
                    <div class="value" style="color: #be123c;">- <?php echo formatMoney($total_expenses); ?></div>
                </div>
                <?php endif; ?>
                <div class="profit-item" style="background: <?php echo $net_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 12px 14px; border-radius: 8px; border: 2px solid <?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <h4 style="font-weight:700; font-size: 1.05rem;">= صافي الربح</h4>
                    <div class="value <?php echo $net_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-weight:800; font-size: 1.2rem;">
                        <?php echo formatMoney($net_profit); ?>
                        <span style="font-size:0.8rem; font-weight:400;">(<?php echo number_format($net_margin, 1); ?>%)</span>
                    </div>
                </div>
            </div>

            <?php if ($expenses_table_exists && !empty($expense_by_category)): ?>
            <div style="margin-top: 1.5rem;">
                <h3 style="font-size: 1rem; margin-bottom: 0.75rem; color: var(--gray-700);">📊 تفصيل المصاريف في هذه الفترة:</h3>
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
    <div class="card">
        <div class="card-header">
            <h2>تفصيل الأرباح حسب المنتج</h2>
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
                        <?php foreach ($product_profits as $pp):
                            $margin = $pp['total_sales_amount'] > 0 ? ($pp['total_profit'] / $pp['total_sales_amount']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($pp['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($pp['name']); ?></td>
                            <td><?php echo htmlspecialchars($pp['unit']); ?></td>
                            <td><?php echo number_format($pp['total_sold'], 2); ?></td>
                            <td><?php echo formatMoney($pp['total_sales_amount']); ?></td>
                            <td><?php echo formatMoney($pp['total_cost_amount']); ?></td>
                            <td class="<?php echo $pp['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?> text-bold">
                                <?php echo formatMoney($pp['total_profit']); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $margin >= 0 ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo number_format($margin, 1); ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:700;">
                            <td colspan="4">الإجمالي</td>
                            <td><?php echo formatMoney($total_sales); ?></td>
                            <td><?php echo formatMoney($total_cost); ?></td>
                            <td class="<?php echo $gross_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatMoney($gross_profit); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $gross_margin >= 0 ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo number_format($gross_margin, 1); ?>%
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
                    <h4>إجمالي المبيعات</h4>
                    <div class="value"><?php echo formatMoney($total_sales); ?></div>
                </div>
                <div class="profit-item">
                    <h4>تكلفة البضاعة المباعة</h4>
                    <div class="value"><?php echo formatMoney($total_cost); ?></div>
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
                    <div class="value" style="color: #be123c;">- <?php echo formatMoney($total_expenses); ?></div>
                </div>
                <div class="profit-item" style="background: <?php echo $net_profit >= 0 ? '#f0fdf4' : '#fef2f2'; ?>; padding: 12px 14px; border-radius: 8px; border: 2px solid <?php echo $net_profit >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <h4 style="font-weight:700;">صافي الربح</h4>
                    <div class="value <?php echo $net_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>" style="font-weight:800;">
                        <?php echo formatMoney($net_profit); ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="profit-item">
                    <h4>قيمة المخزون المتبقي</h4>
                    <div class="value"><?php echo formatMoney($stock_value); ?></div>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
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
                        <?php foreach ($inventory_profit_data as $item):
                            if ($item['total_purchased'] == 0 && $item['total_sold'] == 0) continue;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td><?php echo number_format($item['total_purchased'], 2); ?></td>
                            <td><?php echo number_format($item['total_sold'], 2); ?></td>
                            <td>
                                <?php echo number_format($item['current_stock'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>
                            </td>
                            <td><?php echo formatMoney($item['total_purchase_amount']); ?></td>
                            <td><?php echo formatMoney($item['total_sales_amount']); ?></td>
                            <td><?php echo formatMoney($item['total_cost_amount']); ?></td>
                            <td class="<?php echo $item['total_profit'] >= 0 ? 'text-success' : 'text-danger'; ?> text-bold">
                                <?php echo formatMoney($item['total_profit']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
