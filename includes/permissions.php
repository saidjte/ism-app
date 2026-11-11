<?php
/**
 * ========================================================================
 *  نظام الصلاحيات المفصّل
 *  يحدد ما يمكن لكل دور (admin, manager, user) فعله في النظام
 *
 *  الاستخدام:
 *    requirePermission('products.add');   // يوقف التنفيذ إذا لم تكن الصلاحية متاحة
 *    hasPermission('products.add');       // يرجع true/false
 * ========================================================================
 */

// منع التحميل المزدوج
if (defined('PERMISSIONS_LOADED')) return;
define('PERMISSIONS_LOADED', true);

/**
 * مصفوفة الصلاحيات لكل دور
 *
 * كل صلاحية = قسم.عملية
 * الأقسام: products, sales, purchases, expenses, categories, units,
 *          stock, reports, caisse, settings, users, backups, audit, export
 * العمليات: view, add, edit, delete
 */
function getRolePermissions(): array {
    return [
        // ===== مدير النظام (admin): صلاحية كاملة =====
        'admin' => [
            // المنتجات
            'products.view', 'products.add', 'products.edit', 'products.delete',
            // المبيعات
            'sales.view', 'sales.add', 'sales.edit', 'sales.delete',
            // المشتريات
            'purchases.view', 'purchases.add', 'purchases.edit', 'purchases.delete',
            // المصاريف
            'expenses.view', 'expenses.add', 'expenses.edit', 'expenses.delete',
            // التصنيفات
            'categories.view', 'categories.add', 'categories.edit', 'categories.delete',
            // وحدات القياس
            'units.view', 'units.add', 'units.edit', 'units.delete',
            // المخزون
            'stock.transfer', 'stock.inventory_count',
            // الصندوق
            'caisse.view', 'caisse.manage',
            // التقارير
            'reports.view', 'reports.profits',
            // التصدير
            'export.data',
            // الإعدادات
            'settings.manage',
            // المستخدمون
            'users.view', 'users.add', 'users.edit', 'users.delete',
            // النسخ الاحتياطي
            'backups.view', 'backups.create', 'backups.restore', 'backups.delete',
            // سجلات المراجعة والنظام
            'audit.view', 'logs.view',
            // الترقية وإلغاء التثبيت
            'system.upgrade', 'system.uninstall',
            // أسعار وطباعة
            'prices.view', 'prices.edit', 'labels.print',
            // صلاحية المنتجات
            'expiry.view',
        ],

        // ===== مدير (manager): إدارة البيانات بدون وصول للإعدادات =====
        'manager' => [
            // المنتجات
            'products.view', 'products.add', 'products.edit', 'products.delete',
            // المبيعات
            'sales.view', 'sales.add', 'sales.edit', 'sales.delete',
            // المشتريات
            'purchases.view', 'purchases.add', 'purchases.edit', 'purchases.delete',
            // المصاريف
            'expenses.view', 'expenses.add', 'expenses.edit', 'expenses.delete',
            // التصنيفات — عرض فقط
            'categories.view',
            // وحدات القياس — عرض فقط
            'units.view',
            // المخزون
            'stock.transfer', 'stock.inventory_count',
            // الصندوق
            'caisse.view', 'caisse.manage',
            // التقارير
            'reports.view', 'reports.profits',
            // التصدير
            'export.data',
            // أسعار وطباعة
            'prices.view', 'labels.print',
            // صلاحية المنتجات
            'expiry.view',
        ],

        // ===== مستخدم عادي (user): عرض وتسجيل المبيعات فقط =====
        'user' => [
            // المنتجات — عرض فقط
            'products.view',
            // المبيعات — عرض وتسجيل
            'sales.view', 'sales.add',
            // الصندوق — عرض فقط
            'caisse.view',
            // أسعار — عرض فقط
            'prices.view',
            // صلاحية المنتجات — عرض فقط
            'expiry.view',
        ],
    ];
}

