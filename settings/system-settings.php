<?php
// معالج أخطاء شامل لمنع الصفحة الفارغة (blank page)
ob_start();
set_error_handler(function($severity, $message, $file, $line) {
    error_log("system-settings.php [{$severity}]: {$message} in {$file}:{$line}");
    return false;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="UTF-8"><title>خطأ في الإعدادات</title>';
        echo '<style>body{font-family:system-ui,sans-serif;direction:rtl;padding:40px;background:#fef2f2;color:#991b1b;}';
        echo '.error-box{max-width:600px;margin:0 auto;background:#fff;border:2px solid #fca5a5;border-radius:12px;padding:24px;}';
        echo 'h1{font-size:1.2rem;margin-bottom:12px;}p{font-size:0.9rem;color:#64748b;line-height:1.7;}code{background:#fef9c3;padding:2px 6px;border-radius:4px;}</style></head>';
        echo '<body><div class="error-box">';
        echo '<h1>⚠️ خطأ غير متوقع في صفحة الإعدادات</h1>';
        echo '<p>حدث خطأ أثناء تحميل صفحة إعدادات النظام. يرجى المحاولة مرة أخرى أو التواصل مع المطور.</p>';
        echo '<p><code>' . htmlspecialchars(basename($error['file'])) . ':' . $error['line'] . '</code></p>';
        echo '</div></body></html>';
    }
});

$page_title = 'إعدادات النظام';
require_once '../includes/bootstrap.php';
require_once '../config/upgrades.php';
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
requirePermission('settings.manage');

$success_msg = '';
$error_msg = '';

// ===== معالجة حفظ الإعدادات =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        // === هوية التطبيق ===
        saveSetting('app_name', trim($_POST['app_name'] ?? 'نظام إدارة المخزون'), 'اسم التطبيق');
        saveSetting('app_description', trim($_POST['app_description'] ?? ''), 'وصف التطبيق');
        saveSetting('app_logo_color', trim($_POST['app_logo_color'] ?? '#2563eb'), 'لون شعار التطبيق');

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

        // === إعدادات العرض ===
        $items_per_page = in_array(intval($_POST['items_per_page'] ?? 20), [10, 20, 50, 100]) ? intval($_POST['items_per_page'] ?? 20) : 20;
        saveSetting('items_per_page', $items_per_page, 'عدد العناصر في الصفحة');
        $date_format = trim($_POST['date_format'] ?? 'Y-m-d');
        if (!in_array($date_format, ['Y-m-d', 'd/m/Y', 'm/d/Y'])) $date_format = 'Y-m-d';
        saveSetting('date_format', $date_format, 'صيغة التاريخ');
        $default_language = trim($_POST['default_language'] ?? 'ar');
        if (!in_array($default_language, ['ar', 'fr', 'en'])) $default_language = 'ar';
        saveSetting('default_language', $default_language, 'اللغة الافتراضية');

        // === إعدادات الأمان ===
        $session_timeout_minutes = in_array(intval($_POST['session_timeout_minutes'] ?? 30), [30, 60, 120, 240]) ? intval($_POST['session_timeout_minutes'] ?? 30) : 30;
        saveSetting('session_timeout_minutes', $session_timeout_minutes, 'مدة الجلسة (دقيقة)');
        $max_login_attempts = in_array(intval($_POST['max_login_attempts'] ?? 5), [3, 5, 10]) ? intval($_POST['max_login_attempts'] ?? 5) : 5;
        saveSetting('max_login_attempts', $max_login_attempts, 'عدد محاولات تسجيل الدخول');
        $password_min_length = in_array(intval($_POST['password_min_length'] ?? 6), [6, 8, 12]) ? intval($_POST['password_min_length'] ?? 6) : 6;
        saveSetting('password_min_length', $password_min_length, 'الحد الأدنى لطول كلمة المرور');
        // فترة إجبار تغيير كلمة المرور: نحول 'never' إلى 0 (عدد صحيح) والأرقام إلى int
        $force_pwd_interval = trim($_POST['force_password_change_interval_days'] ?? '90');
        if ($force_pwd_interval === 'never' || $force_pwd_interval === '0') {
            $force_pwd_interval = 0;
        } else {
            $force_pwd_interval = in_array(intval($force_pwd_interval), [30, 60, 90]) ? intval($force_pwd_interval) : 90;
        }
        saveSetting('force_password_change_interval_days', $force_pwd_interval, 'فترة إجبار تغيير كلمة المرور (0 = أبداً)');

        // إخفاء شريط التحذير الأمني
        $hide_env_security_banner = isset($_POST['hide_env_security_banner']) ? '1' : '0';
        saveSetting('hide_env_security_banner', $hide_env_security_banner, 'إخفاء شريط التحذير الأمني للبيانات الحساسة');

        // === إعدادات ضرائب المبيعات ===
        $tax_enabled = isset($_POST['tax_enabled']) ? '1' : '0';
        saveSetting('tax_enabled', $tax_enabled, 'تفعيل ضريبة المبيعات');
        saveSetting('tax_rate', floatval($_POST['tax_rate'] ?? 0), 'نسبة ضريبة المبيعات (%)');
        saveSetting('tax_name', trim($_POST['tax_name'] ?? 'ضريبة القيمة المضافة'), 'اسم ضريبة المبيعات');

        // === إعدادات ضرائب المشتريات (حقوق الثَمْبر) ===
        $purchase_tax_enabled = isset($_POST['purchase_tax_enabled']) ? '1' : '0';
        saveSetting('purchase_tax_enabled', $purchase_tax_enabled, 'تفعيل ضريبة المشتريات');
        saveSetting('purchase_tax_default_name', trim($_POST['purchase_tax_default_name'] ?? 'حقوق الثَمْبر'), 'اسم الضريبة الافتراضي للمشتريات');
        $pt_rate = floatval($_POST['purchase_tax_default_rate'] ?? 0.50);
        $pt_rate = max(0, min(100, $pt_rate));
        saveSetting('purchase_tax_default_rate', $pt_rate, 'نسبة/مبلغ الضريبة الافتراضي للمشتريات');
        $pt_type = trim($_POST['purchase_tax_default_type'] ?? 'percentage');
        if (!in_array($pt_type, ['percentage', 'fixed'])) $pt_type = 'percentage';
        saveSetting('purchase_tax_default_type', $pt_type, 'نوع الضريبة الافتراضي للمشتريات');

        // === إعدادات المخزون ===
        $low_stock_alert = isset($_POST['low_stock_alert']) ? '1' : '0';
        saveSetting('low_stock_alert', $low_stock_alert, 'تفعيل تنبيه المخزون المنخفض');
        $low_stock_threshold = max(1, intval($_POST['low_stock_threshold'] ?? 5));
        saveSetting('low_stock_threshold', $low_stock_threshold, 'عتبة المخزون المنخفض');

        // === إعدادات تنبيهات الصلاحية ===
        $expiry_alert_enabled = isset($_POST['expiry_alert_enabled']) ? '1' : '0';
        saveSetting('expiry_alert_enabled', $expiry_alert_enabled, 'تفعيل تنبيهات الصلاحية');
        $expiry_alert_days = max(1, intval($_POST['expiry_alert_days'] ?? 30));
        saveSetting('expiry_alert_days', $expiry_alert_days, 'عدد أيام تنبيه الصلاحية');
        $expiry_block_sale = isset($_POST['expiry_block_sale']) ? '1' : '0';
        saveSetting('expiry_block_sale', $expiry_block_sale, 'منع بيع المنتجات المنتهية');

        // === إعدادات الإشعارات ===
        $notifications_enabled = isset($_POST['notifications_enabled']) ? '1' : '0';
        saveSetting('notifications_enabled', $notifications_enabled, 'تفعيل نظام الإشعارات');
        $notif_poll_interval = max(10, intval($_POST['notif_poll_interval'] ?? 30));
        saveSetting('notif_poll_interval', $notif_poll_interval, 'فترة استطلاع الإشعارات (ثانية)');
        $notif_sound_enabled = isset($_POST['notif_sound_enabled']) ? '1' : '0';
        saveSetting('notif_sound_enabled', $notif_sound_enabled, 'تفعيل صوت الإشعارات');

        // إعداد SSE (Server-Sent Events) — v6.3.0
        $notifications_sse_enabled = isset($_POST['notifications_sse_enabled']) ? '1' : '0';
        saveSetting('notifications_sse_enabled', $notifications_sse_enabled, 'تفعيل Server-Sent Events للإشعارات الفورية (1 = مفعل، 0 = polling فقط)');

        // إعداد TTL للإشعارات غير المقروءة — v6.3.0
        $notification_ttl_days = max(1, min(365, intval($_POST['notification_ttl_days'] ?? 90)));
        saveSetting('notification_ttl_days', $notification_ttl_days, 'عدد الأيام قبل تعيين الإشعارات غير المقروءة كمقروءة تلقائياً');

        // إعداد إشعارات المتصفح (Browser Push)
        $push_notifications_enabled = isset($_POST['push_notifications_enabled']) ? '1' : '0';
        saveSetting('push_notifications_enabled', $push_notifications_enabled, 'تفعيل إشعارات المتصفح (Browser Push)');

        // إعداد مفاتيح VAPID — توليد تلقائي إذا لم تكن موجودة
        if ($push_notifications_enabled === '1') {
            if (!class_exists('VapidKeys')) {
                require_once __DIR__ . '/../includes/vapid-keys.php';
            }
            if (!VapidKeys::isConfigured()) {
                VapidKeys::generateAndSave();
            }
        }

        // حفظ تفضيلات أنواع الإشعارات
        $notif_types = ['sale', 'purchase', 'product', 'stock', 'expiry', 'expense', 'user', 'system', 'backup'];
        foreach ($notif_types as $nt) {
            $nt_enabled = isset($_POST['notif_type_' . $nt]) ? '1' : '0';
            saveSetting('notif_type_' . $nt, $nt_enabled, 'تفعيل إشعارات ' . $nt);
        }

        // === إعدادات النسخ الاحتياطي ===
        $auto_backup_enabled = isset($_POST['auto_backup_enabled']) ? '1' : '0';
        saveSetting('auto_backup_enabled', $auto_backup_enabled, 'تفعيل النسخ الاحتياطي التلقائي');
        $auto_backup_frequency = trim($_POST['auto_backup_frequency'] ?? 'daily');
        if (!in_array($auto_backup_frequency, ['daily', 'weekly', 'monthly'])) $auto_backup_frequency = 'daily';
        saveSetting('auto_backup_frequency', $auto_backup_frequency, 'تكرار النسخ الاحتياطي');
        $backup_retention_count = max(1, intval($_POST['backup_retention_count'] ?? 7));
        saveSetting('backup_retention_count', $backup_retention_count, 'عدد النسخ المحفوظة');

        // طريقة النسخ الاحتياطي (auto / mysqldump / php)
        $backup_method = trim($_POST['backup_method'] ?? 'auto');
        if (!in_array($backup_method, ['auto', 'mysqldump', 'php'])) $backup_method = 'auto';
        saveSetting('backup_method', $backup_method, 'طريقة النسخ الاحتياطي');

        // مسار mysqldump المخصص
        $mysqldump_path = trim($_POST['backup_mysqldump_path'] ?? '');
        saveSetting('backup_mysqldump_path', $mysqldump_path, 'مسار mysqldump المخصص');

        // مفتاح أمان cron (يُولَّد تلقائياً إذا لم يُدخل المستخدم واحدًا)
        $cron_key = trim($_POST['cron_secret_key'] ?? '');
        if (empty($cron_key)) {
            // توليد مفتاح عشوائي إذا لم يوجد
            $cron_key = bin2hex(random_bytes(16));
        }
        saveSetting('cron_secret_key', $cron_key, 'مفتاح أمان cron للنسخ التلقائي');

        // === إعدادات الفواتير ===
        saveSetting('invoice_header_text', trim($_POST['invoice_header_text'] ?? ''), 'نص رأس الفاتورة');
        saveSetting('invoice_footer', trim($_POST['invoice_footer'] ?? ''), 'نص تذييل الفاتورة');
        $show_logo_on_invoice = isset($_POST['show_logo_on_invoice']) ? '1' : '0';
        saveSetting('show_logo_on_invoice', $show_logo_on_invoice, 'عرض الشعار في الفاتورة');
        $show_store_info_on_invoice = isset($_POST['show_store_info_on_invoice']) ? '1' : '0';
        saveSetting('show_store_info_on_invoice', $show_store_info_on_invoice, 'عرض معلومات المتجر في الفاتورة');

        // === إعدادات المبيعات ===
        $auto_print = isset($_POST['auto_print']) ? '1' : '0';
        saveSetting('auto_print_invoice', $auto_print, 'طباعة الفاتورة تلقائياً بعد البيع');
        $product_limit = intval($_POST['product_limit'] ?? 100);
        // 0 = عرض الكل، وإلا بين 10 و 5000
        if ($product_limit !== 0) {
            $product_limit = max(10, min(5000, $product_limit));
        }
        saveSetting('product_limit', $product_limit, 'حد المنتجات في تقارير الأرباح');

        // === إعدادات نقطة البيع (POS) ===
        saveSetting('pos_theme', trim($_POST['pos_theme'] ?? 'light'), 'سمة نقطة البيع');
        $pos_sound_enabled = isset($_POST['pos_sound_enabled']) ? '1' : '0';
        saveSetting('pos_sound_enabled', $pos_sound_enabled, 'صوت نقطة البيع');
        $pos_auto_complete = isset($_POST['pos_auto_complete']) ? '1' : '0';
        saveSetting('pos_auto_complete', $pos_auto_complete, 'إكمال تلقائي في نقطة البيع');

        // === إعدادات الطابعة الحرارية ===
        $thermal_printer_enabled = isset($_POST['thermal_printer_enabled']) ? '1' : '0';
        saveSetting('thermal_printer_enabled', $thermal_printer_enabled, 'تفعيل الطابعة الحرارية');
        saveSetting('thermal_printer_type', trim($_POST['thermal_printer_type'] ?? 'browser'), 'نوع اتصال الطابعة الحرارية');
        saveSetting('thermal_printer_ip', trim($_POST['thermal_printer_ip'] ?? '192.168.1.100'), 'عنوان IP الطابعة الحرارية');
        saveSetting('thermal_printer_port', trim($_POST['thermal_printer_port'] ?? '9100'), 'منفذ الطابعة الحرارية');
        saveSetting('thermal_printer_width', trim($_POST['thermal_printer_width'] ?? '80mm'), 'عرض ورق الطابعة الحرارية');
        $thermal_auto_print = isset($_POST['thermal_auto_print']) ? '1' : '0';
        saveSetting('thermal_auto_print', $thermal_auto_print, 'طباعة تلقائية على الطابعة الحرارية بعد البيع');
        $thermal_auto_cut = isset($_POST['thermal_auto_cut']) ? '1' : '0';
        saveSetting('thermal_auto_cut', $thermal_auto_cut, 'قص تلقائي للورق بعد الطباعة');
        $thermal_cash_drawer = isset($_POST['thermal_cash_drawer']) ? '1' : '0';
        saveSetting('thermal_cash_drawer', $thermal_cash_drawer, 'فتح درج النقود بعد الطباعة');
        saveSetting('thermal_receipt_footer', trim($_POST['thermal_receipt_footer'] ?? ''), 'نص تذييل إيصال الطابعة الحرارية');

        $success_msg = 'تم حفظ جميع الإعدادات بنجاح';
    } catch (Exception $e) {
        error_log('Settings Save Error: ' . $e->getMessage());
        $error_msg = 'حدث خطأ أثناء حفظ الإعدادات. يرجى المحاولة مرة أخرى.';
    }
}

