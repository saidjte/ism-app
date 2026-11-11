<?php
/**
 * ===== محول التخزين المؤقت Redis (RedisCache) =====
 * نظام تخزين مؤقت مبني على Redis مع تراجع تلقائي إلى التخزين بالملفات
 * 
 * الميزات:
 *   - أداء عالي باستخدام Redis في الذاكرة
 *   - تراجع تلقائي إلى Cache (ملفات) عند عدم توفر Redis
 *   - دعم TTL (مدة صلاحية البيانات)
 *   - بادئات المفاتيح لعزل البيئات
 *   - معالجة أخطاء الاتصال بسلاسة
 *   - دعم جلسات Redis
 * 
 * الاستخدام:
 *   $cache = RedisCache::getInstance();
 *   $cache->set('products_count', 42, 300);   // تخزين لمدة 5 دقائق
 *   $data = $cache->get('products_count');      // جلب القيمة
 *   $cache->delete('products_count');           // حذف
 * 
 * إعدادات البيئة (.env):
 *   REDIS_HOST=127.0.0.1    — عنوان خادم Redis
 *   REDIS_PORT=6379         — منفذ Redis
 *   REDIS_PASSWORD=         — كلمة مرور Redis (فارغ = بدون كلمة مرور)
 *   REDIS_DB=0              — رقم قاعدة بيانات Redis
 */

// تحميل CacheInterface إذا لم تكن محملة
if (!interface_exists('CacheInterface')) {
    require_once __DIR__ . '/CacheInterface.php';
}

// تحميل فئة التخزين المؤقت بالملفات كتراجع
if (!class_exists('Cache')) {
    require_once __DIR__ . '/../Cache.php';
}

class RedisCache implements CacheInterface
{
    /** @var \Redis|null اتصال Redis */
    private ?\Redis $redis = null;

    /** @var Cache تخزين مؤقت بالملفات كبديل عند فشل Redis */
    private Cache $fallback;

    /** @var string بادئة المفاتيح لعزل البيئات */
    private string $prefix = 'isapp:';

    /** @var bool هل اتصال Redis نشط ويعمل؟ */
    private bool $connected = false;

    /** @var int مدة الصلاحية الافتراضية بالثواني */
    private int $defaultTTL = 300;

    /** @var self|null نسخة واحدة من الصنف (Singleton) */
    private static ?self $instance = null;

    /** @var int عدد محاولات إعادة الاتصال */
    private int $reconnectAttempts = 0;

    /** @var int الحد الأقصى لمحاولات إعادة الاتصال */
    private int $maxReconnectAttempts = 3;

    /** @var int وقت الانتظار بين محاولات إعادة الاتصال (بالملي ثانية) */
    private int $reconnectDelay = 1000;

    /**
     * إنشاء نسخة جديدة — خاص لضمان Singleton
     * يحاول الاتصال بـ Redis، ويتراجع إلى التخزين بالملفات عند الفشل
     */
    private function __construct()
    {
        // تهيئة التخزين البديل بالملفات
        $this->fallback = Cache::getInstance();

        // محاولة الاتصال بـ Redis
        $this->tryConnect();
    }

    /**
     * منع استنساخ الكائن
     */
    private function __clone() {}

