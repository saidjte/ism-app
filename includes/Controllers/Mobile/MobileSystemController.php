<?php
/**
 * ===== متحكم إدارة النظام للموبايل (MobileSystemController) =====
 * يوفر معلومات وإدارة النظام عبر API الموبايل (مدير فقط)
 *
 * المسارات:
 *   GET  /api/mobile/system/info            — معلومات النظام
 *   GET  /api/mobile/system/db-status       — حالة قاعدة البيانات
 *   POST /api/mobile/system/test-db         — اختبار اتصال قاعدة بيانات
 *   PUT  /api/mobile/system/db-settings     — تحديث إعدادات قاعدة البيانات
 *   GET  /api/mobile/system/changelog       — سجل التغييرات
 *   GET  /api/mobile/system/permissions     — صلاحيات المستخدم الحالي
 *   GET  /api/mobile/system/version         — التحقق من الإصدار
 *   POST /api/mobile/system/clear-cache     — مسح ذاكرة التخزين المؤقت
 *
 * الصلاحيات:
 *   جميع العمليات تتطلب صلاحيات مدير ما لم يُذكر خلاف ذلك
 *
 * v5.14.0: إنشاء متحكم إدارة النظام للموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileSystemController extends ApiController
{
    /**
     * معلومات النظام
     *
     * GET /api/mobile/system/info
     * يرجع معلومات عامة عن النظام (مدير فقط)
     */
    public function info(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // معلومات التطبيق
            $appName = $db->getSetting('app_name', 'إدارة المخزون');
            $appVersion = defined('APP_VERSION') ? APP_VERSION : ($db->getSetting('app_version', '5.12.0'));
            $apiVersion = defined('API_VERSION') ? API_VERSION : '1.0';

            // معلومات PHP
            $phpVersion = PHP_VERSION;
            $phpExtensions = get_loaded_extensions();

            // معلومات قاعدة البيانات
            $dbVersion = '';
            $dbSize = 0;
            $dbTablesCount = 0;
            try {
                $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
                $dbSizeRow = fetchOne(
                    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size
                     FROM information_schema.tables
                     WHERE table_schema = ?",
                    [defined('DB_NAME') ? DB_NAME : '']
                );
                $dbSize = floatval($dbSizeRow['size'] ?? 0);
                $dbTablesCount = count(fetchAll("SHOW TABLES"));
            } catch (\Throwable $e) {}

            // إحصائيات سريعة
            $stats = [];
            try {
                $stats['products'] = intval(fetchOne("SELECT COUNT(*) as c FROM products")['c'] ?? 0);
                $stats['sales'] = intval(fetchOne("SELECT COUNT(*) as c FROM sales")['c'] ?? 0);
                $stats['purchases'] = intval(fetchOne("SELECT COUNT(*) as c FROM purchases")['c'] ?? 0);
                $stats['users'] = intval(fetchOne("SELECT COUNT(*) as c FROM users")['c'] ?? 0);
                $stats['categories'] = intval(fetchOne("SELECT COUNT(*) as c FROM categories")['c'] ?? 0);
                $stats['expenses'] = intval(fetchOne("SELECT COUNT(*) as c FROM expenses")['c'] ?? 0);
            } catch (\Throwable $e) {}

            // معلومات الخادم
            $serverInfo = [
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'php_version' => $phpVersion,
                'max_upload_size' => ini_get('upload_max_filesize'),
                'max_post_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'timezone' => date_default_timezone_get(),
                'disk_free_space' => $this->formatBytes(disk_free_space('.')),
                'disk_total_space' => $this->formatBytes(disk_total_space('.')),
            ];

            ApiResponse::success([
                'app' => [
                    'name' => $appName,
                    'version' => $appVersion,
                    'api_version' => $apiVersion,
                ],
                'database' => [
                    'type' => 'MySQL',
                    'version' => $dbVersion,
                    'size_mb' => $dbSize,
                    'tables_count' => $dbTablesCount,
                    'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
                    'name' => defined('DB_NAME') ? DB_NAME : '',
                ],
                'stats' => $stats,
                'server' => $serverInfo,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::info');
            }
            ApiResponse::serverError('خطأ في جلب معلومات النظام');
        }
    }

    /**
     * حالة قاعدة البيانات
     *
     * GET /api/mobile/system/db-status
     */
    public function dbStatus(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $status = 'connected';
            $info = [];

            try {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $version = $pdo->query("SELECT VERSION()")->fetchColumn();
                $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
                $charset = $pdo->query("SELECT @@character_set_database")->fetchColumn();
                $collation = $pdo->query("SELECT @@collation_database")->fetchColumn();

                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

                $info = [
                    'driver' => $driver,
                    'version' => $version,
                    'database' => $dbName,
                    'charset' => $charset,
                    'collation' => $collation,
                    'tables_count' => count($tables),
                    'tables' => $tables,
                    'connection_status' => 'connected',
                ];

                // التحقق من الجداول المطلوبة
                $requiredTables = ['products', 'sales', 'purchases', 'users', 'categories',
                    'expenses', 'api_tokens', 'system_settings', 'audit_log'];
                $missingTables = array_diff($requiredTables, $tables);
                $info['missing_tables'] = array_values($missingTables);
                $info['schema_ok'] = empty($missingTables);

            } catch (\Throwable $e) {
                $status = 'error';
                $info = ['error' => $e->getMessage()];
            }

            ApiResponse::success([
                'status' => $status,
                'info' => $info,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::dbStatus');
            }
            ApiResponse::serverError('خطأ في جلب حالة قاعدة البيانات');
        }
    }

    /**
     * اختبار اتصال قاعدة بيانات
     *
     * POST /api/mobile/system/test-db
     * Body: { "host": "localhost", "name": "db_name", "user": "root", "pass": "password" }
     */
    public function testDb(array $params = []): void
    {
        $this->requirePost();

        try {
            $this->requireAdmin();

            $host = trim($this->input('host', ''));
            $name = trim($this->input('name', ''));
            $user = trim($this->input('user', ''));
            $pass = $this->input('pass', '');

            if (empty($host) || empty($name) || empty($user)) {
                ApiResponse::validationError(['host' => 'المضيف مطلوب', 'name' => 'اسم القاعدة مطلوب', 'user' => 'المستخدم مطلوب']);
                return;
            }

            try {
                $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
                $testPdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);

                $version = $testPdo->query("SELECT VERSION()")->fetchColumn();
                $tables = $testPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

                ApiResponse::success([
                    'success' => true,
                    'version' => $version,
                    'database' => $name,
                    'tables_count' => count($tables),
                ], 'تم الاتصال بنجاح');

            } catch (PDOException $e) {
                ApiResponse::success([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 'فشل الاتصال');
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::testDb');
            }
            ApiResponse::serverError('خطأ في اختبار الاتصال');
        }
    }

    /**
     * تحديث إعدادات قاعدة البيانات
     *
     * PUT /api/mobile/system/db-settings
     * Body: { "host": "localhost", "name": "new_db", "user": "root", "pass": "pass" }
     *
     * تحذير: هذه العملية خطيرة وتؤثر على عمل النظام بالكامل
     */
    public function dbSettings(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $host = trim($this->input('host', ''));
            $name = trim($this->input('name', ''));
            $user = trim($this->input('user', ''));
            $pass = $this->input('pass', '');

            if (empty($host)) {
                ApiResponse::validationError(['host' => 'المضيف (DB_HOST) مطلوب']);
                return;
            }
            if (empty($name)) {
                ApiResponse::validationError(['name' => 'اسم قاعدة البيانات (DB_NAME) مطلوب']);
                return;
            }
            if (empty($user)) {
                ApiResponse::validationError(['user' => 'اسم المستخدم (DB_USER) مطلوب']);
                return;
            }

            // إذا كانت كلمة المرور فارغة نحتفظ بالحالية
            if (empty($pass)) {
                $pass = defined('DB_PASS') ? DB_PASS : '';
            }

            // اختبار الاتصال بالمعطيات الجديدة
            try {
                $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
                $testPdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
            } catch (PDOException $e) {
                ApiResponse::validationError(['فشل اختبار الاتصال: ' . $e->getMessage()]);
                return;
            }

            // تشفير كلمة المرور
            $encryptedPass = $pass;
            if (class_exists('EnvEncryptor') && !empty($pass)) {
                try {
                    $encryptedPass = EnvEncryptor::encrypt($pass);
                } catch (\Throwable $e) {}
            }

            // تحديث ملف .env
            $envPath = dirname(__DIR__, 3) . '/.env'; // المسار من Controllers/Mobile إلى الجذر
            if (file_exists($envPath)) {
                // إنشاء نسخة احتياطية
                $backupPath = $envPath . '.backup.' . date('Y-m-d_His');
                copy($envPath, $backupPath);

                $envContent = file_get_contents($envPath);
                $updates = [
                    'DB_HOST' => $host,
                    'DB_NAME' => $name,
                    'DB_USER' => $user,
                    'DB_PASS' => $encryptedPass,
                ];

                foreach ($updates as $key => $value) {
                    $envContent = preg_replace(
                        "/^{$key}=.*/m",
                        "{$key}={$value}",
                        $envContent
                    );
                }

                file_put_contents($envPath, $envContent, LOCK_EX);

                // تسجيل في سجل المراجعة
                if (function_exists('addAuditLog')) {
                    addAuditLog('settings_update', 'تحديث إعدادات قاعدة البيانات عبر الموبايل');
                }

                ApiResponse::success([
                    'updated' => true,
                    'host' => $host,
                    'name' => $name,
                    'backup_created' => true,
                    'message' => 'تم تحديث إعدادات قاعدة البيانات. يجب تسجيل الدخول مجدداً لتطبيق التغييرات.',
                ], 'تم تحديث الإعدادات. يجب إعادة تسجيل الدخول.');

            } else {
                ApiResponse::error('ملف .env غير موجود', 'file_not_found', 500);
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::dbSettings');
            }
            ApiResponse::serverError('خطأ في تحديث إعدادات قاعدة البيانات');
        }
    }

    /**
     * سجل التغييرات
     *
     * GET /api/mobile/system/changelog
     */
    public function changelog(array $params = []): void
    {
        try {
            $changelogPath = dirname(__DIR__, 3) . '/changelog.php';

            if (!file_exists($changelogPath)) {
                // محاولة البحث عن ملف CHANGELOG.md
                $mdPath = dirname(__DIR__, 3) . '/CHANGELOG.md';
                if (file_exists($mdPath)) {
                    $content = file_get_contents($mdPath);
                    ApiResponse::success([
                        'format' => 'markdown',
                        'content' => $content,
                    ]);
                    return;
                }

                ApiResponse::success([
                    'format' => 'none',
                    'content' => '',
                    'message' => 'سجل التغييرات غير متوفر',
                ]);
                return;
            }

            // قراءة محتوى changelog.php واستخراج النص
            ob_start();
            // لا نريد تنفيذ ملف PHP كاملاً (قد يحتوي HTML)، نقرأ المحتوى فقط
            $content = file_get_contents($changelogPath);

            // استخراج النص من HTML إذا كان موجوداً
            $text = strip_tags($content);
            $text = preg_replace('/\s+/', ' ', $text);

            $appVersion = defined('APP_VERSION') ? APP_VERSION : '5.12.0';

            ApiResponse::success([
                'format' => 'text',
                'content' => trim($text),
                'version' => $appVersion,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::changelog');
            }
            ApiResponse::serverError('خطأ في جلب سجل التغييرات');
        }
    }

    /**
     * صلاحيات المستخدم الحالي
     *
     * GET /api/mobile/system/permissions
     * يرجع صلاحيات المستخدم المتصل حالياً
     */
    public function permissions(array $params = []): void
    {
        try {
            $userId = intval($_SESSION['user_id'] ?? 0);
            $userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';

            if ($userId <= 0) {
                ApiResponse::unauthorized();
                return;
            }

            // جلب كل الصلاحيات المتاحة
            $allPermissions = [];
            if (function_exists('getRolePermissions')) {
                $allPermissions = getRolePermissions();
            }

            // صلاحيات المستخدم الحالي
            $userPermissions = $allPermissions[$userRole] ?? [];

            // تجميع حسب القسم
            $grouped = [];
            if (function_exists('getRolePermissionSummary')) {
                $grouped = getRolePermissionSummary($userRole);
            }

            // معلومات المستخدم
            $user = fetchOne("SELECT id, username, full_name, role, is_active FROM users WHERE id = ?", [$userId]);

            // أسماء الصلاحيات بالعربية
            $permissionLabels = [];
            if (function_exists('getPermissionLabel')) {
                foreach ($userPermissions as $perm) {
                    $permissionLabels[$perm] = getPermissionLabel($perm);
                }
            }

            ApiResponse::success([
                'user' => $user ? [
                    'id' => intval($user['id']),
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'is_active' => intval($user['is_active']),
                ] : null,
                'role' => $userRole,
                'permissions' => $userPermissions,
                'permissions_grouped' => $grouped,
                'permissions_labels' => $permissionLabels,
                'is_admin' => $userRole === 'admin',
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::permissions');
            }
            ApiResponse::serverError('خطأ في جلب الصلاحيات');
        }
    }

    /**
     * التحقق من الإصدار
     *
     * GET /api/mobile/system/version
     */
    public function version(array $params = []): void
    {
        try {
            $currentVersion = defined('APP_VERSION') ? APP_VERSION : '5.12.0';
            $apiVersion = defined('API_VERSION') ? API_VERSION : '1.0';

            ApiResponse::success([
                'app_version' => $currentVersion,
                'api_version' => $apiVersion,
                'php_version' => PHP_VERSION,
                'minimum_mobile_app_version' => '1.0.0',
            ]);

        } catch (\Throwable $e) {
            ApiResponse::serverError('خطأ في جلب معلومات الإصدار');
        }
    }

    /**
     * مسح ذاكرة التخزين المؤقت
     *
     * POST /api/mobile/system/clear-cache
     */
    public function clearCache(array $params = []): void
    {
        $this->requirePost();

        try {
            $this->requireAdmin();

            $cleared = [];

            // مسح إعدادات المؤقتة
            if (class_exists('AppDatabase') && property_exists('AppDatabase', 'settingsCache')) {
                AppDatabase::$settingsCache = [];
                $cleared[] = 'settings_cache';
            }

            // مسح ملفات التخزين المؤقت
            $cacheDir = dirname(__DIR__, 3) . '/cache';
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '/*');
                $count = 0;
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== '.gitkeep') {
                        @unlink($file);
                        $count++;
                    }
                }
                $cleared[] = "cache_files:{$count}";
            }

            // مسح ملفات السجلات القديمة
            $logsDir = dirname(__DIR__, 3) . '/logs';
            if (is_dir($logsDir)) {
                $oldLogs = glob($logsDir . '/*.log.*');
                $count = 0;
                foreach ($oldLogs as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                        $count++;
                    }
                }
                if ($count > 0) {
                    $cleared[] = "old_logs:{$count}";
                }
            }

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('cache_clear', 'مسح ذاكرة التخزين المؤقت عبر الموبايل: ' . implode(', ', $cleared));
            }

            ApiResponse::success([
                'cleared' => $cleared,
            ], 'تم مسح ذاكرة التخزين المؤقت');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSystemController::clearCache');
            }
            ApiResponse::serverError('خطأ في مسح ذاكرة التخزين المؤقت');
        }
    }

    /**
     * تنسيق حجم البايتات
     */
    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) return 'N/A';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
