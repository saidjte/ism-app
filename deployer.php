<?php
/**
 * ========================================================================
 *  الناشر الذري المستقل — Standalone Atomic Deployer
 * ========================================================================
 *
 *  ملف مستقل تماماً لا يعتمد على أي ملف آخر في التطبيق.
 *  يُشحن مع التثبيت الأولي ويبقى دائماً متاحاً لرفع أي تحديث ZIP.
 *
 *  يحل معضلة الدجاجة والبيضة:
 *  - ميزة النشر الذري في upgrade.php تحتاج نشر الملفات أولاً
 *  - لكن deployer.php متاح دائماً بدون الحاجة لأي تحديث مسبق
 *
 *  تقنية النشر: Write-Then-Rename (الأمثل)
 *  - كل ملف يُستبدل ذرياً: write(tmp) → rename(tmp, target)
 *  - ذرية مضمونة على نفس نظام الملفات (POSIX guarantee)
 *  - يحافظ على بنية المجلدات (مهم لـ __DIR__ و __FILE__)
 *  - يعمل عبر أنظمة ملفات مختلفة
 *  - لا يعاني من مشكلة "نقل مجلد داخل نفسه" مثل Directory Swap
 *
 *  منذ v4.9.107
 * ========================================================================
 */

// ===== ثوابت =====
define('DEPLOYER_VERSION', '1.4.0');
define('DEPLOYER_MIN_PHP', '8.1.0');

// ====================================================================
//  دالة فك تشفير AES-256-GCM مستقلة — لا تعتمد على أي ملف خارجي
//  تُستخدم لفك تشفير القيم المشفرة (ENC:) في ملف .env
//  منذ v1.3.0 — يحل مشكلة عدم قدرة الناشر على فك تشفير الرمز السري
// ====================================================================

/**
 * فك تشفير قيمة مشفرة بـ AES-256-GCM من ملف .env
 *
 * التنسيق: ENC:base64(iv + tag + ciphertext)
 * - iv: 12 بايت (متجه أولي)
 * - tag: 16 بايت (علامة المصادقة)
 * - ciphertext: البيانات المشفرة
 *
 * @param string $encrypted القيمة المشفرة (تبدأ بـ ENC:)
 * @param string $keyBase64 مفتاح التشفير بصيغة base64 (من APP_ENCRYPTION_KEY)
 * @return string|null النص الأصلي أو null عند الفشل
 */
function deployer_decryptEnvValue(string $encrypted, string $keyBase64): ?string {
    // إذا لم تكن القيمة مشفرة، نعيدها كما هي
    if (!str_starts_with($encrypted, 'ENC:')) {
        return $encrypted;
    }

    // فك ترميز المفتاح
    $key = base64_decode($keyBase64, true);
    if ($key === false || strlen($key) !== 32) {
        return null;
    }

    // إزالة البادئة وفك ترميز base64
    $payload = base64_decode(substr($encrypted, 4), true);
    if ($payload === false || strlen($payload) < 28) {
        // 28 = 12 (iv) + 16 (tag) على الأقل
        return null;
    }

    // استخراج IV و TAG و Ciphertext
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);

    // فك التشفير باستخدام AES-256-GCM
    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $decrypted !== false ? $decrypted : null;
}

/**
 * قراءة قيمة من ملف .env مع فك التشفير التلقائي إن لزم
 *
 * @param string $envPath مسار ملف .env
 * @param string $key اسم المفتاح المطلوب
 * @return string|null القيمة (مفكوكة التشفير إن كانت مشفرة) أو null
 */
function deployer_readEnvValue(string $envPath, string $key): ?string {
    if (!file_exists($envPath)) {
        return null;
    }

    $content = file_get_contents($envPath);
    if ($content === false) {
        return null;
    }

    // البحث عن المفتاح
    if (!preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.+)$/m', $content, $m)) {
        return null;
    }

    $value = trim($m[1]);

    // إذا كانت القيمة مشفرة، نحاول فك التشفير
    if (str_starts_with($value, 'ENC:')) {
        // البحث عن مفتاح التشفير
        $encKey = null;
        if (preg_match('/^APP_ENCRYPTION_KEY\s*=\s*(.+)$/m', $content, $km)) {
            $encKey = trim($km[1]);
        }

        if (!empty($encKey)) {
            $decrypted = deployer_decryptEnvValue($value, $encKey);
            if ($decrypted !== null) {
                return $decrypted;
            }
        }

        // فشل فك التشفير — نعيد القيمة كما هي (لن تعمل للمصادقة)
        return $value;
    }

    return $value;
}

// ===== إعدادات الأمان =====
// رمز سري للوصول — يجب تعيينه في ملف .env
// تحذير: لا تترك القيمة الافتراضية في الإنتاج!
$deployer_secret = getenv('DEPLOYER_SECRET') ?: '';
$deployer_secret_source = 'env_var'; // لتتبع مصدر الرمز السري
$deployer_decryption_ok = true; // هل نجح فك التشفير؟

if (empty($deployer_secret)) {
    // محاولة القراءة من ملف .env مع فك التشفير التلقائي
    $envFile = __DIR__ . '/.env';
    $envValue = deployer_readEnvValue($envFile, 'DEPLOYER_SECRET');
    if ($envValue !== null) {
        $deployer_secret = $envValue;
        $deployer_secret_source = 'env_file';
        // تحقق: هل القيمة لا تزال مشفرة؟ (فشل فك التشفير)
        if (str_starts_with($deployer_secret, 'ENC:')) {
            $deployer_decryption_ok = false;
        }
    } else {
        $deployer_secret_source = 'missing';
    }
} elseif (str_starts_with($deployer_secret, 'ENC:')) {
    // إذا كانت القيمة من متغير البيئة مشفرة أيضاً، نحاول فكها
    $envFile = __DIR__ . '/.env';
    $encKey = null;
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        if (preg_match('/^APP_ENCRYPTION_KEY\s*=\s*(.+)$/m', $envContent, $km)) {
            $encKey = trim($km[1]);
        }
    }
    if (!empty($encKey)) {
        $decrypted = deployer_decryptEnvValue($deployer_secret, $encKey);
        if ($decrypted !== null) {
            $deployer_secret = $decrypted;
            $deployer_secret_source = 'env_var_decrypted';
        } else {
            $deployer_decryption_ok = false;
        }
    } else {
        $deployer_decryption_ok = false;
    }
}

// تنظيف الرمز السري من أي مسافات أو أحرف مخفية
$deployer_secret = trim($deployer_secret);

// فحص القيمة الافتراضية الضعيفة — تحذير أمني
$deployer_uses_default_secret = false;
$default_secrets = ['change_me_to_a_strong_secret_2026', 'changeme', 'secret', 'password', 'deployer', '123456'];
if (empty($deployer_secret) || in_array(strtolower($deployer_secret), $default_secrets, true)) {
    $deployer_uses_default_secret = true;
}

// عناوين IP المسموحة (فارغة = السماح للجميع عند عدم تعيين رمز سري)
$allowed_ips = [];
$iplFile = __DIR__ . '/.env';
if (file_exists($iplFile)) {
    $iplContent = file_get_contents($iplFile);
    if (preg_match('/^DEPLOYER_ALLOWED_IPS\s*=\s*(.+)$/m', $iplContent, $m)) {
        $allowed_ips = array_filter(array_map('trim', explode(',', trim($m[1]))));
    }
}

// ===== فحص إصدار PHP =====
if (version_compare(PHP_VERSION, DEPLOYER_MIN_PHP, '<')) {
    http_response_code(500);
    echo 'يتطلب PHP ' . DEPLOYER_MIN_PHP . ' أو أحدث. الإصدار الحالي: ' . PHP_VERSION;
    exit;
}

// ===== مسار التطبيق الجذري =====
$baseDir = __DIR__;

// ===== بدء الجلسة (مع إعدادات أمنية) =====
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// ====================================================================
//                نظام المصادقة — حماية الناشر من الوصول غير المصرح
// ====================================================================

$deployer_authenticated = false;
$deployer_auth_error = '';
$deployer_auth_debug = []; // معلومات تشخيصية (لا تكشف أسراراً)

// المصادقة مطلوبة دائماً — حتى لو لم يُعيّن رمز سري
$requires_auth = true;

// إعادة تعيين الحظر — معلمة ?reset_lockout=1
// تُستخدم عندما يكون المستخدم محظوراً ويريد إعادة المحاولة
if (isset($_GET['reset_lockout']) && $_GET['reset_lockout'] === '1') {
    $_SESSION['deployer_attempts'] = 0;
    $_SESSION['deployer_blocked_until'] = 0;
    unset($_SESSION['deployer_auth']);
    // إعادة التوجيه لنفس الصفحة بدون المعلمة
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    exit;
}

if ($requires_auth) {
    // فحص IP المسموح أولاً
    if (!empty($allowed_ips)) {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (in_array($clientIP, $allowed_ips)) {
            $deployer_authenticated = true;
        }
    }

    // إذا لم يتم المصادقة عبر IP، نتحقق من الرمز السري
    if (!$deployer_authenticated) {
        // فحص تقييد محاولات المصادقة (5 محاولات ثم حظر 15 دقيقة)
        $deployer_attempts = intval($_SESSION['deployer_attempts'] ?? 0);
        $deployer_blocked_until = intval($_SESSION['deployer_blocked_until'] ?? 0);

        if ($deployer_blocked_until > 0 && time() < $deployer_blocked_until) {
            $remaining = ceil(($deployer_blocked_until - time()) / 60);
            $deployer_auth_error = "تم حظر الوصول بسبب محاولات فاشلة متكررة. حاول بعد {$remaining} دقيقة.";
            $deployer_auth_debug[] = "محظور حتى: " . date('H:i:s', $deployer_blocked_until);
            $deployer_auth_debug[] = "<a href='?reset_lockout=1' style='color:#2563eb;text-decoration:underline'>إعادة تعيين الحظر</a>";
        } else {
            // انتهت فترة الحظر — إعادة تعيين
            if ($deployer_blocked_until > 0 && time() >= $deployer_blocked_until) {
                $_SESSION['deployer_attempts'] = 0;
                $_SESSION['deployer_blocked_until'] = 0;
                $deployer_attempts = 0;
            }

            $auth_secret = !empty($deployer_secret) ? $deployer_secret : '';

            // فحص الجلسة أولاً
            if (!empty($_SESSION['deployer_auth']) && !empty($auth_secret) && $_SESSION['deployer_auth'] === hash('sha256', $auth_secret)) {
                $deployer_authenticated = true;
            }
            // فحص الرمز المُرسل في النموذج
            elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deployer_password'])) {
                $input_password = trim($_POST['deployer_password']);

                if (empty($auth_secret)) {
                    $deployer_auth_error = 'لا يوجد رمز سري مُعيَّن — تحقق من ملف .env و APP_ENCRYPTION_KEY';
                    $deployer_auth_debug[] = 'مصدر الرمز: ' . $deployer_secret_source;
                    $deployer_auth_debug[] = 'فك التشفير: ' . ($deployer_decryption_ok ? 'نعم' : 'فشل!');
                } elseif (hash_equals(hash('sha256', $auth_secret), hash('sha256', $input_password))) {
                    $_SESSION['deployer_auth'] = hash('sha256', $auth_secret);
                    $_SESSION['deployer_attempts'] = 0;
                    $_SESSION['deployer_blocked_until'] = 0;
                    $deployer_authenticated = true;
                } else {
                    // تسجيل محاولة فاشلة
                    $deployer_attempts++;
                    $_SESSION['deployer_attempts'] = $deployer_attempts;

                    if ($deployer_attempts >= 5) {
                        $_SESSION['deployer_blocked_until'] = time() + (15 * 60);
                        $deployer_auth_error = 'تم حظر الوصول بسبب 5 محاولات فاشلة. حاول بعد 15 دقيقة.';
                    } else {
                        $deployer_auth_error = "الرمز السري غير صحيح (محاولة {$deployer_attempts}/5)";
                    }

                    // معلومات تشخيصية (بدون كشف السر!)
                    $deployer_auth_debug[] = 'مصدر الرمز: ' . $deployer_secret_source;
                    $deployer_auth_debug[] = 'فك التشفير: ' . ($deployer_decryption_ok ? 'نعم' : 'فشل — القيمة لا تزال مشفرة!');
                    $deployer_auth_debug[] = 'طول السر: ' . strlen($auth_secret) . ' حرف';
                    $deployer_auth_debug[] = 'يبدأ بـ ENC: ' . (str_starts_with($auth_secret, 'ENC:') ? 'نعم (مشكلة!)' : 'لا');
                    $deployer_auth_debug[] = 'openssl متاح: ' . (function_exists('openssl_decrypt') ? 'نعم' : 'لا');

                    // تسجيل في ملف السجل
                    $logDir = __DIR__ . '/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    $logEntry = sprintf(
                        "[%s] [DEPLOYER-AUTH] محاولة فاشلة | IP: %s | المصدر: %s | فك التشفير: %s | طول السر: %d | ENC: %s\n",
                        date('Y-m-d H:i:s'),
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $deployer_secret_source,
                        $deployer_decryption_ok ? 'OK' : 'FAILED',
                        strlen($auth_secret),
                        str_starts_with($auth_secret, 'ENC:') ? 'YES' : 'NO'
                    );
                    @file_put_contents($logDir . '/security.log', $logEntry, FILE_APPEND);
                }
            }
        }
    }

    // تسجيل محاولات الوصول
    if (!$deployer_authenticated) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logEntry = sprintf("[%s] [DEPLOYER-AUTH] وصول مرفوض من IP: %s\n", date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        @file_put_contents($logDir . '/security.log', $logEntry, FILE_APPEND);
    }
}

