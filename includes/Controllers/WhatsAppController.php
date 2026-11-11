<?php
/**
 * ===== متحكم تنبيهات واتساب API =====
 * إرسال تنبيهات واتساب واختبار اتصال UltraMsg
 */

require_once __DIR__ . '/ApiController.php';

class WhatsAppController extends ApiController
{
    /**
     * إرسال تنبيه واتساب يدوي من لوحة التحكم
     * POST /api/send-whatsapp-alert
     */
    public function sendAlert(array $params): void
    {
        $this->requirePost();

        // تحميل ملف واتساب إذا لم يكن محملاً
        if (!function_exists('sendWhatsAppAlertAuto')) {
            require_once __DIR__ . '/../../includes/whatsapp_alert.php';
        }

        $result = sendWhatsAppAlertAuto();

        // إذا لم يُرسل بسبب الحد اليومي، أرسل بالقوة
        if (!$result['sent'] && $result['message'] && strpos($result['message'], 'مسبقاً') !== false) {
            $wa_enabled = (getSetting('whatsapp_enabled', '0') === '1');
            if ($wa_enabled) {
                $method = getSetting('whatsapp_method', 'wame');
                if ($method === 'ultramsg') {
                    $instance_id = trim(getSetting('whatsapp_ultramsg_instance', ''));
                    $api_token = trim(getSetting('whatsapp_ultramsg_token', ''));
                    $phone = trim(getSetting('whatsapp_number', ''));

                    if (function_exists('fetchExpiryDataForWhatsApp')) {
                        $data = fetchExpiryDataForWhatsApp();
                        if ($data['expired_count'] > 0 || $data['expiring_count'] > 0) {
                            $total_items = $data['expired_count'] + $data['expiring_count'];
                            $store_name = getSetting('store_name', '');

                            if ($total_items <= 20) {
                                $message = generateWhatsAppMessage($data['expired_list'], $data['expiring_list'], $data['days_setting'], $data['currency_symbol']);
                            } else {
                                $message = generateWhatsAppShortMessage($data['expired_count'], $data['expiring_count'], $data['total_loss'], $data['days_setting'], $data['currency_symbol'], $store_name);
                            }

                            $send_result = sendUltraMsg($instance_id, $api_token, $phone, $message);
                            $result = [
                                'sent' => $send_result['success'],
                                'method' => 'ultramsg',
                                'message' => $send_result['message']
                            ];

                            if ($send_result['success']) {
                                saveWhatsAppLastAutoSend(date('Y-m-d H:i:s'));
                            }
                        }
                    }
                }
            }
        }

        ApiResponse::success($result, $result['sent'] ? 'تم الإرسال بنجاح' : $result['message'] ?? 'لم يتم الإرسال');
    }

    /**
     * اختبار اتصال UltraMsg
     * POST /api/test-ultramsg
     */
    public function testUltraMsg(array $params): void
    {
        $this->requirePost();

        // تحميل ملف واتساب إذا لم يكن محملاً
        if (!function_exists('testUltraMsgConnection')) {
            require_once __DIR__ . '/../../includes/whatsapp_alert.php';
        }

        $instance_id = trim($this->input('instance_id', ''));
        $api_token = trim($this->input('api_token', ''));

        if (empty($instance_id) || empty($api_token)) {
            ApiResponse::validationError(['instance_id' => 'يرجى إدخال Instance ID و API Token']);
        }

        $result = testUltraMsgConnection($instance_id, $api_token);
        ApiResponse::success($result);
    }
}
