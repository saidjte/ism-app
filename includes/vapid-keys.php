<?php
/**
 * ===== أدوات مفاتيح VAPID =====
 * توليد وإدارة مفاتيح VAPID لإشعارات Push
 *
 * VAPID (Voluntary Application Server Identification) يُعرّف خادم التطبيق
 * أمام خدمات Push (FCM, Mozilla) ويُمكّن من إرسال إشعارات بدون تسجيل
 * مشروع منفصل على كل خدمة.
 *
 * يتطلب: PHP 8.1+ — يتم استخدام سطر أوامر openssl أو PHP خالص
 * لا يعتمد على دوال PHP OpenSSL (openssl_pkey_new/export) التي قد تفشل
 */

// منع التحميل المزدوج
if (defined('VAPID_KEYS_LOADED')) return;
define('VAPID_KEYS_LOADED', true);

class VapidKeys {

    /**
     * توليد زوج مفاتيح VAPID جديد (ECDSA P-256)
     *
     * الطرق بالترتيب:
     * 1. سطر أوامر openssl (كلياً بدون PHP OpenSSL) — الأكثر موثوقية
     * 2. دوال PHP OpenSSL مع ملف إعداد مؤقت
     * 3. توليد P-256 خالص بـ PHP (بدون أي امتداد)
     *
     * @return array ['publicKey' => string, 'privateKey' => string]
     * @throws Exception إذا فشلت كل الطرق
     */
    public static function generate(): array {
        $errors = [];

        // الطريقة 1: سطر أوامر openssl — بدون أي دوال PHP OpenSSL
        try {
            $result = self::generateViaCliOnly();
            if ($result) return $result;
        } catch (\Throwable $e) {
            $errors[] = 'cli: ' . $e->getMessage();
        }

        // الطريقة 2: دوال PHP OpenSSL مع ملف إعداد مؤقت
        try {
            $result = self::generateViaPhpOpenSSL();
            if ($result) return $result;
        } catch (\Throwable $e) {
            $errors[] = 'php_openssl: ' . $e->getMessage();
        }

        // الطريقة 3: توليد P-256 خالص بـ PHP
        try {
            $result = self::generateViaPurePhp();
            if ($result) return $result;
        } catch (\Throwable $e) {
            $errors[] = 'pure_php: ' . $e->getMessage();
        }

        $detail = implode(' | ', $errors);
        throw new \Exception('فشل توليد مفاتيح VAPID بجميع الطرق — ' . $detail);
    }

    // ========================================================================
    // الطريقة 1: سطر أوامر openssl — بدون أي دوال PHP OpenSSL
    // ========================================================================

    /**
     * توليد مفاتيح VAPID عبر سطر أوامر openssl فقط
     * لا يستخدم أي دالة من امتداد PHP OpenSSL
     * يُحلل النص الخام من openssl ويحوله إلى Base64URL
     */
    private static function generateViaCliOnly(): ?array {
        if (!function_exists('exec') || !is_callable('exec')) return null;

        $opensslBin = self::findOpenSSLBinary();
        if (!$opensslBin) return null;

        // إنشاء ملف إعداد مؤقت لـ openssl CLI
        $cnfPath = self::createTempConfig();
        $envPrefix = $cnfPath ? "OPENSSL_CONF=" . escapeshellarg($cnfPath) . " " : "";

        $tmpDir = sys_get_temp_dir();
        $uid = uniqid('vapid_', true);
        $privateFile = $tmpDir . '/' . $uid . '_priv.pem';
        $textFile = $tmpDir . '/' . $uid . '_text.txt';

        try {
            // توليد مفتاح EC خاص
            $cmd1 = "{$envPrefix}{$opensslBin} ecparam -name prime256v1 -genkey -noout -out " . escapeshellarg($privateFile) . " 2>&1";
            exec($cmd1, $out1, $ret1);
            if ($ret1 !== 0 || !file_exists($privateFile)) return null;

            // استخراج النص الخام (المفتاح الخاص + العام بصيغة hex)
            $cmd2 = "{$envPrefix}{$opensslBin} ec -in " . escapeshellarg($privateFile) . " -text -noout -out " . escapeshellarg($textFile) . " 2>&1";
            exec($cmd2, $out2, $ret2);
            if ($ret2 !== 0 || !file_exists($textFile)) {
                @unlink($privateFile);
                return null;
            }

            $textOutput = file_get_contents($textFile);

            // حذف الملفات المؤقتة مباشرة
            @unlink($privateFile);
            @unlink($textFile);

            if (empty($textOutput)) return null;

            // تحليل النص لاستخراج المفاتيح
            return self::parseOpenSSLTextOutput($textOutput);

        } catch (\Throwable $e) {
            @unlink($privateFile);
            @unlink($textFile);
            return null;
        }
    }

