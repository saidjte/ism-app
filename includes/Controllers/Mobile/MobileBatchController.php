<?php
/**
 * ===== متحكم دفعات المنتجات للموبايل (MobileBatchController) =====
 * يوفر عمليات إدارة دفعات الشراء عبر API الموبايل
 *
 * المسارات:
 *   GET    /api/mobile/products/{id}/batches  — دفعات منتج معين
 *   POST   /api/mobile/batches                — إضافة دفعة جديدة
 *   PUT    /api/mobile/batches/{id}           — تعديل دفعة
 *   DELETE /api/mobile/batches/{id}           — حذف دفعة
 *   GET    /api/mobile/batches/expiring       — دفعات قريبة الصلاحية
 *
 * الصلاحيات:
 *   products.view  — عرض الدفعات
 *   purchases.add  — إضافة دفعات
 *   products.edit  — تعديل/حذف دفعات
 *
 * v5.14.0: إنشاء متحكم دفعات المنتجات للموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileBatchController extends ApiController
{
    /**
     * جلب دفعات منتج معين
     *
     * GET /api/mobile/products/{id}/batches
     *
     * يرجع معلومات الدفعات المتوفرة للمنتج (FIFO)
     */
    public function index(array $params = []): void
    {
        try {
            if (!hasPermission('products.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض المنتجات');
                return;
            }

            $productId = intval($params['id'] ?? 0);
            if ($productId <= 0) {
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // جلب معلومات المنتج
            $product = fetchOne("SELECT id, name, purchase_price, has_expiry, unit FROM products WHERE id = ?", [$productId]);
            if (!$product) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            // محاولة جلب الدفعات من جدول purchase_batches
            $batches = [];
            $totalRemaining = 0;
            $weightedPrice = floatval($product['purchase_price']);

            try {
                $stmt = $pdo->prepare(
                    "SELECT pb.*,
                            DATEDIFF(pb.expiry_date, CURDATE()) as days_to_expiry
                     FROM purchase_batches pb
                     WHERE pb.product_id = ? AND pb.remaining_quantity > 0
                     ORDER BY pb.purchase_date ASC"
                );
                $stmt->execute([$productId]);
                $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!is_array($batches)) $batches = [];

                // حساب المتوسط المرجح
                $totalValue = 0;
                $totalQty = 0;
                foreach ($batches as $batch) {
                    $qty = floatval($batch['remaining_quantity']);
                    $price = floatval($batch['unit_price'] ?? $batch['purchase_price'] ?? 0);
                    $totalValue += ($qty * $price);
                    $totalQty += $qty;
                    $totalRemaining += $qty;
                }
                if ($totalQty > 0) {
                    $weightedPrice = $totalValue / $totalQty;
                }

            } catch (\Throwable $e) {
                // جدول purchase_batches قد لا يكون موجوداً
                $batches = [];
            }

            // تنسيق البيانات
            $batches = array_map(function ($batch) {
                return [
                    'id' => intval($batch['id']),
                    'product_id' => intval($batch['product_id']),
                    'purchase_id' => intval($batch['purchase_id'] ?? 0),
                    'batch_number' => $batch['batch_number'] ?? '',
                    'remaining_quantity' => floatval($batch['remaining_quantity']),
                    'unit_price' => floatval($batch['unit_price'] ?? $batch['purchase_price'] ?? 0),
                    'purchase_date' => $batch['purchase_date'] ?? null,
                    'expiry_date' => $batch['expiry_date'] ?? null,
                    'days_to_expiry' => isset($batch['days_to_expiry']) ? intval($batch['days_to_expiry']) : null,
                    'is_expired' => isset($batch['expiry_date']) && strtotime($batch['expiry_date']) < time(),
                ];
            }, $batches);

            ApiResponse::success([
                'product_id' => $productId,
                'product_name' => $product['name'],
                'product_unit' => $product['unit'] ?? '',
                'current_purchase_price' => floatval($product['purchase_price']),
                'weighted_price' => round($weightedPrice, 2),
                'total_remaining' => $totalRemaining,
                'has_expiry' => intval($product['has_expiry']),
                'batches' => $batches,
                'batches_count' => count($batches),
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileBatchController::index');
            }
            ApiResponse::serverError('خطأ في جلب دفعات المنتج');
        }
    }

    /**
     * إضافة دفعة جديدة
     *
     * POST /api/mobile/batches
     * Body: {
     *   "product_id": 1,
     *   "quantity": 100,
     *   "unit_price": 50.00,
     *   "purchase_date": "2026-01-15",
     *   "expiry_date": "2026-12-31",
     *   "batch_number": "BATCH-001"
     * }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            if (!hasPermission('purchases.add')) {
                ApiResponse::forbidden('ليس لديك صلاحية إضافة مشتريات');
                return;
            }

            $productId = intval($this->input('product_id', 0));
            $quantity = floatval($this->input('quantity', 0));
            $unitPrice = floatval($this->input('unit_price', 0));
            $purchaseDate = trim($this->input('purchase_date', date('Y-m-d')));
            $expiryDate = trim($this->input('expiry_date', ''));
            $batchNumber = trim($this->input('batch_number', ''));

            // التحقق من المدخلات
            $errors = [];
            if ($productId <= 0) {
                $errors[] = 'معرف المنتج مطلوب';
            }
            if ($quantity <= 0) {
                $errors[] = 'الكمية يجب أن تكون أكبر من صفر';
            }
            if ($unitPrice < 0) {
                $errors[] = 'سعر الوحدة غير صالح';
            }

            if (!empty($errors)) {
                ApiResponse::validationError($errors);
                return;
            }

            // التحقق من وجود المنتج
            $product = fetchOne("SELECT id, name FROM products WHERE id = ?", [$productId]);
            if (!$product) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من وجود جدول purchase_batches
            $tableExists = false;
            try {
                $check = $pdo->query("SHOW TABLES LIKE 'purchase_batches'");
                $tableExists = !empty($check->fetch());
            } catch (\Throwable $e) {}

            if (!$tableExists) {
                // إنشاء الجدول إذا لم يكن موجوداً
                $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_batches (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    purchase_id INT DEFAULT NULL,
                    batch_number VARCHAR(100) DEFAULT '',
                    quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
                    remaining_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
                    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
                    purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
                    purchase_date DATE DEFAULT NULL,
                    expiry_date DATE DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_batch_product (product_id),
                    INDEX idx_batch_purchase (purchase_id),
                    INDEX idx_batch_expiry (expiry_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // إنشاء رقم دفعة إذا لم يتم توفيره
            if (empty($batchNumber)) {
                $batchNumber = 'B-' . date('Ymd') . '-' . str_pad($productId, 4, '0', STR_PAD_LEFT);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO purchase_batches (product_id, batch_number, quantity, remaining_quantity, unit_price, purchase_price, purchase_date, expiry_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $productId,
                $batchNumber,
                $quantity,
                $quantity, // remaining = quantity at start
                $unitPrice,
                $unitPrice,
                $purchaseDate,
                !empty($expiryDate) ? $expiryDate : null,
            ]);

            $id = intval($pdo->lastInsertId());

            // تحديث كمية المنتج
            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([$quantity, $productId]);

            // Update product purchase_price to weighted average of all active batches
            $weightedStmt = $pdo->prepare(
                "SELECT SUM(remaining_quantity * purchase_price) / SUM(remaining_quantity) as weighted_price
                 FROM purchase_batches WHERE product_id = ? AND remaining_quantity > 0"
            );
            $weightedStmt->execute([$productId]);
            $weighted = $weightedStmt->fetch(PDO::FETCH_ASSOC);
            if ($weighted && $weighted['weighted_price'] > 0) {
                $pdo->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")
                    ->execute([round(floatval($weighted['weighted_price']), 2), $productId]);
            }

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('batch_add', "إضافة دفعة #{$id} للمنتج {$product['name']}: {$quantity} بسعر {$unitPrice}");
            }

            ApiResponse::created([
                'id' => $id,
                'product_id' => $productId,
                'batch_number' => $batchNumber,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'purchase_date' => $purchaseDate,
                'expiry_date' => $expiryDate ?: null,
            ], 'تم إضافة الدفعة بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileBatchController::store');
            }
            ApiResponse::serverError('خطأ في إضافة الدفعة');
        }
    }

    /**
     * تعديل دفعة
     *
     * PUT /api/mobile/batches/{id}
     */
    public function update(array $params = []): void
    {
        try {
            if (!hasPermission('products.edit')) {
                ApiResponse::forbidden('ليس لديك صلاحية تعديل المنتجات');
                return;
            }

            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف الدفعة مطلوب']);
                return;
            }

            $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$id]);
            if (!$batch) {
                ApiResponse::notFound('الدفعة غير موجودة');
                return;
            }

            $expiryDate = trim($this->input('expiry_date', $batch['expiry_date'] ?? ''));
            $batchNumber = trim($this->input('batch_number', $batch['batch_number'] ?? ''));
            $remainingQty = $this->input('remaining_quantity', null);

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $updateFields = [];
            $updateValues = [];

            if (!empty($batchNumber)) {
                $updateFields[] = "batch_number = ?";
                $updateValues[] = $batchNumber;
            }
            if ($remainingQty !== null) {
                $updateFields[] = "remaining_quantity = ?";
                $updateValues[] = floatval($remainingQty);
            }
            if (!empty($expiryDate) || $expiryDate === '') {
                $updateFields[] = "expiry_date = ?";
                $updateValues[] = !empty($expiryDate) ? $expiryDate : null;
            }

            if (empty($updateFields)) {
                ApiResponse::validationError(['لم يتم تقديم بيانات للتحديث']);
                return;
            }

            $updateValues[] = $id;
            $sql = "UPDATE purchase_batches SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($updateValues);

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('batch_edit', "تعديل دفعة #{$id}");
            }

            $updatedBatch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$id]);
            ApiResponse::success($updatedBatch, 'تم تعديل الدفعة بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBatchController::update');
            ApiResponse::serverError('حدث خطأ أثناء تعديل الدفعة');
        }
    }

    /**
     * حذف دفعة
     *
     * DELETE /api/mobile/batches/{id}
     */
    public function delete(array $params = []): void
    {
        try {
            if (!hasPermission('products.edit')) {
                ApiResponse::forbidden('ليس لديك صلاحية حذف الدفعات');
                return;
            }

            $id = intval($params['id'] ?? $this->input('id', 0));
            if ($id <= 0) {
                ApiResponse::validationError(['معرف الدفعة مطلوب']);
                return;
            }

            $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$id]);
            if (!$batch) {
                ApiResponse::notFound('الدفعة غير موجودة');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // تحديث كمية المنتج (خصم الكمية المتبقية)
            $remainingQty = floatval($batch['remaining_quantity'] ?? 0);
            if ($remainingQty > 0) {
                $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
                    ->execute([$remainingQty, $batch['product_id']]);
            }

            $pdo->prepare("DELETE FROM purchase_batches WHERE id = ?")->execute([$id]);

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('batch_delete', "حذف دفعة #{$id} للمنتج #{$batch['product_id']}");
            }

            ApiResponse::success(null, 'تم حذف الدفعة بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileBatchController::delete');
            }
            ApiResponse::serverError('خطأ في حذف الدفعة');
        }
    }

    /**
     * دفعات قريبة الصلاحية
     *
     * GET /api/mobile/batches/expiring?days=30
     */
    public function expiring(array $params = []): void
    {
        try {
            if (!hasPermission('products.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض المنتجات');
                return;
            }

            $days = max(1, intval($this->input('days', 30)));
            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من وجود جدول purchase_batches
            try {
                $check = $pdo->query("SHOW TABLES LIKE 'purchase_batches'");
                if (empty($check->fetch())) {
                    ApiResponse::success([
                        'data' => [],
                        'pagination' => ['total' => 0, 'per_page' => $perPage, 'current_page' => $page, 'total_pages' => 0],
                        'message' => 'جدول الدفعات غير موجود',
                    ]);
                    return;
                }
            } catch (\Throwable $e) {
                ApiResponse::serverError('خطأ في الوصول لجدول الدفعات');
                return;
            }

            // عدد الإجمالي
            $countSql = "SELECT COUNT(*) as total FROM purchase_batches
                         WHERE remaining_quantity > 0
                         AND expiry_date IS NOT NULL
                         AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute([$days]);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $sql = "SELECT pb.*, p.name as product_name, p.code as product_code, p.unit,
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
                    ORDER BY pb.expiry_date ASC
                    LIMIT {$perPage} OFFSET {$offset}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$days]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $batches = array_map(function ($batch) {
                return [
                    'id' => intval($batch['id']),
                    'product_id' => intval($batch['product_id']),
                    'product_name' => $batch['product_name'],
                    'product_code' => $batch['product_code'] ?? '',
                    'batch_number' => $batch['batch_number'] ?? '',
                    'remaining_quantity' => floatval($batch['remaining_quantity']),
                    'unit_price' => floatval($batch['unit_price'] ?? 0),
                    'expiry_date' => $batch['expiry_date'],
                    'days_to_expiry' => intval($batch['days_to_expiry']),
                    'expiry_status' => $batch['expiry_status'],
                    'unit' => $batch['unit'] ?? '',
                ];
            }, $batches ?: []);

            ApiResponse::success([
                'data' => $batches,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
                'filter_days' => $days,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileBatchController::expiring');
            }
            ApiResponse::serverError('خطأ في جلب الدفعات قريبة الصلاحية');
        }
    }
}
