<?php
/**
 * ===== التخطيط الرئيسي (Main Layout) =====
 * يلف محتوى الصفحة برأس وتذييل التطبيق
 *
 * الاستخدام عبر Template::renderPage():
 *   Template::renderPage('dashboard/view', $data, 'الرئيسية');
 *
 * أو مباشرة:
 *   Template::renderWithLayout('main', 'dashboard/view', $data);
 *
 * @since v4.17.0
 *
 * المتغيرات المتوقعة:
 *   $content — محتوى الصفحة (مُمرر تلقائياً من renderWithLayout)
 *   $page_title — عنوان الصفحة
 *   $load_chartjs — تحميل Chart.js
 */
?>
<?php
// تحميل الرأس
$page_title = $page_title ?? (getSetting('app_name', 'إدارة المخزون'));
$load_chartjs = $load_chartjs ?? false;
require_once dirname(__DIR__, 3) . '/includes/header.php';

// عرض المحتوى
echo $content ?? '';

// تحميل التذييل
require_once dirname(__DIR__, 3) . '/includes/footer.php';