// معالجة تسجيل الخروج — إعادة توجيه لإزالة ?logout=1 من الرابط
// مهم: بدون إعادة التوجيه، يبقى ?logout=1 في الرابط وعند إرسال نموذج
// تسجيل الدخول (action="") يتم تسجيل الخروج فوراً بعد تسجيل الدخول!
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    unset($_SESSION['deployer_auth']);
    $_SESSION['deployer_attempts'] = 0;
    $_SESSION['deployer_blocked_until'] = 0;
    // إعادة توجيه لنفس الصفحة بدون المعلمة
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    exit;
}

// ====================================================================
//     إعادة تعيين الرمز السري — عند نسيان الرمز أو فشل فك التشفير
//     يتطلب التأكيد مرتين + حذف القيمة المشفرة من .env واستبدالها بنص عادي
// ====================================================================
$deployer_reset_result = '';
$deployer_reset_result_type = '';

if (isset($_POST['action']) && $_POST['action'] === 'reset_secret') {
    $new_reset_secret = trim($_POST['new_reset_secret'] ?? '');
    $confirm_reset_secret = trim($_POST['confirm_reset_secret'] ?? '');

    if (empty($new_reset_secret) || empty($confirm_reset_secret)) {
        $deployer_reset_result = 'جميع الحقول مطلوبة';
        $deployer_reset_result_type = 'error';
    } elseif (strlen($new_reset_secret) < 8) {
        $deployer_reset_result = 'الرمز السري الجديد يجب أن يكون 8 أحرف على الأقل';
        $deployer_reset_result_type = 'error';
    } elseif ($new_reset_secret !== $confirm_reset_secret) {
        $deployer_reset_result = 'الرمز السري الجديد وتأكيده غير متطابقين';
        $deployer_reset_result_type = 'error';
    } else {
        // تحديث ملف .env — كتابة الرمز الجديد كنص عادي
        $envFile = $baseDir . '/.env';
        if (file_exists($envFile) && is_writable($envFile)) {
            $content = file_get_contents($envFile);
            if ($content !== false) {
                // استبدال DEPLOYER_SECRET بنص عادي
                if (preg_match('/^DEPLOYER_SECRET\s*=\s*.+$/m', $content)) {
                    $newContent = preg_replace(
                        '/^DEPLOYER_SECRET\s*=\s*.+$/m',
                        'DEPLOYER_SECRET=' . $new_reset_secret,
                        $content
                    );
                } else {
                    $newContent = $content . "\nDEPLOYER_SECRET=" . $new_reset_secret . "\n";
                }
                $newContent = str_replace("\r\n", "\n", $newContent);
                $result = file_put_contents($envFile, $newContent, LOCK_EX);
                if ($result !== false) {
                    // تحديث المتغيرات في الذاكرة
                    $deployer_secret = $new_reset_secret;
                    $deployer_secret_source = 'env_file';
                    $deployer_decryption_ok = true;
                    $deployer_uses_default_secret = false;
                    $_SESSION['deployer_auth'] = hash('sha256', $deployer_secret);
                    $_SESSION['deployer_attempts'] = 0;
                    $_SESSION['deployer_blocked_until'] = 0;
                    $deployer_authenticated = true;
                    $deployer_reset_result = 'تم إعادة تعيين الرمز السري بنجاح! تم تسجيل الدخول تلقائياً.';
                    $deployer_reset_result_type = 'success';
                } else {
                    $deployer_reset_result = 'فشل كتابة ملف .env — تحقق من صلاحيات الملف';
                    $deployer_reset_result_type = 'error';
                }
            } else {
                $deployer_reset_result = 'فشل قراءة ملف .env';
                $deployer_reset_result_type = 'error';
            }
        } else {
            $deployer_reset_result = 'ملف .env غير موجود أو غير قابل للكتابة';
            $deployer_reset_result_type = 'error';
        }
    }
}

// ====================================================================
//                الدوال المساعدة (مضمنة — لا تعتمد على أي ملف خارجي)
// ====================================================================

/**
 * مقارنة إصدارات SemVer — تُستخدم لعرض تحذير عدم تطابق الإصدارات
 *
 * @param string $v1 الإصدار الأول (مثال: '4.26.0')
 * @param string $v2 الإصدار الثاني
 * @return int 1 إذا v1 > v2، -1 إذا v1 < v2، 0 إذا متساويان
 * @since v1.3.1 — ضرورية لعرض تحذير عدم تطابق الإصدارات في لوحة الناشر
 */
function deployer_compareVersions(string $v1, string $v2): int {
    $p1 = array_map('intval', explode('.', $v1));
    $p2 = array_map('intval', explode('.', $v2));
    for ($i = 0; max(count($p1), count($p2)) > $i; $i++) {
        $a = $p1[$i] ?? 0;
        $b = $p2[$i] ?? 0;
        if ($a !== $b) return $a < $b ? -1 : 1;
    }
    return 0;
}

/**
 * تنسيق حجم الملف
 */
function deployer_formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/**
 * حذف مجلد بشكل متكرر
 */
function deployer_removeDir($dir) {
    if (!is_dir($dir)) return false;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    return @rmdir($dir);
}

/**
 * تفعيل وضع الصيانة — يمنع جميع الطلبات باستثناء deployer.php و upgrade.php
 */
function deployer_enableMaintenance($baseDir) {
    $content = "<?php\n"
        . "// وضع الصيانة — تم تفعيله تلقائياً أثناء النشر الذري\n"
        . "// سيتم إزالة هذا الملف تلقائياً بعد انتهاء النشر\n"
        . "\$self = basename(\$_SERVER['SCRIPT_NAME'] ?? '');\n"
        . "if (\$self === 'deployer.php' || \$self === 'upgrade.php') return;\n"
        . "http_response_code(503);\n"
        . "header('Retry-After: 60');\n"
        . "header('Content-Type: text/html; charset=utf-8');\n"
        . "echo '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>صيانة</title></head>'\n"
        . "   . '<body style=\"display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;background:#f1f5f9;\">'\n"
        . "   . '<div style=\"text-align:center;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);\">'\n"
        . "   . '<h1 style=\"font-size:2rem;margin:0 0 .5rem;\">صيانة مؤقتة</h1>'\n"
        . "   . '<p style=\"color:#64748b;font-size:1.1rem;\">جاري تحديث النظام — يرجى الانتظار لحظات...</p>'\n"
        . "   . '</div></body></html>';\n"
        . "exit;\n";
    return file_put_contents($baseDir . '/.maintenance', $content) !== false;
}

/**
 * إلغاء وضع الصيانة
 */
function deployer_disableMaintenance($baseDir) {
    $file = $baseDir . '/.maintenance';
    if (file_exists($file)) {
        return @unlink($file);
    }
    return true;
}

/**
 * فحص مسبق — التحقق من صلاحيات الكتابة ومساحة القرص ومكتبة ZipArchive
 */
