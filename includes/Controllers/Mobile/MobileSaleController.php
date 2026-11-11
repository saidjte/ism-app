<?php
/**
 * ===== متحكم المبيعات للهاتف (Mobile Sale Controller) =====
 * يوفر واجهة API لإدارة المبيعات من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET    /api/mobile/sales           — قائمة المبيعات مع تصفية وترقيم صفحات
 *   GET    /api/mobile/sales/{id}      — تفاصيل عملية بيع واحدة
 *   POST   /api/mobile/sales           — تسجيل عملية بيع جديدة
 *   PUT    /api/mobile/sales/{id}      — تعديل عملية بيع
 *   DELETE /api/mobile/sales/{id}      — حذف عملية بيع
 *
 * يستخدم SaleService كطبقة منطق تجاري (المصدر الوحيد للحقيقة)
 *
 * v4.34.0: إنشاء طبقة API للهاتف
 * v5.13.0: إضافة تعديل عملية بيع
 */

require_once __DIR__ . '/../ApiController.php';

class MobileSaleController extends ApiController
{
    /**
     * قائمة المبيعات مع تصفية وترقيم صفحات
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
            $sql = "SELECT s.*, p.name as product_name, p.unit
                    FROM sales s
                    JOIN products p ON s.product_id = p.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total
                         FROM sales s
                         JOIN products p ON s.product_id = p.id
                         WHERE 1=1";

            $bindings = [];
            $conditions = '';

            // تصفية حسب تاريخ البداية
            if (!empty($dateFrom)) {
                $conditions .= " AND s.sale_date >= ?";
                $bindings[] = $dateFrom;
            }

            // تصفية حسب تاريخ النهاية
            if (!empty($dateTo)) {
                $conditions .= " AND s.sale_date <= ?";
                $bindings[] = $dateTo;
            }

            // تصفية حسب المنتج
            if ($productId > 0) {
                $conditions .= " AND s.product_id = ?";
                $bindings[] = $productId;
            }

            // إضافة الشروط للاستعلامات
            $sql .= $conditions;
            $countSql .= $conditions;

            // ترتيب تنازلي حسب التاريخ والمعرف
            $sql .= " ORDER BY s.sale_date DESC, s.id DESC";

            // إضافة الحد والإزاحة
            $sql .= " LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            // تنفيذ استعلام العدد
            $countRow = fetchOne($countSql, array_slice($bindings, 0, -2));
            $total = intval($countRow['total'] ?? 0);

            // تنفيذ استعلام البيانات
            $sales = fetchAll($sql, $bindings);

            if (!is_array($sales)) {
                $sales = [];
            }

            ApiResponse::paginated($sales, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileSaleController::index');
            ApiResponse::serverError('حدث خطأ أثناء جلب المبيعات');
        }
    }

    /**
     * تفاصيل عملية بيع واحدة
     *
     * معامل المسار: {id}
     *
     * الاستجابة:
     *   { success, data: { sale } } أو 404
     */
    public function show(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف عملية البيع مطلوب']);
                return;
            }

            $sale = fetchOne(
                "SELECT s.*, p.name as product_name, p.unit, p.code as product_code
                 FROM sales s
                 JOIN products p ON s.product_id = p.id
                 WHERE s.id = ?",
                [$id]
            );

            if (!$sale) {
                ApiResponse::notFound('عملية البيع غير موجودة');
                return;
            }

            // Batch subsystem removed: sale batch details are no longer returned.

