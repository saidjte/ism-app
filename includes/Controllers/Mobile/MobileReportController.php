<?php
/**
 * ===== متحكم التقارير للهاتف (Mobile Report Controller) =====
 * يوفر واجهة API للتقارير ولوحة المعلومات من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET /api/mobile/reports/dashboard    — ملخص لوحة المعلومات
 *   GET /api/mobile/reports/profits      — تقرير الأرباح
 *   GET /api/mobile/reports/stock        — تقرير المخزون
 *   GET /api/mobile/reports/expiry       — تقرير الانتهاء
 *   GET /api/mobile/reports/categories   — قائمة التصنيفات مع عدد المنتجات
 *   GET /api/mobile/reports/sales        — تقرير المبيعات التفصيلي
 *   GET /api/mobile/reports/purchases    — تقرير المشتريات التفصيلي
 *   GET /api/mobile/reports/comparison   — مقارنة الفترات
 *
 * v4.34.0: إنشاء طبقة API للهاتف
 * v5.13.0: إضافة تقرير المبيعات + المشتريات + مقارنة الفترات
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Services/SaleService.php';

class MobileReportController extends ApiController
{
    /**
     * ملخص لوحة المعلومات
     *
     * يُرجع إحصائيات عامة عن النظام:
     *   total_products     — إجمالي المنتجات
     *   total_sales_today  — إجمالي المبيعات اليوم
     *   total_sales_month  — إجمالي مبيعات الشهر الحالي
     *   low_stock_count    — عدد المنتجات منخفضة المخزون
     *   total_stock_value  — القيمة الإجمالية للمخزون
     *   total_categories   — إجمالي التصنيفات
     *   profit_today       — صافي ربح اليوم (من SaleService — مُوحَّد)
     *
     * v5.6.0: استخدام SaleService::calculateProfits() لحساب الأرباح
     *
     * الاستجابة:
     *   { success, data: { total_products, total_sales_today, ... } }
     */
    public function dashboard(array $params = []): void
    {
        try {
            $today = date('Y-m-d');
            $monthStart = date('Y-m-01');

            // إجمالي المنتجات
            $productCount = fetchOne("SELECT COUNT(*) as total FROM products");
            $totalProducts = intval($productCount['total'] ?? 0);

            // عدد المنتجات منخفضة المخزون
            $lowStockCount = fetchOne(
                "SELECT COUNT(*) as total FROM products WHERE quantity <= min_quantity"
            );
            $lowStockCountVal = intval($lowStockCount['total'] ?? 0);

            // إجمالي التصنيفات
            $categoryCount = fetchOne("SELECT COUNT(*) as total FROM categories");
            $totalCategories = intval($categoryCount['total'] ?? 0);

            // عدد عمليات البيع اليوم
            $salesCountToday = fetchOne(
                "SELECT COUNT(*) as total FROM sales WHERE sale_date = ?",
                [$today]
            );
            $salesCountTodayVal = intval($salesCountToday['total'] ?? 0);

            // ✅ استخدام SaleService لحساب أرباح اليوم (مُوحَّد مع صفحة الأرباح)
            $todayProfits = SaleService::calculateProfits($today, $today, 100, false);
            $totalSalesToday = $todayProfits['total_sales'];
            $totalProfitToday = $todayProfits['net_profit'];

            // ✅ استخدام SaleService لحساب مبيعات وأرباح الشهر
            $monthEnd = date('Y-m-t');
            $monthProfits = SaleService::calculateProfits($monthStart, $monthEnd, 100, false);
            $totalSalesMonth = $monthProfits['total_sales'];

            // قيمة المخزون من SaleService (تدعم دفعات الشراء مع تراجع)
            $stockValue = $todayProfits['stock_value'] ?? 0;

            ApiResponse::success([
                'total_products'     => $totalProducts,
                'total_sales_today'  => $totalSalesToday,
                'total_sales_month'  => $totalSalesMonth,
                'low_stock_count'    => $lowStockCountVal,
                'total_stock_value'  => $stockValue,
                'total_categories'   => $totalCategories,
                'sales_count_today'  => $salesCountTodayVal,
                'profit_today'       => $totalProfitToday,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::dashboard');
            ApiResponse::serverError('حدث خطأ أثناء جلب بيانات لوحة المعلومات');
        }
    }

    /**
     * تقرير الأرباح
     *
     * معاملات GET:
     *   date_from — تاريخ البداية (مطلوب، Y-m-d)
     *   date_to   — تاريخ النهاية (مطلوب، Y-m-d)
     *
     * يستخدم SaleService::calculateProfits كطبقة منطق تجاري
     *
     * الاستجابة:
     *   { success, data: { total_sales, total_cost, gross_profit, net_profit, ... } }
     */
    public function profits(array $params = []): void
    {
        try {
            $dateFrom = trim($this->input('date_from', ''));
            $dateTo = trim($this->input('date_to', ''));

            // التحقق من التواريخ المطلوبة
            if (empty($dateFrom) || empty($dateTo)) {
                ApiResponse::validationError([
                    'date_from' => empty($dateFrom) ? 'تاريخ البداية مطلوب' : '',
                    'date_to'   => empty($dateTo) ? 'تاريخ النهاية مطلوب' : '',
                ]);
                return;
            }

            // التحقق من صحة التواريخ
            if (strtotime($dateFrom) === false || strtotime($dateTo) === false) {
                ApiResponse::validationError(['date' => 'صيغة التاريخ غير صالحة']);
                return;
            }

            // تفويض الحساب إلى SaleService
            $result = SaleService::calculateProfits($dateFrom, $dateTo);

            ApiResponse::success($result);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::profits');
            ApiResponse::serverError('حدث خطأ أثناء حساب الأرباح');
        }
    }

    /**
     * تقرير المخزون
     *
     * معاملات GET:
     *   category_id    — معرف التصنيف (اختياري)
     *   low_stock_only — عرض المنتجات منخفضة المخزون فقط (اختياري، bool)
     *   page           — رقم الصفحة (افتراضي: 1)
     *   per_page       — عدد العناصر في الصفحة (افتراضي: 20)
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function stockReport(array $params = []): void
    {
        try {
            $categoryId = intval($this->input('category_id', 0));
            $lowStockOnly = filter_var($this->input('low_stock_only', false), FILTER_VALIDATE_BOOL);
            $pagination = $this->getPaginationParams(20);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            // بناء الاستعلام
            $sql = "SELECT p.id, p.code, p.name, p.unit, p.quantity, p.min_quantity,
                           p.purchase_price, p.selling_price,
                           (p.quantity * p.purchase_price) as stock_value,
                           c.name as category_name,
                           CASE WHEN p.quantity <= p.min_quantity THEN 1 ELSE 0 END as is_low_stock
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total
                         FROM products p
                         LEFT JOIN categories c ON p.category_id = c.id
                         WHERE 1=1";

            $bindings = [];
            $conditions = '';

            // تصفية حسب التصنيف
            if ($categoryId > 0) {
                $conditions .= " AND p.category_id = ?";
                $bindings[] = $categoryId;
            }

            // تصفية المنتجات منخفضة المخزون
            if ($lowStockOnly) {
                $conditions .= " AND p.quantity <= p.min_quantity";
            }

            $sql .= $conditions;
            $countSql .= $conditions;

            // ترتيب: المنتجات منخفضة المخزون أولاً، ثم بالاسم
            $sql .= " ORDER BY is_low_stock DESC, p.name ASC";

            // إضافة الحد والإزاحة
            $sql .= " LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            // تنفيذ استعلام العدد
            $countBindings = array_slice($bindings, 0, -2);
            $countRow = fetchOne($countSql, $countBindings);
            $total = intval($countRow['total'] ?? 0);

            // تنفيذ استعلام البيانات
            $products = fetchAll($sql, $bindings);

            if (!is_array($products)) {
                $products = [];
            }

            // حساب ملخص المخزون
            $summary = [
                'total_items'    => $total,
                'low_stock_count' => 0,
                'total_value'    => 0,
            ];

            try {
                $summaryRow = fetchOne(
                    "SELECT COUNT(*) as low_count,
                            COALESCE(SUM(quantity * purchase_price), 0) as total_val
                     FROM products"
                );
                $summary['low_stock_count'] = intval(fetchOne(
                    "SELECT COUNT(*) as total FROM products WHERE quantity <= min_quantity"
                )['total'] ?? 0);
                $summary['total_value'] = floatval($summaryRow['total_val'] ?? 0);
            } catch (\Throwable $e) {
                // تجاهل الخطأ
            }

            ApiResponse::paginated($products, $total, $page, $perPage);

            // v5.11.2: إرجاع الملخص أيضاً — كان محسوباً لكن لم يُرجع
            // ملاحظة: paginated() يستدعي exit، لذا نستخدم custom() بدلاً من ذلك
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::stockReport');
            ApiResponse::serverError('حدث خطأ أثناء جلب تقرير المخزون');
        }
    }

    /**
     * تقرير المنتجات المنتهية الصلاحية أو القريبة الانتهاء
     *
     * معاملات GET:
     *   days_ahead — عدد الأيام القادمة للفحص (افتراضي: 30)
     *   page       — رقم الصفحة (افتراضي: 1)
     *   per_page   — عدد العناصر في الصفحة (افتراضي: 20)
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function expiryReport(array $params = []): void
    {
        try {
            $daysAhead = max(1, intval($this->input('days_ahead', 30)));
            $pagination = $this->getPaginationParams(20);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            // Expiry batch tracking has been removed. Return an empty expiry report.
            $batches = [];
            $total = 0;
            ApiResponse::paginated($batches, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::expiryReport');
            ApiResponse::serverError('حدث خطأ أثناء جلب تقرير الانتهاء');
        }
    }

    /**
     * قائمة التصنيفات مع عدد المنتجات لكل تصنيف
     *
     * الاستجابة:
     *   { success, data: [{ id, name, color, product_count, ... }] }
     */
    public function categories(array $params = []): void
    {
        try {
            $categories = fetchAll(
                "SELECT c.*, COUNT(p.id) as product_count
                 FROM categories c
                 LEFT JOIN products p ON c.id = p.category_id
                 GROUP BY c.id
                 ORDER BY c.name ASC"
            );

            if (!is_array($categories)) {
                $categories = [];
            }

            // إضافة ملخص إضافي لكل تصنيف
            foreach ($categories as &$category) {
                $categoryId = intval($category['id']);

                // إجمالي قيمة المخزون في التصنيف
                try {
                    $stockValue = fetchOne(
                        "SELECT COALESCE(SUM(quantity * purchase_price), 0) as total
                         FROM products WHERE category_id = ?",
                        [$categoryId]
                    );
                    $category['stock_value'] = floatval($stockValue['total'] ?? 0);
                } catch (\Throwable $e) {
                    $category['stock_value'] = 0;
                }

                // عدد المنتجات منخفضة المخزون في التصنيف
                try {
                    $lowStock = fetchOne(
                        "SELECT COUNT(*) as total
                         FROM products WHERE category_id = ? AND quantity <= min_quantity",
                        [$categoryId]
                    );
                    $category['low_stock_count'] = intval($lowStock['total'] ?? 0);
                } catch (\Throwable $e) {
                    $category['low_stock_count'] = 0;
                }

                // تأكد أن product_count عدد صحيح
                $category['product_count'] = intval($category['product_count'] ?? 0);
            }
            unset($category);

            ApiResponse::success($categories);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::categories');
            ApiResponse::serverError('حدث خطأ أثناء جلب التصنيفات');
        }
    }

    /**
     * تقرير المبيعات التفصيلي
     *
     * معاملات GET:
     *   date_from   — تاريخ البداية (اختياري، افتراضي: بداية الشهر)
     *   date_to     — تاريخ النهاية (اختياري، افتراضي: اليوم)
     *   category_id — معرف التصنيف (اختياري)
     *   search      — بحث بالمنتج (اختياري)
     *
     * الاستجابة:
     *   { success, data: { summary, by_product, by_category, daily_trend, top_products } }
     */
    public function sales(array $params = []): void
    {
        try {
            $dateFrom = trim($this->input('date_from', date('Y-m-01')));
            $dateTo = trim($this->input('date_to', date('Y-m-d')));
            $categoryId = intval($this->input('category_id', 0));
            $search = trim($this->input('search', ''));

            // ملخص المبيعات
            $summarySql = "SELECT COUNT(*) as total_transactions,
                                  COALESCE(SUM(s.quantity), 0) as products_sold,
                                  COALESCE(SUM(s.total), 0) as total_sales,
                                  COALESCE(SUM(s.cost_total), 0) as total_cost,
                                  COALESCE(SUM(s.profit), 0) as total_profit
                           FROM sales s WHERE s.sale_date >= ? AND s.sale_date <= ?";
            $summary = fetchOne($summarySql, [$dateFrom, $dateTo]);
            $totalSales = floatval($summary['total_sales'] ?? 0);
            $summary['profit_margin'] = $totalSales > 0
                ? round((floatval($summary['total_profit'] ?? 0) / $totalSales) * 100, 2)
                : 0;

            // حسب المنتج
            $byProductSql = "SELECT p.id, p.name, p.code, p.unit, c.name as category_name,
                                    SUM(s.quantity) as qty_sold,
                                    SUM(s.total) as sales_total,
                                    SUM(s.cost_total) as cost_total,
                                    SUM(s.profit) as profit,
                                    COUNT(*) as transactions
                             FROM sales s
                             JOIN products p ON s.product_id = p.id
                             LEFT JOIN categories c ON p.category_id = c.id
                             WHERE s.sale_date >= ? AND s.sale_date <= ?";

            $byProductBindings = [$dateFrom, $dateTo];

            if ($categoryId > 0) {
                $byProductSql .= " AND p.category_id = ?";
                $byProductBindings[] = $categoryId;
            }
            if (!empty($search)) {
                $byProductSql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
                $byProductBindings[] = "%{$search}%";
                $byProductBindings[] = "%{$search}%";
            }

            $byProductSql .= " GROUP BY p.id ORDER BY profit DESC LIMIT 50";
            $byProduct = fetchAll($byProductSql, $byProductBindings);
            if (!is_array($byProduct)) $byProduct = [];

            // حسب التصنيف
            $byCategory = fetchAll(
                "SELECT c.id, c.name, c.color,
                        COUNT(DISTINCT p.id) as products_count,
                        SUM(s.quantity) as qty_sold,
                        SUM(s.total) as sales_total,
                        SUM(s.cost_total) as cost_total,
                        SUM(s.profit) as profit
                 FROM sales s
                 JOIN products p ON s.product_id = p.id
                 LEFT JOIN categories c ON p.category_id = c.id
                 WHERE s.sale_date >= ? AND s.sale_date <= ?
                 GROUP BY c.id
                 ORDER BY profit DESC",
                [$dateFrom, $dateTo]
            );
            if (!is_array($byCategory)) $byCategory = [];

            // الاتجاه اليومي
            $dailyTrend = fetchAll(
                "SELECT DATE(s.sale_date) as date,
                        SUM(s.quantity) as qty,
                        SUM(s.total) as sales,
                        SUM(s.cost_total) as cost,
                        SUM(s.profit) as profit,
                        COUNT(*) as transactions
                 FROM sales s
                 WHERE s.sale_date >= ? AND s.sale_date <= ?
                 GROUP BY DATE(s.sale_date)
                 ORDER BY date ASC",
                [$dateFrom, $dateTo]
            );
            if (!is_array($dailyTrend)) $dailyTrend = [];

            // أفضل 10 منتجات
            $topProducts = fetchAll(
                "SELECT p.name, SUM(s.total) as revenue, SUM(s.profit) as profit, SUM(s.quantity) as qty
                 FROM sales s JOIN products p ON s.product_id = p.id
                 WHERE s.sale_date >= ? AND s.sale_date <= ?
                 GROUP BY p.id ORDER BY revenue DESC LIMIT 10",
                [$dateFrom, $dateTo]
            );
            if (!is_array($topProducts)) $topProducts = [];

            ApiResponse::success([
                'summary' => $summary,
                'by_product' => $byProduct,
                'by_category' => $byCategory,
                'daily_trend' => $dailyTrend,
                'top_products' => $topProducts,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::sales');
            ApiResponse::serverError('حدث خطأ أثناء جلب تقرير المبيعات');
        }
    }

    /**
     * تقرير المشتريات التفصيلي
     *
     * معاملات GET:
     *   date_from   — تاريخ البداية (اختياري)
     *   date_to     — تاريخ النهاية (اختياري)
     *   category_id — معرف التصنيف (اختياري)
     *   search      — بحث بالمنتج (اختياري)
     *
     * الاستجابة:
     *   { success, data: { summary, by_product, by_category, daily_trend, top_products, price_changes } }
     */
    public function purchases(array $params = []): void
    {
        try {
            $dateFrom = trim($this->input('date_from', date('Y-m-01')));
            $dateTo = trim($this->input('date_to', date('Y-m-d')));
            $categoryId = intval($this->input('category_id', 0));
            $search = trim($this->input('search', ''));

            // ملخص المشتريات
            $summary = fetchOne(
                "SELECT COUNT(*) as total_transactions,
                        COALESCE(SUM(pu.quantity), 0) as products_purchased,
                        COALESCE(SUM(pu.total), 0) as total_purchases,
                        COALESCE(AVG(pu.purchase_price), 0) as avg_price
                 FROM purchases pu WHERE pu.purchase_date >= ? AND pu.purchase_date <= ?",
                [$dateFrom, $dateTo]
            );

            // حسب المنتج
            $byProductSql = "SELECT pr.id, pr.name, pr.code, pr.unit, c.name as category_name,
                                    SUM(pu.quantity) as qty_purchased,
                                    SUM(pu.total) as purchase_total,
                                    AVG(pu.purchase_price) as avg_price,
                                    MIN(pu.purchase_price) as min_price,
                                    MAX(pu.purchase_price) as max_price,
                                    COUNT(*) as transactions
                             FROM purchases pu
                             JOIN products pr ON pu.product_id = pr.id
                             LEFT JOIN categories c ON pr.category_id = c.id
                             WHERE pu.purchase_date >= ? AND pu.purchase_date <= ?";

            $byProductBindings = [$dateFrom, $dateTo];

            if ($categoryId > 0) {
                $byProductSql .= " AND pr.category_id = ?";
                $byProductBindings[] = $categoryId;
            }
            if (!empty($search)) {
                $byProductSql .= " AND (pr.name LIKE ? OR pr.code LIKE ?)";
                $byProductBindings[] = "%{$search}%";
                $byProductBindings[] = "%{$search}%";
            }

            $byProductSql .= " GROUP BY pr.id ORDER BY purchase_total DESC LIMIT 50";
            $byProduct = fetchAll($byProductSql, $byProductBindings);
            if (!is_array($byProduct)) $byProduct = [];

            // حسب التصنيف
            $byCategory = fetchAll(
                "SELECT c.id, c.name, c.color,
                        COUNT(DISTINCT pr.id) as products_count,
                        SUM(pu.quantity) as qty_purchased,
                        SUM(pu.total) as purchase_total,
                        AVG(pu.purchase_price) as avg_price
                 FROM purchases pu
                 JOIN products pr ON pu.product_id = pr.id
                 LEFT JOIN categories c ON pr.category_id = c.id
                 WHERE pu.purchase_date >= ? AND pu.purchase_date <= ?
                 GROUP BY c.id ORDER BY purchase_total DESC",
                [$dateFrom, $dateTo]
            );
            if (!is_array($byCategory)) $byCategory = [];

            // الاتجاه اليومي
            $dailyTrend = fetchAll(
                "SELECT DATE(pu.purchase_date) as date,
                        SUM(pu.quantity) as qty,
                        SUM(pu.total) as total,
                        COUNT(*) as transactions
                 FROM purchases pu
                 WHERE pu.purchase_date >= ? AND pu.purchase_date <= ?
                 GROUP BY DATE(pu.purchase_date) ORDER BY date ASC",
                [$dateFrom, $dateTo]
            );
            if (!is_array($dailyTrend)) $dailyTrend = [];

            // أفضل 10 منتجات
            $topProducts = fetchAll(
                "SELECT pr.name, SUM(pu.total) as spending, SUM(pu.quantity) as qty
                 FROM purchases pu JOIN products pr ON pu.product_id = pr.id
                 WHERE pu.purchase_date >= ? AND pu.purchase_date <= ?
                 GROUP BY pr.id ORDER BY spending DESC LIMIT 10",
                [$dateFrom, $dateTo]
            );
            if (!is_array($topProducts)) $topProducts = [];

            // تغيرات الأسعار
            $priceChanges = [];
            try {
                $priceChanges = fetchAll(
                    "SELECT ph.*, pr.name as product_name, pr.code as product_code
                     FROM price_history ph
                     JOIN products pr ON ph.product_id = pr.id
                     WHERE DATE(ph.changed_at) >= ? AND DATE(ph.changed_at) <= ?
                       AND ph.old_purchase_price != ph.new_purchase_price
                     ORDER BY ph.changed_at DESC LIMIT 50",
                    [$dateFrom, $dateTo]
                );
                if (!is_array($priceChanges)) $priceChanges = [];
            } catch (\Throwable $e) {}

            ApiResponse::success([
                'summary' => $summary,
                'by_product' => $byProduct,
                'by_category' => $byCategory,
                'daily_trend' => $dailyTrend,
                'top_products' => $topProducts,
                'price_changes' => $priceChanges,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::purchases');
            ApiResponse::serverError('حدث خطأ أثناء جلب تقرير المشتريات');
        }
    }

    /**
     * مقارنة الفترات
     *
     * معاملات GET:
     *   mode      — نوع المقارنة: custom|month|quarter|year (افتراضي: month)
     *   date_from_1 — بداية الفترة 1 (لـ custom)
     *   date_to_1   — نهاية الفترة 1 (لـ custom)
     *   date_from_2 — بداية الفترة 2 (لـ custom)
     *   date_to_2   — نهاية الفترة 2 (لـ custom)
     *   p1_month    — شهر الفترة 1 بتنسيق Y-m (لـ month)
     *   p2_month    — شهر الفترة 2 بتنسيق Y-m (لـ month)
     *   p1_quarter  — ربع الفترة 1: 1-4 (لـ quarter)
     *   p1_year     — سنة الفترة 1 (لـ quarter/year)
     *
     * الاستجابة:
     *   { success, data: { period1, period2, comparison, metrics } }
     */
    public function comparison(array $params = []): void
    {
        try {
            $mode = trim($this->input('mode', 'month'));

            // حساب فترات المقارنة
            $period1 = ['from' => '', 'to' => ''];
            $period2 = ['from' => '', 'to' => ''];

            switch ($mode) {
                case 'custom':
                    $period1['from'] = trim($this->input('date_from_1', ''));
                    $period1['to'] = trim($this->input('date_to_1', ''));
                    $period2['from'] = trim($this->input('date_from_2', ''));
                    $period2['to'] = trim($this->input('date_to_2', ''));
                    break;

                case 'quarter':
                    $quarter = intval($this->input('p1_quarter', ceil(date('n') / 3)));
                    $year = intval($this->input('p1_year', date('Y')));
                    $quarter = max(1, min(4, $quarter));

                    $period1['from'] = sprintf('%d-%02d-01', $year, ($quarter - 1) * 3 + 1);
                    $period1['to'] = date('Y-m-t', strtotime($year . '-' . ($quarter * 3) . '-01'));

                    // الربع السابق
                    $prevQuarter = $quarter - 1;
                    $prevYear = $year;
                    if ($prevQuarter < 1) {
                        $prevQuarter = 4;
                        $prevYear = $year - 1;
                    }
                    $period2['from'] = sprintf('%d-%02d-01', $prevYear, ($prevQuarter - 1) * 3 + 1);
                    $period2['to'] = date('Y-m-t', strtotime($prevYear . '-' . ($prevQuarter * 3) . '-01'));
                    break;

                case 'year':
                    $year = intval($this->input('p1_year', date('Y')));
                    $period1['from'] = "{$year}-01-01";
                    $period1['to'] = "{$year}-12-31";
                    $period2['from'] = ($year - 1) . "-01-01";
                    $period2['to'] = ($year - 1) . "-12-31";
                    break;

                case 'month':
                default:
                    $p1Month = trim($this->input('p1_month', date('Y-m')));
                    $p2Month = trim($this->input('p2_month', ''));

                    $period1['from'] = $p1Month . '-01';
                    $period1['to'] = date('Y-m-t', strtotime($period1['from']));

                    if (empty($p2Month)) {
                        // الشهر السابق تلقائياً
                        $p2Month = date('Y-m', strtotime($period1['from'] . ' -1 month'));
                    }
                    $period2['from'] = $p2Month . '-01';
                    $period2['to'] = date('Y-m-t', strtotime($period2Month = $p2Month . '-01'));
                    break;
            }

            // التحقق من صحة الفترات
            if (empty($period1['from']) || empty($period1['to']) || empty($period2['from']) || empty($period2['to'])) {
                ApiResponse::validationError(['date' => 'فترات المقارنة غير مكتملة']);
                return;
            }

            // جلب إحصائيات كل فترة
            $stats1 = $this->getPeriodStats($period1['from'], $period1['to']);
            $stats2 = $this->getPeriodStats($period2['from'], $period2['to']);

            // حساب التغييرات
            $comparison = [];
            $metrics = [
                'sales_total' => 'إجمالي المبيعات',
                'sales_cost' => 'تكلفة المبيعات',
                'sales_profit' => 'صافي الربح',
                'profit_margin' => 'هامش الربح %',
                'sales_count' => 'عدد عمليات البيع',
                'purchases_total' => 'إجمالي المشتريات',
                'purchase_count' => 'عدد عمليات الشراء',
                'expenses_total' => 'إجمالي المصروفات',
                'net_profit' => 'صافي الربح النهائي',
                'products_sold' => 'المنتجات المباعة',
                'products_purchased' => 'المنتجات المشتراة',
            ];

            foreach ($metrics as $key => $label) {
                $val1 = floatval($stats1[$key] ?? 0);
                $val2 = floatval($stats2[$key] ?? 0);
                $diff = $val1 - $val2;
                $changePct = $val2 != 0 ? round(($diff / $val2) * 100, 2) : ($val1 > 0 ? 100 : 0);

                $comparison[$key] = [
                    'label' => $label,
                    'period1' => $val1,
                    'period2' => $val2,
                    'difference' => round($diff, 2),
                    'change_pct' => $changePct,
                    'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'neutral'),
                ];
            }

            ApiResponse::success([
                'mode' => $mode,
                'period1' => $period1,
                'period2' => $period2,
                'stats1' => $stats1,
                'stats2' => $stats2,
                'comparison' => $comparison,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileReportController::comparison');
            ApiResponse::serverError('حدث خطأ أثناء جلب مقارنة الفترات');
        }
    }

    /**
     * جلب إحصائيات فترة محددة
     */
    private function getPeriodStats(string $dateFrom, string $dateTo): array
    {
        $sales = fetchOne(
            "SELECT COALESCE(SUM(total), 0) as sales_total,
                    COALESCE(SUM(cost_total), 0) as sales_cost,
                    COALESCE(SUM(profit), 0) as sales_profit,
                    COUNT(*) as sales_count,
                    COALESCE(SUM(quantity), 0) as products_sold
             FROM sales WHERE sale_date >= ? AND sale_date <= ?",
            [$dateFrom, $dateTo]
        );

        $purchases = fetchOne(
            "SELECT COALESCE(SUM(total), 0) as purchases_total,
                    COUNT(*) as purchase_count,
                    COALESCE(SUM(quantity), 0) as products_purchased
             FROM purchases WHERE purchase_date >= ? AND purchase_date <= ?",
            [$dateFrom, $dateTo]
        );

        $expenses = fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as expenses_total
             FROM expenses WHERE expense_date >= ? AND expense_date <= ?",
            [$dateFrom, $dateTo]
        );

        $salesTotal = floatval($sales['sales_total'] ?? 0);
        $salesProfit = floatval($sales['sales_profit'] ?? 0);
        $expensesTotal = floatval($expenses['expenses_total'] ?? 0);

        return [
            'sales_total' => round($salesTotal, 2),
            'sales_cost' => round(floatval($sales['sales_cost'] ?? 0), 2),
            'sales_profit' => round($salesProfit, 2),
            'profit_margin' => $salesTotal > 0 ? round(($salesProfit / $salesTotal) * 100, 2) : 0,
            'sales_count' => intval($sales['sales_count'] ?? 0),
            'avg_sale' => intval($sales['sales_count'] ?? 0) > 0
                ? round($salesTotal / intval($sales['sales_count'] ?? 1), 2) : 0,
            'purchases_total' => round(floatval($purchases['purchases_total'] ?? 0), 2),
            'purchase_count' => intval($purchases['purchase_count'] ?? 0),
            'expenses_total' => round($expensesTotal, 2),
            'net_profit' => round($salesProfit - $expensesTotal, 2),
            'products_sold' => floatval($sales['products_sold'] ?? 0),
            'products_purchased' => floatval($purchases['products_purchased'] ?? 0),
        ];
    }
}
