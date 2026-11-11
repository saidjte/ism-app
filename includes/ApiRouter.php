<?php
/**
 * ===== موجه API الموحد (REST API Router) =====
 * يوفر نقطة دخول واحدة لكل طلبات API
 *
 * الميزات:
 *   - تسجيل مسارات RESTful (GET, POST, PUT, PATCH, DELETE)
 *   - مجموعات مسارات مع بادئة وبرمجيات وسيطة
 *   - برمجيات وسيطة لكل مسار (per-route middleware)
 *   - معاملات المسار {param} و {param:regex}
 *   - دعم PUT/PATCH/DELETE عبر _method في POST
 *
 * الاستخدام:
 *   $router = new ApiRouter();
 *
 *   // مسار عام بدون وسيط
 *   $router->get('products', [ProductController::class, 'index']);
 *
 *   // مجموعة مع وسيط مصادقة
 *   $router->middleware($authMiddleware)->group('api', function($router) {
 *       $router->get('profile', [UserController::class, 'profile']);
 *       $router->post('profile', [UserController::class, 'update']);
 *   });
 *
 * v4.40.0: رسائل خطأ مفصلة في executeHandler + تشخيص + تفاصيل التصحيح
 */

require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/AppException.php';

class ApiRouter
{
    /** @var array المسارات المسجلة (كل مسار يحمل وسيطه الخاص) */
    private array $routes = [];
    /** @var string|null بادئة المسار الحالية (للمجموعات) */
    private ?string $prefix = null;
    /** @var array البرمجيات الوسيطة النشطة حالياً (تُلتقط عند تسجيل كل مسار) */
    private array $currentMiddleware = [];

    // === تسجيل المسارات ===

    /**
     * تسجيل مسار GET
     */
    public function get(string $path, callable|string|array $handler): self
    {
        $this->addRoute('GET', $path, $handler);
        return $this;
    }

    /**
     * تسجيل مسار POST
     */
    public function post(string $path, callable|string|array $handler): self
    {
        $this->addRoute('POST', $path, $handler);
        return $this;
    }

    /**
     * تسجيل مسار PUT
     */
    public function put(string $path, callable|string|array $handler): self
    {
        $this->addRoute('PUT', $path, $handler);
        return $this;
    }

    /**
     * تسجيل مسار PATCH
     */
    public function patch(string $path, callable|string|array $handler): self
    {
        $this->addRoute('PATCH', $path, $handler);
        return $this;
    }

    /**
     * تسجيل مسار DELETE
     */
    public function delete(string $path, callable|string|array $handler): self
    {
        $this->addRoute('DELETE', $path, $handler);
        return $this;
    }

