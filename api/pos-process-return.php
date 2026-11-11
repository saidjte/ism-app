<?php
/**
 * API — معالجة مرتجع من نقطة البيع
 * v6.1.6 (W16): يُستخدم من POS لإرجاع منتجات واستعادة المخزون
 *
 * يُنشئ سجل في جدول returns ويُرجع الكمية للمخزون
 */

function posReturnJsonError($message, $code = 400) {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function posReturnLog($context, $message) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . '/pos-errors.log',
        sprintf("[%s] [POS-RETURN] %s: %s\n", date('Y-m-d H:i:s'), $context, $message),
        FILE_APPEND
    );
}

ob_start();
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

try {
    require_once __DIR__ . '/../includes/bootstrap.php';
} catch (\Throwable $e) {
    posReturnLog('Bootstrap', $e->getMessage());
    posReturnJsonError('خطأ في تحميل النظام', 500);
}
if (ob_get_level()) ob_clean();

if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/../includes/permissions.php';
}

// فحص تسجيل الدخول
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
    posReturnJsonError('يجب تسجيل الدخول أولاً', 401);
}

if (!hasPermission('sales.add')) {
    posReturnJsonError('ليس لديك صلاحية إجراء المرتجعات', 403);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// فقط POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    posReturnJsonError('طريقة غير مسموحة', 405);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if ($input === null && !empty($rawInput)) {
    posReturnJsonError('بيانات غير صالحة');
}

// CSRF
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
    posReturnJsonError('انتهت صلاحية الأمان — يرجى تحديث الصفحة', 403);
}

// قراءة البيانات
$original_sale_ids = $input['sale_ids'] ?? [];
$items = $input['items'] ?? [];
$reason = trim($input['reason'] ?? '');

if (empty($original_sale_ids) || empty($items)) {
    posReturnJsonError('يرجى تحديد العملية الأصلية والمنتجات المراد إرجاعها');
}

