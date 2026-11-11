<?php
$page_title = 'إعدادات النظام';
require_once '../config/database.php';
require_once '../config/upgrades.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireAdmin();

$success_msg = '';
$error_msg = '';

// ===== معالجة حفظ الإعدادات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        // === معلومات المتجر ===
        saveSetting('store_name', trim($_POST['store_name'] ?? ''), 'اسم المتجر');
        saveSetting('store_phone', trim($_POST['store_phone'] ?? ''), 'رقم هاتف المتجر');
        saveSetting('store_address', trim($_POST['store_address'] ?? ''), 'عنوان المتجر');
        saveSetting('store_city', trim($_POST['store_city'] ?? ''), 'مدينة المتجر');

        // === إعدادات العملة ===
        saveSetting('currency_symbol', trim($_POST['currency_symbol'] ?? 'د.م.'), 'رمز العملة');
        saveSetting('currency_code', trim($_POST['currency_code'] ?? 'MAD'), 'رمز العملة الدولي');
        saveSetting('currency_name', trim($_POST['currency_name'] ?? 'درهم مغربي'), 'اسم العملة');
        saveSetting('decimals', intval($_POST['decimals'] ?? 2), 'عدد الأرقام العشرية');

        // === إعدادات الضرائب ===
        $tax_enabled = isset($_POST['tax_enabled']) ? '1' : '0';
        saveSetting('tax_enabled', $tax_enabled, 'تفعيل الضريبة');
        saveSetting('tax_rate', floatval($_POST['tax_rate'] ?? 0), 'نسبة الضريبة (%)');
        saveSetting('tax_name', trim($_POST['tax_name'] ?? 'ضريبة القيمة المضافة'), 'اسم الضريبة');

        // === إعدادات المخزون ===
        $low_stock_alert = isset($_POST['low_stock_alert']) ? '1' : '0';
        saveSetting('low_stock_alert', $low_stock_alert, 'تفعيل تنبيه المخزون المنخفض');

        // === إعدادات المبيعات ===
        $auto_print = isset($_POST['auto_print']) ? '1' : '0';
        saveSetting('auto_print_invoice', $auto_print, 'طباعة الفاتورة تلقائياً بعد البيع');
        saveSetting('invoice_footer', trim($_POST['invoice_footer'] ?? ''), 'نص تذييل الفاتورة');

        $success_msg = 'تم حفظ جميع الإعدادات بنجاح';
    } catch (Exception $e) {
        ErrorHandler::logException($e, 'save_settings');
        $error_msg = 'حدث خطأ أثناء حفظ الإعدادات: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ الإعدادات');
    }
}

// ===== جلب الإعدادات الحالية =====
$store_name = getSetting('store_name', '');
$store_phone = getSetting('store_phone', '');
$store_address = getSetting('store_address', '');
$store_city = getSetting('store_city', '');

$currency_symbol = getSetting('currency_symbol', 'د.م.');
$currency_code = getSetting('currency_code', 'MAD');
$currency_name = getSetting('currency_name', 'درهم مغربي');
$decimals = getSetting('decimals', '2');

$tax_enabled = getSetting('tax_enabled', '0');
$tax_rate = getSetting('tax_rate', '20');
$tax_name = getSetting('tax_name', 'ضريبة القيمة المضافة');

$low_stock_alert = getSetting('low_stock_alert', '1');
$auto_print = getSetting('auto_print_invoice', '0');
$invoice_footer = getSetting('invoice_footer', '');

