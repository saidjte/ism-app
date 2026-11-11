<?php
/**
 * اختبارات فئة Cache
 * - تخزين واسترجاع القيم
 * - TTL (انتهاء الصلاحية)
 * - حذف القيم
 * - التحقق من الوجود
 * - تنظيف الملفات المنتهية
 * - البادئات
 * - حماية ضد الملفات التالفة
 */

class CacheTest extends TestCase
{
    private string $testDir;
    private Cache $cache;
    private mixed $originalInstance;

    protected function setUp(): void
    {
        // إنشاء مجلد مؤقت
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cache_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        // حفظ النسخة الأصلية
        $reflection = new ReflectionProperty(Cache::class, 'instance');
        $this->originalInstance = $reflection->getValue();

        // إنشاء نسخة اختبارية باستخدام Reflection
        $reflection->setValue(null, null);

        // إنشاء نسخة جديدة
        $cache = Cache::getInstance();
        $dirProp = new ReflectionProperty(Cache::class, 'cacheDir');
        $dirProp->setValue($cache, $this->testDir);

        $this->cache = $cache;
    }

    protected function tearDown(): void
    {
        // تنظيف
        $this->removeTempDir($this->testDir);

        // إعادة النسخة الأصلية
        $reflection = new ReflectionProperty(Cache::class, 'instance');
        $reflection->setValue($this->originalInstance);
    }

    // === اختبارات أساسية ===

    public function testSetAndGet(): void
    {
        $this->cache->set('test_key', 'hello');
        $result = $this->cache->get('test_key');
        $this->assertEqual('hello', $result);
    }

    public function testGetDefaultWhenMissing(): void
    {
        $result = $this->cache->get('nonexistent', 'default_value');
        $this->assertEqual('default_value', $result);
    }

    public function testGetDefaultNullWhenMissing(): void
    {
        $result = $this->cache->get('nonexistent');
        $this->assertNull($result);
    }

    public function testSetAndGetInteger(): void
    {
        $this->cache->set('number', 42);
        $result = $this->cache->get('number');
        $this->assertEqual(42, $result);
    }

    public function testSetAndGetArray(): void
    {
        $data = ['name' => 'أحمد', 'age' => 30, 'active' => true];
        $this->cache->set('user', $data);
        $result = $this->cache->get('user');
        $this->assertEqual($data, $result);
    }

    public function testSetAndGetBoolean(): void
    {
        $this->cache->set('flag', true);
        $result = $this->cache->get('flag');
        $this->assertTrue($result);
    }

    public function testSetAndGetZero(): void
    {
        $this->cache->set('zero', 0);
        $result = $this->cache->get('zero');
        $this->assertEqual(0, $result);
    }

    public function testSetAndGetEmptyString(): void
    {
        $this->cache->set('empty', '');
        $result = $this->cache->get('empty');
        $this->assertEqual('', $result);
    }

    public function testSetReturnsTrue(): void
    {
        $result = $this->cache->set('key', 'value');
        $this->assertTrue($result);
    }

    // === اختبارات الحذف ===

    public function testDeleteExisting(): void
    {
        $this->cache->set('to_delete', 'value');
        $result = $this->cache->delete('to_delete');
        $this->assertTrue($result);
        $this->assertNull($this->cache->get('to_delete'));
    }

    public function testDeleteNonExisting(): void
    {
        $result = $this->cache->delete('nonexistent');
        $this->assertTrue($result);
    }

    // === اختبارات TTL ===

    public function testTTLNotExpiredImmediately(): void
    {
        $this->cache->set('short_lived', 'value', 10);
        $this->assertEqual('value', $this->cache->get('short_lived'));
    }

    public function testDefaultTTL(): void
    {
        $this->assertEqual(300, $this->cache->getDefaultTTL());
        $this->cache->setDefaultTTL(60);
        $this->assertEqual(60, $this->cache->getDefaultTTL());
    }

