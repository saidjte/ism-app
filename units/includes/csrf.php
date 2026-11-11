<?php
// ===== حماية CSRF =====

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * توليد رمز CSRF جديد
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > 3600) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * عرض حقل CSRF مخفي في النماذج
 */
function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * التحقق من صحة رمز CSRF
 */
function verifyCSRFToken() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

/**
 * التحقق من CSRF أو إنهاء التنفيذ
 */
function requireCSRF() {
    if (!verifyCSRFToken()) {
        http_response_code(403);
        die('خطأ أمني: طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
    }
}
?>
