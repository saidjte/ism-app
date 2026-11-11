<?php
/**
 * API الطلبات المعلقة في نقطة البيع (POS)
 * CRUD على جدول held_carts — بدلاً من localStorage
 *
 * الإجراءات:
 *   GET  action=list       — جلب كل الطلبات المعلقة للمستخدم الحالي
 *   POST action=save       — حفظ طلب معلق جديد
 *   POST action=delete     — حذف طلب معلق واحد
 *   POST action=delete_all — حذف كل الطلبات المعلقة للمستخدم
 */

// === ضمان إرجاع JSON دائماً ===
function heldCartsJsonError($message, $code = 400) {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// === التقاط مخرجات PHP غير المرغوبة ===
ob_start();

// === بدء الجلسة ===
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

// === تحميل bootstrap ===
try {
    require_once __DIR__ . '/../includes/bootstrap.php';
} catch (\Throwable $e) {
    heldCartsJsonError('خطأ في تحميل النظام.', 500);
}

if (ob_get_level()) ob_clean();

// === فحص تسجيل الدخول ===
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
    heldCartsJsonError('يجب تسجيل الدخول أولاً.', 401);
}

// === فحص الصلاحية ===
if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/../includes/permissions.php';
}
if (!hasPermission('sales.add')) {
    heldCartsJsonError('ليس لديك صلاحية الوصول.', 403);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// === التحقق من CSRF للطلبات المُعدِّلة ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
        heldCartsJsonError('انتهت صلاحية الأمان. يرجى تحديث الصفحة.', 403);
    }
} else {
    $input = [];
}

// === فحص وجود الجدول + إنشاء تلقائي ===
try {
    $pdo = getDBConnection();
    $tableExists = $pdo->query("SHOW TABLES LIKE 'held_carts'")->rowCount() > 0;
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS held_carts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            cart_id VARCHAR(50) NOT NULL,
            cart_data JSON NOT NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_held_carts_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (\Throwable $e) {
    heldCartsJsonError('خطأ في قاعدة البيانات.', 500);
}

// ============================================
//  الإجراءات
// ============================================

switch ($action) {

    // === جلب كل الطلبات المعلقة ===
    case 'list':
        try {
            $stmt = $pdo->prepare("
                SELECT id, cart_id, cart_data, item_count, total,
                       created_at, updated_at
                FROM held_carts
                WHERE user_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $carts = [];
            foreach ($rows as $row) {
                $data = json_decode($row['cart_data'], true) ?: [];
                $carts[] = [
                    'db_id'     => (int) $row['id'],
                    'id'        => $data['id'] ?? $row['id'],
                    'cart_id'   => $row['cart_id'],
                    'items'     => $data['items'] ?? [],
                    'discount'  => floatval($data['discount'] ?? 0),
                    'subtotal'  => floatval($data['subtotal'] ?? 0),
                    'tax'       => floatval($data['tax'] ?? 0),
                    'total'     => floatval($row['total']),
                    'time'      => $data['time'] ?? '',
                    'date'      => $data['date'] ?? '',
                    'timestamp' => $data['timestamp'] ?? 0,
                    'itemCount' => (int) $row['item_count'],
                    'created_at' => $row['created_at'],
                ];
            }

            echo json_encode(['success' => true, 'carts' => $carts], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            heldCartsJsonError('خطأ في جلب الطلبات المعلقة.', 500);
        }
        break;

    // === حفظ طلب معلق جديد ===
    case 'save':
        $cartData = $input['cart_data'] ?? null;
        if (!$cartData) {
            heldCartsJsonError('بيانات السلة مفقودة.');
        }

        $cartId = $cartData['cart_id'] ?? ('HLD-' . time() . '-' . mt_rand(100, 999));
        $itemCount = (int) ($cartData['itemCount'] ?? 0);
        $total = floatval($cartData['total'] ?? 0);
        $jsonData = json_encode($cartData, JSON_UNESCAPED_UNICODE);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO held_carts (user_id, cart_id, cart_data, item_count, total)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $cartId, $jsonData, $itemCount, $total]);
            $newId = (int) $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'db_id'   => $newId,
                'cart_id' => $cartId,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            heldCartsJsonError('خطأ في حفظ الطلب المعلق.', 500);
        }
        break;

    // === حذف طلب معلق واحد ===
    case 'delete':
        $dbId = (int) ($input['db_id'] ?? 0);
        if ($dbId <= 0) {
            heldCartsJsonError('معرّف الطلب مفقود.');
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM held_carts WHERE id = ? AND user_id = ?");
            $stmt->execute([$dbId, $userId]);

            if ($stmt->rowCount() === 0) {
                heldCartsJsonError('الطلب المعلق غير موجود أو لا تنتمي لك.');
            }

            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            heldCartsJsonError('خطأ في حذف الطلب المعلق.', 500);
        }
        break;

    // === حذف كل الطلبات المعلقة ===
    case 'delete_all':
        try {
            $stmt = $pdo->prepare("DELETE FROM held_carts WHERE user_id = ?");
            $stmt->execute([$userId]);
            $count = $stmt->rowCount();

            echo json_encode([
                'success' => true,
                'deleted_count' => $count,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            heldCartsJsonError('خطأ في حذف الطلبات المعلقة.', 500);
        }
        break;

    default:
        heldCartsJsonError('إجراء غير معروف.', 400);
}