            ApiResponse::success($sale);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileSaleController::show');
            ApiResponse::serverError('حدث خطأ أثناء جلب عملية البيع');
        }
    }

    /**
     * تسجيل عملية بيع جديدة
     *
     * الجسم (JSON):
     *   items: [{ product_id, quantity, selling_price }] — قائمة العناصر (مطلوب)
     *   sale_date — تاريخ البيع (اختياري، افتراضي: اليوم)
     *   notes     — ملاحظات (اختياري)
     *
     * الاستجابة:
     *   201 { success, data: { sale_id, sale_ids, summary }, message }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            $data = $this->all();

            // التحقق من وجود عناصر البيع
            if (empty($data['items'])) {
                ApiResponse::validationError(['items' => 'قائمة عناصر البيع مطلوبة']);
                return;
            }

            // تفويض المنطق التجاري إلى SaleService
            // v5.11.2: إزالة فحص $result['success'] القديم — SaleService يرمي AppException عند الخطأ
            $result = SaleService::create([
                'items'            => $data['items'],
                'sale_date'        => $data['sale_date'] ?? date('Y-m-d'),
                'notes'            => trim($data['notes'] ?? ''),
                'discount'         => floatval($data['discount'] ?? 0),
                'paid_amount'      => floatval($data['paid_amount'] ?? 0),
                'source'           => 'mobile',
                'send_notification' => false,
            ]);

            // SaleService::create() يرمي AppException عند الخطأ — لا نحتاج فحص success
            ApiResponse::created($result['data'] ?? $result, $result['message'] ?? 'تم تسجيل البيع بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            if ($e->getErrorType() === 'not_found') {
                ApiResponse::notFound($e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileSaleController::store');
            ApiResponse::serverError('حدث خطأ أثناء تسجيل البيع');
        }
    }

    /**
     * حذف عملية بيع مع استرجاع المخزون
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
                ApiResponse::validationError(['id' => 'معرف عملية البيع مطلوب']);
                return;
            }

            $result = SaleService::delete($id);

            ApiResponse::success(null, $result['message'] ?? 'تم حذف عملية البيع بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'not_found') {
                ApiResponse::notFound($e->getMessage());
            }
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileSaleController::destroy');
            ApiResponse::serverError('حدث خطأ أثناء حذف البيع');
        }
    }

    /**
     * تعديل عملية بيع
     *
     * معامل المسار: {id}
     * الجسم (JSON):
     *   quantity      — الكمية الجديدة (مطلوب)
     *   selling_price — سعر البيع الجديد (مطلوب)
     *   sale_date     — تاريخ البيع (اختياري)
     *   notes         — ملاحظات (اختياري)
     *
     * الاستجابة:
     *   { success, data: { sale }, message }
     */
    public function update(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف عملية البيع مطلوب']);
                return;
            }

            $quantity = floatval($this->input('quantity', 0));
            $sellingPrice = floatval($this->input('selling_price', 0));
            $saleDate = trim($this->input('sale_date', ''));
            $notes = trim($this->input('notes', ''));

            if ($quantity <= 0) {
                ApiResponse::validationError(['quantity' => 'الكمية يجب أن تكون أكبر من صفر']);
                return;
            }
            if ($sellingPrice <= 0) {
                ApiResponse::validationError(['selling_price' => 'سعر البيع يجب أن يكون أكبر من صفر']);
                return;
            }
            if (empty($saleDate)) {
                $saleDate = date('Y-m-d');
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // بدء المعاملة أولاً حتى يعمل FOR UPDATE
            $pdo->beginTransaction();

            // جلب عملية البيع الحالية مع قفل
            $sale = fetchOne(
                "SELECT s.*, p.name as product_name, p.quantity as current_product_qty
                 FROM sales s
                 JOIN products p ON s.product_id = p.id
                 WHERE s.id = ?
                 FOR UPDATE",
                [$id]
            );

            if (!$sale) {
                $pdo->rollBack();
                ApiResponse::notFound('عملية البيع غير موجودة');
                return;
            }

            $oldQty = floatval($sale['quantity']);
            $newQty = $quantity;
            $productId = intval($sale['product_id']);

            try {
                // المرحلة 1: استرجاع الكمية القديمة للمخزون
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$oldQty, $productId]);

                // المرحلة 2: التحقق من توفر الكمية الجديدة
                $product = fetchOne(
                    "SELECT id, quantity, purchase_price, selling_price FROM products WHERE id = ? FOR UPDATE",
                    [$productId]
                );

                if (floatval($product['quantity']) < $newQty) {
                    $pdo->rollBack();
                    ApiResponse::validationError([
                        'quantity' => "الكمية المتوفرة ({$product['quantity']}) أقل من المطلوب ({$newQty})"
                    ]);
                    return;
                }

                // المرحلة 3: حساب التكلفة والربح
                $costPrice = floatval($product['purchase_price']);
                $total = $newQty * $sellingPrice;
                $costTotal = $newQty * $costPrice;
                $profit = $total - $costTotal;

                // المرحلة 4: تحديث سجل البيع
                $stmt = $pdo->prepare("UPDATE sales SET
                    quantity = ?, selling_price = ?, total = ?, cost_total = ?, profit = ?,
                    purchase_price_at_sale = ?, sale_date = ?, notes = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newQty, $sellingPrice, $total, $costTotal, $profit,
                    $costPrice, $saleDate, $notes, $id
                ]);

                // المرحلة 5: خصم الكمية الجديدة من المخزون
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                $stmt->execute([$newQty, $productId]);

                $pdo->commit();

                // إرسال إشعار
                if (function_exists('addNotification')) {
                    addNotification('sale_edit', "تعديل عملية بيع #{$id} — {$sale['product_name']}");
                }

                // تسجيل في سجل التدقيق
                if (function_exists('addAuditLog')) {
                    addAuditLog('sale_edit', "تعديل عملية بيع #{$id}");
                }

                $updatedSale = fetchOne(
                    "SELECT s.*, p.name as product_name, p.unit, p.code as product_code
                     FROM sales s JOIN products p ON s.product_id = p.id WHERE s.id = ?",
                    [$id]
                );

                ApiResponse::success($updatedSale, 'تم تعديل عملية البيع بنجاح');

            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

        } catch (AppException $e) {
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileSaleController::update');
            ApiResponse::serverError('حدث خطأ أثناء تعديل عملية البيع');
        }
    }
}
