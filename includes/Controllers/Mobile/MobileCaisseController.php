<?php
/**
 * ===== متحكم صندوق الموبايل (MobileCaisseController) =====
 * يوفر عمليات الصندوق (الصندوق النقدي) عبر API الموبايل
 *
 * المسارات:
 *   GET  /api/mobile/caisse/status                     — حالة الصندوق الحالية
 *   GET  /api/mobile/caisse/history                    — سجل الصندوق التاريخي
 *   GET  /api/mobile/caisse/transactions               — قائمة حركات الصندوق
 *   POST /api/mobile/caisse/transactions               — إضافة حركة (إيداع/سحب)
 *   DELETE /api/mobile/caisse/transactions/{id}        — حذف حركة
 *   POST /api/mobile/caisse/project-amount             — تحديث مبلغ انطلاق المشروع
 *   POST /api/mobile/caisse/actual-count               — تسجيل العد الفعلي
 *
 * v5.11.0: إنشاء متحكم صندوق الموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileCaisseController extends ApiController
{
    /**
     * حالة الصندوق الحالية
     *
     * GET /api/mobile/caisse/status
     * يرجع ملخصاً شاملاً لحالة الصندوق لليوم الحالي + إجماليات المشروع
     */
    public function status(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $today = date('Y-m-d');

            // استخدام CaisseHelper لإحصائيات اليوم
            if (class_exists('CaisseHelper')) {
                $todayStats = CaisseHelper::getTodayStats($pdo, $today);
                $projectTotals = CaisseHelper::getProjectTotals($pdo);
            } else {
                // fallback إذا لم يتوفر CaisseHelper
                $todayStats = $this->getBasicTodayStats($pdo, $today);
                $projectTotals = $this->getBasicProjectTotals($pdo);
            }

            ApiResponse::success([
                'today' => [
                    'date' => $today,
                    'start_amount' => floatval($todayStats['start_amount'] ?? 0),
                    'current_amount' => floatval($todayStats['current_amount'] ?? 0),
                    'sales' => floatval($todayStats['today_sales'] ?? 0),
                    'purchases' => floatval($todayStats['today_purchases'] ?? 0),
                    'expenses' => floatval($todayStats['today_expenses'] ?? 0),
                    'deposits' => floatval($todayStats['today_deposits'] ?? 0),
                    'withdrawals' => floatval($todayStats['today_withdrawals'] ?? 0),
                    'actual_amount' => $todayStats['actual_amount'] ?? null,
                    'difference' => $todayStats['difference'] ?? null,
                    'note' => $todayStats['note'] ?? '',
                ],
                'project' => $projectTotals,
                'currency' => function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.م.',
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::status');
            }
            ApiResponse::serverError('خطأ في جلب حالة الصندوق');
        }
    }

    /**
     * سجل الصندوق التاريخي
     *
     * GET /api/mobile/caisse/history?days=7&page=1&per_page=15
     */
    public function history(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $days = min(90, max(1, intval($this->input('days', 7))));
            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(50, max(1, intval($this->input('per_page', 15))));
            $offset = ($page - 1) * $perPage;

            // عدد الإجمالي
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM caisse WHERE record_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
            );
            $countStmt->execute([$days]);
            $total = intval($countStmt->fetchColumn());

            // جلب السجلات
            $stmt = $pdo->prepare(
                "SELECT c.*, u.username as created_by_name
                 FROM caisse c
                 LEFT JOIN users u ON c.created_by = u.id
                 WHERE c.record_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 ORDER BY c.record_date DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$days, $perPage, $offset]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $records = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'date' => $row['record_date'],
                    'start_amount' => floatval($row['start_amount']),
                    'current_amount' => floatval($row['current_amount']),
                    'actual_amount' => $row['actual_amount'] !== null ? floatval($row['actual_amount']) : null,
                    'note' => $row['note'] ?? '',
                    'difference' => $row['actual_amount'] !== null
                        ? floatval($row['actual_amount']) - floatval($row['current_amount'])
                        : null,
                ];
            }, $records);

            ApiResponse::success([
                'data' => $records,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::history');
            }
            ApiResponse::serverError('خطأ في جلب سجل الصندوق');
        }
    }

    /**
     * قائمة حركات الصندوق
     *
     * GET /api/mobile/caisse/transactions?type=deposit&date=2026-05-26&page=1&per_page=20
     */
    public function transactions(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            // تصفية
            $type = trim($this->input('type', ''));
            $date = trim($this->input('date', ''));

            $where = [];
            $bindings = [];

            if (in_array($type, ['deposit', 'withdrawal'])) {
                $where[] = "ct.type = ?";
                $bindings[] = $type;
            }
            if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = "ct.transaction_date = ?";
                $bindings[] = $date;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // عدد الإجمالي
            $countSql = "SELECT COUNT(*) FROM caisse_transactions ct {$whereClause}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($bindings);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $sql = "SELECT ct.*, u.username as created_by_name
                    FROM caisse_transactions ct
                    LEFT JOIN users u ON ct.created_by = u.id
                    {$whereClause}
                    ORDER BY ct.created_at DESC
                    LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($bindings, [$perPage, $offset]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $transactions = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'type' => $row['type'],
                    'type_label' => $row['type'] === 'deposit' ? 'إيداع' : 'سحب',
                    'amount' => floatval($row['amount']),
                    'description' => $row['description'] ?? '',
                    'transaction_date' => $row['transaction_date'],
                    'created_by' => intval($row['created_by'] ?? 0),
                    'created_by_name' => $row['created_by_name'] ?? '',
                    'created_at' => $row['created_at'],
                ];
            }, $rows);

            ApiResponse::success([
                'data' => $transactions,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::transactions');
            }
            ApiResponse::serverError('خطأ في جلب حركات الصندوق');
        }
    }

    /**
     * إضافة حركة صندوق (إيداع أو سحب)
     *
     * POST /api/mobile/caisse/transactions
     * Body: { "type": "deposit", "amount": 5000, "description": "إيداع من المالك", "transaction_date": "2026-05-26" }
     */
    public function storeTransaction(array $params = []): void
    {
        $this->requirePost();

        try {
            $type = trim($this->input('type', ''));
            $amount = floatval($this->input('amount', 0));
            $description = trim($this->input('description', ''));
            $transactionDate = trim($this->input('transaction_date', ''));

            // التحقق
            $errors = [];
            if (!in_array($type, ['deposit', 'withdrawal'])) {
                $errors[] = 'نوع الحركة يجب أن يكون deposit أو withdrawal';
            }
            if ($amount <= 0) {
                $errors[] = 'المبلغ يجب أن يكون أكبر من صفر';
            }
            if (empty($transactionDate)) {
                $transactionDate = date('Y-m-d');
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
                $errors[] = 'صيغة التاريخ غير صالحة';
            }

            if (!empty($errors)) {
                ApiResponse::validationError($errors);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $createdBy = intval($_SESSION['user_id'] ?? 0);

            // إذا كان سحباً، نتحقق من وجود رصيد كافٍ
            if ($type === 'withdrawal') {
                $today = $transactionDate;
                if (class_exists('CaisseHelper')) {
                    $stats = CaisseHelper::getTodayStats($pdo, $today);
                    $currentAmount = floatval($stats['current_amount'] ?? 0);
                    if ($amount > $currentAmount) {
                        ApiResponse::error(
                            "المبلغ المطلوب سحبه ({$amount}) يتجاوز الرصيد الحالي ({$currentAmount})",
                            'insufficient_funds',
                            422
                        );
                        return;
                    }
                }
            }

            $stmt = $pdo->prepare(
                "INSERT INTO caisse_transactions (type, amount, description, transaction_date, created_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$type, $amount, $description ?: null, $transactionDate, $createdBy ?: null]);

            $id = intval($pdo->lastInsertId());

            // تحديث سجل الصندوق اليومي
            if (class_exists('CaisseHelper')) {
                CaisseHelper::autoCreateTodayRecord($pdo, $transactionDate);
            }

            // تسجيل في سجل المراجعة
            $typeLabel = $type === 'deposit' ? 'إيداع' : 'سحب';
            if (function_exists('addAuditLog')) {
                addAuditLog('caisse_transaction', "{$typeLabel}: {$amount}");
            }

            ApiResponse::created([
                'id' => $id,
                'type' => $type,
                'type_label' => $typeLabel,
                'amount' => $amount,
                'transaction_date' => $transactionDate,
            ], 'تم إضافة الحركة بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::storeTransaction');
            }
            ApiResponse::serverError('خطأ في إضافة حركة الصندوق');
        }
    }

    /**
     * حذف حركة صندوق
     *
     * DELETE /api/mobile/caisse/transactions/{id}
     */
    public function deleteTransaction(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? $this->input('id', 0));

            if ($id <= 0) {
                ApiResponse::validationError(['معرف الحركة مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من وجود الحركة
            $stmt = $pdo->prepare("SELECT * FROM caisse_transactions WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                ApiResponse::notFound('الحركة غير موجودة');
                return;
            }

            $pdo->prepare("DELETE FROM caisse_transactions WHERE id = ?")->execute([$id]);

            // تحديث سجل الصندوق
            if (class_exists('CaisseHelper')) {
                CaisseHelper::autoCreateTodayRecord($pdo, $row['transaction_date']);
            }

            $typeLabel = $row['type'] === 'deposit' ? 'إيداع' : 'سحب';
            if (function_exists('addAuditLog')) {
                addAuditLog('caisse_transaction_delete', "حذف حركة: {$typeLabel} - {$row['amount']}");
            }

            ApiResponse::success(null, 'تم حذف الحركة بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::deleteTransaction');
            }
            ApiResponse::serverError('خطأ في حذف حركة الصندوق');
        }
    }

    /**
     * تحديث مبلغ انطلاق المشروع
     *
     * POST /api/mobile/caisse/project-amount
     * Body: { "amount": 50000 }
     */
    public function projectAmount(array $params = []): void
    {
        try {
            // التحقق من صلاحيات المدير
            // v4.41.0: إصلاح — استخدام user_role (المفتاح الذي يضعه TokenAuthMiddleware)
            $role = $_SESSION['user_role'] ?? 'user';
            if ($role !== 'admin') {
                ApiResponse::forbidden('تحديث مبلغ الانطلاق يتطلب صلاحيات المدير');
                return;
            }

            $amount = floatval($this->input('amount', -1));

            if ($amount < 0) {
                ApiResponse::validationError(['مبلغ الانطلاق مطلوب ويجب أن يكون صفراً أو أكثر']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // حفظ في الإعدادات
            $stmt = $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value)
                 VALUES ('project_start_amount', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([(string) $amount]);

            // تحديث سجلات الصندوق
            if (class_exists('CaisseHelper')) {
                CaisseHelper::autoCreateTodayRecord($pdo, date('Y-m-d'));
            }

            if (function_exists('addAuditLog')) {
                addAuditLog('project_amount_update', "تحديث مبلغ الانطلاق: {$amount}");
            }

            ApiResponse::success(['amount' => $amount], 'تم تحديث مبلغ الانطلاق بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::projectAmount');
            }
            ApiResponse::serverError('خطأ في تحديث مبلغ الانطلاق');
        }
    }

    /**
     * تسجيل العد الفعلي للصندوق
     *
     * POST /api/mobile/caisse/actual-count
     * Body: { "actual_amount": 25000, "note": "عد يومي" }
     */
    public function actualCount(array $params = []): void
    {
        try {
            $actualAmount = $this->input('actual_amount', null);
            $note = trim($this->input('note', ''));

            if ($actualAmount === null) {
                ApiResponse::validationError(['المبلغ الفعلي مطلوب']);
                return;
            }

            $actualAmount = floatval($actualAmount);
            if ($actualAmount < 0) {
                ApiResponse::validationError(['المبلغ الفعلي يجب أن يكون صفراً أو أكثر']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $today = date('Y-m-d');

            // التأكد من وجود سجل اليوم
            if (class_exists('CaisseHelper')) {
                CaisseHelper::autoCreateTodayRecord($pdo, $today);
            }

            // تحديث العد الفعلي
            $stmt = $pdo->prepare(
                "UPDATE caisse SET actual_amount = ?, note = ? WHERE record_date = ?"
            );
            $stmt->execute([$actualAmount, $note, $today]);

            if ($stmt->rowCount() === 0) {
                ApiResponse::notFound('لم يتم العثور على سجل الصندوق لليوم');
                return;
            }

            // حساب الفرق
            $caisseRow = $pdo->prepare("SELECT current_amount FROM caisse WHERE record_date = ?");
            $caisseRow->execute([$today]);
            $currentAmount = floatval($caisseRow->fetchColumn());
            $difference = $actualAmount - $currentAmount;

            if (function_exists('addAuditLog')) {
                addAuditLog('caisse_actual_count', "عد فعلي: {$actualAmount} (الفرق: {$difference})");
            }

            ApiResponse::success([
                'actual_amount' => $actualAmount,
                'current_amount' => $currentAmount,
                'difference' => $difference,
            ], 'تم تسجيل العد الفعلي بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCaisseController::actualCount');
            }
            ApiResponse::serverError('خطأ في تسجيل العد الفعلي');
        }
    }

    /**
     * إحصائيات أساسية لليوم (بدون CaisseHelper)
     */
    private function getBasicTodayStats(PDO $pdo, string $today): array
    {
        // v5.11.2: استخدام prepared statements بدلاً من التداخل المباشر (حماية من حقن SQL)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE sale_date = ?");
        $stmt->execute([$today]);
        $todaySales = floatval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE purchase_date = ?");
        $stmt->execute([$today]);
        $todayPurchases = floatval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = ?");
        $stmt->execute([$today]);
        $todayExpenses = floatval($stmt->fetchColumn());

        return [
            'start_amount' => 0,
            'current_amount' => $todaySales - $todayPurchases - $todayExpenses,
            'today_sales' => $todaySales,
            'today_purchases' => $todayPurchases,
            'today_expenses' => $todayExpenses,
            'today_deposits' => 0,
            'today_withdrawals' => 0,
            'actual_amount' => null,
            'difference' => null,
            'note' => '',
        ];
    }

    /**
     * إجماليات أساسية للمشروع (بدون CaisseHelper)
     */
    private function getBasicProjectTotals(PDO $pdo): array
    {
        $totalSales = floatval($pdo->query("SELECT COALESCE(SUM(total), 0) FROM sales")->fetchColumn());
        $totalPurchases = floatval($pdo->query("SELECT COALESCE(SUM(total), 0) FROM purchases")->fetchColumn());
        $totalExpenses = floatval($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn());

        return [
            'project_start_amount' => 0,
            'total_sales' => $totalSales,
            'total_purchases' => $totalPurchases,
            'total_expenses' => $totalExpenses,
            'project_current' => $totalSales - $totalPurchases - $totalExpenses,
        ];
    }
}
