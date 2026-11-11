<?php
/**
 * API تصدير البيانات - CSV / PDF (طباعة)
 * 
 * المعاملات:
 *   type     - نوع البيانات: products, sales, purchases, expenses, profits
 *   format   - صيغة التصدير: csv (افتراضي), pdf
 *   start_date / end_date - فلتر التاريخ (اختياري)
 *   category_id - فلتر التصنيف للمنتجات (اختياري)
 *
 * v4.15.7: إضافة ob_start + استخدام __DIR__
 */

// تشغيل output buffering لمنع أي تحذيرات/إشعارات PHP من إفساد المخرجات
ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('export.data');

// ===== حماية CSRF — التحقق من رمز الأمان =====
$csrfToken = $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
    http_response_code(403);
    die(t('common_csrf_invalid'));
}

// === المعاملات ===
$type = strtolower(trim($_GET['type'] ?? ''));
$format = strtolower(trim($_GET['format'] ?? 'csv'));
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$category_id = intval($_GET['category_id'] ?? 0);
$category = trim($_GET['category'] ?? '');

// === الأنواع المسموحة ===
$allowed_types = ['products', 'sales', 'purchases', 'expenses', 'profits', 'profit_report'];
if (!in_array($type, $allowed_types)) {
    http_response_code(400);
    die(t('export_invalid_type') . ' ' . implode(', ', $allowed_types));
}

// === جلب البيانات حسب النوع ===
function getExportData($type, $start_date, $end_date, $category_id, $category) {
    switch ($type) {
        case 'products':
            return getProductsData($category_id);

        case 'sales':
            return getSalesData($start_date, $end_date);

        case 'purchases':
            return getPurchasesData($start_date, $end_date);

        case 'expenses':
            return getExpensesData($start_date, $end_date, $category);

        case 'profits':
            return getProfitsData($start_date, $end_date);

        case 'profit_report':
            return getProfitReportData($start_date, $end_date, $category_id);

        default:
            return [];
    }
}

// === بيانات المنتجات ===
function getProductsData($category_id) {
    $where = '';
    $params = [];

    if ($category_id > 0) {
        $where = " WHERE p.category_id = ?";
        $params[] = $category_id;
    }

    $products = [];
    try {
        $products = fetchAll(
            "SELECT p.code, p.name, c.name as category_name, 
                    p.purchase_price, p.selling_price, p.quantity, p.unit, p.min_quantity
             FROM products p 
             LEFT JOIN categories c ON p.category_id = c.id 
             $where 
             ORDER BY p.name ASC",
            $params
        );
    } catch (Exception $e) {
        try {
            $products = fetchAll(
                "SELECT p.code, p.name, NULL as category_name, 
                        p.purchase_price, p.selling_price, p.quantity, p.unit, p.min_quantity
                 FROM products p 
                 $where 
                 ORDER BY p.name ASC",
                $params
            );
        } catch (Exception $e2) {
            $products = [];
        }
    }

    $rows = [];
    foreach ($products as $p) {
        $rows[] = [
            $p['code'],
            $p['name'],
            $p['category_name'] ?? '',
            $p['purchase_price'],
            $p['selling_price'],
            $p['quantity'],
            $p['unit'],
            $p['min_quantity'],
        ];
    }

    return [
        'headers' => ['الكود', 'اسم المنتج', 'التصنيف', 'سعر الشراء', 'سعر البيع', 'الكمية', 'الوحدة', 'الحد الأدنى'],
        'rows' => $rows,
    ];
}

// === بيانات المبيعات ===
function getSalesData($start_date, $end_date) {
    $where = '';
    $params = [];

    if (!empty($start_date) && !empty($end_date)) {
        $where = " WHERE s.sale_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    } elseif (!empty($start_date)) {
        $where = " WHERE s.sale_date >= ?";
        $params[] = $start_date;
    } elseif (!empty($end_date)) {
        $where = " WHERE s.sale_date <= ?";
        $params[] = $end_date;
    }

    $sales = fetchAll(
        "SELECT s.id, s.product_id, p.code, p.name, 
                s.quantity, s.selling_price, s.total, s.cost_total, s.profit, 
                s.sale_date, s.notes
         FROM sales s 
         LEFT JOIN products p ON s.product_id = p.id 
         $where 
         ORDER BY s.sale_date DESC, s.id DESC",
        $params
    );

    $rows = [];
    foreach ($sales as $s) {
        $rows[] = [
            $s['id'],
            $s['code'] ?? '',
            $s['name'] ?? '',
            $s['quantity'],
            $s['selling_price'],
            $s['total'],
            $s['cost_total'],
            $s['profit'],
            $s['sale_date'],
            $s['notes'] ?? '',
        ];
    }

    return [
        'headers' => ['رقم', 'الكود', 'المنتج', 'الكمية', 'سعر البيع', 'الإجمالي', 'تكلفة الشراء', 'الربح', 'التاريخ', 'ملاحظات'],
        'rows' => $rows,
    ];
}