function deployer_preflight($baseDir, $zipSize = 0) {
    $errors = [];

    // 1. صلاحية الكتابة على المجلد الجذري
    if (!is_writable($baseDir)) {
        $errors[] = 'المجلد الجذري غير قابل للكتابة: ' . $baseDir;
    }

    // 2. مساحة القرص — نحتاج على الأقل 3 أضعاف حجم ZIP + 10MB
    $freeSpace = @disk_free_space($baseDir);
    $requiredSpace = $zipSize * 3 + 10 * 1024 * 1024;
    if ($freeSpace !== false && $freeSpace < $requiredSpace) {
        $errors[] = 'مساحة القرص غير كافية: متاح ' . deployer_formatSize($freeSpace)
            . ' — مطلوب على الأقل ' . deployer_formatSize($requiredSpace);
    }

    // 3. دالة rename متاحة
    if (!function_exists('rename')) {
        $errors[] = 'دالة rename() غير متاحة — لا يمكن النشر الذري';
    }

    // 4. اختبار عملي لـ rename على نفس نظام الملفات
    $testFile = $baseDir . '/.atomic_test_' . uniqid();
    $testFile2 = $baseDir . '/.atomic_test2_' . uniqid();
    if (@file_put_contents($testFile, 'test') !== false) {
        if (!@rename($testFile, $testFile2)) {
            $errors[] = 'فشل اختبار rename() — قد تكون هناك قيود على نظام الملفات';
            @unlink($testFile);
        } else {
            @unlink($testFile2);
        }
    } else {
        $errors[] = 'فشل إنشاء ملف اختبار — المجلد غير قابل للكتابة';
    }

    // 5. ZipArchive متاح
    if (!class_exists('ZipArchive')) {
        $errors[] = 'مكتبة ZipArchive غير متاحة — لا يمكن فك ضغط ملف ZIP';
    }

    // 6. صلاحية الكتابة على المجلدات الفرعية المهمة
    $criticalDirs = ['includes', 'config', 'assets'];
    foreach ($criticalDirs as $dir) {
        $fullPath = $baseDir . '/' . $dir;
        if (is_dir($fullPath) && !is_writable($fullPath)) {
            $errors[] = "المجلد {$dir}/ غير قابل للكتابة";
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * اكتشاف المجلد الجذري في ملف ZIP
 *
 * كثير من ملفات ZIP تحتوي على مجلد جذر واحد:
 *   is-app/file1.php, is-app/includes/...
 * بدلاً من:
 *   file1.php, includes/...
 */
function deployer_detectZipRoot($zip) {
    $topLevelDirs = [];
    $topLevelFiles = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', $zip->getNameIndex($i));

        // تخطي ملفات macOS المخفية
        if (strpos($name, '__MACOSX') === 0) continue;
        if (strpos(basename($name), '._') === 0) continue;

        $slashPos = strpos($name, '/');
        if ($slashPos === false) {
            $topLevelFiles++;
        } else {
            $topDir = substr($name, 0, $slashPos);
            if (!empty($topDir)) {
                $topLevelDirs[$topDir] = true;
            }
        }
    }

    // إذا كان هناك مجلد واحد فقط في المستوى الأعلى ولا توجد ملفات أخرى
    if (count($topLevelDirs) === 1 && $topLevelFiles === 0) {
        return array_key_first($topLevelDirs) . '/';
    }

    return '';
}

/**
 * نشر ذري حقيقي للملفات من ملف ZIP (Write-Then-Rename)
 *
 * الآلية:
 *  1. فحص مسبق (صلاحيات + مساحة + مكتبات)
 *  2. تفعيل وضع الصيانة
 *  3. فك ضغط ZIP في staging
 *  4. اكتشاف مجلد ZIP الجذري + تحقق أمني
 *  5. نسخ احتياطي للملفات الحالية (للتراجع)
 *  6. استبدال ذري لكل ملف: write(tmp) → rename(tmp, target)
 *  7. إزالة وضع الصيانة
 *  8. حفظ سجل النشر + تنظيف staging
 */
function deployer_atomicDeploy($zipPath, $baseDir) {
    $steps = [];
    $log = function($msg, $ok = true) use (&$steps) {
        $steps[] = ['msg' => $msg, 'ok' => $ok];
    };

    $stagingDir = $baseDir . '/upgrade_staging';
    $backupDir = $baseDir . '/upgrade_file_backup';
    $maintenanceActive = false;
    $deployedFiles = [];
    $backupFiles = [];
    $newDirs = [];
    $tmpFiles = [];

    try {
        // ===== 1. فحص مسبق =====
        $zipSize = file_exists($zipPath) ? filesize($zipPath) : 0;
        $preflight = deployer_preflight($baseDir, $zipSize);
        if (!$preflight['ok']) {
            foreach ($preflight['errors'] as $err) {
                $log('فحص مسبق: ' . $err, false);
            }
            return ['success' => false, 'error' => 'فشل الفحص المسبق — ' . implode('; ', $preflight['errors']), 'files_deployed' => 0, 'steps' => $steps];
        }
        $log('نجح الفحص المسبق (صلاحيات + مساحة + مكتبات)');

        // ===== 2. تفعيل وضع الصيانة =====
        if (!deployer_enableMaintenance($baseDir)) {
            $log('تحذير: فشل تفعيل وضع الصيانة — المتابعة بدون صيانة', false);
        } else {
            $maintenanceActive = true;
            $log('تم تفعيل وضع الصيانة — الطلبات محجوبة مؤقتاً');
        }

        // ===== 3. التحقق من ملف ZIP =====
        if (!file_exists($zipPath)) {
            deployer_disableMaintenance($baseDir);
            return ['success' => false, 'error' => 'ملف ZIP غير موجود', 'files_deployed' => 0, 'steps' => $steps];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            deployer_disableMaintenance($baseDir);
            return ['success' => false, 'error' => 'فشل فتح ملف ZIP — قد يكون تالفاً', 'files_deployed' => 0, 'steps' => $steps];
        }

        $fileCount = $zip->numFiles;
        $log("تم فتح ملف ZIP ({$fileCount} إدخال)");

        // ===== 4. اكتشاف المجلد الجذري في ZIP =====
        $rootPrefix = deployer_detectZipRoot($zip);
        if (!empty($rootPrefix)) {
            $log('تم اكتشاف مجلد جذري في ZIP: ' . rtrim($rootPrefix, '/') . ' — سيتم تجاهله تلقائياً');
        }

        // ===== 5. جمع قائمة الملفات من ZIP =====
        $stagedFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));

            // تجاهل ملفات macOS المخفية
            if (strpos($name, '__MACOSX') === 0) continue;
            if (strpos(basename($name), '._') === 0) continue;

            // إزالة المجلد الجذري إن وُجد
            if (!empty($rootPrefix)) {
                if (strpos($name, $rootPrefix) !== 0) continue;
                $name = substr($name, strlen($rootPrefix));
            }

            if (empty($name)) continue;

            // المجلدات
            if (substr($name, -1) === '/') {
                $newDirs[] = $name;
                continue;
            }

            // التحقق الأمني: رفض مسارات خطرة (Path Traversal + Zip Slip)
            if (strpos($name, '..') !== false) {
                $zip->close();
                deployer_removeDir($stagingDir);
                deployer_disableMaintenance($baseDir);
                return ['success' => false, 'error' => "مسار خطر في ZIP: {$name} (يحتوي على ..) — هجوم Path Traversal محتمل", 'files_deployed' => 0, 'steps' => $steps];
            }
            if (strpos($name, '/') === 0 || preg_match('#^[A-Za-z]:#', $name)) {
                $zip->close();
                deployer_removeDir($stagingDir);
                deployer_disableMaintenance($baseDir);
                return ['success' => false, 'error' => "مسار مطلق في ZIP: {$name} — مرفوض لأسباب أمنية", 'files_deployed' => 0, 'steps' => $steps];
            }

            // حماية: عدم استبدال الملفات الحرجة
            if ($name === '.htaccess' || (basename($name) === '.htaccess' && dirname($name) === '.')) {
                $log('تجاوز .htaccess الجذري (حماية الإعدادات الأمنية الحالية)', false);
                continue;
            }
            if ($name === 'config/database.php') {
                $log('تجاوز config/database.php (حماية إعدادات الاتصال)', false);
                continue;
            }
            if ($name === '.maintenance') {
                continue;
            }
            // تجاوز deployer.php نفسه — لا نستبدل الملف أثناء تنفيذه!
            if ($name === 'deployer.php') {
                $log('تجاوز deployer.php (حماية الملف أثناء التنفيذ)', false);
                continue;
            }

            $stagedFiles[] = $name;
        }

        $zip->close();

        if (empty($stagedFiles)) {
            deployer_removeDir($stagingDir);
            deployer_disableMaintenance($baseDir);
            return ['success' => false, 'error' => 'ملف ZIP فارغ أو لا يحتوي على ملفات صالحة للنشر', 'files_deployed' => 0, 'steps' => $steps];
        }

        $log('عدد الملفات الجديدة/المحدّثة: ' . count($stagedFiles) . ' + ' . count($newDirs) . ' مجلد');

        // ===== 6. فك الضغط إلى مجلد staging =====
        if (is_dir($stagingDir)) {
            deployer_removeDir($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        // حماية مجلد staging
        file_put_contents($stagingDir . '/.htaccess', "Deny from all\n");

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($stagingDir);
        $zip->close();
        $log('تم فك الضغط في مجلد staging');

        // ===== 7. نسخ احتياطي للملفات الحالية التي ستُستبدل =====
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
            file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
        }

        $backedUp = 0;
        foreach ($stagedFiles as $relPath) {
            $targetPath = $baseDir . '/' . $relPath;
            if (file_exists($targetPath)) {
                $backupPath = $backupDir . '/' . $relPath;
                $backupDirPath = dirname($backupPath);
                if (!is_dir($backupDirPath)) {
                    mkdir($backupDirPath, 0755, true);
                }
                if (@copy($targetPath, $backupPath)) {
                    $backupFiles[] = $relPath;
                    $backedUp++;
                } else {
                    $log("تحذير: فشل نسخ احتياطي لـ {$relPath}", false);
                }
            }
        }
        $log("نسخ احتياطي: {$backedUp} ملف حالي سيُستبدل");

        // ===== 8. إنشاء المجلدات الجديدة =====
        $createdDirs = 0;
        foreach ($newDirs as $dirPath) {
            $fullDirPath = $baseDir . '/' . $dirPath;
            if (!is_dir($fullDirPath)) {
                if (@mkdir($fullDirPath, 0755, true)) {
                    $createdDirs++;
                } else {
                    $log("تحذير: فشل إنشاء المجلد {$dirPath}", false);
                }
            }
        }
        if ($createdDirs > 0) {
            $log("تم إنشاء {$createdDirs} مجلد جديد");
        }

        // ===== 9. النشر الذري — Write-Then-Rename =====
        $deployed = 0;
        $failed = 0;

        $log('بدء النشر الذري (Write-Then-Rename)...');

        foreach ($stagedFiles as $relPath) {
            // تحديد مسار الملف المصدر في staging
            if (!empty($rootPrefix)) {
                $sourcePath = $stagingDir . '/' . $rootPrefix . $relPath;
            } else {
                $sourcePath = $stagingDir . '/' . $relPath;
            }

            $targetPath = $baseDir . '/' . $relPath;
            $targetDir = dirname($targetPath);

            // التأكد من وجود المجلد الهدف
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            // التحقق من وجود الملف المصدر
            if (!file_exists($sourcePath)) {
                $log("تجاوز: {$relPath} — غير موجود في staging", false);
                $failed++;
                continue;
            }

            // === Write-Then-Rename: النمط الذري ===
            // 1. كتابة ملف مؤقت في نفس المجلد (نفس نظام الملفات = rename ذري)
            $tmpFile = $targetDir . '/.deploy_' . uniqid() . '.tmp';
            $tmpFiles[] = $tmpFile;

            if (@copy($sourcePath, $tmpFile) === false) {
                $log("فشل نسخ: {$relPath} — تخطي", false);
                $failed++;
                @unlink($tmpFile);
                continue;
            }

            // 2. تعيين نفس صلاحيات الملف الأصلي (إن وُجد)
            if (file_exists($targetPath)) {
                $originalPerms = @fileperms($targetPath);
                if ($originalPerms !== false) {
                    @chmod($tmpFile, $originalPerms & 0777);
                }
            }

            // 3. rename() — ذرية على نفس نظام الملفات (POSIX guarantee)
            if (@rename($tmpFile, $targetPath)) {
                $deployed++;
                $tmpFiles = array_diff($tmpFiles, [$tmpFile]);
            } else {
                $log("فشل استبدال: {$relPath}", false);
                $failed++;
                @unlink($tmpFile);
                $tmpFiles = array_diff($tmpFiles, [$tmpFile]);

                // محاولة بديلة: copy مباشر (ليس ذري لكن يعمل على أنظمة ملفات مختلفة)
                if (@copy($sourcePath, $targetPath)) {
                    $deployed++;
                    $failed--;
                    $log("تم نسخ {$relPath} (بدون ذرية — أنظمة ملفات مختلفة)", false);
                }
            }
        }

        $log("تم نشر {$deployed} ملف ذرياً" . ($failed > 0 ? " ({$failed} فشل)" : ''));

        // ===== 10. إزالة وضع الصيانة =====
        deployer_disableMaintenance($baseDir);
        $maintenanceActive = false;
        $log('تم إزالة وضع الصيانة — التطبيق متاح');

        // ===== 11. حفظ سجل النشر =====
        $deployManifest = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => 'atomic_write_then_rename',
            'deployer_version' => DEPLOYER_VERSION,
            'zip_root_prefix' => $rootPrefix,
            'files' => $stagedFiles,
            'backup_files' => $backupFiles,
            'new_dirs' => $newDirs,
            'deployed' => $deployed,
            'failed' => $failed,
            'zip_file' => basename($zipPath),
        ];
        file_put_contents($backupDir . '/deploy_manifest.json', json_encode($deployManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $log('تم حفظ سجل النشر');

        // ===== 12. تنظيف مجلد staging =====
        deployer_removeDir($stagingDir);
        $log('تم تنظيف مجلد staging');

        // ===== النتيجة النهائية =====
        if ($failed > 0 && $deployed === 0) {
            return [
                'success' => false,
                'error' => "فشل نشر جميع الملفات ({$failed} فشل). تم إزالة وضع الصيانة.",
                'files_deployed' => 0,
                'steps' => $steps,
            ];
        }

        return [
            'success' => true,
            'error' => $failed > 0 ? "تنبيه: {$failed} ملف فشل في النشر" : '',
            'files_deployed' => $deployed,
            'steps' => $steps,
            'deployed_files' => $stagedFiles,
            'backup_files' => $backupFiles,
        ];

    } catch (Throwable $e) {
        // ===== تنظيف طوارئ =====
        foreach ($tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
        if ($maintenanceActive) {
            @deployer_disableMaintenance($baseDir);
        }
        if (is_dir($stagingDir)) {
            @deployer_removeDir($stagingDir);
        }
        // لا نكشف تفاصيل الاستثناء الداخلية للمستخدم
        $errorMsg = 'حدث خطأ غير متوقع أثناء النشر. يرجى مراجعة سجلات الأخطاء.';
        // في وضع التطوير فقط، نكشف التفاصيل
        if (defined('APP_DEBUG') && APP_DEBUG === true) {
            $errorMsg = 'استثناء: ' . $e->getMessage();
        }
        return ['success' => false, 'error' => $errorMsg, 'files_deployed' => 0, 'steps' => $steps];
    }
}

/**
 * تراجع عن نشر الملفات — استعادة الملفات من النسخة الاحتياطية
 */
function deployer_rollback($baseDir) {
    $backupDir = $baseDir . '/upgrade_file_backup';
    $manifestFile = $backupDir . '/deploy_manifest.json';

    if (!file_exists($manifestFile)) {
        return ['success' => false, 'error' => 'لا يوجد سجل نشر للتراجع عنه', 'restored_count' => 0];
    }

    $manifest = json_decode(file_get_contents($manifestFile), true);
    if (!$manifest || empty($manifest['files'])) {
        return ['success' => false, 'error' => 'سجل النشر تالف أو فارغ', 'restored_count' => 0];
    }

    // تفعيل وضع الصيانة أثناء التراجع
    deployer_enableMaintenance($baseDir);

    $restoredCount = 0;
    $failedCount = 0;
    $deletedCount = 0;

    // 1. استعادة الملفات المنسوخة احتياطياً
    if (!empty($manifest['backup_files'])) {
        foreach ($manifest['backup_files'] as $relPath) {
            $backupPath = $backupDir . '/' . $relPath;
            $targetPath = $baseDir . '/' . $relPath;

            if (file_exists($backupPath)) {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (@copy($backupPath, $targetPath)) {
                    $restoredCount++;
                } else {
                    $failedCount++;
                }
            }
        }
    }

    // 2. حذف الملفات الجديدة التي أُضيفت بواسطة النشر
    if (!empty($manifest['files'])) {
        foreach ($manifest['files'] as $relPath) {
            if (!in_array($relPath, $manifest['backup_files'] ?? [])) {
                $targetPath = $baseDir . '/' . $relPath;
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                    $deletedCount++;
                }
            }
        }
    }

    // 3. حذف المجلدات الجديدة (بترتيب عكسي — الأعمق أولاً)
    if (!empty($manifest['new_dirs'])) {
        $dirs = $manifest['new_dirs'];
        usort($dirs, function($a, $b) {
            return substr_count($b, '/') - substr_count($a, '/');
        });
        foreach ($dirs as $dirPath) {
            $fullDirPath = $baseDir . '/' . $dirPath;
            if (is_dir($fullDirPath)) {
                @rmdir($fullDirPath);
            }
        }
    }

    // 4. تنظيف مجلد النسخ الاحتياطي
    if (is_dir($backupDir)) {
        foreach ($manifest['backup_files'] ?? [] as $relPath) {
            @unlink($backupDir . '/' . $relPath);
        }
    }
    @unlink($manifestFile);

    // إزالة وضع الصيانة
    deployer_disableMaintenance($baseDir);

    return [
        'success' => $failedCount === 0,
        'error' => $failedCount > 0 ? "فشل استعادة {$failedCount} ملف" : '',
        'restored_count' => $restoredCount,
        'deleted_count' => $deletedCount,
    ];
}

/**
 * تشغيل ترقية قاعدة البيانات مباشرة من الناشر (استعادة طارئة)
 *
 * يحمل وحدات الترقية وينفذ التحديثات المعلقة فقط.
 * هذا يحل مشكلة "فشل الترقية + فشل التراجع" لأن الناشر مستقل دائماً.
 *
 * @param string $baseDir المسار الجذري للتطبيق
 * @return array نتائج الترقية
 */
function deployer_emergencyUpgrade($baseDir) {
    $steps = [];
    $log = function($msg, $ok = true) use (&$steps) {
        $steps[] = ['msg' => $msg, 'ok' => $ok];
    };

    // 1. تحميل وحدات الترقية
    $upgradeDir = $baseDir . '/includes/upgrades';
    $constantsFile = $upgradeDir . '/upgrade-constants.php';
    $helpersFile = $upgradeDir . '/upgrade-helpers.php';
    $dbFile = $upgradeDir . '/upgrade-db.php';
    $rollbackFile = $upgradeDir . '/upgrade-rollback.php';

    if (!file_exists($constantsFile)) {
        return ['success' => false, 'error' => 'ملف ثوابت الترقية غير موجود: ' . $constantsFile, 'steps' => $steps];
    }
    if (!file_exists($helpersFile)) {
        return ['success' => false, 'error' => 'ملف دوال الترقية المساعدة غير موجود', 'steps' => $steps];
    }

    // ملاحظة مهمة: يجب تعريف المتغيرات كـ global قبل require_once
    // لأن الملفات المطلوبة تعرّف متغيرات (مثل $DB_UPDATES) تدخل نطاق الدالة المحلي
    // وبدون global لن تكون متاحة عند استدعاء global لاحقاً
    global $DB_UPDATES, $_ensureTable_errors;

    require_once $constantsFile;
    require_once $helpersFile;
    if (file_exists($dbFile)) require_once $dbFile;
    if (file_exists($rollbackFile)) require_once $rollbackFile;

    // تحميل ErrorHandler إن وُجد — ضروري لمعالجة الاستثناءات بأمان
    $errorHandlerFile = $baseDir . '/includes/ErrorHandler.php';
    if (file_exists($errorHandlerFile)) {
        require_once $errorHandlerFile;
    }

    $log('تم تحميل وحدات الترقية');

    // 2. الاتصال بقاعدة البيانات
    $dbConfigFile = $baseDir . '/config/database.php';
    if (!file_exists($dbConfigFile)) {
        return ['success' => false, 'error' => 'ملف إعدادات قاعدة البيانات غير موجود', 'steps' => $steps];
    }

    require_once $dbConfigFile;
    if (!function_exists('getDBConnection')) {
        return ['success' => false, 'error' => 'دالة getDBConnection غير متاحة', 'steps' => $steps];
    }

    try {
        $pdo = getDBConnection();
        if (!$pdo) {
            return ['success' => false, 'error' => 'فشل الاتصال بقاعدة البيانات', 'steps' => $steps];
        }
    } catch (Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'DeployerDBConnection');
            $errMsg = ErrorHandler::safeExceptionMessage($e, 'خطأ اتصال بقاعدة البيانات.');
        } else {
            $errMsg = 'خطأ اتصال بقاعدة البيانات: ' . $e->getMessage();
        }
        return ['success' => false, 'error' => $errMsg, 'steps' => $steps];
    }

    $log('تم الاتصال بقاعدة البيانات');

    // 3. قراءة الإصدار الحالي
    $currentVersion = getCurrentDBVersion($pdo);
    $appVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
    $log("إصدار قاعدة البيانات الحالي: v{$currentVersion}");
    $log("إصدار التطبيق (الملفات): v{$appVersion}");

    if (deployer_compareVersions($appVersion, $currentVersion) <= 0) {
        // قاعدة البيانات محدّثة بالفعل أو أحدث من الملفات
        if (deployer_compareVersions($appVersion, $currentVersion) < 0) {
            // قاعدة البيانات أحدث من الملفات — لا يمكن الترقية للخلف
            $log("تحذير: قاعدة البيانات (v{$currentVersion}) أحدث من الملفات (v{$appVersion})");
            $log("يجب نشر ملفات الإصدار v{$currentVersion} لتطابق الإصدارات");
            return ['success' => false, 'error' => 'قاعدة البيانات (v' . $currentVersion . ') أحدث من الملفات (v' . $appVersion . '). يجب نشر ملفات أحدث عبر ZIP لتطابق الإصدارات. لا يمكن ترقية قاعدة البيانات للخلف.', 'steps' => $steps, 'already_updated' => false,
                    'db_version' => $currentVersion, 'app_version' => $appVersion,
                    'message' => 'قاعدة البيانات أحدث من الملفات — يجب نشر ملفات أحدث'];
        }
        return ['success' => true, 'error' => '', 'steps' => $steps, 'already_updated' => true,
                'db_version' => $currentVersion, 'app_version' => $appVersion,
                'message' => 'قاعدة البيانات محدّثة بالفعل (v' . $currentVersion . ')'];
    }

    // 4. إنشاء نسخة احتياطية تلقائية قبل الترقية
    $backupResult = ['success' => false, 'file' => ''];
    if (function_exists('createUpgradeBackup')) {
        $backupResult = createUpgradeBackup($pdo, $currentVersion);
        if ($backupResult['success']) {
            $log('تم إنشاء نسخة احتياطية تلقائية: ' . basename($backupResult['file']));
        } else {
            $log('تحذير: فشل إنشاء نسخة احتياطية — ' . $backupResult['error'], false);
        }
    }

    // 5. تنفيذ التحديثات المعلقة
    // $DB_UPDATES تم تعريفه كـ global أعلاه عند تحميل الملفات
    $pending = [];
    if (!is_array($DB_UPDATES) || empty($DB_UPDATES)) {
        return ['success' => false, 'error' => 'مصفوفة التحديثات ($DB_UPDATES) غير متوفرة — تأكد من تحميل upgrade-constants.php بشكل صحيح', 'steps' => $steps];
    }
    foreach ($DB_UPDATES as $u) {
        if (deployer_compareVersions($u['version'], $currentVersion) > 0) {
            $pending[] = $u;
        }
    }

    $log('عدد التحديثات المعلقة: ' . count($pending));

    $upgradeFailed = false;
    $failedVersion = '';
    $successCount = 0;

    foreach ($pending as $u) {
        $func = 'db_upgrade_' . str_replace('.', '_', $u['version']);
        try {
            // معالجة مرنة: تخطي الدوال المفقودة بدلاً من الفشل
            if (!function_exists($func)) {
                $log("v{$u['version']}: دالة {$func} غير موجودة — تم التخطي (لا تغييرات مطلوبة في قاعدة البيانات)", false);
                updateDBVersion($pdo, $u['version']);
                $currentVersion = $u['version'];
                $successCount++;
                continue;
            }

            global $_ensureTable_errors;
            $_ensureTable_errors = [];
            call_user_func($func, $pdo);
            $tableErrors = getEnsureTableErrors();
            updateDBVersion($pdo, $u['version']);
            $currentVersion = $u['version'];
            $successCount++;

            $detail = '';
            if (!empty($tableErrors)) {
                $detail = ' (تحذيرات: ' . count($tableErrors) . ')';
            }
            $log("v{$u['version']}: {$u['desc']}{$detail}");

        } catch (Throwable $e) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logException($e, "DeployerUpgrade_v{$u['version']}");
                $logMsg = ErrorHandler::safeExceptionMessage($e, 'فشل الترقية.');
            } else {
                $logMsg = 'فشل الترقية: ' . $e->getMessage();
            }
            $log("v{$u['version']}: فشل — " . $logMsg, false);
            $upgradeFailed = true;
            $failedVersion = $u['version'];

            // محاولة التراجع التلقائي
            if ($backupResult['success'] && function_exists('rollbackFromBackup')) {
                $log('جاري التراجع التلقائي...');
                $rollbackResult = rollbackFromBackup($pdo, $backupResult['file']);
                if ($rollbackResult['success']) {
                    $log('تم التراجع تلقائياً إلى v' . $currentVersion);
                } else {
                    $log('فشل التراجع التلقائي: ' . $rollbackResult['error'], false);
                }
            }
            break;
        }
    }

    // 6. تحديث الإصدار النهائي
    if (!$upgradeFailed) {
        updateDBVersion($pdo, $appVersion);
        $currentVersion = $appVersion;
        $log("تم التحديث بنجاح إلى v{$appVersion}! ({$successCount} عملية)");
    }

    return [
        'success' => !$upgradeFailed,
        'error' => $upgradeFailed ? 'فشلت الترقية عند v' . $failedVersion : '',
        'steps' => $steps,
        'db_version' => $currentVersion,
        'app_version' => $appVersion,
        'success_count' => $successCount,
    ];
}

