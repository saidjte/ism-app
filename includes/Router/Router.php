<?php
/**
 * ===== نظام التوجيه المركزي (Router) =====
 * يوفر نظام توجيه موحد لجميع طلبات التطبيق
 * يحل محل التوجيه المبني على الملفات المتفرقة
 * 
 * الميزات:
 *   - توجيه مركزي لجميع المسارات
 *   - دعم Middleware (برمجيات وسيطة)
 *   - مجموعات مسارات مع بادئات مشتركة
 *   - توجيه حسب HTTP method (GET, POST, etc.)
 *   - معاملات المسار الديناميكية (مثل {id})
 *   - توافق كامل مع النظام الحالي
 * 
 * الاستخدام:
 *   // تسجيل مسارات
 *   Router::get('/products', function() { include 'list.php'; });
 *   Router::post('/products', function() { include 'save.php'; }, ['csrf']);
 *   
 *   // مجموعات مسارات
 *   Router::group(['prefix' => 'api', 'middleware' => ['auth']], function() {
 *       Router::get('/products', function() { ... });
 *   });
 *   
 *   // توجيه الطلب
 *   Router::dispatch();
 * 
 * ملاحظة: هذا النظام اختياري — التطبيق يعمل بدونه
 * يمكن تفعيله تدريجياً بنقل مسار واحد في كل مرة
 */

class Router
{
    /** @var array المسارات المسجلة */
    private static array $routes = [];

    /** @var array البرمجيات الوسيطة المسجلة عالمياً */
    private static array $middleware = [];

    /** @var array مكدس مجموعات المسارات (للدعم المتداخل) */
    private static array $groupStack = [];

    /** @var bool هل تم تهيئة النظام؟ */
    private static bool $initialized = false;

