<?php
/**
 * ===== نظام تشفير البيانات الحساسة في .env (EnvEncryptor) =====
 * يوفر تشفير وفك تشفير القيم الحساسة في ملف .env
 * باستخدام خوارزمية AES-256-GCM مع مفتاح APP_ENCRYPTION_KEY
 *
 * الاستخدام:
 *   // تشفير قيمة
 *   $encrypted = EnvEncryptor::encrypt('my_password');
 *   // ← "ENC:AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA="
 *
 *   // فك تشفير قيمة
 *   $decrypted = EnvEncryptor::decrypt('ENC:AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=');
 *   // ← "my_password"
 *
 * اتفاقية القيم المشفرة:
 *   - تبدأ بالبادئة "ENC:" لتعريفها كقيمة مشفرة
 *   - التنسيق: ENC:base64(iv + tag + ciphertext)
 *   - الخوارزمية: AES-256-GCM (مصادقة + تشفير)
 *
 * ملف .env:
 *   DB_PASS=ENC:AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=
 *   DEPLOYER_SECRET=ENC:UHV0LXlvdXItc3Ryb25nLXNlY3JldC1oZXJl
 *   APP_ENCRYPTION_KEY=base64_encoded_32_byte_key
 */

class EnvEncryptor
{
    /** @var string خوارزمية التشفير */
    private const CIPHER = 'aes-256-gcm';

    /** @var int طول المتجه الأولي (IV) بالبايت */
    private const IV_LENGTH = 12;

    /** @var int طول علامة المصادقة (TAG) بالبايت */
    private const TAG_LENGTH = 16;

    /** @var string بادئة القيم المشفرة */
    private const PREFIX = 'ENC:';

    /** @var string|null مفتاح التشفير */
    private static ?string $key = null;

    /** @var bool هل تم تحذير المفتاح الضعيف */
    private static bool $weakKeyWarned = false;

    /**
     * تعيين مفتاح التشفير
     *
     * @param string $key مفتاح التشفير (32 بايت)
     */
    public static function setKey(string $key): void
    {
        self::$key = $key;
    }

    /**
     * الحصول على مفتاح التشفير من البيئة
     *
     * @return string|null مفتاح التشفير أو null إذا لم يكن متوفراً
     */
    private static function getKey(): ?string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        // محاولة تحميل المفتاح من متغيرات البيئة
        $keyBase64 = getenv('APP_ENCRYPTION_KEY');
        if ($keyBase64 === false || empty($keyBase64)) {
            return null;
        }

        // إذا كان المفتاح بصيغة base64، ن فك ترميزه
        $decoded = base64_decode($keyBase64, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            self::$key = $decoded;
            return self::$key;
        }

        // إذا كان المفتاح خام 32 بايت
        if (strlen($keyBase64) === 32) {
            self::$key = $keyBase64;
            return self::$key;
        }

