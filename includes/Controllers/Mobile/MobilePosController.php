<?php
/**
 * ===== متحكم نقطة البيع للهاتف (Mobile POS Controller) =====
 * يوفر واجهة API لعمليات نقطة البيع من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET  /api/mobile/pos/products        — بحث منتجات مخصص لنقطة البيع
 *   GET  /api/mobile/pos/product-images   — صور المنتجات للنقطة
 *   POST /api/mobile/pos/checkout         — معالجة عملية البيع
 *   GET  /api/mobile/pos/settings         — إعدادات نقطة البيع
 *   GET  /api/mobile/pos/categories       — التصنيفات مع عدد المنتجات
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف نقطة البيع
 */

require_once __DIR__ . '/../ApiController.php';

class MobilePosController extends ApiController
{
    /**
     * بحث منتجات مخصص لنقطة البيع
     * يبحث بالاسم، الكود، والباركود مع دعم الإكمال التلقائي
     *
     * معاملات GET:
     *   q           — نص البحث (مطلوب)
     *   category_id — معرف التصنيف (اختياري)
     *   limit       — عدد النتائج (5-50، افتراضي: 20)
     *
     * الاستجابة:
     *   { success, data: [...] }
     */
    public function products(array $params = []): void
    {
        try {
            $query = trim($this->input('q', ''));
            $categoryId = intval($this->input('category_id', 0));
            $limit = min(50, max(5, intval($this->input('limit', 20))));

            if (empty($query) && $categoryId <= 0) {
                ApiResponse::validationError(['q' => 'نص البحث أو التصنيف مطلوب']);
                return;
            }

            // استخدام SearchService إذا كان متاحاً
            if (class_exists('SearchService') && method_exists('SearchService', 'buildSearchWhere')) {
                $results = $this->searchWithService($query, $categoryId, $limit);
            } else {
                $results = $this->searchDirect($query, $categoryId, $limit);
            }

            ApiResponse::success($results);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePosController::products');
            ApiResponse::serverError('حدث خطأ أثناء البحث عن المنتجات');
        }
    }

    /**
     * صور المنتجات لنقطة البيع (لعرض الصور المصغرة)
     *
     * معاملات GET:
     *   ids — قائمة معرفات المنتجات مفصولة بفواصل (مطلوب)
     *
     * الاستجابة:
     *   { success, data: { product_id: image_url, ... } }
     */
    public function productImages(array $params = []): void
    {
        try {
            $ids = $this->input('ids', '');
            if (empty($ids)) {
                ApiResponse::validationError(['ids' => 'قائمة معرفات المنتجات مطلوبة']);
                return;
            }

            $productIds = array_map('intval', array_filter(explode(',', $ids)));
            if (empty($productIds)) {
                ApiResponse::validationError(['ids' => 'قائمة المعرفات غير صالحة']);
                return;
            }

            $images = [];
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));