try {
    $pdo = getDBConnection();

    // التحقق من وجود جدول returns — إنشاءه إذا لم يكن موجود
    $returnsTableExists = false;
    try {
        $check = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'returns' LIMIT 1");
        $returnsTableExists = ($check->fetch() !== false);
    } catch (\Throwable $e) {}

    if (!$returnsTableExists) {
        // إنشاء الجدول تلقائياً (W16)
        $pdo->exec("CREATE TABLE IF NOT EXISTS returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_sale_id INT NOT NULL COMMENT 'معرّف الصنف في جدول sales',
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL COMMENT 'سعر البيع الأصلي',
            refund_total DECIMAL(12,2) NOT NULL COMMENT 'المبلغ المُرتجع',
            reason TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_original_sale (original_sale_id),
            KEY idx_product (product_id),
            KEY idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        posReturnLog('AutoCreate', 'جدول returns أُنشئ تلقائياً');
    }

    // فحص جدول audit_log
    $auditExists = false;
    try {
        $pdo->query("SELECT 1 FROM audit_log LIMIT 1");
        $auditExists = true;
    } catch (\Throwable $e) {}

    $pdo->beginTransaction();

    $total_refund = 0;
    $processed_items = [];
    $errors = [];

    foreach ($items as $idx => $item) {
        $original_sale_id = intval($item['original_sale_id'] ?? 0);
        $product_id = intval($item['product_id'] ?? 0);
        $return_qty = floatval($item['return_quantity'] ?? 0);

        if ($original_sale_id <= 0 || $product_id <= 0 || $return_qty <= 0) {
            $errors[] = 'صنف غير صالح (رقم ' . ($idx + 1) . ')';
            continue;
        }

        // التحقق من وجود العملية الأصلية
        $original = $pdo->prepare("SELECT s.id, s.product_id, s.quantity, s.selling_price, s.total,
                                           p.name AS product_name, p.unit
                                    FROM sales s
                                    LEFT JOIN products p ON p.id = s.product_id
                                    WHERE s.id = ? AND s.product_id = ?");
        $original->execute([$original_sale_id, $product_id]);
        $origRow = $original->fetch(PDO::FETCH_ASSOC);

        if (!$origRow) {
            $errors[] = 'لم يتم العثور على الصنف الأصلي (رقم ' . ($idx + 1) . ')';
            continue;
        }

        // لا يمكن إرجاع أكثر من الكمية المباعة
        if ($return_qty > floatval($origRow['quantity'])) {
            $errors[] = 'الكمية المرتجعة (' . $return_qty . ') أكبر من الكمية المباعة (' . $origRow['quantity'] . ') لـ "' . $origRow['product_name'] . '"';
            continue;
        }

        $unit_price = floatval($origRow['selling_price']);
        $refund_total = $return_qty * $unit_price;

        // استعادة المخزون
        $updateStock = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
        $updateStock->execute([$return_qty, $product_id]);

        // تحديث أو حذف سجل البيع الأصلي
        $sold_qty = floatval($origRow['quantity']);
        if ($return_qty >= $sold_qty) {
            // إرجاع كامل → حذف السجل
            $delSale = $pdo->prepare("DELETE FROM sales WHERE id = ? AND product_id = ?");
            $delSale->execute([$original_sale_id, $product_id]);
        } else {
            // إرجاع جزئي → تقليل الكمية والمبلغ
            $new_qty = $sold_qty - $return_qty;
            $new_total = round($new_qty * $unit_price, 2);
            $updSale = $pdo->prepare("UPDATE sales SET quantity = ?, total = ? WHERE id = ? AND product_id = ?");
            $updSale->execute([$new_qty, $new_total, $original_sale_id, $product_id]);
        }

        // تسجيل المرتجع
        $insertReturn = $pdo->prepare(
            "INSERT INTO returns (original_sale_id, product_id, product_name, quantity, unit_price, refund_total, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insertReturn->execute([
            $original_sale_id, $product_id, $origRow['product_name'],
            $return_qty, $unit_price, $refund_total,
            $reason, intval($_SESSION['user_id'])
        ]);

        $total_refund += $refund_total;
        $processed_items[] = [
            'original_sale_id' => $original_sale_id,
            'product_id' => $product_id,
            'product_name' => $origRow['product_name'],
            'unit' => $origRow['unit'],
            'return_quantity' => $return_qty,
            'unit_price' => $unit_price,
            'refund_total' => round($refund_total, 2),
        ];
    }

    if (!empty($errors)) {
        $pdo->rollBack();
        posReturnJsonError(implode("\n", $errors));
    }

    if (empty($processed_items)) {
        $pdo->rollBack();
        posReturnJsonError('لم يتم تحديد أي منتج صالح للإرجاع');
    }

    $pdo->commit();

    // سجل تدقيق
    try {
        if ($auditExists && function_exists('addAuditLog')) {
            $auditData = json_encode([
                'original_sale_ids' => $original_sale_ids,
                'items' => array_map(function($it) {
                    return ['name' => $it['product_name'], 'qty' => $it['return_quantity'], 'refund' => $it['refund_total']];
                }, $processed_items),
                'total_refund' => round($total_refund, 2),
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE);
            addAuditLog('return_add', 'مرتجع POS: ' . count($processed_items) . ' منتجات بمبلغ ' . round($total_refund, 2), $auditData, 'return', 0);
        }
    } catch (\Throwable $e) {
        posReturnLog('AuditLog', $e->getMessage());
    }

    // تحديث CSRF
    $newCsrf = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $newCsrf;

    if (ob_get_level()) ob_clean();

    echo json_encode([
        'success' => true,
        'message' => 'تمت عملية الإرجاع بنجاح',
        'data' => [
            'items' => $processed_items,
            'item_count' => count($processed_items),
            'total_refund' => round($total_refund, 2),
            'reason' => $reason,
            'csrf_token' => $newCsrf,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    posReturnLog('ReturnError', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $userMsg = 'خطأ في معالجة المرتجع';
    if (strpos($e->getMessage(), "Base table") !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
        $userMsg = 'جدول مفقود في قاعدة البيانات — يرجى تحديث النظام';
    }
    posReturnJsonError($userMsg, 500);
}