// ===== معالجة حفظ إعدادات كاش بنية الجدول =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schema_cache_settings'])) {
    try {
        $schema_cache_enabled = isset($_POST['schema_cache_enabled']) ? '1' : '0';
        saveSetting('schema_cache_enabled', $schema_cache_enabled, 'تفعيل كاش بنية الجدول');

        $schema_cache_ttl = intval($_POST['schema_cache_ttl'] ?? 86400);
        if ($schema_cache_ttl < 60) $schema_cache_ttl = 60;
        if ($schema_cache_ttl > 604800) $schema_cache_ttl = 604800; // أقصى 7 أيام
        saveSetting('schema_cache_ttl', (string)$schema_cache_ttl, 'مدة صلاحية كاش بنية الجدول (ثواني)');

        // إعادة تعيين SchemaCache لتفعيل الإعدادات الجديدة
        SchemaCache::resetInstance();

        $success_msg = 'تم حفظ إعدادات الكاش بنجاح';
    } catch (Exception $e) {
        error_log('Schema Cache Settings Save Error: ' . $e->getMessage());
        $error_msg = 'خطأ في حفظ إعدادات الكاش: ' . $e->getMessage();
    }
}

// ===== معالجة مسح كاش بنية الجدول =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_schema_cache'])) {
    try {
        $cleared = SchemaCache::flush();
        SchemaCache::resetInstance();
        $success_msg = "تم مسح الكاش بنجاح — حُذف {$cleared} ملف";
    } catch (Exception $e) {
        error_log('Schema Cache Clear Error: ' . $e->getMessage());
        $error_msg = 'خطأ في مسح الكاش: ' . $e->getMessage();
    }
}



// ===== جلب الإعدادات الحالية =====

// === هوية التطبيق ===
$app_name = getSetting('app_name', 'نظام إدارة المخزون');
$app_description = getSetting('app_description', 'نظام متكامل لإدارة المخزون والمبيعات');
$app_logo_color = getSetting('app_logo_color', '#2563eb');
$page_title = htmlspecialchars($app_name) . ' - إعدادات النظام';

// === معلومات المتجر ===
$store_name = getSetting('store_name', '');
$store_phone = getSetting('store_phone', '');
$store_address = getSetting('store_address', '');
$store_city = getSetting('store_city', '');

// === إعدادات العملة ===
$currency_symbol = getSetting('currency_symbol', 'د.م.');
$currency_code = getSetting('currency_code', 'MAD');
$currency_name = getSetting('currency_name', 'درهم مغربي');
$decimals = getSetting('decimals', '2');

// === إعدادات العرض ===
$items_per_page = getSetting('items_per_page', '20');
$date_format = getSetting('date_format', 'Y-m-d');
$default_language = getSetting('default_language', 'ar');

// === إعدادات الأمان ===
$session_timeout_minutes = getSetting('session_timeout_minutes', '30');
$max_login_attempts = getSetting('max_login_attempts', '5');
$password_min_length = getSetting('password_min_length', '6');
$force_password_change_interval_days = getSetting('force_password_change_interval_days', '90');
// التأكد من أن القيمة عدد صحيح (توافق مع القيم القديمة 'never')
if ($force_password_change_interval_days === 'never') {
    $force_password_change_interval_days = 0;
}
$force_password_change_interval_days = intval($force_password_change_interval_days);
$hide_env_security_banner = getSetting('hide_env_security_banner', '0');

// === إعدادات ضرائب المبيعات ===
$tax_enabled = getSetting('tax_enabled', '0');
$tax_rate = getSetting('tax_rate', '20');
$tax_name = getSetting('tax_name', 'ضريبة القيمة المضافة');

// === إعدادات ضرائب المشتريات (حقوق الثَمْبر) ===
$purchase_tax_enabled = getSetting('purchase_tax_enabled', '0');
$purchase_tax_default_name = getSetting('purchase_tax_default_name', 'حقوق الثَمْبر');
$purchase_tax_default_rate = getSetting('purchase_tax_default_rate', '0.50');
$purchase_tax_default_type = getSetting('purchase_tax_default_type', 'percentage');

// === إعدادات المخزون والتنبيهات ===
$low_stock_alert = getSetting('low_stock_alert', '1');
$low_stock_threshold = getSetting('low_stock_threshold', '5');
$expiry_alert_enabled = getSetting('expiry_alert_enabled', '1');
$expiry_alert_days = getSetting('expiry_alert_days', '30');
$expiry_block_sale = getSetting('expiry_block_sale', '0');

// === إعدادات الإشعارات ===
$notifications_enabled = getSetting('notifications_enabled', '1');
$notif_poll_interval = getSetting('notif_poll_interval', '30');
$notif_sound_enabled = getSetting('notif_sound_enabled', '1');
$notifications_sse_enabled = getSetting('notifications_sse_enabled', '0'); // v6.3.0 — معطّل افتراضياً
$notification_ttl_days = getSetting('notification_ttl_days', '90'); // v6.3.0
$push_notifications_enabled = getSetting('push_notifications_enabled', '0');
$notif_type_settings = [];
$notif_type_labels = ['sale'=>'مبيعات','purchase'=>'مشتريات','product'=>'منتجات','stock'=>'مخزون','expiry'=>'صلاحية','expense'=>'مصاريف','user'=>'مستخدمين','system'=>'نظام','backup'=>'نسخ احتياطي'];
foreach (array_keys($notif_type_labels) as $nt) {
    $notif_type_settings[$nt] = getSetting('notif_type_' . $nt, '1');
}

// === إعدادات النسخ الاحتياطي ===
$auto_backup_enabled = getSetting('auto_backup_enabled', '0');
$auto_backup_frequency = getSetting('auto_backup_frequency', 'daily');
$backup_retention_count = getSetting('backup_retention_count', '7');
$last_auto_backup = getSetting('last_auto_backup', '');
$cron_secret_key = getSetting('cron_secret_key', '');
$backup_method = getSetting('backup_method', 'auto');
$backup_mysqldump_path = getSetting('backup_mysqldump_path', '');

// === إعدادات كاش بنية الجدول ===
$schema_cache_enabled = getSetting('schema_cache_enabled', '1');
$schema_cache_ttl = getSetting('schema_cache_ttl', '86400');
$schema_cache_stats = null;
try {
    $schema_cache_stats = SchemaCache::getInstance()->getStats();
} catch (Throwable $e) {
    $schema_cache_stats = null;
}

// === إعدادات الفواتير ===
$invoice_header_text = getSetting('invoice_header_text', '');
$invoice_footer = getSetting('invoice_footer', '');
$show_logo_on_invoice = getSetting('show_logo_on_invoice', '1');
$show_store_info_on_invoice = getSetting('show_store_info_on_invoice', '1');

// === إعدادات المبيعات ===
$auto_print = getSetting('auto_print_invoice', '0');
$product_limit = getSetting('product_limit', '100');

// === إعدادات نقطة البيع ===
$pos_theme = getSetting('pos_theme', 'light');
$pos_sound_enabled = getSetting('pos_sound_enabled', '1');
$pos_auto_complete = getSetting('pos_auto_complete', '0');

