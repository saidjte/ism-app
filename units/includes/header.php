<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// حساب المسار الأساسي للتطبيق
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
if (in_array(basename($script_dir), ['products', 'purchases', 'sales', 'expenses', 'api', 'users', 'categories', 'units', 'settings', 'backups'])) {
    $base_url = rtrim(dirname($script_dir), '/');
} else {
    $base_url = rtrim($script_dir, '/');
}
if (empty($base_url)) $base_url = '';

$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="theme-color" content="#2563eb">
    <title><?php echo $page_title ?? 'إدارة المخزون'; ?></title>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/style.css'); ?>">
<?php if ($current_page == 'caisse'): ?>
<style>
/* === تحسين الوافقية مع الهواتف لصفحة الصندوق === */
@media (max-width: 768px) {
    /* شبكات داخلية - تحويل إلى عمود واحد */
    .card-body [style*="grid-template-columns: 1fr 1fr"],
    .card-body [style*="grid-template-columns:1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
    
    /* إخفاء حواف الشبكة السلبية على الهاتف */
    .table-responsive {
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* تحسين حجم الخط للكميات الكبيرة */
    .card-body div[style*="font-size: 2.5rem"],
    .card-body div[style*="font-size: 2.8rem"] {
        font-size: 1.8rem !important;
    }
    
    .card-body div[style*="font-size: 2rem"] {
        font-size: 1.5rem !important;
    }
    
    /* تحسين حقول النماذج */
    .card-body form input[type="number"],
    .card-body form input[type="text"] {
        font-size: 16px !important;
    }
    
    /* تحسين العناوين */
    .card-header h2 {
        font-size: 0.95rem !important;
    }
    
    /* تحسين عرض جدول الحركات */
    table {
        min-width: 500px !important;
    }
}

@media (max-width: 480px) {
    /* شبكة 3 أعمدة للإحصائيات → عمود واحد */
    .stats-grid {
        grid-template-columns: 1fr !important;
    }
    
    /* حجم خط أصغر للقيم الكبيرة */
    .card-body div[style*="font-size: 2.5rem"],
    .card-body div[style*="font-size: 2.8rem"] {
        font-size: 1.4rem !important;
    }
    
    .card-body div[style*="font-size: 2rem"] {
        font-size: 1.2rem !important;
    }
    
    /* تحسين حقول الإدخال */
    .card-body input,
    .card-body select {
        padding: 10px 8px !important;
    }
    
    /* حركة الصندوق - تحويل الشبكة لصف واحد */
    .card-body [style*="grid-template-columns: repeat(auto-fit"] {
        grid-template-columns: 1fr !important;
    }
    
    /* تحسين عرض الأزرار */
    .card-body button[type="submit"],
    .card-body .btn {
        width: 100% !important;
    }
    
    /* الحاوية الرئيسية */
    .container {
        padding: 0.5rem !important;
    }
}
</style>
<?php endif; ?>
</head>
<body>
    <div class="navbar">
        <a href="<?php echo $base_url; ?>/index.php" class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            إدارة المخزون
        </a>
        <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileMenu()" aria-label="القائمة">
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
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                    <a href="<?php echo $base_url; ?>/users/list-users.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                        إدارة المستخدمين
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo $base_url; ?>/users/edit-user.php?id=<?php echo $_SESSION['user_id']; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        تعديل حسابي
                    </a>
                    <a href="<?php echo $base_url; ?>/logout.php" class="mobile-logout-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        تسجيل الخروج
                    </a>
                </div>
            </div>
            <div class="mobile-nav-divider"></div>

            <!-- === القائمة الرئيسية === -->
            <a href="<?php echo $base_url; ?>/index.php" class="<?php echo $current_page == 'index' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1m-6 0h6"/></svg>
                الرئيسية
            </a>

            <!-- المنتجات (قائمة منسدلة) -->
            <div class="nav-dropdown <?php echo ($current_dir == 'products' || $current_dir == 'categories' || $current_dir == 'units') ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    المنتجات
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <a href="<?php echo $base_url; ?>/products/add-product.php" class="<?php echo $current_page == 'add-product' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        إضافة منتوج
                    </a>
                    <a href="<?php echo $base_url; ?>/products/list-products.php" class="<?php echo $current_page == 'list-products' || $current_page == 'view-product' || $current_page == 'edit-product' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 6h16M4 10h16M4 14h10M4 18h7"/></svg>
                        قائمة المنتجات
                    </a>
                    <a href="<?php echo $base_url; ?>/categories/list-categories.php" class="<?php echo $current_dir == 'categories' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 7h16M4 12h16M4 17h10"/><circle cx="18" cy="17" r="2"/></svg>
                        التصنيفات
                    </a>
                    <a href="<?php echo $base_url; ?>/units/list-units.php" class="<?php echo $current_dir == 'units' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                        وحدات القياس
                    </a>
                </div>
            </div>

            <!-- المبيعات (قائمة منسدلة) -->
            <div class="nav-dropdown <?php echo $current_dir == 'sales' ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    المبيعات
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <a href="<?php echo $base_url; ?>/sales/add-sale.php" class="<?php echo $current_page == 'add-sale' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        تسجيل بيع
                    </a>
                    <a href="<?php echo $base_url; ?>/sales/calculate-profits.php" class="<?php echo $current_page == 'calculate-profits' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        حساب الأرباح
                    </a>
                </div>
            </div>

            <!-- المشتريات -->
            <a href="<?php echo $base_url; ?>/purchases/add-purchase.php" class="<?php echo $current_dir == 'purchases' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                المشتريات
            </a>

            <!-- المصاريف -->
            <a href="<?php echo $base_url; ?>/expenses/list-expenses.php" class="<?php echo $current_dir == 'expenses' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                المصاريف
            </a>

            <!-- الصندوق -->
            <a href="<?php echo $base_url; ?>/caisse.php" class="<?php echo $current_page == 'caisse' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 14h.01"/><path d="M10 14h.01"/></svg>
                الصندوق
            </a>

            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
            <a href="<?php echo $base_url; ?>/settings/system-settings.php" class="<?php echo $current_page == 'system-settings' ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                الإعدادات
            </a>
            <?php endif; ?>

            <!-- أكثر (قائمة منسدلة) -->
            <div class="nav-dropdown <?php echo ($current_page == 'price-history' || $current_page == 'print-labels' || $current_page == 'price-list' || $current_page == 'upgrade' || $current_page == 'uninstall' || $current_dir == 'backups') ? 'active-parent' : ''; ?>">
                <button class="nav-dropdown-trigger" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    المزيد
                    <svg class="dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu">
                    <a href="<?php echo $base_url; ?>/products/price-history.php" class="<?php echo $current_page == 'price-history' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 9v4m0 0v4m0-4h4m-4 0H8"/><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
                        سجل الأسعار
                    </a>
                    <a href="<?php echo $base_url; ?>/products/price-list.php" class="<?php echo $current_page == 'price-list' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/><path d="M12 11h.01M8 15h.01M16 15h.01M12 19h.01" stroke-dasharray="1 1"/></svg>
                        لائحة الأسعار
                    </a>
                    <a href="<?php echo $base_url; ?>/products/print-labels.php" class="<?php echo $current_page == 'print-labels' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        طباعة الملصقات
                    </a>
<?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                    <a href="<?php echo $base_url; ?>/users/list-users.php" class="<?php echo $current_dir == 'users' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                        المستخدمين
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo $base_url; ?>/upgrade.php" class="<?php echo $current_page == 'upgrade' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        تحديث النظام
                    </a>
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                    <a href="<?php echo $base_url; ?>/backups/list-backups.php" class="<?php echo $current_dir == 'backups' ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        النسخ الاحتياطي
                    </a>
                    <a href="<?php echo $base_url; ?>/uninstall.php" class="<?php echo $current_page == 'uninstall' ? 'active' : ''; ?>" style="color: #dc2626;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                        إلغاء التثبيت
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
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
                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                <a href="<?php echo $base_url; ?>/settings/system-settings.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    إعدادات النظام
                </a>
                <a href="<?php echo $base_url; ?>/users/list-users.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    إدارة المستخدمين
                </a>
                <?php endif; ?>
                <a href="<?php echo $base_url; ?>/users/edit-user.php?id=<?php echo $_SESSION['user_id']; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    تعديل حسابي
                </a>
                <a href="<?php echo $base_url; ?>/logout.php" class="logout-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    تسجيل الخروج
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- خلفية شفافة عند فتح القائمة في الهاتف -->
    <div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>
    <!-- شريط التنقل السفلي للهاتف -->
    <nav class="mobile-bottom-nav" id="mobileBottomNav">
        <a href="<?php echo $base_url; ?>/index.php" class="bottom-nav-item <?php echo $current_page == 'index' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>الرئيسية</span>
        </a>
        <a href="<?php echo $base_url; ?>/products/list-products.php" class="bottom-nav-item <?php echo $current_dir == 'products' || $current_dir == 'categories' || $current_dir == 'units' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span>المنتجات</span>
        </a>
        <a href="<?php echo $base_url; ?>/sales/add-sale.php" class="bottom-nav-item <?php echo $current_dir == 'sales' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            <span>بيع</span>
        </a>
        <a href="<?php echo $base_url; ?>/purchases/add-purchase.php" class="bottom-nav-item <?php echo $current_dir == 'purchases' ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            <span>شراء</span>
        </a>
        <a href="javascript:void(0)" class="bottom-nav-item" onclick="toggleMobileMenu()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span>المزيد</span>
        </a>
    </nav>
