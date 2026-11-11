<?php
/**
 * ===== بيانات لوحة التحكم (DashboardData) =====
 * يستخرج منطق الإحصائيات من index.php إلى صنف قابل لإعادة الاستخدام
 *
 * الاستخدام:
 *   require_once 'includes/DashboardData.php';
 *   $stats = DashboardData::getStats('2025-01-01', '2025-06-30');
 *   echo $stats['total_sales'];
 *
 * الميزات:
 *   - تجميع كل حسابات لوحة التحكم في مكان واحد
 *   - ❌ بدون تخزين مؤقت — بيانات حقيقية دائماً
 *   - ✅ استخدام SaleService::calculateProfits() لحساب الأرباح (مُوحَّد مع صفحة الأرباح)
 *   - مقارنة الفترات (الحالية vs السابقة)
 *   - إحصائيات شهرية للرسم البياني (مُحسّنة باستعلام واحد GROUP BY)
 *
 * v5.6.0: إزالة التخزين المؤقت نهائياً + استخدام SaleService
 *   - بيانات حقيقية دائماً بدون تأخير
 *   - نفس منطق صفحة الأرباح المُوحَّد
 *   - لا مزيد من البيانات القديمة
 */

require_once __DIR__ . '/Services/SaleService.php';

class DashboardData
{
    /** @var int عدد الأشهر للرسم البياني */
    private const CHART_MONTHS = 6;

    /** @var array أسماء الأشهر (تُملأ عبر t() لدعم تعدد اللغات) */
    private static array $arabicMonths = [];

    /**
     * الحصول على أسماء الأشهر المترجمة
     */
    private static function getMonthNames(): array
    {
        if (!empty(self::$arabicMonths)) {
            return self::$arabicMonths;
        }
        self::$arabicMonths = [
            1  => t('month_january'),
            2  => t('month_february'),
            3  => t('month_march'),
            4  => t('month_april'),
            5  => t('month_may'),
            6  => t('month_june'),
            7  => t('month_july'),
            8  => t('month_august'),
            9  => t('month_september'),
            10 => t('month_october'),
            11 => t('month_november'),
            12 => t('month_december'),
        ];
        return self::$arabicMonths;
    }

    /**
     * الحصول على جميع إحصائيات لوحة التحكم
     * ❌ بدون كاش — بيانات حقيقية دائماً
     *
     * @param string $dateFrom تاريخ بداية الفترة (Y-m-d)
     * @param string $dateTo تاريخ نهاية الفترة (Y-m-d)
     * @return array مصفوفة تحتوي على كل الإحصائيات
     */
    public static function getStats(string $dateFrom, string $dateTo): array
    {
        // التحقق من صحة التواريخ
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = date('Y-m-d');
        }

