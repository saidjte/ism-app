<?php
/**
 * ===== متحكم لوحة تحكم الموبايل (MobileDashboardController) =====
 * يوفر بيانات ملخصة للوحة التحكم عبر API الموبايل
 *
 * المسارات:
 *   GET  /api/mobile/dashboard/summary      — ملخص عام (سريع — بدون فترة)
 *   GET  /api/mobile/dashboard/stats        — إحصائيات شاملة مع مقارنة فترات (DashboardData)
 *   GET  /api/mobile/dashboard/chart-data   — بيانات الرسوم البيانية
 *   GET  /api/mobile/dashboard/monthly      — إحصائيات شهرية للرسم البياني
 *   GET  /api/mobile/dashboard/recent       — آخر العناصر المضافة
 *   GET  /api/mobile/dashboard/alerts            — تنبيهات الصلاحية + المخزون المنخفض (مُجمّعة)
 *   GET  /api/mobile/dashboard/expiry-alerts      — تنبيهات الصلاحية فقط
 *   GET  /api/mobile/dashboard/low-stock-alerts   — تنبيهات المخزون المنخفض فقط
 *   GET  /api/mobile/dashboard/cards              — بطاقات لوحة التحكم (مخزون منخفض + صلاحية + أفضل المنتجات)
 *   GET  /api/mobile/dashboard/top-products — أفضل المنتجات مبيعاً
 *
 * v4.35.0: إنشاء متحكم لوحة تحكم الموبايل
 * v5.15.0: إغناء باستخدام DashboardData — إحصائيات شاملة + مقارنة فترات + أرباح موحدة
 * v5.15.1: إضافة تنبيهات الصلاحية والمخزون المنخفض للوحة التحكم
 * v5.16.0: إضافة بطاقات لوحة التحكم + أفضل المنتجات مبيعاً + تنبيهات صلاحية متقدمة
 * v5.16.1: تقسيم تنبيهات الصلاحية والمخزون المنخفض إلى نقاط نهاية منفصلة
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileDashboardController extends ApiController
{
    /**
     * ملخص عام سريع للوحة التحكم (بدون معاملات — بيانات اليوم والشهر)
     *
     * GET /api/mobile/dashboard/summary
     *
     * هذا المسار يوفر ملخصاً سريعاً بدون تحديد فترة.
     * للحصول على إحصائيات أشمل مع مقارنة فترات، استخدم /dashboard/stats
     * للحصول على تنبيهات مفصّلة، استخدم /dashboard/alerts
     */
    public function summary(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();

            // إجمالي المنتجات
            $productCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM products");
            $totalProducts = intval($productCount['cnt'] ?? 0);

            // إجمالي المخزون
            $stockInfo = $db->fetchOne(
                "SELECT COALESCE(SUM(quantity), 0) as total_qty,
                        COALESCE(SUM(quantity * purchase_price), 0) as stock_value,
                        COALESCE(SUM(quantity * selling_price), 0) as stock_value_retail
                 FROM products"
            );

            // المنتجات منخفضة المخزون — عد + قائمة
            $threshold = 5;
            try { $threshold = intval($db->getSetting('low_stock_threshold', '5')); } catch (\Throwable $e) {}
            $lowStockCount = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM products 
                 WHERE quantity <= min_quantity OR (min_quantity = 0 AND quantity <= ?)",
                [$threshold]
            );

            // قائمة المنتجات منخفضة المخزون (أهم 10)
            $lowStockProducts = [];
            try {
                $lowStockProducts = $db->fetchAll(
                    "SELECT id, name, code, quantity, unit, min_quantity, 
                            purchase_price, selling_price, category_id,
                            COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name
                     FROM products 
                     WHERE quantity <= min_quantity OR (min_quantity = 0 AND quantity <= ?)
                     ORDER BY quantity ASC
                     LIMIT 10",
                    [$threshold]
                ) ?: [];
            } catch (\Throwable $e) {}

            // مبيعات اليوم
            $today = date('Y-m-d');
            $todaySales = $db->fetchOne(
                "SELECT COALESCE(SUM(total), 0) as total_sales,
                        COALESCE(SUM(profit), 0) as total_profit,
                        COUNT(*) as sales_count
                 FROM sales WHERE sale_date = ?",
                [$today]
            );

            // مبيعات الشهر
            $monthStart = date('Y-m-01');
            $monthSales = $db->fetchOne(
                "SELECT COALESCE(SUM(total), 0) as total_sales,
                        COALESCE(SUM(profit), 0) as total_profit,
                        COUNT(*) as sales_count
                 FROM sales WHERE sale_date BETWEEN ? AND ?",
                [$monthStart, $today]
            );

            // مشتريات اليوم
            $todayPurchases = $db->fetchOne(
                "SELECT COALESCE(SUM(total), 0) as total_purchases,
                        COUNT(*) as purchase_count
                 FROM purchases WHERE purchase_date = ?",
                [$today]
            );

            // مصاريف اليوم
            $todayExpenses = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total_expenses
                 FROM expenses WHERE expense_date = ?",
                [$today]
            );

            // === تنبيهات الصلاحية ===
            $expiryAlerts = $this->fetchExpiryAlerts($db);

            ApiResponse::success([
                'products' => [
                    'total' => $totalProducts,
                    'stock_quantity' => floatval($stockInfo['total_qty'] ?? 0),
                    'stock_value' => floatval($stockInfo['stock_value'] ?? 0),
                    'stock_value_retail' => floatval($stockInfo['stock_value_retail'] ?? 0),
                    'low_stock' => intval($lowStockCount['cnt'] ?? 0),
                    'low_stock_products' => $lowStockProducts,
                ],
                'today' => [
                    'sales' => floatval($todaySales['total_sales'] ?? 0),
                    'profit' => floatval($todaySales['total_profit'] ?? 0),
                    'sales_count' => intval($todaySales['sales_count'] ?? 0),
                    'purchases' => floatval($todayPurchases['total_purchases'] ?? 0),
                    'purchase_count' => intval($todayPurchases['purchase_count'] ?? 0),
                    'expenses' => floatval($todayExpenses['total_expenses'] ?? 0),
                ],
                'month' => [
                    'sales' => floatval($monthSales['total_sales'] ?? 0),
                    'profit' => floatval($monthSales['total_profit'] ?? 0),
                    'sales_count' => intval($monthSales['sales_count'] ?? 0),
                ],
                'expiry_alerts' => $expiryAlerts,
                'currency' => function_exists('getCurrencySymbol') ? getCurrencySymbol() : (class_exists('AppDatabase') ? AppDatabase::getInstance()->getCurrencySymbol() : 'د.م.'),
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::summary');
            }
            ApiResponse::serverError('خطأ في جلب ملخص لوحة التحكم');
        }
    }

    /**
     * إحصائيات شاملة مع مقارنة فترات (باستخدام DashboardData)
     *
     * GET /api/mobile/dashboard/stats?date_from=2025-01-01&date_to=2025-06-30
     *
     * يستخدم DashboardData::getStats() الذي يوفر:
     *   - حساب الأرباح الموحَّد مع صفحة الأرباح (SaleService)
     *   - مقارنة مع الفترة السابقة تلقائياً
     *   - نسب التغيير بين الفترتين
     *   - قيمة المخزون والمصاريف والضرائب
     */
    public function stats(array $params = []): void
    {
        try {
            // تحميل DashboardData
            $dashboardDataPath = __DIR__ . '/../../DashboardData.php';
            if (!file_exists($dashboardDataPath)) {
                ApiResponse::serverError('ملف DashboardData غير موجود');
                return;
            }
            require_once $dashboardDataPath;

            // تحديد الفترة
            $dateFrom = $this->input('date_from', date('Y-m-01'));
            $dateTo   = $this->input('date_to', date('Y-m-d'));

            // التحقق من صحة التواريخ
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                ApiResponse::error('صيغة التاريخ غير صحيحة. استخدم Y-m-d', 'validation', 400);
                return;
            }

            if (strtotime($dateFrom) > strtotime($dateTo)) {
                ApiResponse::error('تاريخ البداية يجب أن يكون قبل تاريخ النهاية', 'validation', 400);
                return;
            }

            // جلب الإحصائيات الشاملة
            $stats = DashboardData::getStats($dateFrom, $dateTo);

            // إضافة تنبيهات الصلاحية والمخزون المنخفض
            $db = AppDatabase::getInstance();
            $stats['expiry_alerts'] = $this->fetchExpiryAlerts($db);
            $stats['low_stock_alerts'] = $this->fetchLowStockAlerts($db);

            // إضافة رمز العملة
            $stats['currency'] = function_exists('getCurrencySymbol')
                ? getCurrencySymbol()
                : (class_exists('AppDatabase') ? AppDatabase::getInstance()->getCurrencySymbol() : 'د.م.');

            ApiResponse::success($stats);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::stats');
            }
            ApiResponse::serverError('خطأ في جلب إحصائيات لوحة التحكم');
        }
    }

    /**
     * تنبيهات لوحة التحكم — صلاحية + مخزون منخفض
     *
     * GET /api/mobile/dashboard/alerts
     *
     * يوفر تنبيهات مُفصّلة:
     *   - منتجات منتهية الصلاحية
     *   - منتجات قريبة الصلاحية
     *   - منتجات منخفضة المخزون
     *   - إحصائيات مختصرة
     *   - دفعات قريبة الصلاحية (إن وُجد جدول purchase_batches)
     *
     * لا يوجد معاملات — جميع التنبيهات تُعاد كاملة
     */
    public function alerts(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();

            // === تنبيهات الصلاحية (كلها — بدون حد) ===
            $expiryAlerts = $this->fetchExpiryAlerts($db);

            // === تنبيهات المخزون المنخفض (كلها — بدون حد) ===
            $lowStockAlerts = $this->fetchLowStockAlerts($db);

            // === تنبيهات الدفعات قريبة الصلاحية (كلها — بدون حد) ===
            $batchAlerts = $this->fetchBatchExpiryAlerts($db);

            // === ملخص التنبيهات ===
            $summary = [
                'total_alerts' => $expiryAlerts['expired_count'] + $expiryAlerts['expiring_count'] + count($lowStockAlerts),
                'expiry_alerts_count' => $expiryAlerts['expired_count'] + $expiryAlerts['expiring_count'],
                'expired_count' => $expiryAlerts['expired_count'],
                'expiring_count' => $expiryAlerts['expiring_count'],
                'expired_value' => $expiryAlerts['expired_value'],
                'low_stock_count' => count($lowStockAlerts),
                'batch_expiry_count' => count($batchAlerts['expired'] ?? []) + count($batchAlerts['expiring'] ?? []),
                'has_critical_alerts' => ($expiryAlerts['expired_count'] > 0 || count($lowStockAlerts) > 0),
            ];

            ApiResponse::success([
                'summary' => $summary,
                'expiry' => $expiryAlerts,
                'low_stock' => $lowStockAlerts,
                'batches' => $batchAlerts,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::alerts');
            }
            ApiResponse::serverError('خطأ في جلب تنبيهات لوحة التحكم');
        }
    }

    /**
     * تنبيهات الصلاحية فقط (منفصلة)
     *
     * GET /api/mobile/dashboard/expiry-alerts
     *
     * يوفر تنبيهات الصلاحية فقط:
     *   - منتجات منتهية الصلاحية (كلها — بدون حد)
     *   - منتجات قريبة الصلاحية (كلها — بدون حد)
     *   - دفعات قريبة الصلاحية (كلها — بدون حد)
     *   - إحصائيات دقيقة من استعلامات COUNT مستقلة
     *   - توقيع فريد للتنبيهات
     *
     * لا يوجد معاملات — جميع التنبيهات تُعاد كاملة
     */
    public function expiryAlerts(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();

            // === تنبيهات الصلاحية (كل المنتجات — بدون حد) ===
            $expiryAlerts = $this->fetchExpiryAlerts($db);

            // === تنبيهات الدفعات قريبة الصلاحية (كل الدفعات — بدون حد) ===
            $batchAlerts = $this->fetchBatchExpiryAlerts($db);

            // === إحصائيات مفصّلة ===
            $batchExpired = $batchAlerts['expired'] ?? [];
            $batchExpiring = $batchAlerts['expiring'] ?? [];

            $stats = [
                'expired_count' => $expiryAlerts['expired_count'],
                'expiring_count' => $expiryAlerts['expiring_count'],
                'total_alerts' => $expiryAlerts['expired_count'] + $expiryAlerts['expiring_count'],
                'expired_value' => $expiryAlerts['expired_value'],
                'batch_expired_count' => count($batchExpired),
                'batch_expiring_count' => count($batchExpiring),
                'batch_count' => count($batchExpired) + count($batchExpiring),
                'has_critical' => $expiryAlerts['expired_count'] > 0,
                'severity' => $expiryAlerts['expired_count'] > 0 ? 'critical' : ($expiryAlerts['expiring_count'] > 5 ? 'warning' : ($expiryAlerts['expiring_count'] > 0 ? 'info' : 'normal')),
                'enabled' => $expiryAlerts['enabled'],
                'alert_days' => $expiryAlerts['alert_days'],
            ];

            ApiResponse::success([
                'stats' => $stats,
                'expired' => $expiryAlerts['expired'],
                'expiring' => $expiryAlerts['expiring'],
                'batches' => [
                    'expired' => $batchExpired,
                    'expiring' => $batchExpiring,
                ],
                'signature' => $expiryAlerts['signature'] ?? '',
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::expiryAlerts');
            }
            ApiResponse::serverError('خطأ في جلب تنبيهات الصلاحية');
        }
    }

    /**
     * تنبيهات المخزون المنخفض فقط (منفصلة)
     *
     * GET /api/mobile/dashboard/low-stock-alerts
     *
     * يوفر تنبيهات المخزون المنخفض فقط:
     *   - قائمة جميع المنتجات منخفضة المخزون (بدون حد أقصى — تنبيهات كاملة)
     *   - حالة كل منتج (out_of_stock / below_minimum / below_threshold)
     *   - إحصائيات دقيقة من استعلامات COUNT مستقلة
     *   - مستوى الخطورة
     *
     * لا يوجد معاملات — جميع التنبيهات تُعاد كاملة
     */
    public function lowStockAlerts(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();

            // === إعدادات المخزون المنخفض ===
            $threshold = 5;
            try { $threshold = intval($db->getSetting('low_stock_threshold', '5')); } catch (\Throwable $e) {}

            $lowStockEnabled = true;
            try { $lowStockEnabled = $db->getSetting('low_stock_alert', '1') === '1'; } catch (\Throwable $e) {}

            // === إحصائيات دقيقة من COUNT (بدون LIMIT) ===
            $outOfStockCount = 0;
            $belowMinCount = 0;
            $belowThresholdCount = 0;
            $totalValue = 0;

            try {
                $countRow = $db->fetchOne(
                    "SELECT
                        SUM(CASE WHEN p.min_quantity > 0 AND p.quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
                        SUM(CASE WHEN p.min_quantity > 0 AND p.quantity > 0 AND p.quantity <= p.min_quantity THEN 1 ELSE 0 END) as below_minimum,
                        SUM(CASE WHEN p.min_quantity = 0 AND p.quantity <= ? THEN 1 ELSE 0 END) as below_threshold,
                        COALESCE(SUM(CASE WHEN p.quantity <= p.min_quantity OR (p.min_quantity = 0 AND p.quantity <= ?) THEN p.quantity * p.purchase_price ELSE 0 END), 0) as total_value
                     FROM products p
                     WHERE p.quantity <= p.min_quantity
                        OR (p.min_quantity = 0 AND p.quantity <= ?)",
                    [$threshold, $threshold, $threshold]
                );
                if ($countRow) {
                    $outOfStockCount = intval($countRow['out_of_stock'] ?? 0);
                    $belowMinCount = intval($countRow['below_minimum'] ?? 0);
                    $belowThresholdCount = intval($countRow['below_threshold'] ?? 0);
                    $totalValue = floatval($countRow['total_value'] ?? 0);
                }
            } catch (\Throwable $e) {}

            $totalCount = $outOfStockCount + $belowMinCount + $belowThresholdCount;

            // === قائمة المنتجات منخفضة المخزون (كلها — بدون حد) ===
            $lowStockAlerts = $lowStockEnabled ? $this->fetchLowStockAlerts($db) : [];

            $stats = [
                'total_count' => $totalCount,
                'out_of_stock_count' => $outOfStockCount,
                'below_minimum_count' => $belowMinCount,
                'below_threshold_count' => $belowThresholdCount,
                'total_value' => round($totalValue, 2),
                'has_critical' => $outOfStockCount > 0,
                'severity' => $outOfStockCount > 0 ? 'critical' : ($totalCount > 10 ? 'warning' : ($totalCount > 0 ? 'info' : 'normal')),
                'enabled' => $lowStockEnabled,
                'threshold' => $threshold,
            ];

            ApiResponse::success([
                'stats' => $stats,
                'products' => $lowStockAlerts,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::lowStockAlerts');
            }
            ApiResponse::serverError('خطأ في جلب تنبيهات المخزون المنخفض');
        }
    }

    /**
     * بيانات الرسوم البيانية
     *
     * GET /api/mobile/dashboard/chart-data?type=sales_trend&days=30
     *
     * الأنواع المدعومة:
     *   - sales_trend: اتجاه المبيعات اليومي
     *   - top_products: أكثر المنتجات مبيعاً
     *   - stock_distribution: توزيع المخزون حسب التصنيف
     *   - monthly_comparison: مقارنة شهرية (مبيعات + مشتريات + أرباح + مصاريف)
     */
    public function chartData(array $params = []): void
    {
        try {
            $type = $this->input('type', 'sales_trend');
            $days = min(90, max(7, intval($this->input('days', 30))));
            $db = AppDatabase::getInstance();

            switch ($type) {
                case 'sales_trend':
                    $data = $db->fetchAll(
                        "SELECT sale_date as date,
                                COALESCE(SUM(total), 0) as sales,
                                COALESCE(SUM(profit), 0) as profit,
                                COUNT(*) as count
                         FROM sales
                         WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                         GROUP BY sale_date
                         ORDER BY sale_date ASC",
                        [$days]
                    );
                    break;

                case 'top_products':
                    $limit = min(20, max(5, intval($this->input('limit', 10))));
                    $data = $db->fetchAll(
                        "SELECT p.id, p.name, p.code,
                                COALESCE(SUM(s.quantity), 0) as total_sold,
                                COALESCE(SUM(s.total), 0) as total_sales,
                                COALESCE(SUM(s.profit), 0) as total_profit
                         FROM products p
                         JOIN sales s ON p.id = s.product_id
                         WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                         GROUP BY p.id
                         ORDER BY total_sales DESC
                         LIMIT ?",
                        [$days, $limit]
                    );
                    break;

                case 'stock_distribution':
                    $data = $db->fetchAll(
                        "SELECT c.name as category, COUNT(p.id) as product_count,
                                COALESCE(SUM(p.quantity), 0) as total_stock,
                                COALESCE(SUM(p.quantity * p.purchase_price), 0) as stock_value,
                                COALESCE(SUM(p.quantity * p.selling_price), 0) as stock_value_retail
                         FROM categories c
                         LEFT JOIN products p ON c.id = p.category_id
                         GROUP BY c.id
                         ORDER BY stock_value DESC"
                    );
                    break;

                case 'monthly_comparison':
                    // مقارنة شهرية مُحسّنة — باستخدام DashboardData
                    $dashboardDataPath = __DIR__ . '/../../DashboardData.php';
                    if (file_exists($dashboardDataPath)) {
                        require_once $dashboardDataPath;
                        $months = min(12, max(3, intval($this->input('months', 6))));
                        $dateFrom = date('Y-m-01', strtotime("-{$months} months"));
                        $dateTo = date('Y-m-t');

                        $fullStats = DashboardData::getStats($dateFrom, $dateTo);
                        $data = $fullStats['monthly_stats'] ?? [];
                    } else {
                        // بدون DashboardData — استعلام مباشر
                        $months = min(12, max(3, intval($this->input('months', 6))));
                        $data = $this->fetchMonthlyDirect($months);
                    }
                    break;

                default:
                    ApiResponse::error('نوع الرسم البياني غير معروف: ' . $type, 'validation', 400);
                    return;
            }

            ApiResponse::success($data);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::chartData');
            }
            ApiResponse::serverError('خطأ في جلب بيانات الرسم البياني');
        }
    }

    /**
     * إحصائيات شهرية للرسم البياني
     *
     * GET /api/mobile/dashboard/monthly?months=6
     *
     * يوفر بيانات شهرية مُجمّعة:
     *   - مبيعات + أرباح لكل شهر
     *   - مشتريات لكل شهر
     *   - مصاريف لكل شهر
     *   - ارتفاع الأشرطة كنسبة مئوية (للعرض)
     */
    public function monthly(array $params = []): void
    {
        try {
            $months = min(12, max(3, intval($this->input('months', 6))));

            // محاولة استخدام DashboardData
            $dashboardDataPath = __DIR__ . '/../../DashboardData.php';
            if (file_exists($dashboardDataPath)) {
                require_once $dashboardDataPath;
                $dateFrom = date('Y-m-01', strtotime("-" . ($months - 1) . " months"));
                $dateTo = date('Y-m-t');

                $fullStats = DashboardData::getStats($dateFrom, $dateTo);
                $monthlyData = $fullStats['monthly_stats'] ?? [];

                ApiResponse::success([
                    'months' => $months,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'chart_data' => $monthlyData,
                ]);
            } else {
                // استعلام مباشر بدون DashboardData
                $data = $this->fetchMonthlyDirect($months);
                ApiResponse::success([
                    'months' => $months,
                    'chart_data' => $data,
                ]);
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::monthly');
            }
            ApiResponse::serverError('خطأ في جلب الإحصائيات الشهرية');
        }
    }

    /**
     * آخر العناصر المضافة (منتجات، مشتريات، مبيعات، مصاريف)
     *
     * GET /api/mobile/dashboard/recent?limit=5
     */
    public function recent(array $params = []): void
    {
        try {
            $limit = min(20, max(1, intval($this->input('limit', 5))));
            $db = AppDatabase::getInstance();

            // آخر المنتجات
            $recentProducts = [];
            try {
                $recentProducts = $db->fetchAll(
                    "SELECT id, code, name, quantity, unit, purchase_price, selling_price, category_id
                     FROM products ORDER BY created_at DESC LIMIT ?",
                    [$limit]
                ) ?: [];
            } catch (\Throwable $e) {}

            // آخر المشتريات
            $recentPurchases = [];
            try {
                $recentPurchases = $db->fetchAll(
                    "SELECT p.id, p.product_id, p.quantity, p.total, p.purchase_date,
                            p.unit_price as cost_price,
                            pr.name as product_name, pr.code as product_code
                     FROM purchases p
                     JOIN products pr ON p.product_id = pr.id
                     ORDER BY p.created_at DESC LIMIT ?",
                    [$limit]
                ) ?: [];
            } catch (\Throwable $e) {}

            // آخر المبيعات
            $recentSales = [];
            try {
                $recentSales = $db->fetchAll(
                    "SELECT s.id, s.product_id, s.quantity, s.total, s.profit, s.sale_date,
                            s.unit_price as selling_price,
                            pr.name as product_name, pr.code as product_code
                     FROM sales s
                     JOIN products pr ON s.product_id = pr.id
                     ORDER BY s.created_at DESC LIMIT ?",
                    [$limit]
                ) ?: [];
            } catch (\Throwable $e) {}

            // آخر المصاريف
            $recentExpenses = [];
            try {
                $recentExpenses = $db->fetchAll(
                    "SELECT id, category, amount, description, expense_date
                     FROM expenses ORDER BY created_at DESC LIMIT ?",
                    [$limit]
                ) ?: [];
            } catch (\Throwable $e) {}

            ApiResponse::success([
                'products'  => $recentProducts,
                'purchases' => $recentPurchases,
                'sales'     => $recentSales,
                'expenses'  => $recentExpenses,
                'limit'     => $limit,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::recent');
            }
            ApiResponse::serverError('خطأ في جلب آخر العناصر');
        }
    }

    /**
     * بطاقات لوحة التحكم — بيانات مُحضّرة للعرض في بطاقات الموبايل
     *
     * GET /api/mobile/dashboard/cards
     *
     * يوفر بطاقات جاهزة للعرض:
     *   - بطاقة المخزون المنخفض (عدد + قائمة مختصرة)
     *   - بطاقة الصلاحية المنتهية (عدد + قيمة خسارة + قائمة مختصرة)
     *   - بطاقة الصلاحية القريبة (عدد + قائمة مختصرة)
     *   - بطاقة أفضل المنتجات مبيعاً (أعلى 5)
     *   - ملخص تنبيهات (عدد إجمالي + مستوى خطورة)
     *
     * معاملات الاستعلام:
     *   period — فترة أفضل المنتجات: today, week, month, all (افتراضي: month)
     *   card   — بطاقة محددة فقط: low_stock, expired, expiring, top_products (اختياري)
     */
    public function cards(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $period = $this->input('period', 'month');
            $card = $this->input('card', ''); // فلتر بطاقة محددة

            $result = [];

            // === بطاقة المخزون المنخفض ===
            if (empty($card) || $card === 'low_stock') {
                $threshold = 5;
                try { $threshold = intval($db->getSetting('low_stock_threshold', '5')); } catch (\Throwable $e) {}

                $lowStockEnabled = true;
                try { $lowStockEnabled = $db->getSetting('low_stock_alert', '1') === '1'; } catch (\Throwable $e) {}

                $lowStockCard = ['enabled' => $lowStockEnabled, 'count' => 0, 'items' => [], 'severity' => 'normal'];

                if ($lowStockEnabled) {
                    try {
                        // إحصائيات دقيقة من COUNT (بدون LIMIT) + قيمة المخزون بسعر التكلفة وسعر البيع
                        $countRow = $db->fetchOne(
                            "SELECT
                                COUNT(*) as cnt,
                                COALESCE(SUM(quantity * purchase_price), 0) as total_val,
                                COALESCE(SUM(quantity * selling_price), 0) as total_val_retail
                             FROM products
                             WHERE quantity <= min_quantity
                                OR (min_quantity = 0 AND quantity <= ?)",
                            [$threshold]
                        );
                        $lowCount = intval($countRow['cnt'] ?? 0);
                        $lowStockValue = floatval($countRow['total_val'] ?? 0);
                        $lowStockValueRetail = floatval($countRow['total_val_retail'] ?? 0);

                        // قائمة أهم 5 منتجات منخفضة المخزون (للبطاقة)
                        $lowItems = $db->fetchAll(
                            "SELECT id, name, code, quantity, unit, min_quantity,
                                    selling_price, purchase_price,
                                    COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                                    CASE
                                        WHEN min_quantity > 0 AND quantity <= 0 THEN 'out_of_stock'
                                        WHEN min_quantity > 0 AND quantity <= min_quantity THEN 'below_minimum'
                                        WHEN min_quantity = 0 AND quantity <= ? THEN 'below_threshold'
                                        ELSE 'normal'
                                    END as status,
                                    CASE
                                        WHEN min_quantity > 0 AND quantity <= 0 THEN 'critical'
                                        WHEN min_quantity > 0 AND quantity <= min_quantity THEN 'warning'
                                        ELSE 'info'
                                    END as severity_level
                             FROM products
                             WHERE quantity <= min_quantity
                                OR (min_quantity = 0 AND quantity <= ?)
                             ORDER BY quantity ASC
                             LIMIT 5",
                            [$threshold, $threshold]
                        ) ?: [];

                        $lowStockCard = [
                            'enabled' => true,
                            'count' => $lowCount,
                            'items' => $lowItems,
                            'total_value' => round($lowStockValue, 2),
                            'total_value_retail' => round($lowStockValueRetail, 2),
                            'threshold' => $threshold,
                            'severity' => $lowCount > 10 ? 'critical' : ($lowCount > 0 ? 'warning' : 'normal'),
                        ];
                    } catch (\Throwable $e) {}
                }

                $result['low_stock'] = $lowStockCard;
            }

            // === بطاقة الصلاحية المنتهية ===
            if (empty($card) || $card === 'expired') {
                $expiredCard = ['count' => 0, 'items' => [], 'loss_value' => 0, 'severity' => 'normal'];

                try {
                    $expiryAlertDays = 30;
                    try { $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30')); } catch (\Throwable $e) {}

                    $expiryAlertEnabled = true;
                    try { $expiryAlertEnabled = $db->getSetting('expiry_alert_enabled', '1') === '1'; } catch (\Throwable $e) {}

                    if ($expiryAlertEnabled) {
                        // === إحصائيات دقيقة: عد + قيمة خسارة من COUNT/SUM (بدون LIMIT) ===
                        $statsRow = $db->fetchOne(
                            "SELECT COUNT(*) as cnt,
                                    COALESCE(SUM(quantity * purchase_price), 0) as total_loss
                             FROM products
                             WHERE has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()"
                        );
                        $expiredCount = intval($statsRow['cnt'] ?? 0);
                        $lossValue = floatval($statsRow['total_loss'] ?? 0);

                        // === قائمة أهم 10 منتجات منتهية (للبطاقة) ===
                        $expiredItems = $db->fetchAll(
                            "SELECT id, name, code, quantity, unit, expiry_date,
                                    purchase_price, selling_price,
                                    COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                                    DATEDIFF(CURDATE(), expiry_date) as days_expired,
                                    CASE
                                        WHEN DATEDIFF(CURDATE(), expiry_date) > 90 THEN 'long_expired'
                                        WHEN DATEDIFF(CURDATE(), expiry_date) > 30 THEN 'expired'
                                        ELSE 'recently_expired'
                                    END as urgency
                             FROM products
                             WHERE has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
                             ORDER BY expiry_date ASC
                             LIMIT 10"
                        ) ?: [];

                        $expiredCard = [
                            'enabled' => true,
                            'count' => $expiredCount,
                            'items' => $expiredItems,
                            'loss_value' => round($lossValue, 2),
                            'alert_days' => $expiryAlertDays,
                            'severity' => $expiredCount > 0 ? 'critical' : 'normal',
                        ];
                    } else {
                        $expiredCard = ['enabled' => false, 'count' => 0, 'items' => [], 'loss_value' => 0, 'severity' => 'normal'];
                    }
                } catch (\Throwable $e) {}

                $result['expired'] = $expiredCard;
            }

            // === بطاقة الصلاحية القريبة (قريبة من الانتهاء) ===
            if (empty($card) || $card === 'expiring') {
                $expiringCard = ['count' => 0, 'items' => [], 'severity' => 'normal'];

                try {
                    $expiryAlertDays = 30;
                    try { $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30')); } catch (\Throwable $e) {}

                    $expiryAlertEnabled = true;
                    try { $expiryAlertEnabled = $db->getSetting('expiry_alert_enabled', '1') === '1'; } catch (\Throwable $e) {}

                    if ($expiryAlertEnabled) {
                        // === إحصائيات دقيقة: عد + قيمة مخاطرة من COUNT/SUM (بدون LIMIT) ===
                        $statsRow = $db->fetchOne(
                            "SELECT COUNT(*) as cnt,
                                    COALESCE(SUM(quantity * purchase_price), 0) as total_risk
                             FROM products
                             WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                             AND expiry_date >= CURDATE()
                             AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)",
                            [$expiryAlertDays]
                        );
                        $expiringCount = intval($statsRow['cnt'] ?? 0);
                        $atRiskValue = floatval($statsRow['total_risk'] ?? 0);

                        // === قائمة أهم 10 منتجات قريبة الصلاحية (للبطاقة) ===
                        $expiringItems = $db->fetchAll(
                            "SELECT id, name, code, quantity, unit, expiry_date,
                                    purchase_price, selling_price,
                                    COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                                    DATEDIFF(expiry_date, CURDATE()) as days_remaining,
                                    CASE
                                        WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 THEN 'urgent'
                                        WHEN DATEDIFF(expiry_date, CURDATE()) <= 15 THEN 'warning'
                                        ELSE 'notice'
                                    END as urgency
                             FROM products
                             WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                             AND expiry_date >= CURDATE()
                             AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                             ORDER BY expiry_date ASC
                             LIMIT 10",
                            [$expiryAlertDays]
                        ) ?: [];

                        $expiringCard = [
                            'enabled' => true,
                            'count' => $expiringCount,
                            'items' => $expiringItems,
                            'at_risk_value' => round($atRiskValue, 2),
                            'alert_days' => $expiryAlertDays,
                            'severity' => $expiringCount > 5 ? 'warning' : ($expiringCount > 0 ? 'info' : 'normal'),
                        ];
                    } else {
                        $expiringCard = ['enabled' => false, 'count' => 0, 'items' => [], 'severity' => 'normal'];
                    }
                } catch (\Throwable $e) {}

                $result['expiring'] = $expiringCard;
            }

            // === بطاقة أفضل المنتجات مبيعاً ===
            if (empty($card) || $card === 'top_products') {
                $topProductsCard = ['items' => [], 'period' => $period];

                try {
                    $dateCondition = match($period) {
                        'today'  => "s.sale_date = CURDATE()",
                        'week'   => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
                        'month'  => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                        'all'    => "1=1",
                        default  => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                    };

                    $topProducts = $db->fetchAll(
                        "SELECT p.id, p.name, p.code, p.unit,
                                p.quantity as current_stock,
                                p.selling_price,
                                COALESCE((SELECT name FROM categories WHERE id = p.category_id), '') as category_name,
                                COALESCE(SUM(s.quantity), 0) as total_sold,
                                COALESCE(SUM(s.total), 0) as total_sales,
                                COALESCE(SUM(s.profit), 0) as total_profit,
                                COUNT(s.id) as sales_count
                         FROM products p
                         JOIN sales s ON p.id = s.product_id
                         WHERE {$dateCondition}
                         GROUP BY p.id
                         ORDER BY total_sold DESC
                         LIMIT 5"
                    ) ?: [];

                    // حساب الحصة السوقية
                    $totalSoldAll = 0;
                    foreach ($topProducts as $tp) {
                        $totalSoldAll += floatval($tp['total_sold']);
                    }
                    $topProductsWithShare = [];
                    foreach ($topProducts as $tp) {
                        $share = $totalSoldAll > 0 ? round((floatval($tp['total_sold']) / $totalSoldAll) * 100, 1) : 0;
                        $tp['market_share'] = $share;
                        $tp['total_sold'] = floatval($tp['total_sold']);
                        $tp['total_sales'] = floatval($tp['total_sales']);
                        $tp['total_profit'] = floatval($tp['total_profit']);
                        $tp['sales_count'] = intval($tp['sales_count']);
                        $topProductsWithShare[] = $tp;
                    }

                    $topProductsCard = [
                        'items' => $topProductsWithShare,
                        'period' => $period,
                        'total_sold' => $totalSoldAll,
                    ];
                } catch (\Throwable $e) {}

                $result['top_products'] = $topProductsCard;
            }

            // === ملخص التنبيهات ===
            $alertSummary = [
                'total_alerts' => ($result['low_stock']['count'] ?? 0) + ($result['expired']['count'] ?? 0) + ($result['expiring']['count'] ?? 0),
                'has_critical' => ($result['expired']['severity'] ?? '') === 'critical' || ($result['low_stock']['severity'] ?? '') === 'critical',
                'critical_count' => ($result['expired']['count'] ?? 0),
                'warning_count' => ($result['low_stock']['count'] ?? 0) + ($result['expiring']['count'] ?? 0),
            ];
            $result['alert_summary'] = $alertSummary;

            // === إعدادات التنبيهات ===
            $expiryAlertDays = 30;
            try { $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30')); } catch (\Throwable $e) {}
            $lowStockThreshold = 5;
            try { $lowStockThreshold = intval($db->getSetting('low_stock_threshold', '5')); } catch (\Throwable $e) {}

            $result['settings'] = [
                'expiry_alert_days' => $expiryAlertDays,
                'low_stock_threshold' => $lowStockThreshold,
                'currency' => class_exists('AppDatabase') ? AppDatabase::getInstance()->getCurrencySymbol() : 'د.م.',
            ];

            ApiResponse::success($result);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::cards');
            }
            ApiResponse::serverError('خطأ في جلب بطاقات لوحة التحكم');
        }
    }

    /**
     * أفضل المنتجات مبيعاً
     *
     * GET /api/mobile/dashboard/top-products?period=month&limit=10&category_id=0
     *
     * معاملات الاستعلام:
     *   period      — فترة الحساب: today, week, month, quarter, year, all (افتراضي: month)
     *   limit       — عدد المنتجات (5-50, افتراضي: 10)
     *   category_id — تصفية حسب التصنيف (اختياري)
     *   sort_by     — ترتيب حسب: quantity, revenue, profit (افتراضي: quantity)
     */
    public function topProducts(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $period = $this->input('period', 'month');
            $limit = min(50, max(5, intval($this->input('limit', 10))));
            $categoryId = intval($this->input('category_id', 0));
            $sortBy = $this->input('sort_by', 'quantity');

            // بناء شرط التاريخ
            $dateCondition = match($period) {
                'today'   => "s.sale_date = CURDATE()",
                'week'    => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
                'month'   => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                'quarter' => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
                'year'    => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)",
                'all'     => "1=1",
                default   => "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            };

            // بناء شرط التصنيف
            $categoryCondition = '';
            $categoryBindings = [];
            if ($categoryId > 0) {
                $categoryCondition = ' AND p.category_id = ?';
                $categoryBindings = [$categoryId];
            }

            // ترتيب النتائج
            $orderColumn = match($sortBy) {
                'revenue'  => 'total_sales',
                'profit'   => 'total_profit',
                'quantity' => 'total_sold',
                default    => 'total_sold',
            };

            // جلب أفضل المنتجات
            $topProducts = $db->fetchAll(
                "SELECT p.id, p.name, p.code, p.unit, p.quantity as current_stock,
                        p.selling_price, p.purchase_price,
                        p.category_id,
                        COALESCE((SELECT name FROM categories WHERE id = p.category_id), '') as category_name,
                        COALESCE(SUM(s.quantity), 0) as total_sold,
                        COALESCE(SUM(s.total), 0) as total_sales,
                        COALESCE(SUM(s.profit), 0) as total_profit,
                        COUNT(DISTINCT s.id) as sales_count,
                        AVG(s.unit_price) as avg_selling_price,
                        MIN(s.sale_date) as first_sale_date,
                        MAX(s.sale_date) as last_sale_date
                 FROM products p
                 JOIN sales s ON p.id = s.product_id
                 WHERE {$dateCondition}{$categoryCondition}
                 GROUP BY p.id
                 ORDER BY {$orderColumn} DESC
                 LIMIT ?",
                array_merge($categoryBindings, [$limit])
            ) ?: [];

            // حساب الإجماليات والحصص
            $totalSold = 0;
            $totalRevenue = 0;
            $totalProfit = 0;
            foreach ($topProducts as $tp) {
                $totalSold += floatval($tp['total_sold']);
                $totalRevenue += floatval($tp['total_sales']);
                $totalProfit += floatval($tp['total_profit']);
            }

            // تنسيق البيانات مع الحصة السوقية
            $rank = 1;
            $formatted = [];
            foreach ($topProducts as $tp) {
                $sold = floatval($tp['total_sold']);
                $revenue = floatval($tp['total_sales']);
                $profit = floatval($tp['total_profit']);

                $formatted[] = [
                    'rank' => $rank++,
                    'id' => intval($tp['id']),
                    'name' => $tp['name'],
                    'code' => $tp['code'] ?? '',
                    'unit' => $tp['unit'] ?? '',
                    'category_name' => $tp['category_name'] ?? '',
                    'current_stock' => floatval($tp['current_stock']),
                    'selling_price' => floatval($tp['selling_price']),
                    'total_sold' => $sold,
                    'total_sales' => $revenue,
                    'total_profit' => $profit,
                    'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
                    'sales_count' => intval($tp['sales_count']),
                    'market_share' => $totalSold > 0 ? round(($sold / $totalSold) * 100, 1) : 0,
                    'avg_selling_price' => round(floatval($tp['avg_selling_price'] ?? 0), 2),
                    'first_sale_date' => $tp['first_sale_date'] ?? null,
                    'last_sale_date' => $tp['last_sale_date'] ?? null,
                ];
            }

            ApiResponse::success([
                'products' => $formatted,
                'period' => $period,
                'sort_by' => $sortBy,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'totals' => [
                    'sold' => $totalSold,
                    'revenue' => $totalRevenue,
                    'profit' => $totalProfit,
                ],
                'currency' => class_exists('AppDatabase') ? AppDatabase::getInstance()->getCurrencySymbol() : 'د.م.',
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileDashboardController::topProducts');
            }
            ApiResponse::serverError('خطأ في جلب أفضل المنتجات');
        }
    }

    // ========================================
    // دوال جلب تنبيهات الصلاحية والمخزون
    // ========================================

    /**
     * جلب تنبيهات الصلاحية — منتجات منتهية + قريبة الصلاحية
     *
     * @param AppDatabase $db اتصال قاعدة البيانات
     * @return array بيانات تنبيهات الصلاحية
     */
    private function fetchExpiryAlerts(AppDatabase $db): array
    {
        $expiryAlertDays = 30;
        try {
            $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30'));
        } catch (\Throwable $e) {}

        $expiryAlertEnabled = true;
        try {
            $expiryAlertEnabled = $db->getSetting('expiry_alert_enabled', '1') === '1';
        } catch (\Throwable $e) {}

        if (!$expiryAlertEnabled) {
            return [
                'enabled' => false,
                'expired' => [],
                'expiring' => [],
                'expired_count' => 0,
                'expiring_count' => 0,
                'expired_value' => 0,
                'alert_days' => $expiryAlertDays,
            ];
        }

        // === إحصائيات دقيقة من COUNT (بدون LIMIT) ===
        $expiredCount = 0;
        $expiringCount = 0;
        $expiredValue = 0;
        try {
            $countRow = $db->fetchOne(
                "SELECT
                    COUNT(CASE WHEN has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 1 END) as expired_cnt,
                    COUNT(CASE WHEN has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 END) as expiring_cnt,
                    COALESCE(SUM(CASE WHEN has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN quantity * purchase_price ELSE 0 END), 0) as expired_val
                 FROM products",
                [$expiryAlertDays]
            );
            if ($countRow) {
                $expiredCount = intval($countRow['expired_cnt'] ?? 0);
                $expiringCount = intval($countRow['expiring_cnt'] ?? 0);
                $expiredValue = floatval($countRow['expired_val'] ?? 0);
            }
        } catch (\Throwable $e) {}

        // === قائمة المنتجات منتهية الصلاحية (كلها — بدون حد) ===
        $expired = [];
        try {
            $expired = $db->fetchAll(
                "SELECT id, name, code, quantity, unit, expiry_date,
                        purchase_price, selling_price,
                        COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                        DATEDIFF(CURDATE(), expiry_date) as days_expired
                 FROM products
                 WHERE has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
                 ORDER BY expiry_date ASC"
            ) ?: [];
        } catch (\Throwable $e) {}

        // === قائمة المنتجات قريبة الصلاحية (كلها — بدون حد) ===
        $expiring = [];
        try {
            $expiring = $db->fetchAll(
                "SELECT id, name, code, quantity, unit, expiry_date,
                        purchase_price, selling_price,
                        COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                        DATEDIFF(expiry_date, CURDATE()) as days_remaining
                 FROM products
                 WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                 AND expiry_date >= CURDATE()
                 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 ORDER BY expiry_date ASC",
                [$expiryAlertDays]
            ) ?: [];
        } catch (\Throwable $e) {}

        // إنشاء توقيع فريد (يتغير عند وجود تنبيهات جديدة)
        $signature = md5(serialize($expired) . serialize($expiring) . date('Y-m-d'));

        return [
            'enabled' => true,
            'expired' => $expired,
            'expiring' => $expiring,
            'expired_count' => $expiredCount,
            'expiring_count' => $expiringCount,
            'expired_value' => round($expiredValue, 2),
            'alert_days' => $expiryAlertDays,
            'signature' => $signature,
        ];
    }

    /**
     * جلب تنبيهات المخزون المنخفض — قائمة المنتجات (كلها)
     *
     * @param AppDatabase $db اتصال قاعدة البيانات
     * @return array قائمة المنتجات منخفضة المخزون
     */
    private function fetchLowStockAlerts(AppDatabase $db): array
    {
        $threshold = 5;
        try {
            $threshold = intval($db->getSetting('low_stock_threshold', '5'));
        } catch (\Throwable $e) {}

        $lowStockEnabled = true;
        try {
            $lowStockEnabled = $db->getSetting('low_stock_alert', '1') === '1';
        } catch (\Throwable $e) {}

        if (!$lowStockEnabled) {
            return [];
        }

        try {
            return $db->fetchAll(
                "SELECT p.id, p.name, p.code, p.quantity, p.unit, 
                        p.min_quantity, p.purchase_price, p.selling_price,
                        p.category_id,
                        COALESCE((SELECT name FROM categories WHERE id = p.category_id), '') as category_name,
                        CASE 
                            WHEN p.min_quantity > 0 AND p.quantity <= 0 THEN 'out_of_stock'
                            WHEN p.min_quantity > 0 AND p.quantity <= p.min_quantity THEN 'below_minimum'
                            WHEN p.min_quantity = 0 AND p.quantity <= ? THEN 'below_threshold'
                            ELSE 'normal'
                        END as stock_status,
                        GREATEST(p.min_quantity, ?) as expected_minimum
                 FROM products p
                 WHERE p.quantity <= p.min_quantity 
                    OR (p.min_quantity = 0 AND p.quantity <= ?)
                 ORDER BY p.quantity ASC",
                [$threshold, $threshold, $threshold]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * جلب تنبيهات الدفعات قريبة الصلاحية (كلها) — مفصولة expired/expiring
     *
     * @param AppDatabase $db اتصال قاعدة البيانات
     * @return array ['expired' => [...], 'expiring' => [...]]
     */
    private function fetchBatchExpiryAlerts(AppDatabase $db): array
    {
        $expiryAlertDays = 30;
        try {
            $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30'));
        } catch (\Throwable $e) {}

        $pdo = $db->getConnection();

        // التحقق من وجود جدول purchase_batches
        try {
            $check = $pdo->query("SHOW TABLES LIKE 'purchase_batches'");
            if (empty($check->fetch())) {
                return ['expired' => [], 'expiring' => []];
            }
        } catch (\Throwable $e) {
            return ['expired' => [], 'expiring' => []];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT pb.id, pb.product_id, pb.batch_number, 
                        pb.remaining_quantity, pb.unit_price,
                        pb.expiry_date, pb.purchase_date,
                        p.name as product_name, p.code as product_code, p.unit,
                        DATEDIFF(pb.expiry_date, CURDATE()) as days_to_expiry,
                        CASE
                            WHEN pb.expiry_date < CURDATE() THEN 'expired'
                            ELSE 'expiring'
                        END as expiry_status
                 FROM purchase_batches pb
                 JOIN products p ON pb.product_id = p.id
                 WHERE pb.remaining_quantity > 0
                 AND pb.expiry_date IS NOT NULL
                 AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 ORDER BY pb.expiry_date ASC"
            );
            $stmt->execute([$expiryAlertDays]);
            $allBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($allBatches)) {
                return ['expired' => [], 'expiring' => []];
            }

            // فصل الدفعات إلى منتهية وقريبة
            $expired = [];
            $expiring = [];
            foreach ($allBatches as $batch) {
                $status = $batch['expiry_status'] ?? '';
                if ($status === 'expired') {
                    $expired[] = $batch;
                } else {
                    $expiring[] = $batch;
                }
            }

            return ['expired' => $expired, 'expiring' => $expiring];
        } catch (\Throwable $e) {
            return ['expired' => [], 'expiring' => []];
        }
    }

    // ========================================
    // دوال مساعدة خاصة
    // ========================================

    /**
     * جلب الإحصائيات الشهرية مباشرة (بدون DashboardData)
     *
     * @param int $months عدد الأشهر
     * @return array بيانات شهرية
     */
    private function fetchMonthlyDirect(int $months): array
    {
        $db = AppDatabase::getInstance();
        $monthStart = date('Y-m-01', strtotime("-" . ($months - 1) . " months"));
        $monthEnd = date('Y-m-t');

        // مبيعات + أرباح بالشهر
        $salesByMonth = [];
        try {
            $rows = $db->fetchAll(
                "SELECT DATE_FORMAT(sale_date, '%Y-%m') AS ym,
                        COALESCE(SUM(total), 0) AS sales_total,
                        COALESCE(SUM(profit), 0) AS profit_total
                 FROM sales
                 WHERE sale_date BETWEEN ? AND ?
                 GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                 ORDER BY ym ASC",
                [$monthStart, $monthEnd]
            );
            foreach ($rows as $r) {
                $salesByMonth[$r['ym']] = [
                    'sales'  => floatval($r['sales_total']),
                    'profit' => floatval($r['profit_total']),
                ];
            }
        } catch (\Throwable $e) {}

        // مشتريات بالشهر
        $purchasesByMonth = [];
        try {
            $rows = $db->fetchAll(
                "SELECT DATE_FORMAT(purchase_date, '%Y-%m') AS ym,
                        COALESCE(SUM(total), 0) AS purchases_total
                 FROM purchases
                 WHERE purchase_date BETWEEN ? AND ?
                 GROUP BY DATE_FORMAT(purchase_date, '%Y-%m')
                 ORDER BY ym ASC",
                [$monthStart, $monthEnd]
            );
            foreach ($rows as $r) {
                $purchasesByMonth[$r['ym']] = floatval($r['purchases_total']);
            }
        } catch (\Throwable $e) {}

        // تجميع النتائج
        $stats = [];
        for ($i = ($months - 1); $i >= 0; $i--) {
            $timestamp = strtotime("-{$i} months");
            $ym = date('Y-m', $timestamp);
            $monthNum = intval(date('m', $timestamp));
            $monthNames = [
                1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
            ];

            $sales     = $salesByMonth[$ym]['sales'] ?? 0.0;
            $profit    = $salesByMonth[$ym]['profit'] ?? 0.0;
            $purchases = $purchasesByMonth[$ym] ?? 0.0;

            $stats[] = [
                'month_label' => $monthNames[$monthNum] ?? date('M', $timestamp),
                'year_label'  => date('Y', $timestamp),
                'sales'       => $sales,
                'purchases'   => $purchases,
                'profit'      => $profit,
                'expenses'    => 0.0,
            ];
        }

        return $stats;
    }
}
