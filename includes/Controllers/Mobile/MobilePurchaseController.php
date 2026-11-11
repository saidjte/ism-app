<?php
/**
 * ===== متحكم المشتريات للهاتف (Mobile Purchase Controller) =====
 * يوفر واجهة API لإدارة المشتريات من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET    /api/mobile/purchases           — قائمة المشتريات مع تصفية وترقيم صفحات
 *   GET    /api/mobile/purchases/{id}      — تفاصيل عملية شراء واحدة
 *   POST   /api/mobile/purchases           — تسجيل عملية شراء جديدة
 *   PUT    /api/mobile/purchases/{id}      — تعديل عملية شراء
 *   DELETE /api/mobile/purchases/{id}      — حذف عملية شراء
 *
 * يستخدم PurchaseService كطبقة منطق تجاري (المصدر الوحيد للحقيقة)
 *
 * v4.34.0: إنشاء طبقة API للهاتف
 * v5.13.0: إضافة تعديل عملية شراء
 */

require_once __DIR__ . '/../ApiController.php';

class MobilePurchaseController extends ApiController
{
    /**
     * قائمة المشتريات مع تصفية وترقيم صفحات
     *
     * معاملات GET:
     *   date_from  — تاريخ البداية (اختياري، Y-m-d)
     *   date_to    — تاريخ النهاية (اختياري، Y-m-d)
     *   product_id — معرف المنتج (اختياري)
     *   page       — رقم الصفحة (افتراضي: 1)
     *   per_page   — عدد العناصر في الصفحة (افتراضي: 20)
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function index(array $params = []): void
    {
        try {
            $dateFrom = trim($this->input('date_from', ''));
            $dateTo = trim($this->input('date_to', ''));
            $productId = intval($this->input('product_id', 0));
            $pagination = $this->getPaginationParams(20);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            // بناء الاستعلام مع شروط التصفية
            $sql = "SELECT pu.*, p.name as product_name, p.unit, p.code as product_code
                    FROM purchases pu
                    JOIN products p ON pu.product_id = p.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total
                         FROM purchases pu
                         JOIN products p ON pu.product_id = p.id
                         WHERE 1=1";

            $bindings = [];
            $conditions = '';

            // تصفية حسب تاريخ البداية
            if (!empty($dateFrom)) {
                $conditions .= " AND pu.purchase_date >= ?";
                $bindings[] = $dateFrom;
            }

            // تصفية حسب تاريخ النهاية
            if (!empty($dateTo)) {
                $conditions .= " AND pu.purchase_date <= ?";
                $bindings[] = $dateTo;
            }

            // تصفية حسب المنتج
            if ($productId > 0) {
                $conditions .= " AND pu.product_id = ?";
                $bindings[] = $productId;
            }

            // إضافة الشروط للاستعلامات
            $sql .= $conditions;
            $countSql .= $conditions;

            // ترتيب تنازلي حسب التاريخ والمعرف
            $sql .= " ORDER BY pu.purchase_date DESC, pu.id DESC";

            // إضافة الحد والإزاحة
            $sql .= " LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            // تنفيذ استعلام العدد
            $countBindings = array_slice($bindings, 0, -2);
            $countRow = fetchOne($countSql, $countBindings);
            $total = intval($countRow['total'] ?? 0);

            // تنفيذ استعلام البيانات
            $purchases = fetchAll($sql, $bindings);

            if (!is_array($purchases)) {
                $purchases = [];
            }

            ApiResponse::paginated($purchases, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePurchaseController::index');
            ApiResponse::serverError('حدث خطأ أثناء جلب المشتريات');
        }
    }

    /**
     * تفاصيل عملية شراء واحدة
     *
     * معامل المسار: {id}
     *
     * الاستجابة:
     *   { success, data: { purchase } } أو 404
     */
    public function show(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف عملية الشراء مطلوب']);
                return;
            }

