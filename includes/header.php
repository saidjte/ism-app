<?php
// بدء الجلسة مع الإعدادات الأمنية المركزية
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

// تحميل الإعدادات وقاعدة البيانات (مطلوبة لـ getSetting, getRoleName...)
if (!function_exists('getSetting')) {
    require_once __DIR__ . '/../config/database.php';
}
if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/ErrorHandler.php'; }

// تحميل فئات الإشعارات والتخزين المؤقت (مطلوبة لجرس الإشعارات في الشريط العلوي)
if (!class_exists('Cache')) {
    require_once __DIR__ . '/Cache.php';
}
if (!class_exists('AppNotification')) {
    require_once __DIR__ . '/notifications.php';
}

// تحميل نظام الصلاحيات
if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/permissions.php';
}

// حساب المسار الأساسي للتطبيق
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
if (in_array(basename($script_dir), ['products', 'purchases', 'sales', 'expenses', 'api', 'users', 'categories', 'units', 'settings', 'backups', 'admin', 'reports', 'notifications'])) {
    $base_url = rtrim(dirname($script_dir), '/');
} else {
    $base_url = rtrim($script_dir, '/');
}
if (empty($base_url)) $base_url = '';

$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="<?php echo lang(); ?>" dir="<?php echo langDir(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="<?php echo getSetting('app_name', 'إدارة المخزون'); ?>">
        <meta name="theme-color" content="#2563eb">
        <link rel="manifest" href="<?php echo $base_url; ?>/manifest.php">
        <link rel="apple-touch-icon" href="<?php echo $base_url; ?>/assets/img/price-check-icon-192.png">
    <title><?php echo ($page_title ?? (getSetting('app_name', 'إدارة المخزون'))); ?></title>
    <?php
    /* === ملفات CSS الموديولية === */
    $css_dir = __DIR__ . '/../assets/css/';
    $css_modules = [
        'base.css',       // المتغيرات والإعدادات الأساسية
        'layout.css',     // التخطيط: الحاوية، العنوان، التذييل
        'navbar.css',     // الشريط العلوي والقوائم
        'components.css', // البطاقات والإحصائيات
        'forms.css',      // النماذج والحقول
        'buttons.css',    // الأزرار
        'tables.css',     // الجداول والشارات
        'alerts.css',     // التنبيهات
        'search.css',     // البحث وماسح الباركود
        'modal.css',      // النوافذ المنبثقة
        'tabs.css',       // التبويبات
        'pages.css',      // أنماط خاصة بالصفحات
        'reports.css',    // التقارير والرسوم البيانية
        'mobile.css',     // تحسينات الهاتف
        'notifications.css', // نظام الإشعارات
        'print.css',      // الطباعة
    ];
    $css_version = 'v=4.2';
    foreach ($css_modules as $css_file) {
        echo '    <link rel="stylesheet" href="' . $base_url . '/assets/css/' . $css_file . '?' . $css_version . '">' . "\n";
    }
    ?>
<?php if ($current_page == 'caisse'): ?>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/pages-caisse.css?<?php echo $css_version; ?>">
<?php endif; ?>
<?php
// تحميل CSS الصفحات حسب الحاجة
$_currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));
$_currentFile = basename($_SERVER['SCRIPT_NAME']);
if ($_currentDir === 'sales' || $_currentFile === 'add-sale.php') {
    echo '<link rel="stylesheet" href="' . $base_url . '/assets/css/pages-sales.css?' . $css_version . '">';
}
if ($_currentDir === 'purchases' || $_currentFile === '/add-purchase.php') {
    echo '<link rel="stylesheet" href="' . $base_url . '/assets/css/pages-purchases.css?' . $css_version . '">';
}
if ($_currentFile === 'index.php' && $_currentDir !== 'units') {
    echo '<link rel="stylesheet" href="' . $base_url . '/assets/css/pages-dashboard.css?' . $css_version . '">';
}
?>
<?php if (!empty($load_chartjs)): ?>
    <script src="<?php echo $base_url; ?>/assets/vendor/chart.umd.min.js"></script>
