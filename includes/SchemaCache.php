<?php
/**
 * ===== كاش بنية الجدول (SchemaCache) =====
 * نظام تخزين مؤقت بسيط ومحدود — يُستخدم فقط لكشف بنية الجداول
 * (SHOW COLUMNS, كشف وجود أعمدة/جداول) — بيانات لا تتغير إلا نادراً
 *
 * ⚠️ لا يُستخدم أبداً لبيانات الأعمال (مبيعات، أرباح، إشعارات، صلاحية)
 *
 * الإعدادات تُقرأ من جدول settings:
 *   - schema_cache_enabled: تفعيل/تعطيل (افتراضي: مفعّل)
 *   - schema_cache_ttl: مدة الصلاحية بالثواني (افتراضي: 86400 = 24 ساعة)
 *
 * الاستخدام:
 *   SchemaCache::remember('products_columns', fn() => fetchAll("SHOW COLUMNS FROM products"));
 *   SchemaCache::forget('products_columns');
 *   SchemaCache::clear();
 *   SchemaCache::getStats();
 */

class SchemaCache
{
    /** @var string مسار مجلد التخزين المؤقت */
    private string $cacheDir;

    /** @var bool هل الكاش مفعّل؟ */
    private bool $enabled;

    /** @var int مدة الصلاحية بالثواني */
    private int $ttl;

    /** @var ?self نسخة واحدة (Singleton) */
    private static ?self $instance = null;

    /**
     * إنشاء نسخة — خاص لضمان Singleton
     */
    private function __construct()
    {
        $this->cacheDir = __DIR__ . '/../cache/schema/';

        // قراءة الإعدادات من قاعدة البيانات
        $this->enabled = true;
        $this->ttl = 86400; // 24 ساعة افتراضياً

        try {
            if (function_exists('getSetting')) {
                $this->enabled = (getSetting('schema_cache_enabled', '1') === '1');
                $this->ttl = max(60, intval(getSetting('schema_cache_ttl', '86400')));
            }
        } catch (\Throwable $e) {
            // في حالة عدم توفر قاعدة البيانات — استخدام القيم الافتراضية
        }

        // إنشاء المجلد تلقائياً
        if ($this->enabled && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }

        // حماية المجلد
        if ($this->enabled) {
            $htaccess = $this->cacheDir . '.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
        }
    }

    private function __clone() {}

    /**
     * الحصول على النسخة الوحيدة
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * إعادة تعيين النسخة (عند تغيير الإعدادات)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * جلب قيمة من الكاش، أو تنفيذ callback وتخزين النتيجة
     *
     * @param string $key مفتاح التخزين
     * @param callable $callback دالة تُنفَّذ عند عدم وجود الكاش
     * @return mixed القيمة المخزنة أو النتيجة الجديدة
     */
    public static function remember(string $key, callable $callback): mixed
    {
        $instance = self::getInstance();

        if (!$instance->enabled) {
            return $callback();
        }

        $cached = $instance->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $instance->set($key, $value);

        return $value;
    }

    /**
     * جلب قيمة من التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @param mixed $default القيمة الافتراضية
     * @return mixed القيمة المخزنة أو الافتراضية
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled) {
            return $default;
        }

        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return $default;
        }

        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return $default;
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return $default;
        }

        $content = '';
        while (!feof($handle)) {
            $content .= fread($handle, 8192);
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            @unlink($filePath);
            return $default;
        }

        // التحقق من انتهاء الصلاحية
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            @unlink($filePath);
            return $default;
        }

        return $data['value'];
    }

    /**
     * تخزين قيمة في التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @param mixed $value القيمة
     * @param int $ttl مدة الصلاحية (0 = استخدام الـ TTL الافتراضي)
     * @return bool نجاح العملية
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($ttl === 0) {
            $ttl = $this->ttl;
        }

        $filePath = $this->getFilePath($key);
        $expires = ($ttl > 0) ? (time() + $ttl) : 0;

        $serialized = json_encode([
            'expires' => $expires,
            'value'   => $value,
            'created' => time(),
        ], JSON_UNESCAPED_UNICODE);

        $handle = @fopen($filePath, 'c');
        if (!$handle) {
            return false;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        ftruncate($handle, 0);
        rewind($handle);
        $written = fwrite($handle, $serialized);
        flock($handle, LOCK_UN);
        fclose($handle);

        @chmod($filePath, 0644);

        return $written !== false;
    }

    /**
     * حذف مفتاح من الكاش
     *
     * @param string $key مفتاح التخزين
     * @return bool
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true;
    }

    /**
     * حذف مفتاح (نسخة ثابتة)
     *
     * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        return self::getInstance()->delete($key);
    }

    /**
     * حذف جميع ملفات الكاش
     *
     * @return int عدد الملفات المحذوفة
     */
    public function clear(): int
    {
        $deleted = 0;
        $pattern = $this->cacheDir . '*.cache';
        $files = glob($pattern);

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (basename($file) === '.htaccess') {
                continue;
            }
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * حذف جميع ملفات الكاش (نسخة ثابتة)
     *
     * @return int
     */
    public static function flush(): int
    {
        return self::getInstance()->clear();
    }

    /**
     * الحصول على معلومات إحصائية عن الكاش
     *
     * @return array
     */
    public function getStats(): array
    {
        $totalFiles = 0;
        $totalSize = 0;
        $expiredFiles = 0;
        $keys = [];

        $pattern = $this->cacheDir . '*.cache';
        $files = glob($pattern);

        if ($files !== false) {
            foreach ($files as $file) {
                if (basename($file) === '.htaccess') {
                    continue;
                }
                $totalFiles++;
                $totalSize += filesize($file);

                // محاولة قراءة المفتاح الأصلي
                $handle = @fopen($file, 'r');
                if ($handle && flock($handle, LOCK_SH)) {
                    $content = stream_get_contents($handle);
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    $data = @json_decode($content, true);
                    if (is_array($data) && isset($data['expires'])) {
                        $age = time() - ($data['created'] ?? time());
                        $remaining = ($data['expires'] > 0) ? max(0, $data['expires'] - time()) : -1;
                        $keys[basename($file)] = [
                            'age_seconds' => $age,
                            'remaining_seconds' => $remaining,
                            'size' => filesize($file),
                        ];
                        if ($data['expires'] !== 0 && $data['expires'] < time()) {
                            $expiredFiles++;
                        }
                    }
                }
            }
        }

        return [
            'enabled'       => $this->enabled,
            'ttl'           => $this->ttl,
            'total_files'   => $totalFiles,
            'total_size'    => $totalSize,
            'total_size_kb' => round($totalSize / 1024, 1),
            'expired_files' => $expiredFiles,
            'cache_dir'     => $this->cacheDir,
            'keys'          => $keys,
        ];
    }

    /**
     * هل الكاش مفعّل؟
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * الحصول على TTL الحالي
     */
    public function getTTL(): int
    {
        return $this->ttl;
    }

    /**
     * الحصول على اسم الملف بناءً على المفتاح
     */
    private function getFilePath(string $key): string
    {
        // نحافظ على اسم المفتاح مقروءاً لتسهيل التشخيص
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cacheDir . $safeKey . '.cache';
    }
}
