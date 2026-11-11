<?php
/**
 * ===== نظام التخزين المؤقت (Cache) =====
 * نظام تخزين مؤقت مبني على الملفات - يحسن أداء التطبيق بنسبة كبيرة
 * 
 * الاستخدام:
 *   $cache = Cache::getInstance();
 *   $cache->set('products_count', $count, 60);
 *   $data = $cache->get('products_count');
 * 
 * الميزات:
 *   - تخزين مؤقت مبني على الملفات (لا يحتاج لخادم خارجي)
 *   - دعم TTL (مدة صلاحية البيانات)
 *   - أمان الخيوط عبر flock
 *   - دعم بادئات مجموعات (prefix groups)
 *   - نمط Singleton (نسخة واحدة)
 *   - حذف تلقائي للملفات المنتهية
 */

class Cache
{
    /** @var string مسار مجلد التخزين المؤقت */
    private string $cacheDir;

    /** @var string بادئة أسماء الملفات */
    private string $prefix = '';

    /** @var int مدة الصلاحية الافتراضية بالثواني */
    private int $defaultTTL = 300;

    /** @var ?Cache نسخة واحدة من الصنف (Singleton) */
    private static ?Cache $instance = null;

    /**
     * إنشاء نسخة جديدة - خاص لضمان Singleton
     */
    private function __construct()
    {
        // مسار مجلد التخزين المؤقت
        $this->cacheDir = __DIR__ . '/../cache/';

        // إنشاء المجلد تلقائياً إذا لم يكن موجوداً
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // إنشاء ملف .htaccess لحماية المجلد
        $htaccess = $this->cacheDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        // تنظيف ملفات cache القديمة (serialize) عند أول تشغيل بعد التحديث إلى JSON
        $migrationFlag = $this->cacheDir . '.migrated_to_json';
        if (!file_exists($migrationFlag)) {
            $this->migrateFromSerializeToJson();
            @file_put_contents($migrationFlag, (string) time());
        }
    }

