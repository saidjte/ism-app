<?php
/**
 * ===== نظام معالجة الأخطاء المركزي =====
 * يلتقط كل الأخطاء والاستثناءات ويسجلها بشكل منظم
 * v4.16.9: تحويل تلقائي AppException→ApiResponse + توحيد تنسيق الاستجابة
 * v4.18.3: عرض صفحة خطأ للأخطاء القاتلة (E_PARSE/E_ERROR) بدلاً من صفحة بيضاء
 * v4.18.3: إصلاح خطأ Typed property $logDir must not be accessed before initialization
 */

require_once __DIR__ . '/AppException.php';

class ErrorHandler
{
    /** @var string مسار مجلد السجلات */
    private static string $logDir = '';
    /** @var bool هل تم التهيئة */
    private static bool $initialized = false;
    /** @var string معرف الطلب الأخير (للتتبع في السجلات) */
    private static string $lastRequestId = '';

    /**
     * تهيئة معالج الأخطاء (يُستدعى مرة واحدة فقط)
     */
    public static function init(?string $logDir = null): void
    {
        if (self::$initialized) return;
        self::$initialized = true;

        self::$logDir = $logDir ?? __DIR__ . '/../logs';

        // إنشاء مجلد السجلات إذا لم يكن موجوداً
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }

