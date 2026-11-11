<?php
/**
 * ============================================================
 *  ISM POS — سكربت تدوير الأسرار (المرحلة 0 — إجراء طارئ)
 * ============================================================
 *
 *  السياق: السجل القديم في Git كان يحتوي أسراراً بصيغة نص صريح
 *  (DB_PASS=938685 للمستخدم root، DEPLOYER_SECRET افتراضي،
 *  APP_ENCRYPTION_KEY). أي سر ظهر في Git يجب اعتباره مكشوفاً
 *  نهائياً ويلزم تدويره — هذا السكربت ينفّذ التدوير كاملاً.
 *
 *  ماذا يفعل:
 *   1. يفكّ تشفير القيم الحالية من .env بالمفتاح القديم
 *   2. يولّد أسراراً جديدة قوية:
 *        - DB_PASS (كلمة مرور MySQL جديدة)
 *        - DEPLOYER_SECRET (سر النشر)
 *        - APP_ENCRYPTION_KEY (مفتاح تشفير .env نفسه)
 *   3. يعيد تشفير كل شيء بالمفتاح الجديد
 *   4. يحفظ نسخة احتياطية من .env القديم ثم يكتب الجديد (صلاحية 0640)
 *   5. يطبع SQL جاهزاً لإنشاء مستخدم MySQL مخصص (ism_app) بدل root
 *      وتحديث كلمة مرور root — انسخه ونفّذه في MySQL فوراً
 *   6. يطبع مفتاحاً عشوائياً مقترحاً لحقل "مفتاح أمان cron" في الإعدادات
 *
 *  الاستخدام:
 *    php scripts/rotate-secrets.php --dry-run        معاينة بدون أي تغيير
 *    php scripts/rotate-secrets.php                  التدوير الكامل
 *    php scripts/rotate-secrets.php --keep-db-pass   لا تُغيّر كلمة مرور DB (فقط إعادة تشفيرها بمفتاح جديد)
 *    php scripts/rotate-secrets.php --keep-deployer  الاحتفاظ بسر النشر الحالي
 *
 *  ⛔ يعمل من سطر الأوامر فقط (CLI) — لا يشغّله من المتصفح أبداً
 *  ⛔ بعد التنفيذ نفّذ SQL المطبوع فوراً وإلا سيتوقف التطبيق عن الاتصال
 *  ⛔ أعد تشغيل أي Worker/خدمة تستخدم .env بعد التدوير
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("هذا السكربت يعمل من سطر الأوامر فقط.\n");
}

$dryRun        = in_array('--dry-run', $argv, true);
$keepDbPass    = in_array('--keep-db-pass', $argv, true);
$keepDeployer  = in_array('--keep-deployer', $argv, true);

require_once __DIR__ . '/../includes/EnvEncryptor.php';

const ENV_PATH = __DIR__ . '/../.env';

function generateSecret(int $bytes = 24): string {
    return bin2hex(random_bytes($bytes)); // أحرف hex فقط — آمنة داخل .env وURLs
}

function fail(string $msg): void {
    fwrite(STDERR, "❌ خطأ: $msg\n");
    exit(1);
}

// ---------- 1) قراءة .env ----------
if (!file_exists(ENV_PATH)) {
    fail('.env غير موجود في ' . ENV_PATH);
}
$envLines = file(ENV_PATH, FILE_IGNORE_NEW_LINES);
if ($envLines === false) fail('تعذر قراءة .env');

$env = [];
foreach ($envLines as $line) {
    $t = trim($line);
    if ($t === '' || $t[0] === '#' || !str_contains($t, '=')) continue;
    [$k, $v] = explode('=', $t, 2);
    $env[trim($k)] = trim($v);
}

foreach (['APP_ENCRYPTION_KEY'] as $required) {
    if (empty($env[$required])) fail("$required غير موجود في .env — لا يمكن التدوير بأمان");
}

// ---------- 2) فك التشفير بالمفتاح القديم ----------
$oldKeyRaw = base64_decode($env['APP_ENCRYPTION_KEY'], true);
if ($oldKeyRaw === false || strlen($oldKeyRaw) !== 32) {
    fail('APP_ENCRYPTION_KEY الحالي ليس base64 صالحاً بطول 32 بايت');
}
EnvEncryptor::setKey($oldKeyRaw);

$oldDbPass = '';
try {
    $oldDbPass = EnvEncryptor::decryptOrRaw($env['DB_PASS'] ?? '');
} catch (Throwable $e) {
    fail('تعذر فك تشفير DB_PASS بالمفتاح الحالي: ' . $e->getMessage());
}

$oldDeployer = '';
if (!empty($env['DEPLOYER_SECRET'])) {
    try {
        $oldDeployer = EnvEncryptor::decryptOrRaw($env['DEPLOYER_SECRET']);
    } catch (Throwable $e) {
        fail('تعذر فك تشفير DEPLOYER_SECRET بالمفتاح الحالي: ' . $e->getMessage());
    }
}

// ---------- 3) توليد الأسرار الجديدة ----------
$newDbPass       = $keepDbPass   ? $oldDbPass   : generateSecret(24);       // 48 حرف hex
$newDeployer     = $keepDeployer ? $oldDeployer : generateSecret(24);
$newKeyRaw       = random_bytes(32);
$newKeyBase64    = base64_encode($newKeyRaw);

// ---------- 4) إعادة التشفير بالمفتاح الجديد ----------
EnvEncryptor::setKey($newKeyRaw);
try {
    $newDbPassEnc   = EnvEncryptor::encrypt($newDbPass);
    $newDeployerEnc = EnvEncryptor::encrypt($newDeployer);
} catch (Throwable $e) {
    fail('فشل التشفير بالمفتاح الجديد: ' . $e->getMessage());
}

$dbName = $env['DB_NAME'] ?? 'inventory_db';
$dbUser = $env['DB_USER'] ?? 'root';
$dbHost = $env['DB_HOST'] ?? 'localhost';
$cronKeySuggestion = generateSecret(16);

echo "\n=========================", ($dryRun ? " معاينة (DRY-RUN) " : " تدوير فعلّي "), "=========================\n\n";

echo "القيم الحالية (مُقنّعة):\n";
echo "  DB_USER:          $dbUser\n";
echo "  DB_PASS القديمة:  " . str_repeat('•', max(8, strlen($oldDbPass))) . " (طول " . strlen($oldDbPass) . ")\n";
echo "  DEPLOYER القديم:  " . str_repeat('•', max(8, strlen($oldDeployer))) . " (طول " . strlen($oldDeployer) . ")\n\n";

echo "القيم الجديدة (مُقنّعة):\n";
echo "  DB_PASS الجديدة:  " . str_repeat('•', strlen($newDbPass)) . " (طول " . strlen($newDbPass) . ")\n";
echo "  DEPLOYER الجديد:  " . str_repeat('•', strlen($newDeployer)) . " (طول " . strlen($newDeployer) . ")\n";
echo "  APP_ENCRYPTION_KEY: مفتاح 32 بايت جديد\n\n";

if ($dryRun) {
    echo "لم يُغيّر شيء (وضع المعاينة). أعد التشغيل بدون --dry-run للتطبيق.\n\n";
    exit(0);
}

// ---------- 5) كتابة .env الجديد (مع نسخة احتياطية) ----------
$backupPath = ENV_PATH . '.backup.' . date('Ymd_His');
if (!copy(ENV_PATH, $backupPath)) fail('تعذر إنشاء نسخة احتياطية من .env');
@chmod($backupPath, 0600);
echo "✓ نسخة احتياطية: " . basename($backupPath) . " (احتفظ بها إلى حين التأكد من عمل كل شيء ثم احذفها)\n";

$newLines = [];
$seen = ['DB_PASS' => false, 'DEPLOYER_SECRET' => false, 'APP_ENCRYPTION_KEY' => false];
foreach ($envLines as $line) {
    $t = trim($line);
    if (preg_match('/^(DB_PASS|DEPLOYER_SECRET|APP_ENCRYPTION_KEY)=/', $t)) {
        $key = substr($t, 0, strpos($t, '='));
        $seen[$key] = true;
        if ($key === 'DB_PASS')          $newLines[] = "DB_PASS=" . $newDbPassEnc;
        if ($key === 'DEPLOYER_SECRET')  $newLines[] = "DEPLOYER_SECRET=" . $newDeployerEnc;
        if ($key === 'APP_ENCRYPTION_KEY') $newLines[] = "APP_ENCRYPTION_KEY=" . $newKeyBase64;
        continue;
    }
    $newLines[] = $line;
}
// مفاتيح غير موجودة سابقاً → أضفها في النهاية
if (!$seen['DB_PASS'])          $newLines[] = "DB_PASS=" . $newDbPassEnc;
if (!$seen['DEPLOYER_SECRET'])  $newLines[] = "DEPLOYER_SECRET=" . $newDeployerEnc;
if (!$seen['APP_ENCRYPTION_KEY']) $newLines[] = "APP_ENCRYPTION_KEY=" . $newKeyBase64;

if (file_put_contents(ENV_PATH, implode("\n", $newLines) . "\n", LOCK_EX) === false) {
    fail('فشل كتابة .env — النسخة الاحتياطية محفوظة في ' . basename($backupPath));
}
chmod(ENV_PATH, 0640);
echo "✓ حُدّث .env بالقيم المشفرة الجديدة (صلاحية 0640)\n";

// ---------- 6) SQL للتنفيذ في MySQL ----------
$mysqlUser = 'ism_app';
$sql = "-- ============================================================
-- نفّذ هذا الملف في MySQL فوراً بعد تدوير الأسرار
-- (mysql -u root -p < rotate-secrets.sql)
-- ============================================================

-- 1) إنشاء مستخدم تطبيق مخصص بأقل الصلاحيات اللازمة (بدل root)
CREATE USER IF NOT EXISTS '{$mysqlUser}'@'{$dbHost}' IDENTIFIED BY '{$newDbPass}';
ALTER USER '{$mysqlUser}'@'{$dbHost}' IDENTIFIED BY '{$newDbPass}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, LOCK TABLES
  ON `{$dbName}`.* TO '{$mysqlUser}'@'{$dbHost}';
FLUSH PRIVILEGES;

-- 2) تحديث كلمة مرور root القديمة المكشوفة (اختياري لكن يُنصح به بشدة)
-- استبدل كلمة_مرور_root_الجديدة_هنا بكلمة قوية خاصة بك:
-- ALTER USER 'root'@'localhost' IDENTIFIED BY 'كلمة_مرور_root_الجديدة_هنا';
-- FLUSH PRIVILEGES;
";

echo "\n========== نفّذ هذا الـ SQL في MySQL الآن (انسخه) ==========\n";
echo $sql;
echo "==============================================================\n\n";

echo "ثم حدّث في .env:\n";
echo "  DB_USER={$mysqlUser}\n\n";

echo "مفتاح مقترح لحقل \"مفتاح أمان cron\" (الإعدادات):\n  {$cronKeySuggestion}\n";
echo "  بعد ضبطه حدّث رابط الـ cron المجدول ليصبح:\n";
echo "  .../cron/whatsapp-daily.php?key={$cronKeySuggestion}\n\n";

echo "خطوات ما بعد التدوير:\n";
echo "  1) نفّذ الـ SQL أعلاه في MySQL (إلزامي — وإلا سيفشل الاتصال)\n";
echo "  2) غيّر DB_USER إلى {$mysqlUser} في .env\n";
echo "  3) أعد ضبط سر النشر الجديد عند أول استخدام لـ deployer.php\n";
echo "  4) حدّث مفتاح cron في صفحة الإعدادات + مجدول المهام\n";
echo "  5) تحقق من تسجيل الدخول وعمل POS ثم احذف نسخة .env الاحتياطية\n";
echo "  6) دوّر رمز GitHub المستخدم في أي جلسة سابقة (Settings → Developer settings)\n\n";