    /**
     * الحصول على النسخة الوحيدة من الكائن (Singleton)
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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
        // محاولة جلب من Redis أولاً
        if ($this->connected && $this->redis !== null) {
            try {
                $prefixedKey = $this->prefixedKey($key);
                $value = $this->redis->get($prefixedKey);

                if ($value !== false) {
                    // فك التسلسل من JSON
                    $decoded = @json_decode($value, true);
                    if ($decoded !== null) {
                        return $decoded;
                    }
                    // إرجاع القيمة كما هي إذا لم تكن JSON
                    return $value;
                }

                // المفتاح غير موجود في Redis — إرجاع القيمة الافتراضية
                return $default;
            } catch (\RedisException $e) {
                // خطأ في Redis — التراجع إلى الملفات
                $this->handleRedisError($e);
            } catch (\Throwable $e) {
                // أي خطأ آخر — التراجع إلى الملفات
                $this->handleRedisError($e);
            }
        }

        // التراجع إلى التخزين بالملفات
        return $this->fallback->get($key, $default);
    }

    /**
     * تخزين قيمة في التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @param mixed $value القيمة المراد تخزينها
     * @param int $ttl مدة الصلاحية بالثواني (0 = القيمة الافتراضية)
     * @return bool نجاح العملية
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        // استخدام مدة الصلاحية الافتراضية إذا لم يتم تحديدها
        if ($ttl === 0) {
            $ttl = $this->defaultTTL;
        }

        $result = true;

        // محاولة التخزين في Redis
        if ($this->connected && $this->redis !== null) {
            try {
                $prefixedKey = $this->prefixedKey($key);
                $serialized = json_encode($value, JSON_UNESCAPED_UNICODE);

                if ($ttl > 0) {
                    $redisResult = $this->redis->setex($prefixedKey, $ttl, $serialized);
                } else {
                    // بدون انتهاء صلاحية
                    $redisResult = $this->redis->set($prefixedKey, $serialized);
                }

                if ($redisResult === false) {
                    $result = false;
                }
            } catch (\RedisException $e) {
                // خطأ في Redis — التراجع إلى الملفات
                $this->handleRedisError($e);
                $result = false;
            } catch (\Throwable $e) {
                // أي خطأ آخر — التراجع إلى الملفات
                $this->handleRedisError($e);
                $result = false;
            }
        }

        // دائماً نخزن في التخزين البديل أيضاً لضمان التوفر
        $fallbackResult = $this->fallback->set($key, $value, $ttl);

        return $this->connected ? $result : $fallbackResult;
    }

    /**
     * حذف قيمة من التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @return bool تم الحذف بنجاح
     */
    public function delete(string $key): bool
    {
        $result = true;

        // حذف من Redis
        if ($this->connected && $this->redis !== null) {
            try {
                $prefixedKey = $this->prefixedKey($key);
                $this->redis->del($prefixedKey);
            } catch (\RedisException $e) {
                $this->handleRedisError($e);
                $result = false;
            } catch (\Throwable $e) {
                $this->handleRedisError($e);
                $result = false;
            }
        }

        // حذف من التخزين البديل دائماً
        $fallbackResult = $this->fallback->delete($key);

        return $this->connected ? $result : $fallbackResult;
    }

    /**
     * التحقق من وجود مفتاح صالح في التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @return bool هل المفتاح موجود ولم تنتهِ صلاحيته
     */
    public function has(string $key): bool
    {
        // محاولة التحقق من Redis أولاً
        if ($this->connected && $this->redis !== null) {
            try {
                $prefixedKey = $this->prefixedKey($key);
                $exists = $this->redis->exists($prefixedKey);
                return $exists > 0;
            } catch (\RedisException $e) {
                $this->handleRedisError($e);
            } catch (\Throwable $e) {
                $this->handleRedisError($e);
            }
        }

        // التراجع إلى التخزين بالملفات
        return $this->fallback->has($key);
    }

    /**
     * حذف جميع القيم المخزنة مؤقتاً
     *
     * @return int عدد العناصر المحذوفة
     */
    public function clear(): int
    {
        $deleted = 0;

        // حذف من Redis (فقط المفاتيح التي تبدأ بالبادئة)
        if ($this->connected && $this->redis !== null) {
            try {
                $pattern = $this->prefix . '*';
                $keys = $this->redis->keys($pattern);
                if (!empty($keys) && is_array($keys)) {
                    $deleted = $this->redis->del($keys);
                }
            } catch (\RedisException $e) {
                $this->handleRedisError($e);
            } catch (\Throwable $e) {
                $this->handleRedisError($e);
            }
        }

        // حذف من التخزين البديل دائماً
        $fallbackDeleted = $this->fallback->clear();

        return $this->connected ? $deleted : $fallbackDeleted;
    }