    /**
     * تسجيل مسار GET
     *
     * @param string $path مسار الطلب (مثل '/products' أو '/products/{id}')
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة (مثل ['auth', 'csrf'])
     */
    public static function get(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * تسجيل مسار POST
     *
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    public static function post(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * تسجيل مسار PUT
     *
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    public static function put(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * تسجيل مسار PATCH
     *
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    public static function patch(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * تسجيل مسار DELETE
     *
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    public static function delete(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * تسجيل مسار لأي طريقة HTTP
     *
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    public static function any(string $path, callable $handler, array $middleware = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::addRoute($method, $path, $handler, $middleware);
        }
    }

    /**
     * تسجيل مسار لعدة طرق HTTP محددة
     *
     * @param array $methods قائمة طرق HTTP (مثل ['GET', 'POST'])
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    public static function match(array $methods, string $path, callable $handler, array $middleware = []): void
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper($method), $path, $handler, $middleware);
        }
    }

    /**
     * إنشاء مجموعة مسارات مع بادئة ووسطاء مشتركين
     * يدعم التداخل (مجموعات داخل مجموعات)
     *
     * @param array $attributes خصائص المجموعة:
     *   - 'prefix' => بادئة المسارات (مثل 'api')
     *   - 'middleware' => قائمة البرمجيات الوسيطة (مثل ['auth', 'csrf'])
     * @param callable $callback دالة تعرف مسارات المجموعة
     */
    public static function group(array $attributes, callable $callback): void
    {
        // حفظ الحالة السابقة
        $previousAttributes = [];

        // حساب الخصائص المتراكمة من المجموعة الأب
        if (!empty(self::$groupStack)) {
            $parent = end(self::$groupStack);
            $previousAttributes = $parent;
        }

        // دمج الخصائص الجديدة مع الأب
        $newAttributes = [
            'prefix'     => self::mergePrefix($previousAttributes['prefix'] ?? '', $attributes['prefix'] ?? ''),
            'middleware' => array_merge(
                $previousAttributes['middleware'] ?? [],
                $attributes['middleware'] ?? []
            ),
        ];

        // إضافة إلى مكدس المجموعات
        self::$groupStack[] = $newAttributes;

        // تنفيذ دالة التعريف
        $callback();

        // إزالة من مكدس المجموعات
        array_pop(self::$groupStack);
    }

    /**
     * تسجيل middleware عالمي
     * يمكن استخدامه بالاسم في تعريفات المسارات
     *
     * @param string $name اسم الوسيط (مثل 'auth', 'csrf')
     * @param callable $handler دالة الوسيط — تُرجع true للمتابعة أو false للإيقاف
     */
    public static function middleware(string $name, callable $handler): void
    {
        self::$middleware[$name] = $handler;
    }

    /**
     * توجيه الطلب الحالي
     * يبحث عن المسار المطابق وينفذ المعالج والوسطاء
     *
     * @return mixed نتيجة المعالج أو null إذا لم يُعثر على مسار
     */
    public static function dispatch(): mixed
    {
        // تحديد طريقة الطلب
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // دعم PUT/PATCH/DELETE عبر حقل _method في POST
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        // الحصول على URI نظيف
        $uri = self::getRequestUri();

        // البحث عن المسار المطابق
        foreach (self::$routes as $route) {
            // التحقق من طريقة HTTP أولاً
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            // محاولة مطابقة المسار
            $params = self::matchRoute($route['pattern'], $uri);

            if ($params !== false) {
                // تطبيق البرمجيات الوسيطة
                $allMiddleware = $route['middleware'] ?? [];

                if (!self::runMiddleware($allMiddleware)) {
                    return null; // الوسيط أوقف التنفيذ
                }

                // تنفيذ المعالج
                try {
                    return call_user_func($route['handler'], $params);
                } catch (\Throwable $e) {
                    // تسجيل الخطأ
                    if (class_exists('ErrorHandler')) {
                        try {
                            ErrorHandler::log([
                                'type'    => 'router_error',
                                'message' => $e->getMessage(),
                                'file'    => $e->getFile(),
                                'line'    => $e->getLine(),
                                'route'   => $route['path'],
                            ]);
                        } catch (\Throwable $logError) {
                            // تجاهل أخطاء التسجيل
                        }
                    }
                    throw $e;
                }
            }
        }

        // لم يتم العثور على مسار مطابق
        return null;
    }

    /**
     * التحقق من وجود مسار مطابق للطلب الحالي
     *
     * @return bool هل يوجد مسار مطابق؟
     */
    public static function hasMatch(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = self::getRequestUri();

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            $params = self::matchRoute($route['pattern'], $uri);
            if ($params !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * الحصول على جميع المسارات المسجلة (للتصحيح)
     *
     * @return array
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * مسح جميع المسارات المسجلة (للاختبار)
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$middleware = [];
        self::$groupStack = [];
        self::$initialized = false;
    }

    // ===== دوال خاصة =====

    /**
     * إضافة مسار إلى القائمة
     *
     * @param string $method طريقة HTTP
     * @param string $path مسار الطلب
     * @param callable $handler دالة المعالجة
     * @param array $middleware قائمة البرمجيات الوسيطة
     */
    private static function addRoute(string $method, string $path, callable $handler, array $middleware = []): void
    {
        // تطبيق بادئة المجموعة إن وجدت
        $prefix = '';
        $groupMiddleware = [];

        if (!empty(self::$groupStack)) {
            $currentGroup = end(self::$groupStack);
            $prefix = $currentGroup['prefix'] ?? '';
            $groupMiddleware = $currentGroup['middleware'] ?? [];
        }

        // تنظيف المسار ودمج البادئة
        $path = '/' . trim($path, '/');
        if (!empty($prefix)) {
            $path = '/' . trim($prefix, '/') . $path;
        }

        // تحويل المسار إلى نمط regex
        $pattern = self::pathToRegex($path);

        // دمج الوسطاء
        $allMiddleware = array_merge($groupMiddleware, $middleware);

        self::$routes[] = [
            'method'     => $method,
            'path'       => $path,
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware' => $allMiddleware,
        ];
    }

    /**
     * مطابقة المسار مع النمط
     *
     * @param string $pattern نمط Regex
     * @param string $uri عنوان الطلب
     * @return array|false معاملات المسار أو false إذا لم يتطابق
     */
    private static function matchRoute(string $pattern, string $uri): array|false
    {
        if (!preg_match($pattern, $uri, $matches)) {
            return false;
        }

        // استخراج المعاملات المسماة فقط
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * تحويل مسار فيه معاملات {param} إلى نمط Regex
     * مثال: /products/{id} → #^/products/(?P<id>[^/]+)$#
     * مثال: /products/{id:\d+} → #^/products/(?P<id>\d+)$#
     *
     * @param string $path المسار مع المعاملات الديناميكية
     * @return string نمط Regex
     */
    private static function pathToRegex(string $path): string
    {
        // تحويل المعاملات الديناميكية إلى أنماط regex
        $pattern = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function ($match) {
                $name = $match[1];
                $constraint = $match[2] ?? '[^/]+';
                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $path
        );

        // إضافة مرساة البداية والنهاية
        return '#^' . $pattern . '$#';
    }

    /**
     * تنفيذ البرمجيات الوسيطة
     *
     * @param array $middlewareList قائمة أسماء الوسطاء أو دوال
     * @return bool true للمتابعة، false للإيقاف
     */
    private static function runMiddleware(array $middlewareList): bool
    {
        foreach ($middlewareList as $mw) {
            try {
                if (is_callable($mw)) {
                    // دالة مباشرة
                    $result = call_user_func($mw);
                } elseif (is_string($mw)) {
                    // اسم وسيط مسجل — التحقق من الوسطاء الخاصة
                    $result = self::resolveMiddleware($mw);
                } else {
                    continue;
                }

                // إذا أعاد الوسيط false، نوقف التنفيذ
                if ($result === false) {
                    return false;
                }
            } catch (\Throwable $e) {
                // خطأ في الوسيط — إيقاف التنفيذ
                return false;
            }
        }

        return true;
    }

    /**
     * تحليل وسيط مسجل بالاسم
     * يدعم الصيغ التالية:
     *   - 'auth' → ينفذ AuthMiddleware::handle()
     *   - 'csrf' → ينفذ CsrfMiddleware::handle()
     *   - 'permission:products.add' → ينفذ AuthMiddleware::permission('products.add')
     *
     * @param string $name اسم الوسيط (قد يحتوي على معاملات)
     * @return bool نتيجة تنفيذ الوسيط
     */
    private static function resolveMiddleware(string $name): bool
    {
        // فصل الاسم عن المعاملات
        $parts = explode(':', $name, 2);
        $mwName = $parts[0];
        $mwParam = $parts[1] ?? null;

        // البحث في الوسطاء المسجلين عالمياً
        if (isset(self::$middleware[$mwName])) {
            $handler = self::$middleware[$mwName];
            if ($mwParam !== null) {
                return (bool) call_user_func($handler, $mwParam);
            }
            return (bool) call_user_func($handler);
        }

        // وسطاء مدمجين
        switch ($mwName) {
            case 'auth':
                // يتطلب تسجيل الدخول
                if (class_exists('AuthMiddleware')) {
                    return AuthMiddleware::handle();
                }
                // تراجع إلى النظام القديم
                if (class_exists('AppAuth')) {
                    AppAuth::requireLogin();
                    return true;
                }
                return false;

            case 'admin':
                // يتطلب صلاحية المدير
                if (class_exists('AuthMiddleware')) {
                    return AuthMiddleware::admin();
                }
                if (class_exists('AppAuth')) {
                    AppAuth::requireAdmin();
                    return true;
                }
                return false;

            case 'csrf':
                // التحقق من رمز CSRF
                if (class_exists('CsrfMiddleware')) {
                    return CsrfMiddleware::handle();
                }
                // تراجع إلى النظام القديم
                if (class_exists('AppCsrf')) {
                    return AppCsrf::verify();
                }
                return false;

            case 'permission':
                // يتطلب صلاحية محددة (مثل permission:products.add)
                if ($mwParam !== null && class_exists('AuthMiddleware')) {
                    $handler = AuthMiddleware::permission($mwParam);
                    return (bool) call_user_func($handler);
                }
                if ($mwParam !== null && function_exists('hasPermission')) {
                    return hasPermission($mwParam);
                }
                return false;

            default:
                // وسيط غير معروف — السماح بالمرور (أمان أفضل من الحظر)
                return true;
        }
    }

    /**
     * دمج بادئتين معاً
     *
     * @param string $parent بادئة المجموعة الأب
     * @param string $child بادئة المجموعة الجديدة
     * @return string البادئة المدمجة
     */
    private static function mergePrefix(string $parent, string $child): string
    {
        $parent = trim($parent, '/');
        $child = trim($child, '/');

        if (empty($parent) && empty($child)) {
            return '';
        }

        if (empty($parent)) {
            return $child;
        }

        if (empty($child)) {
            return $parent;
        }

        return $parent . '/' . $child;
    }

    /**
     * الحصول على URI نظيف من الطلب الحالي
     *
     * @return string
     */
    private static function getRequestUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        // إزالة المسار الفرعي للتطبيق (مثل /is-app/)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);

        // إذا كان URI يبدأ بمسار المجلد، نزيله
        if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        // تنظيف المسار
        $uri = '/' . trim($uri, '/');

        // إزالة index.php من المسار
        $uri = str_replace('/index.php', '', $uri);

        return $uri ?: '/';
    }
}