    public function testSetDefaultTTLLowerBound(): void
    {
        $this->cache->setDefaultTTL(-10);
        $this->assertEqual(0, $this->cache->getDefaultTTL());
    }

    // === اختبارات has() ===

    public function testHasReturnsTrueForExisting(): void
    {
        $this->cache->set('exists', 'value');
        $this->assertTrue($this->cache->has('exists'));
    }

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->cache->has('missing'));
    }

    // === اختبارات البادئات ===

    public function testPrefixIsolation(): void
    {
        $this->cache->setPrefix('');
        $this->cache->set('key', 'no_prefix');

        $this->cache->setPrefix('test_');
        $this->cache->set('key', 'with_prefix');

        $this->cache->setPrefix('');
        $noPrefix = $this->cache->get('key');

        $this->cache->setPrefix('test_');
        $withPrefix = $this->cache->get('key');

        $this->assertEqual('no_prefix', $noPrefix);
        $this->assertEqual('with_prefix', $withPrefix);
    }

    // === اختبارات الإحصائيات ===

    public function testStatsStructure(): void
    {
        $this->cache->set('test', 'value');
        $stats = $this->cache->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_files', $stats);
        $this->assertArrayHasKey('total_size', $stats);
        $this->assertArrayHasKey('total_size_mb', $stats);
        $this->assertArrayHasKey('expired_files', $stats);
        $this->assertArrayHasKey('cache_dir', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total_files']);
    }

    // === اختبار الكتابة فوق ===

    public function testOverwriteExisting(): void
    {
        $this->cache->set('key', 'first');
        $this->assertEqual('first', $this->cache->get('key'));

        $this->cache->set('key', 'second');
        $this->assertEqual('second', $this->cache->get('key'));
    }

    // === اختبار Arabic/Unicode ===

    public function testArabicContent(): void
    {
        $data = 'مرحبا بالعالم - نظام إدارة المخزون';
        $this->cache->set('arabic', $data);
        $result = $this->cache->get('arabic');
        $this->assertEqual($data, $result);
    }

    // === اختبار JSON format ===

    public function testCacheFileIsJson(): void
    {
        $this->cache->setPrefix('');
        $this->cache->set('json_test', 'value');

        // إيجاد الملف
        $files = glob($this->testDir . '*.cache');
        $this->assertGreaterThan(0, count($files));

        $content = file_get_contents($files[0]);
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('expires', $data);
        $this->assertArrayHasKey('value', $data);
        $this->assertArrayHasKey('created', $data);
    }

    // === اختبار ملف تالف ===

    public function testCorruptedFileReturnsDefault(): void
    {
        // الحصول على اسم الملف الفعلي الذي يستخدمه Cache (MD5 hash)
        // البادئة الافتراضية فارغة، لذا الـ hash = md5('corrupted_file')
        $key = 'corrupted_file';
        $prefixProp = new ReflectionProperty(Cache::class, 'prefix');
        $prefixProp->setAccessible(true);
        $currentPrefix = $prefixProp->getValue($this->cache);
        $hashedKey = md5($currentPrefix . $key);
        $corruptedFile = $this->testDir . $hashedKey . '.cache';
        file_put_contents($corruptedFile, 'not valid json{{{');

        $result = $this->cache->get($key, 'safe_default');
        $this->assertEqual('safe_default', $result);
    }

    // === اختبار التنظيف ===

    public function testCleanupHandlesCorruptedFiles(): void
    {
        $key = 'corrupted_cleanup';
        $prefixProp = new ReflectionProperty(Cache::class, 'prefix');
        $prefixProp->setAccessible(true);
        $currentPrefix = $prefixProp->getValue($this->cache);
        $hashedKey = md5($currentPrefix . $key);
        $corruptedFile = $this->testDir . $hashedKey . '.cache';
        file_put_contents($corruptedFile, 'not valid json');

        $cleaned = $this->cache->cleanup();
        $this->assertGreaterThanOrEqual(1, $cleaned);
    }
}
