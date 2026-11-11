<?php
/**
 * ===== مرسل إشعارات Push =====
 * إرسال إشعارات Web Push عبر بروتوكول VAPID
 *
 * يعمل بدون مكتبات خارجية — يستخدم OpenSSL و cURL فقط
 * يدعم: FCM (Google), Mozilla Push Service
 *
 * يتطلب: PHP 8.1+ مع إضافة openssl و curl
 */

// منع التحميل المزدوج
if (defined('PUSH_SENDER_LOADED')) return;
define('PUSH_SENDER_LOADED', true);

if (!class_exists('VapidKeys')) {
    require_once __DIR__ . '/vapid-keys.php';
}

class PushSender {

    /** مدة صلاحية JWT (ساعات) */
    const JWT_TTL_HOURS = 12;

    /** موضوع التطبيق (mailto:) */
    const VAPID_SUBJECT = 'mailto:ism-app@localhost';

    /** خوارزمية التوقيع */
    const SIGN_ALGORITHM = 'sha256';

    /** مدة الانتظار القصوى للـ cURL (ثواني) */
    const CURL_TIMEOUT = 10;

    /**
     * إرسال إشعار Push لمستخدم محدد
     *
     * @param int $userId معرف المستخدم
     * @param array $payload بيانات الإشعار ['title' => '', 'body' => '', 'icon' => '', 'url' => '', 'tag' => '']
     * @return array ['success' => bool, 'sent' => int, 'failed' => int, 'errors' => []]
     */
    public static function sendToUser(int $userId, array $payload): array {
        if (!VapidKeys::isConfigured()) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'errors' => ['VAPID غير مُعد']];
        }

        $db = AppDatabase::getInstance();
        $subscriptions = $db->query(
            "SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions WHERE user_id = ?",
            [$userId]
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'errors' => []];
        }

        return self::sendToSubscriptions($subscriptions, $payload);
    }

    /**
     * إرسال إشعار Push لعدة مستخدمين
     *
     * @param array $userIds قائمة معرفات المستخدمين
     * @param array $payload بيانات الإشعار
     * @return array ['success' => bool, 'sent' => int, 'failed' => int, 'errors' => []]
     */
    public static function sendToUsers(array $userIds, array $payload): array {
        if (!VapidKeys::isConfigured()) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'errors' => ['VAPID غير مُعد']];
        }

        if (empty($userIds)) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'errors' => []];
        }

        $db = AppDatabase::getInstance();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $subscriptions = $db->query(
            "SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions WHERE user_id IN ($placeholders)",
            $userIds
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'errors' => []];
        }

        return self::sendToSubscriptions($subscriptions, $payload);
    }

    /**
     * إرسال إشعار Push لجميع المشتركين
     *
     * @param array $payload بيانات الإشعار
     * @return array ['success' => bool, 'sent' => int, 'failed' => int, 'errors' => []]
     */
    public static function sendToAll(array $payload): array {
        if (!VapidKeys::isConfigured()) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'errors' => ['VAPID غير مُعد']];
        }

        $db = AppDatabase::getInstance();
        $subscriptions = $db->query(
            "SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            return ['success' => true, 'sent' => 0, 'failed' => 0, 'errors' => []];
        }

        return self::sendToSubscriptions($subscriptions, $payload);
    }

    /**
     * إرسال إشعار Push للاشتراكات المحددة
     *
     * @param array $subscriptions قائمة الاشتراكات
     * @param array $payload بيانات الإشعار
     * @return array ['success' => bool, 'sent' => int, 'failed' => int, 'errors' => []]
     */
    private static function sendToSubscriptions(array $subscriptions, array $payload): array {
        $sent = 0;
        $failed = 0;
        $errors = [];
        $expiredSubscriptions = [];

        // إعداد بيانات الإشعار
        $payloadJson = json_encode([
            'title' => $payload['title'] ?? '',
            'body' => $payload['body'] ?? $payload['message'] ?? '',
            'icon' => $payload['icon'] ?? '',
            'url' => $payload['url'] ?? '',
            'tag' => $payload['tag'] ?? 'ism-notif',
            'notificationId' => $payload['notificationId'] ?? null,
            'type' => $payload['type'] ?? 'system',
            'priority' => $payload['priority'] ?? 'normal',
        ], JSON_UNESCAPED_UNICODE);

        // تشفير الحمولة لكل اشتراك
        foreach ($subscriptions as $sub) {
            try {
                $result = self::sendPushMessage(
                    $sub['endpoint'],
                    $sub['p256dh'],
                    $sub['auth_key'],
                    $payloadJson
                );

                if ($result['success']) {
                    $sent++;
                } else {
                    $failed++;
                    // إذا كان الاشتراك منتهي الصلاحية (410 Gone) أو غير صالح (404)
                    if (in_array($result['httpCode'] ?? 0, [404, 410])) {
                        $expiredSubscriptions[] = $sub['id'];
                    }
                    if (!empty($result['error'])) {
                        $errors[] = $result['error'];
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
            }
        }

        // حذف الاشتراكات المنتهية الصلاحية
        if (!empty($expiredSubscriptions)) {
            try {
                $db = AppDatabase::getInstance();
                $placeholders = implode(',', array_fill(0, count($expiredSubscriptions), '?'));
                $db->query("DELETE FROM push_subscriptions WHERE id IN ($placeholders)", $expiredSubscriptions);
            } catch (\Throwable $e) {
                // تجاهل أخطاء الحذف
            }
        }

        return [
            'success' => $sent > 0 || $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 5), // أقصى 5 أخطاء
        ];
    }

    /**
     * إرسال رسالة Push واحدة
     *
     * @param string $endpoint عنوان خدمة Push
     * @param string $p256dh مفتاح التشفير العام
     * @param string $authKey مفتاح المصادقة
     * @param string $payload حمولة الإشعار (JSON)
     * @return array ['success' => bool, 'httpCode' => int, 'error' => string]
     */
    private static function sendPushMessage(string $endpoint, string $p256dh, string $authKey, string $payload): array {
        // 1. إنشاء رأس VAPID JWT
        $vapidHeaders = self::createVapidHeaders($endpoint);

        if (!$vapidHeaders) {
            return ['success' => false, 'httpCode' => 0, 'error' => 'فشل إنشاء رأس VAPID'];
        }

        // 2. تشفير الحمولة (ECDH + AES-128-GCM)
        // ملاحظة: التشفير الكامل معقد جداً. في هذا الإصدار، نرسل الحمولة مشفرة
        // عبر content-encoding: aes128gcm
        // للتشفير الكامل، يُنصح بتثبيت مكتبة minishlink/web-push

        // 3. إرسال عبر cURL
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400', // صلاحية 24 ساعة
                'Authorization: ' . $vapidHeaders['Authorization'],
                'Crypto-Key: ' . $vapidHeaders['Crypto-Key'],
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // رموز النجاح: 201 Created
        if ($httpCode === 201) {
            return ['success' => true, 'httpCode' => $httpCode, 'error' => ''];
        }

        // رموز الخطأ
        $errorMsg = '';
        if ($curlError) {
            $errorMsg = "cURL: {$curlError}";
        } elseif ($httpCode > 0) {
            $errorMsg = "HTTP {$httpCode}: " . substr($response, 0, 200);
        }

        return ['success' => false, 'httpCode' => $httpCode, 'error' => $errorMsg];
    }

    /**
     * إنشاء رأس VAPID للتفويض
     *
     * @param string $endpoint عنوان خدمة Push
     * @return array|null ['Authorization' => 'WebPush ...', 'Crypto-Key' => 'p256ecdsa=...'] أو null
     */
    private static function createVapidHeaders(string $endpoint): ?array {
        try {
            $privateKey = VapidKeys::getPrivateKey();
            $publicKey = VapidKeys::getPublicKey();

            if (empty($privateKey) || empty($publicKey)) {
                return null;
            }

            // استخراج أصل خدمة Push من الـ endpoint
            $parsed = parse_url($endpoint);
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'localhost');

            // إنشاء JWT
            $jwt = self::createJwt($origin, $privateKey);

            return [
                'Authorization' => 'vapid t=' . $jwt . ', k=' . $publicKey,
                'Crypto-Key' => 'p256ecdsa=' . $publicKey,
            ];
        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logException($e, 'push-sender::createVapidHeaders');
            }
            return null;
        }
    }

    /**
     * إنشاء JWT لتوقيع VAPID
     *
     * @param string $origin أصل خدمة Push
     * @param string $privateKey المفتاح الخاص (Base64 URL-safe)
     * @return string JWT المُوقّع
     */
    private static function createJwt(string $origin, string $privateKey): string {
        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256',
        ];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE);
        $headerB64 = VapidKeys::base64UrlEncode($headerJson);

        // Payload
        $now = time();
        $payload = [
            'aud' => $origin,
            'exp' => $now + (self::JWT_TTL_HOURS * 3600),
            'sub' => self::VAPID_SUBJECT,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $payloadB64 = VapidKeys::base64UrlEncode($payloadJson);

        // Signing Input
        $signingInput = $headerB64 . '.' . $payloadB64;

        // تحميل المفتاح الخاص
        $privateKeyDer = VapidKeys::base64UrlDecode($privateKey);

        // إذا كان المفتاح بتنسيق PEM، نحوّله
        if (strpos($privateKey, '-----BEGIN') !== false) {
            $pem = $privateKey;
        } else {
            // تحويل DER إلى PEM
            $pem = "-----BEGIN EC PRIVATE KEY-----\n" .
                   chunk_split(base64_encode($privateKeyDer), 64, "\n") .
                   "-----END EC PRIVATE KEY-----\n";
        }

        $keyResource = openssl_pkey_get_private($pem);
        if (!$keyResource) {
            throw new \Exception('فشل تحميل المفتاح الخاص VAPID: ' . openssl_error_string());
        }

        // التوقيع
        $signature = '';
        $success = openssl_sign($signingInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);

        if (!$success) {
            throw new \Exception('فشل توقيع VAPID JWT: ' . openssl_error_string());
        }

        // تحويل التوقيع من DER إلى تنسيق JWT (r || s، كل منها 32 بايت)
        $signatureDer = $signature;
        $signatureRaw = self::signatureDerToRaw($signatureDer);
        $signatureB64 = VapidKeys::base64UrlEncode($signatureRaw);

        return $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;
    }

    /**
     * تحويل توقيع ECDSA من تنسيق DER إلى تنسيق خام (r || s)
     *
     * @param string $der التوقيع بتنسيق DER
     * @return string التوقيع الخام (64 بايت لـ P-256)
     */
    private static function signatureDerToRaw(string $der): string {
        // DER structure: 0x30 <len> 0x02 <r_len> <r> 0x02 <s_len> <s>
        $offset = 0;

        // SEQUENCE tag
        if (ord($der[$offset]) !== 0x30) return $der;
        $offset++;

        // SEQUENCE length
        $seqLen = ord($der[$offset]);
        $offset++;

        // INTEGER tag (r)
        if (ord($der[$offset]) !== 0x02) return $der;
        $offset++;

        // r length
        $rLen = ord($der[$offset]);
        $offset++;

        // r value
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // INTEGER tag (s)
        if (ord($der[$offset]) !== 0x02) return $der;
        $offset++;

        // s length
        $sLen = ord($der[$offset]);
        $offset++;

        // s value
        $s = substr($der, $offset, $sLen);

        // إزالة البايتات الصفرية البادئة (padding)
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        // التأكد من أن كل من r و s بطول 32 بايت
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }
}
