<?php
/**
 * ===== حماية CSRF =====
 * 
 * فئة AppCsrf (OOP) + دوال إجرائية للتوافق مع الكود القديم
 * 
 * ⚠️ ملاحظة: لا نستخدم namespace App لأنها تسبب مشاكل على Windows (NTFS)
 * الفئة مُسماة AppCsrf مباشرة في المساحة العامة
 */

// بدء الجلسة مع الإعدادات الأمنية المركزية
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

/**
 * فئة حماية CSRF
 */
class AppCsrf
{
    /**
     * توليد رمز CSRF جديد
     */
    public static function generateToken(): string {
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > 3600) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * عرض حقل CSRF مخفي في النماذج
     */
    public static function field(): string {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * التحقق من صحة رمز CSRF
     */
    public static function verify(?string $token = null): bool {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? '';
            // دعم جسم JSON (لطلبات App.post)
            if (empty($token)) {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            }
            if (empty($token)) {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
                if (stripos($contentType, 'application/json') !== false) {
                    $raw = file_get_contents('php://input');
                    $json = json_decode($raw, true);
                    if (is_array($json)) {
                        $token = $json['csrf_token'] ?? '';
                    }
                }
            }
        }
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
    public static function require(): void {
        if (!self::verify()) {
            http_response_code(403);
            die('خطأ أمني: طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
    }
}

// ===== دوال إجرائية للتوافق مع الكود القديم =====

function generateCSRFToken() {
    return AppCsrf::generateToken();
}

function csrfField() {
    return AppCsrf::field();
}

function verifyCSRFToken($token = null) {
    return AppCsrf::verify($token);
}

function requireCSRF() {
    return AppCsrf::require();
}