    /**
     * تنظيف العناصر المنتهية الصلاحية
     * Redis يحذف تلقائياً العناصر المنتهية، لذا هذه الدالة تنظف فقط التخزين البديل
     *
     * @return int عدد العناصر المنظفة
     */
    public function cleanup(): int
    {
        // Redis يحذف العناصر المنتهية تلقائياً عبر TTL
        // لكن نحتاج لتنظيف التخزين البديل بالملفات
        return $this->fallback->cleanup();
    }

    /**
     * الحصول على معلومات إحصائية عن التخزين المؤقت
     *
     * @return array معلومات التخزين المؤقت
     */
    public function getStats(): array
    {
        $stats = [
            'driver'     => $this->connected ? 'redis' : 'file',
            'connected'  => $this->connected,
            'prefix'     => $this->prefix,
            'default_ttl'=> $this->defaultTTL,
        ];

        // معلومات Redis
        if ($this->connected && $this->redis !== null) {
            try {
                $info = $this->redis->info();
                $stats['redis'] = [
                    'version'        => $info['redis_version'] ?? 'غير معروف',
                    'used_memory'    => $info['used_memory_human'] ?? 'غير معروف',
                    'connected_clients' => $info['connected_clients'] ?? 0,
                    'total_keys'     => $this->redis->dbsize(),
                    'uptime_seconds' => $info['uptime_in_seconds'] ?? 0,
                ];
            } catch (\RedisException $e) {
                $stats['redis_error'] = $e->getMessage();
            } catch (\Throwable $e) {
                $stats['redis_error'] = $e->getMessage();
            }
        }

        // معلومات التخزين البديل
        $stats['fallback'] = $this->fallback->getStats();

        return $stats;
    }

    /**
     * هل Redis متصل ويعمل؟
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if (!$this->connected || $this->redis === null) {
            return false;
        }

        try {
            // فحص الاتصال بإرسال أمر PING
            $response = $this->redis->ping();
            return $response === true || $response === '+PONG' || $response === 'PONG';
        } catch (\Throwable $e) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * تهيئة جلسات Redis
     * يستبدل جلسات الملفات بجلسات Redis لأداء أفضل
     *
     * @return bool نجاح التهيئة
     */
    public function initSessionHandler(): bool
    {
        // التحقق من توفر Redis
        if (!$this->connected || $this->redis === null) {
            return false;
        }

        try {
            // الطريقة الأولى: استخدام phpredis session handler المدمج
            // هذا هو الأفضل أداءً لأنه مدمج في الإضافة
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = getenv('REDIS_PORT') ?: 6379;
            $password = getenv('REDIS_PASSWORD') ?: '';
            $db = getenv('REDIS_DB') ?: 0;

            $savePath = "tcp://{$host}:{$port}";
            if (!empty($password)) {
                $savePath .= "?auth={$password}";
            }
            if ($db > 0) {
                $savePath .= "&database={$db}";
            }
            $savePath .= "&prefix={$this->prefix}sess:";

            // تعيين إعدادات الجلسة لاستخدام Redis
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', $savePath);

            return true;
        } catch (\Throwable $e) {
            // فشل تهيئة جلسات Redis — استخدام الجلسات العادية
            return false;
        }
    }

    /**
     * محاولة إعادة الاتصال بـ Redis
     *
     * @return bool نجاح إعادة الاتصال
     */
    public function reconnect(): bool
    {
        // إعادة تعيين حالة الاتصال
        $this->connected = false;
        $this->redis = null;
        $this->reconnectAttempts = 0;

        return $this->tryConnect();
    }

    /**
     * الحصول على كائن Redis المباشر (للاستخدام المتقدم)
     * يرجع null إذا لم يكن Redis متصلاً
     *
     * @return \Redis|null
     */
    public function getRedis(): ?\Redis
    {
        return $this->redis;
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
     * تعيين بادئة المفاتيح
     *
     * @param string $prefix البادئة
     * @return self
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = rtrim($prefix, ':') . ':';
        return $this;
    }

