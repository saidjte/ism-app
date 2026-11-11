<?php
/**
 * ===== وسيط حماية CSRF (CsrfMiddleware) =====
 * يتحقق من رمز CSRF في طلبات POST
 * يمنع هجمات التزوير عبر المواقع (Cross-Site Request Forgery)
 * 
 * الاستخدام مع نظام التوجيه:
 *   Router::post('/api/save', function() { ... }, ['csrf']);
 *   Router::post('/products/add', function() { ... }, ['auth', 'csrf']);
 * 
 * يعتمد على فئة AppCsrf الموجودة في النظام
 */

// تحميل نظام CSRF إذا لم يكن محملاً
if (!class_exists('AppCsrf')) {
    require_once __DIR__ . '/../../../csrf.php';
}

class CsrfMiddleware
{
    /**
     * التحقق من رمز CSRF
     * يفحص طلبات POST فقط — طلبات GET تُمرّر تلقائياً
     *
     * آلية العمل:
     *   1. طلبات GET/PUT/DELETE غير محمية (طبيعية)
     *   2. طلبات POST يجب أن تحتوي على رمز CSRF صالح
     *   3. الرمز يُقارن بطريقة آمنة (hash_equals) لمنع هجمات التوقيت
     *   4. إذا فشل التحقق، يُرفض الطلب بخطأ 403
     *
     * @return bool هل التحقق ناجح؟
     */
    public static function handle(): bool
    {
        // طلبات غير POST لا تحتاج فحص CSRF
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            return true;
        }

        try {
            // جلب رمز CSRF من الطلب
            $token = $_POST['csrf_token'] ?? '';

            // دعم رمز CSRF في رأس HTTP (لطلبات AJAX)
            if (empty($token)) {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            }

            // دعم رمز CSRF في جسم JSON (لطلبات App.post)
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

            // التحقق من وجود الرمز في الجلسة
            if (empty($_SESSION['csrf_token'])) {
                return self::reject('رمز CSRF غير موجود في الجلسة');
            }

            // التحقق من وجود الرمز في الطلب
            if (empty($token)) {
                return self::reject('رمز CSRF غير موجود في الطلب');
            }

            // مقارنة الرموز بطريقة آمنة (تمنع هجمات التوقيت)
            if (!hash_equals($_SESSION['csrf_token'], $token)) {
                return self::reject('رمز CSRF غير صالح');
            }

            // التحقق من عدم انتهاء صلاحية الرمز (ساعة واحدة)
            if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 3600) {
                // الرمز منتهي الصلاحية — نرفض الطلب
                // لكن نُولّد رمزاً جديداً للطلب التالي
                self::regenerateToken();
                return self::reject('انتهت صلاحية رمز CSRF — يرجى تحديث الصفحة والمحاولة مرة أخرى');
            }

            // التحقق ناجح
            return true;

        } catch (\Throwable $e) {
            // خطأ غير متوقع — رفض الطلب
            return self::reject('خطأ في التحقق من رمز CSRF');
        }
    }

    /**
     * توليد رمز CSRF جديد يدوياً
     * مفيد عند الحاجة لتجديد الرمز بعد عملية ناجحة
     *
     * @return string رمز CSRF الجديد
     */
    public static function regenerateToken(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        return $_SESSION['csrf_token'];
    }

    /**
     * الحصول على رمز CSRF الحالي (أو توليد واحد جديد)
     *
     * @return string رمز CSRF
     */
    public static function getToken(): string
    {
        if (class_exists('AppCsrf')) {
            return AppCsrf::generateToken();
        }

        // تراجع إذا لم تتوفر فئة AppCsrf
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > 3600) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * رفض الطلب بسبب فشل التحقق من CSRF
     *
     * @param string $reason سبب الرفض
     * @return bool دائماً false
     */
    private static function reject(string $reason = ''): bool
    {
        // طلب AJAX — إرجاع JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'خطأ أمني: طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.',
                'csrf_error' => true,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }

        // طلب عادي — عرض صفحة خطأ
        http_response_code(403);

        if (class_exists('AppCsrf')) {
            // استخدام دالة الرفض المدمجة في النظام
            AppCsrf::require();
            return false;
        }

        // عرض رسالة خطأ مباشرة
        die('خطأ أمني: طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
    }
}