<?php endif; ?>
<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === إعدادات النظام المتاحة لـ JavaScript ===
window.CURRENCY = '<?php echo htmlspecialchars(getCurrencySymbol()); ?>';
window.DATE_FORMAT = '<?php echo htmlspecialchars(getSetting('date_format', 'Y-m-d')); ?>';
window.PUSH_ENABLED = <?php echo getSetting('push_notifications_enabled', '0') === '1' ? 'true' : 'false'; ?>;
</script>
</head>
<body>
    <!-- تحذير أمني: بيانات اعتماد ضعيفة في .env (يظهر للمديرين فقط) -->
    <?php
    // فحص إعداد إخفاء شريط التحذير الأمني العلوي
    $_hide_env_banner = false;
    try {
        if (function_exists('getSetting')) {
            $_hide_env_banner = (getSetting('hide_env_security_banner', '0') === '1');
        }
    } catch (\Throwable $e) { $_hide_env_banner = false; }

    if (!$_hide_env_banner && isset($_SESSION['env_security_warnings']) && !empty($_SESSION['env_security_warnings']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'):
        $highWarnings = array_filter($_SESSION['env_security_warnings'], fn($w) => ($w['severity'] ?? '') === 'high');
        if (!empty($highWarnings)):
    ?>
    <div id="env_security_banner" data-banner-id="env_security" style="background:#dc2626; color:#fff; padding:10px 20px; text-align:center; font-size:0.85rem; font-family:sans-serif; direction:rtl; position:relative; z-index:9999;">
        <strong>تحذير أمني:</strong>
        <?php foreach ($highWarnings as $i => $w): ?>
            <?php echo $i > 0 ? ' | ' : ''; ?>
            <span><?php echo htmlspecialchars($w['key']); ?>: <?php echo htmlspecialchars(implode('، ', $w['warnings'])); ?></span>
        <?php endforeach; ?>
        &mdash; شغّل <code style="background:rgba(255,255,255,0.2); padding:1px 6px; border-radius:3px;">php scripts/encrypt-env-helper.php</code> للإصلاح
        <button id="dismiss_env_banner" style="position:absolute; top:50%; left:12px; transform:translateY(-50%); background:rgba(255,255,255,0.2); border:none; color:#fff; cursor:pointer; font-size:1.1rem; line-height:1; padding:2px 8px; border-radius:4px;" title="إخفاء (يمكن إعادة تفعيله من الإعدادات)">&times;</button>
    </div>
    <script>
    (function(){
        var banner = document.getElementById('env_security_banner');
        if (!banner) return;
        // إخفاء إذا تم إخفاؤه سابقاً من المتصفح
        if (localStorage.getItem('ism_hide_env_banner') === '1') {
            banner.style.display = 'none';
        }
        // زر الإخفاء
        var btn = document.getElementById('dismiss_env_banner');
        if (btn) {
            btn.addEventListener('click', function() {
                banner.style.display = 'none';
                localStorage.setItem('ism_hide_env_banner', '1');
            });
        }
    })();
    </script>
    <?php endif; endif; ?>

    <!-- رمز CSRF المتاح لجميع صفحات التطبيق (يستخدمه نظام الإشعارات وغيره) -->
    <?php if (class_exists('AppCsrf')): ?>
    <input type="hidden" name="csrf_token" id="global-csrf-token" value="<?php echo AppCsrf::generateToken(); ?>">
    <?php endif; ?>
    <div class="navbar">
        <a href="<?php echo $base_url; ?>/index.php" class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <?php echo htmlspecialchars(getSetting('app_name', 'إدارة المخزون')); ?>
        </a>
        <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileMenu()" aria-label="<?php echo t('mobile_menu'); ?>">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>
        <nav id="mainNav">
            <!-- معلومات المستخدم في القائمة الجانبية (مخصوص للهاتف) — منسدلة -->
            <div class="mobile-user-dropdown" id="mobileUserDropdown">
                <button class="mobile-user-toggle" onclick="toggleMobileUserMenu()">
                    <div class="mobile-user-avatar"><?php echo mb_substr($_SESSION['user_name'] ?? 'م', 0, 1); ?></div>
                    <div class="mobile-user-details">
                        <span class="mobile-user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                        <span class="mobile-user-role"><?php echo isset($_SESSION['user_role']) ? getRoleName($_SESSION['user_role']) : ''; ?></span>
                    </div>
                    <svg class="mobile-user-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="mobile-user-actions" id="mobileUserActions">
                    <?php if (hasPermission('users.view')): ?>
                    <a href="<?php echo $base_url; ?>/users/list-users.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                        <?php echo t('nav_user_management'); ?>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo $base_url; ?>/users/edit-user.php?id=<?php echo $_SESSION['user_id']; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <?php echo t('nav_edit_account'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>/logout.php" class="mobile-logout-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <?php echo t('nav_logout'); ?>
                    </a>
                </div>
            </div>
            <div class="mobile-nav-divider"></div>

            <!-- === القائمة الرئيسية === -->
            <a href="<?php echo $base_url; ?>/index.php" class="<?php echo $current_page == 'index' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1m-6 0h6"/></svg>
                <?php echo t('nav_home'); ?>
            </a>

            <!-- Products dropdown -->
            <?php if (hasPermission('products.view')): ?>
            <div class="nav-dropdown <?php echo ($current_dir == 'products' || $current_dir == 'categories' || $current_dir == 'units' || $current_page == 'stock-transfer') ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <?php echo t('nav_products'); ?>
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <?php if (hasPermission('products.add')): ?>
                    <a href="<?php echo $base_url; ?>/products/add-product.php" class="<?php echo $current_page == 'add-product' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        <?php echo t('nav_add_product'); ?>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo $base_url; ?>/products/list-products.php" class="<?php echo $current_page == 'list-products' || $current_page == 'view-product' || $current_page == 'edit-product' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 6h16M4 10h16M4 14h10M4 18h7"/></svg>
                        <?php echo t('nav_product_list'); ?>
                    </a>
                    <?php if (hasPermission('categories.view') && hasPermission('categories.add')): ?>
                    <a href="<?php echo $base_url; ?>/categories/list-categories.php" class="<?php echo $current_dir == 'categories' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 7h16M4 12h16M4 17h10"/><circle cx="18" cy="17" r="2"/></svg>
                        <?php echo t('nav_categories'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('units.view') && hasPermission('units.add')): ?>
                    <a href="<?php echo $base_url; ?>/units/list-units.php" class="<?php echo $current_dir == 'units' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                        <?php echo t('nav_units'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('stock.transfer')): ?>
                    <a href="<?php echo $base_url; ?>/products/stock-transfer.php" class="<?php echo $current_page == 'stock-transfer' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                        <?php echo t('nav_stock_transfer'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('stock.inventory_count')): ?>
                    <a href="<?php echo $base_url; ?>/products/inventory-count.php" class="<?php echo $current_page == 'inventory-count' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        <?php echo t('nav_inventory_count'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('expiry.view')): ?>
                    <a href="<?php echo $base_url; ?>/products/expiry-report.php" class="<?php echo $current_page == 'expiry-report' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php echo t('nav_expiry_report'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- المبيعات (قائمة منسدلة) -->
            <?php if (hasPermission('sales.view')): ?>
            <div class="nav-dropdown <?php echo $current_dir == 'sales' ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    <?php echo t('nav_sales'); ?>
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <?php if (hasPermission('sales.add')): ?>
                    <a href="<?php echo $base_url; ?>/sales/add-sale.php" class="<?php echo $current_page == 'add-sale' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        <?php echo t('nav_register_sale'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('reports.profits')): ?>
                    <a href="<?php echo $base_url; ?>/sales/calculate-profits.php" class="<?php echo $current_page == 'calculate-profits' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        <?php echo t('nav_calculate_profits'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- المشتريات -->
            <?php if (hasPermission('purchases.view')): ?>
            <a href="<?php echo $base_url; ?>/purchases/add-purchase.php" class="<?php echo $current_dir == 'purchases' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <?php echo t('nav_purchases'); ?>
            </a>
            <?php endif; ?>

            <!-- المصاريف -->
            <?php if (hasPermission('expenses.view')): ?>
            <a href="<?php echo $base_url; ?>/expenses/list-expenses.php" class="<?php echo $current_dir == 'expenses' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <?php echo t('nav_expenses'); ?>
            </a>
            <?php endif; ?>

            <!-- التقارير (قائمة منسدلة) -->
            <?php if (hasPermission('reports.view')): ?>
            <div class="nav-dropdown <?php echo $current_dir == 'reports' ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <?php echo t('nav_reports'); ?>
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <a href="<?php echo $base_url; ?>/reports/sales-report.php" class="<?php echo $current_page == 'sales-report' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                        <?php echo t('nav_sales_report'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>/reports/purchases-report.php" class="<?php echo $current_page == 'purchases-report' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
                        <?php echo t('nav_purchases_report'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>/reports/period-comparison.php" class="<?php echo $current_page == 'period-comparison' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                        <?php echo t('nav_period_comparison'); ?>
                    </a>
                    <?php if (hasPermission('reports.profits')): ?>
                    <a href="<?php echo $base_url; ?>/reports/profit-report.php" class="<?php echo $current_page == 'profit-report' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        <?php echo t('nav_profit_report'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>/sales/calculate-profits.php" class="<?php echo $current_page == 'calculate-profits' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        <?php echo t('nav_calculate_profits'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- نقطة البيع -->
            <?php if (hasPermission('sales.add')): ?>
            <a href="<?php echo $base_url; ?>/pos.php" class="<?php echo $current_page == 'pos' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                <?php echo t('nav_pos'); ?>
            </a>
            <?php endif; ?>

            <!-- الصندوق -->
            <?php if (hasPermission('caisse.view')): ?>
            <a href="<?php echo $base_url; ?>/caisse.php" class="<?php echo $current_page == 'caisse' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 14h.01"/><path d="M10 14h.01"/></svg>
                <?php echo t('nav_cashbox'); ?>
            </a>
            <?php endif; ?>

            <!-- تنبيهات الصلاحية — داخل القائمة فقط -->
            <?php
            $nav_expired_count = 0;
            $nav_expiring_count = 0;
            $nav_expiry_enabled = true;
            try {
                $nav_expiry_enabled = (getSetting('expiry_alert_enabled', '1') === '1');
            } catch (Exception $e) { ErrorHandler::logException($e, 'header::expiryAlertEnabled'); }
            if ($nav_expiry_enabled) {
                // === استعلام مباشر لعدّاد الصلاحية — بيانات حقيقية في الوقت الفعلي ===
                try {
                    // Batch expiry tracking removed; expiry alert counts are not available.
                    $nav_expired_count = 0;
                    $nav_expiring_count = 0;
                } catch (Exception $e) { ErrorHandler::logException($e, 'header::expiryCountsQuery'); }
            $nav_total_alerts = $nav_expired_count + $nav_expiring_count;
            ?>
            <?php if ($nav_total_alerts > 0): ?>
            <a href="<?php echo $base_url; ?>/products/expiry-report.php" class="nav-alert-link" title="<?php echo $nav_expired_count; ?> منتهي الصلاحية + <?php echo $nav_expiring_count; ?> قارب الانتهاء">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 9v4m0 0v4m0-4h4m-4 0H8"/><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <span style="background:#dc2626; color:#fff; font-size:0.6rem; font-weight:700; padding:1px 5px; border-radius:8px; margin-right:2px;"><?php echo $nav_total_alerts; ?></span>
                <?php echo $nav_expired_count > 0 ? 'منتهية' : 'قاربت الانتهاء'; ?>
            </a>
            <?php endif; ?>
            <?php } ?>

            <?php if (hasPermission('settings.manage')): ?>
            <a href="<?php echo $base_url; ?>/settings/system-settings.php" class="<?php echo $current_page == 'system-settings' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                <?php echo t('nav_settings'); ?>
            </a>
            <?php endif; ?>

            <!-- أكثر (قائمة منسدلة) -->
            <div class="nav-dropdown <?php echo ($current_page == 'price-history' || $current_page == 'print-labels' || $current_page == 'price-list' || $current_page == 'upgrade' || $current_page == 'uninstall' || $current_dir == 'backups' || $current_page == 'audit-log' || $current_page == 'view-logs') ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    <?php echo t('nav_more'); ?>
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <a href="<?php echo $base_url; ?>/price-check.php" class="<?php echo $current_page == 'price-check' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                        أسعاري
                    </a>
                    <?php if (hasPermission('prices.view')): ?>
                    <a href="<?php echo $base_url; ?>/products/price-history.php" class="<?php echo $current_page == 'price-history' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 9v4m0 0v4m0-4h4m-4 0H8"/><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
                        <?php echo t('nav_price_history'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('prices.view')): ?>
                    <a href="<?php echo $base_url; ?>/products/price-list.php" class="<?php echo $current_page == 'price-list' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/><path d="M12 11h.01M8 15h.01M16 15h.01M12 19h.01" stroke-dasharray="1 1"/></svg>
                        <?php echo t('nav_price_list'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('labels.print')): ?>
                    <a href="<?php echo $base_url; ?>/products/print-labels.php" class="<?php echo $current_page == 'print-labels' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        <?php echo t('nav_print_labels'); ?>
                    </a>
                    <?php endif; ?>
<?php if (hasPermission('users.view')): ?>
                    <a href="<?php echo $base_url; ?>/users/list-users.php" class="<?php echo $current_dir == 'users' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                        <?php echo t('nav_users'); ?>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo $base_url; ?>/changelog.php" class="<?php echo $current_page == 'changelog' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php echo t('nav_changelog'); ?>
                    </a>
<?php if (hasPermission('system.upgrade')): ?>
                    <a href="<?php echo $base_url; ?>/upgrade.php" class="<?php echo $current_page == 'upgrade' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        <?php echo t('nav_upgrade'); ?>
                    </a>
                    <?php if (hasPermission('settings.manage')): ?>
                    <a href="<?php echo $base_url; ?>/settings/db-settings.php" class="<?php echo $current_page == 'db-settings' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                        قاعدة البيانات
                    </a>
                    <a href="<?php echo $base_url; ?>/settings/api-tokens.php" class="<?php echo $current_page == 'api-tokens' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                        رموز API
                    </a>
                    <a href="<?php echo $base_url; ?>/api-docs.php" class="<?php echo $current_page == 'api-docs' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        توثيق API
                    </a>
                    <a href="<?php echo $base_url; ?>/setup-api.php" class="<?php echo $current_page == 'setup-api' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                        إعداد API
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('audit.view')): ?>
                    <a href="<?php echo $base_url; ?>/admin/audit-log.php" class="<?php echo $current_page == 'audit-log' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        <?php echo t('nav_audit_log'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>/admin/view-logs.php" class="<?php echo $current_page == 'view-logs' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        <?php echo t('nav_system_logs'); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('backups.view')): ?>
                    <a href="<?php echo $base_url; ?>/backups/list-backups.php" class="<?php echo $current_dir == 'backups' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <?php echo t('nav_backup'); ?>
                    </a>
                    <a href="<?php echo $base_url; ?>/uninstall.php" class="<?php echo $current_page == 'uninstall' ? 'active' : ''; ?>" style="color: #dc2626;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                        <?php echo t('nav_uninstall'); ?>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <!-- === جرس التنبيهات الموحّد (خارج nav) === -->
        <?php
        $notif_unread = 0;
        try {
            if (isset($_SESSION['user_id'])) {
                // === استعلام مباشر لعدّاد الإشعارات — بيانات حقيقية في الوقت الفعلي ===
                $notif_unread = AppNotification::getUnreadCount(intval($_SESSION['user_id']));
            }
        } catch (Exception $e) { ErrorHandler::logException($e, 'header::notifUnreadCount'); }
        $nav_total_alerts = ($nav_expired_count ?? 0) + ($nav_expiring_count ?? 0);
        $bell_total = $notif_unread + $nav_total_alerts;
        $bell_title_parts = [];
        if ($notif_unread > 0) $bell_title_parts[] = $notif_unread . ' إشعار جديد';
        if (($nav_expired_count ?? 0) > 0) $bell_title_parts[] = $nav_expired_count . ' منتهي الصلاحية';
        if (($nav_expiring_count ?? 0) > 0) $bell_title_parts[] = $nav_expiring_count . ' قارب الانتهاء';
        $bell_title = count($bell_title_parts) > 0 ? implode(' | ', $bell_title_parts) : (function_exists('t') ? t('notif_no_notifications') : 'لا توجد تنبيهات');
        ?>
        <div class="notif-bell-wrapper">
            <button id="notif-bell-btn" class="notif-bell" aria-label="التنبيهات" aria-expanded="false" data-expiry-alerts="<?php echo $nav_total_alerts; ?>" title="<?php echo $bell_title; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                <span id="notif-badge" class="notif-badge" style="display:<?php echo $bell_total > 0 ? 'flex' : 'none'; ?>;"><?php echo $bell_total > 99 ? '99+' : $bell_total; ?></span>
            </button>
        </div>

        <!-- معلومات المستخدم الحالي -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="user-menu">
            <div class="user-info" onclick="this.classList.toggle('open')">
                <div class="user-avatar">
                    <?php echo mb_substr($_SESSION['user_name'] ?? 'م', 0, 1); ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                    <span class="user-role"><?php echo isset($_SESSION['user_role']) ? getRoleName($_SESSION['user_role']) : ''; ?></span>
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="color:#94a3b8; margin-right:4px;">
                    <path d="M6 9l6 6 6-6"/>
                </svg>
            </div>
            <div class="user-dropdown">
                <?php if (hasPermission('settings.manage')): ?>
                <a href="<?php echo $base_url; ?>/settings/system-settings.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    <?php echo t('nav_settings'); ?>
                </a>
                <?php endif; ?>
                <?php if (hasPermission('users.view')): ?>
                <a href="<?php echo $base_url; ?>/users/list-users.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <?php echo t('nav_user_management'); ?>
                </a>
                <?php endif; ?>
                <a href="<?php echo $base_url; ?>/users/edit-user.php?id=<?php echo $_SESSION['user_id']; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php echo t('nav_edit_account'); ?>
                </a>
                <a href="<?php echo $base_url; ?>/logout.php" class="logout-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <?php echo t('nav_logout'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- === القائمة المنسدلة للتنبيهات — خارج navbar لتجاوز stacking context === -->
    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    var NOTIF_I18N = {
        no_notifications: <?php echo json_encode(function_exists('t') ? t('notif_no_notifications') : 'لا توجد إشعارات', JSON_UNESCAPED_UNICODE); ?>,
        new_notification: <?php echo json_encode(function_exists('t') ? t('notif_new_notification') : 'إشعار جديد', JSON_UNESCAPED_UNICODE); ?>,
        new_count: <?php echo json_encode(function_exists('t') ? t('notif_new_count') : 'جديد', JSON_UNESCAPED_UNICODE); ?>,
        delete_btn: <?php echo json_encode(function_exists('t') ? t('delete') : 'حذف', JSON_UNESCAPED_UNICODE); ?>,
        just_now: <?php echo json_encode(function_exists('t') ? t('common_now') : 'الآن', JSON_UNESCAPED_UNICODE); ?>,
        minutes_ago: <?php echo json_encode(function_exists('t') ? str_replace('{n}', '{n}', t('notif_time_ago_minute')) : 'منذ {n} دقيقة', JSON_UNESCAPED_UNICODE); ?>,
        hours_ago: <?php echo json_encode(function_exists('t') ? str_replace('{n}', '{n}', t('notif_time_ago_hour')) : 'منذ {n} ساعة', JSON_UNESCAPED_UNICODE); ?>,
        days_ago: <?php echo json_encode(function_exists('t') ? str_replace('{n}', '{n}', t('notif_time_ago_day')) : 'منذ {n} يوم', JSON_UNESCAPED_UNICODE); ?>,
        push_enabled: <?php echo json_encode(function_exists('t') ? t('notif_push_enabled', 'مفعّل') : 'مفعّل', JSON_UNESCAPED_UNICODE); ?>,
        push_denied: <?php echo json_encode(function_exists('t') ? t('notif_push_denied', 'مرفوض') : 'مرفوض', JSON_UNESCAPED_UNICODE); ?>,
        push_disabled: <?php echo json_encode(function_exists('t') ? t('notif_push_disabled', 'غير مفعّل') : 'غير مفعّل', JSON_UNESCAPED_UNICODE); ?>,
    };
    </script>
    <div id="notif-dropdown" class="notif-dropdown">
        <div class="notif-dropdown-header">
            <span style="font-weight:700; font-size:0.9rem;">🔔 <?php echo function_exists('t') ? t('nav_notifications') : 'التنبيهات'; ?></span>
            <div style="display:flex; gap:8px; align-items:center;">
                <span class="notif-count-label" style="font-size:0.75rem; color:#64748b;"></span>
                <a href="<?php echo $base_url; ?>/notifications/" style="font-size:0.78rem; color:#2563eb; font-weight:600; text-decoration:none;">عرض الكل</a>
                <button id="notif-close-btn" class="notif-close-btn" aria-label="<?php echo function_exists('t') ? t('close') : 'إغلاق'; ?>" title="<?php echo function_exists('t') ? t('close') : 'إغلاق'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <?php if ($nav_total_alerts > 0): ?>
        <a href="<?php echo $base_url; ?>/products/expiry-report.php" class="notif-expiry-alert" style="display:flex; align-items:center; gap:10px; padding:10px 16px; background:<?php echo ($nav_expired_count ?? 0) > 0 ? '#fef2f2' : '#fffbeb'; ?>; border-bottom:1px solid var(--gray-200); text-decoration:none; color:inherit;">
            <span style="width:32px; height:32px; border-radius:8px; background:<?php echo ($nav_expired_count ?? 0) > 0 ? '#fee2e2' : '#fef3c7'; ?>; color:<?php echo ($nav_expired_count ?? 0) > 0 ? '#dc2626' : '#d97706'; ?>; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0;">📅</span>
            <span style="flex:1; min-width:0;">
                <span style="display:block; font-weight:600; font-size:0.82rem; color:<?php echo ($nav_expired_count ?? 0) > 0 ? '#dc2626' : '#d97706'; ?>;"><?php echo ($nav_expired_count ?? 0) > 0 ? ($nav_expired_count . ' منتج منتهي الصلاحية') : (($nav_expiring_count ?? 0) . ' منتج قارب الانتهاء'); ?></span>
                <span style="font-size:0.72rem; color:#94a3b8;">انقر لعرض تقرير الصلاحية</span>
            </span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="color:#94a3b8;"><path d="M9 18l6-6-6-6"/></svg>
        </a>
        <?php endif; ?>
        <div id="notif-dropdown-list" class="notif-dropdown-list">
            <div class="notif-empty"><p><?php echo function_exists('t') ? t('common_loading') : 'جاري التحميل...'; ?></p></div>
        </div>
        <div class="notif-dropdown-footer">
            <button class="notif-mark-all-btn" onclick="NotifSystem.markAllRead();" style="display:<?php echo $notif_unread > 0 ? '' : 'none'; ?>;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo function_exists('t') ? t('nav_mark_all_read') : 'تحديد الكل كمقروء'; ?>
            </button>
            <button id="push-enable-btn" onclick="if(typeof PushNotificationSystem!=='undefined')PushNotificationSystem.requestPermission();" style="display:flex;align-items:center;gap:6px;font-size:0.78rem;color:#2563eb;font-weight:600;padding:6px 10px;background:none;border:1px solid #bfdbfe;border-radius:6px;cursor:pointer;margin-top:4px;width:100%;justify-content:center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                <span><?php echo function_exists('t') ? t('notif_enable_browser') : 'تفعيل تنبيهات المتصفح'; ?></span>
            </button>
        </div>
    </div>
    <!-- خلفية شفافة عند فتح القائمة في الهاتف -->
    <div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>
    <!-- شريط التنقل السفلي للهاتف -->
    <nav class="mobile-bottom-nav" id="mobileBottomNav">
        <a href="<?php echo $base_url; ?>/index.php" class="bottom-nav-item <?php echo $current_page == 'index' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span><?php echo t('nav_home'); ?></span>
        </a>
        <?php if (hasPermission('products.view')): ?>
        <a href="<?php echo $base_url; ?>/products/list-products.php" class="bottom-nav-item <?php echo $current_dir == 'products' || $current_dir == 'categories' || $current_dir == 'units' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span><?php echo t('nav_products'); ?></span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('sales.add')): ?>
        <a href="<?php echo $base_url; ?>/pos.php" class="bottom-nav-item <?php echo $current_page == 'pos' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <span><?php echo t('nav_pos'); ?></span>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('purchases.view')): ?>
        <a href="<?php echo $base_url; ?>/purchases/add-purchase.php" class="bottom-nav-item <?php echo $current_dir == 'purchases' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            <span><?php echo t('mobile_purchase'); ?></span>
        </a>
        <?php endif; ?>
        <a href="<?php echo $base_url; ?>/price-check.php" class="bottom-nav-item <?php echo $current_page == 'price-check' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <span>أسعاري</span>
        </a>
        <a href="javascript:void(0)" class="bottom-nav-item" onclick="toggleMobileMenu()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span><?php echo t('nav_more'); ?></span>
        </a>
    </nav>