    /**
     * إعادة تعيين النسخة الوحيدة (Singleton)
     * يُستخدم عند تغيير إعدادات Redis من صفحة الإعدادات
     * حتى يتم إعادة الاتصال بالإعدادات الجديدة
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            // إغلاق الاتصال الحالي إن وُجد
            try {
                if (self::$instance->redis !== null) {
                    self::$instance->redis->close();
                }
            } catch (\Throwable $e) {
                // تجاهل أخطاء الإغلاق
            }
            self::$instance = null;
        }
    }

    // ===== دوال خاصة =====

    /**
     * محاولة الاتصال بخادم Redis
     *
     * @return bool نجاح الاتصال
     */
    private function tryConnect(): bool
    {
        // التحقق من توفر إضافة phpredis
        if (!class_exists('\Redis')) {
            $this->connected = false;
            return false;
        }

        // قراءة إعدادات الاتصال من متغيرات البيئة
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $password = getenv('REDIS_PASSWORD') ?: '';
        $db = (int)(getenv('REDIS_DB') ?: 0);
        $timeout = 2.0; // مهلة الاتصال بالثواني

        try {
            $this->redis = new \Redis();

            // محاولة الاتصال
            $connected = @$this->redis->connect($host, $port, $timeout);

            if (!$connected) {
                $this->connected = false;
                $this->redis = null;
                return false;
            }

            // تعيين كلمة المرور إذا كانت محددة
            if (!empty($password)) {
                $authResult = $this->redis->auth($password);
                if (!$authResult) {
                    $this->connected = false;
                    $this->redis = null;
                    return false;
                }
            }

            // اختيار قاعدة البيانات
            if ($db > 0) {
                $this->redis->select($db);
            }

            // تعيين خيارات Redis
            $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);

            // فحص الاتصال
            $ping = $this->redis->ping();
            if ($ping === false) {
                $this->connected = false;
                $this->redis = null;
                return false;
            }

            // الاتصال ناجح
            $this->connected = true;
            $this->reconnectAttempts = 0;
            return true;

        } catch (\RedisException $e) {
            $this->connected = false;
            $this->redis = null;
            return false;
        } catch (\Throwable $e) {
            $this->connected = false;
            $this->redis = null;
            return false;
        }
    }

    /**
     * إضافة بادئة إلى المفتاح
     * ملاحظة: إذا تم تعيين OPT_PREFIX في Redis، لا نحتاج لإضافة البادئة يدوياً
     * لكن نحتفظ بهذه الدالة للمرونة
     *
     * @param string $key المفتاح الأصلي
     * @return string المفتاح مع البادئة
     */
    private function prefixedKey(string $key): string
    {
        // بما أننا عيّنا OPT_PREFIX في Redis، لا نحتاج لإضافة البادئة يدوياً
        // إضافة البادئة يدوياً ستؤدي إلى بادئة مزدوجة
        return $key;
    }

    /**
     * معالجة أخطاء Redis
     * تحاول إعادة الاتصال تلقائياً عند انقطاع الاتصال
     *
     * @param \Throwable $e الاستثناء الذي حدث
     */
    private function handleRedisError(\Throwable $e): void
    {
        // تعليم الاتصال بأنه غير متاح
        $this->connected = false;

        // محاولة إعادة الاتصال إذا لم نتجاوز الحد الأقصى
        if ($this->reconnectAttempts < $this->maxReconnectAttempts) {
            $this->reconnectAttempts++;

            // الانتظار قبل المحاولة
            if ($this->reconnectDelay > 0) {
                usleep($this->reconnectDelay * 1000);
            }

            $this->tryConnect();
        }

        // تسجيل الخطأ في سجل النظام إذا توفر
        if (class_exists('ErrorHandler')) {
            try {
                ErrorHandler::log([
                    'type'    => 'redis_error',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
            } catch (\Throwable $logError) {
                // تجاهل أخطاء التسجيل
            }
        }
    }
}
