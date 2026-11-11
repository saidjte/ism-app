<?php
/**
 * ===== متحكم بطاقات المنتجات للهاتف (Mobile Label Controller) =====
 * يوفر واجهة API لبيانات بطاقات المنتجات (باليطاج) من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET  /api/mobile/labels/data          — بيانات بطاقات المنتجات
 *   POST /api/mobile/labels/generate      — توليد بيانات الطباعة
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف طباعة بطاقات المنتجات
 */

require_once __DIR__ . '/../ApiController.php';

class MobileLabelController extends ApiController
{
    /**
     * تخطيطات البطاقات المتاحة
     */
    private const LAYOUTS = [
        'extra_large' => ['cols' => 2, 'rows' => 4, 'label' => 'كبيرة جداً'],
        'wide'        => ['cols' => 1, 'rows' => 5, 'label' => 'عريضة'],
        'large'       => ['cols' => 2, 'rows' => 5, 'label' => 'كبيرة'],
        'medium'      => ['cols' => 3, 'rows' => 5, 'label' => 'متوسطة'],
        'small'       => ['cols' => 3, 'rows' => 6, 'label' => 'صغيرة'],
        'tiny'        => ['cols' => 3, 'rows' => 7, 'label' => 'صغيرة جداً'],
    ];

