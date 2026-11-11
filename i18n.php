<?php
/**
 * نظام الترجمة متعدد اللغات - Multi-language Translation System
 * يدعم العربية (ar)، الفرنسية (fr)، الإنجليزية (en)
 */

// حالة التهيئة
$GLOBALS['i18n_initialized'] = false;
$GLOBALS['i18n_lang'] = 'ar';
$GLOBALS['i18n_translations'] = [];

/**
 * تهيئة نظام الترجمة
 */
function initI18n() {
    if ($GLOBALS['i18n_initialized']) return;

    // تحديد اللغة الحالية من الإعدادات
    $lang = 'ar';
    try {
        if (function_exists('getSetting')) {
            $saved = getSetting('default_language', 'ar');
            if (in_array($saved, ['ar', 'fr', 'en'])) {
                $lang = $saved;
            }
        }
    } catch (Exception $e) {
        if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
            ErrorHandler::logException($e, 'i18n::initLanguage');
        } else {
            error_log('[ISM] i18n::initLanguage: ' . $e->getMessage());
        }
    }

    $GLOBALS['i18n_lang'] = $lang;
    $GLOBALS['i18n_initialized'] = true;

    // تحميل ملف الترجمة
    $lang_file = __DIR__ . '/languages/' . $lang . '.php';
    if (file_exists($lang_file)) {
        $GLOBALS['i18n_translations'] = require $lang_file;
    }
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
    if (!$GLOBALS['i18n_initialized']) {
        initI18n();
    }

    $text = $GLOBALS['i18n_translations'][$key] ?? $key;

    if (!empty($args)) {
        $text = vsprintf($text, $args);
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
