<?php
/**
 * ===== مساعد الصندوق (CaisseHelper) =====
 * يستخرج منطق الصندوق الثقيل من caisse.php إلى صنف قابل لإعادة الاستخدام
 *
 * الاستخدام:
 *   require_once 'includes/CaisseHelper.php';
 *   $pdo = getDBConnection();
 *   $today = date('Y-m-d');
 *   $stats = CaisseHelper::getTodayStats($pdo, $today);
 *
 * الميزات:
 *   - تجميع كل حسابات الصندوق في مكان واحد
 *   - إنشاء/تحديث سجل الصندوق اليومي تلقائياً
 *   - توليد السجلات المفقودة للأيام السابقة
 *   - حساب صافي حركات الصندوق (إيداعات - مسحوبات)
 *   - إجماليات المشروع الشاملة
 *
 * v4.18.3: استبدال كتل catch الفارغة بتسجيل الأخطاء + أنواع إرجاع
 */

class CaisseHelper
{
    // ========================================
    // قوائم البيضاء للجداول والأعمدة (حماية من حقن SQL)
    // ========================================

    /** @var array<string> الجداول المسموح بها */
    private static array $ALLOWED_TABLES = ['sales', 'purchases', 'expenses', 'caisse_transactions', 'caisse'];

    /** @var array<string> أعمدة المبالغ المسموح بها */
    private static array $ALLOWED_SUM_COLUMNS = ['total', 'amount', 'subtotal', 'discount', 'tax'];

    /** @var array<string> أعمدة التواريخ المسموح بها */
    private static array $ALLOWED_DATE_COLUMNS = ['sale_date', 'purchase_date', 'expense_date', 'transaction_date', 'created_at', 'updated_at'];

    /**
     * التحقق من صحة اسم جدول (مقابل القائمة البيضاء)
     * @throws \InvalidArgumentException إذا كان الاسم غير مصرح به
     */
    private static function validateTableName(string $table): void
    {
        if (!in_array($table, self::$ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException("اسم الجدول غير مصرح به: {$table}");
        }
    }

    /**
     * التحقق من صحة اسم عمود (مقابل القائمة البيضاء)
     * @throws \InvalidArgumentException إذا كان الاسم غير مصرح به
     */
    private static function validateColumnName(string $column, array $allowed): void
    {
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("اسم العمود غير مصرح به: {$column}");
        }
    }

    // ========================================
    // إنشاء وتحديث سجل الصندوق اليومي
    // ========================================