    /**
     * بيانات بطاقات المنتجات
     *
     * معاملات GET:
     *   product_ids — قائمة معرفات المنتجات مفصولة بفواصل (اختياري)
     *   category_id — معرف التصنيف (اختياري)
     *   search      — بحث بالاسم أو الكود (اختياري)
     *
     * الاستجابة:
     *   { success, data: { products, layouts, settings } }
     */
    public function data(array $params = []): void
    {
        try {
            $productIds = $this->input('product_ids', '');
            $categoryId = intval($this->input('category_id', 0));
            $search = trim($this->input('search', ''));

            $products = [];

            // جلب منتجات محددة بالمعرفات
            if (!empty($productIds)) {
                $ids = array_map('intval', array_filter(explode(',', $productIds)));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $products = fetchAll(
                        "SELECT p.id, p.name, p.code, p.selling_price, p.purchase_price,
                                p.unit, p.category_id, c.name as category_name, c.color as category_color
                         FROM products p
                         LEFT JOIN categories c ON p.category_id = c.id
                         WHERE p.id IN ({$placeholders})
                         ORDER BY p.name ASC",
                        $ids
                    );
                }
            }
            // جلب منتجات حسب التصنيف
            elseif ($categoryId > 0) {
                $products = fetchAll(
                    "SELECT p.id, p.name, p.code, p.selling_price, p.purchase_price,
                            p.unit, p.category_id, c.name as category_name, c.color as category_color
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.category_id = ?
                     ORDER BY p.name ASC",
                    [$categoryId]
                );
            }
            // بحث
            elseif (!empty($search)) {
                $products = fetchAll(
                    "SELECT p.id, p.name, p.code, p.selling_price, p.purchase_price,
                            p.unit, p.category_id, c.name as category_name, c.color as category_color
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.name LIKE ? OR p.code LIKE ?
                     ORDER BY p.name ASC
                     LIMIT 50",
                    ["%{$search}%", "%{$search}%"]
                );
            }

            if (!is_array($products)) {
                $products = [];
            }

            // جلب الوحدات الإضافية
            foreach ($products as &$product) {
                try {
                    $additionalUnits = fetchAll(
                        "SELECT unit_name, selling_price, conversion_factor
                         FROM additional_units WHERE product_id = ?",
                        [intval($product['id'])]
                    );
                    $product['additional_units'] = is_array($additionalUnits) ? $additionalUnits : [];
                } catch (\Throwable $e) {
                    $product['additional_units'] = [];
                }
            }
            unset($product);

            // جلب إعدادات المتجر
            $storeName = function_exists('getSetting') ? (getSetting('store_name', '') ?? '') : '';
            $storePhone = function_exists('getSetting') ? (getSetting('store_phone', '') ?? '') : '';
            $storeAddress = function_exists('getSetting') ? (getSetting('store_address', '') ?? '') : '';
            $currency = function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.ج';

            // جلب التصنيفات
            $categories = [];
            try {
                $categories = fetchAll("SELECT id, name, color FROM categories ORDER BY name ASC");
                if (!is_array($categories)) {
                    $categories = [];
                }
            } catch (\Throwable $e) {
                $categories = [];
            }

            ApiResponse::success([
                'products' => $products,
                'categories' => $categories,
                'layouts' => self::LAYOUTS,
                'settings' => [
                    'store_name' => $storeName,
                    'store_phone' => $storePhone,
                    'store_address' => $storeAddress,
                    'currency' => $currency,
                ],
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileLabelController::data');
            ApiResponse::serverError('حدث خطأ أثناء جلب بيانات البطاقات');
        }
    }

    /**
     * توليد بيانات الطباعة للبطاقات
     *
     * الجسم (JSON):
     *   product_ids      — قائمة معرفات المنتجات (مطلوب)
     *   quantities       — كميات كل منتج (اختياري، افتراضي: 1 لكل منتج)
     *   label_layout     — نوع التخطيط (اختياري، افتراضي: medium)
     *   show_purchase    — عرض سعر الشراء (اختياري)
     *   show_code        — عرض كود المنتج (اختياري)
     *   show_barcode     — عرض الباركود (اختياري)
     *   show_category    — عرض التصنيف (اختياري)
     *   show_date        — عرض التاريخ (اختياري)
     *   store_name       — اسم المتجر (اختياري)
     *   code_type        — نوع الكود: barcode|qrcode|none (افتراضي: barcode)
     *   barcode_format   — صيغة الباركود: CODE128|EAN13|CODE39|UPC (افتراضي: CODE128)
     *   price_display    — عرض السعر: basic|additional|multiple (افتراضي: basic)
     *
     * الاستجابة:
     *   { success, data: { labels, layout, settings } }
     */
    public function generate(array $params = []): void
    {
        try {
            $productIds = $this->input('product_ids', []);
            $quantities = $this->input('quantities', []);
            $labelLayout = $this->input('label_layout', 'medium');
            $showPurchase = filter_var($this->input('show_purchase', false), FILTER_VALIDATE_BOOL);
            $showCode = filter_var($this->input('show_code', true), FILTER_VALIDATE_BOOL);
            $showBarcode = filter_var($this->input('show_barcode', true), FILTER_VALIDATE_BOOL);
            $showCategory = filter_var($this->input('show_category', false), FILTER_VALIDATE_BOOL);
            $showDate = filter_var($this->input('show_date', false), FILTER_VALIDATE_BOOL);
            $codeType = $this->input('code_type', 'barcode');
            $barcodeFormat = $this->input('barcode_format', 'CODE128');
            $priceDisplay = $this->input('price_display', 'basic');

            if (empty($productIds) || !is_array($productIds)) {
                ApiResponse::validationError(['product_ids' => 'قائمة المنتجات مطلوبة']);
                return;
            }

            // التحقق من التخطيط
            if (!isset(self::LAYOUTS[$labelLayout])) {
                $labelLayout = 'medium';
            }

            $layout = self::LAYOUTS[$labelLayout];

            // بناء قائمة البطاقات
            $labels = [];
            $merged = []; // دمج المنتجات المكررة

            foreach ($productIds as $idx => $pid) {
                $pid = intval($pid);
                if ($pid <= 0) continue;

                $qty = isset($quantities[$idx]) ? intval($quantities[$idx]) : 1;
                if ($qty < 1) continue;

                if (isset($merged[$pid])) {
                    $merged[$pid] += $qty;
                } else {
                    $merged[$pid] = $qty;
                }
            }

            // جلب بيانات المنتجات
            if (!empty($merged)) {
                $ids = array_keys($merged);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $products = fetchAll(
                    "SELECT p.id, p.name, p.code, p.selling_price, p.purchase_price,
                            p.unit, p.category_id, c.name as category_name, c.color as category_color
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.id IN ({$placeholders})",
                    $ids
                );

                if (is_array($products)) {
                    foreach ($products as $product) {
                        $pid = intval($product['id']);
                        $qty = $merged[$pid] ?? 1;

                        // جلب الوحدات الإضافية
                        $additionalUnits = [];
                        try {
                            $additionalUnits = fetchAll(
                                "SELECT unit_name, selling_price, conversion_factor
                                 FROM additional_units WHERE product_id = ?",
                                [$pid]
                            );
                            if (!is_array($additionalUnits)) {
                                $additionalUnits = [];
                            }
                        } catch (\Throwable $e) {}

                        for ($i = 0; $i < $qty; $i++) {
                            $labels[] = [
                                'product_id' => $pid,
                                'name' => $product['name'],
                                'code' => $product['code'],
                                'selling_price' => floatval($product['selling_price']),
                                'purchase_price' => floatval($product['purchase_price']),
                                'unit' => $product['unit'],
                                'category_name' => $product['category_name'] ?? '',
                                'category_color' => $product['category_color'] ?? '',
                                'additional_units' => $additionalUnits,
                                'code_type' => $codeType,
                                'barcode_format' => $barcodeFormat,
                            ];
                        }
                    }
                }
            }

            // إعدادات الطباعة
            $storeName = $this->input('store_name', '');
            if (empty($storeName)) {
                $storeName = function_exists('getSetting') ? (getSetting('store_name', '') ?? '') : '';
            }
            $currency = function_exists('getCurrencySymbol') ? getCurrencySymbol() : 'د.ج';

            ApiResponse::success([
                'labels' => $labels,
                'total_labels' => count($labels),
                'layout' => [
                    'key' => $labelLayout,
                    'cols' => $layout['cols'],
                    'rows' => $layout['rows'],
                    'label' => $layout['label'],
                ],
                'options' => [
                    'show_purchase' => $showPurchase,
                    'show_code' => $showCode,
                    'show_barcode' => $showBarcode,
                    'show_category' => $showCategory,
                    'show_date' => $showDate,
                    'code_type' => $codeType,
                    'barcode_format' => $barcodeFormat,
                    'price_display' => $priceDisplay,
                ],
                'settings' => [
                    'store_name' => $storeName,
                    'currency' => $currency,
                    'date' => date('Y-m-d'),
                ],
            ], 'تم توليد بيانات البطاقات بنجاح');

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileLabelController::generate');
            ApiResponse::serverError('حدث خطأ أثناء توليد بيانات البطاقات');
        }
    }
}
