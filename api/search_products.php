<?php
/**
 * API للبحث عن المنتجات - يُستخدم في صفحات البيع والشراء
 * متوافق مع كل الصفحات: يُرجع products + data + query + total
 *
 * @since v4.19.0 يستخدم SearchService المركزي (MATCH...AGAINST + FULLTEXT)
 * @since v4.19.1 إصلاح البحث بالباركود + كود المنتج (تجاوز deleted_at)
 */

// تشغيل output buffering لمنع أي تحذيرات/إشعارات PHP من إفساد JSON
ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-API-Version: ' . API_VERSION);

$query = trim($_GET['q'] ?? $_GET['query'] ?? '');
$filterCategory = intval($_GET['category'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(1, min(50, intval($_GET['per_page'] ?? 20)));

try {
    // === كشف الأعمدة الموجودة تلقائياً (مع كاش بنية الجدول) ===
    $existingCols = [];
    try {
        $existingCols = SchemaCache::remember('products_columns', function() {
            $colRows = fetchAll("SHOW COLUMNS FROM products");
            $cols = [];
            foreach ($colRows as $cr) {
                $cols[] = strtolower($cr['Field']);
            }
            return $cols;
        });
    } catch (Exception $e) {
        ErrorHandler::logException($e, 'search_products::schemaColumnDetection');
    }

    $hasBarcode = in_array('barcode', $existingCols);
    $hasExpiry = in_array('has_expiry', $existingCols);
    $hasMinQty = in_array('min_quantity', $existingCols);
    $hasSelling = in_array('selling_price', $existingCols);
    $hasPurchase = in_array('purchase_price', $existingCols);
    $hasPrimaryImage = in_array('primary_image', $existingCols);
    $hasDeletedAt = in_array('deleted_at', $existingCols);

    // === كشف جدول التصنيفات (مع كاش بنية الجدول) ===
    $hasCat = false;
    try {
        $hasCat = SchemaCache::remember('categories_table_exists', function() {
            try {
                fetchOne("SELECT 1 FROM categories LIMIT 1");
                return true;
            } catch (Exception $e) {
                return false;
            }
        });
    } catch (Exception $e) {
        $hasCat = false;
    }

    // === بناء أعمدة SELECT ديناميكياً ===
    $selectCols = ["p.id", "p.code", "p.name", "p.unit", "p.quantity"];
    if ($hasBarcode) $selectCols[] = "p.barcode";
    if ($hasSelling) $selectCols[] = "p.selling_price";
    if ($hasPurchase) $selectCols[] = "p.purchase_price";
    if ($hasMinQty) $selectCols[] = "p.min_quantity";
    if ($hasExpiry) $selectCols[] = "p.has_expiry";
    if ($hasPrimaryImage) $selectCols[] = "p.primary_image";

    $catSelect = "";
    $catJoin = "";
    if ($hasCat) {
        $catSelect = ", c.name as category_name, c.color as category_color";
        $catJoin = " LEFT JOIN categories c ON p.category_id = c.id";
    }

    $cols = implode(", ", $selectCols) . $catSelect;

    // === شرط deleted_at (حسب وجود العمود) ===
    $deletedCondition = $hasDeletedAt ? " AND p.deleted_at IS NULL" : "";

    // === بناء الفلاتر ===
    $filters = [];
    if (!empty($query)) {
        $filters['search'] = $query;
    }
    if ($filterCategory > 0 && $hasCat) {
        $filters['category_id'] = $filterCategory;
    }

    // ============================================================
    //  بحث مباشر بالباركود أو كود المنتج (قبل FULLTEXT)
    //  لأن SearchService يبحث بالاسم فقط (MATCH...AGAINST)
    // ============================================================
    $directMatch = null;

    if (!empty($query)) {
        // === 1. بحث بالباركود (تطابق دقيق) ===
        if ($hasBarcode && $directMatch === null) {
            try {
                $directMatch = fetchAll(
                    "SELECT " . $cols . " FROM products p" . $catJoin . "
                     WHERE p.barcode = ?
                       " . $deletedCondition . "
                     LIMIT 1",
                    [$query]
                );
                if (empty($directMatch)) $directMatch = null;
            } catch (\Throwable $e) {
                $directMatch = null;
            }
        }

        // === 2. بحث بالباركود (LIKE جزئي) ===
        if ($hasBarcode && $directMatch === null) {
            try {
                $directMatch = fetchAll(
                    "SELECT " . $cols . " FROM products p" . $catJoin . "
                     WHERE p.barcode LIKE ?
                       " . $deletedCondition . "
                     LIMIT 5",
                    ['%' . $query . '%']
                );
                if (empty($directMatch)) $directMatch = null;
            } catch (\Throwable $e) {
                $directMatch = null;
            }
        }

        // === 3. بحث بكود المنتج (تطابق دقيق) ===
        if ($directMatch === null) {
            try {
                $directMatch = fetchAll(
                    "SELECT " . $cols . " FROM products p" . $catJoin . "
                     WHERE p.code = ?
                       " . $deletedCondition . "
                     LIMIT 1",
                    [$query]
                );
                if (empty($directMatch)) $directMatch = null;
            } catch (\Throwable $e) {
                $directMatch = null;
            }
        }

        // === 4. بحث بكود المنتج (LIKE جزئي) ===
        if ($directMatch === null) {
            try {
                $directMatch = fetchAll(
                    "SELECT " . $cols . " FROM products p" . $catJoin . "
                     WHERE p.code LIKE ?
                       " . $deletedCondition . "
                     LIMIT 5",
                    ['%' . $query . '%']
                );
                if (empty($directMatch)) $directMatch = null;
            } catch (\Throwable $e) {
                $directMatch = null;
            }
        }
    }

    // === استخدام SearchService المركزي (بحث بالاسم) ===
    $result = SearchService::searchProducts($filters, $page, $perPage, [
        'select_columns' => $cols,
        'include_barcode' => $hasBarcode,
    ]);

    $products = $result['products'];
    $total = $result['total'];

    // === دمج نتائج البحث المباشر مع نتائج FULLTEXT ===
    if (!empty($directMatch)) {
        $existingIds = array_map(function($p) { return intval($p['id']); }, $products);

        foreach ($directMatch as $match) {
            if (!in_array(intval($match['id']), $existingIds)) {
                // غير موجود في نتائج FULLTEXT — أضفه في البداية
                array_unshift($products, $match);
                $total++;
            }
        }

        // ضمان أن التطابق الدقيق الأول يكون في رأس القائمة
        if (!empty($products) && !empty($directMatch)) {
            $firstMatchId = intval($directMatch[0]['id']);
            $idx = array_search($firstMatchId, array_map(function($p) { return intval($p['id']); }, $products));
            if ($idx !== false && $idx > 0) {
                $item = array_splice($products, $idx, 1);
                array_unshift($products, $item[0]);
            }
        }
    }

    // تنظيف أي output سابق (تحذيرات PHP، إشعارات، إلخ) قبل إرسال JSON
    if (ob_get_level()) ob_clean();

    echo json_encode([
        'success'    => true,
        'message'    => 'تم البحث بنجاح',
        'api_version' => API_VERSION,
        'products'   => $products,
        'data'       => $products,
        'query'      => $query,
        'total'      => $total,
        'pagination' => [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => max(1, (int) ceil($total / $perPage)),
            'has_next'     => $page < max(1, (int) ceil($total / $perPage)),
            'has_prev'     => $page > 1,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    // تسجيل الخطأ التفصيلي في السجلات فقط — لا نكشفه للمستخدم
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/api-errors.log', sprintf("[%s] [SEARCH-PRODUCTS] %s in %s:%d\n", date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine()), FILE_APPEND);

    // تنظيف أي output سابق قبل إرسال JSON
    if (ob_get_level()) ob_clean();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء البحث. يرجى المحاولة لاحقاً.',
        'products' => [],
        'data'     => [],
        'total'    => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