    /**
     * تحليل خرج openssl ec -text -noout
     * يستخرج المفتاح الخاص (d) والعام (04||x||y) بصيغة hex
     * ثم يحولهما إلى Base64URL
     */
    private static function parseOpenSSLTextOutput(string $text): ?array {
        // استخراج المفتاح الخاص (قيمة d)
        $privateKeyHex = null;
        $publicKeyHex = null;

        // البحث عن قسم priv
        if (preg_match('/priv:\s*\n((?:\s+[0-9a-f:]+\n?)+)/i', $text, $m)) {
            $privateKeyHex = self::cleanHexBlock($m[1]);
        }

        // البحث عن قسم pub
        if (preg_match('/pub:\s*\n((?:\s+[0-9a-f:]+\n?)+)/i', $text, $m)) {
            $publicKeyHex = self::cleanHexBlock($m[1]);
        }

        if (!$privateKeyHex || !$publicKeyHex) return null;

        // تحويل hex إلى binary
        $privateKeyBin = hex2bin($privateKeyHex);
        $publicKeyBin = hex2bin($publicKeyHex);

        if (!$privateKeyBin || !$publicKeyBin) return null;

        // المفتاح الخاص: يجب أن يكون 32 بايت (P-256 scalar)
        // إذا كان 33 بايت (بادئة 00)، أزلها
        if (strlen($privateKeyBin) === 33 && $privateKeyBin[0] === "\x00") {
            $privateKeyBin = substr($privateKeyBin, 1);
        }

        // المفتاح العام: يجب أن يبدأ بـ 04 (نقطة غير مضغوطة) وطوله 65 بايت
        if (strlen($publicKeyBin) >= 65 && $publicKeyBin[0] === "\x04") {
            $publicKeyBin = substr($publicKeyBin, 0, 65);
        } elseif (strlen($publicKeyBin) === 64) {
            // بدون بادئة 04 — أضفها
            $publicKeyBin = "\x04" . $publicKeyBin;
        }

        // التحقق من الأطوال
        if (strlen($privateKeyBin) !== 32 || strlen($publicKeyBin) !== 65) {
            return null;
        }

        $publicKeyBase64 = self::base64UrlEncode($publicKeyBin);
        $privateKeyBase64 = self::base64UrlEncode($privateKeyBin);

        if (empty($publicKeyBase64) || empty($privateKeyBase64)) return null;

        return [
            'publicKey'     => $publicKeyBase64,
            'privateKey'    => $privateKeyBase64,
            'publicKeyPem'  => '', // غير مطلوب للعمل
            'privateKeyPem' => '', // غير مطلوب للعمل
        ];
    }

    /**
     * تنظيف كتلة hex من خرج openssl
     * يحول "    00:1a:2b:3c:..." إلى "001a2b3c..."
     */
    private static function cleanHexBlock(string $block): string {
        $block = preg_replace('/[^0-9a-fA-F]/', '', $block);
        return strtolower($block);
    }

    // ========================================================================
    // الطريقة 2: دوال PHP OpenSSL مع ملف إعداد مؤقت
    // ========================================================================

    private static function generateViaPhpOpenSSL(): ?array {
        $cnfPath = self::createTempConfig();
        if (!$cnfPath) return null;

        $oldConf = getenv('OPENSSL_CONF');
        putenv("OPENSSL_CONF={$cnfPath}");

        $config = [
            'digest_alg'       => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
            'config'           => $cnfPath,
        ];

        $keyPair = @openssl_pkey_new($config);

        if (!$keyPair) {
            self::restoreEnvConf($oldConf);
            return null;
        }

        // استخراج المفتاح الخاص — مع ملف الإعداد
        $privateKeyPem = '';
        $exportOk = @openssl_pkey_export($keyPair, $privateKeyPem, null, ['config' => $cnfPath]);

        if (!$exportOk || empty($privateKeyPem)) {
            // محاولة ثانية بدون config
            $exportOk = @openssl_pkey_export($keyPair, $privateKeyPem);
        }

        self::restoreEnvConf($oldConf);

        if (!$exportOk || empty($privateKeyPem)) return null;

        // استخراج المفتاح العام
        $details = @openssl_pkey_get_details($keyPair);
        if (!$details) return null;
        $publicKeyPem = $details['key'];

        // تحويل إلى Base64URL
        $publicKeyDer = self::pemToDer($publicKeyPem);
        $privateKeyDer = self::pemToDer($privateKeyPem, true);
        $publicKeyBase64 = self::base64UrlEncode($publicKeyDer);
        $privateKeyBase64 = self::base64UrlEncode($privateKeyDer);

        if (strlen($publicKeyDer) !== 65 || strlen($privateKeyDer) !== 32) return null;

        return [
            'publicKey'     => $publicKeyBase64,
            'privateKey'    => $privateKeyBase64,
            'publicKeyPem'  => $publicKeyPem,
            'privateKeyPem' => $privateKeyPem,
        ];
    }

