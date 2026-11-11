<?php
/**
 * ===== متحكم المنتجات للهاتف (Mobile Product Controller) =====
 * يوفر واجهة API لإدارة المنتجات من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET    /api/mobile/products           — قائمة المنتجات مع بحث وترقيم صفحات
 *   GET    /api/mobile/products/{id}      — تفاصيل منتج واحد
 *   POST   /api/mobile/products           — إضافة منتج جديد
 *   PUT    /api/mobile/products/{id}      — تحديث منتج
 *   DELETE /api/mobile/products/{id}      — حذف منتج
 *   GET    /api/mobile/products/search    — بحث متقدم (FULLTEXT + LIKE)
 *   GET    /api/mobile/products/{id}/units — الوحدات الإضافية للمنتج
 *   GET    /api/mobile/products/{id}/purchase-units — وحدات الشراء للمنتج
 *
 * يستخدم ProductService كطبقة منطق تجاري (المصدر الوحيد للحقيقة)
 *
 * v4.34.0: إنشاء طبقة API للهاتف
 * v5.13.0: إضافة بحث متقدم + وحدات إضافية + وحدات الشراء
 */

require_once __DIR__ . '/../ApiController.php';

class MobileProductController extends ApiController
{
    /**
     * قائمة المنتجات مع بحث وترقيم صفحات
     *
     * معاملات GET:
     *   search      — نص البحث (اختياري)
     *   category_id — معرف التصنيف (اختياري)
     *   page        — رقم الصفحة (افتراضي: 1)
     *   per_page    — عدد العناصر في الصفحة (افتراضي: 20، أقصى: 100)
     *   low_stock   — عرض المنتجات منخفضة المخزون فقط (اختياري، bool)
     *
     * الاستجابة:
     *   { success, data: [...], pagination: { total, per_page, current_page, total_pages, has_next, has_prev } }
     */
    public function index(array $params = []): void
    {
        try {
            $search = trim($this->input('search', ''));
            $categoryId = intval($this->input('category_id', 0));
            $pagination = $this->getPaginationParams(20);
            $lowStock = filter_var($this->input('low_stock', false), FILTER_VALIDATE_BOOL);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];

            // إذا كان هناك نص بحث أو تصفية بالتصنيف، نستخدم ProductService::search
            if (!empty($search) || $categoryId > 0) {
                $filters = [];
                if (!empty($search)) {
                    $filters['search'] = $search;
                }
                if ($categoryId > 0) {
                    $filters['category_id'] = $categoryId;
                }

                $result = ProductService::search($filters, $page, $perPage);
                $products = $result['products'] ?? $result['data'] ?? [];
                $total = intval($result['total'] ?? 0);

                // تصفية المنتجات منخفضة المخزون إذا طُلب
                if ($lowStock && !empty($products)) {
                    $products = array_values(array_filter($products, function ($p) {
                        return isset($p['quantity'], $p['min_quantity'])
                            && floatval($p['quantity']) <= floatval($p['min_quantity']);
                    }));
                    $total = count($products);
                }
            } elseif ($lowStock) {
                // طلب المنتجات منخفضة المخزون فقط
                $offset = $pagination['offset'];

                $products = fetchAll(
                    "SELECT p.*, c.name as category_name
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.quantity <= p.min_quantity
                     ORDER BY p.name ASC
                     LIMIT ? OFFSET ?",
                    [$perPage, $offset]
                );

                $countRow = fetchOne(
                    "SELECT COUNT(*) as total FROM products WHERE quantity <= min_quantity"
                );
                $total = intval($countRow['total'] ?? 0);

                if (!is_array($products)) {
                    $products = [];
                }
            } else {
                // عرض كل المنتجات مع ترقيم الصفحات
                $offset = $pagination['offset'];

                $products = fetchAll(
                    "SELECT p.*, c.name as category_name
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     ORDER BY p.name ASC
                     LIMIT ? OFFSET ?",
                    [$perPage, $offset]
                );

                $countRow = fetchOne("SELECT COUNT(*) as total FROM products");
                $total = intval($countRow['total'] ?? 0);

                if (!is_array($products)) {
                    $products = [];
                }
            }

            ApiResponse::paginated($products, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::index');
            ApiResponse::serverError('حدث خطأ أثناء جلب المنتجات');
        }
    }

