<?php
/**
 * نظام الترجمة متعدد اللغات - Multi-language Translation System
 * يدعم العربية (ar)، الفرنسية (fr)، الإنجليزية (en)
 *
 * v4.32.3: إضافة معامل $force لـ initI18n() لدعم إعادة التهيئة بعد تحميل قاعدة البيانات
 */

// حالة التهيئة
$GLOBALS['i18n_initialized'] = false;
$GLOBALS['i18n_lang'] = 'ar';
$GLOBALS['i18n_translations'] = [];

/**
 * تهيئة نظام الترجمة
 *
 * @param bool $force إذا كان true، يُعيد التهيئة حتى لو تمت مسبقاً (لاستخدام بعد تحميل قاعدة البيانات)
 */
function initI18n($force = false) {
    if (!$force && !empty($GLOBALS['i18n_initialized'])) return;

    // تحديد اللغة الحالية من الإعدادات
    $lang = 'ar';
    try {
        if (function_exists('getSetting')) {
            $saved = getSetting('default_language', 'ar');
            if (in_array($saved, ['ar', 'fr', 'en'])) {
                $lang = $saved;
            }
        }
    } catch (\Throwable $e) {}

    $GLOBALS['i18n_lang'] = $lang;

    // تحميل ملف الترجمة
    $lang_file = __DIR__ . '/languages/' . $lang . '.php';
    if (file_exists($lang_file)) {
        try {
            $translations = require $lang_file;
            $GLOBALS['i18n_translations'] = is_array($translations) ? $translations : [];
        } catch (\Throwable $e) {
            $GLOBALS['i18n_translations'] = [];
        }
    }

    $GLOBALS['i18n_initialized'] = true;
}

/**
 * إعادة تهيئة نظام الترجمة (بعد تحميل قاعدة البيانات)
 * يُستدعى من bootstrap.php بعد توفر getSetting()
 */
function reinitI18n() {
    initI18n(true);
}

/**
 * ترجمة مفتاح إلى النص الحالي
 * يدعم sprintf placeholders: t('key', $arg1, $arg2)
 *
 * @param string $key مفتاح الترجمة
 * @param mixed ...$args معاملات لـ sprintf
 * @return string النص المترجم أو المفتاح نفسه إذا لم يُعثر عليه
 */
function t($key, ...$args) {
    // تهيئة تلقائية آمنة — لا تفشل حتى لو initI18n() أطلقت استثناءً
    if (empty($GLOBALS['i18n_initialized'])) {
        try {
            initI18n();
        } catch (\Throwable $e) {
            // فشل التهيئة — نعيد المفتاح نفسه كبديل آمن
            if (!empty($args)) {
                try { return vsprintf($key, $args); } catch (\Throwable $e2) {}
            }
            return $key;
        }
    }

    $text = $GLOBALS['i18n_translations'][$key] ?? $key;

    if (!empty($args)) {
        try {
            $text = vsprintf($text, $args);
        } catch (\Throwable $e) {
            // فشل vsprintf (عدد المعاملات غير متطابق) — نعيد النص بدون تنسيق
        }
    }

    return $text;
}

/**
 * الحصول على كود اللغة الحالية
 */
function lang() {
    if (!$GLOBALS['i18n_initialized']) initI18n();
    return $GLOBALS['i18n_lang'];
}

/**
 * هل اللغة الحالية من اليمين لليسار؟
 */
function isRTL() {
    return lang() === 'ar';
}

/**
 * اتجاه النص: rtl أو ltr
 */
function langDir() {
    return isRTL() ? 'rtl' : 'ltr';
}

/**
 * اسم اللغة بلغتها
 */
function langName() {
    $names = ['ar' => 'العربية', 'fr' => 'Français', 'en' => 'English'];
    return $names[lang()] ?? lang();
}

/**
 * محاذاة النص حسب اتجاه اللغة
 */
function langAlign() {
    return isRTL() ? 'right' : 'left';
}

/**
 * محاذاة معاكسة
 */
function langAlignReverse() {
    return isRTL() ? 'left' : 'right';
}

/**
 * الحصول على جميع الترجمات
 */
function getTranslations() {
    if (!$GLOBALS['i18n_initialized']) initI18n();
    return $GLOBALS['i18n_translations'];
}