    // ========================================================================
    // الطريقة 3: توليد P-256 خالص بـ PHP (بدون أي امتداد)
    // ========================================================================

    /**
     * توليد زوج مفاتيح P-256 خالص بـ PHP
     * يستخدم عمليات حسابية على المنحنى الإهليلجي P-256
     * لا يعتمد على أي امتداد OpenSSL أو Sodium
     */
    private static function generateViaPurePhp(): ?array {
        // معاملات المنحنى P-256 (secp256r1 / prime256v1)
        $p = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF', 16);
        $a = gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFC', 16);
        $b = gmp_init('5AC635D8AA3A93E7B3EBBD55769886BC651D06B0CC53B0F63BCE3C3E27D2604B', 16);
        $n = gmp_init('FFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551', 16);
        $Gx = gmp_init('6B17D1F2E12C4247F8BCE6E563A440F277037D812DEB33A0F4A13945D898C296', 16);
        $Gy = gmp_init('4FE342E2FE1A7F9B8EE7EB4A7C0F9E162BCE33576B315ECECBB6406837BF51F5', 16);

        if (!function_exists('gmp_init')) return null;

        // توليد مفتاح خاص عشوائي (1 <= d < n)
        $d = null;
        $one = gmp_init(1);
        $nMinus1 = gmp_sub($n, $one);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $randomBytes = random_bytes(32);
            $d = gmp_init(bin2hex($randomBytes), 16);
            // التأكد من أن d في النطاق الصحيح
            if (gmp_cmp($d, $one) >= 0 && gmp_cmp($d, $nMinus1) <= 0) {
                break;
            }
            $d = null;
        }

        if ($d === null) return null;

        // حساب المفتاح العام: Q = d * G
        $Q = self::ecPointMultiply($d, $Gx, $Gy, $p, $a, $n);
        if ($Q === null) return null;

        [$Qx, $Qy] = $Q;

        // تحويل إلى binary
        $privateKeyBin = self::gmpToPaddedBinary($d, 32);
        $publicKeyBin = "\x04" . self::gmpToPaddedBinary($Qx, 32) . self::gmpToPaddedBinary($Qy, 32);

        if (strlen($privateKeyBin) !== 32 || strlen($publicKeyBin) !== 65) return null;

        $publicKeyBase64 = self::base64UrlEncode($publicKeyBin);
        $privateKeyBase64 = self::base64UrlEncode($privateKeyBin);

        // التحقق من صحة المفتاح العام (Q على المنحنى)
        $y2 = gmp_powm($Qy, gmp_init(2), $p);
        $x3 = gmp_powm($Qx, gmp_init(3), $p);
        $ax = gmp_mul($a, $Qx);
        $rhs = gmp_mod(gmp_add(gmp_add($x3, $ax), $b), $p);
        if (gmp_cmp($y2, $rhs) !== 0) return null;

