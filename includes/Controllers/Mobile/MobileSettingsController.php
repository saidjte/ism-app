<?php
/**
 * ===== متحكم إعدادات الموبايل (MobileSettingsController) =====
 * يوفر قراءة وتحديث إعدادات النظام عبر API الموبايل
 *
 * المسارات:
 *   GET /api/mobile/settings           — جلب جميع الإعدادات (مجمّعة)
 *   PUT /api/mobile/settings           — تحديث إعدادات (مدير فقط)
 *
 * v5.11.0: إنشاء متحكم إعدادات الموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileSettingsController extends ApiController
{
    /**
     * الإعدادات المسموح بقراءتها عبر API الموبايل
     * مفاتيح ممنوعة: cron_secret_key، backup_*، db_*، كلمات مرور
     */
    private const READABLE_SETTINGS = [
        // هوية التطبيق
        'app_name', 'app_description', 'app_logo_color', 'app_version',
        // معلومات المتجر
        'store_name', 'store_phone', 'store_address', 'store_city',
        // العملة
        'currency_symbol', 'currency_code', 'currency_name', 'decimals',
        // الضرائب
        'tax_enabled', 'tax_rate', 'tax_name',
        'purchase_tax_enabled', 'purchase_tax_default_name', 'purchase_tax_default_rate', 'purchase_tax_default_type',
        // المخزون
        'low_stock_alert', 'items_per_page',
        // الصلاحية
        'expiry_alert_enabled', 'expiry_alert_days', 'expiry_block_sale',
        // الإشعارات
        'notifications_enabled', 'notif_poll_interval', 'notif_sound_enabled',
        'notif_type_sale', 'notif_type_purchase', 'notif_type_product',
        'notif_type_stock', 'notif_type_expiry', 'notif_type_expense',
        'notif_type_user', 'notif_type_system', 'notif_type_backup',
        // العرض
        'date_format', 'default_language',
        // نقطة البيع
        'pos_theme', 'pos_sound_enabled', 'pos_auto_complete',
        // الفاتورة
        'show_logo_on_invoice', 'show_store_info_on_invoice', 'invoice_header_text', 'invoice_footer',
        // الطابعة الحرارية
        'thermal_printer_enabled', 'thermal_printer_type',
        'thermal_printer_width', 'thermal_auto_print', 'thermal_auto_cut',
        'thermal_cash_drawer', 'thermal_receipt_footer',
        // الواتساب
        'whatsapp_enabled', 'whatsapp_number', 'whatsapp_auto_popup',
        // متفرقة
        'product_limit',
    ];

    /**
     * الإعدادات المسموح بتحديثها عبر API الموبايل (مدير فقط)
     * لا يشمل إعدادات أمنية أو حساسة
     */
    private const WRITABLE_SETTINGS = [
        'store_name', 'store_phone', 'store_address', 'store_city',
        'currency_symbol', 'currency_code', 'currency_name', 'decimals',
        'tax_enabled', 'tax_rate', 'tax_name',
        'purchase_tax_enabled', 'purchase_tax_default_name', 'purchase_tax_default_rate', 'purchase_tax_default_type',
        'low_stock_alert', 'items_per_page',
        'expiry_alert_enabled', 'expiry_alert_days', 'expiry_block_sale',
        'notifications_enabled', 'notif_poll_interval', 'notif_sound_enabled',
        'notif_type_sale', 'notif_type_purchase', 'notif_type_product',
        'notif_type_stock', 'notif_type_expiry', 'notif_type_expense',
        'notif_type_user', 'notif_type_system', 'notif_type_backup',
        'pos_theme', 'pos_sound_enabled', 'pos_auto_complete',
        'show_logo_on_invoice', 'show_store_info_on_invoice',
        'invoice_header_text', 'invoice_footer',
        'whatsapp_enabled', 'whatsapp_number', 'whatsapp_auto_popup',
        'product_limit',
    ];

    /**
     * جلب جميع الإعدادات (مجمّعة حسب القسم)
     *
     * GET /api/mobile/settings
     */
    public function index(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // جلب كل الإعدادات المسموح بقراءتها
            $placeholders = implode(',', array_fill(0, count(self::READABLE_SETTINGS), '?'));
            $stmt = $pdo->prepare(
                "SELECT setting_key, setting_value, description FROM system_settings WHERE setting_key IN ({$placeholders})"
            );
            $stmt->execute(self::READABLE_SETTINGS);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تحويل إلى مصفوفة مفاتيح
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = [
                    'value' => $row['setting_value'],
                    'description' => $row['description'] ?? '',
                ];
            }

            // تجميع الإعدادات في أقسام
            $grouped = [
                'app' => [],
                'store' => [],
                'currency' => [],
                'tax' => [],
                'inventory' => [],
                'expiry' => [],
                'notifications' => [],
                'display' => [],
                'pos' => [],
                'invoice' => [],
                'thermal_printer' => [],
                'whatsapp' => [],
                'other' => [],
            ];

            $keyToGroup = [
                'app_name' => 'app', 'app_description' => 'app', 'app_logo_color' => 'app', 'app_version' => 'app',
                'store_name' => 'store', 'store_phone' => 'store', 'store_address' => 'store', 'store_city' => 'store',
                'currency_symbol' => 'currency', 'currency_code' => 'currency', 'currency_name' => 'currency', 'decimals' => 'currency',
                'tax_enabled' => 'tax', 'tax_rate' => 'tax', 'tax_name' => 'tax',
                'purchase_tax_enabled' => 'tax', 'purchase_tax_default_name' => 'tax', 'purchase_tax_default_rate' => 'tax', 'purchase_tax_default_type' => 'tax',
                'low_stock_alert' => 'inventory', 'items_per_page' => 'inventory',
                'expiry_alert_enabled' => 'expiry', 'expiry_alert_days' => 'expiry', 'expiry_block_sale' => 'expiry',
                'notifications_enabled' => 'notifications', 'notif_poll_interval' => 'notifications', 'notif_sound_enabled' => 'notifications',
                'notif_type_sale' => 'notifications', 'notif_type_purchase' => 'notifications', 'notif_type_product' => 'notifications',
                'notif_type_stock' => 'notifications', 'notif_type_expiry' => 'notifications', 'notif_type_expense' => 'notifications',
                'notif_type_user' => 'notifications', 'notif_type_system' => 'notifications', 'notif_type_backup' => 'notifications',
                'date_format' => 'display', 'default_language' => 'display',
                'pos_theme' => 'pos', 'pos_sound_enabled' => 'pos', 'pos_auto_complete' => 'pos',
                'show_logo_on_invoice' => 'invoice', 'show_store_info_on_invoice' => 'invoice',
                'invoice_header_text' => 'invoice', 'invoice_footer' => 'invoice',
                'thermal_printer_enabled' => 'thermal_printer', 'thermal_printer_type' => 'thermal_printer',
                'thermal_printer_width' => 'thermal_printer', 'thermal_auto_print' => 'thermal_printer',
                'thermal_auto_cut' => 'thermal_printer', 'thermal_cash_drawer' => 'thermal_printer',
                'thermal_receipt_footer' => 'thermal_printer',
                'whatsapp_enabled' => 'whatsapp', 'whatsapp_number' => 'whatsapp', 'whatsapp_auto_popup' => 'whatsapp',
                'product_limit' => 'other',
            ];

            foreach ($settings as $key => $data) {
                $group = $keyToGroup[$key] ?? 'other';
                $grouped[$group][$key] = $data['value'];
            }

            // إزالة الأقسام الفارغة
            $grouped = array_filter($grouped, fn($g) => !empty($g));

            // إضافة معلومات السياق المفيدة
            $context = [
                'currency_symbol' => $settings['currency_symbol']['value'] ?? 'د.م.',
                'currency_code' => $settings['currency_code']['value'] ?? 'MAD',
                'is_rtl' => ($settings['default_language']['value'] ?? 'ar') === 'ar',
                'tax_enabled' => ($settings['tax_enabled']['value'] ?? '0') === '1',
                'tax_rate' => floatval($settings['tax_rate']['value'] ?? 20),
                'store_name' => $settings['store_name']['value'] ?? '',
            ];

            ApiResponse::success([
                'settings' => $grouped,
                'context' => $context,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSettingsController::index');
            }

            // إرجاع إعدادات افتراضية عند الفشل
            ApiResponse::success([
                'settings' => [
                    'currency' => [
                        'currency_symbol' => 'د.م.',
                        'currency_code' => 'MAD',
                        'currency_name' => 'درهم مغربي',
                        'decimals' => '3',
                    ],
                    'tax' => [
                        'tax_enabled' => '0',
                        'tax_rate' => '20',
                        'tax_name' => 'ضريبة القيمة المضافة',
                    ],
                    'app' => [
                        'app_name' => 'نظام إدارة المخزون',
                        'default_language' => 'ar',
                    ],
                ],
                'context' => [
                    'currency_symbol' => 'د.م.',
                    'currency_code' => 'MAD',
                    'is_rtl' => true,
                    'tax_enabled' => false,
                    'tax_rate' => 20,
                    'store_name' => '',
                ],
            ]);
        }
    }

    /**
     * تحديث إعدادات النظام (مدير فقط)
     *
     * PUT /api/mobile/settings
     * Body: { "store_name": "متجر النور", "tax_enabled": "1", ... }
     */
    public function update(array $params = []): void
    {
        try {
            // التحقق من صلاحيات المدير
            // v5.11.3: إصلاح — استخدام user_role (المفتاح الذي يضعه TokenAuthMiddleware)
            $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
            if ($role !== 'admin') {
                ApiResponse::forbidden('تحديث الإعدادات يتطلب صلاحيات المدير');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();
            $data = $this->getJsonInput();

            if (empty($data)) {
                ApiResponse::validationError(['لم يتم تقديم بيانات للتحديث']);
                return;
            }

            $updated = [];
            $errors = [];

            foreach ($data as $key => $value) {
                // التحقق من أن المفتاح مسموح بتحديثه
                if (!in_array($key, self::WRITABLE_SETTINGS, true)) {
                    $errors[] = "الإعداد '{$key}' غير مسموح بتحديثه عبر API";
                    continue;
                }

                // تحقق خاص حسب نوع الإعداد
                if (!$this->validateSettingValue($key, $value)) {
                    $errors[] = "قيمة الإعداد '{$key}' غير صالحة";
                    continue;
                }

                // تحديث أو إدراج الإعداد (UPSERT)
                $stmt = $pdo->prepare(
                    "INSERT INTO system_settings (setting_key, setting_value)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                );
                $stmt->execute([$key, (string) $value]);
                $updated[] = $key;
            }

            // تسجيل في سجل المراجعة
            if (!empty($updated) && function_exists('addAuditLog')) {
                addAuditLog('settings_update', 'تحديث إعدادات عبر الموبايل: ' . implode(', ', $updated));
            }

            if (!empty($errors)) {
                ApiResponse::custom([
                    'success' => true,
                    'data' => [
                        'updated' => $updated,
                        'errors' => $errors,
                    ],
                    'message' => 'تم تحديث بعض الإعدادات مع وجود أخطاء',
                ], 207); // 207 Multi-Status
            } else {
                ApiResponse::success(['updated' => $updated], 'تم تحديث الإعدادات بنجاح');
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileSettingsController::update');
            }
            ApiResponse::serverError('خطأ في تحديث الإعدادات');
        }
    }

    /**
     * التحقق من صحة قيمة إعداد معين
     *
     * @param string $key مفتاح الإعداد
     * @param mixed $value القيمة
     * @return bool هل القيمة صالحة
     */
    private function validateSettingValue(string $key, mixed $value): bool
    {
        // الإعدادات المنطقية (0 أو 1)
        $booleanSettings = [
            'tax_enabled', 'purchase_tax_enabled', 'low_stock_alert',
            'expiry_alert_enabled', 'expiry_block_sale',
            'notifications_enabled', 'notif_sound_enabled',
            'notif_type_sale', 'notif_type_purchase', 'notif_type_product',
            'notif_type_stock', 'notif_type_expiry', 'notif_type_expense',
            'notif_type_user', 'notif_type_system', 'notif_type_backup',
            'pos_sound_enabled', 'pos_auto_complete',
            'show_logo_on_invoice', 'show_store_info_on_invoice',
            'thermal_printer_enabled', 'thermal_auto_print', 'thermal_auto_cut',
            'thermal_cash_drawer',
            'whatsapp_enabled', 'whatsapp_auto_popup',
        ];

        if (in_array($key, $booleanSettings, true)) {
            return in_array((string) $value, ['0', '1', 'true', 'false'], true);
        }

        // إعدادات عددية
        $numericSettings = [
            'tax_rate' => [0, 100],
            'purchase_tax_default_rate' => [0, 100],
            'decimals' => [0, 6],
            'items_per_page' => [5, 200],
            'expiry_alert_days' => [1, 365],
            'notif_poll_interval' => [5, 3600],
            'product_limit' => [10, 10000],
        ];

        if (isset($numericSettings[$key])) {
            $num = floatval($value);
            return $num >= $numericSettings[$key][0] && $num <= $numericSettings[$key][1];
        }

        // إعدادات التعداد
        $enumSettings = [
            'pos_theme' => ['light', 'dark'],
            'thermal_printer_type' => ['browser', 'network'],
            'thermal_printer_width' => ['58mm', '80mm'],
            'purchase_tax_default_type' => ['percentage', 'fixed'],
        ];

        if (isset($enumSettings[$key])) {
            return in_array((string) $value, $enumSettings[$key], true);
        }

        // إعدادات نصية - التحقق من الطول
        $textSettings = [
            'store_name' => 100, 'store_phone' => 50, 'store_address' => 200, 'store_city' => 100,
            'currency_symbol' => 10, 'currency_code' => 10, 'currency_name' => 50,
            'tax_name' => 100, 'purchase_tax_default_name' => 100,
            'invoice_header_text' => 500, 'invoice_footer' => 500,
            'thermal_receipt_footer' => 500,
            'whatsapp_number' => 20,
        ];

        if (isset($textSettings[$key])) {
            return is_string($value) && mb_strlen($value) <= $textSettings[$key];
        }

        // الافتراضي: السماح
        return true;
    }
}
