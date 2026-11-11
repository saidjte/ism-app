<?php
/**
 * ===== سكربت تشفير القيم الحساسة في .env (نسخة آمنة) =====
 * يُشغَّل مرة واحدة لتشفير كلمات المرور والرموز السرية الموجودة
 *
 * الاستخدام:
 *   php scripts/encrypt-env-helper.php              ← تشفير مع تأكيد تفاعلي
 *   php scripts/encrypt-env-helper.php --dry-run     ← معاينة بدون حفظ
 *   php scripts/encrypt-env-helper.php --yes         ← تشفير بدون تأكيد
 *
 * يقوم بما يلي:
 *   1. قراءة القيم الحساسة الحالية من ملف .env
 *   2. عرض ما سيتم تشفيره (مع إخفاء القيم)
 *   3. طلب تأكيد المستخدم
 *   4. تشفيرها باستخدام AES-256-GCM
 *   5. التحقق من صحة التشفير (فك التشفير ومقارنة القيمة)
 *   6. تحديث ملف .env بالقيم المشفرة
 *
 * ⛔ هذا السكربت لا يُغيّر كلمات المرور أبداً — فقط يُشفّر القيم الموجودة
 * ⛔ لا يُولّد كلمات مرور جديدة
 * ⛔ لا يُعدّل أي خدمة (MySQL, Redis, إلخ)
 */

// ===== وسائط سطر الأوامر =====
$dryRun = in_array('--dry-run', $argv);
$autoYes = in_array('--yes', $argv) || in_array('-y', $argv);

if ($dryRun) {
    echo "=== وضع المعاينة (DRY-RUN) — لن يتم حفظ أي تغيير ===\n\n";
}

// تحميل فئة التشفير
require_once __DIR__ . '/../includes/EnvEncryptor.php';

// القيم الحساسة التي يجب تشفيرها
$SENSITIVE_KEYS = ['DB_PASS', 'DEPLOYER_SECRET', 'REDIS_PASSWORD'];

// ⛔ أنواع القيم التي لا يجب أن تُغيّر قيمتها أبداً
$NEVER_CHANGE_WARNING = [
    'DB_PASS' => 'كلمة مرور MySQL — تغييرها يتطلب ALTER USER في MySQL أيضاً',
    'DEPLOYER_SECRET' => 'سر النشر — تغييره يبطل رموز النشر الحالية',
];

// قراءة ملف .env
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    echo "❌ خطأ: ملف .env غير موجود في المسار المتوقع\n";
    exit(1);
}

// تحميل متغيرات البيئة
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0 || !strpos($line, '=')) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) continue;
    $key = trim($parts[0]);
    $value = trim($parts[1]);
    // إزالة علامات الاقتباس إذا كانت محاطة بها
    if (preg_match('/^"(.*)"$/', $value, $m)) {
        $value = $m[1];
    } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
        $value = $m[1];
    }
    putenv("$key=$value");
    $_ENV[$key] = $value;
}

// تعيين مفتاح التشفير
$encryptionKey = getenv('APP_ENCRYPTION_KEY');
if (empty($encryptionKey)) {
    echo "❌ خطأ: APP_ENCRYPTION_KEY غير معرّف في ملف .env\n";
    echo "أضف السطر التالي إلى ملف .env أولاً:\n";
    echo "   APP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\n";
    exit(1);
}

EnvEncryptor::setKey(base64_decode($encryptionKey, true) ?: $encryptionKey);

// ===== المرحلة 1: تحليل القيم التي تحتاج تشفير =====
echo "=== تحليل القيم الحساسة في ملف .env ===\n\n";

$toEncrypt = [];
$skipped = [];
$errors = [];

foreach ($lines as $lineIndex => $line) {
    $trimmed = trim($line);
    if (strpos($trimmed, '#') === 0 || !strpos($trimmed, '=')) continue;

    $parts = explode('=', $trimmed, 2);
    if (count($parts) !== 2) continue;
    $key = trim($parts[0]);
    $value = trim($parts[1]);
    // إزالة علامات الاقتباس
    if (preg_match('/^"(.*)"$/', $value, $m)) {
        $value = $m[1];
    } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
        $value = $m[1];
    }

    // تخطي المفاتيح غير الحساسة
    if (!in_array($key, $SENSITIVE_KEYS)) continue;

    // تخطي القيم الفارغة
    if (empty($value)) {
        $skipped[] = "{$key} (فارغ)";
        continue;
    }

    // تخطي القيم المشفرة بالفعل
    if (EnvEncryptor::isEncrypted($value)) {
        $skipped[] = "{$key} (مشفّر بالفعل)";
        continue;
    }

    // تخطي مفتاح التشفير نفسه
    if ($key === 'APP_ENCRYPTION_KEY') {
        $skipped[] = "{$key} (مفتاح التشفير — لا يُشفَّر)";
        continue;
    }

    // عرض القيمة مع إخفاء معظمها
    $masked = maskValue($value);
    $toEncrypt[] = [
        'key' => $key,
        'value' => $value,
        'masked' => $masked,
        'lineIndex' => $lineIndex,
    ];
    echo "  📋 {$key}: القيمة الحالية = {$masked}\n";

    // تحذير خاص: لا تُغيّر كلمة المرور!
    if (isset($NEVER_CHANGE_WARNING[$key])) {
        echo "     ⚠️  تنبيه: {$NEVER_CHANGE_WARNING[$key]}\n";
    }
}

