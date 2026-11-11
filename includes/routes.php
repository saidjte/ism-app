<?php
/**
 * ===== تعريفات المسارات =====
 * يحدد كل مسارات التطبيق في مكان واحد
 * 
 * ملاحظة: هذا الملف اختياري — التطبيق يعمل بدون نظام التوجيه
 * يمكن تفعيله تدريجياً بنقل مسار واحد في كل مرة
 * 
 * لتفعيل نظام التوجيه، أضف في بداية ملف .htaccess أو index.php:
 *   require_once __DIR__ . '/includes/Router/Router.php';
 *   require_once __DIR__ . '/includes/Router/Middleware/AuthMiddleware.php';
 *   require_once __DIR__ . '/includes/Router/Middleware/CsrfMiddleware.php';
 *   require_once __DIR__ . '/includes/routes.php';
 *   $result = Router::dispatch();
 *   if ($result !== null) { return; } // تم التعامل مع الطلب
 *   // وإلا نتابع بالطريقة القديمة
 */

// تحميل نظام التوجيه والوسطاء إذا لم يكونا محملين
if (!class_exists('Router')) {
    require_once __DIR__ . '/Router/Router.php';
}
if (!class_exists('AuthMiddleware')) {
    require_once __DIR__ . '/Router/Middleware/AuthMiddleware.php';
}
if (!class_exists('CsrfMiddleware')) {
    require_once __DIR__ . '/Router/Middleware/CsrfMiddleware.php';
}

// ===== تسجيل الوسطاء المخصصين =====

// وسيط تسجيل الدخول
Router::middleware('auth', function() {
    return AuthMiddleware::handle();
});

// وسيط صلاحية المدير
Router::middleware('admin', function() {
    return AuthMiddleware::admin();
});

// وسيط حماية CSRF
Router::middleware('csrf', function() {
    return CsrfMiddleware::handle();
});

// وسيط الصلاحيات (يُستخدم: permission:products.add)
Router::middleware('permission', function($permission = '') {
    if (empty($permission)) return false;
    $handler = AuthMiddleware::permission($permission);
    return (bool) call_user_func($handler);
});


// ===================================================================
// ===== المسارات التي لا تتطلب مصادقة =====
// ===================================================================

// تسجيل الدخول
Router::get('/login', function() { include __DIR__ . '/../login.php'; });
Router::post('/login', function() { include __DIR__ . '/../login.php'; });

// تسجيل الخروج
Router::get('/logout', function() { include __DIR__ . '/../logout.php'; });
Router::post('/logout', function() { include __DIR__ . '/../logout.php'; });

// التثبيت
Router::get('/install', function() { include __DIR__ . '/../install.php'; });
Router::post('/install', function() { include __DIR__ . '/../install.php'; });

// إلغاء التثبيت
Router::get('/uninstall', function() { include __DIR__ . '/../uninstall.php'; });
Router::post('/uninstall', function() { include __DIR__ . '/../uninstall.php'; });

// الترقية
Router::get('/upgrade', function() { include __DIR__ . '/../upgrade.php'; });
Router::post('/upgrade', function() { include __DIR__ . '/../upgrade.php'; });

// النشر
Router::get('/deployer', function() { include __DIR__ . '/../deployer.php'; });
Router::post('/deployer', function() { include __DIR__ . '/../deployer.php'; });


// ===================================================================
// ===== المسارات التي تتطلب مصادقة =====
// ===================================================================