// === بيانات المشتريات ===
function getPurchasesData($start_date, $end_date) {
    $where = '';
    $params = [];

    if (!empty($start_date) && !empty($end_date)) {
        $where = " WHERE pur.purchase_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    } elseif (!empty($start_date)) {
        $where = " WHERE pur.purchase_date >= ?";
        $params[] = $start_date;
    } elseif (!empty($end_date)) {
        $where = " WHERE pur.purchase_date <= ?";
        $params[] = $end_date;
    }

    $purchases = fetchAll(
        "SELECT pur.id, p.code, p.name, 
                pur.quantity, pur.purchase_price, pur.total, 
                pur.purchase_date, pur.notes
         FROM purchases pur 
         LEFT JOIN products p ON pur.product_id = p.id 
         $where 
         ORDER BY pur.purchase_date DESC, pur.id DESC",
        $params
    );

    $rows = [];
    foreach ($purchases as $p) {
        $rows[] = [
            $p['id'],
            $p['code'] ?? '',
            $p['name'] ?? '',
            $p['quantity'],
            $p['purchase_price'],
            $p['total'],
            $p['purchase_date'],
            $p['notes'] ?? '',
        ];
    }

    return [
        'headers' => ['رقم', 'الكود', 'المنتج', 'الكمية', 'سعر الشراء', 'الإجمالي', 'التاريخ', 'ملاحظات'],
        'rows' => $rows,
    ];
}

// === بيانات المصاريف ===
function getExpensesData($start_date, $end_date, $category = '') {
    $where = '';
    $params = [];

    if (!empty($start_date) && !empty($end_date)) {
        $where = " WHERE e.expense_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    } elseif (!empty($start_date)) {
        $where = " WHERE e.expense_date >= ?";
        $params[] = $start_date;
    } elseif (!empty($end_date)) {
        $where = " WHERE e.expense_date <= ?";
        $params[] = $end_date;
    }

    if (!empty($category)) {
        if (empty($where)) {
            $where = " WHERE e.category = ?";
        } else {
            $where .= " AND e.category = ?";
        }
        $params[] = $category;
    }

    $expenses = fetchAll(
        "SELECT e.id, e.category, e.amount, e.expense_date, e.description, 
                u.full_name as creator_name
         FROM expenses e 
         LEFT JOIN users u ON e.created_by = u.id 
         $where 
         ORDER BY e.expense_date DESC, e.id DESC",
        $params
    );

    $rows = [];
    foreach ($expenses as $e) {
        $rows[] = [
            $e['id'],
            $e['category'] ?? '',
            $e['amount'],
            $e['expense_date'],
            $e['description'] ?? '',
            $e['creator_name'] ?? '',
        ];
    }

    return [
        'headers' => ['رقم', 'التصنيف', 'المبلغ', 'التاريخ', 'الوصف', 'بواسطة'],
        'rows' => $rows,
    ];
}

