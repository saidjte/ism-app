<?php
/**
 * ===== متحكم منتجات API =====
 * بحث المنتجات، وحدات القياس، دفعات الشراء
 */

require_once __DIR__ . '/ApiController.php';

class ProductApiController extends ApiController
{
    /**
     * بحث المنتجات
     * GET /api/search-products?q=xxx&category=1&page=1&per_page=20
     */
    public function search(array $params): void
    {
        $query = trim($_GET['q'] ?? $_GET['query'] ?? '');
        $filterCategory = intval($_GET['category'] ?? 0);
        $pagination = $this->getPaginationParams(20);

        try {
            // كشف الأعمدة الموجودة تلقائياً
            $existingCols = [];
            try {
                $colRows = fetchAll("SHOW COLUMNS FROM products");
                foreach ($colRows as $cr) {
                    $existingCols[] = strtolower($cr['Field']);
                }
            } catch (Exception $e) {
                appLog('api.search', 'فشل كشف أعمدة جدول المنتجات', $e, 'info');
            }

            $hasBarcode = in_array('barcode', $existingCols);
            $hasExpiry = in_array('has_expiry', $existingCols);
            $hasMinQty = in_array('min_quantity', $existingCols);
            $hasSelling = in_array('selling_price', $existingCols);
            $hasPurchase = in_array('purchase_price', $existingCols);

            // كشف جدول التصنيفات
            $hasCat = false;
            try { fetchOne("SELECT 1 FROM categories LIMIT 1"); $hasCat = true; } catch (Exception $e) {}

            // بناء أعمدة SELECT ديناميكياً
            $selectCols = ["p.id", "p.code", "p.name", "p.unit", "p.quantity"];
            if ($hasBarcode) $selectCols[] = "p.barcode";
            if ($hasSelling) $selectCols[] = "p.selling_price";
            if ($hasPurchase) $selectCols[] = "p.purchase_price";
            if ($hasMinQty) $selectCols[] = "p.min_quantity";
            if ($hasExpiry) $selectCols[] = "p.has_expiry";

            $catJoin = "";
            $catSelect = "";
            if ($hasCat) {
                $catJoin = " LEFT JOIN categories c ON p.category_id = c.id";
                $catSelect = ", c.name as category_name, c.color as category_color";
            }

            $cols = implode(", ", $selectCols) . $catSelect;
            $from = "FROM products p{$catJoin}";
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];
            $lim = "LIMIT {$perPage} OFFSET {$offset}";

            if (empty($query)) {
                $where = "";
                $sqlParams = [];
                if ($filterCategory > 0 && $hasCat) {
                    $where = " WHERE p.category_id = ?";
                    $sqlParams = [$filterCategory];
                }
                $products = fetchAll("SELECT {$cols} {$from} {$where} ORDER BY p.name ASC {$lim}", $sqlParams);
                $row = fetchOne("SELECT COUNT(*) as cnt {$from} {$where}", $sqlParams);
                $total = intval($row['cnt'] ?? 0);
            } else {
                $like = '%' . $query . '%';
                $start = $query . '%';

                $where = " WHERE (p.code LIKE ? OR p.name LIKE ?";
                $sqlParams = [$like, $like];
                if ($hasBarcode) {
                    $where .= " OR p.barcode LIKE ?";
                    $sqlParams[] = $like;
                }
                $where .= ")";

                if ($filterCategory > 0 && $hasCat) {
                    $where .= " AND p.category_id = ?";
                    $sqlParams[] = $filterCategory;
                }

                $row = fetchOne("SELECT COUNT(*) as cnt {$from} {$where}", $sqlParams);
                $total = intval($row['cnt'] ?? 0);

                $orderParams = [$start, $start];
                if ($hasBarcode) {
                    $orderParams[] = $start;
                    $order = " ORDER BY CASE WHEN p.code LIKE ? THEN 0 WHEN p.name LIKE ? THEN 1 WHEN p.barcode LIKE ? THEN 2 ELSE 3 END, p.name ASC";
                } else {
                    $order = " ORDER BY CASE WHEN p.code LIKE ? THEN 0 WHEN p.name LIKE ? THEN 1 ELSE 2 END, p.name ASC";
                }
                $allParams = array_merge($sqlParams, $orderParams);
                $products = fetchAll("SELECT {$cols} {$from} {$where} {$order} {$lim}", $allParams);
            }

            // حساب إجمالي الصفحات
            $totalPages = max(1, (int) ceil($total / $perPage));

            // إرجاع هيكل متوافق مع JavaScript القديم (pos.php)
            // يجب أن يكون products و data و pagination في المستوى الأعلى
            ApiResponse::custom([
                'success'    => true,
                'message'    => 'تم البحث بنجاح',
                'products'   => $products,
                'data'       => $products,
                'query'      => $query,
                'total'      => $total,
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $pagination['page'],
                    'total_pages'  => $totalPages,
                    'has_next'     => $pagination['page'] < $totalPages,
                    'has_prev'     => $pagination['page'] > 1,
                ],
            ], 200);

        } catch (\Throwable $e) {
            appLog('api.search', 'خطأ في بحث المنتجات', $e, 'error');
            ApiResponse::serverError('حدث خطأ أثناء البحث. يرجى المحاولة لاحقاً.');
        }
    }

    /**
     * جلب وحدات القياس الإضافية لمنتج
     * GET /api/get-units?product_id=1
     */
    public function getUnits(array $params): void
    {
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            ApiResponse::custom(['success' => true, 'units' => []]);
        }

        $units = [];
        try {
            $units = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$productId]);
        } catch (Exception $e) {
            appLog('api.units', 'جدول additional_units غير متاح', $e, 'info');
        }

        ApiResponse::custom(['success' => true, 'units' => $units]);
    }

    /**
     * جلب وحدات الشراء لمنتج
     * GET /api/get-purchase-units?product_id=1
     */
    public function getPurchaseUnits(array $params): void
    {
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            ApiResponse::custom(['success' => true, 'purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
        }

        $product = fetchOne("SELECT unit, purchase_unit, purchase_unit_factor FROM products WHERE id = ?", [$productId]);
        if (!$product) {
            ApiResponse::custom(['success' => true, 'purchase_unit' => '', 'purchase_unit_factor' => 1, 'units' => []]);
        }

        $purchase_unit = $product['purchase_unit'] ?: $product['unit'];
        $purchase_unit_factor = floatval($product['purchase_unit_factor'] ?? 1);
        if (empty($product['purchase_unit'])) {
            $purchase_unit_factor = 1;
        }

        $purchase_additional = [];
        try {
            $purchase_additional = fetchAll("SELECT * FROM purchase_additional_units WHERE product_id = ?", [$productId]);
        } catch (Exception $e) {
            appLog('api.units', 'جدول وحدات الشراء الإضافية غير متاح', $e, 'info');
        }

        ApiResponse::custom([
            'success'              => true,
            'purchase_unit'         => $purchase_unit,
            'purchase_unit_factor'  => $purchase_unit_factor,
            'selling_unit'          => $product['unit'],
            'units'                 => $purchase_additional,
        ]);
    }

    /**
     * جلب دفعات الشراء المتوفرة لمنتج (FIFO)
     * GET /api/get-batches?product_id=1
     */
    public function getBatches(array $params): void
    {
        $productId = intval($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            ApiResponse::error('معرف المنتج مطلوب', 'validation', 400);
        }

        try {
            $pdo = getDBConnection();
            $product = $pdo->prepare("SELECT purchase_price, has_expiry FROM products WHERE id = ?");
            $product->execute([$productId]);
            $product_data = $product->fetch(PDO::FETCH_ASSOC);
            $current_price = $product_data ? floatval($product_data['purchase_price']) : 0;

            ApiResponse::custom([
                'success'                => true,
                'batches'                => [],
                'total_remaining'        => 0,
                'weighted_price'         => $current_price,
                'current_purchase_price' => $current_price,
                'has_expiry'             => $product_data ? intval($product_data['has_expiry']) : 0,
                'fallback'               => true,
            ]);
        } catch (Exception $e) {
            appLog('api.batches', 'فشل جلب بيانات الدفعات والمنتج', $e, 'error');
            ApiResponse::serverError('حدث خطأ أثناء جلب البيانات. يرجى المحاولة لاحقاً.');
        }
    }
}