// === إعدادات الطابعة الحرارية ===
$thermal_printer_enabled = getSetting('thermal_printer_enabled', '0');
$thermal_printer_type = getSetting('thermal_printer_type', 'browser');
$thermal_printer_ip = getSetting('thermal_printer_ip', '192.168.1.100');
$thermal_printer_port = getSetting('thermal_printer_port', '9100');
$thermal_printer_width = getSetting('thermal_printer_width', '80mm');
$thermal_auto_print = getSetting('thermal_auto_print', '0');
$thermal_auto_cut = getSetting('thermal_auto_cut', '1');
$thermal_cash_drawer = getSetting('thermal_cash_drawer', '0');
$thermal_receipt_footer = getSetting('thermal_receipt_footer', '');

// ===== إحصائيات النظام =====
$total_products = 0;
$total_categories = 0;
$total_users = 0;
$total_sales_today = 0;
$low_stock_count = 0;
$db_size = 0;
$total_sales = 0;
$total_purchases = 0;
try {
    $total_products = fetchOne("SELECT COUNT(*) as c FROM products")['c'];
    $total_categories = fetchOne("SELECT COUNT(*) as c FROM categories")['c'];
    $total_users = fetchOne("SELECT COUNT(*) as c FROM users")['c'];
    $total_sales_today = fetchOne("SELECT COUNT(*) as c FROM sales WHERE sale_date = CURDATE()")['c'];
    $low_stock_count = fetchOne("SELECT COUNT(*) as c FROM products WHERE quantity <= min_quantity OR (min_quantity = 0 AND quantity <= ?)", [$low_stock_threshold])['c'];
    $total_sales = fetchOne("SELECT COUNT(*) as c FROM sales")['c'];
    $total_purchases = fetchOne("SELECT COUNT(*) as c FROM purchases")['c'];
    $db_row = fetchOne("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = ?", [DB_NAME]);
    $db_size = $db_row ? $db_row['size'] : 0;
} catch (Exception $e) {
    ErrorHandler::logException($e, 'system-settings::fetchStats');
}
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
            <span style="font-size:0.75rem; font-weight:400; color:#94a3b8; margin-right:8px;">مدير النظام فقط</span>
            <a href="db-settings.php" style="font-size:0.78rem; font-weight:600; color:#7c3aed; text-decoration:none; margin-right:12px; display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border:1px solid #d8b4fe; border-radius:6px; background:#f5f3ff;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                إعدادات قاعدة البيانات
            </a>
        </h1>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" style="display:flex; align-items:center; gap:8px; margin-bottom:1rem;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#059669; flex-shrink:0;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php echo htmlspecialchars($success_msg); ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger" style="display:flex; align-items:center; gap:8px; margin-bottom:1rem;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:#dc2626; flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>

    <!-- إحصائيات سريعة -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-bottom:1.5rem;">
        <div style="background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #bfdbfe; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#2563eb;"><?php echo $total_products; ?></div>
            <div style="font-size:0.78rem; color:#3b82f6; font-weight:600;">منتج</div>
        </div>
        <div style="background:linear-gradient(135deg, #f0fdf4, #dcfce7); border:1px solid #bbf7d0; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#16a34a;"><?php echo $total_categories; ?></div>
            <div style="font-size:0.78rem; color:#22c55e; font-weight:600;">تصنيف</div>
        </div>
        <div style="background:linear-gradient(135deg, #fef3c7, #fde68a); border:1px solid #fcd34d; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#d97706;"><?php echo $total_sales_today; ?></div>
            <div style="font-size:0.78rem; color:#ca8a04; font-weight:600;">مبيعات اليوم</div>
        </div>
        <div style="background:linear-gradient(135deg, #fef2f2, #fecaca); border:1px solid #fca5a5; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#dc2626;"><?php echo $low_stock_count; ?></div>
            <div style="font-size:0.78rem; color:#ef4444; font-weight:600;">مخزون منخفض</div>
        </div>
        <div style="background:linear-gradient(135deg, #f5f3ff, #e9d5ff); border:1px solid #d8b4fe; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#7c3aed;"><?php echo $total_users; ?></div>
            <div style="font-size:0.78rem; color:#8b5cf6; font-weight:600;">مستخدم</div>
        </div>
        <div style="background:linear-gradient(135deg, #f0f9ff, #bae6fd); border:1px solid #7dd3fc; border-radius:12px; padding:14px; text-align:center;">
            <div style="font-size:1.6rem; font-weight:900; color:#0284c7;"><?php echo $db_size; ?></div>
            <div style="font-size:0.78rem; color:#0ea5e9; font-weight:600;">حجم قاعدة البيانات (MB)</div>
        </div>
    </div>

    <form method="POST" action="system-settings.php">
        <?php echo csrfField(); ?>

        <!-- ===== هوية التطبيق ===== -->
        <div class="card" style="margin-bottom:1.5rem; border:2px solid var(--primary, #2563eb); position:relative; overflow:hidden;">
            <div style="position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg, var(--primary, #2563eb), #7c3aed, #2563eb);"></div>
            <div class="card-header" style="background:linear-gradient(135deg, rgba(37,99,235,0.05), rgba(124,58,237,0.05));">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    هوية التطبيق
                    <span style="font-size:0.7rem; font-weight:400; color:#94a3b8; margin-right:6px;">الشكل والاسم الأساسي للتطبيق</span>
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-weight:700; margin-bottom:4px; font-size:0.9rem; color:#1e293b;">
                            اسم التطبيق <span style="color:#dc2626;">*</span>
                            <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">يظهر في شريط التنقل وكل صفحات النظام</span>
                        </label>
                        <input type="text" name="app_name" id="app_name_input" class="form-control" value="<?php echo htmlspecialchars($app_name); ?>" placeholder="نظام إدارة المخزون" required style="font-size:1rem; font-weight:700; padding:12px 14px;" oninput="updateAppPreview()">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">وصف التطبيق</label>
                        <input type="text" name="app_description" class="form-control" value="<?php echo htmlspecialchars($app_description); ?>" placeholder="نظام متكامل لإدارة المخزون والمبيعات">
                        <small style="color:#94a3b8; font-size:0.75rem;">وصف مختصر يظهر في لوحة التحكم</small>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">لون الشعار</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="color" name="app_logo_color" id="app_logo_color_input" value="<?php echo htmlspecialchars($app_logo_color); ?>" style="width:44px; height:44px; border:2px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:2px;" oninput="updateAppPreview()">
                            <input type="text" id="app_logo_color_text" class="form-control" value="<?php echo htmlspecialchars($app_logo_color); ?>" style="max-width:120px; direction:ltr; text-align:center; font-family:monospace; font-size:0.85rem;" oninput="syncColorFromText()">
                            <small style="color:#94a3b8; font-size:0.75rem;">يظهر في الشعار والأيقونات</small>
                        </div>
                    </div>
                </div>

                <!-- معاينة شريط التنقل -->
                <div style="margin-top:16px;">
                    <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.82rem; color:#64748b;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="vertical-align:middle; margin-left:4px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        معاينة شريط التنقل
                    </label>
                    <div class="app-preview" id="app_preview" style="background:var(--dark, #1e293b); border-radius:var(--radius-lg, 12px); padding:12px 16px; display:flex; align-items:center; gap:10px; border:1px solid #334155;">
                        <div id="app_preview_logo" style="color:<?php echo htmlspecialchars($app_logo_color); ?>; font-size:20px; flex-shrink:0;">⬡</div>
                        <div id="app_preview_name" style="color:white; font-weight:700; font-size:1rem;"><?php echo htmlspecialchars($app_name); ?></div>
                        <div style="flex:1;"></div>
                        <div style="display:flex; gap:14px; align-items:center;">
                            <div style="width:18px; height:18px; background:#475569; border-radius:4px;"></div>
                            <div style="width:18px; height:18px; background:#475569; border-radius:4px;"></div>
                            <div style="width:18px; height:18px; background:#475569; border-radius:50%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== معلومات المتجر ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    معلومات المتجر
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">اسم المتجر <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="store_name" class="form-control" value="<?php echo htmlspecialchars($store_name); ?>" placeholder="مثال: متجر النور" required>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">رقم الهاتف</label>
                        <input type="text" name="store_phone" class="form-control" value="<?php echo htmlspecialchars($store_phone); ?>" placeholder="06XXXXXXXX" style="direction:ltr; text-align:right;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">المدينة</label>
                        <input type="text" name="store_city" class="form-control" value="<?php echo htmlspecialchars($store_city); ?>" placeholder="الدار البيضاء">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">العنوان التفصيلي</label>
                        <input type="text" name="store_address" class="form-control" value="<?php echo htmlspecialchars($store_address); ?>" placeholder="الحي، الشارع، رقم...">
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات العملة ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    إعدادات العملة
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">رمز العملة</label>
                        <input type="text" name="currency_symbol" class="form-control" value="<?php echo htmlspecialchars($currency_symbol); ?>" placeholder="د.م.">
                        <small style="color:#94a3b8; font-size:0.75rem;">مثال: د.م. أو $ أو €</small>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">الرمز الدولي</label>
                        <input type="text" name="currency_code" class="form-control" value="<?php echo htmlspecialchars($currency_code); ?>" placeholder="MAD" style="direction:ltr; text-align:right;">
                        <small style="color:#94a3b8; font-size:0.75rem;">مثال: MAD, EUR, USD</small>
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">اسم العملة</label>
                        <input type="text" name="currency_name" class="form-control" value="<?php echo htmlspecialchars($currency_name); ?>" placeholder="درهم مغربي">
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">عدد الأرقام العشرية</label>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $decimals == '2' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $decimals == '2' ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="decimals" value="2" <?php echo $decimals == '2' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            خانتين (0.00)
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $decimals == '3' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $decimals == '3' ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="decimals" value="3" <?php echo $decimals == '3' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            ثلاث خانات (0.000)
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $decimals == '0' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $decimals == '0' ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="decimals" value="0" <?php echo $decimals == '0' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            بدون كسور (0)
                        </label>
                    </div>
                </div>
                <div style="margin-top:10px; padding:10px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-size:0.85rem; color:#166534;">
                    💡 مثال: <strong><?php echo number_format(1250.5, intval($decimals)); ?> <?php echo htmlspecialchars($currency_symbol); ?></strong>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات العرض ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    إعدادات العرض
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">

                    <!-- عدد العناصر في الصفحة -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">عدد العناصر في الصفحة</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ([10, 20, 50, 100] as $ipp_val): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $items_per_page == $ipp_val ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $items_per_page == $ipp_val ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="items_per_page" value="<?php echo $ipp_val; ?>" <?php echo $items_per_page == $ipp_val ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <?php echo $ipp_val; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">عدد المنتجات أو السجلات في كل صفحة</small>
                    </div>

                    <!-- صيغة التاريخ -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">صيغة التاريخ</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php
                            $date_formats = [
                                'Y-m-d' => '2025-01-15',
                                'd/m/Y' => '15/01/2025',
                                'm/d/Y' => '01/15/2025',
                            ];
                            foreach ($date_formats as $df_key => $df_label):
                            ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $date_format == $df_key ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $date_format == $df_key ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s; direction:ltr;">
                                <input type="radio" name="date_format" value="<?php echo $df_key; ?>" <?php echo $date_format == $df_key ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <?php echo $df_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">الصيغة المستخدمة في عرض التواريخ</small>
                    </div>

                    <!-- اللغة الافتراضية -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">اللغة الافتراضية</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php
                            $languages = [
                                'ar' => '🇸🇦 العربية',
                                'fr' => '🇫🇷 الفرنسية',
                                'en' => '🇬🇧 الإنجليزية',
                            ];
                            foreach ($languages as $lang_key => $lang_label):
                            ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $default_language == $lang_key ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $default_language == $lang_key ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="default_language" value="<?php echo $lang_key; ?>" <?php echo $default_language == $lang_key ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <?php echo $lang_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">اللغة الافتراضية لواجهة المستخدم</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات الأمان ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    إعدادات الأمان
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">

                    <!-- مدة الجلسة -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">مدة الجلسة (دقيقة)</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ([30, 60, 120, 240] as $st_val): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $session_timeout_minutes == $st_val ? '#dc2626' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $session_timeout_minutes == $st_val ? '#fee2e2' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="session_timeout_minutes" value="<?php echo $st_val; ?>" <?php echo $session_timeout_minutes == $st_val ? 'checked' : ''; ?> style="accent-color:#dc2626;">
                                <?php echo $st_val >= 60 ? ($st_val / 60) . ' ساعة' : $st_val . ' دقيقة'; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">بعد انتهاء المدة يتم تسجيل الخروج تلقائياً</small>
                    </div>

                    <!-- محاولات تسجيل الدخول -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">الحد الأقصى لمحاولات تسجيل الدخول</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ([3, 5, 10] as $mla_val): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $max_login_attempts == $mla_val ? '#dc2626' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $max_login_attempts == $mla_val ? '#fee2e2' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="max_login_attempts" value="<?php echo $mla_val; ?>" <?php echo $max_login_attempts == $mla_val ? 'checked' : ''; ?> style="accent-color:#dc2626;">
                                <?php echo $mla_val; ?> محاولات
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">عدد المحاولات قبل قفل الحساب</small>
                    </div>

                    <!-- الحد الأدنى لطول كلمة المرور -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">الحد الأدنى لطول كلمة المرور</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ([6, 8, 12] as $pml_val): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $password_min_length == $pml_val ? '#dc2626' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $password_min_length == $pml_val ? '#fee2e2' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="password_min_length" value="<?php echo $pml_val; ?>" <?php echo $password_min_length == $pml_val ? 'checked' : ''; ?> style="accent-color:#dc2626;">
                                <?php echo $pml_val; ?> حرف
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">الحد الأدنى المطلوب عند إنشاء كلمة مرور</small>
                    </div>

                    <!-- فترة إجبار تغيير كلمة المرور -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">إجبار تغيير كلمة المرور كل</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ([30 => '30 يوم', 60 => '60 يوم', 90 => '90 يوم', 0 => 'أبداً'] as $fpd_key => $fpd_label): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $force_password_change_interval_days == $fpd_key ? '#dc2626' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $force_password_change_interval_days == $fpd_key ? '#fee2e2' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="force_password_change_interval_days" value="<?php echo $fpd_key; ?>" <?php echo $force_password_change_interval_days == $fpd_key ? 'checked' : ''; ?> style="accent-color:#dc2626;">
                                <?php echo $fpd_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">يُطلب من المستخدم تغيير كلمة المرور تلقائياً</small>
                    </div>
                </div>

                <!-- إخفاء شريط التحذير الأمني -->
                <div style="margin-top:16px; padding:12px 14px; border:1.5px solid <?php echo $hide_env_security_banner == '1' ? '#fca5a5' : '#fecaca'; ?>; border-radius:10px; background:<?php echo $hide_env_security_banner == '1' ? '#fef2f2' : '#fff5f5'; ?>;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="hide_env_security_banner" id="hide_env_security_banner_cb" <?php echo $hide_env_security_banner == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#dc2626;" onchange="if(!this.checked) localStorage.removeItem('ism_hide_env_banner');">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem; color:#991b1b;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M1 1l22 22"/><path d="M16.72 11.06A10.94 10.94 0 0119 12.55"/><path d="M5 12.55a10.94 10.94 0 015.17-2.39"/><path d="M10.71 5.05A16 16 0 0122.56 9"/><path d="M1.42 9a15.91 15.91 0 014.7-2.88"/><path d="M8.53 16.11a6 6 0 016.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
                                إخفاء شريط التحذير الأمني
                            </div>
                            <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                                إخفاء الشريط الأحمر العلوي الذي يُحذّر من بيانات الاعتماد الضعيفة (REDIS_PASSWORD, DB_PASS, إلخ). التحذيرات تظل مسجّلة في سجل الأخطاء. يمكنك أيضاً الضغط على زر × في الشريط لإخفائه مؤقتاً.
                            </div>
                        </div>
                    </label>
                    <?php if ($hide_env_security_banner == '1'): ?>
                    <div style="margin-top:8px; padding:8px 10px; background:#fef9c3; border:1px solid #fde68a; border-radius:6px; font-size:0.75rem; color:#92400e;">
                        ⚠️ التحذيرات الأمنية مخفية حالياً. يُنصح بتشفير القيم الحساسة عبر <code style="background:#fef9c3; padding:1px 4px; border-radius:3px;">php scripts/encrypt-env-helper.php</code> ثم إلغاء الإخفاء.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات الضرائب ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    إعدادات الضرائب
                </h2>
            </div>
            <div class="card-body">

                <!-- === ضرائب المبيعات === -->
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="font-size:0.95rem; font-weight:700; color:#1e293b; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #e2e8f0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                        ضرائب المبيعات
                    </h3>
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px; padding:10px 14px; border:1.5px solid <?php echo $tax_enabled == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $tax_enabled == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;" id="taxToggleLabel">
                        <input type="checkbox" name="tax_enabled" <?php echo $tax_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;" id="taxToggle" onchange="toggleTaxSection()">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem;">تفعيل الضريبة على المبيعات</div>
                            <div style="font-size:0.75rem; color:#64748b;">إضافة ضريبة تلقائياً على فواتير البيع</div>
                        </div>
                    </label>
                    <div id="taxSection" style="display:<?php echo $tax_enabled == '1' ? 'block' : 'none'; ?>;">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">نسبة الضريبة (%)</label>
                                <input type="number" name="tax_rate" class="form-control" value="<?php echo htmlspecialchars($tax_rate); ?>" min="0" max="100" step="0.01" placeholder="20">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">اسم الضريبة</label>
                                <input type="text" name="tax_name" class="form-control" value="<?php echo htmlspecialchars($tax_name); ?>" placeholder="ضريبة القيمة المضافة">
                            </div>
                        </div>
                        <div style="margin-top:10px; padding:10px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; font-size:0.85rem; color:#92400e;">
                            💡 مثال: منتج بـ <strong>100 <?php echo htmlspecialchars($currency_symbol); ?></strong> + ضريبة <strong><?php echo htmlspecialchars($tax_rate); ?>%</strong> = <strong><?php echo number_format(100 * (1 + floatval($tax_rate) / 100), intval($decimals)); ?> <?php echo htmlspecialchars($currency_symbol); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- === ضرائب المشتريات (حقوق الثَمْبر) === -->
                <div style="padding-top: 1rem; border-top: 2px dashed #e2e8f0;">
                    <h3 style="font-size:0.95rem; font-weight:700; color:#1e293b; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #e2e8f0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        ضرائب المشتريات (حقوق الثَمْبر)
                        <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">تُضاف تلقائياً عند تسجيل عملية شراء</span>
                    </h3>
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px; padding:10px 14px; border:1.5px solid <?php echo $purchase_tax_enabled == '1' ? '#6366f1' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $purchase_tax_enabled == '1' ? '#eef2ff' : '#fff'; ?>; transition:all 0.15s;" id="purchaseTaxToggleLabel">
                        <input type="checkbox" name="purchase_tax_enabled" <?php echo $purchase_tax_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#6366f1;" id="purchaseTaxToggle" onchange="togglePurchaseTaxSection()">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem;">تفعيل الضريبة على المشتريات</div>
                            <div style="font-size:0.75rem; color:#64748b;">إضافة حقوق الثَمْبر أو أي ضريبة عند تسجيل الشراء</div>
                        </div>
                    </label>
                    <div id="purchaseTaxSection" style="display:<?php echo $purchase_tax_enabled == '1' ? 'block' : 'none'; ?>;">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                            <div style="grid-column: 1 / -1;">
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">اسم الضريبة الافتراضي</label>
                                <input type="text" name="purchase_tax_default_name" class="form-control" value="<?php echo htmlspecialchars($purchase_tax_default_name); ?>" placeholder="حقوق الثَمْبر">
                                <small style="color:#94a3b8; font-size:0.75rem;">يظهر كاسم افتراضي في صفحة تسجيل المشتريات (يمكن تعديله لكل عملية)</small>
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">نوع الضريبة</label>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $purchase_tax_default_type == 'percentage' ? '#6366f1' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $purchase_tax_default_type == 'percentage' ? '#eef2ff' : '#fff'; ?>; transition:all 0.15s;">
                                        <input type="radio" name="purchase_tax_default_type" value="percentage" <?php echo $purchase_tax_default_type == 'percentage' ? 'checked' : ''; ?> style="accent-color:#6366f1;">
                                        نسبة مئوية (%)
                                    </label>
                                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $purchase_tax_default_type == 'fixed' ? '#6366f1' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $purchase_tax_default_type == 'fixed' ? '#eef2ff' : '#fff'; ?>; transition:all 0.15s;">
                                        <input type="radio" name="purchase_tax_default_type" value="fixed" <?php echo $purchase_tax_default_type == 'fixed' ? 'checked' : ''; ?> style="accent-color:#6366f1;">
                                        مبلغ ثابت
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">القيمة الافتراضية <?php echo $purchase_tax_default_type == 'percentage' ? '(%)' : '(' . htmlspecialchars($currency_symbol) . ')'; ?></label>
                                <input type="number" name="purchase_tax_default_rate" class="form-control" value="<?php echo htmlspecialchars($purchase_tax_default_rate); ?>" min="0" step="0.01" placeholder="<?php echo $purchase_tax_default_type == 'percentage' ? '0.50' : '0.00'; ?>">
                                <small style="color:#94a3b8; font-size:0.75rem;"><?php echo $purchase_tax_default_type == 'percentage' ? 'مثال: 0.50 تعني 0.50%' : 'المبلغ الثابت الذي يُضاف لكل فاتورة شراء'; ?></small>
                            </div>
                        </div>
                        <div style="margin-top:10px; padding:10px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px; font-size:0.85rem; color:#3730a3;">
                            💡 مثال: شراء بقيمة <strong>10,000 <?php echo htmlspecialchars($currency_symbol); ?></strong> + حقوق الثَمْبر <strong><?php echo htmlspecialchars($purchase_tax_default_rate); ?><?php echo $purchase_tax_default_type == 'percentage' ? '%' : ' ' . htmlspecialchars($currency_symbol); ?></strong> = <strong><?php echo number_format(10000 + ($purchase_tax_default_type == 'percentage' ? 10000 * floatval($purchase_tax_default_rate) / 100 : floatval($purchase_tax_default_rate)), intval($decimals)); ?> <?php echo htmlspecialchars($currency_symbol); ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات المخزون ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    إعدادات المخزون والتنبيهات
                </h2>
            </div>
            <div class="card-body">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $low_stock_alert == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $low_stock_alert == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                    <input type="checkbox" name="low_stock_alert" <?php echo $low_stock_alert == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;">
                    <div>
                        <div style="font-weight:700; font-size:0.88rem;">تنبيه المخزون المنخفض</div>
                        <div style="font-size:0.75rem; color:#64748b;">عرض تحذير عند وصول المنتجات للحد الأدنى</div>
                    </div>
                </label>
                <div style="margin-top:12px;">
                    <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">عتبة المخزون المنخفض</label>
                    <input type="number" name="low_stock_threshold" class="form-control" value="<?php echo htmlspecialchars($low_stock_threshold); ?>" min="1" max="1000" step="1" style="max-width:150px;">
                    <small style="color:#94a3b8; font-size:0.75rem;">عدد الوحدات الذي يُعتبر بعده المخزون منخفضاً (للمنتجات بدون حد أدنى محدد)</small>
                </div>
                <div style="margin-top:10px; padding:10px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; font-size:0.85rem; color:#991b1b;">
                    ⚠️ يوجد حالياً <strong><?php echo $low_stock_count; ?></strong> منتج في حالة مخزون منخفض
                    <?php if ($low_stock_count > 0): ?>
                    — <a href="<?php echo $base_url; ?>/products/list-products.php?low_stock=1" style="color:#dc2626; font-weight:700;">عرضها</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات تنبيهات الصلاحية ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    إعدادات تنبيهات الصلاحية
                </h2>
            </div>
            <div class="card-body">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $expiry_alert_enabled == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $expiry_alert_enabled == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s; margin-bottom:12px;" id="expiryAlertLabel">
                    <input type="checkbox" name="expiry_alert_enabled" <?php echo $expiry_alert_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;" id="expiryAlertToggle" onchange="toggleExpirySection()">
                    <div>
                        <div style="font-weight:700; font-size:0.88rem;">تفعيل تنبيهات انتهاء الصلاحية</div>
                        <div style="font-size:0.75rem; color:#64748b;">عرض تنبيهات في لوحة التحكم والقائمة الجانبية</div>
                    </div>
                </label>
                <div id="expirySection" style="display:<?php echo $expiry_alert_enabled == '1' ? 'block' : 'none'; ?>;">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">أيام التنبيه المبكر</label>
                            <input type="number" name="expiry_alert_days" class="form-control" value="<?php echo htmlspecialchars($expiry_alert_days); ?>" min="1" max="365" step="1" placeholder="30">
                            <small style="color:#94a3b8; font-size:0.75rem;">التنبيه قبل انتهاء الصلاحية بعدد الأيام المحدد</small>
                        </div>
                    </div>
                    <div style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $expiry_alert_days == '7' ? '#d97706' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $expiry_alert_days == '7' ? '#fef3c7' : '#fff'; ?>;">
                            <input type="radio" name="expiry_alert_days" value="7" <?php echo $expiry_alert_days == '7' ? 'checked' : ''; ?> style="accent-color:#d97706;"> أسبوع
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $expiry_alert_days == '14' ? '#d97706' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $expiry_alert_days == '14' ? '#fef3c7' : '#fff'; ?>;">
                            <input type="radio" name="expiry_alert_days" value="14" <?php echo $expiry_alert_days == '14' ? 'checked' : ''; ?> style="accent-color:#d97706;"> أسبوعين
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $expiry_alert_days == '30' ? '#d97706' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $expiry_alert_days == '30' ? '#fef3c7' : '#fff'; ?>;">
                            <input type="radio" name="expiry_alert_days" value="30" <?php echo $expiry_alert_days == '30' ? 'checked' : ''; ?> style="accent-color:#d97706;"> شهر
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $expiry_alert_days == '60' ? '#d97706' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $expiry_alert_days == '60' ? '#fef3c7' : '#fff'; ?>;">
                            <input type="radio" name="expiry_alert_days" value="60" <?php echo $expiry_alert_days == '60' ? 'checked' : ''; ?> style="accent-color:#d97706;"> شهرين
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $expiry_alert_days == '90' ? '#d97706' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $expiry_alert_days == '90' ? '#fef3c7' : '#fff'; ?>;">
                            <input type="radio" name="expiry_alert_days" value="90" <?php echo $expiry_alert_days == '90' ? 'checked' : ''; ?> style="accent-color:#d97706;"> 3 أشهر
                        </label>
                    </div>
                    <div style="margin-top:14px; padding-top:12px; border-top:1px dashed #e2e8f0;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $expiry_block_sale == '1' ? '#dc2626' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $expiry_block_sale == '1' ? '#fee2e2' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="checkbox" name="expiry_block_sale" <?php echo $expiry_block_sale == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#dc2626;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem; color:<?php echo $expiry_block_sale == '1' ? '#dc2626' : '#1e293b'; ?>;">🚫 منع بيع المنتجات المنتهية</div>
                                <div style="font-size:0.75rem; color:#64748b;">عرض تحذير عند محاولة بيع منتج منتهي الصلاحية</div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات الإشعارات ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    إشعارات التطبيق
                </h2>
            </div>
            <div class="card-body">
                <!-- تفعيل رئيسي -->
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px; padding:10px 14px; border:1.5px solid <?php echo $notifications_enabled == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $notifications_enabled == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;" id="notifToggleLabel">
                    <input type="checkbox" name="notifications_enabled" <?php echo $notifications_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;" id="notifToggle" onchange="toggleNotifSection()">
                    <div>
                        <div style="font-weight:700; font-size:0.88rem;">تفعيل نظام الإشعارات</div>
                        <div style="font-size:0.75rem; color:#64748b;">إرسال إشعارات عند حدوث عمليات مهمة في التطبيق</div>
                    </div>
                </label>

                <div id="notifSection" style="display:<?php echo $notifications_enabled == '1' ? 'block' : 'none'; ?>;">
                    <!-- فترة التحديث -->
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">فترة التحديث (استطلاع الإشعارات)</label>
                        <select name="notif_poll_interval" class="form-control" style="max-width:200px;">
                            <option value="10" <?php echo $notif_poll_interval == '10' ? 'selected' : ''; ?>>كل 10 ثوانٍ</option>
                            <option value="20" <?php echo $notif_poll_interval == '20' ? 'selected' : ''; ?>>كل 20 ثانية</option>
                            <option value="30" <?php echo $notif_poll_interval == '30' ? 'selected' : ''; ?>>كل 30 ثانية</option>
                            <option value="60" <?php echo $notif_poll_interval == '60' ? 'selected' : ''; ?>>كل 60 ثانية</option>
                        </select>
                        <small style="color:#94a3b8; font-size:0.75rem;">كلما قلت المدة، زادت سرعة وصول الإشعارات</small>
                    </div>

                    <!-- صوت التنبيه -->
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:14px; padding:10px 14px; border:1.5px solid <?php echo $notif_sound_enabled == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $notif_sound_enabled == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                        <input type="checkbox" name="notif_sound_enabled" <?php echo $notif_sound_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem;">🔔 صوت التنبيه</div>
                            <div style="font-size:0.75rem; color:#64748b;">تشغيل صوت عند وصول إشعار جديد</div>
                        </div>
                    </label>

                    <!-- الإشعارات الفورية SSE — v6.3.0 -->
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:14px; padding:10px 14px; border:1.5px solid <?php echo $notifications_sse_enabled == '1' ? '#059669' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $notifications_sse_enabled == '1' ? '#ecfdf5' : '#fff'; ?>; transition:all 0.15s;">
                        <input type="checkbox" name="notifications_sse_enabled" <?php echo $notifications_sse_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#059669;">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem; color:#059669;">
                                ⚡ الإشعارات الفورية (SSE)
                                <?php if ($notifications_sse_enabled == '1'): ?>
                                    <span style="font-size:0.7rem; color:#059669; font-weight:400;"> — مفعّل ✅</span>
                                <?php else: ?>
                                    <span style="font-size:0.7rem; color:#dc2626; font-weight:400;"> — معطّل ⏸️</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:0.75rem; color:#64748b;">اتصال مستمر مع الخادم يُرسل الإشعارات فوراً. إذا واجهت مشاكل (timeout)، عطّله وسيعمل النظام بالاستطلاع التقليدي.</div>
                        </div>
                    </label>

                    <!-- مدة بقاء الإشعارات غير المقروءة (TTL) — v6.3.0 -->
                    <div style="margin-bottom:14px; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; background:#fff;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">⏳ مدة بقاء الإشعارات غير المقروءة</label>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="number" name="notification_ttl_days" value="<?php echo intval($notification_ttl_days); ?>" min="1" max="365" style="width:80px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; text-align:center; font-weight:600; font-size:0.9rem;">
                            <span style="font-size:0.82rem; color:#64748b;">يوم</span>
                        </div>
                        <small style="color:#94a3b8; font-size:0.75rem;">بعد هذا العدد من الأيام، تُعرّف الإشعارات غير المقروءة تلقائياً كمقروءة (الافتراضي: 90 يوم)</small>
                    </div>

                    <!-- إشعارات المتصفح (Browser Push) -->
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:14px; padding:10px 14px; border:1.5px solid <?php echo $push_notifications_enabled == '1' ? '#7c3aed' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $push_notifications_enabled == '1' ? '#f5f3ff' : '#fff'; ?>; transition:all 0.15s;">
                        <input type="checkbox" name="push_notifications_enabled" <?php echo $push_notifications_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#7c3aed;">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem; color:#7c3aed;">🔔 إشعارات المتصفح (Browser Push)</div>
                            <div style="font-size:0.75rem; color:#64748b;">عرض إشعارات على سطح المكتب والهاتف حتى عند إغلاق التبويب — يحتاج HTTPS</div>
                        </div>
                    </label>

                    <!-- VAPID Key Management -->
                    <?php
                    $vapidPublicKey = '';
                    $vapidConfigured = false;
                    try {
                        if (!class_exists('VapidKeys')) require_once __DIR__ . '/../includes/vapid-keys.php';
                        $vapidPublicKey = VapidKeys::getPublicKey();
                        $vapidConfigured = VapidKeys::isConfigured();
                    } catch (\Throwable $e) {}
                    ?>
                    <div id="vapid-section" style="margin-bottom:14px; padding:12px 14px; border:1.5px solid <?php echo $vapidConfigured ? '#059669' : '#d97706'; ?>; border-radius:8px; background:<?php echo $vapidConfigured ? '#ecfdf5' : '#fffbeb'; ?>; transition:all 0.15s;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem; color:<?php echo $vapidConfigured ? '#059669' : '#d97706'; ?>;">
                                    🔑 مفاتيح VAPID
                                    <?php if ($vapidConfigured): ?>
                                        <span style="font-size:0.75rem; color:#059669; font-weight:400;"> — مُعد ✅</span>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; color:#d97706; font-weight:400;"> — غير مُعد ⚠️</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:0.75rem; color:#64748b;">مطلوبة لإشعارات Push في المتصفحات الحديثة (Chrome, Edge, Firefox)</div>
                            </div>
                            <button type="button" onclick="generateVapidKeys()" style="padding:6px 14px; border:1px solid <?php echo $vapidConfigured ? '#059669' : '#d97706'; ?>; border-radius:6px; background:<?php echo $vapidConfigured ? '#ecfdf5' : '#fffbeb'; ?>; color:<?php echo $vapidConfigured ? '#059669' : '#d97706'; ?>; font-size:0.8rem; font-weight:600; cursor:pointer; transition:all 0.15s;">
                                <?php echo $vapidConfigured ? '🔄 إعادة توليد' : '⚡ توليد الآن'; ?>
                            </button>
                        </div>
                        <?php if ($vapidConfigured): ?>
                        <div style="font-size:0.72rem; color:#64748b; word-break:break-all; background:#f8fafc; padding:6px 8px; border-radius:4px; border:1px solid #e2e8f0;">
                            <strong>Public Key:</strong> <?php echo htmlspecialchars(substr($vapidPublicKey, 0, 40)); ?>...
                        </div>
                        <?php endif; ?>
                        <div id="vapid-status" style="display:none; margin-top:8px; font-size:0.8rem; padding:6px 10px; border-radius:4px;"></div>
                    </div>


                    <!-- أنواع الإشعارات -->
                    <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #e2e8f0;">
                        <div style="font-weight:700; font-size:0.85rem; margin-bottom:8px;">أنواع الإشعارات المفعّلة</div>
                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:8px;">
                            <?php foreach ($notif_type_labels as $nt => $label): ?>
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:8px 12px; border:1.5px solid <?php echo $notif_type_settings[$nt] == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $notif_type_settings[$nt] == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s; font-size:0.82rem; font-weight:600;">
                                <input type="checkbox" name="notif_type_<?php echo $nt; ?>" <?php echo $notif_type_settings[$nt] == '1' ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#2563eb;">
                                <?php echo htmlspecialchars($label); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات النسخ الاحتياطي ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    إعدادات النسخ الاحتياطي
                </h2>
            </div>
            <div class="card-body">
                <!-- تفعيل رئيسي -->
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px; padding:10px 14px; border:1.5px solid <?php echo $auto_backup_enabled == '1' ? '#059669' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $auto_backup_enabled == '1' ? '#ecfdf5' : '#fff'; ?>; transition:all 0.15s;" id="backupToggleLabel">
                    <input type="checkbox" name="auto_backup_enabled" <?php echo $auto_backup_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#059669;" id="backupToggle" onchange="toggleBackupSection()">
                    <div>
                        <div style="font-weight:700; font-size:0.88rem;">تفعيل النسخ الاحتياطي التلقائي</div>
                        <div style="font-size:0.75rem; color:#64748b;">إنشاء نسخة احتياطية تلقائياً لقاعدة البيانات</div>
                    </div>
                </label>

                <div id="backupSection" style="display:<?php echo $auto_backup_enabled == '1' ? 'block' : 'none'; ?>;">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
                        <!-- تكرار النسخ -->
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">تكرار النسخ الاحتياطي</label>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <?php
                                $backup_freqs = [
                                    'daily' => '📅 يومياً',
                                    'weekly' => '📆 أسبوعياً',
                                    'monthly' => '🗓️ شهرياً',
                                ];
                                foreach ($backup_freqs as $bf_key => $bf_label):
                                ?>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $auto_backup_frequency == $bf_key ? '#059669' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $auto_backup_frequency == $bf_key ? '#ecfdf5' : '#fff'; ?>; transition:all 0.15s;">
                                    <input type="radio" name="auto_backup_frequency" value="<?php echo $bf_key; ?>" <?php echo $auto_backup_frequency == $bf_key ? 'checked' : ''; ?> style="accent-color:#059669;">
                                    <?php echo $bf_label; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- عدد النسخ المحفوظة -->
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">عدد النسخ المحفوظة</label>
                            <input type="number" name="backup_retention_count" class="form-control" value="<?php echo htmlspecialchars($backup_retention_count); ?>" min="1" max="365" step="1" placeholder="7" style="max-width:160px;">
                            <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:4px;">يتم حذف النسخ القديمة عند تجاوز العدد المحدد</small>
                        </div>
                    </div>

                    <!-- طريقة النسخ الاحتياطي -->
                    <div style="margin-top:14px; padding:14px; border:1.5px solid #e2e8f0; border-radius:8px; background:#fafafa;">
                        <label style="display:block; font-weight:700; margin-bottom:8px; font-size:0.88rem; color:#1e293b;">
                            طريقة النسخ الاحتياطي
                        </label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            <?php
                            $backup_methods = [
                                'auto' => ['تلقائي', 'mysqldump إذا توفر، وإلا PHP', '#2563eb', '#eff6ff'],
                                'mysqldump' => ['mysqldump', 'الأسرع — يحتاج توفر mysqldump', '#059669', '#ecfdf5'],
                                'php' => ['PHP', 'متوافق دائماً — أبطأ مع البيانات الكبيرة', '#7c3aed', '#f5f3ff'],
                            ];
                            foreach ($backup_methods as $bm_key => $bm_info):
                            ?>
                            <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; padding:10px 14px; border:2px solid <?php echo $backup_method == $bm_key ? $bm_info[2] : '#e2e8f0'; ?>; border-radius:10px; font-size:0.85rem; font-weight:600; background:<?php echo $backup_method == $bm_key ? $bm_info[3] : '#fff'; ?>; transition:all 0.15s; flex:1; min-width:140px;">
                                <input type="radio" name="backup_method" value="<?php echo $bm_key; ?>" <?php echo $backup_method == $bm_key ? 'checked' : ''; ?> style="accent-color:<?php echo $bm_info[2]; ?>; margin-top:2px;">
                                <div>
                                    <div style="font-weight:700; color:<?php echo $bm_info[2]; ?>;"><?php echo $bm_info[0]; ?></div>
                                    <div style="font-size:0.72rem; font-weight:400; color:#6b7280;"><?php echo $bm_info[1]; ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- مسار mysqldump المخصص -->
                        <div style="margin-top:8px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.82rem;">
                                مسار mysqldump المخصص
                                <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">اتركه فارغاً للبحث التلقائي</span>
                            </label>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" name="backup_mysqldump_path" class="form-control" value="<?php echo htmlspecialchars($backup_mysqldump_path); ?>" placeholder="مثال: C:\wamp64\bin\mysql\mysql8.0.31\bin\mysqldump.exe" style="flex:1; direction:ltr; text-align:left; font-family:monospace; font-size:0.82rem;">
                                <?php
                                // عرض حالة الكشف التلقائي
                                require_once __DIR__ . '/../config/backup.php';
                                $mdCheck = checkMysqldumpAvailability();
                                ?>
                            </div>
                            <?php if ($mdCheck['available']): ?>
                            <div style="margin-top:6px; padding:8px 12px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:6px; font-size:0.78rem; color:#065f46;">
                                <strong>تم الكشف تلقائياً:</strong> <?php echo htmlspecialchars($mdCheck['path']); ?>
                                <?php if (!empty($mdCheck['version'])): ?>
                                <span style="color:#047857;">(v<?php echo htmlspecialchars($mdCheck['version']); ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div style="margin-top:6px; padding:8px 12px; background:#fef3c7; border:1px solid #fde68a; border-radius:6px; font-size:0.78rem; color:#92400e;">
                                <strong>لم يتم الكشف عن mysqldump تلقائياً.</strong> أدخل المسار يدوياً إذا كنت تعرفه.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top:10px; padding:10px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-size:0.85rem; color:#166534;">
                        💡 يتم تخزين النسخ الاحتياطية في مجلد <strong>backups/</strong> بصيغة SQL مضغوطة (gzip)
                    </div>

                    <!-- حالة آخر نسخة تلقائية -->
                    <?php if (!empty($last_auto_backup)): ?>
                    <div style="margin-top:10px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span style="font-size:1.2rem;">🕐</span>
                        <div>
                            <div style="font-weight:700; font-size:0.82rem; color:#1e40af;">آخر نسخة تلقائية</div>
                            <div style="font-size:0.8rem; color:#3b82f6;"><?php echo htmlspecialchars($last_auto_backup); ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:10px; padding:10px 14px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; display:flex; align-items:center; gap:10px;">
                        <span style="font-size:1.2rem;">⚠️</span>
                        <div style="font-size:0.82rem; color:#92400e;">لم يتم إنشاء أي نسخة تلقائية بعد — فعّل الإعداد واضغط "حفظ الإعدادات"</div>
                    </div>
                    <?php endif; ?>

                    <!-- إعداد cron للجدولة -->
                    <div style="margin-top:10px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <div style="font-weight:700; font-size:0.85rem; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                            ⏰ جدولة النسخ التلقائي (Cron)
                        </div>
                        <div style="font-size:0.8rem; color:#475569; margin-bottom:8px; line-height:1.7;">
                            لضمان عمل النسخ التلقائي حتى بدون زيارة لوحة التحكم، أضف أحد الأوامر التالية إلى <strong>crontab</strong> الخادم:
                        </div>
                        <div style="background:#1e293b; color:#e2e8f0; padding:10px 14px; border-radius:8px; font-family:monospace; font-size:0.78rem; line-height:1.8; overflow-x:auto;">
                            <div style="color:#94a3b8;"># طريقة 1: سطر الأوامر (PHP CLI)</div>
                            <div>0 2 * * * php <?php echo realpath(__DIR__ . '/..'); ?>/cron/auto-backup.php</div>
                            <div style="color:#94a3b8; margin-top:6px;"># طريقة 2: عبر الويب (wget)</div>
                            <div>0 2 * * * wget -q -O /dev/null "<?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com'); ?>/cron/auto-backup.php?key=<span id='cronKeyDisplay'><?php echo htmlspecialchars($cron_secret_key); ?></span>"</div>
                        </div>
                        <div style="margin-top:8px;">
                            <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.8rem;">مفتاح أمان Cron</label>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" name="cron_secret_key" class="form-control" value="<?php echo htmlspecialchars($cron_secret_key); ?>" placeholder="يُولَّد تلقائياً عند الحفظ" style="font-family:monospace; font-size:0.8rem; flex:1;" readonly id="cronKeyInput">
                                <button type="button" onclick="regenerateCronKey()" style="padding:6px 12px; font-size:0.78rem; background:#2563eb; color:#fff; border:none; border-radius:6px; cursor:pointer; white-space:nowrap;">🔄 توليد جديد</button>
                            </div>
                            <small style="color:#94a3b8; font-size:0.72rem;">يُستخدم لحماية نقطة نهاية cron من الاستدعاء غير المصرح به</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات الفواتير ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    إعدادات الفواتير
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">نص رأس الفاتورة</label>
                        <input type="text" name="invoice_header_text" class="form-control" value="<?php echo htmlspecialchars($invoice_header_text); ?>" placeholder="فاتورة بيع - متجر النور">
                        <small style="color:#94a3b8; font-size:0.75rem;">يظهر في أعلى الفاتورة المطبوعة</small>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">نص تذييل الفاتورة</label>
                        <textarea name="invoice_footer" class="form-control" rows="2" placeholder="شكراً لزيارتكم..."><?php echo htmlspecialchars($invoice_footer); ?></textarea>
                        <small style="color:#94a3b8; font-size:0.75rem;">يظهر في أسفل الفاتورة المطبوعة</small>
                    </div>
                </div>

                <div style="margin-top:14px; display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:10px;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $show_logo_on_invoice == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $show_logo_on_invoice == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                        <input type="checkbox" name="show_logo_on_invoice" <?php echo $show_logo_on_invoice == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem;">🖼️ عرض الشعار في الفاتورة</div>
                            <div style="font-size:0.75rem; color:#64748b;">إظهار شعار المتجر/التطبيق في رأس الفاتورة</div>
                        </div>
                    </label>
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $show_store_info_on_invoice == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $show_store_info_on_invoice == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                        <input type="checkbox" name="show_store_info_on_invoice" <?php echo $show_store_info_on_invoice == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;">
                        <div>
                            <div style="font-weight:700; font-size:0.88rem;">🏪 عرض معلومات المتجر</div>
                            <div style="font-size:0.75rem; color:#64748b;">اسم المتجر، الهاتف، العنوان في الفاتورة</div>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات المبيعات ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
                    إعدادات المبيعات
                </h2>
            </div>
            <div class="card-body">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px; padding:10px 14px; border:1.5px solid <?php echo $auto_print == '1' ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $auto_print == '1' ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                    <input type="checkbox" name="auto_print" <?php echo $auto_print == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#2563eb;">
                    <div>
                        <div style="font-weight:700; font-size:0.88rem;">🖨️ طباعة الفاتورة تلقائياً</div>
                        <div style="font-size:0.75rem; color:#64748b;">فتح نافذة الطباعة تلقائياً بعد كل عملية بيع</div>
                    </div>
                </label>

                <!-- حد المنتجات في تقارير الأرباح -->
                <div style="margin-top:8px; padding:14px; border:1.5px solid #e2e8f0; border-radius:8px; background:#fafafa;">
                    <label style="display:block; font-weight:700; margin-bottom:8px; font-size:0.88rem; color:#1e293b;">
                        📊 حد المنتجات في تقارير الأرباح
                        <span style="font-size:0.72rem; font-weight:400; color:#94a3b8; margin-right:6px;">الحد الأقصى لعدد المنتجات المعروضة في تفصيل الأرباح</span>
                    </label>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $product_limit == 0 ? '#059669' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $product_limit == 0 ? '#d1fae5' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="radio" name="product_limit" value="0" <?php echo $product_limit == 0 ? 'checked' : ''; ?> style="accent-color:#059669;">
                            الكل
                        </label>
                        <?php foreach ([10, 50, 100, 200, 500, 1000, 5000] as $pl_val): ?>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:2px solid <?php echo $product_limit == $pl_val ? '#2563eb' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $product_limit == $pl_val ? '#eff6ff' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="radio" name="product_limit" value="<?php echo $pl_val; ?>" <?php echo $product_limit == $pl_val ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                            <?php echo $pl_val; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small style="color:#94a3b8; font-size:0.75rem; display:block; margin-top:6px;">"الكل" = عرض جميع المنتجات بدون حد | الافتراضي: 100 | الحد الأقصى: 5000</small>
                    <?php if ($product_limit == 0 && $total_products > 2000): ?>
                    <div style="margin-top:6px; padding:8px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:6px; font-size:0.78rem; color:#92400e;">
                        ⚠️ لديك <?php echo number_format($total_products); ?> منتج — عرض الكل قد يكون أبطأ
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات نقطة البيع (POS) ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    إعدادات نقطة البيع (POS)
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">

                    <!-- سمة نقطة البيع -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px; font-size:0.85rem;">سمة نقطة البيع</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php
                            $pos_themes = [
                                'light' => '☀️ فاتح',
                                'dark' => '🌙 داكن',
                            ];
                            foreach ($pos_themes as $pt_key => $pt_label):
                            ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $pos_theme == $pt_key ? '#7c3aed' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.85rem; font-weight:600; background:<?php echo $pos_theme == $pt_key ? '#f5f3ff' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="pos_theme" value="<?php echo $pt_key; ?>" <?php echo $pos_theme == $pt_key ? 'checked' : ''; ?> style="accent-color:#7c3aed;">
                                <?php echo $pt_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- صوت نقطة البيع -->
                    <div>
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $pos_sound_enabled == '1' ? '#7c3aed' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $pos_sound_enabled == '1' ? '#f5f3ff' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="checkbox" name="pos_sound_enabled" <?php echo $pos_sound_enabled == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#7c3aed;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">🔊 صوت نقطة البيع</div>
                                <div style="font-size:0.75rem; color:#64748b;">تشغيل صوت عند إتمام عملية البيع أو المسح</div>
                            </div>
                        </label>
                    </div>

                    <!-- إكمال تلقائي -->
                    <div>
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $pos_auto_complete == '1' ? '#7c3aed' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $pos_auto_complete == '1' ? '#f5f3ff' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="checkbox" name="pos_auto_complete" <?php echo $pos_auto_complete == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#7c3aed;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">⚡ إكمال تلقائي</div>
                                <div style="font-size:0.75rem; color:#64748b;">إكمال تلقائي لأسماء المنتجات أثناء البحث</div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== إعدادات الطابعة الحرارية ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    إعدادات الطابعة الحرارية
                </h2>
            </div>
            <div class="card-body">

                <!-- تفعيل الطابعة الحرارية -->
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:16px; padding:12px 16px; border:1.5px solid <?php echo $thermal_printer_enabled == '1' ? '#ea580c' : '#e2e8f0'; ?>; border-radius:10px; background:<?php echo $thermal_printer_enabled == '1' ? '#fff7ed' : '#fff'; ?>; transition:all 0.15s;">
                    <input type="checkbox" name="thermal_printer_enabled" <?php echo $thermal_printer_enabled == '1' ? 'checked' : ''; ?> style="width:22px; height:22px; accent-color:#ea580c;">
                    <div>
                        <div style="font-weight:700; font-size:0.9rem;">🖨️ تفعيل الطابعة الحرارية</div>
                        <div style="font-size:0.75rem; color:#64748b;">استخدام طابعة حرارية (ESC/POS) لطباعة الإيصالات بدلاً من الطباعة العادية</div>
                    </div>
                </label>

                <!-- خيارات الطابعة الحرارية (تظهر عند التفعيل) -->
                <div id="thermalPrinterOptions" style="<?php echo $thermal_printer_enabled == '1' ? '' : 'display:none;'; ?> padding:16px; border:1px solid #fed7aa; border-radius:10px; background:#fffbf5;">

                    <!-- نوع الاتصال -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-weight:600; margin-bottom:8px; font-size:0.85rem;">نوع الاتصال</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php
                            $printer_types = [
                                'browser' => '🌐 طباعة المتصفح (HTML حراري)',
                                'serial' => '🔌 USB (Web Serial)',
                                'network' => '📡 شبكة (WiFi/Ethernet)',
                            ];
                            foreach ($printer_types as $pt_key => $pt_label):
                            ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:10px 16px; border:2px solid <?php echo $thermal_printer_type == $pt_key ? '#ea580c' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.83rem; font-weight:600; background:<?php echo $thermal_printer_type == $pt_key ? '#fff7ed' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="thermal_printer_type" value="<?php echo $pt_key; ?>" <?php echo $thermal_printer_type == $pt_key ? 'checked' : ''; ?> style="accent-color:#ea580c;" onchange="toggleThermalFields()">
                                <?php echo $pt_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- إعدادات الشبكة (تظهر عند اختيار network) -->
                    <div id="thermalNetworkFields" style="<?php echo $thermal_printer_type == 'network' ? '' : 'display:none;'; ?> margin-bottom:16px; padding:12px; border:1px dashed #fdba74; border-radius:8px; background:#fff;">
                        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:12px;">
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.82rem;">عنوان IP</label>
                                <input type="text" name="thermal_printer_ip" value="<?php echo htmlspecialchars($thermal_printer_ip); ?>" placeholder="192.168.1.100" style="width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.9rem; direction:ltr; text-align:left;" pattern="^(\d{1,3}\.){3}\d{1,3}$">
                            </div>
                            <div>
                                <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.82rem;">المنفذ</label>
                                <input type="text" name="thermal_printer_port" value="<?php echo htmlspecialchars($thermal_printer_port); ?>" placeholder="9100" style="width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.9rem; direction:ltr; text-align:left;" pattern="^\d{1,5}$">
                            </div>
                        </div>
                        <p style="font-size:0.72rem; color:#94a3b8; margin-top:6px;">المنفذ الافتراضي لطابعات ESC/POS هو 9100. يحتاج خادم WebSocket وسيط (proxy) لتحويل WebSocket إلى TCP.</p>
                    </div>

                    <!-- إعدادات USB (تظهر عند اختيار serial) -->
                    <div id="thermalSerialFields" style="<?php echo $thermal_printer_type == 'serial' ? '' : 'display:none;'; ?> margin-bottom:16px; padding:12px; border:1px dashed #fdba74; border-radius:8px; background:#fff;">
                        <p style="font-size:0.82rem; color:#64748b; line-height:1.6;">
                            🔌 <strong>USB (Web Serial API):</strong> يتطلب متصفح Chrome أو Edge إصدار 89 أو أحدث.<br>
                            عند اختيار USB، ستظهر نافذة اختيار الجهاز عند أول عملية طباعة.<br>
                            تأكد من توصيل الطابعة بمنفذ USB قبل الطباعة.
                        </p>
                    </div>

                    <!-- عرض الورق -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-weight:600; margin-bottom:8px; font-size:0.85rem;">عرض الورق</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php
                            $paper_widths = [
                                '58mm' => '📏 58mm (32 حرف)',
                                '80mm' => '📏 80mm (48 حرف)',
                            ];
                            foreach ($paper_widths as $pw_key => $pw_label):
                            ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:10px 16px; border:2px solid <?php echo $thermal_printer_width == $pw_key ? '#ea580c' : '#e2e8f0'; ?>; border-radius:8px; font-size:0.83rem; font-weight:600; background:<?php echo $thermal_printer_width == $pw_key ? '#fff7ed' : '#fff'; ?>; transition:all 0.15s;">
                                <input type="radio" name="thermal_printer_width" value="<?php echo $pw_key; ?>" <?php echo $thermal_printer_width == $pw_key ? 'checked' : ''; ?> style="accent-color:#ea580c;">
                                <?php echo $pw_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- خيارات إضافية -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:16px;">
                        <!-- طباعة تلقائية -->
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $thermal_auto_print == '1' ? '#ea580c' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $thermal_auto_print == '1' ? '#fff7ed' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="checkbox" name="thermal_auto_print" <?php echo $thermal_auto_print == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#ea580c;">
                            <div>
                                <div style="font-weight:700; font-size:0.85rem;">⚡ طباعة تلقائية</div>
                                <div style="font-size:0.72rem; color:#64748b;">طباعة تلقائياً بعد كل عملية بيع</div>
                            </div>
                        </label>

                        <!-- قص تلقائي -->
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $thermal_auto_cut == '1' ? '#ea580c' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $thermal_auto_cut == '1' ? '#fff7ed' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="checkbox" name="thermal_auto_cut" <?php echo $thermal_auto_cut == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#ea580c;">
                            <div>
                                <div style="font-weight:700; font-size:0.85rem;">✂️ قص تلقائي</div>
                                <div style="font-size:0.72rem; color:#64748b;">قص الورق تلقائياً بعد الطباعة</div>
                            </div>
                        </label>

                        <!-- فتح درج النقود -->
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1.5px solid <?php echo $thermal_cash_drawer == '1' ? '#ea580c' : '#e2e8f0'; ?>; border-radius:8px; background:<?php echo $thermal_cash_drawer == '1' ? '#fff7ed' : '#fff'; ?>; transition:all 0.15s;">
                            <input type="checkbox" name="thermal_cash_drawer" <?php echo $thermal_cash_drawer == '1' ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#ea580c;">
                            <div>
                                <div style="font-weight:700; font-size:0.85rem;">💰 فتح درج النقود</div>
                                <div style="font-size:0.72rem; color:#64748b;">فتح درج النقود بعد الطباعة</div>
                            </div>
                        </label>
                    </div>

                    <!-- نص تذييل الإيصال -->
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.82rem;">نص تذييل الإيصال</label>
                        <input type="text" name="thermal_receipt_footer" value="<?php echo htmlspecialchars($thermal_receipt_footer); ?>" placeholder="شكراً لزيارتكم — نتمنى لكم يوماً سعيداً" style="width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.9rem;" maxlength="200">
                        <p style="font-size:0.72rem; color:#94a3b8; margin-top:4px;">يظهر في أسفل الإيصال بعد المبلغ. اتركه فارغاً للنص الافتراضي.</p>
                    </div>
                </div>

            </div>
        </div>

        <!-- ===== إعدادات كاش بنية الجدول (SchemaCache) ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    إعدادات كاش بنية الجدول (SchemaCache)
                </h2>
            </div>
            <div class="card-body">

                <!-- وصف الكاش -->
                <div style="margin-bottom:16px; padding:14px 18px; border-radius:10px; border:1.5px solid #bfdbfe; background:#eff6ff;">
                    <div style="font-size:0.85rem; color:#1e40af; line-height:1.8;">
                        <strong>ما هو كاش بنية الجدول؟</strong><br>
                        يُخزّن مؤقتاً نتائج استعلامات بنية الجداول (<code style="background:#dbeafe; padding:1px 4px; border-radius:3px;">SHOW COLUMNS</code> وكشف وجود الأعمدة/الجداول) لأنها لا تتغير إلا عند التحديث. هذا يُقلّل حمل قاعدة البيانات عند كثرة المستخدمين.<br>
                        <span style="color:#dc2626; font-weight:600;">⚠️ لا يُستخدم أبداً لبيانات الأعمال</span> (مبيعات، أرباح، إشعارات، صلاحية) — هذه تبقى دائماً بيانات حقيقية في الوقت الفعلي.
                    </div>
                </div>

                <!-- حالة الكاش الحالية -->
                <div style="margin-bottom:16px; padding:14px 18px; border-radius:10px; border:1.5px solid <?php echo ($schema_cache_enabled === '1') ? '#86efac' : '#fecaca'; ?>; background:<?php echo ($schema_cache_enabled === '1') ? '#f0fdf4' : '#fef2f2'; ?>;">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                        <div style="width:12px; height:12px; border-radius:50%; background:<?php echo ($schema_cache_enabled === '1') ? '#16a34a' : '#dc2626'; ?>; flex-shrink:0;"></div>
                        <div style="font-weight:700; font-size:0.9rem; color:<?php echo ($schema_cache_enabled === '1') ? '#16a34a' : '#dc2626'; ?>;">
                            <?php echo ($schema_cache_enabled === '1') ? '✅ كاش بنية الجدول مفعّل' : '❌ كاش بنية الجدول معطّل'; ?>
                        </div>
                    </div>
                    <?php if ($schema_cache_stats && $schema_cache_enabled === '1'): ?>
                    <div style="display:flex; gap:16px; flex-wrap:wrap; font-size:0.78rem; color:#64748b;">
                        <span>📄 الملفات: <strong><?php echo intval($schema_cache_stats['total_files']); ?></strong></span>
                        <span>💾 الحجم: <strong><?php echo $schema_cache_stats['total_size_kb']; ?> KB</strong></span>
                        <span>⏱️ TTL: <strong><?php echo $schema_cache_stats['ttl']; ?> ثانية (<?php echo round($schema_cache_stats['ttl'] / 3600, 1); ?> ساعة)</strong></span>
                        <?php if ($schema_cache_stats['expired_files'] > 0): ?>
                        <span style="color:#dc2626;">🗑️ منتهية الصلاحية: <strong><?php echo intval($schema_cache_stats['expired_files']); ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- إعدادات الكاش -->
                <div style="margin-bottom:16px;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin-bottom:12px;">
                        <input type="checkbox" name="schema_cache_enabled" id="schema_cache_enabled" value="1" <?php echo ($schema_cache_enabled === '1') ? 'checked' : ''; ?> style="width:18px; height:18px; cursor:pointer;">
                        <div>
                            <div style="font-weight:600; font-size:0.9rem;">تفعيل كاش بنية الجدول</div>
                            <div style="font-size:0.78rem; color:#94a3b8;">عند التعطيل، يتم تنفيذ استعلامات البنية في كل طلب</div>
                        </div>
                    </label>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.82rem;">مدة الصلاحية (بالثواني)</label>
                    <input type="number" name="schema_cache_ttl" id="schema_cache_ttl" value="<?php echo intval($schema_cache_ttl); ?>" min="60" max="604800" style="width:160px; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.9rem; direction:ltr; text-align:left;">
                    <p style="font-size:0.72rem; color:#94a3b8; margin-top:3px;">الحد الأدنى: 60 ثانية — الحد الأقصى: 604800 (7 أيام) — الافتراضي: 86400 (24 ساعة)</p>
                </div>

                <!-- المفاتيح المخزنة -->
                <?php if ($schema_cache_stats && !empty($schema_cache_stats['keys']) && $schema_cache_enabled === '1'): ?>
                <div style="margin-bottom:16px;">
                    <div style="font-weight:600; font-size:0.82rem; margin-bottom:8px;">المفاتيح المخزنة:</div>
                    <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:0.78rem;">
                            <thead>
                                <tr style="background:#f8fafc;">
                                    <th style="padding:8px 12px; text-align:right; border-bottom:1px solid #e2e8f0;">المفتاح</th>
                                    <th style="padding:8px 12px; text-align:center; border-bottom:1px solid #e2e8f0;">العمر</th>
                                    <th style="padding:8px 12px; text-align:center; border-bottom:1px solid #e2e8f0;">متبقي</th>
                                    <th style="padding:8px 12px; text-align:center; border-bottom:1px solid #e2e8f0;">الحجم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schema_cache_stats['keys'] as $keyName => $info): ?>
                                <tr>
                                    <td style="padding:6px 12px; border-bottom:1px solid #f1f5f9; direction:ltr; text-align:left; font-family:monospace; font-size:0.72rem;"><?php echo htmlspecialchars(str_replace('.cache', '', $keyName)); ?></td>
                                    <td style="padding:6px 12px; border-bottom:1px solid #f1f5f9; text-align:center;"><?php echo round(intval($info['age_seconds']) / 60); ?> د</td>
                                    <td style="padding:6px 12px; border-bottom:1px solid #f1f5f9; text-align:center; <?php echo $info['remaining_seconds'] >= 0 ? '' : 'color:#94a3b8;'; ?>"><?php echo $info['remaining_seconds'] >= 0 ? round(intval($info['remaining_seconds']) / 60) . ' د' : 'بلا انتهاء'; ?></td>
                                    <td style="padding:6px 12px; border-bottom:1px solid #f1f5f9; text-align:center;"><?php echo round(intval($info['size']) / 1024, 1); ?> KB</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" name="save_schema_cache_settings" value="1" class="btn btn-primary" style="display:flex; align-items:center; gap:6px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        حفظ إعدادات الكاش
                    </button>
                    <button type="submit" name="clear_schema_cache" value="1" class="btn" style="display:flex; align-items:center; gap:6px; background:#fef2f2; border:1.5px solid #fecaca; color:#dc2626; border-radius:8px; padding:8px 16px; cursor:pointer; font-weight:600; font-size:0.85rem; transition:all 0.15s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                        مسح الكاش
                    </button>
                </div>

                <div style="margin-top:14px; padding:12px 16px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-size:0.78rem; color:#166534; line-height:1.7;">
                    <strong>✅ ضمان البيانات الحقيقية:</strong><br>
                    • لوحة التحكم: <strong>بدون كاش</strong> — بيانات حقيقية دائماً<br>
                    • الإشعارات: <strong>بدون كاش</strong> — تنخفض فور القراءة<br>
                    • عدّاد الصلاحية: <strong>بدون كاش</strong> — يتحدث مع كل عملية بيع/شراء<br>
                    • حساب الأرباح: <strong>بدون كاش</strong> — يتطابق مع الواقع دائماً<br>
                    • الكاش يُستخدم <strong>فقط</strong> لكشف بنية الجداول (أسماء الأعمدة، وجود جداول)
                </div>
            </div>
        </div>

        <!-- ===== معلومات النظام ===== -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    معلومات النظام
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                    <div style="padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <div style="font-size:0.72rem; color:#64748b; font-weight:600;">إصدار التطبيق</div>
                        <div style="font-size:1.05rem; font-weight:800; color:#1e293b;"><?php echo APP_VERSION; ?></div>
                    </div>
                    <div style="padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <div style="font-size:0.72rem; color:#64748b; font-weight:600;">إصدار PHP</div>
                        <div style="font-size:1.05rem; font-weight:800; color:#1e293b;"><?php echo PHP_VERSION; ?></div>
                    </div>
                    <div style="padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <div style="font-size:0.72rem; color:#64748b; font-weight:600;">قاعدة البيانات</div>
                        <div style="font-size:1.05rem; font-weight:800; color:#1e293b;"><?php echo htmlspecialchars(DB_NAME); ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8; font-weight:400;">(<?php echo $db_size; ?> MB)</div>
                    </div>
                    <div style="padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <div style="font-size:0.72rem; color:#64748b; font-weight:600;">الخادم</div>
                        <div style="font-size:1.05rem; font-weight:800; color:#1e293b;"><?php echo htmlspecialchars(DB_HOST); ?></div>
                    </div>
                </div>

                <!-- إحصائيات السجلات -->
                <div style="margin-top:14px; padding-top:14px; border-top:1px dashed #e2e8f0;">
                    <div style="font-weight:700; font-size:0.85rem; margin-bottom:8px; color:#64748b;">📊 إجمالي السجلات في النظام</div>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:8px;">
                        <div style="display:flex; align-items:center; gap:8px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;">
                            <div style="width:8px; height:8px; background:#2563eb; border-radius:50%; flex-shrink:0;"></div>
                            <div>
                                <div style="font-size:1rem; font-weight:800; color:#1e293b;"><?php echo $total_products; ?></div>
                                <div style="font-size:0.72rem; color:#3b82f6; font-weight:600;">منتج</div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; padding:10px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px;">
                            <div style="width:8px; height:8px; background:#059669; border-radius:50%; flex-shrink:0;"></div>
                            <div>
                                <div style="font-size:1rem; font-weight:800; color:#1e293b;"><?php echo $total_sales; ?></div>
                                <div style="font-size:0.72rem; color:#16a34a; font-weight:600;">عملية بيع</div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; padding:10px 14px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px;">
                            <div style="width:8px; height:8px; background:#d97706; border-radius:50%; flex-shrink:0;"></div>
                            <div>
                                <div style="font-size:1rem; font-weight:800; color:#1e293b;"><?php echo $total_purchases; ?></div>
                                <div style="font-size:0.72rem; color:#ca8a04; font-weight:600;">عملية شراء</div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; padding:10px 14px; background:#f5f3ff; border:1px solid #e9d5ff; border-radius:8px;">
                            <div style="width:8px; height:8px; background:#7c3aed; border-radius:50%; flex-shrink:0;"></div>
                            <div>
                                <div style="font-size:1rem; font-weight:800; color:#1e293b;"><?php echo $total_users; ?></div>
                                <div style="font-size:0.72rem; color:#8b5cf6; font-weight:600;">مستخدم</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- زر الحفظ -->
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
            <button type="submit" name="save_settings" value="1" class="btn btn-primary btn-lg" style="min-width:240px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                حفظ جميع الإعدادات
            </button>
            <a href="<?php echo $base_url; ?>/index.php" class="btn btn-outline btn-lg">العودة للرئيسية</a>
        </div>
    </form>
</div>

<script src="<?php echo $base_url ?? ''; ?>/assets/js/pages-system-settings.js?v=6.3.5"></script>

<?php require_once '../includes/footer.php'; ?>
</html>