// === بيانات الأرباح ===
function getProfitsData($start_date, $end_date) {
    if (empty($start_date)) $start_date = date('Y-m-01');
    if (empty($end_date)) $end_date = date('Y-m-d');

    $product_profits = fetchAll(
        "SELECT p.id, p.code, p.name, p.unit, p.quantity as current_stock, 
                p.purchase_price as current_purchase_price, p.selling_price as current_selling_price,
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

    $rows = [];
    foreach ($product_profits as $pp) {
        $margin = $pp['total_sales_amount'] > 0 ? ($pp['total_profit'] / $pp['total_sales_amount']) * 100 : 0;
        $rows[] = [
            $pp['code'],
            $pp['name'],
            $pp['unit'],
            $pp['total_sold'],
            $pp['total_sales_amount'],
            $pp['total_cost_amount'],
            $pp['total_profit'],
            number_format($margin, 1) . '%',
        ];
    }

    return [
        'headers' => ['الكود', 'اسم المنتج', 'الوحدة', 'الكمية المباعة', 'إجمالي المبيعات', 'تكلفة الشراء', 'الربح', 'هامش الربح'],
        'rows' => $rows,
    ];
}

// === بيانات تقرير الأرباح المفصّل ===
function getProfitReportData($start_date, $end_date, $category_id = 0) {
    if (empty($start_date)) $start_date = date('Y-m-01');
    if (empty($end_date)) $end_date = date('Y-m-d');

    $catJoin = '';
    $catFilter = '';
    $catFilterExp = '';
    $params = [$start_date, $end_date];
    $expParams = [$start_date, $end_date];

    if ($category_id > 0) {
        $catJoin = ' JOIN products p ON p.id = s.product_id';
        $catFilter = ' AND p.category_id = ' . intval($category_id);
        $catFilterExp = ' AND p.category_id = ' . intval($category_id);
    }

    // إجمالي المبيعات والتكلفة
    $salesRow = fetchOne(
        "SELECT COALESCE(SUM(s.total), 0) as total_sales,
                COALESCE(SUM(s.cost_total), 0) as total_cogs,
                COALESCE(SUM(s.profit), 0) as gross_profit
         FROM sales s $catJoin
         WHERE s.sale_date BETWEEN ? AND ? $catFilter",
        $params
    );

    // المصاريف
    $total_expenses = 0;
    try {
        $total_expenses = floatval(fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?",
            $expParams
        )['total']);
    } catch (Exception $e) {
        ErrorHandler::logException($e, 'export::totalExpenses');
    }

    // الضرائب
    $total_taxes = 0;
    try {
        $taxRow = fetchOne(
            "SELECT COALESCE(SUM(tax_amount), 0) as total_taxes FROM purchases WHERE purchase_date BETWEEN ? AND ?",
            $params
        );
        $total_taxes = floatval($taxRow['total_taxes']);
    } catch (Exception $e) {
        ErrorHandler::logException($e, 'export::totalTaxes');
    }

    $net_profit = floatval($salesRow['gross_profit']) - $total_expenses - $total_taxes;

    // تفصيل المنتجات
    $product_profits = fetchAll(
        "SELECT p.code, p.name, p.unit,
                COALESCE(c.name, '—') as category_name,
                SUM(s.quantity) as qty_sold,
                SUM(s.total) as sales_total,
                SUM(s.cost_total) as cogs_total,
                SUM(s.profit) as profit_total
         FROM sales s
         JOIN products p ON p.id = s.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE s.sale_date BETWEEN ? AND ?
         " . ($category_id > 0 ? ' AND p.category_id = ' . intval($category_id) : '') . "
         GROUP BY s.product_id
         ORDER BY profit_total DESC",
        $params
    );

    $rows = [];
    // سطر الملخص
    $rows[] = ['إجمالي المبيعات', '', '', '', '', '', number_format(floatval($salesRow['total_sales']), 2)];
    $rows[] = ['تكلفة البضاعة المباعة', '', '', '', '', '', '-' . number_format(floatval($salesRow['total_cogs']), 2)];
    $rows[] = ['الربح الإجمالي', '', '', '', '', '', number_format(floatval($salesRow['gross_profit']), 2)];
    if ($total_expenses > 0) {
        $rows[] = ['المصاريف التشغيلية', '', '', '', '', '', '-' . number_format($total_expenses, 2)];
    }
    if ($total_taxes > 0) {
        $rows[] = ['الضرائب على المشتريات', '', '', '', '', '', '-' . number_format($total_taxes, 2)];
    }
    $rows[] = ['صافي الربح', '', '', '', '', '', number_format($net_profit, 2)];
    $rows[] = ['', '', '', '', '', '', '']; // سطر فارغ

    // تفصيل المنتجات
    foreach ($product_profits as $pp) {
        $margin = $pp['sales_total'] > 0 ? ($pp['profit_total'] / $pp['sales_total']) * 100 : 0;
        $rows[] = [
            $pp['code'],
            $pp['name'],
            $pp['category_name'],
            $pp['unit'],
            number_format($pp['qty_sold'], 2),
            number_format($margin, 1) . '%',
            number_format($pp['profit_total'], 2),
        ];
    }

    return [
        'headers' => ['البند', 'المنتج', 'التصنيف', 'الوحدة', 'الكمية', 'الهامش', 'المبلغ'],
        'rows' => $rows,
    ];
}

// === أسماء الأنواع بالعربية ===
$type_names = [
    'products' => 'المنتجات',
    'sales' => 'المبيعات',
    'purchases' => 'المشتريات',
    'expenses' => 'المصاريف',
    'profits' => 'الأرباح',
    'profit_report' => 'تقرير الأرباح المفصّل',
];

// === جلب البيانات ===
$data = getExportData($type, $start_date, $end_date, $category_id, $category);

