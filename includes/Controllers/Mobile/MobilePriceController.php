<?php
/**
 * ===== متحكم الأسعار للهاتف (Mobile Price Controller) =====
 * يوفر واجهة API لقائمة الأسعار وتاريخ تغيرات الأسعار من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET  /api/mobile/prices/list          — قائمة أسعار المنتجات مع تصفية
 *   GET  /api/mobile/prices/history        — سجل تغيرات الأسعار
 *   GET  /api/mobile/prices/history/{id}   — تاريخ أسعار منتج محدد
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف قائمة الأسعار وتاريخ الأسعار
 */

require_once __DIR__ . '/../ApiController.php';

class MobilePriceController extends ApiController
{
    /**
     * قائمة أسعار المنتجات مع تصفية بالتصنيف
     *
     * معاملات GET:
     *   category_id — معرف التصنيف (اختياري)
     *   search      — نص البحث (اختياري)
     *   page        — رقم الصفحة (افتراضي: 1)
     *   per_page    — عدد العناصر في الصفحة (افتراضي: 20، أقصى: 100)
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function priceList(array $params = []): void
    {
        try {
            $categoryId = intval($this->input('category_id', 0));
            $search = trim($this->input('search', ''));
            $pagination = $this->getPaginationParams(20);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            // بناء الاستعلام
            $sql = "SELECT p.id, p.name, p.code, p.selling_price, p.purchase_price, p.unit,
                           p.quantity, p.category_id, c.name as category_name, c.color as category_color
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total FROM products p WHERE 1=1";
            $bindings = [];

            // تصفية بالتصنيف
            if ($categoryId > 0) {
                $sql .= " AND p.category_id = ?";
                $countSql .= " AND p.category_id = ?";
                $bindings[] = $categoryId;
            }

            // بحث بالنص
            if (!empty($search)) {
                $sql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
                $countSql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
                $bindings[] = "%{$search}%";
                $bindings[] = "%{$search}%";
            }

            // ترتيب حسب التصنيف ثم الاسم
            $sql .= " ORDER BY c.name ASC, p.name ASC";

            // إضافة ترقيم الصفحات
            $sql .= " LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            $products = fetchAll($sql, $bindings);
            $countRow = fetchOne($countSql, array_slice($bindings, 0, -2));
            $total = intval($countRow['total'] ?? 0);

            if (!is_array($products)) {
                $products = [];
            }

            // جلب الوحدات الإضافية لكل منتج
            foreach ($products as &$product) {
                try {
                    $additionalUnits = fetchAll(
                        "SELECT unit_name, selling_price, conversion_factor
                         FROM additional_units
                         WHERE product_id = ?",
                        [intval($product['id'])]
                    );
                    $product['additional_units'] = is_array($additionalUnits) ? $additionalUnits : [];
                } catch (\Throwable $e) {
                    $product['additional_units'] = [];
                }
            }
            unset($product);

            // جلب معلومات العملة
            $currency = function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.ج';
            $currencyPosition = function_exists('getSetting') ? (getSetting('currency_position', 'right') ?? 'right') : 'right';

            ApiResponse::paginated($products, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePriceController::priceList');
            ApiResponse::serverError('حدث خطأ أثناء جلب قائمة الأسعار');
        }
    }

    /**
     * سجل تغيرات الأسعار
     *
     * معاملات GET:
     *   search    — بحث بكود أو اسم المنتج (اختياري)
     *   type      — نوع التغيير: all|purchase|selling (افتراضي: all)
     *   date_from — تاريخ البداية (اختياري)
     *   date_to   — تاريخ النهاية (اختياري)
     *   page      — رقم الصفحة (افتراضي: 1)
     *   per_page  — عدد العناصر (افتراضي: 50)
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function priceHistory(array $params = []): void
    {
        try {
            $search = trim($this->input('search', ''));
            $type = $this->input('type', 'all');
            $dateFrom = trim($this->input('date_from', ''));
            $dateTo = trim($this->input('date_to', ''));
            $pagination = $this->getPaginationParams(50);

            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            // فحص وجود جدول price_history
            try {
                $db = AppDatabase::getInstance();
                $pdo = $db->getConnection();
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'price_history'");
                if (empty($tableCheck->fetch())) {
                    ApiResponse::success([
                        'records' => [],
                        'stats' => [
                            'total_changes' => 0,
                            'purchase_changes' => 0,
                            'selling_changes' => 0,
                            'last_change' => null,
                        ],
                    ], 'جدول تاريخ الأسعار غير موجود');
                    return;
                }
            } catch (\Throwable $e) {
                ApiResponse::success([
                    'records' => [],
                    'stats' => [
                        'total_changes' => 0,
                        'purchase_changes' => 0,
                        'selling_changes' => 0,
                        'last_change' => null,
                    ],
                ], 'تعذر الوصول لجدول تاريخ الأسعار');
                return;
            }

            // بناء الاستعلام
            $sql = "SELECT ph.*, p.name as product_name, p.code as product_code
                    FROM price_history ph
                    JOIN products p ON ph.product_id = p.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total
                         FROM price_history ph
                         JOIN products p ON ph.product_id = p.id
                         WHERE 1=1";

            $bindings = [];

            // تصفية حسب نوع التغيير
            if ($type === 'purchase') {
                $sql .= " AND ph.old_purchase_price != ph.new_purchase_price";
                $countSql .= " AND ph.old_purchase_price != ph.new_purchase_price";
            } elseif ($type === 'selling') {
                $sql .= " AND ph.old_selling_price != ph.new_selling_price";
                $countSql .= " AND ph.old_selling_price != ph.new_selling_price";
            }

            // تصفية بالتاريخ
            if (!empty($dateFrom)) {
                $sql .= " AND DATE(ph.changed_at) >= ?";
                $countSql .= " AND DATE(ph.changed_at) >= ?";
                $bindings[] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $sql .= " AND DATE(ph.changed_at) <= ?";
                $countSql .= " AND DATE(ph.changed_at) <= ?";
                $bindings[] = $dateTo;
            }

            // بحث بالمنتج
            if (!empty($search)) {
                $sql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
                $countSql .= " AND (p.name LIKE ? OR p.code LIKE ?)";
                $bindings[] = "%{$search}%";
                $bindings[] = "%{$search}%";
            }

            // ترتيب تنازلي
            $sql .= " ORDER BY ph.changed_at DESC, ph.id DESC";
            $sql .= " LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            $records = fetchAll($sql, $bindings);
            $countRow = fetchOne($countSql, array_slice($bindings, 0, -2));
            $total = intval($countRow['total'] ?? 0);

            if (!is_array($records)) {
                $records = [];
            }

            // حساب نسب التغيير لكل سجل
            foreach ($records as &$record) {
                $record['purchase_diff'] = floatval($record['new_purchase_price'] ?? 0) - floatval($record['old_purchase_price'] ?? 0);
                $record['selling_diff'] = floatval($record['new_selling_price'] ?? 0) - floatval($record['old_selling_price'] ?? 0);

                $oldPurchase = floatval($record['old_purchase_price'] ?? 0);
                $record['purchase_change_pct'] = $oldPurchase > 0
                    ? round(($record['purchase_diff'] / $oldPurchase) * 100, 2)
                    : 0;

                $oldSelling = floatval($record['old_selling_price'] ?? 0);
                $record['selling_change_pct'] = $oldSelling > 0
                    ? round(($record['selling_diff'] / $oldSelling) * 100, 2)
                    : 0;
            }
            unset($record);

            // إحصائيات سريعة
            $stats = [
                'total_changes' => $total,
                'purchase_changes' => 0,
                'selling_changes' => 0,
                'last_change' => null,
            ];

            try {
                $purchaseCount = fetchOne(
                    "SELECT COUNT(*) as c FROM price_history WHERE old_purchase_price != new_purchase_price"
                );
                $stats['purchase_changes'] = intval($purchaseCount['c'] ?? 0);

                $sellingCount = fetchOne(
                    "SELECT COUNT(*) as c FROM price_history WHERE old_selling_price != new_selling_price"
                );
                $stats['selling_changes'] = intval($sellingCount['c'] ?? 0);

                $lastChange = fetchOne(
                    "SELECT changed_at FROM price_history ORDER BY changed_at DESC LIMIT 1"
                );
                $stats['last_change'] = $lastChange['changed_at'] ?? null;
            } catch (\Throwable $e) {
                // تجاهل أخطاء الإحصائيات
            }

            $data = [
                'records' => $records,
                'stats' => $stats,
            ];

            ApiResponse::paginated($data, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePriceController::priceHistory');
            ApiResponse::serverError('حدث خطأ أثناء جلب تاريخ الأسعار');
        }
    }

    /**
     * تاريخ أسعار منتج محدد
     *
     * معامل المسار: {id} — معرف المنتج
     *
     * الاستجابة:
     *   { success, data: { product, history } }
     */
    public function productPriceHistory(array $params = []): void
    {
        try {
            $productId = intval($params['id'] ?? 0);
            if ($productId <= 0) {
                ApiResponse::validationError(['id' => 'معرف المنتج مطلوب']);
                return;
            }

            // جلب بيانات المنتج
            $product = fetchOne(
                "SELECT id, name, code, purchase_price, selling_price, unit FROM products WHERE id = ?",
                [$productId]
            );

            if (!$product) {
                ApiResponse::notFound('المنتج غير موجود');
                return;
            }

            // جلب تاريخ الأسعار
            try {
                $history = fetchAll(
                    "SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC, id DESC LIMIT 100",
                    [$productId]
                );
                if (!is_array($history)) {
                    $history = [];
                }
            } catch (\Throwable $e) {
                $history = [];
            }

            ApiResponse::success([
                'product' => $product,
                'history' => $history,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePriceController::productPriceHistory');
            ApiResponse::serverError('حدث خطأ أثناء جلب تاريخ أسعار المنتج');
        }
    }
}
