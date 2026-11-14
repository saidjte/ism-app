<?php
/**
 * ===== نقطة دخول API الموبايل =====
 * يوجه كل طلبات API للهاتف عبر ApiRouter الموحد
 *
 * v5.14.0: إضافة متحكمات جديدة (Unit, Expiry, System, Batch)
 *
 * المسارات المتاحة:
 *   POST /api/mobile/auth/login           — تسجيل الدخول (عام)
 *   POST /api/mobile/auth/logout          — تسجيل الخروج (مصادقة)
 *   GET  /api/mobile/auth/me              — بيانات المستخدم (مصادقة)
 *   POST /api/mobile/auth/refresh         — تجديد الرمز (مصادقة)
 *   PUT  /api/mobile/auth/change-password — تغيير كلمة المرور (مصادقة)
 *
 *   GET    /api/mobile/products       — قائمة المنتجات (مصادقة)
 *   GET    /api/mobile/products/{id}  — تفاصيل منتج (مصادقة)
 *   POST   /api/mobile/products       — إضافة منتج (مصادقة + صلاحية)
 *   PUT    /api/mobile/products/{id}  — تعديل منتج (مصادقة + صلاحية)
 *   PATCH  /api/mobile/products/{id}  — تعديل منتج (مصادقة + صلاحية)
 *   DELETE /api/mobile/products/{id}  — حذف منتج (مصادقة + صلاحية)
 *
 *   GET    /api/mobile/sales          — قائمة المبيعات (مصادقة)
 *   GET    /api/mobile/sales/{id}     — تفاصيل بيع (مصادقة)
 *   POST   /api/mobile/sales          — تسجيل بيع (مصادقة + صلاحية)
 *   DELETE /api/mobile/sales/{id}     — حذف بيع (مصادقة + صلاحية)
 *
 *   GET    /api/mobile/purchases      — قائمة المشتريات (مصادقة)
 *   GET    /api/mobile/purchases/{id} — تفاصيل شراء (مصادقة)
 *   POST   /api/mobile/purchases      — تسجيل شراء (مصادقة + صلاحية)
 *   DELETE /api/mobile/purchases/{id} — حذف شراء (مصادقة + صلاحية)
 *
 *   GET    /api/mobile/reports/dashboard  — لوحة التحكم (مصادقة)
 *   GET    /api/mobile/reports/profits    — تقرير الأرباح (مصادقة)
 *   GET    /api/mobile/reports/stock      — تقرير المخزون (مصادقة)
 *   GET    /api/mobile/reports/expiry     — تقرير الصلاحية (مصادقة)
 *   GET    /api/mobile/reports/categories — التصنيفات (مصادقة)
 *
 *   GET    /api/mobile/categories          — قائمة التصنيفات (مصادقة)
 *   POST   /api/mobile/categories          — إضافة تصنيف (مصادقة + صلاحية)
 *   PUT    /api/mobile/categories/{id}     — تعديل تصنيف (مصادقة + صلاحية)
 *   DELETE /api/mobile/categories/{id}     — حذف تصنيف (مصادقة + صلاحية)
 *
 *   GET /api/mobile/dashboard/summary     — ملخص لوحة التحكم (مصادقة)
 *   GET /api/mobile/dashboard/chart-data  — بيانات الرسوم البيانية (مصادقة)
 *
 *   GET    /api/mobile/expenses           — قائمة المصروفات (مصادقة)
 *   POST   /api/mobile/expenses           — إضافة مصروف (مصادقة + صلاحية)
 *   DELETE /api/mobile/expenses/{id}      — حذف مصروف (مصادقة + صلاحية)
 *   GET    /api/mobile/expenses/categories — تصنيفات المصروفات (مصادقة)
 *
 *   GET /api/mobile/settings              — عرض الإعدادات (مصادقة)
 *   PUT /api/mobile/settings              — تحديث الإعدادات (مصادقة + مدير)
 *
 *   GET    /api/mobile/caisse/status                    — حالة الصندوق (مصادقة)
 *   GET    /api/mobile/caisse/history                   — سجل الصندوق (مصادقة)
 *   GET    /api/mobile/caisse/transactions              — حركات الصندوق (مصادقة)
 *   POST   /api/mobile/caisse/transactions              — إيداع/سحب (مصادقة + صلاحية)
 *   DELETE /api/mobile/caisse/transactions/{id}         — حذف حركة (مصادقة + صلاحية)
 *   POST   /api/mobile/caisse/project-amount           — مبلغ متوقع (مصادقة + مدير)
 *   POST   /api/mobile/caisse/actual-count              — عد فعلي (مصادقة + مدير)
 *
 *   GET    /api/mobile/notifications              — قائمة الإشعارات (مصادقة)
 *   GET    /api/mobile/notifications/unread-count  — عدد غير المقروءة (مصادقة)
 *   GET    /api/mobile/notifications/poll          — جلب جديد (مصادقة)
 *   POST   /api/mobile/notifications/mark-read     — تعيين مقروء (مصادقة)
 *   POST   /api/mobile/notifications/mark-all-read — تعيين الكل مقروء (مصادقة)
 *   DELETE /api/mobile/notifications/{id}          — حذف إشعار (مصادقة)
 *
 *   POST   /api/mobile/products/{id}/images  — رفع صورة منتج (مصادقة + صلاحية)
 *   GET    /api/mobile/products/{id}/images  — قائمة صور منتج (مصادقة)
 *   DELETE /api/mobile/images/{id}           — حذف صورة (مصادقة + صلاحية)
 *   POST   /api/mobile/images/{id}/set-primary — تعيين رئيسية (مصادقة + صلاحية)
 *
 *   GET  /api/mobile/users                     — قائمة المستخدمين (مصادقة + مدير)
 *   POST /api/mobile/users                     — إضافة مستخدم (مصادقة + مدير)
 *   PUT  /api/mobile/users/{id}                — تعديل مستخدم (مصادقة + مدير)
 *   POST /api/mobile/users/{id}/toggle         — تفعيل/تعطيل (مصادقة + مدير)
 *   POST /api/mobile/users/{id}/reset-password — إعادة كلمة المرور (مصادقة + مدير)
 *
 * المصادقة: رأس Authorization: Bearer <token>
 * لا يتطلب CSRF — الرموز بديل آمن
 *
 * v5.12.0: تحديث التوثيق ليشمل جميع نقاط النهاية المُنفَّذة
 */