Router::group(['middleware' => ['auth']], function() {

    // ===== لوحة التحكم =====
    Router::get('/', function() { include __DIR__ . '/../index.php'; });
    Router::get('/index', function() { include __DIR__ . '/../index.php'; });

    // ===== نقطة البيع (POS) =====
    Router::get('/pos', function() { include __DIR__ . '/../pos.php'; }, ['permission:caisse.manage']);
    Router::get('/caisse', function() { include __DIR__ . '/../caisse.php'; }, ['permission:caisse.view']);

    // ===== المنتجات =====
    Router::group(['prefix' => 'products'], function() {
        Router::get('/list', function() { include __DIR__ . '/../products/list-products.php'; }, ['permission:products.view']);
        Router::get('/add', function() { include __DIR__ . '/../products/add-product.php'; }, ['permission:products.add']);
        Router::post('/add', function() { include __DIR__ . '/../products/add-product.php'; }, ['csrf', 'permission:products.add']);
        Router::get('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../products/edit-product.php';
        }, ['permission:products.edit']);
        Router::post('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../products/edit-product.php';
        }, ['csrf', 'permission:products.edit']);
        Router::get('/view/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../products/view-product.php';
        }, ['permission:products.view']);
        Router::get('/price-list', function() { include __DIR__ . '/../products/price-list.php'; }, ['permission:prices.view']);
        Router::get('/price-history/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../products/price-history.php';
        }, ['permission:prices.view']);
        Router::get('/print-labels', function() { include __DIR__ . '/../products/print-labels.php'; }, ['permission:labels.print']);
        Router::get('/stock-transfer', function() { include __DIR__ . '/../products/stock-transfer.php'; }, ['permission:stock.transfer']);
        Router::post('/stock-transfer', function() { include __DIR__ . '/../products/stock-transfer.php'; }, ['csrf', 'permission:stock.transfer']);
        Router::get('/inventory-count', function() { include __DIR__ . '/../products/inventory-count.php'; }, ['permission:stock.inventory_count']);
        Router::post('/inventory-count', function() { include __DIR__ . '/../products/inventory-count.php'; }, ['csrf', 'permission:stock.inventory_count']);
        Router::get('/expiry-report', function() { include __DIR__ . '/../products/expiry-report.php'; }, ['permission:expiry.view']);
    });

    // ===== المبيعات =====
    Router::group(['prefix' => 'sales'], function() {
        Router::get('/add', function() { include __DIR__ . '/../sales/add-sale.php'; }, ['permission:sales.add']);
        Router::post('/add', function() { include __DIR__ . '/../sales/add-sale.php'; }, ['csrf', 'permission:sales.add']);
        Router::get('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../sales/edit-sale.php';
        }, ['permission:sales.edit']);
        Router::post('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../sales/edit-sale.php';
        }, ['csrf', 'permission:sales.edit']);
        Router::get('/delete/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../sales/delete-sale.php';
        }, ['permission:sales.delete']);
        Router::post('/delete/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../sales/delete-sale.php';
        }, ['csrf', 'permission:sales.delete']);
        Router::get('/calculate-profits', function() { include __DIR__ . '/../sales/calculate-profits.php'; }, ['permission:reports.profits']);
    });

    // ===== المشتريات =====
    Router::group(['prefix' => 'purchases'], function() {
        Router::get('/add', function() { include __DIR__ . '/../purchases/add-purchase.php'; }, ['permission:purchases.add']);
        Router::post('/add', function() { include __DIR__ . '/../purchases/add-purchase.php'; }, ['csrf', 'permission:purchases.add']);
        Router::get('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../purchases/edit-purchase.php';
        }, ['permission:purchases.edit']);
        Router::post('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../purchases/edit-purchase.php';
        }, ['csrf', 'permission:purchases.edit']);
        Router::get('/delete/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../purchases/delete-purchase.php';
        }, ['permission:purchases.delete']);
        Router::post('/delete/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../purchases/delete-purchase.php';
        }, ['csrf', 'permission:purchases.delete']);
    });

    // ===== المصاريف =====
    Router::group(['prefix' => 'expenses'], function() {
        Router::get('/list', function() { include __DIR__ . '/../expenses/list-expenses.php'; }, ['permission:expenses.view']);
        Router::get('/add', function() { include __DIR__ . '/../expenses/add-expense.php'; }, ['permission:expenses.add']);
        Router::post('/add', function() { include __DIR__ . '/../expenses/add-expense.php'; }, ['csrf', 'permission:expenses.add']);
    });

    // ===== التصنيفات =====
    Router::group(['prefix' => 'categories'], function() {
        Router::get('/list', function() { include __DIR__ . '/../categories/list-categories.php'; }, ['permission:categories.view']);
    });

    // ===== المستخدمون =====
    Router::group(['prefix' => 'users'], function() {
        Router::get('/list', function() { include __DIR__ . '/../users/list-users.php'; }, ['permission:users.view']);
        Router::get('/add', function() { include __DIR__ . '/../users/add-user.php'; }, ['permission:users.add']);
        Router::post('/add', function() { include __DIR__ . '/../users/add-user.php'; }, ['csrf', 'permission:users.add']);
        Router::get('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../users/edit-user.php';
        }, ['permission:users.edit']);
        Router::post('/edit/{id:\d+}', function($params) {
            $_GET['id'] = $params['id'];
            include __DIR__ . '/../users/edit-user.php';
        }, ['csrf', 'permission:users.edit']);
        Router::get('/change-password', function() { include __DIR__ . '/../users/change-password.php'; });
        Router::post('/change-password', function() { include __DIR__ . '/../users/change-password.php'; }, ['csrf']);
    });

    // ===== التقارير =====
    Router::group(['prefix' => 'reports'], function() {
        Router::get('/sales', function() { include __DIR__ . '/../reports/sales-report.php'; }, ['permission:reports.view']);
        Router::get('/purchases', function() { include __DIR__ . '/../reports/purchases-report.php'; }, ['permission:reports.view']);
        Router::get('/profits', function() { include __DIR__ . '/../reports/profit-report.php'; }, ['permission:reports.profits']);
        Router::get('/period-comparison', function() { include __DIR__ . '/../reports/period-comparison.php'; }, ['permission:reports.view']);
    });

    // ===== الإعدادات =====
    Router::group(['prefix' => 'settings'], function() {
        Router::get('/system', function() { include __DIR__ . '/../settings/system-settings.php'; }, ['permission:settings.manage']);
        Router::post('/system', function() { include __DIR__ . '/../settings/system-settings.php'; }, ['csrf', 'permission:settings.manage']);
    });

    // ===== النسخ الاحتياطي =====
    Router::group(['prefix' => 'backups'], function() {
        Router::get('/list', function() { include __DIR__ . '/../backups/list-backups.php'; }, ['permission:backups.view']);
    });

    // ===== الإشعارات =====
    Router::group(['prefix' => 'notifications'], function() {
        Router::get('/', function() { include __DIR__ . '/../notifications/index.php'; });
    });

    // ===== الإدارة =====
    Router::group(['prefix' => 'admin', 'middleware' => ['admin']], function() {
        Router::get('/audit-log', function() { include __DIR__ . '/../admin/audit-log.php'; });
        Router::get('/view-logs', function() { include __DIR__ . '/../admin/view-logs.php'; });
    });

    // ===== واجهة برمجة التطبيقات (API) =====
    Router::group(['prefix' => 'api'], function() {
        // بحث المنتجات (لا يتطلب CSRF لأنه GET)
        Router::get('/search-products', function() { include __DIR__ . '/../api/search_products.php'; }, ['permission:products.view']);

        // إشعارات
        Router::get('/notifications', function() { include __DIR__ . '/../api/notifications.php'; });

        // عمليات POST تتطلب CSRF
        Router::post('/pos-process-sale', function() { include __DIR__ . '/../api/pos-process-sale.php'; }, ['csrf', 'permission:sales.add']);
        Router::post('/push-subscribe', function() { include __DIR__ . '/../api/push-subscribe.php'; }, ['csrf']);

        // وحدات القياس
        Router::get('/get-units', function() { include __DIR__ . '/../api/get_units.php'; }, ['permission:units.view']);
        Router::get('/get-purchase-units', function() { include __DIR__ . '/../api/get_purchase_units.php'; }, ['permission:purchases.view']);
        Router::get('/get-batches', function() { include __DIR__ . '/../api/get_batches.php'; }, ['permission:products.view']);

        // التصدير
        Router::get('/export', function() { include __DIR__ . '/../api/export.php'; }, ['permission:export.data']);

        // إغلاق نافذة الصلاحية
        Router::post('/dismiss-expiry-popup', function() { include __DIR__ . '/../api/dismiss-expiry-popup.php'; }, ['csrf']);

        // واتساب
        Router::get('/test-ultramsg', function() { include __DIR__ . '/../api/test-ultramsg.php'; }, ['admin']);
        Router::post('/send-whatsapp-alert', function() { include __DIR__ . '/../api/send-whatsapp-alert.php'; }, ['csrf', 'admin']);
    });

    // ===== سجل التغييرات =====
    Router::get('/changelog', function() { include __DIR__ . '/../changelog.php'; });

}); // نهاية مجموعة المسارات المصادقة
