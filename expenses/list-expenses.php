<?php
require_once '../includes/bootstrap.php';
$page_title = t('expenses_expense_log');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('expenses.view');

// تصنيفات المصاريف
$expense_categories = ['مواد التغليف', 'استخلاص أرباح', 'إيجار المحل', 'كهرباء وماء', 'نقل ونقلية', 'صيانة', 'رواتب', 'ضرائب ورسوم', 'تسويق وإعلان', 'أخرى'];

// فلاتر
$filter_category = isset($_GET['category']) ? trim($_GET['category']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$current_month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');

// حذف مصروف قبل إرسال أي محتوى HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireCSRF();
    $delete_id = intval($_POST['id'] ?? 0);
    if ($delete_id > 0) {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->execute([$delete_id]);
            header("Location: list-expenses.php?" . http_build_query(array_filter(['category' => $filter_category, 'date_from' => $filter_date_from, 'date_to' => $filter_date_to, 'month' => $current_month])));
            exit;
        } catch (Exception $e) {
            $delete_error = t('common_error_deleting');
        }
    }
}

// بناء الاستعلام
$where = [];
$params = [];

if (!empty($filter_category)) {
    $where[] = "e.category = ?";
    $params[] = $filter_category;
}

if (!empty($filter_date_from)) {
    $where[] = "e.expense_date >= ?";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where[] = "e.expense_date <= ?";
    $params[] = $filter_date_to;
}