// === ضمان إرجاع JSON دائماً حتى في حالة الخطأ ===
ob_start();

// === دالة تحميل آمنة — تمنع fatal_error عند فقدان ملف ===
function safeRequireOnce(string $path, string $label = ''): bool
{
    if (!file_exists($path)) {
        error_log("API mobile: ملف مفقود: {$path}" . ($label ? " ({$label})" : ''));
        return false;
    }
    try {
        require_once $path;
        return true;
    } catch (\Throwable $e) {
        error_log("API mobile: خطأ في تحميل {$path}: " . $e->getMessage());
        return false;
    }
}

// === تحميل الأنظمة الأساسية ===
try {
    require_once __DIR__ . '/../../includes/bootstrap.php';
} catch (\Throwable $e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => ['type' => 'bootstrap_error', 'message' => 'خطأ في تحميل النظام'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تنظيف أي output غير مرغوب من bootstrap
if (ob_get_level()) ob_clean();

// === إصلاح تلقائي لجدول api_tokens ===
// v4.41.0: تشغيل مرة واحدة فقط يومياً (تخزين مؤقت في ملف) لتجنب إبطاء كل طلب
// v6.3.6 (v4.44.0): إصلاح حرج — القائمة القديمة لم تشمل token_hash/token_name/scopes
//          مما كان يجعل خطأ "Column not found: token_hash" يستمر حتى بعد الإصلاح!
//          + تعبئة token_hash تلقائياً من token للصفوف القديمة (الرموز القديمة تبقى صالحة)
//          + لاحقة v2 في ملف العلامة لإجبار التشغيل الفوري بعد رفع الكود المحدّث
$migrationFlagFile = __DIR__ . '/../../logs/.api_tokens_migrated_v2_' . date('Y-m-d');
$needsMigration = !file_exists($migrationFlagFile);

if ($needsMigration) {
try {
    $db = AppDatabase::getInstance();
    $pdo = $db->getConnection();

    // فحص وجود الجدول أولاً
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
    $tableExists = !empty($tableCheck->fetch());

    if (!$tableExists) {
        // الجدول غير موجود — إنشاؤه بالكامل
        try {
            $pdo->exec("CREATE TABLE api_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(128) NOT NULL UNIQUE,
                token_hash VARCHAR(64) DEFAULT NULL,
                token_name VARCHAR(255) DEFAULT 'Mobile App',
                scopes VARCHAR(255) DEFAULT 'read,write',
                device_name VARCHAR(255) DEFAULT '',
                is_revoked TINYINT(1) DEFAULT 0,
                expires_at DATETIME DEFAULT NULL,
                last_used_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_api_tokens_user (user_id),
                INDEX idx_api_tokens_token (token),
                INDEX idx_api_tokens_revoked (is_revoked),
                INDEX idx_api_tokens_expires (expires_at),
                UNIQUE INDEX idx_api_tokens_hash (token_hash),
                CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            error_log("API auto-migration: created api_tokens table");
        } catch (\Throwable $e1) {
            // فشل مع FK — إنشاء بدون FK
            try {
                $pdo->exec("CREATE TABLE api_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(128) NOT NULL UNIQUE,
                    token_hash VARCHAR(64) DEFAULT NULL,
                    token_name VARCHAR(255) DEFAULT 'Mobile App',
                    scopes VARCHAR(255) DEFAULT 'read,write',
                    device_name VARCHAR(255) DEFAULT '',
                    is_revoked TINYINT(1) DEFAULT 0,
                    expires_at DATETIME DEFAULT NULL,
                    last_used_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_api_tokens_user (user_id),
                    INDEX idx_api_tokens_token (token),
                    INDEX idx_api_tokens_revoked (is_revoked),
                    INDEX idx_api_tokens_expires (expires_at),
                    UNIQUE INDEX idx_api_tokens_hash (token_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                error_log("API auto-migration: created api_tokens table (no FK)");
            } catch (\Throwable $e2) {
                error_log("API auto-migration: failed to create table: " . $e2->getMessage());
            }
        }
    } else {
        // الجدول موجود — كشف الأعمدة المفقودة وإضافتها
        $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
        $existingCols = [];
        while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingCols[] = $row['Field'];
        }

        // v6.3.6: أضيفت token_hash/token_name/scopes — كانت السبب في فشل تسجيل دخول الهاتف
        $requiredCols = ['id', 'user_id', 'token', 'token_hash', 'token_name', 'scopes', 'device_name', 'is_revoked', 'expires_at', 'last_used_at', 'created_at'];
        $missingCols = array_diff($requiredCols, $existingCols);

        if (!empty($missingCols)) {
            error_log("API auto-migration: missing columns detected: " . implode(', ', $missingCols));

            // عدد الصفوف الموجودة
            $rowCount = 0;
            try {
                $rc = $pdo->query("SELECT COUNT(*) as c FROM api_tokens")->fetch(PDO::FETCH_ASSOC);
                $rowCount = intval($rc['c'] ?? 0);
            } catch (\Throwable $e) {}

            // v4.40.0: إضافة الأعمدة WITHOUT AFTER — أبسط وأكثر موثوقية
            // AFTER يفشل إذا كان العمود المرجعي مفقوداً أو بترتيب مختلف
            $alterDefs = [
                'user_id'      => "ALTER TABLE api_tokens ADD COLUMN user_id INT NULL DEFAULT NULL",
                'token'        => "ALTER TABLE api_tokens ADD COLUMN token VARCHAR(128) NULL DEFAULT NULL",
                'token_hash'   => "ALTER TABLE api_tokens ADD COLUMN token_hash VARCHAR(64) NULL DEFAULT NULL",
                'token_name'   => "ALTER TABLE api_tokens ADD COLUMN token_name VARCHAR(255) DEFAULT 'Mobile App'",
                'scopes'       => "ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(255) DEFAULT 'read,write'",
                'device_name'  => "ALTER TABLE api_tokens ADD COLUMN device_name VARCHAR(255) DEFAULT ''",
                'is_revoked'   => "ALTER TABLE api_tokens ADD COLUMN is_revoked TINYINT(1) DEFAULT 0",
                'expires_at'   => "ALTER TABLE api_tokens ADD COLUMN expires_at DATETIME DEFAULT NULL",
                'last_used_at' => "ALTER TABLE api_tokens ADD COLUMN last_used_at DATETIME DEFAULT NULL",
                'created_at'   => "ALTER TABLE api_tokens ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            ];

            foreach ($missingCols as $col) {
                if (isset($alterDefs[$col])) {
                    try {
                        $pdo->exec($alterDefs[$col]);
                        error_log("API auto-migration: added column {$col}");
                    } catch (\Throwable $e) {
                        error_log("API auto-migration: failed to add column {$col}: " . $e->getMessage());
                    }
                }
            }

            // إذا كانت هناك صفوف موجودة وعمود token مفقود، نولّد قيم فريدة
            if ($rowCount > 0 && in_array('token', $missingCols)) {
                try {
                    $rows = $pdo->query("SELECT id FROM api_tokens WHERE token IS NULL")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $fakeHash = hash('sha256', 'legacy_' . $row['id'] . '_' . bin2hex(random_bytes(16)));
                        $stmt = $pdo->prepare("UPDATE api_tokens SET token = ? WHERE id = ?");
                        $stmt->execute([$fakeHash, intval($row['id'])]);
                    }
                    error_log("API auto-migration: populated token values for existing rows");
                } catch (\Throwable $e) {
                    error_log("API auto-migration: failed to populate tokens: " . $e->getMessage());
                }
            }

            // تعديل user_id و token إلى NOT NULL وإضافة UNIQUE على token
            if (in_array('user_id', $missingCols) && $rowCount == 0) {
                try { $pdo->exec("ALTER TABLE api_tokens MODIFY COLUMN user_id INT NOT NULL"); } catch (\Throwable $e) {}
            }
            if (in_array('token', $missingCols)) {
                try { $pdo->exec("ALTER TABLE api_tokens MODIFY COLUMN token VARCHAR(128) NOT NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_token (token)"); } catch (\Throwable $e) {}
            }

            // إضافة فهارس مفقودة
            $indexes = [
                'idx_api_tokens_user'    => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_user (user_id)',
                'idx_api_tokens_revoked' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_revoked (is_revoked)',
                'idx_api_tokens_expires' => 'ALTER TABLE api_tokens ADD INDEX idx_api_tokens_expires (expires_at)',
                'idx_api_tokens_hash'    => 'ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_hash (token_hash)',
            ];
            foreach ($indexes as $name => $sql) {
                try { $pdo->exec($sql); } catch (\Throwable $e) { /* فهرس موجود مسبقاً */ }
            }
        }

        // === v6.3.6: تعبئة token_hash للصفوف القديمة (تُنفَّذ دائماً — حتى بدون أعمدة مفقودة) ===
        // الرموز القديمة المخزّنة نصاً في token تبقى صالحة: token_hash = SHA2(token, 256)
        try {
            $backfilled = $pdo->exec("UPDATE api_tokens SET token_hash = SHA2(token, 256) WHERE token_hash IS NULL AND token IS NOT NULL AND token != ''");
            if ($backfilled > 0) {
                error_log("API auto-migration: backfilled token_hash for {$backfilled} legacy rows");
            }
        } catch (\Throwable $e) {
            error_log("API auto-migration: backfill token_hash failed: " . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    // الجدول قد لا يكون موجوداً — لا نوقف API
    error_log("API auto-migration check failed: " . $e->getMessage());
}

    // v4.41.0: إنشاء علامة أن التحقق تم لهذا اليوم
    @file_put_contents($migrationFlagFile, date('Y-m-d H:i:s'));
} // end if ($needsMigration)

// === تحميل ApiRouter ===
safeRequireOnce(__DIR__ . '/../../includes/ApiRouter.php', 'ApiRouter');

// === تحميل وسيط مصادقة الرموز ===
safeRequireOnce(__DIR__ . '/../../includes/Router/Middleware/TokenAuthMiddleware.php', 'TokenAuthMiddleware');

// === تحميل متحكمات الموبايل ===
// v5.11.3: استخدام safeRequireOnce لمنع fatal_error عند فقدان أي ملف
$missingControllers = [];

$controllerFiles = [
    'MobileAuthController'        => __DIR__ . '/../../includes/Controllers/Mobile/MobileAuthController.php',
    'MobileProductController'     => __DIR__ . '/../../includes/Controllers/Mobile/MobileProductController.php',
    'MobileSaleController'        => __DIR__ . '/../../includes/Controllers/Mobile/MobileSaleController.php',
    'MobilePurchaseController'    => __DIR__ . '/../../includes/Controllers/Mobile/MobilePurchaseController.php',
    'MobileReportController'      => __DIR__ . '/../../includes/Controllers/Mobile/MobileReportController.php',
    'MobileCategoryController'    => __DIR__ . '/../../includes/Controllers/Mobile/MobileCategoryController.php',
    'MobileDashboardController'   => __DIR__ . '/../../includes/Controllers/Mobile/MobileDashboardController.php',
    'MobileExpenseController'     => __DIR__ . '/../../includes/Controllers/Mobile/MobileExpenseController.php',
    'MobileSettingsController'    => __DIR__ . '/../../includes/Controllers/Mobile/MobileSettingsController.php',
    'MobileCaisseController'      => __DIR__ . '/../../includes/Controllers/Mobile/MobileCaisseController.php',
    'MobileNotificationController' => __DIR__ . '/../../includes/Controllers/Mobile/MobileNotificationController.php',
    'MobileProductImageController' => __DIR__ . '/../../includes/Controllers/Mobile/MobileProductImageController.php',
    'MobileUserController'        => __DIR__ . '/../../includes/Controllers/Mobile/MobileUserController.php',
    // v5.13.0: متحكمات جديدة لتغطية كاملة
    'MobilePriceController'       => __DIR__ . '/../../includes/Controllers/Mobile/MobilePriceController.php',
    'MobileStockController'       => __DIR__ . '/../../includes/Controllers/Mobile/MobileStockController.php',
    'MobileLabelController'       => __DIR__ . '/../../includes/Controllers/Mobile/MobileLabelController.php',
    'MobileExportController'      => __DIR__ . '/../../includes/Controllers/Mobile/MobileExportController.php',
    'MobileBackupController'      => __DIR__ . '/../../includes/Controllers/Mobile/MobileBackupController.php',
    'MobileAuditController'       => __DIR__ . '/../../includes/Controllers/Mobile/MobileAuditController.php',
    'MobilePosController'         => __DIR__ . '/../../includes/Controllers/Mobile/MobilePosController.php',
    'MobileWhatsAppController'    => __DIR__ . '/../../includes/Controllers/Mobile/MobileWhatsAppController.php',
    'MobilePushController'        => __DIR__ . '/../../includes/Controllers/Mobile/MobilePushController.php',
    'MobileApiTokenController'    => __DIR__ . '/../../includes/Controllers/Mobile/MobileApiTokenController.php',
    // v5.14.0: متحكمات إضافية
    'MobileUnitController'        => __DIR__ . '/../../includes/Controllers/Mobile/MobileUnitController.php',
    'MobileExpiryController'      => __DIR__ . '/../../includes/Controllers/Mobile/MobileExpiryController.php',
    'MobileSystemController'      => __DIR__ . '/../../includes/Controllers/Mobile/MobileSystemController.php',
    'MobileBatchController'       => __DIR__ . '/../../includes/Controllers/Mobile/MobileBatchController.php',
];

foreach ($controllerFiles as $name => $path) {
    if (!safeRequireOnce($path, $name)) {
        $missingControllers[] = $name;
    }
}

// === تسجيل المسارات ===
$router = new ApiRouter();

// ───────────────────────────────────────────
//  نقاط نهاية عامة (بدون مصادقة)
// ───────────────────────────────────────────

$router->group('', function($router) {

    // تسجيل الدخول — الحصول على رمز API
    if (class_exists('MobileAuthController')) {
        $router->post('auth/login', [MobileAuthController::class, 'login']);
    }

});

// ───────────────────────────────────────────
//  نقاط نهاية تتطلب مصادقة بالرموز
// ───────────────────────────────────────────

$router->middleware(function($params) {
    try {
        return TokenAuthMiddleware::handle();
    } catch (\Throwable $e) {
        // v5.11.3: التقاط أي خطأ في الوسيط ومنع fatal_error
        if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
            ErrorHandler::logException($e, 'TokenAuthMiddleware');
        }
        if (class_exists('ApiResponse')) {
            ApiResponse::serverError('خطأ في التحقق من المصادقة');
        }
        return false;
    }
})->group('', function($router) use ($missingControllers) {

    // ===== المصادقة =====
    if (class_exists('MobileAuthController')) {
        $router->post('auth/logout', [MobileAuthController::class, 'logout']);
        $router->get('auth/me', [MobileAuthController::class, 'me']);
        $router->post('auth/refresh', [MobileAuthController::class, 'refresh']);
        $router->put('auth/change-password', [MobileAuthController::class, 'changePassword']);
    }

    // ===== المنتجات =====
    if (class_exists('MobileProductController')) {
        $router->get('products', [MobileProductController::class, 'index']);
        $router->get('products/search', [MobileProductController::class, 'search']);
        $router->get('products/{id:[0-9]+}', [MobileProductController::class, 'show']);
        $router->post('products', [MobileProductController::class, 'store']);
        $router->put('products/{id:[0-9]+}', [MobileProductController::class, 'update']);
        $router->patch('products/{id:[0-9]+}', [MobileProductController::class, 'update']);
        $router->delete('products/{id:[0-9]+}', [MobileProductController::class, 'destroy']);
        // v5.13.0: وحدات إضافية
        $router->get('products/{id:[0-9]+}/units', [MobileProductController::class, 'units']);
        $router->get('products/{id:[0-9]+}/purchase-units', [MobileProductController::class, 'purchaseUnits']);
    }

    // ===== المبيعات =====
    if (class_exists('MobileSaleController')) {
        $router->get('sales', [MobileSaleController::class, 'index']);
        $router->get('sales/{id:[0-9]+}', [MobileSaleController::class, 'show']);
        $router->post('sales', [MobileSaleController::class, 'store']);
        $router->put('sales/{id:[0-9]+}', [MobileSaleController::class, 'update']); // v5.13.0
        $router->delete('sales/{id:[0-9]+}', [MobileSaleController::class, 'destroy']);
    }

    // ===== المشتريات =====
    if (class_exists('MobilePurchaseController')) {
        $router->get('purchases', [MobilePurchaseController::class, 'index']);
        $router->get('purchases/{id:[0-9]+}', [MobilePurchaseController::class, 'show']);
        $router->post('purchases', [MobilePurchaseController::class, 'store']);
        $router->put('purchases/{id:[0-9]+}', [MobilePurchaseController::class, 'update']); // v5.13.0
        $router->delete('purchases/{id:[0-9]+}', [MobilePurchaseController::class, 'destroy']);
    }

    // ===== التقارير =====
    if (class_exists('MobileReportController')) {
        $router->get('reports/dashboard', [MobileReportController::class, 'dashboard']);
        $router->get('reports/profits', [MobileReportController::class, 'profits']);
        $router->get('reports/stock', [MobileReportController::class, 'stockReport']);
        $router->get('reports/expiry', [MobileReportController::class, 'expiryReport']);
        $router->get('reports/categories', [MobileReportController::class, 'categories']);
        // v5.13.0: تقارير جديدة
        $router->get('reports/sales', [MobileReportController::class, 'sales']);
        $router->get('reports/purchases', [MobileReportController::class, 'purchases']);
        $router->get('reports/comparison', [MobileReportController::class, 'comparison']);
    }

    // ===== التصنيفات =====
    if (class_exists('MobileCategoryController')) {
        $router->get('categories', [MobileCategoryController::class, 'index']);
        $router->post('categories', [MobileCategoryController::class, 'store']);
        $router->put('categories/{id:[0-9]+}', [MobileCategoryController::class, 'update']);
        $router->delete('categories/{id:[0-9]+}', [MobileCategoryController::class, 'delete']);
    }

    // ===== لوحة التحكم =====
    if (class_exists('MobileDashboardController')) {
        $router->get('dashboard/summary', [MobileDashboardController::class, 'summary']);
        $router->get('dashboard/stats', [MobileDashboardController::class, 'stats']);           // v5.15.0: إحصائيات شاملة + مقارنة فترات
        $router->get('dashboard/chart-data', [MobileDashboardController::class, 'chartData']);
        $router->get('dashboard/monthly', [MobileDashboardController::class, 'monthly']);       // v5.15.0: إحصائيات شهرية
        $router->get('dashboard/recent', [MobileDashboardController::class, 'recent']);         // v5.15.0: آخر العناصر
        $router->get('dashboard/alerts', [MobileDashboardController::class, 'alerts']);              // v5.15.1: تنبيهات صلاحية + مخزون منخفض (مُجمّعة)
        $router->get('dashboard/expiry-alerts', [MobileDashboardController::class, 'expiryAlerts']);    // v5.16.1: تنبيهات الصلاحية فقط
        $router->get('dashboard/low-stock-alerts', [MobileDashboardController::class, 'lowStockAlerts']); // v5.16.1: تنبيهات المخزون المنخفض فقط
        $router->get('dashboard/cards', [MobileDashboardController::class, 'cards']);                  // v5.16.0: بطاقات لوحة التحكم
        $router->get('dashboard/top-products', [MobileDashboardController::class, 'topProducts']); // v5.16.0: أفضل المنتجات مبيعاً
    }

    // ===== المصروفات =====
    if (class_exists('MobileExpenseController')) {
        $router->get('expenses', [MobileExpenseController::class, 'index']);
        $router->post('expenses', [MobileExpenseController::class, 'store']);
        $router->put('expenses/{id:[0-9]+}', [MobileExpenseController::class, 'update']); // v5.13.0
        $router->delete('expenses/{id:[0-9]+}', [MobileExpenseController::class, 'delete']);
        $router->get('expenses/categories', [MobileExpenseController::class, 'categories']);
    }

    // ===== الإعدادات =====
    if (class_exists('MobileSettingsController')) {
        $router->get('settings', [MobileSettingsController::class, 'index']);
        $router->put('settings', [MobileSettingsController::class, 'update']);
    }

    // ===== الصندوق =====
    if (class_exists('MobileCaisseController')) {
        $router->get('caisse/status', [MobileCaisseController::class, 'status']);
        $router->get('caisse/history', [MobileCaisseController::class, 'history']);
        $router->get('caisse/transactions', [MobileCaisseController::class, 'transactions']);
        $router->post('caisse/transactions', [MobileCaisseController::class, 'storeTransaction']);
        $router->delete('caisse/transactions/{id:[0-9]+}', [MobileCaisseController::class, 'deleteTransaction']);
        $router->post('caisse/project-amount', [MobileCaisseController::class, 'projectAmount']);
        $router->post('caisse/actual-count', [MobileCaisseController::class, 'actualCount']);
    }

    // ===== الإشعارات =====
    if (class_exists('MobileNotificationController')) {
        $router->get('notifications', [MobileNotificationController::class, 'index']);
        $router->get('notifications/unread-count', [MobileNotificationController::class, 'unreadCount']);
        $router->get('notifications/poll', [MobileNotificationController::class, 'poll']);
        $router->post('notifications/mark-read', [MobileNotificationController::class, 'markRead']);
        $router->post('notifications/mark-all-read', [MobileNotificationController::class, 'markAllRead']);
        $router->delete('notifications/{id:[0-9]+}', [MobileNotificationController::class, 'delete']);
    }

    // ===== صور المنتجات =====
    if (class_exists('MobileProductImageController')) {
        $router->post('products/{id:[0-9]+}/images', [MobileProductImageController::class, 'upload']);
        $router->get('products/{id:[0-9]+}/images', [MobileProductImageController::class, 'list']);
        $router->delete('images/{id:[0-9]+}', [MobileProductImageController::class, 'delete']);
        $router->post('images/{id:[0-9]+}/set-primary', [MobileProductImageController::class, 'setPrimary']);
    }

    // ===== المستخدمين =====
    if (class_exists('MobileUserController')) {
        $router->get('users', [MobileUserController::class, 'index']);
        $router->post('users', [MobileUserController::class, 'store']);
        $router->put('users/{id:[0-9]+}', [MobileUserController::class, 'update']);
        $router->post('users/{id:[0-9]+}/toggle', [MobileUserController::class, 'toggle']);
        $router->post('users/{id:[0-9]+}/reset-password', [MobileUserController::class, 'resetPassword']);
    }

    // ───────────────────────────────────────────
    //  v5.13.0: نقاط نهاية جديدة — تغطية كاملة
    // ───────────────────────────────────────────

    // ===== الأسعار =====
    if (class_exists('MobilePriceController')) {
        $router->get('prices/list', [MobilePriceController::class, 'priceList']);
        $router->get('prices/history', [MobilePriceController::class, 'priceHistory']);
        $router->get('prices/history/{id:[0-9]+}', [MobilePriceController::class, 'productPriceHistory']);
    }

    // ===== المخزون =====
    if (class_exists('MobileStockController')) {
        $router->post('stock/transfer', [MobileStockController::class, 'transfer']);
        $router->post('stock/inventory-count', [MobileStockController::class, 'saveInventoryCount']);
        $router->post('stock/update-qty', [MobileStockController::class, 'updateQty']);
        $router->get('stock/inventory-data', [MobileStockController::class, 'inventoryData']);
        $router->get('stock/last-count', [MobileStockController::class, 'lastCount']);
    }

    // ===== بطاقات المنتجات =====
    if (class_exists('MobileLabelController')) {
        $router->get('labels/data', [MobileLabelController::class, 'data']);
        $router->post('labels/generate', [MobileLabelController::class, 'generate']);
    }

    // ===== التصدير =====
    if (class_exists('MobileExportController')) {
        $router->get('export/csv/{type}', [MobileExportController::class, 'csv']);
        $router->get('export/pdf/{type}', [MobileExportController::class, 'pdf']);
        $router->get('export/data/{type}', [MobileExportController::class, 'data']);
    }

    // ===== النسخ الاحتياطية =====
    if (class_exists('MobileBackupController')) {
        $router->get('backups', [MobileBackupController::class, 'index']);
        $router->post('backups/create', [MobileBackupController::class, 'create']);
        $router->post('backups/delete', [MobileBackupController::class, 'delete']);
        $router->post('backups/restore', [MobileBackupController::class, 'restore']);
        $router->get('backups/download', [MobileBackupController::class, 'download']);
        $router->get('backups/status', [MobileBackupController::class, 'status']);
    }

    // ===== التدقيق والسجلات =====
    if (class_exists('MobileAuditController')) {
        $router->get('audit/logs', [MobileAuditController::class, 'logs']);
        $router->get('audit/error-logs', [MobileAuditController::class, 'errorLogs']);
        $router->get('audit/stats', [MobileAuditController::class, 'stats']);
    }

    // ===== نقطة البيع =====
    if (class_exists('MobilePosController')) {
        $router->get('pos/products', [MobilePosController::class, 'products']);
        $router->get('pos/product-images', [MobilePosController::class, 'productImages']);
        $router->post('pos/checkout', [MobilePosController::class, 'checkout']);
        $router->get('pos/settings', [MobilePosController::class, 'settings']);
        $router->get('pos/categories', [MobilePosController::class, 'categories']);
    }

    // ===== واتساب =====
    if (class_exists('MobileWhatsAppController')) {
        $router->post('whatsapp/send-alert', [MobileWhatsAppController::class, 'sendAlert']);
        $router->post('whatsapp/test', [MobileWhatsAppController::class, 'test']);
        $router->get('whatsapp/status', [MobileWhatsAppController::class, 'status']);
    }

    // ===== الإشعارات الفورية =====
    if (class_exists('MobilePushController')) {
        $router->post('push/subscribe', [MobilePushController::class, 'subscribe']);
        $router->post('push/unsubscribe', [MobilePushController::class, 'unsubscribe']);
        $router->get('push/status', [MobilePushController::class, 'status']);
        $router->post('push/permission', [MobilePushController::class, 'permission']);
    }

    // ===== رموز API =====
    if (class_exists('MobileApiTokenController')) {
        $router->get('tokens', [MobileApiTokenController::class, 'index']);
        $router->post('tokens/create', [MobileApiTokenController::class, 'create']);
        $router->post('tokens/revoke/{id:[0-9]+}', [MobileApiTokenController::class, 'revoke']);
        $router->delete('tokens/{id:[0-9]+}', [MobileApiTokenController::class, 'delete']);
    }

    // ───────────────────────────────────────────
    //  v5.14.0: متحكمات إضافية — وحدات القياس / الصلاحية / النظام / الدفعات
    // ───────────────────────────────────────────

    // ===== وحدات القياس =====
    if (class_exists('MobileUnitController')) {
        $router->get('units', [MobileUnitController::class, 'index']);
        $router->get('units/{id:[0-9]+}', [MobileUnitController::class, 'show']);
        $router->post('units', [MobileUnitController::class, 'store']);
        $router->put('units/{id:[0-9]+}', [MobileUnitController::class, 'update']);
        $router->delete('units/{id:[0-9]+}', [MobileUnitController::class, 'delete']);
    }

    // ===== صلاحية المنتجات =====
    if (class_exists('MobileExpiryController')) {
        $router->get('expiry/report', [MobileExpiryController::class, 'report']);
        $router->get('expiry/alerts', [MobileExpiryController::class, 'alerts']);
        $router->get('expiry/tracking', [MobileExpiryController::class, 'tracking']);   // v5.16.0: تتبع الصلاحية المتقدم
        $router->get('expiry/timeline', [MobileExpiryController::class, 'timeline']);   // v5.16.0: جدول زمني للصلاحيات
        $router->post('expiry/dismiss', [MobileExpiryController::class, 'dismiss']);
        $router->get('expiry/dismissed', [MobileExpiryController::class, 'dismissed']);
        $router->post('expiry/batch-update', [MobileExpiryController::class, 'batchUpdate']);
    }

    // ===== إدارة النظام =====
    if (class_exists('MobileSystemController')) {
        $router->get('system/info', [MobileSystemController::class, 'info']);
        $router->get('system/db-status', [MobileSystemController::class, 'dbStatus']);
        $router->post('system/test-db', [MobileSystemController::class, 'testDb']);
        $router->put('system/db-settings', [MobileSystemController::class, 'dbSettings']);
        $router->get('system/changelog', [MobileSystemController::class, 'changelog']);
        $router->get('system/permissions', [MobileSystemController::class, 'permissions']);
        $router->get('system/version', [MobileSystemController::class, 'version']);
        $router->post('system/clear-cache', [MobileSystemController::class, 'clearCache']);
    }

    // ===== دفعات المنتجات =====
    if (class_exists('MobileBatchController')) {
        $router->get('products/{id:[0-9]+}/batches', [MobileBatchController::class, 'index']);
        $router->post('batches', [MobileBatchController::class, 'store']);
        $router->put('batches/{id:[0-9]+}', [MobileBatchController::class, 'update']);
        $router->delete('batches/{id:[0-9]+}', [MobileBatchController::class, 'delete']);
        $router->get('batches/expiring', [MobileBatchController::class, 'expiring']);
    }

});

// === تنفيذ التوجيه ===
try {
    $router->dispatch();
} catch (\Throwable $e) {
    // v5.11.3: التقاط أي خطأ غير متوقع في التوجيه
    if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
        ErrorHandler::logException($e, 'mobile-index::dispatch');
    }

    if (ob_get_level()) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $isDebug = defined('APP_DEBUG') && APP_DEBUG === true;
    echo json_encode([
        'success' => false,
        'error'   => [
            'type'    => 'router_error',
            'message' => $isDebug ? $e->getMessage() : 'حدث خطأ داخلي في الخادم. يرجى المحاولة لاحقاً.',
            'details' => $isDebug ? [
                'exception' => get_class($e),
                'file'      => basename($e->getFile()) . ':' . $e->getLine(),
            ] : [],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