            $rows = fetchAll(
                "SELECT product_id, file_path, is_primary
                 FROM product_images
                 WHERE product_id IN ({$placeholders})
                   AND deleted_at IS NULL
                 ORDER BY is_primary DESC, sort_order ASC",
                $productIds
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $pid = intval($row['product_id']);
                    if (!isset($images[$pid])) {
                        $images[$pid] = $row['file_path'];
                    }
                }
            }

            ApiResponse::success($images);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePosController::productImages');
            ApiResponse::serverError('حدث خطأ أثناء جلب صور المنتجات');
        }
    }

    /**
     * معالجة عملية البيع في نقطة البيع
     *
     * الجسم (JSON):
     *   items: [{ product_id, quantity, selling_price }] — قائمة العناصر (مطلوب)
     *   sale_date    — تاريخ البيع (اختياري)
     *   notes        — ملاحظات (اختياري)
     *   discount     — خصم (اختياري)
     *   paid_amount  — المبلغ المدفوع (اختياري)
     *   source       — المصدر (افتراضي: pos_mobile)
     *
     * الاستجابة:
     *   201 { success, data: { sale_id, sale_ids, summary, receipt }, message }
     */
    public function checkout(array $params = []): void
    {
        $this->requirePost();

        try {
            $data = $this->all();

            if (empty($data['items'])) {
                ApiResponse::validationError(['items' => 'قائمة عناصر البيع مطلوبة']);
                return;
            }

            // حساب الضرائب إذا كانت مفعلة
            $taxEnabled = function_exists('getSetting') ? (getSetting('tax_enabled', '0') === '1') : false;
            $taxRate = floatval(function_exists('getSetting') ? getSetting('tax_rate', '0') : 0);
            $taxAmount = 0;

            // تفويض إلى SaleService
            $saleData = [
                'items'            => $data['items'],
                'sale_date'        => $data['sale_date'] ?? date('Y-m-d'),
                'notes'            => trim($data['notes'] ?? ''),
                'discount'         => floatval($data['discount'] ?? 0),
                'paid_amount'      => floatval($data['paid_amount'] ?? 0),
                'source'           => 'pos_mobile',
                'send_notification' => true,
            ];

            $result = SaleService::create($saleData);

            // إضافة معلومات الإيصال
            $storeName = function_exists('getSetting') ? (getSetting('store_name', '') ?? '') : '';
            $storePhone = function_exists('getSetting') ? (getSetting('store_phone', '') ?? '') : '';
            $storeAddress = function_exists('getSetting') ? (getSetting('store_address', '') ?? '') : '';
            $currency = function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.ج';

            // حساب الباقي
            $totalAmount = floatval($result['data']['total'] ?? 0);
            $discount = floatval($data['discount'] ?? 0);
            $paidAmount = floatval($data['paid_amount'] ?? 0);
            $change = max(0, $paidAmount - ($totalAmount - $discount));

            $result['receipt'] = [
                'store_name' => $storeName,
                'store_phone' => $storePhone,
                'store_address' => $storeAddress,
                'currency' => $currency,
                'date' => date('Y-m-d H:i:s'),
                'total' => $totalAmount,
                'discount' => $discount,
                'paid' => $paidAmount,
                'change' => round($change, 2),
                'tax_enabled' => $taxEnabled,
                'tax_rate' => $taxRate,
            ];

            ApiResponse::created($result['data'] ?? $result, $result['message'] ?? 'تم تسجيل البيع بنجاح');

        } catch (AppException $e) {
            if ($e->getErrorType() === 'validation') {
                ApiResponse::validationError([$e->getMessage()], $e->getMessage());
            }
            ApiResponse::error($e->getMessage(), $e->getErrorType(), $e->getHttpStatusCode());
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePosController::checkout');
            ApiResponse::serverError('حدث خطأ أثناء معالجة عملية البيع');
        }
    }

    /**
     * إعدادات نقطة البيع
     *
     * الاستجابة:
     *   { success, data: { pos_settings, store_info, tax_info, printer_config } }
     */
    public function settings(array $params = []): void
    {
        try {
            $getSetting = function_exists('getSetting') ? 'getSetting' : function ($key, $default = '') { return $default; };

            $posSettings = [
                'theme' => $getSetting('pos_theme', 'light'),
                'sound_enabled' => $getSetting('pos_sound_enabled', '1') === '1',
                'auto_complete' => $getSetting('pos_auto_complete', '1') === '1',
                'auto_focus_search' => $getSetting('pos_auto_focus_search', '1') === '1',
            ];

            $storeInfo = [
                'name' => $getSetting('store_name', ''),
                'phone' => $getSetting('store_phone', ''),
                'address' => $getSetting('store_address', ''),
            ];

            $taxInfo = [
                'enabled' => $getSetting('tax_enabled', '0') === '1',
                'rate' => floatval($getSetting('tax_rate', '0')),
                'type' => $getSetting('tax_type', 'percentage'),
                'name' => $getSetting('tax_name', 'ضريبة'),
            ];

            $printerConfig = [
                'enabled' => $getSetting('thermal_printer_enabled', '0') === '1',
                'type' => $getSetting('thermal_printer_type', 'browser'),
                'ip' => $getSetting('thermal_printer_ip', ''),
                'port' => intval($getSetting('thermal_printer_port', '9100')),
                'width' => intval($getSetting('thermal_printer_width', '58')),
                'auto_print' => $getSetting('thermal_auto_print', '0') === '1',
                'auto_cut' => $getSetting('thermal_auto_cut', '0') === '1',
                'cash_drawer' => $getSetting('thermal_cash_drawer', '0') === '1',
                'receipt_footer' => $getSetting('thermal_receipt_footer', ''),
            ];

            $currency = function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.ج';

            ApiResponse::success([
                'pos_settings' => $posSettings,
                'store_info' => $storeInfo,
                'tax_info' => $taxInfo,
                'printer_config' => $printerConfig,
                'currency' => $currency,
                'currency_position' => $getSetting('currency_position', 'right'),
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePosController::settings');
            ApiResponse::serverError('حدث خطأ أثناء جلب إعدادات نقطة البيع');
        }
    }

    /**
     * التصنيفات مع عدد المنتجات (لتبويبات نقطة البيع)
     *
     * الاستجابة:
     *   { success, data: [...] }
     */
    public function categories(array $params = []): void
    {
        try {
            $categories = fetchAll(
                "SELECT c.id, c.name, c.color,
                        (SELECT COUNT(*) FROM products WHERE category_id = c.id) as product_count
                 FROM categories c
                 ORDER BY c.sort_order ASC, c.name ASC"
            );

            if (!is_array($categories)) {
                $categories = [];
            }

            // إضافة تصنيف "الكل" في البداية
            $totalCount = fetchOne("SELECT COUNT(*) as c FROM products");
            array_unshift($categories, [
                'id' => 0,
                'name' => 'الكل',
                'color' => '#4a90d9',
                'product_count' => intval($totalCount['c'] ?? 0),
            ]);

            ApiResponse::success($categories);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobilePosController::categories');
            ApiResponse::serverError('حدث خطأ أثناء جلب التصنيفات');
        }
    }

    /**
     * بحث باستخدام SearchService
     */
    private function searchWithService(string $query, int $categoryId, int $limit): array
    {
        try {
            $result = ProductService::search([
                'search' => $query,
                'category_id' => $categoryId > 0 ? $categoryId : null,
            ], 1, $limit);

            $products = $result['products'] ?? $result['data'] ?? [];

            // تنسيق للنقطة
            return array_map(function ($p) {
                return [
                    'id' => intval($p['id']),
                    'name' => $p['name'] ?? '',
                    'code' => $p['code'] ?? '',
                    'selling_price' => floatval($p['selling_price'] ?? 0),
                    'purchase_price' => floatval($p['purchase_price'] ?? 0),
                    'quantity' => floatval($p['quantity'] ?? 0),
                    'unit' => $p['unit'] ?? '',
                    'category_id' => intval($p['category_id'] ?? 0),
                    'category_name' => $p['category_name'] ?? '',
                    'in_stock' => floatval($p['quantity'] ?? 0) > 0,
                ];
            }, is_array($products) ? $products : []);
        } catch (\Throwable $e) {
            return $this->searchDirect($query, $categoryId, $limit);
        }
    }

    /**
     * بحث مباشر في قاعدة البيانات
     */
    private function searchDirect(string $query, int $categoryId, int $limit): array
    {
        $sql = "SELECT p.id, p.name, p.code, p.selling_price, p.purchase_price,
                       p.quantity, p.unit, p.category_id, c.name as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE 1=1";
        $bindings = [];

        if (!empty($query)) {
            $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)";
            $bindings[] = "%{$query}%";
            $bindings[] = "%{$query}%";
            $bindings[] = "%{$query}%";
        }

        if ($categoryId > 0) {
            $sql .= " AND p.category_id = ?";
            $bindings[] = $categoryId;
        }

        $sql .= " ORDER BY p.name ASC LIMIT ?";
        $bindings[] = $limit;

        $products = fetchAll($sql, $bindings);
        if (!is_array($products)) $products = [];

        return array_map(function ($p) {
            $p['in_stock'] = floatval($p['quantity'] ?? 0) > 0;
            $p['selling_price'] = floatval($p['selling_price'] ?? 0);
            $p['purchase_price'] = floatval($p['purchase_price'] ?? 0);
            $p['quantity'] = floatval($p['quantity'] ?? 0);
            $p['id'] = intval($p['id']);
            $p['category_id'] = intval($p['category_id'] ?? 0);
            return $p;
        }, $products);
    }
}
