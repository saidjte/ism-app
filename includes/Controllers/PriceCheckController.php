<?php
/**
 * ===== متحكم بيانات التحقق من الأسعار API =====
 * يُرجع بيانات المنتجات بصيغة JSON للتخزين المؤقت في PWA
 * يعمل بدون مصادقة — بيانات عامة (أسماء + أسعار + باركود فقط)
 */

require_once __DIR__ . '/ApiController.php';

class PriceCheckController extends ApiController
{
    /**
     * جلب بيانات المنتجات للتخزين المؤقت في PWA
     * GET /api/price-check-data
     * عام — بدون مصادقة
     */
    public function getData(array $params): void
    {
        try {
            $pdo = getDBConnection();

            // جلب آخر وقت تحديث
            $lastModified = null;
            try {
                $stmt = $pdo->query("SELECT MAX(updated_at) as last_mod FROM products");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $lastModified = $row['last_mod'] ?? null;
            } catch (\Throwable $e) {
                appLog('api.pricelist', 'عمود updated_at غير موجود', $e, 'info');
            }

            // التحقق من ETag
            $etag = '"' . md5($lastModified ?? date('Y-m-d')) . '"';
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
                http_response_code(304);
                exit;
            }
            header('ETag: ' . $etag);

            // جلب بيانات المنتجات
            $stmt = $pdo->query("
                SELECT
                    p.id, p.code, p.name, p.selling_price, p.purchase_price,
                    p.unit, p.quantity, p.min_quantity,
                    COALESCE(c.name, '') as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                ORDER BY p.name ASC
            ");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // جلب وحدات إضافية
            $units = [];
            try {
                $stmt = $pdo->query("
                    SELECT au.product_id, au.unit_name, au.conversion_factor, au.selling_price as unit_selling_price
                    FROM additional_units au ORDER BY au.product_id, au.id
                ");
                $rawUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawUnits as $u) {
                    $pid = $u['product_id'];
                    if (!isset($units[$pid])) $units[$pid] = [];
                    $units[$pid][] = [
                        'name' => $u['unit_name'],
                        'factor' => floatval($u['conversion_factor']),
                        'price' => floatval($u['unit_selling_price']),
                    ];
                }
            } catch (\Throwable $e) {
                appLog('api.pricelist', 'جدول الوحدات الإضافية غير متاح', $e, 'info');
            }

            // دمج الوحدات الإضافية مع المنتجات
            foreach ($products as &$product) {
                $pid = $product['id'];
                $product['additional_units'] = isset($units[$pid]) ? $units[$pid] : [];
                $product['selling_price'] = floatval($product['selling_price']);
                $product['purchase_price'] = floatval($product['purchase_price']);
                $product['quantity'] = floatval($product['quantity']);
                $product['min_quantity'] = floatval($product['min_quantity']);
            }
            unset($product);

            $currency = getSetting('currency_symbol', 'د.م.');
            $appName = getSetting('app_name', 'IS-APP');

            ApiResponse::success([
                'timestamp'     => date('c'),
                'last_modified' => $lastModified,
                'currency'      => $currency,
                'app_name'      => $appName,
                'count'         => count($products),
                'products'      => $products,
            ]);

        } catch (\Throwable $e) {
            appLog('api.pricelist', 'فشل في جلب بيانات الأسعار', $e, 'error');
            ApiResponse::serverError('فشل في جلب البيانات');
        }
    }
}