        // التقاط كل الأخطاء
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleFatalError']);
    }

    /**
     * معالجة الأخطاء العادية (warning, notice, etc.)
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // لا نعرض أخطاء توقيع الإيقاف (تم إيقافها)
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $type = match ($severity) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR      => 'خطأ حرج',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'تحذير',
            E_NOTICE, E_USER_NOTICE                                      => 'ملاحظة',
            E_DEPRECATED, E_USER_DEPRECATED                             => 'مهمل',
            E_STRICT                                                     => 'صرامة',
            default                                                       => 'خطأ',
        };

        self::log([
            'type'    => $type,
            'level'   => $severity,
            'message' => $message,
            'file'    => self::relativePath($file),
            'line'    => $line,
        ]);

        return true;
    }

    /**
     * معالجة الاستثناءات غير الملتقطة
     */
    public static function handleException(Throwable $exception): void
    {
        self::log([
            'type'    => ($exception instanceof AppException) ? $exception->getErrorType() : 'exception',
            'level'   => $exception->getCode(),
            'message' => $exception->getMessage(),
            'file'    => self::relativePath($exception->getFile()),
            'line'    => $exception->getLine(),
            'trace'   => $exception->getTraceAsString(),
            'details' => ($exception instanceof AppException) ? $exception->getDetails() : [],
        ]);

        // في وضع CLI، أظهر الخطأ مباشرة بدون HTML
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "[ISM] " . get_class($exception) . ": " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine() . "\n");
            return;
        }

        // إذا كان طلب API، أرسل JSON
        if (self::isApiRequest()) {
            self::jsonResponse($exception);
        } else {
            self::showErrorPage($exception);
        }
    }

    /**
     * معالجة الأخطاء القاتلة (shutdown)
     * v4.18.3: عرض صفحة خطأ بدلاً من صفحة بيضاء
     */
    public static function handleFatalError(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            return;
        }

        $requestId = self::generateRequestId();
        $relativeFile = self::relativePath($error['file']);

        self::log([
            'type'    => 'خطأ قاتل',
            'level'   => $error['type'],
            'message' => $error['message'],
            'file'    => $relativeFile,
            'line'    => $error['line'],
        ]);

        // تنظيف أي output سابق
        while (ob_get_level()) {
            ob_end_clean();
        }

        // عرض صفحة خطأ بدلاً من صفحة بيضاء
        $isDebug = defined('APP_DEBUG') && APP_DEBUG === true;
        $isApi = self::isApiRequest();

        if ($isApi) {
            // طلب API: إرجاع JSON
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            echo json_encode([
                'success'  => false,
                'error'    => [
                    'type'    => 'fatal_error',
                    'message' => $isDebug ? $error['message'] : 'حدث خطأ داخلي في الخادم. يرجى المحاولة لاحقاً.',
                    'ref'     => $requestId,
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // طلب عادي: عرض صفحة خطأ HTML
            http_response_code(500);
            $safeMessage = $isDebug
                ? $error['message']
                : 'حدث خطأ غير متوقع. يرجى المحاولة لاحقاً.';
            ?>
            <!DOCTYPE html>
            <html lang="ar" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>حدث خطأ غير متوقع - إدارة المخزون</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body {
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        background: #f1f5f9;
                        min-height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding: 20px;
                    }
                    .error-card {
                        background: #fff;
                        border-radius: 16px;
                        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                        padding: 40px;
                        max-width: 480px;
                        width: 100%;
                        text-align: center;
                        border-top: 4px solid #ef4444;
                    }
                    .error-icon { font-size: 64px; margin-bottom: 16px; }
                    .error-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
                    .error-message { color: #64748b; font-size: 1rem; line-height: 1.6; margin-bottom: 24px; }
                    .error-code {
                        display: inline-block; background: #f1f5f9; color: #94a3b8;
                        padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; margin-bottom: 20px;
                    }
                    .error-ref {
                        display: inline-block; background: #fef3c7; color: #92400e;
                        padding: 4px 12px; border-radius: 20px; font-size: 0.75rem;
                        margin-bottom: 16px; font-family: monospace;
                    }
                    .error-details {
                        text-align: right; background: #fef2f2; border: 1px solid #fecaca;
                        border-radius: 8px; padding: 16px; margin-top: 16px;
                        font-size: 0.8rem; color: #991b1b; direction: ltr;
                        white-space: pre-wrap; overflow-x: auto;
                        max-height: 200px; overflow-y: auto;
                        display: <?php echo $isDebug ? 'block' : 'none'; ?>;
                    }
                    .btn-back {
                        display: inline-block; background: #ef4444; color: #fff;
                        padding: 10px 28px; border-radius: 8px; text-decoration: none;
                        font-weight: 600; font-size: 0.95rem; transition: opacity 0.2s;
                    }
                    .btn-back:hover { opacity: 0.85; }
                    @media (max-width: 480px) {
                        .error-card { padding: 24px; }
                        .error-icon { font-size: 48px; }
                    }
                </style>
            </head>
            <body>
                <div class="error-card">
                    <div class="error-icon">&#x274C;</div>
                    <h1 class="error-title">حدث خطأ غير متوقع</h1>
                    <p class="error-message"><?php echo htmlspecialchars($safeMessage); ?></p>
                    <span class="error-code">500</span>
                    <br>
                    <span class="error-ref"><?php echo htmlspecialchars($requestId); ?></span>
                    <?php if ($isDebug): ?>
                    <div class="error-details"><?php echo htmlspecialchars($error['message'] . "\n\nملف: " . $relativeFile . "\nسطر: " . $error['line']); ?></div>
                    <?php endif; ?>
                    <br><br>
                    <a href="/" class="btn-back">العودة للرئيسية</a>
                </div>
            </body>
            </html>
            <?php
        }
        exit;
    }

    /**
     * توليد معرف طلب فريد (للتتبع)
     */
    private static function generateRequestId(): string
    {
        if (empty(self::$lastRequestId)) {
            self::$lastRequestId = 'REQ-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        return self::$lastRequestId;
    }

    /**
     * تسجيل الخطأ في ملف السجلات
     */
    public static function log(array $data): void
    {
        // تهيئة تلقائية — تضمن عمل log() حتى لو لم يُستدعَ init()
        if (self::$logDir === '') {
            self::$logDir = __DIR__ . '/../logs';
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf(
            "[%s] [%s] %s في %s:%d\n",
            $timestamp,
            $data['type'] ?? 'خطأ',
            $data['message'] ?? '',
            $data['file'] ?? '',
            $data['line'] ?? 0
        );

        if (!empty($data['trace'])) {
            $logEntry .= "الاستدعاءات:\n" . $data['trace'] . "\n";
        }
        if (!empty($data['details'])) {
            $logEntry .= "تفاصيل: " . json_encode($data['details'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        $logEntry .= str_repeat('-', 60) . "\n";

        $logFile = self::$logDir . '/error-' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * تحويل AppException تلقائياً إلى ApiResponse
     * يُستخدم من قبل ApiRouter و Controllers لتحويل الاستثناءات
     *
     * @param AppException $exception الاستثناء
     */
    public static function handleApiException(AppException $exception): void
    {
        require_once __DIR__ . '/ApiResponse.php';
        ApiResponse::error(
            $exception->getMessage(),
            $exception->getErrorType(),
            $exception->getHttpStatusCode(),
            $exception->getDetails()
        );
    }

    /**
     * استجابة JSON للأخطاء في طلبات API
     * v4.16.9: توحيد التنسيق مع ApiResponse — نفس الهيكل دائماً
     */
    private static function jsonResponse(Throwable $exception): void
    {
        $isApp = $exception instanceof AppException;
        $statusCode = $isApp ? $exception->getHttpStatusCode() : 500;
        $type = $isApp ? $exception->getErrorType() : 'server_error';

        // تنظيف أي output سابق
        if (ob_get_level()) ob_clean();

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        // في وضع الإنتاج: لا نكشف رسائل الأخطاء الداخلية
        $isDebug = defined('APP_DEBUG') && APP_DEBUG === true;
        $safeMessage = self::safeExceptionMessage($exception, 'حدث خطأ داخلي في الخادم. يرجى المحاولة لاحقاً.');

        // v4.16.9: تنسيق موحد يتوافق مع ApiResponse
        echo json_encode([
            'success'  => false,
            'error'    => [
                'type'    => $type,
                'message' => $safeMessage,
                'details' => $isDebug ? ($isApp ? $exception->getDetails() : []) : [],
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * عرض صفحة خطأ للمستخدم
     */
    private static function showErrorPage(Throwable $exception): void
    {
        $isApp = $exception instanceof AppException;
        $statusCode = $isApp ? $exception->getHttpStatusCode() : 500;
        $type = $isApp ? $exception->getErrorType() : 'server_error';

        http_response_code($statusCode);

        $messages = [
            'validation' => [
                'title' => 'خطأ في البيانات المدخلة',
                'icon'  => '&#x26A0;&#xFE0F;',
                'color' => '#f59e0b',
            ],
            'auth' => [
                'title' => 'غير مصرح',
                'icon'  => '&#x1F512;',
                'color' => '#ef4444',
            ],
            'forbidden' => [
                'title' => 'وصول مرفوض',
                'icon'  => '&#x1F6AB;',
                'color' => '#ef4444',
            ],
            'not_found' => [
                'title' => 'الصفحة غير موجودة',
                'icon'  => '&#x1F50D;',
                'color' => '#6366f1',
            ],
            'database' => [
                'title' => 'خطأ في قاعدة البيانات',
                'icon'  => '&#x1F5C4;&#xFE0F;',
                'color' => '#ef4444',
            ],
            'default' => [
                'title' => 'حدث خطأ غير متوقع',
                'icon'  => '&#x274C;',
                'color' => '#ef4444',
            ],
        ];

        $msg = $messages[$type] ?? $messages['default'];
        $userMessage = self::safeExceptionMessage($exception, 'حدث خطأ غير متوقع. يرجى المحاولة لاحقاً.');

        // في وضع التطوير، أظهر التفاصيل
        $showDetails = defined('APP_DEBUG') && APP_DEBUG === true;

        ?>
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo $msg['title']; ?> - إدارة المخزون</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: #f1f5f9;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .error-card {
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                    padding: 40px;
                    max-width: 480px;
                    width: 100%;
                    text-align: center;
                    border-top: 4px solid <?php echo $msg['color']; ?>;
                }
                .error-icon { font-size: 64px; margin-bottom: 16px; }
                .error-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
                .error-message { color: #64748b; font-size: 1rem; line-height: 1.6; margin-bottom: 24px; }
                .error-code { display: inline-block; background: #f1f5f9; color: #94a3b8; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; margin-bottom: 20px; }
                .error-details {
                    text-align: right;
                    background: #fef2f2;
                    border: 1px solid #fecaca;
                    border-radius: 8px;
                    padding: 16px;
                    margin-top: 16px;
                    font-size: 0.8rem;
                    color: #991b1b;
                    direction: ltr;
                    white-space: pre-wrap;
                    overflow-x: auto;
                    max-height: 200px;
                    overflow-y: auto;
                    display: <?php echo $showDetails ? 'block' : 'none'; ?>;
                }
                .btn-back {
                    display: inline-block;
                    background: <?php echo $msg['color']; ?>;
                    color: #fff;
                    padding: 10px 28px;
                    border-radius: 8px;
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 0.95rem;
                    transition: opacity 0.2s;
                }
                .btn-back:hover { opacity: 0.85; }
                @media (max-width: 480px) {
                    .error-card { padding: 24px; }
                    .error-icon { font-size: 48px; }
                }
            </style>
        </head>
        <body>
            <div class="error-card">
                <div class="error-icon"><?php echo $msg['icon']; ?></div>
                <h1 class="error-title"><?php echo htmlspecialchars($msg['title']); ?></h1>
                <p class="error-message"><?php echo htmlspecialchars($userMessage); ?></p>
                <span class="error-code"><?php echo $statusCode; ?></span>
                <?php if ($showDetails): ?>
                <div class="error-details"><?php echo htmlspecialchars($exception->getMessage() . "\n\n" . $exception->getTraceAsString()); ?></div>
                <?php endif; ?>
                <br><br>
                <a href="/" class="btn-back">العودة للرئيسية</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * تنقية رسالة الاستثناء — تُعيد رسالة آمنة للمستخدم في الإنتاج
     * ورسالة مفصّلة في وضع التطوير
     *
     * القواعد:
     *   - AppException: رسالته آمنة دائماً (مُصمّمة للمستخدم)
     *   - الإنتاج (APP_DEBUG=false): رسالة عامة دائماً
     *   - التطوير (APP_DEBUG=true): الرسالة الأصلية مع سياق
     *
     * @param \Throwable $e الاستثناء
     * @param string $fallback رسالة بديلة في الإنتاج (اختياري)
     * @return string رسالة آمنة للمستخدم
     */
    public static function safeExceptionMessage(\Throwable $e, string $fallback = 'حدث خطأ أثناء المعالجة. يرجى المحاولة مرة أخرى.'): string
    {
        // رسائل AppException آمنة دائماً — مُصمّمة للمستخدم
        if ($e instanceof AppException) {
            return $e->getMessage();
        }

        // في وضع التطوير: نعرض الرسالة الأصلية
        if (defined('APP_DEBUG') && APP_DEBUG === true) {
            return $e->getMessage();
        }

        // في الإنتاج: رسالة عامة دائماً
        return $fallback;
    }

    /**
     * تسجيل استثناء بشكل موحد — يُسجّل الرسالة الكاملة مع السياق
     *
     * @param \Throwable $e الاستثناء
     * @param string $context سياق إضافي (مثلاً: 'SaleService::create')
     */
    public static function logException(\Throwable $e, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        $message = $prefix . $e->getMessage();
        $file = self::relativePath($e->getFile());
        $line = $e->getLine();

        self::log([
            'type'    => ($e instanceof AppException) ? $e->getErrorType() : 'exception',
            'level'   => $e->getCode(),
            'message' => $message,
            'file'    => $file,
            'line'    => $line,
            'trace'   => $e->getTraceAsString(),
        ]);

        // تسجيل في error_log أيضاً (للوصول إليها من سجل الخادم)
        error_log("[ISM] {$prefix}{$e->getMessage()} في {$file}:{$line}");
    }

    /**
     * هل الطلب الحالي هو طلب API؟
     */
    private static function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $accept = $headers['Accept'] ?? '';

        return str_contains($uri, '/api/') ||
               str_contains($accept, 'application/json') ||
               (isset($_GET['ajax']) && $_GET['ajax'] == '1');
    }

    /**
     * تحويل المسار المطلق إلى مسار نسبي
     */
    private static function relativePath(string $path): string
    {
        $base = dirname(__DIR__);
        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }
        return basename($path);
    }
}