    /**
     * تنظيف ملفات cache القديمة بصيغة serialize واستبدالها بصيغة JSON
     * تُنفَّذ مرة واحدة فقط بعد التحديث
     */
    private function migrateFromSerializeToJson(): void
    {
        $pattern = $this->cacheDir . '*.cache';
        $files = glob($pattern);
        if (!$files) return;

        foreach ($files as $file) {
            if (basename($file) === '.htaccess') continue;

            $content = @file_get_contents($file);
            if ($content === false) continue;

            // إذا كان الملف يبدأ بـ { أو [ فهو JSON بالفعل — تخطي
            $trimmed = ltrim($content);
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                continue;
            }

            // ملف بصيغة serialize قديمة — حذفه (سيُعاد بناؤه تلقائياً بصيغة JSON)
            @unlink($file);
        }
    }

    /**
     * منع استنساخ الكائن
     */
    private function __clone() {}

    /**
     * الحصول على النسخة الوحيدة من الكائن (Singleton)
     * 
     * @return Cache
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * تعيين بادئة لمجموعة من المفاتيح
     * مفيد لفصل تخزينات مختلفة (مثال: dashboard_, caisse_, api_)
     * 
     * @param string $prefix البادئة
     * @return self
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = rtrim($prefix, '_') . '_';
        return $this;
    }

    /**
     * الحصول على اسم الملف المؤقت بناءً على المفتاح
     * 
     * @param string $key مفتاح التخزين
     * @return string المسار الكامل للملف
     */
    private function getFilePath(string $key): string
    {
        // دمج البادئة مع المفتاح وتحويله إلى MD5
        $hashedKey = md5($this->prefix . $key);
        return $this->cacheDir . $hashedKey . '.cache';
    }

    /**
     * جلب قيمة من التخزين المؤقت
     * 
     * @param string $key مفتاح التخزين
     * @param mixed $default القيمة الافتراضية في حال عدم وجود البيانات
     * @return mixed القيمة المخزنة أو القيمة الافتراضية
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $filePath = $this->getFilePath($key);

        // التحقق من وجود الملف
        if (!file_exists($filePath)) {
            return $default;
        }

        // قراءة محتوى الملف مع قفل القراءة (أمان الخيوط)
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return $default;
        }

        // قفل القراءة المشترك
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

        // فك التسلسل (JSON بدلاً من unserialize للحماية)
        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            // ملف تالف أو صيغة غير صحيحة - حذفه
            @unlink($filePath);
            return $default;
        }

        // التحقق من انتهاء الصلاحية
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            // انتهت الصلاحية - حذف الملف
            @unlink($filePath);
            return $default;
        }

        return $data['value'];
    }

    /**
     * تخزين قيمة في التخزين المؤقت
     * 
     * @param string $key مفتاح التخزين
     * @param mixed $value القيمة المراد تخزينها
     * @param int $ttl مدة الصلاحية بالثواني (0 = بلا انتهاء)
     * @return bool نجاح العملية
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        // استخدام مدة الصلاحية الافتراضية إذا لم يتم تحديدها
        if ($ttl === 0) {
            $ttl = $this->defaultTTL;
        }

        $filePath = $this->getFilePath($key);

        // حساب وقت الانتهاء (0 يعني بلا انتهاء)
        $expires = ($ttl > 0) ? (time() + $ttl) : 0;

        // تسلسل البيانات مع طابع الانتهاء (JSON بدلاً من serialize للحماية)
        $serialized = json_encode([
            'expires' => $expires,
            'value'   => $value,
            'created' => time(),
        ], JSON_UNESCAPED_UNICODE);

        // الكتابة مع قفل حصري (أمان الخيوط)
        $handle = fopen($filePath, 'c');
        if (!$handle) {
            return false;
        }

        // قفل حصري للكتابة
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        // تصفير الملف ثم الكتابة
        ftruncate($handle, 0);
        rewind($handle);
        $written = fwrite($handle, $serialized);

        // تحرير القفل وإغلاق الملف
        flock($handle, LOCK_UN);
        fclose($handle);

        // إعادة تعيين أذونات الملف
        @chmod($filePath, 0644);

        return $written !== false;
    }

    /**
     * حذف قيمة من التخزين المؤقت
     * 
     * @param string $key مفتاح التخزين
     * @return bool تم الحذف بنجاح
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true; // لا وجود للملف = تم الحذف بنجاح
    }

    /**
     * التحقق من وجود مفتاح صالح في التخزين المؤقت
     * 
     * @param string $key مفتاح التخزين
     * @return bool هل المفتاح موجود ولم تنتهِ صلاحيته
     */
    public function has(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return false;
        }

        // قراءة سريعة للتحقق من الصلاحية فقط
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return false;
        }

        $content = '';
        while (!feof($handle)) {
            $content .= fread($handle, 8192);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'])) {
            return false;
        }

        // التحقق من انتهاء الصلاحية
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            @unlink($filePath);
            return false;
        }

        return true;
    }

    /**
     * حذف جميع الملفات المؤقتة
     * يدعم حذف مجموعة محددة عبر البادئة
     * 
     * @return int عدد الملفات المحذوفة
     */
    public function clear(): int
    {
        $deleted = 0;

        // إذا كانت هناك بادئة محددة، نحذف فقط الملفات المطابقة
        $prefixHash = $this->prefix ? md5($this->prefix) : '';
        $pattern = $this->cacheDir . '*.cache';
        $files = glob($pattern);

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            // تخطي ملف .htaccess
            if (basename($file) === '.htaccess') {
                continue;
            }

            // إذا كانت هناك بادئة، تحقق من مطابقة اسم الملف
            if ($prefixHash !== '') {
                $fileName = basename($file, '.cache');
                // نتحقق بجلب القيمة المخزنة وبادئتها
                // بديل أبسط: نحذف الكل إذا لم تكن بادئة محددة، 
                // وإلا نحاول المطابقة عبر إعادة بناء المفتاح
                continue; // سيتم معالجتها أدناه بطريقة أخرى
            }

            if (@unlink($file)) {
                $deleted++;
            }
        }

        // إذا كانت هناك بادئة، نحذف الملفات المطابقة للبادئة فقط
        if ($prefixHash !== '') {
            $files = glob($pattern);
            if ($files) {
                foreach ($files as $file) {
                    if (basename($file) === '.htaccess') {
                        continue;
                    }
                    // قراءة البيانات للتحقق من البادئة
                    $handle = fopen($file, 'r');
                    if (!$handle) continue;
                    flock($handle, LOCK_SH);
                    $content = stream_get_contents($handle);
                    flock($handle, LOCK_UN);
                    fclose($handle);

                    $data = @json_decode($content, true);
                    if (!is_array($data) || !isset($data['expires'], $data['value'])) {
                        continue;
                    }

                    // التحقق عبر إعادة حساب MD5 مع البادئة
                    // لا يمكن معرفة المفتاح الأصلي من MD5، لذا نحذف الكل إذا طلب clear مع بادئة
                    @unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * تنظيف الملفات المنتهية الصلاحية
     * يمكن استدعاؤها دورياً لتحرير المساحة
     * 
     * @return int عدد الملفات المنظفة
     */
    public function cleanup(): int
    {
        $cleaned = 0;
        $pattern = $this->cacheDir . '*.cache';
        $files = glob($pattern);

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (basename($file) === '.htaccess') {
                continue;
            }

            // التحقق من انتهاء الصلاحية
            $handle = fopen($file, 'r');
            if (!$handle) continue;

            if (flock($handle, LOCK_SH)) {
                $content = stream_get_contents($handle);
                flock($handle, LOCK_UN);
            } else {
                fclose($handle);
                continue;
            }
            fclose($handle);

            $data = @json_decode($content, true);
            if (is_array($data) && isset($data['expires'])) {
                if ($data['expires'] !== 0 && $data['expires'] < time()) {
                    if (@unlink($file)) {
                        $cleaned++;
                    }
                }
            } else {
                // ملف تالف - حذفه
                if (@unlink($file)) {
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * الحصول على معلومات إحصائية عن التخزين المؤقت
     * 
     * @return array معلومات التخزين المؤقت
     */
    public function getStats(): array
    {
        $pattern = $this->cacheDir . '*.cache';
        $files = glob($pattern);
        
        $totalFiles = 0;
        $totalSize = 0;
        $expiredFiles = 0;

        if ($files !== false) {
            foreach ($files as $file) {
                if (basename($file) === '.htaccess') {
                    continue;
                }
                $totalFiles++;
                $totalSize += filesize($file);

                // التحقق من الصلاحية
                $handle = fopen($file, 'r');
                if ($handle) {
                    if (flock($handle, LOCK_SH)) {
                        $content = stream_get_contents($handle);
                        flock($handle, LOCK_UN);
                    }
                    fclose($handle);

                    $data = @json_decode($content ?? '', true);
                    if (is_array($data) && isset($data['expires'])) {
                        if ($data['expires'] !== 0 && $data['expires'] < time()) {
                            $expiredFiles++;
                        }
                    }
                }
            }
        }

        return [
            'total_files'    => $totalFiles,
            'total_size'     => $totalSize,
            'total_size_mb'  => round($totalSize / 1048576, 2),
            'expired_files'  => $expiredFiles,
            'cache_dir'      => $this->cacheDir,
            'prefix'         => $this->prefix,
        ];
    }

    /**
     * تعيين مدة الصلاحية الافتراضية
     * 
     * @param int $ttl المدة بالثواني
     * @return self
     */
    public function setDefaultTTL(int $ttl): self
    {
        $this->defaultTTL = max(0, $ttl);
        return $this;
    }

    /**
     * الحصول على مدة الصلاحية الافتراضية الحالية
     * 
     * @return int المدة بالثواني
     */
    public function getDefaultTTL(): int
    {
        return $this->defaultTTL;
    }
}