            $purchase = fetchOne(
                "SELECT pu.*, p.name as product_name, p.unit, p.code as product_code
                 FROM purchases pu
                 JOIN products p ON pu.product_id = p.id
                 WHERE pu.id = ?",
                [$id]
            );

            if (!$purchase) {
                ApiResponse::notFound('عملية الشراء غير موجودة');
                return;
            }

            // Batch subsystem removed: purchase batch details are no longer returned.

            // جلب التخفيضات المرتبطة إن وُجدت
            $remises = [];
            try {
                $remises = fetchAll(
                    "SELECT * FROM purchase_remises WHERE purchase_id = ?",
                    [$id]
                );
            } catch (\Throwable $e) {
                // جدول purchase_remises قد لا يكون موجوداً
            }

            if (!empty($remises)) {
                $purchase['remises'] = $remises;
            }

            ApiResponse::success($purchase);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePurchaseController::show');
            ApiResponse::serverError('حدث خطأ أثناء جلب عملية الشراء');
        }
    }

    /**
     * تسجيل عملية شراء جديدة
     *
     * الجسم (JSON):
     *   product_id    — معرف المنتج (مطلوب)
     *   quantity      — الكمية (مطلوب)
     *   cost_price    — سعر الشراء (مطلوب)
     *   purchase_date — تاريخ الشراء (اختياري، افتراضي: اليوم)
     *   notes         — ملاحظات (اختياري)
     *   sale_price    — سعر البيع الجديد (اختياري)
     *   tax_enabled   — تفعيل الضريبة (اختياري)
     *   tax_type      — نوع الضريبة: percentage|fixed (اختياري)
     *   tax_value     — قيمة الضريبة (اختياري)
     *   tax_name      — اسم الضريبة (اختياري)
     *   batch_items   — عناصر الدفعات [{quantity, expiry_date}] (اختياري)
     *
     * الاستجابة:
     *   201 { success, data: { purchase_id, message } }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            $data = $this->all();

            // التحقق من الحقول المطلوبة
            $requiredErrors = [];
            if (empty($data['product_id']) || intval($data['product_id']) <= 0) {
                $requiredErrors['product_id'] = 'معرف المنتج مطلوب';
            }
            if (empty($data['quantity']) || floatval($data['quantity']) <= 0) {
                $requiredErrors['quantity'] = 'الكمية مطلوبة ويجب أن تكون أكبر من صفر';
            }
            if (empty($data['cost_price']) || floatval($data['cost_price']) <= 0) {
                $requiredErrors['cost_price'] = 'سعر الشراء مطلوب ويجب أن يكون أكبر من صفر';
            }

            if (!empty($requiredErrors)) {
                ApiResponse::validationError($requiredErrors);
                return;
            }

            // تفويض المنطق التجاري إلى PurchaseService
            $result = PurchaseService::create($data);

            ApiResponse::created($result, $result['message'] ?? 'تم تسجيل عملية الشراء بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            if ($e->getErrorType() === 'not_found') {
                ApiResponse::notFound($e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePurchaseController::store');
            ApiResponse::serverError('حدث خطأ أثناء تسجيل المشتريات');
        }
    }

    /**
     * حذف عملية شراء مع إعادة تعديل المخزون
     *
     * معامل المسار: {id}
     *
     * الاستجابة:
     *   { success, message }
     */
    public function destroy(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف عملية الشراء مطلوب']);
                return;
            }

            $result = PurchaseService::delete($id);

            ApiResponse::success(null, $result['message'] ?? 'تم حذف عملية الشراء بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'not_found') {
                ApiResponse::notFound($e->getMessage());
            }
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePurchaseController::destroy');
            ApiResponse::serverError('حدث خطأ أثناء حذف المشتريات');
        }
    }

    /**
     * تعديل عملية شراء
     *
     * معامل المسار: {id}
     * الجسم (JSON):
     *   quantity       — الكمية الجديدة (مطلوب)
     *   purchase_price — سعر الشراء الجديد (مطلوب)
     *   purchase_date  — تاريخ الشراء (اختياري)
     *   notes          — ملاحظات (اختياري)
     *
     * الاستجابة:
     *   { success, data: { purchase }, message }
     */
    public function update(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف عملية الشراء مطلوب']);
                return;
            }

            $quantity = floatval($this->input('quantity', 0));
            $purchasePrice = floatval($this->input('purchase_price', 0));
            $purchaseDate = trim($this->input('purchase_date', ''));
            $notes = trim($this->input('notes', ''));

            if ($quantity <= 0) {
                ApiResponse::validationError(['quantity' => 'الكمية يجب أن تكون أكبر من صفر']);
                return;
            }
            if ($purchasePrice <= 0) {
                ApiResponse::validationError(['purchase_price' => 'سعر الشراء يجب أن يكون أكبر من صفر']);
                return;
            }
            if (empty($purchaseDate)) {
                $purchaseDate = date('Y-m-d');
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // بدء المعاملة أولاً حتى يعمل FOR UPDATE
            $pdo->beginTransaction();

            // جلب عملية الشراء الحالية مع قفل
            $purchase = fetchOne(
                "SELECT pu.*, p.name as product_name, p.purchase_price as current_purchase_price
                 FROM purchases pu
                 JOIN products p ON pu.product_id = p.id
                 WHERE pu.id = ?
                 FOR UPDATE",
                [$id]
            );

            if (!$purchase) {
                $pdo->rollBack();
                ApiResponse::notFound('عملية الشراء غير موجودة');
                return;
            }

            $oldQty = floatval($purchase['quantity']);
            $oldPrice = floatval($purchase['purchase_price']);
            $productId = intval($purchase['product_id']);
            $qtyDiff = $quantity - $oldQty;
            $total = $quantity * $purchasePrice;

            try {
                // تعديل المخزون بالفرق في الكمية
                if (abs($qtyDiff) > 0.001) {
                    $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                    $stmt->execute([$qtyDiff, $productId]);
                }

                // تحديث سعر الشراء إذا تغير
                if (abs($purchasePrice - $oldPrice) > 0.001) {
                    $stmt = $pdo->prepare("UPDATE products SET purchase_price = ? WHERE id = ?");
                    $stmt->execute([$purchasePrice, $productId]);

                    // تسجيل تغير السعر
                    try {
                        $stmt = $pdo->prepare("INSERT INTO price_history
                            (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason, changed_at)
                            VALUES (?, ?, ?, ?, ?, 'تعديل عملية شراء', NOW())");
                        $product = fetchOne("SELECT selling_price FROM products WHERE id = ?", [$productId]);
                        $stmt->execute([
                            $productId,
                            $oldPrice, $purchasePrice,
                            floatval($product['selling_price'] ?? 0), floatval($product['selling_price'] ?? 0),
                        ]);
                    } catch (\Throwable $e) {
                        // تجاهل إذا لم يكن جدول price_history موجوداً
                    }
                }

                // تحديث سجل الشراء
                $stmt = $pdo->prepare("UPDATE purchases SET
                    quantity = ?, purchase_price = ?, total = ?, purchase_date = ?, notes = ?
                    WHERE id = ?");
                $stmt->execute([$quantity, $purchasePrice, $total, $purchaseDate, $notes, $id]);

                $pdo->commit();

                // تسجيل في سجل التدقيق
                if (function_exists('addAuditLog')) {
                    addAuditLog('purchase_edit', "تعديل عملية شراء #{$id}");
                }

                $updatedPurchase = fetchOne(
                    "SELECT pu.*, p.name as product_name, p.unit, p.code as product_code
                     FROM purchases pu JOIN products p ON pu.product_id = p.id WHERE pu.id = ?",
                    [$id]
                );

                ApiResponse::success($updatedPurchase, 'تم تعديل عملية الشراء بنجاح');

            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

        } catch (AppException $e) {
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePurchaseController::update');
            ApiResponse::serverError('حدث خطأ أثناء تعديل عملية الشراء');
        }
    }
}
