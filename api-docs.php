<?php
/**
 * ===== توثيق API للمطورين =====
 * صفحة توثيق تفاعلية لواجهة برمجة التطبيقات (API)
 * متاحة للمديرين فقط
 *
 * v4.35.0: إنشاء صفحة التوثيق
 * v5.12.0: إضافة توثيق نقاط النهاية المفقودة
 * v5.15.0: توثيق شامل لجميع نقاط النهاية (~110 نقطة، 29 قسم)
 */

$page_title = 'توثيق API للمطورين';
require_once 'includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
requireAdmin();

$base_url = rtrim(getSetting('base_url', ''), '/');
if (empty($base_url)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
}
$base_url = rtrim($base_url, '/');

$app_name = htmlspecialchars(getSetting('app_name', 'إدارة المخزون'));
$app_version = defined('APP_VERSION') ? APP_VERSION : '5.16.0';
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $app_name; ?> — توثيق API</title>
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/api-docs.css?v=6.3.5">
</head>
<body>

<!-- Header -->
<div class="api-header">
    <h1>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32" style="color:var(--primary-light);">
            <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/>
            <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>
        </svg>
        توثيق API للمطورين
    </h1>
    <div class="subtitle"><?php echo $app_name; ?> — واجهة برمجة تطبيقات الهاتف المحمول</div>
    <div class="badges">
        <span class="badge-item" style="color:var(--green);border-color:rgba(34,197,94,0.3);">REST API</span>
        <span class="badge-item" style="color:var(--primary-light);border-color:rgba(59,130,246,0.3);">Bearer Token Auth</span>
        <span class="badge-item" style="color:var(--purple);border-color:rgba(168,85,247,0.3);">JSON</span>
        <span class="badge-item" style="color:var(--cyan);border-color:rgba(6,182,212,0.3);">v1</span>
        <span class="badge-item" style="color:var(--yellow);border-color:rgba(234,179,8,0.3);"><?php echo $app_version; ?></span>
    </div>
</div>

<!-- Nav -->
<nav class="api-nav">
    <ul>
        <li><a href="#intro" class="active">مقدمة</a></li>
        <li><a href="#auth">المصادقة</a></li>
        <li><a href="#products">المنتجات</a></li>
        <li><a href="#sales">المبيعات</a></li>
        <li><a href="#purchases">المشتريات</a></li>
        <li><a href="#reports">التقارير</a></li>
        <li><a href="#categories">التصنيفات</a></li>
        <li><a href="#dashboard">لوحة التحكم</a></li>
        <li><a href="#expenses">المصروفات</a></li>
        <li><a href="#settings">الإعدادات</a></li>
        <li><a href="#caisse">الصندوق</a></li>
        <li><a href="#notifications">الإشعارات</a></li>
        <li><a href="#product-images">صور المنتجات</a></li>
        <li><a href="#users">المستخدمين</a></li>
        <li><a href="#prices">الأسعار</a></li>
        <li><a href="#stock">المخزون</a></li>
        <li><a href="#labels">بطاقات المنتجات</a></li>
        <li><a href="#export">التصدير</a></li>
        <li><a href="#backups">النسخ الاحتياطية</a></li>
        <li><a href="#audit">التدقيق</a></li>
        <li><a href="#pos">نقطة البيع</a></li>
        <li><a href="#whatsapp">واتساب</a></li>
        <li><a href="#push">الإشعارات الفورية</a></li>
        <li><a href="#tokens">رموز API</a></li>
        <li><a href="#units">وحدات القياس</a></li>
        <li><a href="#expiry">صلاحية المنتجات</a></li>
        <li><a href="#system">إدارة النظام</a></li>
        <li><a href="#batches">الدفعات</a></li>
        <li><a href="#errors">الأخطاء</a></li>
    </ul>
</nav>

<!-- Content -->
<div class="api-content">

<?php
// === أقسام التوثيق الـ29 (مفصولة في المرحلة 3 — M3) — بالترتيب نفسه السابق ===
$api_doc_sections = [
    '01-intro', // مقدمة
    '02-auth', // المصادقة
    '03-products', // المنتجات
    '04-sales', // المبيعات
    '05-purchases', // المشتريات
    '06-reports', // التقارير
    '07-categories', // التصنيفات
    '08-dashboard', // لوحة التحكم
    '09-expenses', // المصروفات
    '10-settings', // الإعدادات
    '11-caisse', // الصندوق
    '12-notifications', // الإشعارات
    '13-product-images', // صور المنتجات
    '14-users', // المستخدمين
    '15-prices', // الأسعار
    '16-stock', // المخزون
    '17-labels', // بطاقات المنتجات
    '18-export', // التصدير
    '19-backups', // النسخ الاحتياطية
    '20-audit', // التدقيق
    '21-pos', // نقطة البيع
    '22-whatsapp', // واتساب
    '23-push-notifications', // الإشعارات الفورية
    '24-api-tokens', // رموز API
    '25-measurement-units', // وحدات القياس
    '26-product-expiry', // صلاحية المنتجات
    '27-system-admin', // إدارة النظام
    '28-batches', // الدفعات
    '29-errors', // الأخطاء
];
foreach ($api_doc_sections as $api_doc_section) {
    include __DIR__ . '/templates/api-docs/sections/' . $api_doc_section . '.php';
}
?>
<!-- Footer -->
<div class="api-footer">
    <p><?php echo $app_name; ?> — توثيق API v<?php echo $app_version; ?> — <?php echo date('Y'); ?></p>
    <p style="margin-top:4px;">جميع نقاط النهاية تتطلب مصادقة Bearer Token ما لم يُذكر خلاف ذلك</p>
</div>

<script src="<?php echo $base_url; ?>/assets/js/api-docs.js?v=6.3.5"></script>
</body>
</html>
