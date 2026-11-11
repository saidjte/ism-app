<?php
/**
 * ========================================================================
 *  API بيانات التحقق من الأسعار
 *  يُرجع بيانات المنتجات بصيغة JSON للتخزين المؤقت في PWA
 *  يعمل بدون مصادقة — بيانات عامة (أسماء + أسعار بيع + باركود فقط)
 * ========================================================================
 *  v4.15.11 — إصلاح حرج: كشف ديناميكي للأعمدة + إضافة حقل الباركود
 *  - إذا عمود غير موجود (مثل barcode أو min_quantity) لا يفشل الاستعلام
 *  - إضافة حقل barcode لتفعيل البحث بمسح الباركود في صفحة أسعاري
 *  v6.3.2 (أمني — المرحلة 0):
 *  - حذف سعر الشراء (purchase_price) من الاستجابة نهائياً — كشف تكلفة
 *    الشراء للعالم عبر نقطة عامة كان تسريباً تجارياً خطيراً
 *  - حذف الكمية الدقيقة (quantity) وحد التنبيه (min_quantity) واستبدالهما
 *    بحالة مخزون مبسطة (stock: ok|low|out) تحفظ تجربة المستخدم دون كشف الأرقام
 */

// تحميل الإعدادات مباشرة (بدون bootstrap الكامل — لسرعة الاستجابة)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ErrorHandler.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = getDBConnection();

    // === كشف الأعمدة الموجودة تلقائياً ===
    // مثل api/search_products.php — يمنع فشل الاستعلام إذا عمود غير موجود
    $existingCols = [];
    try {
        $colRows = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colRows as $cr) {
            $existingCols[] = strtolower($cr['Field']);
        }
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'price-check-data::columnDetection');
        // إذا فشل كشف الأعمدة، نستخدم القائمة الافتراضية
        $existingCols = ['id', 'code', 'name', 'selling_price', 'unit', 'quantity'];
    }

    $hasBarcode      = in_array('barcode', $existingCols);
    $hasMinQuantity   = in_array('min_quantity', $existingCols);
    $hasSellingPrice  = in_array('selling_price', $existingCols);
    // v6.3.2: purchase_price لم يُرسل بعد الآن — احذف الاعتماد عليه
    $hasUnit          = in_array('unit', $existingCols);
    $hasPrimaryImage  = in_array('primary_image', $existingCols);
    $hasCategory      = false;
    try {
        $pdo->query("SELECT 1 FROM categories LIMIT 1");
        $hasCategory = true;
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'price-check-data::categoryTableCheck');
    }

    // === بناء أعمدة SELECT ديناميكياً ===
    // ملاحظة أمنية v6.3.2: quantity وmin_quantity يُجلبان لاحتساب حالة
    // المخزون داخلياً فقط ثم يُحذفان من الاستجابة — لا يُرسلان للعميل أبداً
    $selectCols = ['p.id', 'p.code', 'p.name'];
    if ($hasBarcode)      $selectCols[] = 'p.barcode';
    if ($hasSellingPrice)  $selectCols[] = 'p.selling_price';
    if ($hasUnit)          $selectCols[] = 'p.unit';
    $selectCols[] = 'p.quantity';
    if ($hasMinQuantity)   $selectCols[] = 'p.min_quantity';
    if ($hasPrimaryImage)  $selectCols[] = 'p.primary_image';

    $catJoin = '';
    $catSelect = '';
    if ($hasCategory) {
        $catJoin = ' LEFT JOIN categories c ON p.category_id = c.id';
        $catSelect = ", COALESCE(c.name, '') as category_name";
    }

    $cols = implode(', ', $selectCols) . $catSelect;

    // جلب آخر وقت تحديث
    $lastModified = null;
    try {
        $stmt = $pdo->query("SELECT MAX(updated_at) as last_mod FROM products");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastModified = $row['last_mod'] ?? null;
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'price-check-data::lastModifiedCheck');
    }

    // التحقق من رأس If-None-Match (ETag)
    $etag = '"' . md5($lastModified ?? date('Y-m-d')) . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        http_response_code(304);
        exit;
    }
    header('ETag: ' . $etag);

    // جلب بيانات المنتجات — فقط الحقول الموجودة فعلاً
    $stmt = $pdo->query("SELECT {$cols} FROM products p{$catJoin} ORDER BY p.name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // جلب صور المنتجات (الصورة الرئيسية من جدول product_images)
    $primaryImages = [];
    try {
        $stmt = $pdo->query("
            SELECT product_id, thumbnail_path, medium_path
            FROM product_images
            WHERE is_primary = 1 AND deleted_at IS NULL
            ORDER BY product_id
        ");
        $imgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($imgRows as $img) {
            $pid = $img['product_id'];
            // تحويل المسار المطلق إلى مسار ويب نسبي
            $webThumb = '';
            $webMedium = '';
            if (!empty($img['thumbnail_path'])) {
                $webThumb = preg_replace('#^.*/uploads/#', 'uploads/', $img['thumbnail_path']);
                $webThumb = str_replace('\\', '/', $webThumb);
            }
            if (!empty($img['medium_path'])) {
                $webMedium = preg_replace('#^.*/uploads/#', 'uploads/', $img['medium_path']);
                $webMedium = str_replace('\\', '/', $webMedium);
            }
            $primaryImages[$pid] = [
                'thumb' => $webThumb,
                'medium' => $webMedium,
            ];
        }
    } catch (\Throwable $e) {
        // جدول product_images قد لا يكون موجوداً بعد
        ErrorHandler::logException($e, 'price-check-data::productImages');
    }

    // جلب وحدات إضافية للمنتجات
    $units = [];
    try {
        $stmt = $pdo->query("
            SELECT
                au.product_id,
                au.unit_name,
                au.conversion_factor,
                au.selling_price as unit_selling_price
            FROM additional_units au
            ORDER BY au.product_id, au.id
        ");
        $rawUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawUnits as $u) {
            $pid = $u['product_id'];
            if (!isset($units[$pid])) {
                $units[$pid] = [];
            }
            $units[$pid][] = [
                'name' => $u['unit_name'],
                'factor' => floatval($u['conversion_factor']),
                'price' => floatval($u['unit_selling_price']),
            ];
        }
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'price-check-data::additionalUnits');
    }

    // دمج الوحدات الإضافية + الصور مع المنتجات + تحويل الأرقام
    foreach ($products as &$product) {
        $pid = $product['id'];
        $product['additional_units'] = isset($units[$pid]) ? $units[$pid] : [];
        // إضافة رابط الصورة الرئيسية
        if (isset($primaryImages[$pid]) && !empty($primaryImages[$pid]['thumb'])) {
            $product['image'] = $primaryImages[$pid]['thumb'];
            $product['image_medium'] = $primaryImages[$pid]['medium'];
        } elseif ($hasPrimaryImage && !empty($product['primary_image'])) {
            $product['image'] = $product['primary_image'];
        } else {
            $product['image'] = '';
            $product['image_medium'] = '';
        }
        // تحويل الأرقام — مع فحص وجود الحقل
        if (isset($product['selling_price']))  $product['selling_price']  = floatval($product['selling_price']);
        
        // v6.3.2 (أمني): احتساب حالة المخزون داخلياً ثم حذف الأرقام الفعلية
        // كانت الاستجابة تكشف: سعر الشراء + الكمية الدقيقة + حد التنبيه للعالم
        $pQty  = isset($product['quantity'])    ? floatval($product['quantity'])    : 0;
        $pMin  = isset($product['min_quantity']) ? floatval($product['min_quantity']) : 0;
        if ($pQty <= 0) {
            $product['stock'] = 'out';
        } elseif ($pMin > 0 && $pQty <= $pMin) {
            $product['stock'] = 'low';
        } else {
            $product['stock'] = 'ok';
        }
        unset(
            $product['quantity'],
            $product['min_quantity'],
            $product['purchase_price']
        );
        // barcode يبقى كنص (قد يحتوي أصفار بادئة)
        if (!isset($product['barcode'])) $product['barcode'] = '';
        if (!$hasCategory) $product['category_name'] = '';
    }
    unset($product);

    // جلب إعدادات العملة
    $currency = getSetting('currency_symbol', 'د.م.');
    $appName = getSetting('app_name', 'IS-APP');

    echo json_encode([
        'success' => true,
        'timestamp' => date('c'),
        'last_modified' => $lastModified,
        'currency' => $currency,
        'app_name' => $appName,
        'count' => count($products),
        'products' => $products,
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    // تسجيل الخطأ في السجلات
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/api-errors.log', sprintf("[%s] [PRICE-CHECK-DATA] %s in %s:%d\n", date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine()), FILE_APPEND);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'فشل في جلب البيانات',
        'message' => getenv('APP_DEBUG') === 'true' ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
