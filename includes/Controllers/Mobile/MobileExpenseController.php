<?php
/**
 * ===== متحكم مصروفات الموبايل (MobileExpenseController) =====
 * يوفر عمليات المصروفات عبر API الموبايل
 *
 * المسارات:
 *   GET    /api/mobile/expenses           — قائمة المصروفات مع ترقيم الصفحات
 *   POST   /api/mobile/expenses           — إضافة مصروف جديد
 *   PUT    /api/mobile/expenses/{id}      — تعديل مصروف
 *   DELETE /api/mobile/expenses/{id}      — حذف مصروف
 *   GET    /api/mobile/expenses/categories — قائمة فئات المصروفات
 *
 * v5.11.0: إنشاء متحكم مصروفات الموبايل
 * v5.13.0: إضافة تعديل مصروف
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileExpenseController extends ApiController
{
    /**
     * فئات المصروفات الافتراضية
     * تُستخدم عندما لا توجد فئات مخصصة في قاعدة البيانات
     */
    private const DEFAULT_CATEGORIES = [
        'مواد التغليف',
        'سحب الأرباح',
        'الإيجار',
        'الكهرباء والماء',
        'النقل',
        'الصيانة',
        'الرواتب',
        'الضرائب',
        'التسويق',
        'أخرى',
    ];

    /**
     * قائمة المصروفات مع ترقيم الصفحات والتصفية
     *
     * GET /api/mobile/expenses?page=1&per_page=20&category=إيجار&date_from=2026-01-01&date_to=2026-12-31
     */
    public function index(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // معاملات الترقيم
            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            // معاملات التصفية
            $category = trim($this->input('category', ''));
            $dateFrom = trim($this->input('date_from', ''));
            $dateTo = trim($this->input('date_to', ''));

            // بناء الاستعلام مع الشروط
            $where = [];
            $bindings = [];

            if (!empty($category)) {
                $where[] = "e.category = ?";
                $bindings[] = $category;
            }
            if (!empty($dateFrom)) {
                $where[] = "e.expense_date >= ?";
                $bindings[] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $where[] = "e.expense_date <= ?";
                $bindings[] = $dateTo;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // عدد الإجمالي
            $countSql = "SELECT COUNT(*) as total FROM expenses e {$whereClause}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($bindings);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $sql = "SELECT e.*, u.username as created_by_name
                    FROM expenses e
                    LEFT JOIN users u ON e.created_by = u.id
                    {$whereClause}
                    ORDER BY e.expense_date DESC, e.created_at DESC
                    LIMIT {$perPage} OFFSET {$offset}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تنسيق البيانات
            $expenses = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'category' => $row['category'],
                    'amount' => floatval($row['amount']),
                    'description' => $row['description'] ?? '',
                    'expense_date' => $row['expense_date'],
                    'created_by' => intval($row['created_by'] ?? 0),
                    'created_by_name' => $row['created_by_name'] ?? '',
                    'created_at' => $row['created_at'],
                ];
            }, $expenses);

            // إحصائيات سريعة
            $statsSql = "SELECT
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN expense_date = CURDATE() THEN amount ELSE 0 END), 0) as today_total,
                COALESCE(SUM(CASE WHEN expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END), 0) as month_total
                FROM expenses";
            $statsStmt = $pdo->query($statsSql);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            ApiResponse::success([
                'data' => $expenses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
                'stats' => [
                    'total_amount' => floatval($stats['total_amount'] ?? 0),
                    'today_total' => floatval($stats['today_total'] ?? 0),
                    'month_total' => floatval($stats['month_total'] ?? 0),
                ],
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpenseController::index');
            }
            ApiResponse::serverError('خطأ في جلب المصروفات');
        }
    }

    /**
     * إضافة مصروف جديد
     *
     * POST /api/mobile/expenses
     * Body: { "category": "إيجار", "amount": 5000, "description": "...", "expense_date": "2026-05-26" }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            $category = trim($this->input('category', ''));
            $amount = floatval($this->input('amount', 0));
            $description = trim($this->input('description', ''));
            $expenseDate = trim($this->input('expense_date', ''));

            // التحقق من المدخلات
            $errors = [];
            if (empty($category)) {
                $errors[] = 'فئة المصروف مطلوبة';
            }
            if ($amount <= 0) {
                $errors[] = 'المبلغ يجب أن يكون أكبر من صفر';
            }
            if (empty($expenseDate)) {
                $expenseDate = date('Y-m-d');
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
                $errors[] = 'صيغة التاريخ غير صالحة (YYYY-MM-DD)';
            }

            if (!empty($errors)) {
                ApiResponse::validationError($errors);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // الحصول على معرف المستخدم الحالي من الجلسة (التي أنشأها TokenAuthMiddleware)
            $createdBy = intval($_SESSION['user_id'] ?? 0);

            $stmt = $pdo->prepare(
                "INSERT INTO expenses (category, amount, description, expense_date, created_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$category, $amount, $description ?: null, $expenseDate, $createdBy ?: null]);

            $id = intval($pdo->lastInsertId());

            // إرسال إشعار
            if (class_exists('AppNotification')) {
                $currencySymbol = function_exists('getCurrencySymbol') ? getCurrencySymbol() : '';
                $title_key = 'notif_title_new_expense';
                $message_key = 'notif_msg_new_expense';
                $message_params = ['category' => $category, 'amount' => $amount, 'currency' => $currencySymbol];
                AppNotification::add(
                    NOTIF_EXPENSE ?? 'expense',
                    t($title_key),
                    t($message_key, $category, $amount, $currencySymbol),
                    null, null, $title_key, $message_key, $message_params
                );
            }

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('expense_add', "إضافة مصروف جديد: {$category} - {$amount}");
            }

            ApiResponse::created([
                'id' => $id,
                'category' => $category,
                'amount' => $amount,
                'expense_date' => $expenseDate,
            ], 'تم إضافة المصروف بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpenseController::store');
            }
            ApiResponse::serverError('خطأ في إضافة المصروف');
        }
    }

    /**
     * تعديل مصروف
     *
     * PUT /api/mobile/expenses/{id}
     * الجسم (JSON):
     *   category     — الفئة (اختياري)
     *   amount       — المبلغ (اختياري)
     *   description  — الوصف (اختياري)
     *   expense_date — تاريخ المصروف (اختياري)
     */
    public function update(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف المصروف مطلوب']);
                return;
            }

            // جلب المصروف الحالي
            $expense = fetchOne("SELECT * FROM expenses WHERE id = ?", [$id]);
            if (!$expense) {
                ApiResponse::notFound('المصروف غير موجود');
                return;
            }

            $category = trim($this->input('category', $expense['category'] ?? ''));
            $amount = floatval($this->input('amount', $expense['amount'] ?? 0));
            $description = trim($this->input('description', $expense['description'] ?? ''));
            $expenseDate = trim($this->input('expense_date', $expense['expense_date'] ?? date('Y-m-d')));

            if (empty($category)) {
                ApiResponse::validationError(['category' => 'الفئة مطلوبة']);
                return;
            }
            if ($amount <= 0) {
                ApiResponse::validationError(['amount' => 'المبلغ يجب أن يكون أكبر من صفر']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("UPDATE expenses SET category = ?, amount = ?, description = ?, expense_date = ? WHERE id = ?");
            $stmt->execute([$category, $amount, $description, $expenseDate, $id]);

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('expense_edit', "تعديل مصروف #{$id}");
            }

            $updatedExpense = fetchOne("SELECT * FROM expenses WHERE id = ?", [$id]);
            ApiResponse::success($updatedExpense, 'تم تعديل المصروف بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileExpenseController::update');
            ApiResponse::serverError('حدث خطأ أثناء تعديل المصروف');
        }
    }

    /**
     * حذف مصروف
     *
     * DELETE /api/mobile/expenses/{id}
     */
    public function delete(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? $this->input('id', 0));

            if ($id <= 0) {
                ApiResponse::validationError(['معرف المصروف مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من وجود المصروف
            $expense = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
            $expense->execute([$id]);
            $row = $expense->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                ApiResponse::notFound('المصروف غير موجود');
                return;
            }

            $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('expense_delete', "حذف مصروف: {$row['category']} - {$row['amount']}");
            }

            ApiResponse::success(null, 'تم حذف المصروف بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpenseController::delete');
            }
            ApiResponse::serverError('خطأ في حذف المصروف');
        }
    }

    /**
     * قائمة فئات المصروفات
     *
     * GET /api/mobile/expenses/categories
     * يرجع الفئات الافتراضية + أي فئات مستخدمة موجودة في قاعدة البيانات
     */
    public function categories(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // جلب الفئات المستخدمة من قاعدة البيانات
            $stmt = $pdo->query("SELECT DISTINCT category FROM expenses ORDER BY category ASC");
            $dbCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // دمج الفئات الافتراضية مع الفئات الموجودة (بدون تكرار)
            $allCategories = array_unique(array_merge(self::DEFAULT_CATEGORIES, $dbCategories));
            sort($allCategories, SORT_LOCALE_STRING);

            // إحصائيات لكل فئة
            $statsStmt = $pdo->query(
                "SELECT category, COUNT(*) as count, COALESCE(SUM(amount), 0) as total
                 FROM expenses
                 GROUP BY category"
            );
            $statsRows = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

            $categoryStats = [];
            foreach ($statsRows as $row) {
                $categoryStats[$row['category']] = [
                    'count' => intval($row['count']),
                    'total' => floatval($row['total']),
                ];
            }

            $categories = array_map(function ($cat) use ($categoryStats) {
                return [
                    'name' => $cat,
                    'count' => $categoryStats[$cat]['count'] ?? 0,
                    'total' => $categoryStats[$cat]['total'] ?? 0,
                ];
            }, $allCategories);

            ApiResponse::success($categories);

        } catch (\Throwable $e) {
            // في حالة فشل قاعدة البيانات، نرجع الفئات الافتراضية
            $fallback = array_map(function ($cat) {
                return ['name' => $cat, 'count' => 0, 'total' => 0];
            }, self::DEFAULT_CATEGORIES);

            ApiResponse::success($fallback);
        }
    }
}