    /**
     * تسجيل مسار لعدة طرق
     */
    public function match(array $methods, string $path, callable|string|array $handler): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $this;
    }

    /**
     * تسجيل مسار لأي طريقة
     */
    public function any(string $path, callable|string|array $handler): self
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    /**
     * إنشاء مجموعة مسارات مع بادئة
     *
     * البرمجيات الوسيطة المُضافة قبل استدعاء group() تُطبق على
     * كل المسارات داخل المجموعة فقط (وليس على المسارات خارجها).
     *
     * مثال:
     *   $router->middleware($auth)->group('api', function($router) {
     *       $router->get('profile', ...); // ← يحتاج مصادقة
     *   });
     *   $router->get('public', ...); // ← لا يحتاج مصادقة
     */
    public function group(string $prefix, callable $callback): self
    {
        // حفظ الحالة السابقة
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->currentMiddleware;

        // تعيين البادئة الجديدة
        // v4.37.0: دعم البادئة الفارغة — لا نضيف '/' إذا كان الـ prefix فارغاً
        $trimmedPrefix = trim($prefix, '/');
        if ($trimmedPrefix !== '') {
            $this->prefix = ($previousPrefix ?? '') . '/' . $trimmedPrefix;
        } else {
            $this->prefix = $previousPrefix ?? '';
        }

        // تنفيذ الاستدعاء — المسارات المسجلة ستلتقط currentMiddleware
        $callback($this);

        // استعادة الحالة السابقة (إزالة وسيط المجموعة)
        $this->prefix = $previousPrefix;
        $this->currentMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * إضافة برمجية وسيطة
     *
     * الوسيط يُطبق على المسارات المُسجلة بعده فقط.
     * يمكن استدعاؤها قبل group() لتطبيقها على كل مسارات المجموعة.
     *
     * مثال:
     *   $router->middleware($auth)->group('api', function($router) { ... });
     */
    public function middleware(callable $middleware): self
    {
        $this->currentMiddleware[] = $middleware;
        return $this;
    }

    // === التنفيذ ===

    /**
     * توجيه الطلب إلى المسار المناسب
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // دعم PUT/PATCH/DELETE عبر _method في POST
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        // تحليل URI
        $uri = $this->getRequestUri();
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // البحث عن المسار المطابق
        $matched = false;
        $params = [];

        foreach ($this->routes as $route) {
            // تخطي المسارات بطرق HTTP مختلفة
            if ($route['method'] !== $method) {
                continue;
            }

            // بناء مسار كامل مع البادئة
            $routePath = ($route['prefix'] ?? '') . $route['path'];
            $regex = $this->pathToRegex($routePath);

            if (preg_match($regex, $path, $matches)) {
                // استخراج المعاملات المسماة
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                // تنفيذ برمجيات الوسيط الخاصة بهذا المسار فقط
                // v5.11.3: إضافة try/catch حول الوسيط لمنع fatal_error
                foreach ($route['middleware'] as $mw) {
                    try {
                        $result = $mw($params);
                        if ($result === false) {
                            return; // الوسيط أوقف التنفيذ
                        }
                    } catch (\Throwable $e) {
                        ErrorHandler::logException($e, 'ApiRouter::middleware');
                        ApiResponse::serverError('خطأ في التحقق من الصلاحيات');
                        return;
                    }
                }

                // تنفيذ المعالج
                $this->executeHandler($route['handler'], $params);
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            ApiResponse::notFound('المسار غير موجود: ' . $method . ' ' . $path);
        }
    }

    // === دوال داخلية ===

    /**
     * إضافة مسار — يلتقط البرمجيات الوسيطة النشطة حالياً
     */
    private function addRoute(string $method, string $path, callable|string|array $handler): void
    {
        $path = '/' . trim($path, '/');
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'handler'    => $handler,
            'middleware' => $this->currentMiddleware, // ← لقطة من الوسيط النشط
            'prefix'     => $this->prefix,            // ← لقطة من البادئة النشطة
        ];
    }

    /**
     * تحويل مسار فيه معاملات {param} إلى regex
     * مثال: /products/{id} → #^/products/(?P<id>[^/]+)$#
     * مثال: /products/{id:[0-9]+} → #^/products/(?P<id>[0-9]+)$#
     */
    private function pathToRegex(string $path): string
    {
        $path = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($match) {
            $name = $match[1];
            $constraint = $match[2] ?? '[^/]+';
            return '(?P<' . $name . '>' . $constraint . ')';
        }, $path);

        return '#^' . $path . '$#';
    }

    /**
     * تنفيذ المعالج
     */
    private function executeHandler(callable|string|array $handler, array $params): void
    {
        try {
            if (is_callable($handler)) {
                call_user_func($handler, $params);
            } elseif (is_array($handler)) {
                [$class, $method] = $handler;
                if (is_string($class) && class_exists($class)) {
                    $instance = new $class();
                    $instance->$method($params);
                } elseif (is_object($class)) {
                    $class->$method($params);
                }
            }
        } catch (AppException $e) {
            ErrorHandler::handleApiException($e);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'ApiRouter::executeHandler');
            
            // v4.40.0: رسائل خطأ مفصلة أكثر + تفاصيل التصحيح
            $errorMsg = 'حدث خطأ داخلي في الخادم. يرجى المحاولة لاحقاً.';
            $errType = 'server_error';
            $statusCode = 500;
            $details = [];
            
            $msg = $e->getMessage();
            if (strpos($msg, 'Column not found') !== false || strpos($msg, 'Champ') !== false) {
                $errorMsg = 'خطأ في هيكل قاعدة البيانات — عمود مفقود. يرجى زيارة صفحة إعداد API لإصلاح الجدول.';
                $errType = 'db_schema_error';
            } elseif (strpos($msg, "Table") !== false && strpos($msg, "doesn't exist") !== false) {
                $errorMsg = 'جدول قاعدة البيانات غير موجود. يرجى زيارة صفحة إعداد API.';
                $errType = 'db_table_missing';
            } elseif (strpos($msg, 'SQLSTATE') !== false) {
                $errorMsg = 'خطأ في قاعدة البيانات. يرجى المحاولة لاحقاً أو التواصل مع المدير.';
                $errType = 'db_error';
            }

            // v4.40.0: إضافة تفاصيل التصحيح في وضع التطوير
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                $details = [
                    'exception' => get_class($e),
                    'message' => $msg,
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ];
            }
            
            ApiResponse::error($errorMsg, $errType, $statusCode, $details);
        }
    }

    /**
     * الحصول على URI نظيف
     *
     * v4.37.0: إصلاح حرج — إزالة المسار الفرعي للتطبيق (مثل /ism-app)
     * من URI قبل مطابقة المسارات. بدون هذا الإصلاح، المسارات لا تتطابق
     * أبداً عندما يكون التطبيق في مسار فرعي.
     *
     * مثال:
     *   SCRIPT_NAME = /ism-app/api/mobile/index.php
     *   REQUEST_URI = /ism-app/api/mobile/auth/login
     *   basePath = /ism-app/api/mobile
     *   بعد الإزالة: /auth/login
     */
    private function getRequestUri(): string
    {
        $uri = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        // إزالة المسار الأساسي (الدليل الأب لملف الدخول)
        // هذا ضروري عندما يكون التطبيق في مسار فرعي مثل /ism-app/
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName) {
            // dirname('/ism-app/api/mobile/index.php') = '/ism-app/api/mobile'
            $basePath = rtrim(dirname($scriptName), '/');
            if ($basePath && $basePath !== '/' && str_starts_with($uri, $basePath)) {
                $uri = substr($uri, strlen($basePath));
            }

            // إزالة اسم الملف (index.php) إذا كان موجوداً في URI
            if (str_contains($uri, basename($scriptName))) {
                $uri = substr($uri, 0, strpos($uri, basename($scriptName)));
            }
        }

        return '/' . ltrim($uri, '/');
    }
}
