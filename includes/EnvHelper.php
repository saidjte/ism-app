<?php
/**
 * EnvHelper — أدوات مشتركة لقراءة وكتابة ملف .env
 * يُستخدم من settings/db-settings.php و settings/system-settings.php
 *
 * @since v4.27.0
 * @since v4.31.5 دعم القيم ذات الأحرف الخاصة (اقتباس تلقائي + إزالة اقتباس)
 */

class EnvHelper
{
    /**
     * الأحرف التي تتطلب اقتباس القيمة في ملف .env
     * تتضمن: مسافات، علامات اقتباس، # (تعليقات)، $ (متغيرات)،
     * أحرف خاصة مثل } { ( ) ? , \ إلخ
     */
    private const CHARS_REQUIRE_QUOTING = ' "#\'$}{}()?,;\\!%^&*|~`';

    /**
     * قراءة ملف .env كاملاً وتحويله لمصفوفة مهيكلة
     *
     * @param string $path مسار ملف .env
     * @return array مصفوفة من العناصر: ['type'=>'comment'|'other'|'entry', 'key'=>..., 'value'=>..., 'raw'=>...]
     */
    public static function readEnvFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $env = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $env[] = ['type' => 'comment', 'raw' => $line];
                continue;
            }
            if (!str_contains($trimmed, '=')) {
                $env[] = ['type' => 'other', 'raw' => $line];
                continue;
            }
            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);

            // إزالة علامات الاقتباس المحيطة بالقيمة
            $value = self::unquoteValue($value);

            $env[] = [
                'type'  => 'entry',
                'key'   => $key,
                'value' => $value,
                'raw'   => $line,
            ];
        }
        return $env;
    }

    /**
     * كتابة ملف .env مع تحديث قيم محددة
     *
     * @param string $path   مسار ملف .env
     * @param array  $envData مصفوفة من readEnvFile()
     * @param array  $updates قيم للتحديث ['KEY' => 'new_value']
     * @return bool نجاح العملية
     */
    public static function writeEnvFile(string $path, array $envData, array $updates): bool
    {
        $lines = [];
        $updatedKeys = [];

        foreach ($envData as $item) {
            if ($item['type'] === 'entry' && isset($updates[$item['key']])) {
                $newValue = $updates[$item['key']];
                $lines[] = $item['key'] . '=' . self::quoteValue($newValue);
                $updatedKeys[$item['key']] = true;
            } else {
                $lines[] = $item['raw'];
            }
        }

        // إضافة المفاتيح الجديدة غير الموجودة
        foreach ($updates as $key => $value) {
            if (!isset($updatedKeys[$key])) {
                $lines[] = $key . '=' . self::quoteValue($value);
            }
        }

        // كتابة آلية (atomic write)
        $tmpFile = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmpFile, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            @unlink($tmpFile);
            return false;
        }
        if (!rename($tmpFile, $path)) {
            @unlink($tmpFile);
            return false;
        }
        return true;
    }

    /**
     * إنشاء نسخة احتياطية من ملف .env
     *
     * @param string $path مسار ملف .env
     * @return string|false مسار النسخة الاحتياطية أو false عند الفشل
     */
    public static function backupEnvFile(string $path): string|false
    {
        if (!file_exists($path)) {
            return false;
        }
        $backupPath = $path . '.backup.' . date('Y-m-d_His');
        if (copy($path, $backupPath)) {
            return $backupPath;
        }
        return false;
    }

    /**
     * اقتباس قيمة لكتابتها في ملف .env
     *
     * القواعد:
     * — القيم المشفرة (ENC:...) لا تُقتبَس (أحرف آمنة: base64)
     * — القيم الفارغة لا تُقتبَس
     * — القيم التي تحتوي أحرف خاصة تُحاط بعلامات اقتباس مزدوجة
     * — علامات الاقتباس المزدوجة داخل القيمة تُهرب بـ \"
     *
     * @param string $value القيمة المراد اقتباسها
     * @return string القيمة مع الاقتباس إذا لزم
     */
    public static function quoteValue(string $value): string
    {
        // القيم الفارغة لا تحتاج اقتباس
        if ($value === '') {
            return '';
        }

        // القيم المشفرة (ENC:...) تحتوي فقط أحرف base64 و = و : — آمنة بدون اقتباس
        if (str_starts_with($value, 'ENC:')) {
            return $value;
        }

        // القيم الرقمية البحتة آمنة بدون اقتباس
        if (preg_match('/^[a-zA-Z0-9_.\-:\/@]+$/', $value)) {
            return $value;
        }

        // القيمة تحتاج اقتباس — نحيطها بعلامات اقتباس مزدوجة
        // مع إهرب أي علامات اقتباس مزدوجة داخلها
        $escaped = str_replace('\\', '\\\\', $value);
        $escaped = str_replace('"', '\\"', $escaped);
        $escaped = str_replace('$', '\\$', $escaped);
        $escaped = str_replace('`', '\\`', $escaped);

        return '"' . $escaped . '"';
    }

    /**
     * إزالة اقتباس قيمة مقروءة من ملف .env
     *
     * @param string $value القيمة المقروءة من الملف
     * @return string القيمة بدون اقتباس
     */
    public static function unquoteValue(string $value): string
    {
        // إزالة علامات الاقتباس المزدوجة المحيطة
        if (preg_match('/^"(.*)"$/s', $value, $matches)) {
            $inner = $matches[1];
            // عكس التهريب
            $inner = str_replace('\\`', '`', $inner);
            $inner = str_replace('\\$', '$', $inner);
            $inner = str_replace('\\"', '"', $inner);
            $inner = str_replace('\\\\', '\\', $inner);
            return $inner;
        }

        // إزالة علامات الاقتباس المفردة المحيطة
        if (preg_match("/^'(.*)'$/s", $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }
}