        // === حساب الفترة السابقة للمقارنة ===
        $periodDays = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400);
        $prevFrom = date('Y-m-d', strtotime($dateFrom . " - {$periodDays} days"));
        $prevTo   = date('Y-m-d', strtotime($dateFrom . " - 1 day"));

        // === التحقق من جدول المصاريف (مرة واحدة فقط!) ===
        $expensesTableExists = self::checkExpensesTable();

        // ========================================
        // ✅ استخدام SaleService لحساب الأرباح
        // (مُوحَّد مع صفحة حساب الأرباح — نفس المنطق بالضبط)
        // ========================================
        $currentProfits = SaleService::calculateProfits($dateFrom, $dateTo, 100, false);
        $prevProfits = SaleService::calculateProfits($prevFrom, $prevTo, 100, false);

        // --- إحصائيات المنتجات: استعلام واحد يُرجع 3 قيم ---
        $productStats = self::fetchProductStats();

        // --- المشتريات: استعلام واحد بـ CASE WHEN (الحالي + السابق) ---
        $purchasesStats = self::fetchPurchasesStats($dateFrom, $dateTo, $prevFrom, $prevTo);

        // --- الإحصائيات الشهرية: استعلامات GROUP BY ---
        $monthlyStats = self::fetchMonthlyStatsGrouped($expensesTableExists);

        // --- آخر العناصر ---
        $recentProducts   = self::fetchRecentProducts();
        $recentPurchases  = self::fetchRecentPurchases();
        $recentSales      = self::fetchRecentSales();
        $recentExpenses   = $expensesTableExists ? self::fetchRecentExpenses() : [];

        // === تجميع كل البيانات ===
        $stats = [
            // تواريخ الفترة
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'prev_from'     => $prevFrom,
            'prev_to'       => $prevTo,

            // الإحصائيات الأساسية (من SaleService + fetchProductStats)
            'total_products'         => $productStats['count'],
            'total_stock_value'      => $currentProfits['stock_value'] ?? $productStats['stock_value'],
            'total_stock_value_retail' => $currentProfits['stock_value_retail'] ?? $productStats['stock_value_retail'],
            'total_purchases'        => $purchasesStats['current_total'],
            'total_sales'       => $currentProfits['total_sales'],
            'low_stock_count'   => $productStats['low_stock'],

            // الأرباح والمصاريف (من SaleService — مُوحَّد مع صفحة الأرباح)
            'gross_profit'          => $currentProfits['gross_profit'],
            'net_profit'            => $currentProfits['net_profit'],
            'total_expenses'        => $currentProfits['total_expenses'],
            'total_taxes'           => $currentProfits['total_taxes'],
            'expenses_table_exists' => $expensesTableExists,

            // مقارنة مع الفترة السابقة (من SaleService)
            'prev_sales'        => $prevProfits['total_sales'],
            'prev_purchases'    => $purchasesStats['prev_total'],
            'prev_gross_profit' => $prevProfits['gross_profit'],
            'prev_net_profit'   => $prevProfits['net_profit'],
            'prev_expenses'     => $prevProfits['total_expenses'],

            // نسب التغيير
            'sales_change'        => self::calcChange($currentProfits['total_sales'], $prevProfits['total_sales']),
            'purchases_change'    => self::calcChange($purchasesStats['current_total'], $purchasesStats['prev_total']),
            'gross_profit_change' => self::calcChange($currentProfits['gross_profit'], $prevProfits['gross_profit']),
            'net_profit_change'   => self::calcChange($currentProfits['net_profit'], $prevProfits['net_profit']),
            'expenses_change'     => self::calcChange($currentProfits['total_expenses'], $prevProfits['total_expenses']),

            // الرسم البياني الشهري
            'monthly_stats' => $monthlyStats,

            // آخر العناصر
            'recent_products'  => $recentProducts,
            'recent_purchases' => $recentPurchases,
            'recent_sales'     => $recentSales,
            'recent_expenses'  => $recentExpenses,
        ];

        return $stats;
    }

    // ========================================
    // دوال جلب البيانات
    // ========================================

    /**
     * إحصائيات المنتجات: استعلام واحد يُرجع 3 قيم
     * سابقاً: 3 استعلامات منفصلة (COUNT + SUM stock + COUNT low_stock)
     *
     * @return array{count: int, stock_value: float, low_stock: int}
     */
    private static function fetchProductStats(): array
    {
        try {
            $row = fetchOne(
                "SELECT
                    COUNT(*) AS cnt,
                    COALESCE(SUM(quantity * purchase_price), 0) AS stock_val,
                    COALESCE(SUM(quantity * selling_price), 0) AS stock_val_retail,
                    SUM(CASE WHEN quantity <= min_quantity AND min_quantity > 0 THEN 1 ELSE 0 END) AS low_cnt
                 FROM products"
            );
            return [
                'count'              => intval($row['cnt'] ?? 0),
                'stock_value'        => floatval($row['stock_val'] ?? 0),
                'stock_value_retail' => floatval($row['stock_val_retail'] ?? 0),
                'low_stock'          => intval($row['low_cnt'] ?? 0),
            ];
        } catch (Exception $e) {
            return ['count' => 0, 'stock_value' => 0.0, 'stock_value_retail' => 0.0, 'low_stock' => 0];
        }
    }

    /**
     * إحصائيات المشتريات: استعلام واحد بـ CASE WHEN
     * سابقاً: 2 استعلامات (الحالية + السابقة)
     *
     * @return array{current_total: float, prev_total: float}
     */
    private static function fetchPurchasesStats(string $from, string $to, string $prevFrom, string $prevTo): array
    {
        try {
            $row = fetchOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN purchase_date BETWEEN ? AND ? THEN total ELSE 0 END), 0) AS cur_total,
                    COALESCE(SUM(CASE WHEN purchase_date BETWEEN ? AND ? THEN total ELSE 0 END), 0) AS prev_total
                 FROM purchases
                 WHERE purchase_date BETWEEN ? AND ?",
                [$from, $to, $prevFrom, $prevTo, $prevFrom, $to]
            );
            return [
                'current_total' => floatval($row['cur_total'] ?? 0),
                'prev_total'    => floatval($row['prev_total'] ?? 0),
            ];
        } catch (Exception $e) {
            return ['current_total' => 0.0, 'prev_total' => 0.0];
        }
    }

    /**
     * التحقق من وجود جدول المصاريف
     */
    private static function checkExpensesTable(): bool
    {
        try {
            return fetchOne("SHOW TABLES LIKE 'expenses'") ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * الإحصائيات الشهرية: استعلامات GROUP BY بدل حلقات N+1
     *
     * سابقاً: 6 أشهر × (1 sales + 1 purchases + 1 profit + 1 expenses) = 18-24 استعلام
     * الآن: 2-3 استعلامات فقط باستخدام GROUP BY DATE_FORMAT
     *
     * @param bool $expensesTableExists هل جدول المصاريف موجود؟
     * @return array مصفوفة إحصائيات شهرية
     */
    private static function fetchMonthlyStatsGrouped(bool $expensesTableExists): array
    {
        // حساب نطاق الأشهر الـ 6 الأخيرة
        $monthStart = date('Y-m-01', strtotime('-' . (self::CHART_MONTHS - 1) . ' months'));
        $monthEnd   = date('Y-m-t'); // حتى نهاية الشهر الحالي

        // --- استعلام واحد للمبيعات (total + profit) مجمع بالشهر ---
        $salesByMonth = [];
        try {
            $rows = fetchAll(
                "SELECT
                    DATE_FORMAT(sale_date, '%Y-%m') AS ym,
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
        } catch (Exception $e) {}

        // --- استعلام واحد للمشتريات مجمع بالشهر ---
        $purchasesByMonth = [];
        try {
            $rows = fetchAll(
                "SELECT
                    DATE_FORMAT(purchase_date, '%Y-%m') AS ym,
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
        } catch (Exception $e) {}

        // --- استعلام واحد للمصاريف مجمع بالشهر (اختياري) ---
        $expensesByMonth = [];
        if ($expensesTableExists) {
            try {
                $rows = fetchAll(
                    "SELECT
                        DATE_FORMAT(expense_date, '%Y-%m') AS ym,
                        COALESCE(SUM(amount), 0) AS expenses_total
                     FROM expenses
                     WHERE expense_date BETWEEN ? AND ?
                     GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                     ORDER BY ym ASC",
                    [$monthStart, $monthEnd]
                );
                foreach ($rows as $r) {
                    $expensesByMonth[$r['ym']] = floatval($r['expenses_total']);
                }
            } catch (Exception $e) {}
        }

        // --- تجميع النتائج في مصفوفة الأشهر ---
        $stats = [];
        for ($i = (self::CHART_MONTHS - 1); $i >= 0; $i--) {
            $timestamp   = strtotime("-{$i} months");
            $ym          = date('Y-m', $timestamp);
            $monthNum    = intval(date('m', $timestamp));
            $yearLabel   = date('Y', $timestamp);
            $monthLabel  = self::getMonthNames()[$monthNum] ?? date('M', $timestamp);

            $sales     = $salesByMonth[$ym]['sales'] ?? 0.0;
            $profit    = $salesByMonth[$ym]['profit'] ?? 0.0;
            $purchases = $purchasesByMonth[$ym] ?? 0.0;
            $expenses  = $expensesByMonth[$ym] ?? 0.0;

            $stats[] = [
                'month_label'       => $monthLabel,
                'year_label'        => $yearLabel,
                'sales'             => $sales,
                'purchases'         => $purchases,
                'profit'            => $profit,
                'expenses'          => $expenses,
                'sales_height'      => 0, // سيتم حسابه لاحقاً
                'purchases_height'  => 0, // سيتم حسابه لاحقاً
            ];
        }

        // === حساب ارتفاع الأشرطة كنسبة مئوية من القيمة القصوى ===
        $allVals = array_merge(
            array_column($stats, 'sales'),
            array_column($stats, 'purchases')
        );
        $maxVal = max(array_merge($allVals, [1]));

        foreach ($stats as &$ms) {
            $ms['sales_height']     = round(($ms['sales'] / $maxVal) * 100);
            $ms['purchases_height'] = round(($ms['purchases'] / $maxVal) * 100);
        }
        unset($ms);

        return $stats;
    }

    /**
     * جلب آخر المنتجات المضافة
     */
    private static function fetchRecentProducts(): array
    {
        try {
            return fetchAll("SELECT * FROM products ORDER BY created_at DESC LIMIT 5") ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * جلب آخر المشتريات
     */
    private static function fetchRecentPurchases(): array
    {
        try {
            return fetchAll(
                "SELECT p.*, pr.name as product_name, pr.code
                 FROM purchases p
                 JOIN products pr ON p.product_id = pr.id
                 ORDER BY p.created_at DESC LIMIT 5"
            ) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * جلب آخر المبيعات
     */
    private static function fetchRecentSales(): array
    {
        try {
            return fetchAll(
                "SELECT s.*, pr.name as product_name, pr.code
                 FROM sales s
                 JOIN products pr ON s.product_id = pr.id
                 ORDER BY s.created_at DESC LIMIT 5"
            ) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * جلب آخر المصاريف
     */
    private static function fetchRecentExpenses(): array
    {
        try {
            return fetchAll("SELECT * FROM expenses ORDER BY created_at DESC LIMIT 5") ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    // ========================================
    // دوال مساعدة لحساب التغييرات
    // ========================================

    /**
     * حساب نسبة التغيير بين قيمتين
     *
     * @param float|int $current القيمة الحالية
     * @param float|int $previous القيمة السابقة
     * @return array مصفوفة تحتوي على نسبة التغيير واتجاهه
     */
    public static function calcChange(float|int $current, float|int $previous): array
    {
        if ($previous == 0 && $current == 0) return ['pct' => 0, 'dir' => 'same'];
        if ($previous == 0) return ['pct' => 100, 'dir' => 'up'];

        $pct = round((($current - $previous) / $previous) * 100, 1);

        if ($pct > 0) return ['pct' => $pct, 'dir' => 'up'];
        if ($pct < 0) return ['pct' => abs($pct), 'dir' => 'down'];
        return ['pct' => 0, 'dir' => 'same'];
    }

    /**
     * توليد شارة HTML لنسبة التغيير (للاستخدام في العرض)
     *
     * @param float|int $current القيمة الحالية
     * @param float|int $previous القيمة السابقة
     * @return string كود HTML للشارة
     */
    public static function renderChangeBadge(float|int $current, float|int $previous): string
    {
        $change = self::calcChange($current, $previous);

        if ($change['dir'] === 'up') {
            return '<div style="font-size:0.75rem; color:#059669; font-weight:600; margin-top:4px;">+' . $change['pct'] . '%</div>';
        } elseif ($change['dir'] === 'down') {
            return '<div style="font-size:0.75rem; color:#dc2626; font-weight:600; margin-top:4px;">-' . $change['pct'] . '%</div>';
        } else {
            return '<div style="font-size:0.75rem; color:#94a3b8; font-weight:600; margin-top:4px;">0%</div>';
        }
    }
}
