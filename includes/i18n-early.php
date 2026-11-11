<?php
/**
 * مُحمِّل i18n المبكر — يُحمَّل تلقائياً قبل أي كود صفحة
 * عبر auto_prepend_file في .user.ini أو .htaccess
 *
 * يضمن توفر دالة t() حتى لو لم يُحمَّل bootstrap.php بعد.
 * هذا يحل خطأ "Call to undefined function t()" في الصفحات
 * التي تستدعي t() قبل require_once bootstrap.php
 *
 * آلية العمل:
 *   1. يُحمَّل هذا الملف تلقائياً قبل كل سكربت PHP
 *   2. يُحمِّل i18n.php الذي يُعرِّف t() و initI18n()
 *   3. عند أول استدعاء لـ t()، تُهيَّأ الترجمة باللغة العربية (الافتراضية)
 *   4. لاحقاً، عند تحميل bootstrap.php واتصال قاعدة البيانات،
 *      يُستدعى initI18n(true) لتحميل اللغة الصحيحة من الإعدادات
 *
 * @since v4.32.3
 */

if (!function_exists('t')) {
    // __DIR__ يشير دائماً إلى مجلد includes/ أينما كان هذا الملف
    $i18nPath = __DIR__ . '/i18n.php';
    if (file_exists($i18nPath)) {
        require_once $i18nPath;
    }
}