    /**
     * تفاصيل منتج واحد
     *
     * معامل المسار: {id}
     *
     * الاستجابة:
     *   { success, data: { product } } أو 404
     */
    public function show(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            $product = ProductService::getById($id);

            if ($product === null) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            ApiResponse::success($product);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::show');
            ApiResponse::serverError('حدث خطأ أثناء جلب المنتج');
        }
    }

    /**
     * إضافة منتج جديد
     *
     * الجسم (JSON):
     *   name, code, purchase_price, selling_price, unit, quantity, min_quantity,
     *   category_id, has_expiry, additional_units, expiry_dates
     *
     * الاستجابة:
     *   201 { success, data: { id, message } }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            $data = $this->all();
            $result = ProductService::create($data);

            ApiResponse::created($result, $result['message'] ?? 'تم إضافة المنتج بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::store');
            ApiResponse::serverError('حدث خطأ أثناء إضافة المنتج');
        }
    }

    /**
     * تحديث منتج موجود
     *
     * معامل المسار: {id}
     * الجسم (JSON): البيانات المراد تحديثها
     *
     * الاستجابة:
     *   { success, data: { message } }
     */
    public function update(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            $data = $this->all();
            $result = ProductService::update($id, $data);

            ApiResponse::success($result, $result['message'] ?? 'تم تحديث المنتج بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            if ($e->getErrorType() === 'not_found') {
                ApiResponse::notFound($e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::update');
            ApiResponse::serverError('حدث خطأ أثناء تحديث المنتج');
        }
    }

    /**
     * حذف منتج
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
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            $result = ProductService::delete($id);

            ApiResponse::success(null, $result['message'] ?? 'تم حذف المنتج بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'not_found') {
                ApiResponse::notFound($e->getMessage());
            }
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::destroy');
            ApiResponse::serverError('حدث خطأ أثناء حذف المنتج');
        }
    }

    /**
     * بحث متقدم عن المنتجات (FULLTEXT + LIKE fallback)
     *
     * معاملات GET:
     *   q           — نص البحث (مطلوب)
     *   category_id — معرف التصنيف (اختياري)
     *   limit       — عدد النتائج (5-100، افتراضي: 20)
     *
     * الاستجابة:
     *   { success, data: { products, total } }
     */
    public function search(array $params = []): void
    {
        try {
            $query = trim($this->input('q', ''));
            $categoryId = intval($this->input('category_id', 0));
            $limit = min(100, max(5, intval($this->input('limit', 20))));

            if (empty($query)) {
                ApiResponse::validationError(['q' => 'نص البحث مطلوب']);
                return;
            }

            // استخدام SearchService إذا كان متاحاً
            if (class_exists('SearchService') && method_exists('SearchService', 'search')) {
                $result = SearchService::search($query, $categoryId, $limit);
                $products = $result['products'] ?? $result['data'] ?? $result ?? [];
                $total = intval($result['total'] ?? count($products));
            } elseif (class_exists('ProductService') && method_exists('ProductService', 'search')) {
                $filters = ['search' => $query];
                if ($categoryId > 0) {
                    $filters['category_id'] = $categoryId;
                }
                $result = ProductService::search($filters, 1, $limit);
                $products = $result['products'] ?? $result['data'] ?? [];
                $total = intval($result['total'] ?? 0);
            } else {
                // بحث مباشر
                $sql = "SELECT p.*, c.name as category_name
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE (p.name LIKE ? OR p.code LIKE ?)";
                $bindings = ["%{$query}%", "%{$query}%"];

                if ($categoryId > 0) {
                    $sql .= " AND p.category_id = ?";
                    $bindings[] = $categoryId;
                }

                $sql .= " ORDER BY p.name ASC LIMIT ?";
                $bindings[] = $limit;

                $products = fetchAll($sql, $bindings);
                if (!is_array($products)) $products = [];
                $total = count($products);
            }

            ApiResponse::success([
                'products' => $products,
                'total' => $total,
                'query' => $query,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::search');
            ApiResponse::serverError('حدث خطأ أثناء البحث عن المنتجات');
        }
    }

    /**
     * الوحدات الإضافية للبيع للمنتج
     *
     * معامل المسار: {id} — معرف المنتج
     *
     * الاستجابة:
     *   { success, data: { product_id, units } }
     */
    public function units(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            $product = fetchOne("SELECT id, name, unit, selling_price FROM products WHERE id = ?", [$id]);
            if (!$product) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            $units = [];
            try {
                $units = fetchAll(
                    "SELECT id, unit_name, selling_price, conversion_factor
                     FROM additional_units
                     WHERE product_id = ?
                     ORDER BY id ASC",
                    [$id]
                );
                if (!is_array($units)) $units = [];
            } catch (\Throwable $e) {
                $units = [];
            }

            ApiResponse::success([
                'product_id' => $id,
                'product_name' => $product['name'],
                'base_unit' => $product['unit'],
                'base_price' => floatval($product['selling_price']),
                'units' => $units,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::units');
            ApiResponse::serverError('حدث خطأ أثناء جلب الوحدات الإضافية');
        }
    }

    /**
     * وحدات الشراء للمنتج
     *
     * معامل المسار: {id} — معرف المنتج
     *
     * الاستجابة:
     *   { success, data: { product_id, purchase_unit, purchase_unit_factor, selling_unit, additional_units } }
     */
    public function purchaseUnits(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            $product = fetchOne(
                "SELECT id, name, unit as selling_unit, purchase_price, selling_price,
                        purchase_unit, purchase_unit_factor
                 FROM products WHERE id = ?",
                [$id]
            );
            if (!$product) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            // وحدات الشراء الإضافية
            $additionalUnits = [];
            try {
                $additionalUnits = fetchAll(
                    "SELECT id, unit_name, selling_price, conversion_factor
                     FROM additional_units
                     WHERE product_id = ?
                     ORDER BY id ASC",
                    [$id]
                );
                if (!is_array($additionalUnits)) $additionalUnits = [];
            } catch (\Throwable $e) {
                $additionalUnits = [];
            }

            ApiResponse::success([
                'product_id' => $id,
                'product_name' => $product['name'],
                'purchase_unit' => $product['purchase_unit'] ?? $product['selling_unit'],
                'purchase_unit_factor' => floatval($product['purchase_unit_factor'] ?? 1),
                'selling_unit' => $product['selling_unit'],
                'purchase_price' => floatval($product['purchase_price']),
                'selling_price' => floatval($product['selling_price']),
                'additional_units' => $additionalUnits,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileProductController::purchaseUnits');
            ApiResponse::serverError('حدث خطأ أثناء جلب وحدات الشراء');
        }
    }
}
