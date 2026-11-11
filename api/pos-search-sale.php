<?php
/**
 * API — البحث عن عملية بيع سابقة للمرتجع
 * v6.1.6 (W16): يُستخدم من POS لعرض تفاصيل عملية بيع قبل المرتجع
 * v6.1.9: إصلاح جراحي — فحص ديناميكي لكل الأعمدة بدون استثناء
 *
 * البارامترات (GET):
 *   - receipt_no: رقم الإيصال (YYYYMMDD-NNNN)
 *   - ids: قائمة معرّفات المبيعات مفصولة بفاصلة (مثال: 101,102,103)
 *   - date: تاريخ البيع (YYYY-MM-DD)
 */

// === ضمان إرجاع JSON دائماً حتى في حالة الخطأ ===
function searchJsonError($message, $code = 400) {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// تسجيل الأخطاء
function searchLogError($context, $message) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logEntry = sprintf("[%s] [POS-SEARCH] %s: %s\n", date('Y-m-d H:i:s'), $context, $message);
    @file_put_contents($logDir . '/pos-errors.log', $logEntry, FILE_APPEND);
}

// === التقاط مخرجات PHP غير المرغوبة ===
ob_start();

// بدء الجلسة
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

// تحميل bootstrap
try {
    require_once __DIR__ . '/../includes/bootstrap.php';
} catch (\Throwable $e) {
    searchLogError('Bootstrap', $e->getMessage());
    searchJsonError(t('common_error_loading'), 500);
}

// حماية: تحميل permissions.php إذا لم يُحمَّل عبر bootstrap.php
if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/../includes/permissions.php';
}

// فحص تسجيل الدخول
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
    searchJsonError(t('pos_session_expired'), 401);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// فحص الصلاحية
if (!hasPermission('sales.add')) {
    searchJsonError(t('pos_no_permission'), 403);
}

// قراءة البارامترات
$ids_raw = isset($_GET['ids']) ? trim($_GET['ids']) : '';
$date_raw = isset($_GET['date']) ? trim($_GET['date']) : '';
$receipt_no = isset($_GET['receipt_no']) ? trim($_GET['receipt_no']) : '';

if (empty($ids_raw) && empty($date_raw) && empty($receipt_no)) {
    searchJsonError('يرجى إدخال رقم الإيصال أو رقم العملية أو التاريخ');
}