/**
 * الحصول على آخر سجل نشر (إن وُجد)
 */
function deployer_getLastManifest($baseDir) {
    $manifestFile = $baseDir . '/upgrade_file_backup/deploy_manifest.json';
    if (!file_exists($manifestFile)) return null;
    $content = file_get_contents($manifestFile);
    if ($content === false) return null;
    return json_decode($content, true);
}

/**
 * تحديث الرمز السري في ملف .env
 *
 * يكتب القيمة الجديدة كنص عادي أو مشفرة (إن وُجد APP_ENCRYPTION_KEY)
 *
 * @param string $envPath مسار ملف .env
 * @param string $newSecret الرمز السري الجديد
 * @return array ['success' => bool, 'error' => string]
 */
function deployer_updateEnvSecret(string $envPath, string $newSecret): array {
    if (!file_exists($envPath)) {
        return ['success' => false, 'error' => 'ملف .env غير موجود'];
    }
    if (!is_writable($envPath)) {
        return ['success' => false, 'error' => 'ملف .env غير قابل للكتابة — تحقق من الصلاحيات'];
    }

    $content = file_get_contents($envPath);
    if ($content === false) {
        return ['success' => false, 'error' => 'فشل قراءة ملف .env'];
    }

    // محاولة التشفير إن وُجد APP_ENCRYPTION_KEY
    $valueToWrite = $newSecret;
    if (preg_match('/^APP_ENCRYPTION_KEY\s*=\s*(.+)$/m', $content, $km)) {
        $encKey = trim($km[1]);
        if (!empty($encKey)) {
            $encrypted = deployer_encryptEnvValue($newSecret, $encKey);
            if ($encrypted !== null) {
                $valueToWrite = $encrypted;
            }
            // إذا فشل التشفير، نكتب القيمة كنص عادي
        }
    }

    // استبدال أو إضافة DEPLOYER_SECRET
    if (preg_match('/^DEPLOYER_SECRET\s*=\s*.+$/m', $content)) {
        // استبدال القيمة الحالية
        $newContent = preg_replace(
            '/^DEPLOYER_SECRET\s*=\s*.+$/m',
            'DEPLOYER_SECRET=' . $valueToWrite,
            $content
        );
    } else {
        // إضافة سطر جديد بعد التعليقات
        $newContent = $content . "\nDEPLOYER_SECRET=" . $valueToWrite . "\n";
    }

    // توحيد نهايات الأسطر
    $newContent = str_replace("\r\n", "\n", $newContent);

    $result = file_put_contents($envPath, $newContent, LOCK_EX);
    if ($result === false) {
        return ['success' => false, 'error' => 'فشل كتابة ملف .env'];
    }

    return ['success' => true, 'error' => ''];
}

