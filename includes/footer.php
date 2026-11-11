
    <div class="footer">
        <p><?php echo htmlspecialchars(getSetting('app_name', function_exists('t') ? t('app_name') : 'إدارة المخزون')); ?> &copy; <?php echo date('Y'); ?> - <?php echo function_exists('t') ? t('footer_rights') : 'جميع الحقوق محفوظة'; ?></p>
    </div>

    <!-- === ملفات JavaScript المركزية === -->
    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
        // متغيرات عامة من PHP
        window.CURRENCY = '<?php echo defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : getCurrencySymbol(); ?>';
        window.BASE_URL = '<?php echo $base_url ?? ""; ?>';

        // === رسائل التحقق والمؤشر المترجمة لـ JavaScript ===
        window.I18N_MESSAGES = <?php
            $js_messages = [];
            if (function_exists('t')) {
                $validation_keys = [
                    'validation_required', 'validation_email', 'validation_minlength', 'validation_maxlength',
                    'validation_pattern', 'validation_min', 'validation_max', 'validation_step',
                    'validation_number', 'validation_url', 'validation_date',
                    'validation_password_mismatch', 'validation_password_min', 'validation_username_min',
                    'validation_select_required', 'validation_custom_category', 'validation_barcode_required',
                    'validation_conversion_positive', 'validation_custom_unit_name',
                    'validation_selling_price_positive', 'validation_purchase_price_positive', 'validation_amount_positive',
                    'loading_please_wait', 'loading_processing', 'loading_saving', 'loading_loading'
                ];
                foreach ($validation_keys as $key) {
                    $js_messages[$key] = t($key);
                }
            }
            echo json_encode($js_messages, JSON_UNESCAPED_UNICODE);
        ?>;
        window.CURRENT_LANG = '<?php echo function_exists("lang") ? lang() : "ar"; ?>';
    </script>

    <!-- 0. مكتبة الرسوم البيانية Chart.js — تُحمّل في header.php عبر $load_chartjs -->

    <!-- 1. الأدوات الأساسية: إشعارات، تأكيدات، طلبات AJAX، تنسيق -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/utils.js?v=4.2"></script>

    <!-- 1.5 مؤشر التحميل الموحد -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/app-loading.js?v=4.30"></script>

    <!-- 1.6 نظام التحقق من النماذج -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/form-validation.js?v=4.30"></script>

    <!-- 2. التطبيق الأساسي: التنقل، القائمة الجانبية، أدوات DOM -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/app.js?v=4.2"></script>

    <!-- 2.5 نظام الإشعارات (polling, dropdown) -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/notifications.js?v=4.8"></script>

    <!-- 2.6 إشعارات المتصفح (Browser Push) -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/push-notifications.js?v=4.9.79"></script>

    <!-- 3. مكون البحث عن المنتجات (AJAX) -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/product-search.js?v=4.2"></script>

    <!-- 4. مكون ترقيم الصفحات (AJAX) -->
    <script src="<?php echo $base_url ?? ""; ?>/assets/js/pagination.js?v=4.2"></script>

    <!-- === تهيئة مؤشر التحميل والتحقق من النماذج === -->
    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    document.addEventListener('DOMContentLoaded', function() {
        // تهيئة مؤشر التحميل الموحد
        if (window.AppLoading) {
            AppLoading.init({
                messages: {
                    please_wait: window.I18N_MESSAGES.loading_please_wait || 'يرجى الانتظار...',
                    processing: window.I18N_MESSAGES.loading_processing || 'جاري المعالجة...',
                    saving: window.I18N_MESSAGES.loading_saving || 'جاري الحفظ...',
                    loading: window.I18N_MESSAGES.loading_loading || 'جاري التحميل...'
                }
            });
        }

        // تهيئة نظام التحقق من النماذج
        if (window.FormValidation) {
            FormValidation.init({
                messages: window.I18N_MESSAGES || {},
                lang: window.CURRENT_LANG || 'ar'
            });
        }
    });
    </script>

</body>
</html>