try {
    $pdo = getDBConnection();

    // === فحص ديناميكي لكل الأعمدة — بدون استثناء ===
    $salesCols = [];
    $productCols = [];
    try {
        $sCols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
        $pCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);

        // أعمدة sales المطلوبة مع أسماء مستعارة
        $salesWanted = [
            'id', 'product_id', 'quantity', 'selling_price', 'total',
            'sale_date', 'discount', 'receipt_no', 'created_at',
        ];
        foreach ($salesWanted as $col) {
            if (in_array($col, $sCols, true)) $salesCols[] = 's.'.$col;
        }

        // أعمدة products المطلوبة مع أسماء مستعارة
        $productWanted = [
            'name'     => 'product_name',
            'code'     => 'product_code',
            'image'    => null,
            'unit'     => null,
        ];
        foreach ($productWanted as $col => $alias) {
            if (in_array($col, $pCols, true)) {
                $productCols[] = $alias ? "p.{$col} AS {$alias}" : "p.{$col}";
            }
        }
    } catch (\Throwable $e) {
        searchLogError('ColumnCheck', $e->getMessage());
    }

    // بناء SELECT ديناميكي بالكامل
    $selectParts = array_merge($salesCols, $productCols);
    if (empty($selectParts)) {
        searchJsonError('خطأ: تعذر تحديد أعمدة الجداول', 500);
    }
    $selectFull = "SELECT " . implode(', ', $selectParts);

    // أعلام وجود الأعمدة (للفلترة والتجميع)
    $hasDiscountCol  = in_array('s.discount',   $salesCols, true);
    $hasReceiptNoCol  = in_array('s.receipt_no', $salesCols, true);
    $hasCreatedAtCol  = in_array('s.created_at', $salesCols, true);

    // تنظيف أي output قبل JSON
    if (ob_get_level()) ob_clean();

    // === البحث برقم الإيصال (receipt_no) ===
    if (!empty($receipt_no)) {
        if (!$hasReceiptNoCol) {
            echo json_encode(['success' => false, 'message' => 'عمود رقم الإيصال غير متوفر. يرجى تحديث قاعدة البيانات.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare(
            "$selectFull
             FROM sales s
             LEFT JOIN products p ON p.id = s.product_id
             WHERE s.receipt_no = ?
             ORDER BY s.id ASC"
        );
        $stmt->execute([$receipt_no]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'لم يتم العثور على عملية بيع بهذا رقم الإيصال'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ids = array_column($items, 'id');
        $total = array_sum(array_column($items, 'total'));
        $total_discount = ($hasDiscountCol && isset($items[0]['discount'])) ? array_sum(array_column($items, 'discount')) : 0;
        $sale_date = $items[0]['sale_date'];
        $created_at = ($hasCreatedAtCol && isset($items[0]['created_at'])) ? $items[0]['created_at'] : '';

        echo json_encode([
            'success' => true,
            'data' => [
                'items' => $items,
                'sale_ids' => $ids,
                'sale_date' => $sale_date,
                'created_at' => $created_at,
                'receipt_no' => $receipt_no,
                'subtotal' => round($total + $total_discount, 2),
                'discount' => round($total_discount, 2),
                'total' => round($total, 2),
                'item_count' => count($items),
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($ids_raw)) {
        // البحث بمعرّفات المبيعات
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'معرّفات غير صالحة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "$selectFull
             FROM sales s
             LEFT JOIN products p ON p.id = s.product_id
             WHERE s.id IN ($placeholders)
             ORDER BY s.id ASC"
        );
        $stmt->execute($ids);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'لم يتم العثور على عملية بيع بهذه المعرّفات'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $total = array_sum(array_column($items, 'total'));
        $total_discount = ($hasDiscountCol && isset($items[0]['discount'])) ? array_sum(array_column($items, 'discount')) : 0;
        $sale_date = $items[0]['sale_date'];
        $created_at = ($hasCreatedAtCol && isset($items[0]['created_at'])) ? $items[0]['created_at'] : '';

        echo json_encode([
            'success' => true,
            'data' => [
                'items' => $items,
                'sale_ids' => $ids,
                'sale_date' => $sale_date,
                'created_at' => $created_at,
                'subtotal' => round($total + $total_discount, 2),
                'discount' => round($total_discount, 2),
                'total' => round($total, 2),
                'item_count' => count($items),
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } else {
        // البحث بالتاريخ
        if (!$hasCreatedAtCol) {
            echo json_encode(['success' => false, 'message' => 'عمود created_at غير متوفر. يرجى تحديث قاعدة البيانات.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare(
            "$selectFull
             FROM sales s
             LEFT JOIN products p ON p.id = s.product_id
             WHERE s.sale_date = ?
             ORDER BY s.created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$date_raw]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'لا توجد مبيعات في هذا التاريخ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sales = [];
        foreach ($items as $item) {
            $key = substr($item['created_at'], 0, 19);
            if (!isset($sales[$key])) {
                $sales[$key] = ['items' => [], 'ids' => [], 'created_at' => $item['created_at'], 'sale_date' => $item['sale_date']];
            }
            $sales[$key]['items'][] = $item;
            $sales[$key]['ids'][] = $item['id'];
        }
        $sales = array_values($sales);

        echo json_encode([
            'success' => true,
            'data' => [
                'sales' => $sales,
                'count' => count($sales),
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (\Throwable $e) {
    searchLogError('SearchSale', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $userMsg = t('common_error_loading');

    if (strpos($e->getMessage(), "Base table or view not found") !== false
        || strpos($e->getMessage(), "doesn't exist") !== false
        || strpos($e->getMessage(), "Unknown column") !== false) {
        $userMsg = 'مشكلة في قاعدة البيانات. يرجى تحديثها من صفحة التحديثات.';
    }

    searchJsonError($userMsg, 500);
}
