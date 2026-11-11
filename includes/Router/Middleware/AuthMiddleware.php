<?php
/**
 * ===== وسيط المصادقة (AuthMiddleware) =====
 * يتحقق من تسجيل الدخول قبل السماح بالوصول
 * 
 * الاستخدام مع نظام التوجيه:
 *   Router::get('/dashboard', function() { ... }, ['auth']);
 *   Router::get('/admin', function() { ... }, ['admin']);
 *   Router::get('/products/add', function() { ... }, ['permission:products.add']);
 * 
 * يعتمد على فئة AppAuth الموجودة في النظام
 */

// تحميل نظام المصادقة إذا لم يكن محملاً
if (!class_exists('AppAuth')) {
    require_once __DIR__ . '/../../../auth.php';
}

class AuthMiddleware
{
    /**
     * يتطلب تسجيل الدخول
     * يتحقق من أن المستخدم مسجل الدخول عبر الجلسة
     * إذا لم يكن مسجلاً، يحوّله إلى صفحة تسجيل الدخول
     *
     * @return bool هل المستخدم مسجل الدخول؟
     */
    public static function handle(): bool
    {
        try {
            // التحقق من "تذكرني" أولاً
            if (class_exists('AppAuth')) {
                AppAuth::checkRememberMe();
            }

            // التحقق من تسجيل الدخول
            if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
                // حفظ الصفحة المطلوبة للعودة إليها بعد تسجيل الدخول
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];

                // إذا كان طلب AJAX، أرجع خطأ 401
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'message' => 'يجب تسجيل الدخول أولاً',
                        'redirect' => 'login.php',
                    ], JSON_UNESCAPED_UNICODE);
                    return false;
                }

                // طلب عادي — تحويل إلى صفحة تسجيل الدخول
                $baseUrl = '';
                if (class_exists('AppAuth')) {
                    $baseUrl = AppAuth::getBaseUrl();
                }
                header("Location: " . $baseUrl . "/login.php");
                return false;
            }

            // التحقق من انتهاء الجلسة (30 دقيقة بدون نشاط)
            if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
                // إنهاء الجلسة المنتهية
                session_unset();
                session_destroy();
                session_start();
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'message' => 'انتهت صلاحية الجلسة',
                        'redirect' => 'login.php',
                    ], JSON_UNESCAPED_UNICODE);
                    return false;
                }

                $baseUrl = '';
                if (class_exists('AppAuth')) {
                    $baseUrl = AppAuth::getBaseUrl();
                }
                header("Location: " . $baseUrl . "/login.php?msg=session_expired");
                return false;
            }

            // تحديث وقت النشاط
            $_SESSION['last_activity'] = time();

            // تجديد معرف الجلسة إذا لم يكن مهيأ (حماية من Session Fixation)
            if (!isset($_SESSION['session_initiated'])) {
                session_regenerate_id(true);
                $_SESSION['session_initiated'] = true;
            }

            return true;

        } catch (\Throwable $e) {
            // خطأ غير متوقع — رفض الوصول
            return false;
        }
    }

    /**
     * يتطلب صلاحية المدير
     * يتحقق من أن المستخدم مسجل الدخول ودوره 'admin'
     *
     * @return bool هل المستخدم مدير؟
     */
    public static function admin(): bool
    {
        // أولاً: التحقق من تسجيل الدخول
        if (!self::handle()) {
            return false;
        }

        // التحقق من صلاحية المدير
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            // طلب AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'ليس لديك صلاحية المدير للوصول إلى هذه الصفحة',
                ], JSON_UNESCAPED_UNICODE);
                return false;
            }

            // طلب عادي — عرض خطأ 403
            http_response_code(403);
            die('ليس لديك صلاحية الوصول إلى هذه الصفحة. هذه الصفحة متاحة للمدير فقط.');
        }

        return true;
    }

    /**
     * يتطلب صلاحية محددة
     * يُرجع دالة قابلة للتنفيذ لتستخدم كـ middleware
     *
     * @param string $permission اسم الصلاحية (مثل 'products.add')
     * @return callable دالة الوسيط
     */
    public static function permission(string $permission): callable
    {
        return function () use ($permission): bool {
            // أولاً: التحقق من تسجيل الدخول
            if (!self::handle()) {
                return false;
            }

            // التحقق من الصلاحية
            if (function_exists('hasPermission')) {
                if (hasPermission($permission)) {
                    return true;
                }
            } elseif (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
                // المدير يملك كل الصلاحيات
                return true;
            } else {
                // دالة hasPermission غير متاحة — رفض الوصول
                return false;
            }

            // الصلاحية غير متاحة
            $roleName = function_exists('getRoleName') 
                ? getRoleName($_SESSION['user_role']) 
                : ($_SESSION['user_role'] ?? 'غير معروف');

            $permLabel = function_exists('getPermissionLabel') 
                ? getPermissionLabel($permission) 
                : $permission;

            $message = "ليس لديك صلاحية الوصول.\nالصلاحية المطلوبة: {$permLabel}\nدورك الحالي: {$roleName}";

            // طلب AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => $message,
                ], JSON_UNESCAPED_UNICODE);
                return false;
            }

            // طلب عادي — استخدام دالة requirePermission إذا توفرت
            if (function_exists('requirePermission')) {
                requirePermission($permission);
                // إذا وصلنا هنا، الصلاحية متاحة (requirePermission توقف التنفيذ إذا لم تكن)
                return true;
            }

            // طلب عادي بدون دالة requirePermission
            http_response_code(403);
            die(htmlspecialchars($message));
        };
    }

    /**
     * يتطلب أن يكون المستخدم مسجل الدخول مع إمكانية تجاوز الصلاحية للمدير
     * يعيد true إذا كان مسجلاً ويمتلك الصلاحية أو كان مديراً
     *
     * @param string $permission اسم الصلاحية المطلوبة
     * @return bool
     */
    public static function requirePermission(string $permission): bool
    {
        $handler = self::permission($permission);
        return (bool) call_user_func($handler);
    }
}
