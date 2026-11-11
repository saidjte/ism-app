<?php
/**
 * ===== متحكم واتساب للهاتف (Mobile WhatsApp Controller) =====
 * يوفر واجهة API لإرسال تنبيهات واتساب من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   POST /api/mobile/whatsapp/send-alert  — إرسال تنبيه واتساب
 *   POST /api/mobile/whatsapp/test        — اختبار اتصال UltraMsg
 *   GET  /api/mobile/whatsapp/status      — حالة واتساب
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف واتساب
 */

require_once __DIR__ . '/../ApiController.php';

class MobileWhatsAppController extends ApiController
{
    /**
     * إرسال تنبيه واتساب
     *
     * الجسم (JSON):
     *   message  — نص الرسالة (اختياري، يستخدم القالب الافتراضي إذا لم يحدد)
     *   force    — إجبار الإرسال حتى لو تم الإرسال اليوم (اختياري)
     *
     * الاستجابة:
     *   { success, data: { sent, message }, message }
     */
    public function sendAlert(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $message = trim($this->input('message', ''));
            $force = filter_var($this->input('force', false), FILTER_VALIDATE_BOOL);

            if (function_exists('sendWhatsAppAlertAuto')) {
                $result = sendWhatsAppAlertAuto($force, $message);

                if ($result['success'] ?? false) {
                    ApiResponse::success([
                        'sent' => true,
                        'message' => $result['message'] ?? 'تم إرسال التنبيه',
                    ], 'تم إرسال تنبيه واتساب بنجاح');
                } else {
                    ApiResponse::error($result['message'] ?? 'فشل إرسال التنبيه', 'whatsapp_error', 400);
                }
            } else {
                // محاولة الإرسال يدوياً عبر UltraMsg
                $result = $this->sendManualAlert($message, $force);
                if ($result['success']) {
                    ApiResponse::success($result, 'تم إرسال تنبيه واتساب بنجاح');
                } else {
                    ApiResponse::error($result['message'], 'whatsapp_error', 400);
                }
            }

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileWhatsAppController::sendAlert');
            ApiResponse::serverError('حدث خطأ أثناء إرسال تنبيه واتساب');
        }
    }

    /**
     * اختبار اتصال UltraMsg
     *
     * الجسم (JSON):
     *   instance_id — معرف المثيل (اختياري، يستخدم الإعدادات المحفوظة)
     *   api_token   — رمز API (اختياري، يستخدم الإعدادات المحفوظة)
     *
     * الاستجابة:
     *   { success, data: { connected, instance_info }, message }
     */
    public function test(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $instanceId = trim($this->input('instance_id', ''));
            $apiToken = trim($this->input('api_token', ''));

            // استخدام الإعدادات المحفوظة إذا لم يتم توفير بيانات جديدة
            if (empty($instanceId) && function_exists('getSetting')) {
                $instanceId = getSetting('ultramsg_instance_id', '');
            }
            if (empty($apiToken) && function_exists('getSetting')) {
                $apiToken = getSetting('ultramsg_api_token', '');
            }

            if (empty($instanceId) || empty($apiToken)) {
                ApiResponse::validationError([
                    'instance_id' => empty($instanceId) ? 'معرف المثيل مطلوب' : '',
                    'api_token' => empty($apiToken) ? 'رمز API مطلوب' : '',
                ]);
                return;
            }

            // اختبار الاتصال
            $url = "https://api.ultramsg.com/{$instanceId}/instance/status?token={$apiToken}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                ApiResponse::error('خطأ في الاتصال: ' . $curlError, 'connection_error', 502);
                return;
            }

            $result = json_decode($response, true);

            if ($httpCode === 200 && is_array($result)) {
                ApiResponse::success([
                    'connected' => true,
                    'instance_info' => $result,
                    'http_code' => $httpCode,
                ], 'تم الاتصال بنجاح بخدمة UltraMsg');
            } else {
                ApiResponse::error(
                    'فشل الاتصال بخدمة UltraMsg (HTTP ' . $httpCode . ')',
                    'connection_failed',
                    502
                );
            }

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileWhatsAppController::test');
            ApiResponse::serverError('حدث خطأ أثناء اختبار اتصال واتساب');
        }
    }

    /**
     * حالة تكامل واتساب
     *
     * الاستجابة:
     *   { success, data: { enabled, configured, phone } }
     */
    public function status(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $getSetting = function_exists('getSetting') ? 'getSetting' : function ($key, $default = '') { return $default; };

            $enabled = $getSetting('whatsapp_notifications_enabled', '0') === '1';
            $instanceId = $getSetting('ultramsg_instance_id', '');
            $apiToken = $getSetting('ultramsg_api_token', '');
            $phone = $getSetting('whatsapp_phone', '');

            ApiResponse::success([
                'enabled' => $enabled,
                'configured' => !empty($instanceId) && !empty($apiToken),
                'phone' => $phone,
                'instance_id_set' => !empty($instanceId),
                'api_token_set' => !empty($apiToken),
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileWhatsAppController::status');
            ApiResponse::serverError('حدث خطأ أثناء جلب حالة واتساب');
        }
    }

    /**
     * إرسال يدوي عبر UltraMsg
     */
    private function sendManualAlert(string $message, bool $force): array
    {
        $getSetting = function_exists('getSetting') ? 'getSetting' : function ($key, $default = '') { return $default; };

        $instanceId = $getSetting('ultramsg_instance_id', '');
        $apiToken = $getSetting('ultramsg_api_token', '');
        $phone = $getSetting('whatsapp_phone', '');

        if (empty($instanceId) || empty($apiToken) || empty($phone)) {
            return ['success' => false, 'message' => 'إعدادات واتساب غير مكتملة'];
        }

        if (empty($message)) {
            $storeName = $getSetting('store_name', '');
            $message = "📊 تقرير يومي - {$storeName}\nالتاريخ: " . date('Y-m-d');
        }

        $url = "https://api.ultramsg.com/{$instanceId}/messages/chat";

        $postData = http_build_query([
            'token' => $apiToken,
            'to' => $phone,
            'body' => $message,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'message' => 'تم إرسال الرسالة بنجاح'];
        }

        return ['success' => false, 'message' => 'فشل الإرسال (HTTP ' . $httpCode . ')'];
    }
}
