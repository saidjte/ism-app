<?php
/**
 * ===== متحكم التصدير للهاتف (Mobile Export Controller) =====
 * يوفر واجهة API لتصدير البيانات بصيغ CSV و PDF من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET  /api/mobile/export/csv/{type}  — تصدير CSV
 *   GET  /api/mobile/export/pdf/{type}  — تصدير PDF (HTML قابل للطباعة)
 *   GET  /api/mobile/export/data/{type} — تصدير بيانات JSON
 *
 * أنواع التصدير: products, sales, purchases, expenses, profits, profit_report
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف تصدير البيانات
 */

require_once __DIR__ . '/../ApiController.php';

class MobileExportController extends ApiController
{
    /** أنواع التصدير المسموحة */
    private const ALLOWED_TYPES = ['products', 'sales', 'purchases', 'expenses', 'profits', 'profit_report'];

    /**
     * تصدير البيانات بصيغة CSV
     *
     * معاملات GET:
     *   type        — نوع التصدير (مطلوب): products|sales|purchases|expenses|profits|profit_report
     *   date_from   — تاريخ البداية (اختياري)
     *   date_to     — تاريخ النهاية (اختياري)
     *   category_id — معرف التصنيف (اختياري، لـ products و profit_report)
     *
     * الاستجابة: ملف CSV مع BOM لـ UTF-8
     */
    public function csv(array $params = []): void
    {
        try {
            $type = $params['type'] ?? $this->input('type', '');
            if (!in_array($type, self::ALLOWED_TYPES)) {
                ApiResponse::validationError(['type' => 'نوع التصدير غير صالح. الأنواع المسموحة: ' . implode(', ', self::ALLOWED_TYPES)]);
                return;
            }

            $data = $this->getExportData($type);

            // توليد CSV
            $csv = $this->generateCsv($data, $type);

            // إرسال كملف
            $filename = $type . '_' . date('Y-m-d_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // BOM لـ Excel UTF-8
            echo "\xEF\xBB\xBF";
            echo $csv;
            exit;

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileExportController::csv');
            ApiResponse::serverError('حدث خطأ أثناء تصدير البيانات');
        }
    }

    /**
     * تصدير البيانات كـ PDF (HTML قابل للطباعة)
     *
     * نفس معاملات csv
     *
     * الاستجابة: HTML منسق للطباعة
     */
    public function pdf(array $params = []): void
    {
        try {
            $type = $params['type'] ?? $this->input('type', '');
            if (!in_array($type, self::ALLOWED_TYPES)) {
                ApiResponse::validationError(['type' => 'نوع التصدير غير صالح']);
                return;
            }

            $data = $this->getExportData($type);
            $currency = function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.ج';
            $storeName = function_exists('getSetting') ? (getSetting('store_name', '') ?? '') : '';

            $dateFrom = $this->input('date_from', '');
            $dateTo = $this->input('date_to', '');
            $title = $this->getTypeTitle($type);
            $dateRange = '';
            if (!empty($dateFrom) || !empty($dateTo)) {
                $dateRange = ($dateFrom ?: '...') . ' → ' . ($dateTo ?: '...');
            }

            // توليد HTML
            $html = $this->generatePdfHtml($data, $type, $title, $storeName, $currency, $dateRange);

            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileExportController::pdf');
            ApiResponse::serverError('حدث خطأ أثناء تصدير البيانات');
        }
    }

    /**
     * تصدير البيانات بصيغة JSON
     *
     * نفس معاملات csv
     *
     * الاستجابة: JSON { success, data: { type, date_range, rows, summary } }
     */
    public function data(array $params = []): void
    {
        try {
            $type = $params['type'] ?? $this->input('type', '');
            if (!in_array($type, self::ALLOWED_TYPES)) {
                ApiResponse::validationError(['type' => 'نوع التصدير غير صالح']);
                return;
            }

            $data = $this->getExportData($type);

            ApiResponse::success([
                'type' => $type,
                'date_from' => $this->input('date_from', ''),
                'date_to' => $this->input('date_to', ''),
                'rows' => $data['rows'],
                'summary' => $data['summary'] ?? [],
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileExportController::data');
            ApiResponse::serverError('حدث خطأ أثناء تصدير البيانات');
        }
    }

    /**
     * جلب بيانات التصدير حسب النوع
     */
    private function getExportData(string $type): array
    {
        $dateFrom = trim($this->input('date_from', ''));
        $dateTo = trim($this->input('date_to', ''));
        $categoryId = intval($this->input('category_id', 0));

        return match ($type) {
            'products' => $this->getProductsData($categoryId),
            'sales' => $this->getSalesData($dateFrom, $dateTo),
            'purchases' => $this->getPurchasesData($dateFrom, $dateTo),
            'expenses' => $this->getExpensesData($dateFrom, $dateTo),
            'profits' => $this->getProfitsData($dateFrom, $dateTo),
            'profit_report' => $this->getProfitReportData($dateFrom, $dateTo, $categoryId),
            default => ['rows' => [], 'summary' => []],
        };
    }

    /**
     * بيانات المنتجات
     */
    private function getProductsData(int $categoryId): array
    {
        $sql = "SELECT p.id, p.code, p.name, c.name as category, p.purchase_price, p.selling_price,
                       p.unit, p.quantity, p.min_quantity
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id";
        $bindings = [];

        if ($categoryId > 0) {
            $sql .= " WHERE p.category_id = ?";
            $bindings[] = $categoryId;
        }

        $sql .= " ORDER BY p.name ASC";

        $rows = fetchAll($sql, $bindings);
        if (!is_array($rows)) $rows = [];

        $totalValue = 0;
        $totalValueRetail = 0;
        foreach ($rows as $row) {
            $totalValue += floatval($row['quantity']) * floatval($row['purchase_price']);
            $totalValueRetail += floatval($row['quantity']) * floatval($row['selling_price']);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_products' => count($rows),
                'total_stock_value' => round($totalValue, 2),
                'total_stock_value_retail' => round($totalValueRetail, 2),
            ],
        ];
    }

    /**
     * بيانات المبيعات
     */
    private function getSalesData(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT s.id, p.name as product, p.code as product_code, s.quantity, s.selling_price,
                       s.total, s.cost_total, s.profit, s.sale_date, s.notes
                FROM sales s
                JOIN products p ON s.product_id = p.id
                WHERE 1=1";
        $bindings = [];

        if (!empty($dateFrom)) {
            $sql .= " AND s.sale_date >= ?";
            $bindings[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $sql .= " AND s.sale_date <= ?";
            $bindings[] = $dateTo;
        }

        $sql .= " ORDER BY s.sale_date DESC, s.id DESC";

        $rows = fetchAll($sql, $bindings);
        if (!is_array($rows)) $rows = [];

        $totalSales = 0;
        $totalCost = 0;
        $totalProfit = 0;
        foreach ($rows as $row) {
            $totalSales += floatval($row['total']);
            $totalCost += floatval($row['cost_total']);
            $totalProfit += floatval($row['profit']);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_transactions' => count($rows),
                'total_sales' => round($totalSales, 2),
                'total_cost' => round($totalCost, 2),
                'total_profit' => round($totalProfit, 2),
            ],
        ];
    }

    /**
     * بيانات المشتريات
     */
    private function getPurchasesData(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT pu.id, p.name as product, p.code as product_code, pu.quantity,
                       pu.purchase_price, pu.total, pu.purchase_date, pu.notes
                FROM purchases pu
                JOIN products p ON pu.product_id = p.id
                WHERE 1=1";
        $bindings = [];

        if (!empty($dateFrom)) {
            $sql .= " AND pu.purchase_date >= ?";
            $bindings[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $sql .= " AND pu.purchase_date <= ?";
            $bindings[] = $dateTo;
        }

        $sql .= " ORDER BY pu.purchase_date DESC, pu.id DESC";

        $rows = fetchAll($sql, $bindings);
        if (!is_array($rows)) $rows = [];

        $totalPurchases = 0;
        $totalQty = 0;
        foreach ($rows as $row) {
            $totalPurchases += floatval($row['total']);
            $totalQty += floatval($row['quantity']);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_transactions' => count($rows),
                'total_purchases' => round($totalPurchases, 2),
                'total_quantity' => $totalQty,
            ],
        ];
    }

    /**
     * بيانات المصروفات
     */
    private function getExpensesData(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT e.id, e.category, e.amount, e.description, e.expense_date,
                       u.full_name as created_by_name
                FROM expenses e
                LEFT JOIN users u ON e.created_by = u.id
                WHERE 1=1";
        $bindings = [];

        if (!empty($dateFrom)) {
            $sql .= " AND e.expense_date >= ?";
            $bindings[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $sql .= " AND e.expense_date <= ?";
            $bindings[] = $dateTo;
        }

        $sql .= " ORDER BY e.expense_date DESC, e.id DESC";

        $rows = fetchAll($sql, $bindings);
        if (!is_array($rows)) $rows = [];

        $totalExpenses = 0;
        foreach ($rows as $row) {
            $totalExpenses += floatval($row['amount']);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_expenses' => count($rows),
                'total_amount' => round($totalExpenses, 2),
            ],
        ];
    }

    /**
     * بيانات الأرباح لكل منتج
     */
    private function getProfitsData(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT p.id, p.name, p.code, p.unit,
                       SUM(s.quantity) as qty_sold,
                       SUM(s.total) as sales_total,
                       SUM(s.cost_total) as cost_total,
                       SUM(s.profit) as profit
                FROM sales s
                JOIN products p ON s.product_id = p.id
                WHERE 1=1";
        $bindings = [];

        if (!empty($dateFrom)) {
            $sql .= " AND s.sale_date >= ?";
            $bindings[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $sql .= " AND s.sale_date <= ?";
            $bindings[] = $dateTo;
        }

        $sql .= " GROUP BY p.id HAVING qty_sold > 0 ORDER BY profit DESC";

        $rows = fetchAll($sql, $bindings);
        if (!is_array($rows)) $rows = [];

        $totalSales = 0;
        $totalCost = 0;
        $totalProfit = 0;
        foreach ($rows as $row) {
            $totalSales += floatval($row['sales_total']);
            $totalCost += floatval($row['cost_total']);
            $totalProfit += floatval($row['profit']);
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total_sales' => round($totalSales, 2),
                'total_cost' => round($totalCost, 2),
                'total_profit' => round($totalProfit, 2),
                'profit_margin' => $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * تقرير الأرباح التفصيلي (P&L)
     */
    private function getProfitReportData(string $dateFrom, string $dateTo, int $categoryId): array
    {
        $profitsData = $this->getProfitsData($dateFrom, $dateTo);

        // جلب المصروفات
        $expensesData = $this->getExpensesData($dateFrom, $dateTo);

        // جلب الضرائب
        $taxTotal = 0;
        try {
            $taxSql = "SELECT SUM(tax_amount) as total_tax FROM purchases WHERE 1=1";
            $taxBindings = [];
            if (!empty($dateFrom)) {
                $taxSql .= " AND purchase_date >= ?";
                $taxBindings[] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $taxSql .= " AND purchase_date <= ?";
                $taxBindings[] = $dateTo;
            }
            $taxRow = fetchOne($taxSql, $taxBindings);
            $taxTotal = floatval($taxRow['total_tax'] ?? 0);
        } catch (\Throwable $e) {}

        $totalSales = floatval($profitsData['summary']['total_sales'] ?? 0);
        $totalCost = floatval($profitsData['summary']['total_cost'] ?? 0);
        $grossProfit = floatval($profitsData['summary']['total_profit'] ?? 0);
        $totalExpenses = floatval($expensesData['summary']['total_amount'] ?? 0);
        $netProfit = $grossProfit - $totalExpenses - $taxTotal;

        return [
            'rows' => $profitsData['rows'],
            'summary' => [
                'total_sales' => round($totalSales, 2),
                'total_cost' => round($totalCost, 2),
                'gross_profit' => round($grossProfit, 2),
                'total_expenses' => round($totalExpenses, 2),
                'total_taxes' => round($taxTotal, 2),
                'net_profit' => round($netProfit, 2),
                'profit_margin' => $totalSales > 0 ? round(($netProfit / $totalSales) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * توليد سلسلة CSV من البيانات
     */
    private function generateCsv(array $data, string $type): string
    {
        $rows = $data['rows'];
        if (empty($rows)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // صف العناوين
        $headers = array_keys($rows[0]);
        fputcsv($output, $headers);

        // صفوف البيانات
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * توليد HTML للطباعة
     */
    private function generatePdfHtml(array $data, string $type, string $title, string $storeName, string $currency, string $dateRange): string
    {
        $rows = $data['rows'];
        $summary = $data['summary'] ?? [];

        ob_start();
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <title><?php echo htmlspecialchars($title); ?></title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 12px; direction: rtl; margin: 20px; }
                h1 { text-align: center; color: #333; margin-bottom: 5px; }
                .date-range { text-align: center; color: #666; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: right; }
                th { background: #4a90d9; color: white; font-weight: bold; }
                tr:nth-child(even) { background: #f9f9f9; }
                .summary { background: #f0f0f0; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
                .summary span { display: inline-block; margin-left: 20px; }
                .money { text-align: left; }
                @media print { body { margin: 0; } }
            </style>
        </head>
        <body>
            <h1><?php echo htmlspecialchars($storeName ?: $title); ?></h1>
            <?php if (!empty($dateRange)): ?>
                <div class="date-range"><?php echo htmlspecialchars($dateRange); ?></div>
            <?php endif; ?>

            <?php if (!empty($summary)): ?>
                <div class="summary">
                    <?php foreach ($summary as $key => $value): ?>
                        <span><strong><?php echo htmlspecialchars($key); ?>:</strong> <?php echo htmlspecialchars(strval($value)); ?> <?php echo htmlspecialchars($currency); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($rows)): ?>
                <table>
                    <thead>
                        <tr>
                            <?php foreach (array_keys($rows[0]) as $header): ?>
                                <th><?php echo htmlspecialchars($header); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?php echo htmlspecialchars(strval($cell ?? '')); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #999;">لا توجد بيانات للتصدير</p>
            <?php endif; ?>

            <script>window.print();</script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * عنوان نوع التصدير
     */
    private function getTypeTitle(string $type): string
    {
        return match ($type) {
            'products' => 'تقرير المنتجات',
            'sales' => 'تقرير المبيعات',
            'purchases' => 'تقرير المشتريات',
            'expenses' => 'تقرير المصروفات',
            'profits' => 'تقرير الأرباح',
            'profit_report' => 'تقرير الأرباح والخسائر',
            default => 'تقرير',
        };
    }
}