if (!empty($current_month)) {
    $where[] = "DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
    $params[] = $current_month;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// إحصائيات
$stats_sql = "SELECT 
    COUNT(*) as total_count,
    COALESCE(SUM(amount), 0) as total_amount,
    COALESCE(AVG(amount), 0) as avg_amount
    FROM expenses e $whereClause";
$stats = fetchOne($stats_sql, $params);

// إحصائيات حسب التصنيف
$category_stats_sql = "SELECT 
    category,
    COUNT(*) as count,
    COALESCE(SUM(amount), 0) as total
    FROM expenses e $whereClause
    GROUP BY category
    ORDER BY total DESC";
$category_stats = fetchAll($category_stats_sql, $params);

// المصاريف مع الترقيم
$count_sql = "SELECT COUNT(*) as total FROM expenses e $whereClause";
$total_rows = fetchOne($count_sql, $params)['total'];

$pagination = Pagination::fromRequest($total_rows, 25);
$offset = $pagination->getOffset();
$limit = $pagination->getLimit();

$expenses_sql = "SELECT e.*, u.full_name as creator_name 
    FROM expenses e 
    LEFT JOIN users u ON e.created_by = u.id 
    $whereClause 
    ORDER BY e.expense_date DESC, e.created_at DESC 
    LIMIT $limit OFFSET $offset";
$expenses = fetchAll($expenses_sql, $params);

// إجمالي الشهر الحالي
$month_total = fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?", [date('Y-m')])['total'];

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <?php echo t('expenses_expense_log'); ?>
        </h1>
        <a href="add-expense.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 4v16m8-8H4"/></svg>
            <?php echo t('dashboard_register_expense'); ?>
        </a>
        <a href="../api/export.php?type=expenses&format=csv<?php echo !empty($filter_date_from) ? '&start_date=' . urlencode($filter_date_from) : ''; ?><?php echo !empty($filter_date_to) ? '&end_date=' . urlencode($filter_date_to) : ''; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;">
            📥 <?php echo t('reports_export_csv'); ?>
        </a>
        <a href="../api/export.php?type=expenses&format=pdf<?php echo !empty($filter_date_from) ? '&start_date=' . urlencode($filter_date_from) : ''; ?><?php echo !empty($filter_date_to) ? '&end_date=' . urlencode($filter_date_to) : ''; ?><?php echo !empty($filter_category) ? '&category=' . urlencode($filter_category) : ''; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:6px;" target="_blank">
            📄 <?php echo t('reports_export_pdf'); ?>
        </a>
    </div>

    <!-- إحصائيات سريعة -->
    <div class="stats-grid">
        <div class="stat-card red">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t('caisse_total_expenses'); ?> (<?php echo date('m/Y'); ?>)</h3>
                <div class="stat-value text-danger"><?php echo formatMoney($month_total); ?></div>
            </div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="stat-info">
                <h3>مجموع الفترة المحددة</h3>
                <div class="stat-value"><?php echo formatMoney($stats['total_amount']); ?></div>
            </div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="stat-info">
                <h3>عدد العمليات</h3>
                <div class="stat-value"><?php echo number_format($stats['total_count']); ?></div>
            </div>
        </div>
    </div>

    <!-- الفلاتر -->
    <div class="card">
        <div class="card-header">
            <h2>تصفية المصاريف</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="date-filter-grid form-grid">
                <div class="form-group">
                    <label>التصنيف</label>
                    <select name="category" class="form-control">
                        <option value="">كل التصنيفات</option>
                        <?php foreach ($expense_categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category == $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="form-group">
                    <label>إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="form-group" style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:8px;">
                    <button type="submit" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        تصفية
                    </button>
                    <a href="list-expenses.php" class="btn btn-outline">إعادة تعيين</a>
                </div>
            </form>
        </div>
    </div>

    <!-- حسب التصنيف -->
    <?php if (!empty($category_stats)): ?>
    <div class="card">
        <div class="card-header">
            <h2>تفصيل حسب التصنيف</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التصنيف</th>
                            <th>عدد العمليات</th>
                            <th>المبلغ الإجمالي</th>
                            <th>النسبة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($category_stats as $cs): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($cs['category']); ?></strong></td>
                            <td><?php echo number_format($cs['count']); ?></td>
                            <td><strong><?php echo formatMoney($cs['total']); ?></strong></td>
                            <td><?php echo $stats['total_amount'] > 0 ? round(($cs['total'] / $stats['total_amount']) * 100, 1) . '%' : '0%'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:700;">
                            <td>المجموع</td>
                            <td><?php echo number_format($stats['total_count']); ?></td>
                            <td class="text-danger"><?php echo formatMoney($stats['total_amount']); ?></td>
                            <td>100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- قائمة المصاريف -->
    <div class="card">
        <div class="card-header">
            <h2>تفاصيل المصاريف</h2>
            <span class="badge badge-info"><?php echo number_format($total_rows); ?> نتيجة</span>
        </div>
        <div class="card-body">
            <?php if (empty($expenses)): ?>
                <p class="text-muted" style="text-align:center; padding: 2rem;"><?php echo t('expenses_no_expenses_period'); ?> <a href="add-expense.php"><?php echo t('expenses_register_new'); ?></a></p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('category'); ?></th>
                            <th><?php echo t('amount'); ?></th>
                            <th><?php echo t('description'); ?></th>
                            <th><?php echo t('common_by'); ?></th>
                            <th><?php echo t('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $i => $expense): ?>
                        <tr>
                            <td><?php echo $offset + $i + 1; ?></td>
                            <td style="white-space:nowrap;"><?php echo $expense['expense_date']; ?></td>
                            <td>
                                <span class="badge badge-danger"><?php echo htmlspecialchars($expense['category']); ?></span>
                            </td>
                            <td><strong class="text-danger"><?php echo formatMoney($expense['amount']); ?></strong></td>
                            <td style="max-width:250px; white-space:pre-line; font-size:0.85rem; color:var(--gray-600);"><?php echo htmlspecialchars($expense['description'] ?? '-'); ?></td>
                            <td style="font-size:0.8rem; color:var(--gray-500);"><?php echo htmlspecialchars($expense['creator_name'] ?? '-'); ?></td>
                            <td>
                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('<?php echo t('expenses_confirm_delete'); ?>');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                                    <?php echo csrfField(); ?>
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?php echo t('delete'); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                        <?php echo t('delete'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ترقيم الصفحات -->
            <?php echo $pagination->renderInfo('مصروف'); ?>
            <?php echo $pagination->renderHtml(); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