/**
 * تشفير قيمة بـ AES-256-GCM لكتابتها في ملف .env
 *
 * @param string $plainText النص الأصلي
 * @param string $keyBase64 مفتاح التشفير بصيغة base64
 * @return string|null القيمة المشفرة (ENC:base64...) أو null عند الفشل
 */
function deployer_encryptEnvValue(string $plainText, string $keyBase64): ?string {
    $key = base64_decode($keyBase64, true);
    if ($key === false || strlen($key) !== 32) {
        return null;
    }

    $iv = random_bytes(12); // 12 بايت لـ GCM
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($ciphertext === false) {
        return null;
    }

    return 'ENC:' . base64_encode($iv . $tag . $ciphertext);
}

// ====================================================================
//                معالجة الطلبات (POST)
// ====================================================================

// ===== فحص المصادقة قبل معالجة أي طلب =====
if ($requires_auth && !$deployer_authenticated) {
    // عرض نموذج المصادقة فقط — لا معالجة
    $action = '';
} else {
    $action = $_POST['action'] ?? '';
}

$message = '';
$msgType = '';
$deploySteps = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$requires_auth || $deployer_authenticated)) {

    if ($action === 'deploy_zip') {
        // === نشر ذري لملف ZIP ===
        if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'الملف أكبر من الحد المسموح في php.ini',
                UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد المسموح في النموذج',
                UPLOAD_ERR_PARTIAL => 'تم رفع جزء من الملف فقط',
                UPLOAD_ERR_NO_FILE => 'لم يتم رفع أي ملف',
                UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود',
                UPLOAD_ERR_CANT_WRITE => 'فشل كتابة الملف على القرص',
            ];
            $errCode = $_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $message = 'فشل رفع الملف: ' . ($uploadErrors[$errCode] ?? 'خطأ غير معروف');
            $msgType = 'error';
        } else {
            $tmpPath = $_FILES['zip_file']['tmp_name'];
            $originalName = $_FILES['zip_file']['name'];

            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
                $message = 'يجب رفع ملف ZIP فقط';
                $msgType = 'error';
            } else {
                $result = deployer_atomicDeploy($tmpPath, $baseDir);
                if ($result['success']) {
                    foreach ($result['steps'] as $step) {
                        $deploySteps[] = ['msg' => $step['msg'], 'ok' => $step['ok']];
                    }
                    $message = 'تم نشر ' . $result['files_deployed'] . ' ملف بنجاح من: ' . htmlspecialchars($originalName);
                    $msgType = 'success';
                } else {
                    foreach ($result['steps'] as $step) {
                        $deploySteps[] = ['msg' => $step['msg'], 'ok' => $step['ok']];
                    }
                    $message = 'فشل النشر: ' . $result['error'];
                    $msgType = 'error';
                }
            }
        }

    } elseif ($action === 'rollback_files') {
        // === تراجع عن نشر ملفات ===
        $result = deployer_rollback($baseDir);
        if ($result['success']) {
            $message = 'تم التراجع عن نشر الملفات — تم استعادة ' . $result['restored_count'] . ' ملف';
            $msgType = 'success';
        } else {
            $message = 'فشل التراجع: ' . $result['error'];
            $msgType = 'error';
        }

    } elseif ($action === 'emergency_upgrade') {
        // === استعادة طارئة — تشغيل ترقية قاعدة البيانات ===
        $result = deployer_emergencyUpgrade($baseDir);
        if (!empty($result['steps'])) {
            foreach ($result['steps'] as $step) {
                $deploySteps[] = ['msg' => $step['msg'], 'ok' => $step['ok']];
            }
        }
        if ($result['success']) {
            if (!empty($result['already_updated'])) {
                $message = $result['message'];
                $msgType = 'success';
            } else {
                $message = 'تم التحديث بنجاح! (' . ($result['success_count'] ?? 0) . ' عملية) — إصدار قاعدة البيانات الآن: v' . ($result['db_version'] ?? '?');
                $msgType = 'success';
            }
        } else {
            $message = 'فشل التحديث: ' . $result['error'];
            $msgType = 'error';
        }
    } elseif ($action === 'change_secret') {
        // === تغيير الرمز السري للناشر ===
        $current_secret_input = trim($_POST['current_secret'] ?? '');
        $new_secret_input = trim($_POST['new_secret'] ?? '');
        $confirm_secret_input = trim($_POST['confirm_secret'] ?? '');

        if (empty($current_secret_input) || empty($new_secret_input) || empty($confirm_secret_input)) {
            $message = 'جميع الحقول مطلوبة';
            $msgType = 'error';
        } elseif (hash('sha256', $current_secret_input) !== hash('sha256', $deployer_secret)) {
            $message = 'الرمز السري الحالي غير صحيح';
            $msgType = 'error';
        } elseif (strlen($new_secret_input) < 8) {
            $message = 'الرمز السري الجديد يجب أن يكون 8 أحرف على الأقل';
            $msgType = 'error';
        } elseif ($new_secret_input !== $confirm_secret_input) {
            $message = 'الرمز السري الجديد وتأكيده غير متطابقين';
            $msgType = 'error';
        } else {
            // تحديث ملف .env
            $envFile = $baseDir . '/.env';
            $updateResult = deployer_updateEnvSecret($envFile, $new_secret_input);
            if ($updateResult['success']) {
                // تحديث المتغير في الذاكرة
                $deployer_secret = $new_secret_input;
                $_SESSION['deployer_auth'] = hash('sha256', $deployer_secret);
                $deployer_uses_default_secret = false;
                $deployer_decryption_ok = true;
                $message = 'تم تغيير الرمز السري بنجاح! احفظ الرمز الجديد في مكان آمن.';
                $msgType = 'success';
            } else {
                $message = 'فشل تحديث ملف .env: ' . $updateResult['error'];
                $msgType = 'error';
            }
        }
    }
}

// ===== معلومات النظام =====
$lastManifest = deployer_getLastManifest($baseDir);
$maintenanceActive = file_exists($baseDir . '/.maintenance');
$diskFree = @disk_free_space($baseDir);
$phpVersion = PHP_VERSION;

// محاولة قراءة إصدار التطبيق الحالي
$appVersion = 'غير معروف';
$constantsFile = $baseDir . '/includes/upgrades/upgrade-constants.php';
if (file_exists($constantsFile)) {
    $content = file_get_contents($constantsFile);
    if (preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $content, $m)) {
        $appVersion = $m[1];
    }
}