        return [
            'publicKey'     => $publicKeyBase64,
            'privateKey'    => $privateKeyBase64,
            'publicKeyPem'  => '',
            'privateKeyPem' => '',
        ];
    }

    /**
     * ضرب نقطة على المنحنى الإهليلجي P-256
     * خوارزمية Double-and-Add
     *
     * @return array|null [x, y] أو null
     */
    private static function ecPointMultiply(\GMP $k, \GMP $Px, \GMP $Py, \GMP $p, \GMP $a, \GMP $n): ?array {
        // استخدم نقطة في اللانهاية كـ 0
        $Rx = gmp_init(0);
        $Ry = gmp_init(0);
        $isInfinity = true;

        $Qx = $Px;
        $Qy = $Py;

        $kBits = gmp_strval($k, 2); // binary string
        $len = strlen($kBits);

        for ($i = 0; $i < $len; $i++) {
            if ($kBits[$i] === '1') {
                if ($isInfinity) {
                    $Rx = $Qx;
                    $Ry = $Qy;
                    $isInfinity = false;
                } else {
                    // R = R + Q (جمع نقطتين)
                    $sum = self::ecPointAdd($Rx, $Ry, $Qx, $Qy, $p, $a);
                    if ($sum === null) return null;
                    [$Rx, $Ry] = $sum;
                }
            }
            // Q = 2Q (مضاعفة نقطة)
            $dbl = self::ecPointDouble($Qx, $Qy, $p, $a);
            if ($dbl === null) return null;
            [$Qx, $Qy] = $dbl;
        }

        if ($isInfinity) return null;

        return [$Rx, $Ry];
    }

    /**
     * جمع نقطتين على المنحنى الإهليلجي
     */
    private static function ecPointAdd(\GMP $x1, \GMP $y1, \GMP $x2, \GMP $y2, \GMP $p, \GMP $a): ?array {
        // إذا كانت النقطتان متساويتين، استخدم مضاعفة النقطة
        if (gmp_cmp($x1, $x2) === 0) {
            if (gmp_cmp($y1, $y2) === 0) {
                return self::ecPointDouble($x1, $y1, $p, $a);
            }
            // P + (-P) = O
            return null;
        }

        // λ = (y2 - y1) / (x2 - x1) mod p
        $dy = gmp_mod(gmp_sub($y2, $y1), $p);
        $dx = gmp_mod(gmp_sub($x2, $x1), $p);
        $dxInv = gmp_invert($dx, $p);
        if ($dxInv === false) return null;

        $lambda = gmp_mod(gmp_mul($dy, $dxInv), $p);

        // x3 = λ² - x1 - x2 mod p
        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_powm($lambda, 2, $p), $x1), $x2), $p);

        // y3 = λ(x1 - x3) - y1 mod p
        $y3 = gmp_mod(gmp_sub(gmp_mul($lambda, gmp_sub($x1, $x3)), $y1), $p);

        return [$x3, $y3];
    }

    /**
     * مضاعفة نقطة على المنحنى الإهليلجي (2P)
     */
    private static function ecPointDouble(\GMP $x, \GMP $y, \GMP $p, \GMP $a): ?array {
        // y = 0 → النتيجة هي نقطة اللانهاية
        if (gmp_cmp($y, 0) === 0) return null;

        // λ = (3x² + a) / (2y) mod p
        $x2 = gmp_powm($x, 2, $p);
        $numerator = gmp_mod(gmp_add(gmp_mul(gmp_init(3), $x2), $a), $p);
        $denominator = gmp_mod(gmp_mul(gmp_init(2), $y), $p);
        $denInv = gmp_invert($denominator, $p);
        if ($denInv === false) return null;

        $lambda = gmp_mod(gmp_mul($numerator, $denInv), $p);

        // x3 = λ² - 2x mod p
        $x3 = gmp_mod(gmp_sub(gmp_powm($lambda, 2, $p), gmp_mul(gmp_init(2), $x)), $p);

        // y3 = λ(x - x3) - y mod p
        $y3 = gmp_mod(gmp_sub(gmp_mul($lambda, gmp_sub($x, $x3)), $y), $p);

        return [$x3, $y3];
    }

    /**
     * تحويل GMP إلى binary مع padding
     */
    private static function gmpToPaddedBinary(\GMP $num, int $byteLen): string {
        $hex = gmp_strval($num, 16);
        $hex = str_pad($hex, $byteLen * 2, '0', STR_PAD_LEFT);
        return hex2bin($hex);
    }

    // ========================================================================
    // أدوات مشتركة
    // ========================================================================

    /** @var string|null مسار ملف إعداد OpenSSL المؤقت */
    private static ?string $tempCnfPath = null;

    /**
     * إنشاء ملف إعداد OpenSSL مؤقت
     */
    private static function createTempConfig(): ?string {
        if (self::$tempCnfPath !== null && file_exists(self::$tempCnfPath)) {
            return self::$tempCnfPath;
        }

        $tmpDir = sys_get_temp_dir();
        if (!is_writable($tmpDir)) {
            $altDirs = [dirname(__DIR__), __DIR__, dirname(__DIR__) . '/tmp', dirname(__DIR__) . '/cache'];
            foreach ($altDirs as $dir) {
                if (is_dir($dir) && is_writable($dir)) { $tmpDir = $dir; break; }
            }
        }

        $cnfPath = $tmpDir . '/ism_vapid_openssl_' . md5(__FILE__) . '.cnf';

        $cnfContent = <<<'CNF'
HOME            = .
RANDFILE        = $ENV::HOME/.rnd
[ ca ]
default_ca      = CA_default
[ CA_default ]
dir             = ./demoCA
certs           = $dir/certs
database        = $dir/index.txt
new_certs_dir   = $dir/newcerts
[ req ]
default_bits       = 2048
default_md         = sha256
distinguished_name = req_dn
prompt             = no
[ req_dn ]
CN = ISM VAPID
CNF;

        if (@file_put_contents($cnfPath, $cnfContent) === false) return null;
        if (!is_readable($cnfPath)) { @unlink($cnfPath); return null; }

        self::$tempCnfPath = $cnfPath;
        return $cnfPath;
    }

    /**
     * تنظيف ملف الإعداد المؤقت
     */
    private static function cleanupTempConfig(): void {
        if (self::$tempCnfPath !== null && file_exists(self::$tempCnfPath)) {
            @unlink(self::$tempCnfPath);
            self::$tempCnfPath = null;
        }
    }

    /**
     * استعادة متغير بيئة OPENSSL_CONF
     */
    private static function restoreEnvConf($oldConf): void {
        if ($oldConf !== false) {
            putenv("OPENSSL_CONF={$oldConf}");
        } else {
            putenv('OPENSSL_CONF');
        }
    }

    /**
     * البحث عن ملف تنفيذي openssl
     */
    private static function findOpenSSLBinary(): ?string {
        $paths = ['/usr/bin/openssl', '/usr/local/bin/openssl', '/usr/sbin/openssl',
                  '/opt/homebrew/bin/openssl', '/opt/local/bin/openssl'];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) return $path;
        }

        if (!function_exists('exec') || !is_callable('exec')) return null;

        $which = @exec('which openssl 2>/dev/null');
        if (!empty($which) && file_exists(trim($which))) return trim($which);

        $where = @exec('where openssl 2>nul');
        if (!empty($where)) {
            foreach (explode("\n", trim($where)) as $line) {
                $candidate = trim($line);
                if (file_exists($candidate)) return $candidate;
            }
        }

        return null;
    }

    // ========================================================================
    // حفظ / جلب / التحقق
    // ========================================================================

    /**
     * حفظ مفاتيح VAPID في إعدادات النظام
     */
    public static function save(string $publicKey, string $privateKey): bool {
        try {
            saveSetting('vapid_public_key', $publicKey, 'مفتاح VAPID العام لإشعارات Push');
            saveSetting('vapid_private_key', $privateKey, 'مفتاح VAPID الخاص لإشعارات Push');
            return true;
        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logException($e, 'vapid-keys::save');
            }
            return false;
        }
    }

    /**
     * جلب المفتاح العام من الإعدادات
     */
    public static function getPublicKey(): string {
        try { return trim(getSetting('vapid_public_key', '')); }
        catch (\Throwable $e) { return ''; }
    }

    /**
     * جلب المفتاح الخاص من الإعدادات
     */
    public static function getPrivateKey(): string {
        try { return trim(getSetting('vapid_private_key', '')); }
        catch (\Throwable $e) { return ''; }
    }

    /**
     * هل مفاتيح VAPID مُعدة؟
     */
    public static function isConfigured(): bool {
        return !empty(self::getPublicKey()) && !empty(self::getPrivateKey());
    }

    /**
     * توليد وحفظ مفاتيح VAPID جديدة
     */
    public static function generateAndSave(): array {
        try {
            $keys = self::generate();
            $saved = self::save($keys['publicKey'], $keys['privateKey']);
            self::cleanupTempConfig();

            if ($saved) {
                return ['success' => true, 'publicKey' => $keys['publicKey'],
                        'message' => 'تم توليد وحفظ مفاتيح VAPID بنجاح'];
            }
            return ['success' => false, 'publicKey' => '',
                    'message' => 'فشل حفظ مفاتيح VAPID'];
        } catch (\Throwable $e) {
            self::cleanupTempConfig();
            return ['success' => false, 'publicKey' => '',
                    'message' => 'فشل توليد مفاتيح VAPID: ' . $e->getMessage()];
        }
    }

    // ========================================================================
    // تحويل PEM → DER (للطريقة 2 فقط)
    // ========================================================================

    private static function pemToDer(string $pem, bool $isPrivate = false): string {
        $lines = explode("\n", trim($pem));
        $base64 = '';
        foreach ($lines as $line) {
            if (strpos($line, '-----') === 0) continue;
            $base64 .= trim($line);
        }
        $der = base64_decode($base64);

        if ($isPrivate) {
            return substr($der, -32);
        }

        for ($i = 0; $i < strlen($der) - 65; $i++) {
            if (ord($der[$i]) === 0x04 &&
                ($i === 0 || ord($der[$i - 1]) === 0x00) &&
                strlen($der) - $i >= 65) {
                return substr($der, $i, 65);
            }
        }

        return $der;
    }

    // ========================================================================
    // Base64 URL-safe
    // ========================================================================

    public static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($padded);
    }
}