// ===== المرحلة 2: عرض الملخص وطلب التأكيد =====
echo "\n=== ملخص ===\n";
if (!empty($toEncrypt)) {
    echo "سيتم تشفير " . count($toEncrypt) . " قيمة: " . implode(', ', array_column($toEncrypt, 'key')) . "\n";
} else {
    echo "لا توجد قيم جديدة لتشفيرها.\n";
}
if (!empty($skipped)) {
    echo "تم التخطي: " . implode(', ', $skipped) . "\n";
}

if (empty($toEncrypt)) {
    echo "\n✅ جميع القيم الحساسة مشفرة بالفعل. لا حاجة لأي إجراء.\n";
    exit(0);
}

echo "\n⛔ تنبيه مهم:\n";
echo "   — هذا السكربت يُشفّر القيم الموجودة فقط ولا يُغيّرها\n";
echo "   — لن يتم تغيير أي كلمة مرور في أي خدمة\n";
echo "   — سيتم إنشاء نسخة احتياطية من .env قبل الحفظ\n";
echo "   — يُنصح بتشغيل --dry-run أولاً للمعاينة\n\n";

// طلب تأكيد المستخدم
if (!$autoYes && !$dryRun) {
    echo "هل تريد المتابعة؟ (اكتب 'نعم' أو 'yes' للتأكيد): ";
    $handle = fopen('php://stdin', 'r');
    $answer = trim(fgets($handle));
    fclose($handle);

    if ($answer !== 'نعم' && $answer !== 'yes') {
        echo "❌ تم الإلغاء.\n";
        exit(0);
    }
    echo "\n";
}

// ===== المرحلة 3: التشفير مع التحقق =====
$encrypted = [];
foreach ($toEncrypt as $item) {
    $key = $item['key'];
    $value = $item['value'];
    $lineIndex = $item['lineIndex'];

    try {
        // تشفير القيمة الحالية
        $encryptedValue = EnvEncryptor::encrypt($value);

        // ⚠️ تحقق حرج: فك التشفير ومقارنة القيمة الأصلية
        $decryptedValue = EnvEncryptor::decrypt($encryptedValue);
        if ($decryptedValue !== $value) {
            $errors[] = "{$key}: فشل التحقق — القيمة المفكوكة لا تطابق الأصلية!";
            echo "  ❌ {$key}: فشل التحقق من صحة التشفير! (القيمة الأصلية لا تطابق المفكوكة)\n";
            continue;
        }

        // تحديث السطر في الملف
        $lines[$lineIndex] = "{$key}={$encryptedValue}";
        $encrypted[] = $key;
        echo "  ✅ {$key}: تم التشفير والتحقق بنجاح ({$item['masked']} → ENC:...)\n";

    } catch (Throwable $e) {
        $errors[] = "{$key}: " . $e->getMessage();
        echo "  ❌ {$key}: فشل التشفير - " . $e->getMessage() . "\n";
    }
}

// ===== المرحلة 4: حفظ الملف =====
if ($dryRun) {
    echo "\n=== وضع المعاينة — لم يتم حفظ أي تغيير ===\n";
    if (!empty($encrypted)) {
        echo "سيتم تشفير: " . implode(', ', $encrypted) . "\n";
    }
    if (!empty($errors)) {
        echo "أخطاء: " . implode(', ', $errors) . "\n";
    }
    exit(0);
}

if (!empty($encrypted)) {
    // إنشاء نسخة احتياطية إلزامية
    $backupPath = $envPath . '.backup.' . date('Y-m-d_His');
    if (!copy($envPath, $backupPath)) {
        echo "\n❌ خطأ حرج: فشل إنشاء النسخة الاحتياطية! لن يتم حفظ التغييرات.\n";
        exit(1);
    }
    echo "\n📁 نسخة احتياطية: " . basename($backupPath) . "\n";

    // حفظ الملف
    $writeResult = file_put_contents($envPath, implode("\n", $lines) . "\n", LOCK_EX);
    if ($writeResult === false) {
        echo "❌ خطأ: فشل كتابة ملف .env! استعد من النسخة الاحتياطية.\n";
        // محاولة استعادة النسخة الاحتياطية
        copy($backupPath, $envPath);
        exit(1);
    }

    echo "\n✅ تم تحديث ملف .env بنجاح!\n";
} else {
    echo "\nℹ️ لا توجد قيم جديدة لتشفيرها.\n";
}

// ===== النتيجة النهائية =====
echo "\n=== النتيجة النهائية ===\n";
if (!empty($encrypted)) {
    echo "✅ تم التشفير: " . implode(', ', $encrypted) . "\n";
}
if (!empty($skipped)) {
    echo "⏭️ تم التخطي: " . implode(', ', $skipped) . "\n";
}
if (!empty($errors)) {
    echo "❌ أخطاء: " . implode(', ', $errors) . "\n";
}

echo "\n📌 ملاحظات:\n";
echo "   — هذا السكربت يُشفّر القيم الموجودة فقط ولا يُغيّر كلمات المرور\n";
echo "   — يمكنك إعادة تشغيله بأمان — سيخطّي القيم المشفرة\n";
echo "   — احتفظ بالنسخة الاحتياطية حتى تتحقق من عمل كل شيء\n";

/**
 * إخفاء قيمة حساسة — يعرض أول 2 وآخر 2 أحرف فقط
 */
function maskValue(string $value): string {
    $len = strlen($value);
    if ($len <= 4) {
        return str_repeat('●', $len);
    }
    if ($len <= 8) {
        return substr($value, 0, 2) . str_repeat('●', $len - 4) . substr($value, -2);
    }
    return substr($value, 0, 2) . str_repeat('●', $len - 4) . substr($value, -2) . " ({$len} حرف)";
}