// محاولة قراءة إصدار قاعدة البيانات
$dbVersion = 'غير متاح';
$dbFile = $baseDir . '/config/database.php';
if (file_exists($dbFile)) {
    try {
        // محاولة اتصال بسيطة
        require_once $dbFile;
        if (function_exists('getDBConnection')) {
            $pdo = getDBConnection();
            if ($pdo) {
                $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'app_version'");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) $dbVersion = $row['setting_value'];
                }
            }
        }
    } catch (Throwable $e) {
        // لا نمانع — المعلومات اختيارية
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الناشر الذري المستقل — Atomic Deployer</title>
    <style>
        .auth-form{max-width:400px;margin:0 auto;text-align:center}
        .auth-form input[type=password]{width:100%;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;font-size:1rem;margin:12px 0;box-sizing:border-box;direction:ltr;text-align:center}
        .auth-form input[type=password]:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.2)}
        .auth-error{color:#dc2626;font-size:.85rem;margin:8px 0;padding:8px;background:#fee2e2;border-radius:8px}
        .lock-icon{font-size:3rem;margin-bottom:10px}
        .auth-logout{display:inline-block;margin-top:12px;padding:6px 16px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:.85rem;cursor:pointer;text-decoration:none}
    </style>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:2rem 1rem;direction:rtl}
        .main{max-width:720px;width:100%}
        .card{background:#fff;border-radius:16px;padding:2rem;margin-bottom:1.5rem;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        h1{text-align:center;font-size:1.4rem;margin-bottom:.3rem;color:#1e293b}
        .sub{text-align:center;color:#64748b;margin-bottom:1.5rem;font-size:.85rem}
        .badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:.8rem;font-weight:600}
        .b-ok{background:#d1fae5;color:#065f46}.b-warn{background:#fef3c7;color:#92400e}.b-err{background:#fee2e2;color:#dc2626}.b-info{background:#dbeafe;color:#1e40af}
        .sec{margin-bottom:1.5rem}
        .stitle{display:flex;align-items:center;gap:8px;font-size:1rem;font-weight:700;color:#334155;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:2px solid #e2e8f0}
        .item{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;background:#f8fafc;font-size:.88rem}
        .item.ok{background:#d1fae5;border-color:#a7f3d0}.item.err{background:#fee2e2;border-color:#fecaca}
        .alert{padding:12px 16px;border-radius:8px;margin-bottom:1rem;font-size:.85rem}
        .a-ok{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
        .a-err{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
        .a-warn{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
        .a-info{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
        .btn{display:block;width:100%;padding:13px;border:none;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none}
        .btn-p{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff}
        .btn-p:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.4)}
        .btn-p:disabled{background:#94a3b8;cursor:not-allowed;transform:none;box-shadow:none}
        .btn-s{background:#059669;color:#fff;margin-top:8px}.btn-s:hover{background:#047857}
        .btn-d{background:#dc2626;color:#fff;margin-top:8px}.btn-d:hover{background:#b91c1c}
        .btn-w{background:#d97706;color:#fff;margin-top:8px}.btn-w:hover{background:#b45309}
        .scr{max-height:400px;overflow-y:auto}
        .srow{display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
        .sitem{flex:1;min-width:120px;background:#f8fafc;border-radius:10px;padding:1rem;text-align:center;border:1px solid #e2e8f0}
        .snum{font-size:1.3rem;font-weight:800}.slbl{font-size:.75rem;color:#64748b;margin-top:2px}
        .g{color:#059669}.r{color:#dc2626}.b{color:#2563eb}
        .info-box{margin-top:1.5rem;background:#f1f5f9;border-radius:10px;padding:1rem}
        .info-box h4{font-size:.9rem;color:#334155;margin-bottom:6px}
        .info-box p{font-size:.78rem;color:#64748b;line-height:1.7}
        .links{display:flex;gap:8px;margin-top:1rem;flex-wrap:wrap}
        .links a{padding:8px 16px;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;transition:all .2s}
        .link-upgrade{background:#dbeafe;color:#1d4ed8}.link-upgrade:hover{background:#bfdbfe}
        .link-app{background:#d1fae5;color:#065f46}.link-app:hover{background:#a7f3d0}
        @media(max-width:600px){.card{padding:1.25rem}.srow{flex-direction:column}.links{flex-direction:column}}
        /* ===== تحقق + تحميل + لغة ===== */
        .field-error{color:#dc2626;font-size:.78rem;margin-top:3px;display:none;direction:rtl}
        .field-error.visible{display:block}
        input.invalid,select.invalid{border-color:#dc2626!important;box-shadow:0 0 0 3px rgba(220,38,38,.15)!important}
        input.valid,select.valid{border-color:#059669!important}
        .deployer-loading{position:fixed;inset:0;background:rgba(15,23,42,.7);display:flex;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(2px)}
        .deployer-loading-box{background:#fff;border-radius:16px;padding:2rem 3rem;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);max-width:90vw}
        .deployer-spinner{width:48px;height:48px;border:5px solid #e2e8f0;border-top:5px solid #2563eb;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px}
        @keyframes spin{to{transform:rotate(360deg)}}
        .deployer-loading-text{font-size:1rem;color:#334155;font-weight:600}
        .lang-switcher{position:absolute;top:12px;left:12px;display:flex;gap:4px;z-index:10}
        .lang-btn{padding:4px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:.75rem;cursor:pointer;background:#fff;color:#334155;font-weight:600;transition:all .2s}
        .lang-btn:hover{border-color:#2563eb;color:#2563eb}
        .lang-btn.active{background:#2563eb;color:#fff;border-color:#2563eb}
    </style>
</head>
<body>
<!-- تراكب التحميل الموحد -->
<div id="deployer-loading" class="deployer-loading" style="display:none">
    <div class="deployer-loading-box">
        <div class="deployer-spinner"></div>
        <div class="deployer-loading-text" data-i18n="loading">جاري التنفيذ...</div>
    </div>
</div>

<div class="main">
    <div class="card" style="position:relative">
        <!-- مبدل اللغة -->
        <div class="lang-switcher">
            <button type="button" class="lang-btn active" data-lang="ar">عربي</button>
            <button type="button" class="lang-btn" data-lang="en">EN</button>
            <button type="button" class="lang-btn" data-lang="fr">FR</button>
        </div>
        <h1>🚀 الناشر الذري المستقل</h1>
        <p class="sub">Standalone Atomic Deployer v<?php echo DEPLOYER_VERSION; ?> — نشر ملفات التحديث من ZIP بشكل ذري (Write-Then-Rename)</p>

        <?php if ($requires_auth && !$deployer_authenticated): ?>
        <!-- ===== نموذج المصادقة ===== -->
        <div class="auth-form">
            <div class="lock-icon">🔒</div>
            <h2  class="mb-2 text-dark text-xl">محمي بالرمز السري</h2>
            <p style="font-size:.85rem" class="text-muted mb-4">هذه الصفحة محمية. أدخل الرمز السري للوصول إلى الناشر.</p>
            <?php if (!empty($deployer_auth_error)): ?>
            <div class="auth-error"><?php echo htmlspecialchars($deployer_auth_error); ?></div>
            <?php endif; ?>
            <?php if (!empty($deployer_auth_debug)): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin:8px 0;text-align:right;font-size:.8rem;color:#64748b;direction:rtl">
                <div style="font-weight:700;margin-bottom:4px;color:#334155">🔍 معلومات تشخيصية:</div>
                <?php foreach ($deployer_auth_debug as $dbg): ?>
                <div><?php echo $dbg; ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST" action="" id="login-form" novalidate>
                <input type="password" name="deployer_password" placeholder="أدخل الرمز السري" required autocomplete="off" minlength="1" data-i18n-placeholder="enter_secret" data-validate="required" data-error-i18n="err_required" style="direction:ltr;text-align:center">
                <div class="field-error" data-for="deployer_password" data-i18n="err_required">هذا الحقل مطلوب</div>
                <button type="submit" class="btn btn-p mt-2" data-i18n="login">🔓 دخول</button>
            </form>
            <?php if (!$deployer_decryption_ok): ?>
            <div style="margin-top:12px;padding:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:.8rem;color:#92400e;text-align:right;direction:rtl">
                ⚠️ <b>فشل فك تشفير الرمز السري!</b><br>
                القيمة في ملف .env مشفرة (تبدأ بـ ENC:) لكن فك التشفير فشل.<br>
                الحل: تأكد من تفعيل ملحق openssl في PHP، وأن APP_ENCRYPTION_KEY صحيح في .env<br>
                أو استخدم زر "نسيت الرمز السري" أدناه لإعادة تعيينه.
            </div>
            <?php endif; ?>

            <!-- زر نسيت الرمز السري -->
            <div style="margin-top:14px;text-align:center">
                <button type="button" onclick="document.getElementById('reset-form').style.display = document.getElementById('reset-form').style.display === 'none' ? 'block' : 'none'" style="background:none;border:none;color:#2563eb;font-size:.85rem;cursor:pointer;text-decoration:underline;font-weight:600">
                    🔑 نسيت الرمز السري؟ إعادة تعيين
                </button>
            </div>

            <!-- نموذج إعادة تعيين الرمز السري -->
            <div id="reset-form" style="display:none;margin-top:12px;padding:16px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;text-align:right;direction:rtl">
                <div style="font-size:.9rem;font-weight:700;color:#334155;margin-bottom:8px">🔑 إعادة تعيين الرمز السري</div>
                <div style="font-size:.78rem;color:#64748b;margin-bottom:12px">
                    إذا نسيت الرمز السري أو فشل فك التشفير، يمكنك تعيين رمز جديد.<br>
                    سيتم كتابة الرمز الجديد كنص عادي في ملف .env.
                </div>
                <?php if (!empty($deployer_reset_result)): ?>
                <div class="<?php echo $deployer_reset_result_type === 'success' ? 'a-ok' : 'a-err'; ?>" style="padding:8px;border-radius:8px;margin-bottom:8px;font-size:.82rem">
                    <?php echo htmlspecialchars($deployer_reset_result); ?>
                </div>
                <?php endif; ?>
                <form method="POST" action="" id="reset-secret-form" novalidate>
                    <input type="hidden" name="action" value="reset_secret">
                    <div style="margin-bottom:8px">
                        <label style="display:block;font-size:.82rem;font-weight:600;color:#334155;margin-bottom:4px" data-i18n="new_secret_min8">الرمز السري الجديد (8 أحرف على الأقل)</label>
                        <input type="password" name="new_reset_secret" required autocomplete="new-password" minlength="8" pattern=".{8,}" data-validate="required|minlength" data-minlength="8" data-error-i18n="err_minlength" style="width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:.9rem;box-sizing:border-box;direction:ltr;text-align:center">
                        <div class="field-error" data-for="new_reset_secret" data-i18n="err_minlength">8 أحرف على الأقل</div>
                    </div>
                    <div style="margin-bottom:10px">
                        <label style="display:block;font-size:.82rem;font-weight:600;color:#334155;margin-bottom:4px" data-i18n="confirm_new_secret">تأكيد الرمز السري الجديد</label>
                        <input type="password" name="confirm_reset_secret" required autocomplete="new-password" minlength="8" data-validate="required|match" data-match="new_reset_secret" data-error-i18n="err_match" style="width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:.9rem;box-sizing:border-box;direction:ltr;text-align:center">
                        <div class="field-error" data-for="confirm_reset_secret" data-i18n="err_match">الرمزان غير متطابقين</div>
                    </div>
                    <button type="submit" class="btn btn-p" style="font-size:.85rem" data-i18n="reset_secret_btn">
                        🔑 إعادة تعيين الرمز السري
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>

        <?php if ($requires_auth): ?>
        <div style="margin-bottom:10px" class="text-center">
            <a href="?logout=1" class="auth-logout">🔓 تسجيل الخروج</a>
        </div>
        <?php endif; ?>

        <?php if ($deployer_uses_default_secret): ?>
        <div class="alert a-err font-semibold">
            ⚠️ تحذير أمني خطير: الرمز السري لا يزال بالقيمة الافتراضية أو غير معيّن!
            يجب تغيير DEPLOYER_SECRET في ملف .env إلى قيمة قوية وفريدة فوراً.
            أي شخص يعرف القيمة الافتراضية يمكنه الوصول إلى الناشر ونشر ملفات خبيثة.
        </div>
        <?php endif; ?>

        <?php if ($maintenanceActive): ?>
        <div class="alert a-warn">⚠️ وضع الصيانة مفعّل حالياً — قد يكون هناك نشر لم يكتمل. يمكنك التراجع أدناه.</div>
        <?php endif; ?>

        <?php
        // تحذير عدم تطابق الإصدارات — معالجة كلا الاتجاهين
        $versionMismatch = ($dbVersion !== 'غير متاح' && $appVersion !== 'غير معروف' && $dbVersion !== $appVersion);
        $mismatchDirection = '';
        if ($versionMismatch) {
            $cmp = deployer_compareVersions($appVersion, $dbVersion);
            if ($cmp > 0) {
                $mismatchDirection = 'files_ahead'; // الملفات أحدث من قاعدة البيانات
            } elseif ($cmp < 0) {
                $mismatchDirection = 'db_ahead'; // قاعدة البيانات أحدث من الملفات
            }
        }
        if ($versionMismatch):
        ?>
        <div class="alert a-err font-semibold">
            🚨 عدم تطابق الإصدارات! الملفات: v<?php echo htmlspecialchars($appVersion); ?> | قاعدة البيانات: v<?php echo htmlspecialchars($dbVersion); ?>
            <?php if ($mismatchDirection === 'files_ahead'): ?>
            <br>هذا يعني أن الملفات تم تحديثها لكن قاعدة البيانات لم تُرقّى بعد. استخدم زر "استعادة طارئة" أدناه لإكمال الترقية.
            <?php elseif ($mismatchDirection === 'db_ahead'): ?>
            <br>⚠️ قاعدة البيانات أحدث من الملفات! هذا يعني أن قاعدة البيانات تمت ترقيتها لكن الملفات لم تُحدّث بعد. يجب نشر ملفات الإصدار v<?php echo htmlspecialchars($dbVersion); ?> عبر رفع ملف ZIP أعلاه لتطابق الإصدارات.
            <?php else: ?>
            <br>هناك اختلاف في الإصدارات. يرجى التحقق من حالة النظام.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- رسائل النتيجة -->
        <?php if (!empty($message)): ?>
        <div class="alert <?php echo $msgType === 'success' ? 'a-ok' : 'a-err'; ?>">
            <?php echo $msgType === 'success' ? '✅' : '❌'; ?> <?php echo htmlspecialchars($message); ?>
        </div>
        <?php if (!empty($deploySteps)): ?>
        <div class="sec">
            <div class="stitle">📋 تفاصيل العمليات</div>
            <div class="scr">
                <?php foreach ($deploySteps as $step): ?>
                <div class="item <?php echo $step['ok'] ? 'ok' : 'err'; ?>">
                    <span><?php echo $step['ok'] ? '✅' : '❌'; ?></span>
                    <span class="desc"><?php echo htmlspecialchars($step['msg']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- معلومات النظام -->
        <div class="srow">
            <div class="sitem"><div class="snum b"><?php echo htmlspecialchars($appVersion); ?></div><div class="slbl">إصدار التطبيق</div></div>
            <div class="sitem"><div class="snum <?php echo $dbVersion !== 'غير متاح' ? 'g' : 'r'; ?>"><?php echo htmlspecialchars($dbVersion); ?></div><div class="slbl">إصدار قاعدة البيانات</div></div>
            <div class="sitem"><div class="snum b"><?php echo $phpVersion; ?></div><div class="slbl">PHP</div></div>
            <div class="sitem"><div class="snum g"><?php echo deployer_formatSize($diskFree ?: 0); ?></div><div class="slbl">مساحة حرة</div></div>
        </div>

        <!-- النشر الذري -->
        <div class="sec">
            <div class="stitle">📦 نشر تحديث من ملف ZIP (Write-Then-Rename Atomic Deploy)</div>
            <div class="alert a-info">
                <strong>آلية النشر الذري:</strong><br>
                1. فحص مسبق (صلاحيات + مساحة قرص + مكتبة ZipArchive)<br>
                2. تفعيل وضع الصيانة تلقائياً (حجب الطلبات)<br>
                3. فك ضغط ZIP في staging + اكتشاف المجلد الجذري تلقائياً<br>
                4. تحقق أمني من المسارات (حماية Path Traversal / Zip Slip)<br>
                5. نسخ احتياطي للملفات الحالية التي ستُستبدل (للتراجع)<br>
                6. <strong>استبدال ذري لكل ملف: write(tmp) → rename(tmp, target)</strong> — ذري على نفس نظام الملفات حسب POSIX<br>
                7. حماية الملفات الحرجة (.htaccess + config/database.php + deployer.php لا تُستبدل)<br>
                8. إزالة وضع الصيانة + تراجع تلقائي عند الفشل
            </div>
            <form method="POST" action="" enctype="multipart/form-data" style="margin-top:10px" id="deploy-form" novalidate>
                <input type="hidden" name="action" value="deploy_zip">
                <div  class="d-flex gap-3 items-center flex-wrap">
                    <input type="file" name="zip_file" accept=".zip,application/zip,application/x-zip-compressed" required data-validate="required|zipfile" data-error-i18n="err_zip_required" style="flex:1; min-width:200px; padding:10px; border:2px dashed #94a3b8; font-size:.9rem" class="rounded-lg bg-gray-100">
                    <div class="field-error" data-for="zip_file" data-i18n="err_zip_required">يرجى اختيار ملف ZIP</div>
                    <button type="submit" class="btn btn-p w-auto" style="padding:10px 24px; font-size:.9rem; white-space:nowrap" data-i18n="deploy_atomic">
                        🚀 نشر ذري
                    </button>
                </div>
            </form>
            <div style="margin-top:6px; font-size:.72rem" class="text-muted text-center">💡 يدعم حزم ZIP ذات مجلد جذر واحد تلقائياً — لا حاجة لاستخراج الملفات يدوياً</div>

            <?php if ($lastManifest): ?>
            <div style="margin-top:10px; padding:10px; font-size:.82rem" class="bg-gray-100 rounded-lg">
                <strong>آخر نشر:</strong> <?php echo htmlspecialchars($lastManifest['timestamp'] ?? ''); ?> |
                <strong>الطريقة:</strong> <?php echo htmlspecialchars($lastManifest['method'] ?? ''); ?> |
                <strong>الملفات:</strong> <?php echo count($lastManifest['files'] ?? []); ?>
                <?php if (isset($lastManifest['zip_root_prefix']) && !empty($lastManifest['zip_root_prefix'])): ?> |
                <strong>مجلد ZIP:</strong> <?php echo htmlspecialchars(rtrim($lastManifest['zip_root_prefix'], '/')); ?>
                <?php endif; ?>
                <form method="POST" action="" style="margin-top:6px" class="d-inline m-0 p-0" id="rollback-form">
                    <input type="hidden" name="action" value="rollback_files">
                    <button type="submit" style="background:#dc2626; padding:4px 12px; font-size:.78rem" class="text-white border-0 rounded cursor-pointer font-semibold" data-i18n="rollback_btn">
                        ⏪ تراجع عن نشر الملفات
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- روابط -->
        <div class="links">
            <?php if (file_exists($baseDir . '/upgrade.php')): ?>
            <a href="upgrade.php" class="link-upgrade">🔄 مركز التحديث والإصلاح</a>
            <?php endif; ?>
            <?php if (file_exists($baseDir . '/index.php')): ?>
            <a href="index.php" class="link-app">🏠 العودة للتطبيق</a>
            <?php endif; ?>
        </div>

        <!-- معلومات -->
        <!-- الاستعادة الطارئة -->
        <div class="sec">
            <div class="stitle">🚑 استعادة طارئة — تشغيل ترقية قاعدة البيانات</div>
            <div class="alert a-info">
                إذا فشلت الترقية وفشل التراجع التلقائي، يمكنك تشغيل الترقية مباشرة من هنا.
                سيتم إنشاء نسخة احتياطية تلقائياً قبل البدء.
                الدوال المفقودة تُتخطى تلقائياً بدلاً من إيقاف الترقية.
            </div>
            <form method="POST" action="" id="emergency-form">
                <input type="hidden" name="action" value="emergency_upgrade">
                <button type="submit" class="btn btn-w" data-i18n="emergency_btn">
                    🚑 تشغيل ترقية قاعدة البيانات الآن
                </button>
            </form>
        </div>

        <!-- تغيير الرمز السري -->
        <div class="sec">
            <div class="stitle">🔑 تغيير الرمز السري للناشر</div>
            <div class="alert a-info">
                غيّر الرمز السري للوصول إلى الناشر. سيتم تحديث ملف .env تلقائياً.
                <?php if ($deployer_uses_default_secret): ?>
                <br><strong style="color:#dc2626">⚠️ الرمز الحالي ضعيف! يُنصح بتغييره فوراً.</strong>
                <?php endif; ?>
            </div>
            <form method="POST" action="" style="max-width:500px" id="change-secret-form" novalidate>
                <input type="hidden" name="action" value="change_secret">
                <div style="margin-bottom:10px">
                    <label style="display:block;font-size:.85rem;font-weight:600;color:#334155;margin-bottom:4px" data-i18n="current_secret">الرمز السري الحالي</label>
                    <input type="password" name="current_secret" required autocomplete="off" data-validate="required" data-error-i18n="err_required" style="width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:.9rem;box-sizing:border-box;direction:ltr;text-align:center">
                    <div class="field-error" data-for="current_secret" data-i18n="err_required">هذا الحقل مطلوب</div>
                </div>
                <div style="margin-bottom:10px">
                    <label style="display:block;font-size:.85rem;font-weight:600;color:#334155;margin-bottom:4px" data-i18n="new_secret_min8">الرمز السري الجديد (8 أحرف على الأقل)</label>
                    <input type="password" name="new_secret" required autocomplete="new-password" minlength="8" pattern=".{8,}" data-validate="required|minlength" data-minlength="8" data-error-i18n="err_minlength" style="width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:.9rem;box-sizing:border-box;direction:ltr;text-align:center">
                    <div class="field-error" data-for="new_secret" data-i18n="err_minlength">8 أحرف على الأقل</div>
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-size:.85rem;font-weight:600;color:#334155;margin-bottom:4px" data-i18n="confirm_new_secret">تأكيد الرمز السري الجديد</label>
                    <input type="password" name="confirm_secret" required autocomplete="new-password" minlength="8" data-validate="required|match" data-match="new_secret" data-error-i18n="err_match" style="width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:.9rem;box-sizing:border-box;direction:ltr;text-align:center">
                    <div class="field-error" data-for="confirm_secret" data-i18n="err_match">الرمزان غير متطابقين</div>
                </div>
                <button type="submit" class="btn btn-p" data-i18n="change_secret_btn">
                    🔑 تغيير الرمز السري
                </button>
            </form>
        </div>

        <div class="info-box">
            <h4>ℹ️ حول الناشر الذري المستقل</h4>
            <p>
                هذا الملف <strong>مستقل تماماً</strong> — لا يعتمد على أي ملف آخر في التطبيق.<br>
                يُشحن مع التثبيت الأولي ويبقى <strong>دائماً متاحاً</strong> لرفع أي تحديث ZIP.<br><br>
                <strong>لماذا ملف مستقل؟</strong> ميزة النشر الذري في مركز التحديث تحتاج نشر الملفات أولاً (معضلة الدجاجة والبيضة). لكن هذا الملف متاح دائماً بدون أي تحديث مسبق.<br><br>
                <strong>سير العمل الموصى به:</strong><br>
                1. ارفع ملف ZIP التحديث هنا ← يتم نشر الملفات ذرياً<br>
                2. انتقل إلى <strong>مركز التحديث والإصلاح</strong> ← قم بتحديث قاعدة البيانات<br><br>
                <strong>تقنية Write-Then-Rename</strong> (الأمثل مقارنة بـ Directory Swap):<br>
                • كل ملف يُستبدل ذرياً بعملية rename واحدة (POSIX guarantee)<br>
                • يحافظ على بنية المجلدات (مهم لـ __DIR__ و __FILE__)<br>
                • لا يعاني من مشكلة "نقل مجلد داخل نفسه"<br>
                • يعمل عبر أنظمة ملفات مختلفة
            </p>
        </div>
    <?php endif; ?>
    </div>
</div>

<script>
// ====================================================================
//  نظام i18n — ترجمة رسائل الواجهة (عربي / إنجليزي / فرنسي)
// ====================================================================
const I18N = {
    ar: {
        // عام
        loading: 'جاري التنفيذ...',
        confirm_action: 'هل تريد المتابعة؟',
        yes: 'نعم',
        no: 'لا',
        // تسجيل الدخول
        enter_secret: 'أدخل الرمز السري',
        login: '🔓 دخول',
        forgot_secret: '🔑 نسيت الرمز السري؟ إعادة تعيين',
        // إعادة تعيين
        new_secret_min8: 'الرمز السري الجديد (8 أحرف على الأقل)',
        confirm_new_secret: 'تأكيد الرمز السري الجديد',
        reset_secret_btn: '🔑 إعادة تعيين الرمز السري',
        reset_confirm: 'سيتم تعيين رمز سري جديد للناشر.\n\nتأكد من حفظ الرمز الجديد في مكان آمن!\n\nهل تريد المتابعة؟',
        // نشر
        deploy_atomic: '🚀 نشر ذري',
        deploy_confirm: 'سيتم نشر ملفات التحديث بشكل ذري (Write-Then-Rename).\n\n• يتم تفعيل وضع الصيانة تلقائياً\n• يتم نسخ الملفات الحالية احتياطياً قبل الاستبدال\n• كل ملف يُستبدل ذرياً بعملية rename (POSIX guarantee)\n• الملفات الحرجة (.htaccess + config/database.php) لا تُستبدل\n• في حالة الفشل يتم التراجع تلقائياً\n\nسيتم حجب الطلبات مؤقتاً أثناء النشر.\n\nهل تريد المتابعة؟',
        // تراجع
        rollback_btn: '⏪ تراجع عن نشر الملفات',
        rollback_confirm: '⚠️ سيتم استعادة الملفات القديمة وحذف ملفات التحديث المنشورة.\n\nهل أنت متأكد؟',
        // ترقية طارئة
        emergency_btn: '🚑 تشغيل ترقية قاعدة البيانات الآن',
        emergency_confirm: 'سيتم تشغيل ترقية قاعدة البيانات لجميع الإصدارات المعلقة.\n\n• يتم إنشاء نسخة احتياطية تلقائياً قبل البدء\n• الدوال المفقودة تُتخطى بدلاً من إيقاف الترقية\n• في حالة الفشل يتم التراجع تلقائياً\n\nهل تريد المتابعة؟',
        // تغيير الرمز
        current_secret: 'الرمز السري الحالي',
        change_secret_btn: '🔑 تغيير الرمز السري',
        change_confirm: 'سيتم تغيير الرمز السري للناشر.\n\nتأكد من حفظ الرمز الجديد في مكان آمن!\n\nهل تريد المتابعة؟',
        // أخطاء التحقق
        err_required: 'هذا الحقل مطلوب',
        err_minlength: 'يجب أن يكون 8 أحرف على الأقل',
        err_match: 'الرمزان غير متطابقين',
        err_zip_required: 'يرجى اختيار ملف ZIP',
        err_zip_invalid: 'يجب اختيار ملف بصيغة .zip فقط',
        // تحميل
        loading_deploy: 'جاري نشر الملفات ذرياً...',
        loading_rollback: 'جاري التراجع عن النشر...',
        loading_upgrade: 'جاري تشغيل ترقية قاعدة البيانات...',
        loading_change: 'جاري تغيير الرمز السري...',
        loading_reset: 'جاري إعادة تعيين الرمز السري...',
        loading_login: 'جاري تسجيل الدخول...'
    },
    en: {
        loading: 'Processing...',
        confirm_action: 'Do you want to continue?',
        enter_secret: 'Enter secret key',
        login: '🔓 Login',
        forgot_secret: '🔑 Forgot secret? Reset',
        new_secret_min8: 'New secret (at least 8 characters)',
        confirm_new_secret: 'Confirm new secret',
        reset_secret_btn: '🔑 Reset Secret',
        reset_confirm: 'A new deployer secret will be set.\n\nMake sure to save the new secret in a safe place!\n\nDo you want to continue?',
        deploy_atomic: '🚀 Atomic Deploy',
        deploy_confirm: 'Files will be deployed atomically (Write-Then-Rename).\n\n• Maintenance mode activated automatically\n• Current files backed up before replacement\n• Each file replaced atomically via rename (POSIX guarantee)\n• Critical files (.htaccess + config/database.php) are not replaced\n• Automatic rollback on failure\n\nRequests will be temporarily blocked during deployment.\n\nDo you want to continue?',
        rollback_btn: '⏪ Rollback Deployment',
        rollback_confirm: '⚠️ Current files will be restored and deployed updates will be removed.\n\nAre you sure?',
        emergency_btn: '🚑 Run Emergency DB Upgrade',
        emergency_confirm: 'Database upgrade will run for all pending versions.\n\n• Automatic backup before starting\n• Missing functions are skipped instead of stopping\n• Automatic rollback on failure\n\nDo you want to continue?',
        current_secret: 'Current secret',
        change_secret_btn: '🔑 Change Secret',
        change_confirm: 'The deployer secret will be changed.\n\nMake sure to save the new secret in a safe place!\n\nDo you want to continue?',
        err_required: 'This field is required',
        err_minlength: 'Must be at least 8 characters',
        err_match: 'The two secrets do not match',
        err_zip_required: 'Please select a ZIP file',
        err_zip_invalid: 'Only .zip files are allowed',
        loading_deploy: 'Deploying files atomically...',
        loading_rollback: 'Rolling back deployment...',
        loading_upgrade: 'Running database upgrade...',
        loading_change: 'Changing secret...',
        loading_reset: 'Resetting secret...',
        loading_login: 'Logging in...'
    },
    fr: {
        loading: 'Traitement en cours...',
        confirm_action: 'Voulez-vous continuer ?',
        enter_secret: 'Entrez la clé secrète',
        login: '🔓 Connexion',
        forgot_secret: '🔑 Clé oubliée ? Réinitialiser',
        new_secret_min8: 'Nouvelle clé (au moins 8 caractères)',
        confirm_new_secret: 'Confirmer la nouvelle clé',
        reset_secret_btn: '🔑 Réinitialiser la clé',
        reset_confirm: 'Une nouvelle clé sera définie.\n\nAssurez-vous de sauvegarder la nouvelle clé !\n\nVoulez-vous continuer ?',
        deploy_atomic: '🚀 Déploiement atomique',
        deploy_confirm: 'Les fichiers seront déployés atomiquement (Write-Then-Rename).\n\n• Mode maintenance activé automatiquement\n• Sauvegarde des fichiers avant remplacement\n• Chaque fichier remplacé atomiquement (POSIX guarantee)\n• Les fichiers critiques ne sont pas remplacés\n• Retour automatique en cas d\'échec\n\nVoulez-vous continuer ?',
        rollback_btn: '⏪ Annuler le déploiement',
        rollback_confirm: '⚠️ Les fichiers actuels seront restaurés et les mises à jour supprimées.\n\nÊtes-vous sûr ?',
        emergency_btn: '🚑 Mise à jour d\'urgence de la BDD',
        emergency_confirm: 'La mise à jour de la base de données sera exécutée.\n\n• Sauvegarde automatique avant de commencer\n• Les fonctions manquantes sont ignorées\n• Retour automatique en cas d\'échec\n\nVoulez-vous continuer ?',
        current_secret: 'Clé secrète actuelle',
        change_secret_btn: '🔑 Changer la clé',
        change_confirm: 'La clé sera modifiée.\n\nAssurez-vous de sauvegarder la nouvelle clé !\n\nVoulez-vous continuer ?',
        err_required: 'Ce champ est obligatoire',
        err_minlength: 'Doit contenir au moins 8 caractères',
        err_match: 'Les deux clés ne correspondent pas',
        err_zip_required: 'Veuillez sélectionner un fichier ZIP',
        err_zip_invalid: 'Seuls les fichiers .zip sont autorisés',
        loading_deploy: 'Déploiement atomique en cours...',
        loading_rollback: 'Annulation du déploiement...',
        loading_upgrade: 'Mise à jour de la BDD en cours...',
        loading_change: 'Changement de clé en cours...',
        loading_reset: 'Réinitialisation de la clé...',
        loading_login: 'Connexion en cours...'
    }
};

// اللغة الحالية — من localStorage أو الافتراضية
let currentLang = localStorage.getItem('deployer_lang') || 'ar';

// تطبيق الترجمة على كل العناصر
function applyI18N(lang) {
    if (!I18N[lang]) lang = 'ar';
    currentLang = lang;
    localStorage.setItem('deployer_lang', lang);
    document.documentElement.lang = lang;
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';

    // تحديث الأزرار النشطة
    document.querySelectorAll('.lang-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.lang === lang);
    });

    // ترجمة العناصر ذات data-i18n
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (I18N[lang][key] !== undefined) el.textContent = I18N[lang][key];
    });

    // ترجمة placeholder
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (I18N[lang][key] !== undefined) el.placeholder = I18N[lang][key];
    });

    // تحديث اتجاه حقول كلمة المرور
    document.querySelectorAll('input[type="password"]').forEach(el => {
        el.style.direction = 'ltr';
        el.style.textAlign = 'center';
    });
}

// استخراج نص مؤكد حسب الإجراء
function getConfirmMsg(action) {
    const msgs = {
        deploy_zip: I18N[currentLang]?.deploy_confirm || I18N.ar.deploy_confirm,
        rollback_files: I18N[currentLang]?.rollback_confirm || I18N.ar.rollback_confirm,
        emergency_upgrade: I18N[currentLang]?.emergency_confirm || I18N.ar.emergency_confirm,
        change_secret: I18N[currentLang]?.change_confirm || I18N.ar.change_confirm,
        reset_secret: I18N[currentLang]?.reset_confirm || I18N.ar.reset_confirm
    };
    return msgs[action] || I18N[currentLang]?.confirm_action || I18N.ar.confirm_action;
}

// استخراج نص التحميل حسب الإجراء
function getLoadingMsg(action) {
    const msgs = {
        deploy_zip: 'loading_deploy',
        rollback_files: 'loading_rollback',
        emergency_upgrade: 'loading_upgrade',
        change_secret: 'loading_change',
        reset_secret: 'loading_reset'
    };
    const key = msgs[action] || 'loading';
    return I18N[currentLang]?.[key] || I18N.ar[key] || I18N.ar.loading;
}

// ====================================================================
//  مكون التحميل الموحد
// ====================================================================
function showLoading(action) {
    const overlay = document.getElementById('deployer-loading');
    const textEl = overlay?.querySelector('.deployer-loading-text');
    if (overlay) {
        if (textEl) textEl.textContent = getLoadingMsg(action);
        overlay.style.display = 'flex';
    }
}
function hideLoading() {
    const overlay = document.getElementById('deployer-loading');
    if (overlay) overlay.style.display = 'none';
}

// ====================================================================
//  نظام التحقق من جانب العميل
// ====================================================================
function validateField(input) {
    const rules = (input.dataset.validate || '').split('|');
    const minLen = parseInt(input.dataset.minlength) || 0;
    const matchName = input.dataset.match || null;
    let errorMsg = '';

    for (const rule of rules) {
        if (rule === 'required' && !input.value.trim()) {
            errorMsg = I18N[currentLang]?.err_required || I18N.ar.err_required;
            break;
        }
        if (rule === 'minlength' && input.value.trim().length < minLen) {
            errorMsg = I18N[currentLang]?.err_minlength || I18N.ar.err_minlength;
            break;
        }
        if (rule === 'match' && matchName) {
            const matchInput = input.form?.querySelector('[name="' + matchName + '"]');
            if (matchInput && input.value !== matchInput.value) {
                errorMsg = I18N[currentLang]?.err_match || I18N.ar.err_match;
                break;
            }
        }
        if (rule === 'zipfile') {
            const file = input.files?.[0];
            if (file && !file.name.toLowerCase().endsWith('.zip')) {
                errorMsg = I18N[currentLang]?.err_zip_invalid || I18N.ar.err_zip_invalid;
                break;
            }
        }
    }

    // عرض/إخفاء رسالة الخطأ
    const errorEl = input.parentElement?.querySelector('.field-error[data-for="' + input.name + '"]');
    if (errorMsg) {
        input.classList.add('invalid');
        input.classList.remove('valid');
        if (errorEl) {
            errorEl.textContent = errorMsg;
            errorEl.classList.add('visible');
        }
    } else {
        input.classList.remove('invalid');
        if (input.value.trim()) input.classList.add('valid');
        else input.classList.remove('valid');
        if (errorEl) errorEl.classList.remove('visible');
    }
    return !errorMsg;
}

// التحقق من نموذج كامل
function validateForm(form) {
    const inputs = form.querySelectorAll('[data-validate]');
    let allValid = true;
    inputs.forEach(input => {
        if (!validateField(input)) allValid = false;
    });
    return allValid;
}

// ====================================================================
//  تهيئة الأحداث عند تحميل الصفحة
// ====================================================================
document.addEventListener('DOMContentLoaded', function() {
    // تطبيق اللغة المحفوظة
    applyI18N(currentLang);

    // أزرار تبديل اللغة
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            applyI18N(this.dataset.lang);
        });
    });

    // التحقق الفوري عند الكتابة
    document.querySelectorAll('[data-validate]').forEach(input => {
        input.addEventListener('input', function() { validateField(this); });
        input.addEventListener('change', function() { validateField(this); });
        // عند فقدان التركيز
        input.addEventListener('blur', function() {
            if (this.value.trim()) validateField(this);
        });
    });

    // اعتراض إرسال جميع النماذج
    document.querySelectorAll('form[method="POST"]').forEach(form => {
        form.addEventListener('submit', function(e) {
            // تحقق من جانب العميل
            const hasValidation = this.querySelectorAll('[data-validate]').length > 0;
            if (hasValidation && !validateForm(this)) {
                e.preventDefault();
                // التركيز على أول حقل خاطئ
                const firstInvalid = this.querySelector('.invalid');
                if (firstInvalid) firstInvalid.focus();
                return;
            }

            // تحديد الإجراء
            const actionInput = this.querySelector('[name="action"]');
            const action = actionInput ? actionInput.value : 'login';

            // تأكيد قبل الإجراءات الخطرة
            const needsConfirm = ['deploy_zip', 'rollback_files', 'emergency_upgrade', 'change_secret', 'reset_secret'];
            if (needsConfirm.includes(action)) {
                if (!confirm(getConfirmMsg(action))) {
                    e.preventDefault();
                    return;
                }
            }

            // عرض مؤشر التحميل
            showLoading(action);

            // للنماذج العادية (non-AJAX) — إخفاء التحميل بعد 60 ثانية كحد أقصى
            setTimeout(hideLoading, 60000);
        });
    });

    // تحسين حقل رفع الملفات — عرض اسم الملف
    const zipInput = document.querySelector('input[name="zip_file"]');
    if (zipInput) {
        zipInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const name = this.files[0].name;
                const size = (this.files[0].size / (1024 * 1024)).toFixed(1);
                // عرض معلومات الملف
                let info = this.parentElement.querySelector('.zip-info');
                if (!info) {
                    info = document.createElement('div');
                    info.className = 'zip-info';
                    info.style.cssText = 'font-size:.78rem;color:#059669;margin-top:4px';
                    this.parentElement.insertBefore(info, this.nextSibling.nextSibling || null);
                }
                info.textContent = '📎 ' + name + ' (' + size + ' MB)';
            }
        });
    }
});
</script>
</body>
</html>