    /**
     * إنشاء/تحديث سجل الصندوق اليومي تلقائياً
     * يحسب مبلغ البداية والرصيد الحالي بناءً على جميع المعاملات
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $today تاريخ اليوم (Y-m-d)
     * @return array بيانات اليوم المحسوبة
     */
    public static function autoCreateTodayRecord(PDO $pdo, string $today): array
    {
        // جلب مبلغ الإنطلاق العام للمشروع
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute(['project_start_amount']);
        $projectStartRow = $stmt->fetch();
        $projectStartAmount = $projectStartRow ? floatval($projectStartRow['setting_value']) : 0;

        // === حساب معاملات ما قبل اليوم ===
        $prevSales = self::getSumBeforeDate($pdo, 'sales', 'total', 'sale_date', $today);
        $prevPurchases = self::getSumBeforeDate($pdo, 'purchases', 'total', 'purchase_date', $today);
        $prevExpenses = self::getSumBeforeDateSafe($pdo, 'expenses', 'amount', 'expense_date', $today);
        $prevTx = self::getNetTransactions($pdo, $today, true);

        // مبلغ بداية اليوم = الإنطلاق + مبيعات سابقة - مشتريات سابقة - مصاريف سابقة + إيداعات - مسحوبات
        $todayStart = $projectStartAmount + $prevSales - $prevPurchases - $prevExpenses
                    + $prevTx['deposits'] - $prevTx['withdrawals'];

        // === حساب معاملات اليوم ===
        $todaySales = self::getSumOnDate($pdo, 'sales', 'total', 'sale_date', $today);
        $todayPurchases = self::getSumOnDate($pdo, 'purchases', 'total', 'purchase_date', $today);
        $todayExpenses = self::getSumOnDateSafe($pdo, 'expenses', 'amount', 'expense_date', $today);
        $todayTx = self::getNetTransactions($pdo, $today, false);

        // الرصيد الحالي = البداية + مبيعات اليوم - مشتريات اليوم - مصاريف اليوم + إيداعات - مسحوبات
        $todayCurrent = $todayStart + $todaySales - $todayPurchases - $todayExpenses
                      + $todayTx['deposits'] - $todayTx['withdrawals'];

        // === حفظ/تحديث السجل في قاعدة البيانات ===
        $existingStmt = $pdo->prepare("SELECT id, note FROM caisse WHERE record_date = ?");
        $existingStmt->execute([$today]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            // تحديث سجل موجود
            $pdo->prepare("UPDATE caisse SET start_amount = ?, current_amount = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$todayStart, $todayCurrent, $existing['id']]);
        } else {
            // إنشاء سجل جديد
            $pdo->prepare("INSERT INTO caisse (record_date, start_amount, current_amount) VALUES (?, ?, ?)")
                ->execute([$today, $todayStart, $todayCurrent]);
        }

        return [
            'start'       => $todayStart,
            'current'     => $todayCurrent,
            'sales'       => $todaySales,
            'purchases'   => $todayPurchases,
            'expenses'    => $todayExpenses,
            'deposits'    => $todayTx['deposits'],
            'withdrawals' => $todayTx['withdrawals'],
        ];
    }

    // ========================================
    // حساب صافي حركات الصندوق
    // ========================================

    /**
     * حساب صافي حركات الصندوق (إيداعات - مسحوبات) لتاريخ معين
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $date التاريخ
     * @param bool $before هل نحسب الحركات قبل هذا التاريخ (وليس فيه)
     * @return array مصفوفة تحتوي على deposits و withdrawals
     */
    public static function getNetTransactions(PDO $pdo, string $date, bool $before = false): array
    {
        try {
            if ($before) {
                // حركات قبل التاريخ المحدد
                $sql = "SELECT
                    COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) as deposits,
                    COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) as withdrawals
                    FROM caisse_transactions WHERE transaction_date < ?";
            } else {
                // حركات في التاريخ المحدد
                $sql = "SELECT
                    COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) as deposits,
                    COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) as withdrawals
                    FROM caisse_transactions WHERE transaction_date = ?";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$date]);
            $row = $stmt->fetch();

