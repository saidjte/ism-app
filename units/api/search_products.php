<?php
/**
 * API للبحث عن المنتجات (وحدات متعددة) - يُستخدم في صفحات البيع والشراء
 *
 * @since v4.19.0 يستخدم SearchService المركزي (MATCH...AGAINST + FULLTEXT)
 */
header('Content-Type: application/json; charset=utf-8');

// التحقق من الجلسة
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['products' => [], 'query' => '', 'total' => 0]);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Services/SearchService.php';

$query = trim($_GET['q'] ?? '');
$filter_category = intval($_GET['category'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;

// التحقق من وجود جدول التصنيفات
$has_categories = false;
try {
    $pdo = getDBConnection();
    $pdo->query("SELECT 1 FROM categories LIMIT 1");
    $has_categories = true;
} catch (Exception $e) {}

$category_select = $has_categories ? ", c.name as category_name, c.color as category_color" : "";

try {
    $filters = [];
    if (!empty($query)) {
        $filters['search'] = $query;
    }
    if ($filter_category > 0 && $has_categories) {
        $filters['category_id'] = $filter_category;
    }

    $selectCols = "p.id, p.code, p.name, p.unit, p.selling_price, p.quantity, p.purchase_price, p.min_quantity{$category_select}";

    $result = SearchService::searchProducts($filters, $page, $limit, [
        'select_columns' => $selectCols,
    ]);

    echo json_encode([
        'products' => $result['products'],
        'query' => $query,
        'total' => $result['total'],
        'page' => $page,
    ]);

} catch (Exception $e) {
    // لا نكشف تفاصيل الخطأ في الإنتاج
    $isDebug = defined('APP_DEBUG') && APP_DEBUG === true;
    echo json_encode(['error' => $isDebug ? $e->getMessage() : 'حدث خطأ أثناء البحث', 'products' => []]);
}