        return null;
    }

    /**
     * تشفير قيمة نصية
     *
     * @param string $plaintext النص المراد تشفيره
     * @return string القيمة المشفرة بالبادئة ENC:
     * @throws \RuntimeException إذا لم يكن مفتاح التشفير متوفراً
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        if ($key === null) {
            throw new \RuntimeException('مفتاح التشفير APP_ENCRYPTION_KEY غير متوفر. لا يمكن تشفير البيانات.');
        }

        // توليد متجه أولي عشوائي (IV)
        $iv = random_bytes(self::IV_LENGTH);

        // تشفير باستخدام AES-256-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('فشل التشفير: ' . openssl_error_string());
        }

        // دمج IV + TAG + Ciphertext وتشفيره بصيغة base64
        $payload = $iv . $tag . $ciphertext;
        return self::PREFIX . base64_encode($payload);
    }

    /**
     * فك تشفير قيمة مشفرة
     *
     * @param string $encrypted القيمة المشفرة (تبدأ بـ ENC:)
     * @return string النص الأصلي
     * @throws \RuntimeException إذا فشل فك التشفير
     */
    public static function decrypt(string $encrypted): string
    {
        // إذا لم تكن القيمة مشفرة، نعيدها كما هي
        if (!self::isEncrypted($encrypted)) {
            return $encrypted;
        }

        $key = self::getKey();
        if ($key === null) {
            // إذا لم يكن المفتاح متوفراً، نحاول إعادة القيمة كما هي
            // مع تحذير في السجلات
            error_log('[EnvEncryptor] تحذير: مفتاح التشفير غير متوفر، لا يمكن فك تشفير القيمة');
            return $encrypted;
        }

        // إزالة البادئة وفك ترميز base64
        $payload = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if ($payload === false) {
            throw new \RuntimeException('فشل فك ترميز base64 للقيمة المشفرة');
        }

        // استخراج IV و TAG و Ciphertext
        $ivLength = self::IV_LENGTH;
        $tagLength = self::TAG_LENGTH;

        if (strlen($payload) < $ivLength + $tagLength) {
            throw new \RuntimeException('طول البيانات المشفرة غير صالح');
        }

        $iv = substr($payload, 0, $ivLength);
        $tag = substr($payload, $ivLength, $tagLength);
        $ciphertext = substr($payload, $ivLength + $tagLength);

        // فك التشفير باستخدام AES-256-GCM
        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('فشل فك التشفير: مفتاح غير صحيح أو بيانات تالفة');
        }

        return $decrypted;
    }

    /**
     * التحقق مما إذا كانت القيمة مشفرة
     *
     * @param string $value القيمة المراد فحصها
     * @return bool هل القيمة مشفرة
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * فك تشفير قيمة من .env (آمن — يُعيد القيمة كما هي إذا فشل فك التشفير)
     *
     * @param string $value القيمة من .env
     * @return string القيمة الأصلية (مفكوكة التشفير أو كما هي)
     */
    public static function decryptOrRaw(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        try {
            return self::decrypt($value);
        } catch (\Throwable $e) {
            // في حالة فشل فك التشفير، نعيد القيمة كما هي
            // ونُسجّل التحذير
            error_log('[EnvEncryptor] تحذير: فشل فك تشفير قيمة - ' . $e->getMessage());
            return $value;
        }
    }

    /**
     * توليد مفتاح تشفير جديد (32 بايت)
     *
     * @return string مفتاح مشفر بصيغة base64
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * كشف بيانات الاعتماد الضعيفة في .env
     *
     * @param string $key مفتاح الإعداد
     * @param string $value قيمة الإعداد
     * @return array|null تحذير إذا كانت القيمة ضعيفة، أو null
     */
    public static function detectWeakCredential(string $key, string $value): ?array
    {
        // فك تشفير القيمة أولاً إذا كانت مشفرة
        $rawValue = self::isEncrypted($value) ? '***مشفرة***' : $value;

        // لا نحلل القيم المشفرة — هي آمنة بالفعل
        if (self::isEncrypted($value)) {
            return null;
        }

        $warnings = [];

        // كشف كلمات المرور الضعيفة
        $weakPasswords = ['admin', 'password', '123456', 'root', '', 'admin123', '938685', 'changeme', 'secret'];
        $keyLower = strtolower($key);
        $isSensitiveKey = str_contains($keyLower, 'pass') || str_contains($keyLower, 'secret') || str_contains($keyLower, 'key');

        if ($isSensitiveKey && in_array(strtolower(trim($value)), $weakPasswords)) {
            $warnings[] = 'كلمة مرور/سر ضعيفة أو افتراضية';
        }

        // كشف كلمات المرور القصيرة
        if ($isSensitiveKey && strlen($value) > 0 && strlen($value) < 12) {
            $warnings[] = 'كلمة المرور قصيرة جداً (أقل من 12 حرف)';
        }

        // كشف عدم تشفير القيم الحساسة
        if ($isSensitiveKey && !self::isEncrypted($value) && !empty($value)) {
            $warnings[] = 'القيمة غير مشفرة — يُنصح بتشفيرها باستخدام ENC:';
        }

        if (empty($warnings)) {
            return null;
        }

        return [
            'key' => $key,
            'warnings' => $warnings,
            'severity' => in_array(strtolower(trim($value)), $weakPasswords) ? 'high' : 'medium',
        ];
    }

    /**
     * فحص جميع بيانات .env وكشف القيم الضعيفة
     *
     * @param string $envPath مسار ملف .env
     * @return array قائمة التحذيرات
     */
    public static function auditEnvFile(string $envPath): array
    {
        $warnings = [];

        if (!file_exists($envPath)) {
            return [['key' => '.env', 'warnings' => ['ملف .env غير موجود'], 'severity' => 'high']];
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $hasEncryptionKey = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === 'APP_ENCRYPTION_KEY') {
                $hasEncryptionKey = !empty($value);
                continue;
            }

            $warning = self::detectWeakCredential($key, $value);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        if (!$hasEncryptionKey) {
            $warnings[] = [
                'key' => 'APP_ENCRYPTION_KEY',
                'warnings' => ['مفتاح التشفير غير معرّف — لا يمكن تشفير البيانات الحساسة'],
                'severity' => 'medium',
            ];
        }

        return $warnings;
    }

    /**
     * تشفير ملف .env بالكامل (تشفير القيم الحساسة فقط)
     *
     * @param string $envPath مسار ملف .env
     * @return array تقرير التشفير
     */
    public static function encryptEnvFile(string $envPath): array
    {
        if (!file_exists($envPath)) {
            return ['success' => false, 'message' => 'ملف .env غير موجود'];
        }

        $key = self::getKey();
        if ($key === null) {
            return ['success' => false, 'message' => 'مفتاح التشفير APP_ENCRYPTION_KEY غير متوفر'];
        }

        $sensitiveKeys = ['DB_PASS', 'DEPLOYER_SECRET', 'APP_ENCRYPTION_KEY', 'REDIS_PASSWORD'];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        $encrypted = [];
        $skipped = [];

        foreach ($lines as &$line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $trimmed, 2);
            $k = trim($k);
            $v = trim($v);

            // تخطي المفتاح نفسه والقيم الفارغة والمشفرة بالفعل
            if ($k === 'APP_ENCRYPTION_KEY' || empty($v) || self::isEncrypted($v)) {
                $skipped[] = $k;
                continue;
            }

            if (in_array($k, $sensitiveKeys)) {
                try {
                    $encryptedValue = self::encrypt($v);
                    $line = "{$k}={$encryptedValue}";
                    $encrypted[] = $k;
                } catch (\Throwable $e) {
                    $skipped[] = $k . ' (فشل التشفير)';
                }
            }
        }
        unset($line);

        // حفظ الملف المشفر
        file_put_contents($envPath, implode("\n", $lines) . "\n", LOCK_EX);

        return [
            'success' => true,
            'encrypted' => $encrypted,
            'skipped' => $skipped,
            'message' => 'تم تشفير ' . count($encrypted) . ' قيمة حساسة بنجاح',
        ];
    }
}