// ===== إحصائيات النظام =====
$total_products = 0;
$total_categories = 0;
$total_users = 0;
$total_sales_today = 0;
$low_stock_count = 0;
$db_size = 0;
try {
    $total_products = fetchOne("SELECT COUNT(*) as c FROM products")['c'];
    $total_categories = fetchOne("SELECT COUNT(*) as c FROM categories")['c'];
    $total_users = fetchOne("SELECT COUNT(*) as c FROM users")['c'];
    $total_sales_today = fetchOne("SELECT COUNT(*) as c FROM sales WHERE sale_date = CURDATE()")['c'];
    $low_stock_count = fetchOne("SELECT COUNT(*) as c FROM products WHERE quantity <= min_quantity")['c'];
    $db_row = fetchOne("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]);
    $db_size = $db_row ? $db_row['size'] : 0;
} catch (Exception $e) {}
?>
<?php require_once '../includes/header.php'; ?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>
            </svg>
            إعدادات النظام
            <span class="settings-admin-badge">مدير النظام فقط</span>
        </h1>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success settings-alert-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" class="text-success flex-shrink-0"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php echo htmlspecialchars($success_msg); ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger settings-alert-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" class="text-danger flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>

    <!-- إحصائيات سريعة -->
    <div class="settings-stats-grid">
        <div class="settings-stat-card settings-stat-card--blue">
            <div class="settings-stat-value"><?php echo $total_products; ?></div>
            <div class="settings-stat-label">منتج</div>
        </div>
        <div class="settings-stat-card settings-stat-card--green">
            <div class="settings-stat-value"><?php echo $total_categories; ?></div>
            <div class="settings-stat-label">تصنيف</div>
        </div>
        <div class="settings-stat-card settings-stat-card--amber">
            <div class="settings-stat-value"><?php echo $total_sales_today; ?></div>
            <div class="settings-stat-label">مبيعات اليوم</div>
        </div>
        <div class="settings-stat-card settings-stat-card--red">
            <div class="settings-stat-value"><?php echo $low_stock_count; ?></div>
            <div class="settings-stat-label">مخزون منخفض</div>
        </div>
        <div class="settings-stat-card settings-stat-card--purple">
            <div class="settings-stat-value"><?php echo $total_users; ?></div>
            <div class="settings-stat-label">مستخدم</div>
        </div>
        <div class="settings-stat-card settings-stat-card--sky">
            <div class="settings-stat-value"><?php echo $db_size; ?></div>
            <div class="settings-stat-label">حجم قاعدة البيانات (MB)</div>
        </div>
    </div>

    <form method="POST" action="system-settings.php">
        <?php echo csrfField(); ?>

        <!-- ===== معلومات المتجر ===== -->
        <div class="card settings-section-card">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    معلومات المتجر
                </h2>
            </div>
            <div class="card-body">
                <div class="settings-form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="settings-grid-full-span">
                        <label class="settings-form-label">اسم المتجر <span class="text-danger">*</span></label>
                        <input type="text" name="store_name" class="form-control" value="<?php echo htmlspecialchars($store_name); ?>" placeholder="مثال: متجر النور" required>
                    </div>
                    <div>
                        <label class="settings-form-label">رقم الهاتف</label>
                        <input type="text" name="store_phone" class="form-control settings-input-ltr" value="<?php echo htmlspecialchars($store_phone); ?>" placeholder="06XXXXXXXX">
                    </div>
                    <div>
                        <label class="settings-form-label">المدينة</label>
                        <input type="text" name="store_city" class="form-control" value="<?php echo htmlspecialchars($store_city); ?>" placeholder="الدار البيضاء">
                    </div>
                    <div class="settings-grid-full-span">
                        <label class="settings-form-label">العنوان التفصيلي</label>
                        <input type="text" name="store_address" class="form-control" value="<?php echo htmlspecialchars($store_address); ?>" placeholder="الحي، الشارع، رقم...">
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات العملة ===== -->
        <div class="card settings-section-card">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    إعدادات العملة
                </h2>
            </div>
            <div class="card-body">
                <div class="settings-form-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                    <div>
                        <label class="settings-form-label">رمز العملة</label>
                        <input type="text" name="currency_symbol" class="form-control" value="<?php echo htmlspecialchars($currency_symbol); ?>" placeholder="د.م.">
                        <small class="settings-help-text">مثال: د.م. أو $ أو €</small>
                    </div>
                    <div>
                        <label class="settings-form-label">الرمز الدولي</label>
                        <input type="text" name="currency_code" class="form-control settings-input-ltr" value="<?php echo htmlspecialchars($currency_code); ?>" placeholder="MAD">
                        <small class="settings-help-text">مثال: MAD, EUR, USD</small>
                    </div>
                    <div>
                        <label class="settings-form-label">اسم العملة</label>
                        <input type="text" name="currency_name" class="form-control" value="<?php echo htmlspecialchars($currency_name); ?>" placeholder="درهم مغربي">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="settings-form-label">عدد الأرقام العشرية</label>
                    <div class="d-flex gap-3 items-center">
                        <label class="settings-radio-option" style="border:2px solid <?php echo $decimals == '2' ? '#2563eb' : '#e2e8f0'; ?>; background:<?php echo $decimals == '2' ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="decimals" value="2" <?php echo $decimals == '2' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            خانتين (0.00)
                        </label>
                        <label class="settings-radio-option" style="border:2px solid <?php echo $decimals == '3' ? '#2563eb' : '#e2e8f0'; ?>; background:<?php echo $decimals == '3' ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="decimals" value="3" <?php echo $decimals == '3' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            ثلاث خانات (0.000)
                        </label>
                        <label class="settings-radio-option" style="border:2px solid <?php echo $decimals == '0' ? '#2563eb' : '#e2e8f0'; ?>; background:<?php echo $decimals == '0' ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="decimals" value="0" <?php echo $decimals == '0' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            بدون كسور (0)
                        </label>
                    </div>
                </div>
                <div class="settings-tip-box settings-tip-box--success">
                    💡 مثال: <strong><?php echo number_format(1250.5, intval($decimals)); ?> <?php echo htmlspecialchars($currency_symbol); ?></strong>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات الضرائب ===== -->
        <div class="card settings-section-card">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    إعدادات الضرائب
                </h2>
            </div>
            <div class="card-body">
                <label class="settings-toggle-label mb-3" style="border:1.5px solid <?php echo $tax_enabled == '1' ? '#2563eb' : '#e2e8f0'; ?>; background:<?php echo $tax_enabled == '1' ? '#eff6ff' : '#fff'; ?>;" id="taxToggleLabel">
                    <input type="checkbox" name="tax_enabled" <?php echo $tax_enabled == '1' ? 'checked' : ''; ?> class="settings-toggle-checkbox" id="taxToggle" onchange="toggleTaxSection()">
                    <div>
                        <div class="settings-toggle-title">تفعيل الضريبة على المبيعات</div>
                        <div class="settings-toggle-subtitle">إضافة ضريبة تلقائياً على فواتير البيع</div>
                    </div>
                </label>
                <div id="taxSection" style="display:<?php echo $tax_enabled == '1' ? 'block' : 'none'; ?>;">
                    <div class="settings-form-grid" style="grid-template-columns: 1fr 1fr;">
                        <div>
                            <label class="settings-form-label">نسبة الضريبة (%)</label>
                            <input type="number" name="tax_rate" class="form-control" value="<?php echo htmlspecialchars($tax_rate); ?>" min="0" max="100" step="0.01" placeholder="20">
                        </div>
                        <div>
                            <label class="settings-form-label">اسم الضريبة</label>
                            <input type="text" name="tax_name" class="form-control" value="<?php echo htmlspecialchars($tax_name); ?>" placeholder="ضريبة القيمة المضافة">
                        </div>
                    </div>
                    <div class="settings-tip-box settings-tip-box--warning">
                        💡 مثال: منتج بـ <strong>100 <?php echo htmlspecialchars($currency_symbol); ?></strong> + ضريبة <strong><?php echo htmlspecialchars($tax_rate); ?>%</strong> = <strong><?php echo number_format(100 * (1 + floatval($tax_rate) / 100), intval($decimals)); ?> <?php echo htmlspecialchars($currency_symbol); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات المخزون ===== -->
        <div class="card settings-section-card">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    إعدادات المخزون والتنبيهات
                </h2>
            </div>
            <div class="card-body">
                <label class="settings-toggle-label" style="border:1.5px solid <?php echo $low_stock_alert == '1' ? '#2563eb' : '#e2e8f0'; ?>; background:<?php echo $low_stock_alert == '1' ? '#eff6ff' : '#fff'; ?>;">
                    <input type="checkbox" name="low_stock_alert" <?php echo $low_stock_alert == '1' ? 'checked' : ''; ?> class="settings-toggle-checkbox">
                    <div>
                        <div class="settings-toggle-title">تنبيه المخزون المنخفض</div>
                        <div class="settings-toggle-subtitle">عرض تحذير عند وصول المنتجات للحد الأدنى</div>
                    </div>
                </label>
                <div class="settings-tip-box settings-tip-box--danger">
                    ⚠️ يوجد حالياً <strong><?php echo $low_stock_count; ?></strong> منتج في حالة مخزون منخفض
                    <?php if ($low_stock_count > 0): ?>
                    — <a href="<?php echo $base_url; ?>/products/list-products.php?low_stock=1" class="text-danger font-bold">عرضها</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات المبيعات والفواتير ===== -->
        <div class="card settings-section-card">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    إعدادات المبيعات والفواتير
                </h2>
            </div>
            <div class="card-body">
                <label class="settings-toggle-label mb-3" style="border:1.5px solid <?php echo $auto_print == '1' ? '#2563eb' : '#e2e8f0'; ?>; background:<?php echo $auto_print == '1' ? '#eff6ff' : '#fff'; ?>;">
                    <input type="checkbox" name="auto_print" <?php echo $auto_print == '1' ? 'checked' : ''; ?> class="settings-toggle-checkbox">
                    <div>
                        <div class="settings-toggle-title">طباعة الفاتورة تلقائياً</div>
                        <div class="settings-toggle-subtitle">فتح نافذة الطباعة تلقائياً بعد كل عملية بيع</div>
                    </div>
                </label>
                <div>
                    <label class="settings-form-label">نص تذييل الفاتورة</label>
                    <textarea name="invoice_footer" class="form-control" rows="2" placeholder="شكراً لزيارتكم..."><?php echo htmlspecialchars($invoice_footer); ?></textarea>
                    <small class="settings-help-text">يظهر في أسفل الفاتورة المطبوعة</small>
                </div>
            </div>
        </div>

        <!-- ===== معلومات النظام ===== -->
        <div class="card settings-section-card">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    معلومات النظام
                </h2>
            </div>
            <div class="card-body">
                <div class="settings-sysinfo-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="settings-sysinfo-item">
                        <div class="settings-sysinfo-label">إصدار التطبيق</div>
                        <div class="settings-sysinfo-value"><?php echo APP_VERSION; ?></div>
                    </div>
                    <div class="settings-sysinfo-item">
                        <div class="settings-sysinfo-label">إصدار PHP</div>
                        <div class="settings-sysinfo-value"><?php echo PHP_VERSION; ?></div>
                    </div>
                    <div class="settings-sysinfo-item">
                        <div class="settings-sysinfo-label">قاعدة البيانات</div>
                        <div class="settings-sysinfo-value"><?php echo htmlspecialchars(DB_NAME); ?> <small class="settings-help-text font-normal">(<?php echo $db_size; ?> MB)</small></div>
                    </div>
                    <div class="settings-sysinfo-item">
                        <div class="settings-sysinfo-label">الخادم</div>
                        <div class="settings-sysinfo-value"><?php echo htmlspecialchars(DB_HOST); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- زر الحفظ -->
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
            <button type="submit" name="save_settings" value="1" class="btn btn-primary btn-lg" style="min-width:240px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                حفظ الإعدادات
            </button>
            <a href="<?php echo $base_url; ?>/index.php" class="btn btn-outline btn-lg">العودة للرئيسية</a>
        </div>
    </form>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
function toggleTaxSection() {
    var checked = document.getElementById('taxToggle').checked;
    document.getElementById('taxSection').style.display = checked ? 'block' : 'none';
    var label = document.getElementById('taxToggleLabel');
    label.style.borderColor = checked ? '#2563eb' : '#e2e8f0';
    label.style.background = checked ? '#eff6ff' : '#fff';
}
</script>

<?php require_once '../includes/footer.php'; ?>
</html>
