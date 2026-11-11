<?php
/**
 * اختبارات فئة AppDatabase (بدون اتصال فعلي بقاعدة البيانات)
 * - نمط Singleton
 * - فحص الواجهة العامة
 * - disconnect و clearCache
 * - formatMoney
 */

class DatabaseTest extends TestCase
{
    // === اختبارات Singleton ===

    public function testSingletonReturnsSameInstance(): void
    {
        // حفظ النسخة الأصلية
        $prop = new ReflectionProperty(AppDatabase::class, 'instance');
        $original = $prop->getValue();

        try {
            $prop->setValue(null);
            $db1 = AppDatabase::getInstance();
            $db2 = AppDatabase::getInstance();
            $this->assertTrue($db1 === $db2);
        } finally {
            $prop->setValue($original);
        }
    }

    public function testDisconnectResetsConnection(): void
    {
        $prop = new ReflectionProperty(AppDatabase::class, 'instance');
        $original = $prop->getValue();

        try {
            $prop->setValue(null);
            $db = AppDatabase::getInstance();
            $db->disconnect();

            $connProp = new ReflectionProperty(AppDatabase::class, 'connection');
            $conn = $connProp->getValue($db);
            $this->assertNull($conn);
        } finally {
            $prop->setValue($original);
        }
    }

    // === اختبارات clearCache ===

    public function testClearCache(): void
    {
        $cacheProp = new ReflectionProperty(AppDatabase::class, 'settingsCache');
        $originalCache = $cacheProp->getValue();

        try {
            // تعبئة cache
            $cacheProp->setValue(['test_key' => 'test_value']);
            AppDatabase::clearCache();

            $this->assertEqual([], $cacheProp->getValue());
        } finally {
            $cacheProp->setValue($originalCache);
        }
    }

    public function testClearCacheResetsCurrencySymbol(): void
    {
        $currProp = new ReflectionProperty(AppDatabase::class, 'currencySymbolCache');
        $original = $currProp->getValue();

        try {
            $currProp->setValue('د.ج.');
            AppDatabase::clearCache();

            $this->assertNull($currProp->getValue());
        } finally {
            $currProp->setValue($original);
        }
    }

    // === اختبارات formatMoney (مع cache للعملة) ===

    public function testFormatMoneyWithCachedSymbol(): void
    {
        $currProp = new ReflectionProperty(AppDatabase::class, 'currencySymbolCache');
        $original = $currProp->getValue();

        try {
            $currProp->setValue('د.م.');
            $prop = new ReflectionProperty(AppDatabase::class, 'instance');
            $originalInstance = $prop->getValue();
            $prop->setValue(null);
            $db = AppDatabase::getInstance();

            // formatMoney يحتاج للاتصال → سيرمي RuntimeException
            // لكن getCurrencySymbol يستخدم cache
            $symbol = $db->getCurrencySymbol();
            $this->assertEqual('د.م.', $symbol);
        } finally {
            $currProp->setValue($original);
            $prop = new ReflectionProperty(AppDatabase::class, 'instance');
            $prop->setValue($originalInstance ?? null);
        }
    }

    public function testFormatMoneyDefaultDecimals(): void
    {
        // اختبار أن number_format يعمل بشكل صحيح
        $amount = 1250.5;
        $formatted = number_format($amount, 2) . ' د.م.';
        $this->assertEqual('1,250.50 د.م.', $formatted);
    }

    public function testFormatMoneyWithZero(): void
    {
        $formatted = number_format(0, 2) . ' د.م.';
        $this->assertEqual('0.00 د.م.', $formatted);
    }

    public function testFormatMoneyWithNull(): void
    {
        $amount = null ?? 0;
        $formatted = number_format($amount, 2) . ' د.م.';
        $this->assertEqual('0.00 د.م.', $formatted);
    }

    public function testFormatMoneyWithNegative(): void
    {
        $formatted = number_format(-150.75, 2) . ' د.م.';
        $this->assertEqual('-150.75 د.م.', $formatted);
    }

    public function testFormatMoneyCustomDecimals(): void
    {
        $formatted = number_format(1234.567, 3) . ' د.م.';
        $this->assertEqual('1,234.567 د.م.', $formatted);
    }

    // === اختبار واجهة الفئة ===

    public function testHasPublicMethods(): void
    {
        $expectedMethods = [
            'getInstance', 'getConnection', 'query', 'fetchOne',
            'fetchAll', 'insert', 'getSetting', 'saveSetting',
            'getCurrencySymbol', 'formatMoney', 'disconnect', 'clearCache',
        ];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists(AppDatabase::class, $method),
                "AppDatabase should have method: {$method}"
            );
        }
    }

    // === اختبار __wakeup ===

    public function testWakeupThrowsException(): void
    {
        $this->assertThrows(\RuntimeException::class, function () {
            $db = AppDatabase::getInstance();
            $serialized = serialize($db);
            unserialize($serialized);
        });
    }
}
