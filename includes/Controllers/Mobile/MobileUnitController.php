<?php
/**
 * ===== متحكم وحدات القياس للموبايل (MobileUnitController) =====
 * يوفر عمليات إدارة وحدات القياس عبر API الموبايل
 *
 * المسارات:
 *   GET    /api/mobile/units           — قائمة وحدات القياس
 *   GET    /api/mobile/units/{id}      — تفاصيل وحدة قياس
 *   POST   /api/mobile/units           — إضافة وحدة قياس جديدة
 *   PUT    /api/mobile/units/{id}      — تعديل وحدة قياس
 *   DELETE /api/mobile/units/{id}      — حذف وحدة قياس
 *
 * الصلاحيات:
 *   units.view   — عرض وحدات القياس
 *   units.add    — إضافة وحدة قياس
 *   units.edit   — تعديل وحدة قياس
 *   units.delete — حذف وحدة قياس
 *
 * v5.14.0: إنشاء متحكم وحدات القياس للموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileUnitController extends ApiController
{
    /**
     * قائمة وحدات القياس مع ترقيم الصفحات
     *
     * GET /api/mobile/units?page=1&per_page=20&search=كيلو
     */
    public function index(array $params = []): void
    {
        try {
            // التحقق من الصلاحية
            if (!hasPermission('units.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض وحدات القياس');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // معاملات الترقيم
            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            // معامل البحث
            $search = trim($this->input('search', ''));

            // بناء الاستعلام
            $where = [];
            $bindings = [];

            if (!empty($search)) {
                $where[] = "(u.name LIKE ? OR u.symbol LIKE ? OR u.description LIKE ?)";
                $bindings[] = "%{$search}%";
                $bindings[] = "%{$search}%";
                $bindings[] = "%{$search}%";
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // عدد الإجمالي
            $countSql = "SELECT COUNT(*) as total FROM units u {$whereClause}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($bindings);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $sql = "SELECT u.*,
                    (SELECT COUNT(*) FROM products p WHERE p.unit = u.name OR p.purchase_unit = u.name) as products_count
                    FROM units u
                    {$whereClause}
                    ORDER BY u.name ASC
                    LIMIT {$perPage} OFFSET {$offset}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تنسيق البيانات
            $units = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'symbol' => $row['symbol'] ?? '',
                    'description' => $row['description'] ?? '',
                    'is_base_unit' => intval($row['is_base_unit'] ?? 0),
                    'products_count' => intval($row['products_count'] ?? 0),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }, $units);

            ApiResponse::paginated($units, $total, $page, $perPage);

        } catch (\Throwable $e) {
            // إذا كان جدول units غير موجود، نرجع وحدات افتراضية
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                $defaultUnits = $this->getDefaultUnits();
                ApiResponse::success([
                    'data' => $defaultUnits,
                    'pagination' => [
                        'total' => count($defaultUnits),
                        'per_page' => $perPage ?? 20,
                        'current_page' => 1,
                        'total_pages' => 1,
                    ],
                    'is_default' => true,
                    'message' => 'جدول وحدات القياس غير موجود، يتم عرض الوحدات الافتراضية',
                ]);
                return;
            }

            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUnitController::index');
            }
            ApiResponse::serverError('خطأ في جلب وحدات القياس');
        }
    }

    /**
     * تفاصيل وحدة قياس واحدة
     *
     * GET /api/mobile/units/{id}
     */
    public function show(array $params = []): void
    {
        try {
            if (!hasPermission('units.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض وحدات القياس');
                return;
            }

            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف وحدة القياس مطلوب']);
                return;
            }

            $unit = fetchOne("SELECT * FROM units WHERE id = ?", [$id]);
            if (!$unit) {
                ApiResponse::notFound('وحدة القياس غير موجودة');
                return;
            }

            // عدد المنتجات المستخدمة لهذه الوحدة
            $productsCount = fetchOne(
                "SELECT COUNT(*) as c FROM products WHERE unit = ? OR purchase_unit = ?",
                [$unit['name'], $unit['name']]
            );
            $unit['products_count'] = intval($productsCount['c'] ?? 0);
            $unit['id'] = intval($unit['id']);

            ApiResponse::success($unit);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUnitController::show');
            }
            ApiResponse::serverError('خطأ في جلب وحدة القياس');
        }
    }

    /**
     * إضافة وحدة قياس جديدة
     *
     * POST /api/mobile/units
     * Body: { "name": "كيلوغرام", "symbol": "كغ", "description": "وحدة وزن", "is_base_unit": 1 }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            if (!hasPermission('units.add')) {
                ApiResponse::forbidden('ليس لديك صلاحية إضافة وحدة قياس');
                return;
            }

            $name = trim($this->input('name', ''));
            $symbol = trim($this->input('symbol', ''));
            $description = trim($this->input('description', ''));
            $isBaseUnit = intval($this->input('is_base_unit', 0));

            // التحقق من المدخلات
            $errors = [];
            if (empty($name)) {
                $errors[] = 'اسم وحدة القياس مطلوب';
            }
            if (mb_strlen($name) > 50) {
                $errors[] = 'اسم وحدة القياس لا يمكن أن يتجاوز 50 حرفاً';
            }

            if (!empty($errors)) {
                ApiResponse::validationError($errors);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من عدم وجود وحدة بنفس الاسم
            $existing = fetchOne("SELECT id FROM units WHERE name = ?", [$name]);
            if ($existing) {
                ApiResponse::validationError(['وحدة القياس بهذا الاسم موجودة مسبقاً']);
                return;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO units (name, symbol, description, is_base_unit, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$name, $symbol, $description, $isBaseUnit]);

            $id = intval($pdo->lastInsertId());

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('unit_add', "إضافة وحدة قياس: {$name}");
            }

            ApiResponse::created([
                'id' => $id,
                'name' => $name,
                'symbol' => $symbol,
                'description' => $description,
                'is_base_unit' => $isBaseUnit,
            ], 'تم إضافة وحدة القياس بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUnitController::store');
            }
            ApiResponse::serverError('خطأ في إضافة وحدة القياس');
        }
    }

    /**
     * تعديل وحدة قياس
     *
     * PUT /api/mobile/units/{id}
     * Body: { "name": "كيلوغرام", "symbol": "kg", "description": "...", "is_base_unit": 1 }
     */
    public function update(array $params = []): void
    {
        try {
            if (!hasPermission('units.edit')) {
                ApiResponse::forbidden('ليس لديك صلاحية تعديل وحدات القياس');
                return;
            }

            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف وحدة القياس مطلوب']);
                return;
            }

            // جلب الوحدة الحالية
            $unit = fetchOne("SELECT * FROM units WHERE id = ?", [$id]);
            if (!$unit) {
                ApiResponse::notFound('وحدة القياس غير موجودة');
                return;
            }

            $name = trim($this->input('name', $unit['name'] ?? ''));
            $symbol = trim($this->input('symbol', $unit['symbol'] ?? ''));
            $description = trim($this->input('description', $unit['description'] ?? ''));
            $isBaseUnit = intval($this->input('is_base_unit', $unit['is_base_unit'] ?? 0));

            if (empty($name)) {
                ApiResponse::validationError(['name' => 'اسم وحدة القياس مطلوب']);
                return;
            }

            // التحقق من عدم تكرار الاسم مع وحدة أخرى
            $existing = fetchOne("SELECT id FROM units WHERE name = ? AND id != ?", [$name, $id]);
            if ($existing) {
                ApiResponse::validationError(['اسم وحدة القياس مستخدم بوحدة أخرى']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // تحديث المنتجات المرتبطة إذا تغير الاسم
            $oldName = $unit['name'] ?? '';
            if ($oldName !== $name) {
                $pdo->prepare("UPDATE products SET unit = ? WHERE unit = ?")->execute([$name, $oldName]);
                $pdo->prepare("UPDATE products SET purchase_unit = ? WHERE purchase_unit = ?")->execute([$name, $oldName]);
            }

            $stmt = $pdo->prepare("UPDATE units SET name = ?, symbol = ?, description = ?, is_base_unit = ? WHERE id = ?");
            $stmt->execute([$name, $symbol, $description, $isBaseUnit, $id]);

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('unit_edit', "تعديل وحدة قياس #{$id}: {$name}");
            }

            $updatedUnit = fetchOne("SELECT * FROM units WHERE id = ?", [$id]);
            ApiResponse::success($updatedUnit, 'تم تعديل وحدة القياس بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileUnitController::update');
            ApiResponse::serverError('حدث خطأ أثناء تعديل وحدة القياس');
        }
    }

    /**
     * حذف وحدة قياس
     *
     * DELETE /api/mobile/units/{id}
     */
    public function delete(array $params = []): void
    {
        try {
            if (!hasPermission('units.delete')) {
                ApiResponse::forbidden('ليس لديك صلاحية حذف وحدات القياس');
                return;
            }

            $id = intval($params['id'] ?? $this->input('id', 0));
            if ($id <= 0) {
                ApiResponse::validationError(['معرف وحدة القياس مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من وجود الوحدة
            $unit = $pdo->prepare("SELECT * FROM units WHERE id = ?");
            $unit->execute([$id]);
            $row = $unit->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                ApiResponse::notFound('وحدة القياس غير موجودة');
                return;
            }

            // التحقق من عدم استخدامها في منتجات
            $productsUsing = fetchOne(
                "SELECT COUNT(*) as c FROM products WHERE unit = ? OR purchase_unit = ?",
                [$row['name'], $row['name']]
            );
            if (intval($productsUsing['c'] ?? 0) > 0) {
                ApiResponse::validationError([
                    "لا يمكن حذف وحدة القياس '{$row['name']}' لأنها مستخدمة في {$productsUsing['c']} منتج(ات). قم بتغيير وحدة المنتجات أولاً."
                ]);
                return;
            }

            $pdo->prepare("DELETE FROM units WHERE id = ?")->execute([$id]);

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('unit_delete', "حذف وحدة قياس: {$row['name']}");
            }

            ApiResponse::success(null, 'تم حذف وحدة القياس بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUnitController::delete');
            }
            ApiResponse::serverError('خطأ في حذف وحدة القياس');
        }
    }

    /**
     * الوحدات الافتراضية (عندما لا يكون جدول units موجوداً)
     */
    private function getDefaultUnits(): array
    {
        return [
            ['id' => 0, 'name' => 'قطعة', 'symbol' => 'ق', 'description' => 'وحدة عد', 'is_base_unit' => 1, 'products_count' => 0],
            ['id' => 0, 'name' => 'كيلوغرام', 'symbol' => 'كغ', 'description' => 'وحدة وزن', 'is_base_unit' => 1, 'products_count' => 0],
            ['id' => 0, 'name' => 'غرام', 'symbol' => 'غ', 'description' => 'وحدة وزن', 'is_base_unit' => 0, 'products_count' => 0],
            ['id' => 0, 'name' => 'لتر', 'symbol' => 'ل', 'description' => 'وحدة حجم سائل', 'is_base_unit' => 1, 'products_count' => 0],
            ['id' => 0, 'name' => 'ملي لتر', 'symbol' => 'مل', 'description' => 'وحدة حجم سائل', 'is_base_unit' => 0, 'products_count' => 0],
            ['id' => 0, 'name' => 'متر', 'symbol' => 'م', 'description' => 'وحدة طول', 'is_base_unit' => 1, 'products_count' => 0],
            ['id' => 0, 'name' => 'سنتيمتر', 'symbol' => 'سم', 'description' => 'وحدة طول', 'is_base_unit' => 0, 'products_count' => 0],
            ['id' => 0, 'name' => 'علبة', 'symbol' => 'ع', 'description' => 'وحدة تغليف', 'is_base_unit' => 0, 'products_count' => 0],
            ['id' => 0, 'name' => 'كرتون', 'symbol' => 'ك', 'description' => 'وحدة تغليف', 'is_base_unit' => 0, 'products_count' => 0],
            ['id' => 0, 'name' => 'طن', 'symbol' => 'ط', 'description' => 'وحدة وزن كبيرة', 'is_base_unit' => 0, 'products_count' => 0],
        ];
    }
}