// === تصدير CSV ===
if ($format === 'csv') {
    $filename = 'export_' . $type;
    if (!empty($start_date)) $filename .= '_' . $start_date;
    if (!empty($end_date)) $filename .= '_to_' . $end_date;
    $filename .= '_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM لـ Excel UTF-8
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // عنوان التقرير
    $title = $type_names[$type] . ' - ' . getCurrencySymbol();
    if (!empty($start_date) && !empty($end_date)) {
        $title .= ' (' . $start_date . ' إلى ' . $end_date . ')';
    }
    fputcsv($output, [$title]);
    fputcsv($output, []); // سطر فارغ

    // عناوين الأعمدة
    fputcsv($output, $data['headers']);

    // البيانات
    foreach ($data['rows'] as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

// === تصدير PDF (صفحة طباعة) ===
if ($format === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');

    $title = $type_names[$type] . ' - ' . getCurrencySymbol();
    $date_range = '';
    if (!empty($start_date) && !empty($end_date)) {
        $date_range = $start_date . ' إلى ' . $end_date;
    }
    $export_time = date('Y-m-d H:i:s');

    // تحديد الأعمدة المالية (لتنسيقها كعملة)
    $money_columns = [];
    switch ($type) {
        case 'products':
            $money_columns = [3, 4]; // سعر الشراء، سعر البيع (فهرس 0-based)
            break;
        case 'sales':
            $money_columns = [4, 5, 6, 7]; // سعر البيع، الإجمالي، تكلفة الشراء، الربح
            break;
        case 'purchases':
            $money_columns = [4, 5]; // سعر الشراء، الإجمالي
            break;
        case 'expenses':
            $money_columns = [2]; // المبلغ
            break;
        case 'profits':
            $money_columns = [4, 5, 6]; // إجمالي المبيعات، تكلفة الشراء، الربح
            break;
        case 'profit_report':
            $money_columns = [6]; // المبلغ
            break;
    }
    $money_cols_json = json_encode($money_columns);

    $row_count = count($data['rows']);
    ?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            direction: rtl;
            padding: 20px;
            color: #333;
            background: #fff;
            font-size: 12px;
        }
        .report-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #333;
        }
        .report-header h1 {
            font-size: 20px;
            margin-bottom: 5px;
        }
        .report-header .date-range {
            font-size: 13px;
            color: #666;
        }
        .report-header .export-time {
            font-size: 11px;
            color: #999;
            margin-top: 3px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px 10px;
            text-align: right;
            white-space: nowrap;
        }
        th {
            background: #2563eb;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
        }
        tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        tbody tr:hover {
            background: #eff6ff;
        }
        .money { text-align: left; direction: ltr; white-space: nowrap; }
        .text-center { text-align: center; }
        .report-footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #999;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
        @media print {
            body { padding: 10px; }
            .no-print { display: none !important; }
            @page { margin: 1.5cm; }
            th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        .btn-print {
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 10px 24px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            z-index: 100;
        }
        .btn-print:hover { background: #1d4ed8; }
        @media (max-width: 768px) {
            .btn-print {
                top: auto;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 28px;
                font-size: 15px;
                border-radius: 50px;
                box-shadow: 0 4px 16px rgba(37,99,235,0.4);
            }
        }
        @media (max-width: 480px) {
            .btn-print {
                padding: 10px 20px;
                font-size: 13px;
                bottom: 12px;
            }
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        طباعة / حفظ PDF
    </button>

    <div class="report-header">
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <?php if (!empty($date_range)): ?>
            <div class="date-range">الفترة: <?php echo htmlspecialchars($date_range); ?></div>
        <?php endif; ?>
        <div class="export-time">عدد السجلات: <?php echo number_format($row_count); ?> | تاريخ التصدير: <?php echo $export_time; ?></div>
    </div>

    <?php if (empty($data['rows'])): ?>
        <div class="no-data">لا توجد بيانات للتصدير</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <?php foreach ($data['headers'] as $header): ?>
                <th><?php echo htmlspecialchars($header); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data['rows'] as $row): ?>
            <tr>
                <?php foreach ($row as $col_idx => $cell): ?>
                <td class="<?php echo in_array($col_idx, $money_columns) ? 'money' : ''; ?>">
                    <?php 
                    if (in_array($col_idx, $money_columns)) {
                        echo number_format(floatval($cell), 2) . ' ' . getCurrencySymbol();
                    } else {
                        echo htmlspecialchars($cell ?? '');
                    }
                    ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="report-footer">
        <span><?php echo htmlspecialchars($title); ?></span>
        <span><?php echo $export_time; ?></span>
    </div>
</body>
</html>
    <?php
    exit;
}

// === صيغة غير مدعومة ===
http_response_code(400);
die(t('reports_export_invalid_format'));
