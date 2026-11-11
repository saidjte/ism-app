<?php
/**
 * ===== متحكم المخزون للهاتف (Mobile Stock Controller) =====
 * يوفر واجهة API لتحويل المخزون والجرد من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   POST /api/mobile/stock/transfer        — تحويل مخزون بين منتجين
 *   POST /api/mobile/stock/inventory-count — حفظ جرد المخزون
 *   POST /api/mobile/stock/update-qty      — تحديث كمية منتج واحد (AJAX)
 *   GET  /api/mobile/stock/inventory-count — بيانات الجرد الحالية
 *   GET  /api/mobile/stock/last-count      — آخر جرد منفذ
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف تحويل المخزون والجرد
 */

require_once __DIR__ . '/../ApiController.php';

class MobileStockController extends ApiController
{
    /**
     * تحويل مخزون بين منتجين
     *
     * الجسم (JSON):
     *   from_product_id — معرف المنتج المصدر (مطلوب)
     *   to_product_id   — معرف المنتج الوجهة (مطلوب)
     *   quantity        — الكمية المحولة (مطلوب، > 0)
     *   notes           — ملاحظات (اختياري)
     *
     * الاستجابة:
     *   { success, data: { from_product, to_product, quantity, message } }
     */
    public function transfer(array $params = []): void
    {
        try {
            $this->requirePost();
            $fromProductId = intval($this->input('from_product_id', 0));
            $toProductId = intval($this->input('to_product_id', 0));
            $quantity = floatval($this->input('quantity', 0));
            $notes = trim($this->input('notes', ''));

            // التحقق من المدخلات
            if ($fromProductId <= 0) {
                ApiResponse::validationError(['from_product_id' => 'معرف المنتج المصدر مطلوب']);
                return;
            }
            if ($toProductId <= 0) {
                ApiResponse::validationError(['to_product_id' => 'معرف المنتج الوجهة مطلوب']);
                return;
            }
            if ($fromProductId === $toProductId) {
                ApiResponse::validationError(['to_product_id' => 'لا يمكن التحويل بين نفس المنتج']);
                return;
            }
            if ($quantity <= 0) {
                ApiResponse::validationError(['quantity' => 'يجب أن تكون الكمية أكبر من صفر']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // بدء المعاملة أولاً حتى يعمل FOR UPDATE
            $pdo->beginTransaction();

            // جلب المنتج المصدر مع قفل
            $fromProduct = fetchOne(
                "SELECT id, name, quantity FROM products WHERE id = ? FOR UPDATE",
                [$fromProductId]
            );

            if (!$fromProduct) {
                $pdo->rollBack();
                ApiResponse::notFound('المنتج المصدر غير موجود');
                return;
            }

            // التحقق من الكمية المتوفرة
            if (floatval($fromProduct['quantity']) < $quantity) {
                $pdo->rollBack();
                ApiResponse::validationError([
                    'quantity' => "الكمية المتوفرة ({$fromProduct['quantity']}) أقل من المطلوب ({$quantity})"
                ]);
                return;
            }

            // جلب المنتج الوجهة مع قفل
            $toProduct = fetchOne(
                "SELECT id, name, quantity FROM products WHERE id = ? FOR UPDATE",
                [$toProductId]
            );

            if (!$toProduct) {
                $pdo->rollBack();
                ApiResponse::notFound('المنتج الوجهة غير موجود');
                return;
            }

            try {
                // خصم من المصدر
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                $stmt->execute([$quantity, $fromProductId]);

                // إضافة للوجهة
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$quantity, $toProductId]);

                $pdo->commit();

                // تسجيل في سجل التدقيق
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        'stock_transfer',
                        "تحويل {$quantity} من '{$fromProduct['name']}' إلى '{$toProduct['name']}" . ($notes ? " — {$notes}" : '')
                    );
                }

                // جلب البيانات المحدثة
                $updatedFrom = fetchOne("SELECT id, name, quantity FROM products WHERE id = ?", [$fromProductId]);
                $updatedTo = fetchOne("SELECT id, name, quantity FROM products WHERE id = ?", [$toProductId]);

                ApiResponse::success([
                    'from_product' => $updatedFrom,
                    'to_product' => $updatedTo,
                    'quantity' => $quantity,
                ], 'تم تحويل المخزون بنجاح');

            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

        } catch (AppException $e) {
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileStockController::transfer');
            ApiResponse::serverError('حدث خطأ أثناء تحويل المخزون');
        }
    }

    /**
     * تحديث كمية منتج واحد (AJAX-style)
     *
     * الجسم (JSON):
     *   product_id — معرف المنتج (مطلوب)
     *   new_qty    — الكمية الجديدة (مطلوب، >= 0)
     *
     * الاستجابة:
     *   { success, data: { product_id, old_qty, new_qty, difference } }
     */
    public function updateQty(array $params = []): void
    {
        try {
            $this->requirePost();
            $productId = intval($this->input('product_id', 0));
            $newQty = floatval($this->input('new_qty', -1));

            if ($productId <= 0) {
                ApiResponse::validationError(['product_id' => 'معرف المنتج مطلوب']);
                return;
            }
            if ($newQty < 0) {
                ApiResponse::validationError(['new_qty' => 'الكمية الجديدة مطلوبة ويجب أن تكون >= 0']);
                return;
            }

            // جلب الكمية الحالية
            $product = fetchOne("SELECT id, name, quantity FROM products WHERE id = ?", [$productId]);
            if (!$product) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            $oldQty = floatval($product['quantity']);
            $difference = $newQty - $oldQty;

            // تحديث الكمية
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
            $stmt->execute([$newQty, $productId]);

            // تسجيل في جدول الجرد
            try {
                $stmt = $pdo->prepare("INSERT INTO inventory_counts
                    (product_id, actual_quantity, system_quantity, difference, count_date, notes, created_by)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                $stmt->execute([
                    $productId,
                    $newQty,
                    $oldQty,
                    $difference,
                    'تحديث مباشر من الهاتف',
                    $this->userId(),
                ]);
            } catch (\Throwable $e) {
                // تجاهل إذا لم يكن الجدول موجوداً
            }

            // تسجيل في سجل التدقيق
            if (function_exists('addAuditLog')) {
                addAuditLog('stock_update', "تحديث كمية المنتج '{$product['name']}' من {$oldQty} إلى {$newQty}");
            }

            ApiResponse::success([
                'product_id' => $productId,
                'old_qty' => $oldQty,
                'new_qty' => $newQty,
                'difference' => $difference,
            ], 'تم تحديث الكمية بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileStockController::updateQty');
            ApiResponse::serverError('حدث خطأ أثناء تحديث الكمية');
        }
    }

    /**
     * حفظ جرد المخزون الكامل
     *
     * الجسم (JSON):
     *   count_date — تاريخ الجرد (اختياري، افتراضي: اليوم)
     *   notes      — ملاحظات (اختياري)
     *   items      — [{ product_id, actual_qty }] قائمة المنتجات (مطلوب)
     *
     * الاستجابة:
     *   { success, data: { total_items, updated, matched, deficit, surplus, message } }
     */
    public function saveInventoryCount(array $params = []): void
    {
        try {
            $this->requirePost();
            $countDate = trim($this->input('count_date', date('Y-m-d')));
            $notes = trim($this->input('notes', ''));
            $items = $this->input('items', []);

            if (empty($items) || !is_array($items)) {
                ApiResponse::validationError(['items' => 'قائمة المنتجات مطلوبة']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $pdo->beginTransaction();

            try {
                $totalItems = count($items);
                $updated = 0;
                $matched = 0;
                $deficit = 0;
                $surplus = 0;

                foreach ($items as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $actualQty = floatval($item['actual_qty'] ?? -1);

                    if ($productId <= 0 || $actualQty < 0) {
                        continue;
                    }

                    // جلب الكمية الحالية
                    $product = fetchOne("SELECT id, quantity FROM products WHERE id = ?", [$productId]);
                    if (!$product) {
                        continue;
                    }

                    $systemQty = floatval($product['quantity']);
                    $difference = $actualQty - $systemQty;

                    // تسجيل في جدول الجرد
                    try {
                        $stmt = $pdo->prepare("INSERT INTO inventory_counts
                            (product_id, actual_quantity, system_quantity, difference, count_date, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $productId,
                            $actualQty,
                            $systemQty,
                            $difference,
                            $countDate,
                            $notes,
                            $this->userId(),
                        ]);
                    } catch (\Throwable $e) {
                        // تجاهل إذا لم يكن الجدول موجوداً
                    }

                    // تحديث الكمية فقط إذا اختلف الفعلي عن النظامي
                    if (abs($difference) > 0.001) {
                        $stmt = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                        $stmt->execute([$actualQty, $productId]);
                        $updated++;

                        if ($difference < 0) {
                            $deficit++;
                        } else {
                            $surplus++;
                        }
                    } else {
                        $matched++;
                    }
                }

                $pdo->commit();

                // تسجيل في سجل التدقيق
                if (function_exists('addAuditLog')) {
                    addAuditLog('inventory_count', "جرد المخزون: {$totalItems} منتج، {$updated} محدث، {$matched} مطابق");
                }

                ApiResponse::success([
                    'total_items' => $totalItems,
                    'updated' => $updated,
                    'matched' => $matched,
                    'deficit' => $deficit,
                    'surplus' => $surplus,
                ], 'تم حفظ الجرد بنجاح');

            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileStockController::saveInventoryCount');
            ApiResponse::serverError('حدث خطأ أثناء حفظ الجرد');
        }
    }

    /**
     * بيانات الجرد الحالية (قائمة المنتجات مع الكميات)
     *
     * معاملات GET:
     *   category_id — تصفية بالتصنيف (اختياري)
     *   page        — رقم الصفحة
     *   per_page    — عدد العناصر
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function inventoryData(array $params = []): void
    {
        try {
            $categoryId = intval($this->input('category_id', 0));
            $pagination = $this->getPaginationParams(50);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            $sql = "SELECT p.id, p.name, p.code, p.quantity as system_qty, p.unit,
                           p.category_id, c.name as category_name
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total FROM products p WHERE 1=1";
            $bindings = [];

            if ($categoryId > 0) {
                $sql .= " AND p.category_id = ?";
                $countSql .= " AND p.category_id = ?";
                $bindings[] = $categoryId;
            }

            $sql .= " ORDER BY p.name ASC LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            $products = fetchAll($sql, $bindings);
            $countRow = fetchOne($countSql, array_slice($bindings, 0, -2));
            $total = intval($countRow['total'] ?? 0);

            if (!is_array($products)) {
                $products = [];
            }

            ApiResponse::paginated($products, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileStockController::inventoryData');
            ApiResponse::serverError('حدث خطأ أثناء جلب بيانات الجرد');
        }
    }

    /**
     * آخر جرد منفذ
     *
     * الاستجابة:
     *   { success, data: { last_count_date, items_count, total_difference } }
     */
    public function lastCount(array $params = []): void
    {
        try {
            try {
                $lastCount = fetchOne(
                    "SELECT DATE(count_date) as count_date, COUNT(*) as items_count,
                            SUM(difference) as total_difference
                     FROM inventory_counts
                     GROUP BY DATE(count_date)
                     ORDER BY count_date DESC
                     LIMIT 1"
                );
            } catch (\Throwable $e) {
                $lastCount = null;
            }

            ApiResponse::success($lastCount ?? [
                'count_date' => null,
                'items_count' => 0,
                'total_difference' => 0,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileStockController::lastCount');
            ApiResponse::serverError('حدث خطأ أثناء جلب آخر جرد');
        }
    }
}