            return [
                'deposits'    => floatval($row['deposits'] ?? 0),
                'withdrawals' => floatval($row['withdrawals'] ?? 0),
            ];
        } catch (Exception $e) {
            self::logError($e, 'getNetTransactions');
            return ['deposits' => 0, 'withdrawals' => 0];
        }
    }

    // ========================================
    // توليد السجلات المفقودة
    // ========================================

    /**
     * توليد سجلات تلقائية للأيام التي فيها معاملات
     * يفحص آخر 30 يوم من المبيعات والمشتريات والمصاريف وحركات الصندوق
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param float $projectStartAmount مبلغ الإنطلاق العام للمشروع
     * @return int عدد السجلات المولّدة
     */
    public static function generateMissingRecords(PDO $pdo, float $projectStartAmount): int
    {
        $dates = [];

        // جمع كل التواريخ الممكنة من الجداول المختلفة
        $sources = [
            ["SELECT DISTINCT DATE(sale_date) as d FROM sales ORDER BY d DESC LIMIT 30"],
            ["SELECT DISTINCT DATE(purchase_date) as d FROM purchases ORDER BY d DESC LIMIT 30"],
            ["SELECT DISTINCT DATE(transaction_date) as d FROM caisse_transactions ORDER BY d DESC LIMIT 30"],
        ];

        foreach ($sources as [$sql]) {
            try {
                $stmt = $pdo->query($sql);
                while ($row = $stmt->fetch()) {
                    $dates[] = $row['d'];
                }
            } catch (Exception $e) {
                // الجدول غير موجود - تجاوز (متوقع في بعض التثبيتات)
                self::logError($e, 'generateMissingRecords::sources');
            }
        }

        // محاولة جلب تواريخ المصاريف
        try {
            $stmt = $pdo->query("SELECT DISTINCT DATE(expense_date) as d FROM expenses ORDER BY d DESC LIMIT 30");
            while ($row = $stmt->fetch()) {
                $dates[] = $row['d'];
            }
        } catch (Exception $e) {
            // جدول المصاريف غير موجود - تجاوز
            self::logError($e, 'generateMissingRecords::expenses');
        }

        // إزالة التكرارات وترتيب التواريخ
        $dates = array_unique($dates);
        sort($dates);

        $generated = 0;

        foreach ($dates as $date) {
            // تخطي إذا كان السجل موجوداً بالفعل
            $exists = $pdo->prepare("SELECT id FROM caisse WHERE record_date = ?");
            $exists->execute([$date]);
            if ($exists->fetch()) {
                continue;
            }

            // === حساب معاملات ما قبل هذا اليوم ===
            $prevSales     = self::getSumBeforeDate($pdo, 'sales', 'total', 'sale_date', $date);
            $prevPurchases = self::getSumBeforeDate($pdo, 'purchases', 'total', 'purchase_date', $date);
            $prevExpenses  = self::getSumBeforeDateSafe($pdo, 'expenses', 'amount', 'expense_date', $date);
            $prevTx        = self::getNetTransactions($pdo, $date, true);

            $dayStart = $projectStartAmount + $prevSales - $prevPurchases - $prevExpenses
                      + $prevTx['deposits'] - $prevTx['withdrawals'];

            // === حساب معاملات هذا اليوم ===
            $daySales     = self::getSumOnDate($pdo, 'sales', 'total', 'sale_date', $date);
            $dayPurchases = self::getSumOnDate($pdo, 'purchases', 'total', 'purchase_date', $date);
            $dayExpenses  = self::getSumOnDateSafe($pdo, 'expenses', 'amount', 'expense_date', $date);
            $dayTx        = self::getNetTransactions($pdo, $date, false);

            $dayCurrent = $dayStart + $daySales - $dayPurchases - $dayExpenses
                        + $dayTx['deposits'] - $dayTx['withdrawals'];

            // إنشاء السجل
            $pdo->prepare("INSERT INTO caisse (record_date, start_amount, current_amount) VALUES (?, ?, ?)")
                ->execute([$date, $dayStart, $dayCurrent]);

            $generated++;
        }

        return $generated;
    }

    // ========================================
    // إجماليات المشروع
    // ========================================

    /**
     * الحصول على إجماليات المشروع الشاملة
     * تشمل: مبلغ الإنطلاق، إجمالي المبيعات، المشتريات، المصاريف، الإيداعات، المسحوبات، الرصيد الحالي
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @return array إجماليات المشروع
     */
    public static function getProjectTotals(PDO $pdo): array
    {
        // جلب مبلغ الإنطلاق العام
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute(['project_start_amount']);
        $row = $stmt->fetch();
        $projectStartAmount = $row ? floatval($row['setting_value']) : 0;

        // إجمالي المبيعات
        $totalSales = 0.0;
        try {
            $totalSales = floatval(
                $pdo->query("SELECT COALESCE(SUM(total), 0) FROM sales")->fetchColumn()
            );
        } catch (Exception $e) {
            self::logError($e, 'getProjectTotals::sales');
        }

        // إجمالي المشتريات
        $totalPurchases = 0.0;
        try {
            $totalPurchases = floatval(
                $pdo->query("SELECT COALESCE(SUM(total), 0) FROM purchases")->fetchColumn()
            );
        } catch (Exception $e) {
            self::logError($e, 'getProjectTotals::purchases');
        }

        // إجمالي المصاريف
        $totalExpenses = 0.0;
        try {
            $totalExpenses = floatval(
                $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn()
            );
        } catch (Exception $e) {
            self::logError($e, 'getProjectTotals::expenses');
        }

        // إجمالي حركات الصندوق (كل الحركات)
        $totalTx = self::getNetTransactions($pdo, '2099-12-31', true);
        $totalDeposits = $totalTx['deposits'];
        $totalWithdrawals = $totalTx['withdrawals'];

        // حساب الرصيد الحالي
        $projectCurrentAmount = $projectStartAmount + $totalSales - $totalPurchases - $totalExpenses
                              + $totalDeposits - $totalWithdrawals;

        return [
            'project_start_amount' => $projectStartAmount,
            'total_sales'          => $totalSales,
            'total_purchases'      => $totalPurchases,
            'total_expenses'       => $totalExpenses,
            'total_deposits'       => $totalDeposits,
            'total_withdrawals'    => $totalWithdrawals,
            'project_current'      => $projectCurrentAmount,
        ];
    }

    // ========================================
    // إحصائيات اليوم
    // ========================================

    /**
     * الحصول على إحصائيات الصندوق لليوم الحالي
     * يشمل: مبلغ البداية، الرصيد الحالي، تفاصيل المعاملات، العد الفعلي
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $today تاريخ اليوم (Y-m-d)
     * @return array إحصائيات اليوم
     */
    public static function getTodayStats(PDO $pdo, string $today): array
    {
        // إنشاء/تحديث سجل اليوم تلقائياً
        $todayData = self::autoCreateTodayRecord($pdo, $today);

        // جلب السجل الكامل من قاعدة البيانات
        $todayRecord = null;
        try {
            $stmt = $pdo->prepare("SELECT * FROM caisse WHERE record_date = ?");
            $stmt->execute([$today]);
            $todayRecord = $stmt->fetch() ?: null;
        } catch (Exception $e) {
            self::logError($e, 'getTodayStats::fetchRecord');
        }

        // actual_column مضمون من upgrade.php (db_upgrade_4_9_76)
        $hasActualColumn = true;
        $actualAmount = null;
        $difference = null;
        $note = $todayRecord ? ($todayRecord['note'] ?? '') : '';

        // جلب العد الفعلي إن وجد
        if ($hasActualColumn && $todayRecord) {
            try {
                $stmt = $pdo->prepare("SELECT actual_amount FROM caisse WHERE record_date = ?");
                $stmt->execute([$today]);
                $actualRow = $stmt->fetch();
                if ($actualRow && $actualRow['actual_amount'] !== null) {
                    $actualAmount = floatval($actualRow['actual_amount']);
                    $difference = $actualAmount - floatval($todayData['current']);
                }
            } catch (Exception $e) {
                self::logError($e, 'getTodayStats::actualAmount');
            }
        }

        return [
            // بيانات السجل
            'start_amount'  => floatval($todayData['start']),
            'current_amount' => floatval($todayData['current']),
            'record'        => $todayRecord,

            // تفاصيل معاملات اليوم
            'today_sales'       => $todayData['sales'],
            'today_purchases'   => $todayData['purchases'],
            'today_expenses'    => $todayData['expenses'],
            'today_deposits'    => $todayData['deposits'],
            'today_withdrawals' => $todayData['withdrawals'],

            // العد الفعلي
            'has_actual_column' => $hasActualColumn,
            'actual_amount'     => $actualAmount,
            'difference'        => $difference,
            'note'              => $note,
        ];
    }

    // ========================================
    // دوال مساعدة داخلية
    // ========================================

    /**
     * جلب مجموع عمود قبل تاريخ محدد
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $table اسم الجدول
     * @param string $sumColumn اسم عمود المجموع
     * @param string $dateColumn اسم عمود التاريخ
     * @param string $date التاريخ
     * @return float المجموع
     */
    private static function getSumBeforeDate(PDO $pdo, string $table, string $sumColumn, string $dateColumn, string $date): float
    {
        self::validateTableName($table);
        self::validateColumnName($sumColumn, self::$ALLOWED_SUM_COLUMNS);
        self::validateColumnName($dateColumn, self::$ALLOWED_DATE_COLUMNS);

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$sumColumn}`), 0) FROM `{$table}` WHERE DATE(`{$dateColumn}`) < ?");
            $stmt->execute([$date]);
            return floatval($stmt->fetchColumn());
        } catch (Exception $e) {
            self::logError($e, "getSumBeforeDate({$table})");
            return 0.0;
        }
    }

    /**
     * جلب مجموع عمود قبل تاريخ محدد (مع معالجة أخطاء الجدول غير الموجود)
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $table اسم الجدول
     * @param string $sumColumn اسم عمود المجموع
     * @param string $dateColumn اسم عمود التاريخ
     * @param string $date التاريخ
     * @return float المجموع
     */
    private static function getSumBeforeDateSafe(PDO $pdo, string $table, string $sumColumn, string $dateColumn, string $date): float
    {
        self::validateTableName($table);
        self::validateColumnName($sumColumn, self::$ALLOWED_SUM_COLUMNS);
        self::validateColumnName($dateColumn, self::$ALLOWED_DATE_COLUMNS);

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$sumColumn}`), 0) FROM `{$table}` WHERE DATE(`{$dateColumn}`) < ?");
            $stmt->execute([$date]);
            return floatval($stmt->fetchColumn());
        } catch (Exception $e) {
            self::logError($e, "getSumBeforeDateSafe({$table})");
            return 0.0;
        }
    }

    /**
     * جلب مجموع عمود في تاريخ محدد
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $table اسم الجدول
     * @param string $sumColumn اسم عمود المجموع
     * @param string $dateColumn اسم عمود التاريخ
     * @param string $date التاريخ
     * @return float المجموع
     */
    private static function getSumOnDate(PDO $pdo, string $table, string $sumColumn, string $dateColumn, string $date): float
    {
        self::validateTableName($table);
        self::validateColumnName($sumColumn, self::$ALLOWED_SUM_COLUMNS);
        self::validateColumnName($dateColumn, self::$ALLOWED_DATE_COLUMNS);

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$sumColumn}`), 0) FROM `{$table}` WHERE DATE(`{$dateColumn}`) = ?");
            $stmt->execute([$date]);
            return floatval($stmt->fetchColumn());
        } catch (Exception $e) {
            self::logError($e, "getSumOnDate({$table})");
            return 0.0;
        }
    }

    /**
     * جلب مجموع عمود في تاريخ محدد (مع معالجة أخطاء الجدول غير الموجود)
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string $table اسم الجدول
     * @param string $sumColumn اسم عمود المجموع
     * @param string $dateColumn اسم عمود التاريخ
     * @param string $date التاريخ
     * @return float المجموع
     */
    private static function getSumOnDateSafe(PDO $pdo, string $table, string $sumColumn, string $dateColumn, string $date): float
    {
        self::validateTableName($table);
        self::validateColumnName($sumColumn, self::$ALLOWED_SUM_COLUMNS);
        self::validateColumnName($dateColumn, self::$ALLOWED_DATE_COLUMNS);

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$sumColumn}`), 0) FROM `{$table}` WHERE DATE(`{$dateColumn}`) = ?");
            $stmt->execute([$date]);
            return floatval($stmt->fetchColumn());
        } catch (Exception $e) {
            self::logError($e, "getSumOnDateSafe({$table})");
            return 0.0;
        }
    }

    // ========================================
    // تسجيل الأخطاء الداخلي
    // ========================================

    /**
     * تسجيل الخطأ — يستخدم ErrorHandler إن كان متوفراً، وإلا error_log
     *
     * @param \Throwable $e الاستثناء
     * @param string $context سياق إضافي
     */
    private static function logError(\Throwable $e, string $context = ''): void
    {
        if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
            ErrorHandler::logException($e, 'CaisseHelper::' . $context);
        } else {
            $file = basename($e->getFile());
            error_log("[ISM] CaisseHelper::{$context}: {$e->getMessage()} في {$file}:{$e->getLine()}");
        }
    }
}