/**
 * التحقق من امتلاك المستخدم الحالي لصلاحية معينة
 *
 * @param string $permission اسم الصلاحية (مثال: 'products.add')
 * @return bool
 */
function hasPermission(string $permission): bool {
    if (!isset($_SESSION['user_role'])) return false;

    $role = $_SESSION['user_role'];
    $all_permissions = getRolePermissions();

    if (!isset($all_permissions[$role])) return false;

    // admin يملك كل الصلاحيات تلقائياً
    if ($role === 'admin') return true;

    return in_array($permission, $all_permissions[$role], true);
}

/**
 * طلب صلاحية — يوقف التنفيذ إذا لم تكن متاحة
 *
 * @param string $permission اسم الصلاحية المطلوبة
 * @param string $message رسالة مخصصة (اختياري)
 */
function requirePermission(string $permission, string $message = ''): void {
    // التأكد من تسجيل الدخول أولاً
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        $base_url = '';
        $script_dir = dirname($_SERVER['SCRIPT_NAME']);
        if (in_array(basename($script_dir), ['products', 'purchases', 'sales', 'api', 'users', 'settings', 'expenses', 'units', 'categories', 'backups', 'reports', 'admin', 'notifications'])) {
            $base_url = rtrim(dirname($script_dir), '/');
        } else {
            $base_url = rtrim($script_dir, '/');
        }
        header("Location: " . $base_url . "/login.php");
        exit;
    }

    if (hasPermission($permission)) return;

    // الصلاحية غير متاحة — عرض صفحة 403
    http_response_code(403);

    $role_name = function_exists('getRoleName') ? getRoleName($_SESSION['user_role']) : $_SESSION['user_role'];
    $perm_label = getPermissionLabel($permission);

    if (empty($message)) {
        $message = "ليس لديك صلاحية الوصول إلى هذه الصفحة.\n\nالصلاحية المطلوبة: {$perm_label}\nدورك الحالي: {$role_name}";
    }

    // إذا كان طلب API (AJAX)، أرجع JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // صفحة HTML كاملة للخطأ 403
    echo '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - وصول مرفوض</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; direction: rtl; }
        .forbidden-card { background: #fff; border-radius: 16px; padding: 48px 40px; text-align: center; max-width: 480px; width: 90%; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        .forbidden-icon { width: 80px; height: 80px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
        .forbidden-icon svg { width: 40px; height: 40px; color: #dc2626; }
        .forbidden-title { font-size: 1.5rem; font-weight: 800; color: #1e293b; margin-bottom: 12px; }
        .forbidden-message { font-size: 0.95rem; color: #64748b; line-height: 1.7; margin-bottom: 8px; white-space: pre-line; }
        .forbidden-role { display: inline-block; margin-top: 12px; padding: 6px 16px; background: #f1f5f9; border-radius: 20px; font-size: 0.82rem; color: #475569; font-weight: 600; }
        .forbidden-actions { margin-top: 28px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .forbidden-actions a { display: inline-flex; align-items: center; gap: 6px; padding: 10px 24px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: all 0.15s; }
        .btn-back { background: #2563eb; color: #fff; }
        .btn-back:hover { background: #1d4ed8; }
        .btn-login { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .btn-login:hover { background: #e2e8f0; }
    </style>
</head>
<body>
    <div class="forbidden-card">
        <div class="forbidden-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
        </div>
        <div class="forbidden-title">وصول مرفوض (403)</div>
        <div class="forbidden-message">' . htmlspecialchars($message) . '</div>
        <div class="forbidden-role">دورك: ' . htmlspecialchars($role_name) . '</div>
        <div class="forbidden-actions">
            <a href="javascript:history.back()" class="btn-back">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
                العودة
            </a>
            <a href="' . $base_url . '/index.php" class="btn-login">الصفحة الرئيسية</a>
        </div>
    </div>
</body>
</html>';
    exit;
}

/**
 * الحصول على تسمية الصلاحية بالعربية
 *
 * @param string $permission اسم الصلاحية
 * @return string التسمية بالعربية
 */
function getPermissionLabel(string $permission): string {
    $labels = [
        // المنتجات
        'products.view' => 'عرض المنتجات',
        'products.add' => 'إضافة منتج',
        'products.edit' => 'تعديل منتج',
        'products.delete' => 'حذف منتج',
        // المبيعات
        'sales.view' => 'عرض المبيعات',
        'sales.add' => 'تسجيل بيع',
        'sales.edit' => 'تعديل بيع',
        'sales.delete' => 'حذف بيع',
        // المشتريات
        'purchases.view' => 'عرض المشتريات',
        'purchases.add' => 'إضافة مشتريات',
        'purchases.edit' => 'تعديل مشتريات',
        'purchases.delete' => 'حذف مشتريات',
        // المصاريف
        'expenses.view' => 'عرض المصاريف',
        'expenses.add' => 'إضافة مصاريف',
        'expenses.edit' => 'تعديل مصاريف',
        'expenses.delete' => 'حذف مصاريف',
        // التصنيفات
        'categories.view' => 'عرض التصنيفات',
        'categories.add' => 'إضافة تصنيف',
        'categories.edit' => 'تعديل تصنيف',
        'categories.delete' => 'حذف تصنيف',
        // وحدات القياس
        'units.view' => 'عرض وحدات القياس',
        'units.add' => 'إضافة وحدة قياس',
        'units.edit' => 'تعديل وحدة قياس',
        'units.delete' => 'حذف وحدة قياس',
        // المخزون
        'stock.transfer' => 'تحويل المخزون',
        'stock.inventory_count' => 'جرد المخزون',
        // الصندوق
        'caisse.view' => 'عرض الصندوق',
        'caisse.manage' => 'إدارة الصندوق',
        // التقارير
        'reports.view' => 'عرض التقارير',
        'reports.profits' => 'حساب الأرباح',
        // التصدير
        'export.data' => 'تصدير البيانات',
        // الإعدادات
        'settings.manage' => 'إدارة الإعدادات',
        // المستخدمون
        'users.view' => 'عرض المستخدمين',
        'users.add' => 'إضافة مستخدم',
        'users.edit' => 'تعديل مستخدم',
        'users.delete' => 'حذف مستخدم',
        // النسخ الاحتياطي
        'backups.view' => 'عرض النسخ الاحتياطية',
        'backups.create' => 'إنشاء نسخة احتياطية',
        'backups.restore' => 'استعادة نسخة احتياطية',
        'backups.delete' => 'حذف نسخة احتياطية',
        // سجلات
        'audit.view' => 'عرض سجل المراجعة',
        'logs.view' => 'عرض سجلات النظام',
        // النظام
        'system.upgrade' => 'ترقية النظام',
        'system.uninstall' => 'إلغاء تثبيت النظام',
        // أسعار
        'prices.view' => 'عرض الأسعار',
        'prices.edit' => 'تعديل الأسعار',
        'labels.print' => 'طباعة الملصقات',
        // صلاحية المنتجات
        'expiry.view' => 'عرض تقرير الصلاحية',
    ];

    return $labels[$permission] ?? $permission;
}

/**
 * الحصول على ملخص صلاحيات دور معين
 *
 * @param string $role اسم الدور
 * @return array ملخص الصلاحيات مرتبة حسب القسم
 */
function getRolePermissionSummary(string $role): array {
    $all = getRolePermissions();
    $perms = $all[$role] ?? [];

    $summary = [];
    foreach ($perms as $p) {
        $parts = explode('.', $p, 2);
        $section = $parts[0];
        $action = $parts[1] ?? '';
        if (!isset($summary[$section])) $summary[$section] = [];
        $summary[$section][] = $action;
    }
    return $summary;
}
