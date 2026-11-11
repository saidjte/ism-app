<?php
/**
 * ===== اختبارات خدمة المنتجات (ProductService) =====
 * يغطي:
 * - التحقق من صحة بيانات المنتج (validateProductData)
 * - توليد كود المنتج التلقائي (generateProductCode)
 * - إنشاء المنتجات مع الدفعات وتواريخ الانتهاء
 * - تحديث المنتجات وتسجيل تغييرات الأسعار
 * - حذف المنتجات مع التحقق من العمليات المرتبطة
 * - تحويل المخزون بين الوحدات
 * - جرد المخزون
 */

class ProductServiceTest extends TestCase
{
    private static ?PDO $sqlite = null;

    /**
     * إعداد قاعدة بيانات SQLite في الذاكرة قبل كل اختبار
     */
    public function setUp(): void
    {
        // إنشاء اتصال SQLite في الذاكرة
        self::$sqlite = new PDO('sqlite::memory:');
        self::$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // إنشاء الجداول المطلوبة
        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            purchase_price REAL NOT NULL DEFAULT 0,
            selling_price REAL NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT '',
            purchase_unit TEXT DEFAULT NULL,
            purchase_unit_factor REAL NOT NULL DEFAULT 1,
            category_id INTEGER DEFAULT NULL,
            quantity REAL NOT NULL DEFAULT 0,
            min_quantity REAL NOT NULL DEFAULT 0,
            has_expiry INTEGER NOT NULL DEFAULT 0
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            color TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS additional_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            unit_name TEXT NOT NULL,
            conversion_factor REAL NOT NULL DEFAULT 1,
            selling_price REAL NOT NULL DEFAULT 0
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS purchase_additional_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            unit_name TEXT NOT NULL,
            conversion_factor REAL NOT NULL DEFAULT 1
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS purchase_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            purchase_id INTEGER DEFAULT NULL,
            original_quantity REAL NOT NULL DEFAULT 0,
            remaining_quantity REAL NOT NULL DEFAULT 0,
            purchase_price REAL NOT NULL DEFAULT 0,
            purchase_date TEXT DEFAULT NULL,
            expiry_date TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 0,
            selling_price REAL NOT NULL DEFAULT 0,
            purchase_price_at_sale REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            cost_total REAL NOT NULL DEFAULT 0,
            profit REAL NOT NULL DEFAULT 0,
            sale_date TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 0,
            purchase_price REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            purchase_date TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS price_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            old_purchase_price REAL DEFAULT NULL,
            new_purchase_price REAL DEFAULT NULL,
            old_selling_price REAL DEFAULT NULL,
            new_selling_price REAL DEFAULT NULL,
            change_reason TEXT DEFAULT NULL,
            changed_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS inventory_counts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            actual_quantity REAL NOT NULL DEFAULT 0,
            system_quantity REAL NOT NULL DEFAULT 0,
            difference REAL NOT NULL DEFAULT 0,
            count_date TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL
        )");

        // حقن اتصال SQLite في AppDatabase Singleton
        $this->injectSqliteConnection(self::$sqlite);
    }

    /**
     * تنظيف بعد كل اختبار
     */
    public function tearDown(): void
    {
        if (self::$sqlite !== null) {
            self::$sqlite->exec("DELETE FROM inventory_counts");
            self::$sqlite->exec("DELETE FROM price_history");
            self::$sqlite->exec("DELETE FROM sales");
            self::$sqlite->exec("DELETE FROM purchases");
            self::$sqlite->exec("DELETE FROM purchase_batches");
            self::$sqlite->exec("DELETE FROM additional_units");
            self::$sqlite->exec("DELETE FROM purchase_additional_units");
            self::$sqlite->exec("DELETE FROM products");
            self::$sqlite->exec("DELETE FROM categories");
        }
    }

    /**
     * حقن اتصال SQLite في AppDatabase
     */
    private function injectSqliteConnection(PDO $sqlite): void
    {
        $instance = AppDatabase::getInstance();

        // استخدام Reflection لحقن اتصال SQLite
        $connProp = new ReflectionProperty(AppDatabase::class, 'connection');
        $connProp->setAccessible(true);
        $connProp->setValue($instance, $sqlite);
    }

    // ========================================================================
    // اختبارات validateProductData (عبر Reflection - طريقة خاصة)
    // ========================================================================

    public function testValidateProductDataValidInput(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'باراسيتامول 500 ملغ',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 100,
        ];

        $result = $method->invoke(null, $data);
        $this->assertTrue($result);
    }

    public function testValidateProductDataMissingName(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertContains('اسم المنتج مطلوب', $result);
    }

    public function testValidateProductDataZeroPurchasePrice(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 0,
            'selling_price' => 75,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertContains('سعر الشراء يجب أن يكون أكبر من صفر', $result);
    }

    public function testValidateProductDataNegativeSellingPrice(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 50,
            'selling_price' => -10,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertContains('سعر البيع يجب أن يكون أكبر من صفر', $result);
    }

    public function testValidateProductDataMissingUnit(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => '',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertContains('وحدة القياس مطلوبة', $result);
    }

    public function testValidateProductDataNegativeQuantity(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => -5,
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertContains('الكمية لا يمكن أن تكون سالبة', $result);
    }

    public function testValidateProductDataDuplicateCode(): void
    {
        // إدراج منتج بكود موجود مسبقاً
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-001', 'منتج قديم', 10, 20, 'علبة', 50)");

        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج جديد',
            'code' => 'PRD-001',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertContains('كود المنتج موجود مسبقاً', $result);
    }

    public function testValidateProductDataDuplicateCodeExcludedOnUpdate(): void
    {
        // إدراج منتج
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-001', 'منتج قديم', 10, 20, 'علبة', 50)");

        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج محدث',
            'code' => 'PRD-001',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
        ];

        // عند التحديث مع excludeId = 1، لا يجب أن يُعتبر الكود مكرراً
        $result = $method->invoke(null, $data, 1);
        $this->assertTrue($result);
    }

    public function testValidateProductDataExpiryQtyMismatch(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 100,
            'has_expiry' => 1,
            'expiry_dates' => [
                ['date' => '2026-06-01', 'quantity' => 30],
                ['date' => '2026-12-01', 'quantity' => 40],
                // المجموع = 70 ≠ 100
            ],
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        // يجب أن يكون هناك خطأ بعدم تطابق الكميات
        $found = false;
        foreach ($result as $error) {
            if (str_contains($error, 'تواريخ الانتهاء') && str_contains($error, 'الكمية الإجمالية')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should detect expiry quantity mismatch');
    }

    public function testValidateProductDataExpiryQtyMatch(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 100,
            'has_expiry' => 1,
            'expiry_dates' => [
                ['date' => '2026-06-01', 'quantity' => 60],
                ['date' => '2026-12-01', 'quantity' => 40],
                // المجموع = 100 = الكمية
            ],
        ];

        $result = $method->invoke(null, $data);
        $this->assertTrue($result);
    }

    public function testValidateProductDataMultipleErrors(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => '',
            'purchase_price' => 0,
            'selling_price' => -5,
            'unit' => '',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $this->assertGreaterThan(2, count($result), 'Should have multiple validation errors');
    }

    public function testValidateProductDataSellingBelowPurchase(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 2222,
            'selling_price' => 18,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
        $found = false;
        foreach ($result as $error) {
            if (str_contains($error, 'لا يمكن أن يكون أقل من سعر الشراء')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should detect selling price below purchase price');
    }

    public function testValidateProductDataSellingEqualPurchaseAllowed(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 100,
            'selling_price' => 100,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertTrue($result, 'Selling price equal to purchase price should be allowed');
    }

    public function testValidateProductDataSellingAbovePurchaseAllowed(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'validateProductData');
        $method->setAccessible(true);

        $data = [
            'name' => 'منتج اختبار',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
        ];

        $result = $method->invoke(null, $data);
        $this->assertTrue($result, 'Selling price above purchase price should be allowed');
    }

    public function testCreateProductSellingBelowPurchaseFails(): void
    {
        $data = [
            'name' => 'منتج خطأ سعر',
            'purchase_price' => 2222,
            'selling_price' => 18,
            'unit' => 'علبة',
            'quantity' => 10,
        ];

        $this->assertThrows(AppException::class, function() use ($data) {
            ProductService::create($data);
        }, 'Creating product with selling price below purchase price should throw AppException');
    }

    public function testUpdateProductSellingBelowPurchaseFails(): void
    {
        // إنشاء منتج صالح أولاً
        $createResult = ProductService::create([
            'name' => 'منتج أصلي',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);
        $this->assertTrue($createResult['success']);
        $id = $createResult['id'];
        $code = fetchOne("SELECT code FROM products WHERE id = ?", [$id])['code'];

        // محاولة التحديث بسعر بيع أقل من سعر الشراء
        $this->assertThrows(AppException::class, function() use ($id, $code) {
            ProductService::update($id, [
                'name' => 'منتج محدث',
                'purchase_price' => 2222,
                'selling_price' => 18,
                'unit' => 'علبة',
                'quantity' => 50,
                'code' => $code,
            ]);
        }, 'Updating product with selling price below purchase price should throw AppException');
    }

    // ========================================================================
    // اختبارات generateProductCode (عبر Reflection - طريقة خاصة)
    // ========================================================================

    public function testGenerateProductCodeFirstProduct(): void
    {
        $method = new ReflectionMethod(ProductService::class, 'generateProductCode');
        $method->setAccessible(true);

        $code = $method->invoke(null);
        $this->assertEqual('PRD-001', $code);
    }

    public function testGenerateProductCodeIncremental(): void
    {
        // إدراج منتجات موجودة
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-001', 'منتج 1', 10, 20, 'علبة', 50)");
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-002', 'منتج 2', 15, 25, 'علبة', 30)");

        $method = new ReflectionMethod(ProductService::class, 'generateProductCode');
        $method->setAccessible(true);

        $code = $method->invoke(null);
        $this->assertEqual('PRD-003', $code);
    }

    public function testGenerateProductCodeSkipsGaps(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-001', 'منتج 1', 10, 20, 'علبة', 50)");
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-005', 'منتج 5', 15, 25, 'علبة', 30)");

        $method = new ReflectionMethod(ProductService::class, 'generateProductCode');
        $method->setAccessible(true);

        $code = $method->invoke(null);
        // يجب أن يولد PRD-006 (أكبر رقم + 1)
        $this->assertEqual('PRD-006', $code);
    }

    // ========================================================================
    // اختبارات ProductService::create
    // ========================================================================

    public function testCreateProductSuccess(): void
    {
        $data = [
            'name' => 'أموكسيسيلين 250 ملغ',
            'purchase_price' => 120,
            'selling_price' => 180,
            'unit' => 'علبة',
            'quantity' => 50,
            'min_quantity' => 10,
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success'], 'Product creation should succeed');
        $this->assertNotNull($result['id']);
        $this->assertIsNumeric($result['id']);

        // التحقق من إدراج المنتج
        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$result['id']]);
        $this->assertNotNull($product);
        $this->assertEqual('أموكسيسيلين 250 ملغ', $product['name']);
        $this->assertEqual(120.0, floatval($product['purchase_price']));
        $this->assertEqual(180.0, floatval($product['selling_price']));
        $this->assertEqual(50.0, floatval($product['quantity']));
    }

    public function testCreateProductAutoCode(): void
    {
        $data = [
            'name' => 'منتج بدون كود',
            'purchase_price' => 30,
            'selling_price' => 50,
            'unit' => 'قطعة',
            'quantity' => 100,
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success']);

        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$result['id']]);
        $this->assertTrue(
            str_starts_with($product['code'], 'PRD-'),
            'Auto-generated code should start with PRD-'
        );
    }

    public function testCreateProductWithDuplicateCodeAutoIncrement(): void
    {
        // إدراج منتج بكود PRD-001
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-001', 'قديم', 10, 20, 'علبة', 50)");

        $data = [
            'name' => 'منتج جديد',
            'code' => 'PRD-001',
            'purchase_price' => 30,
            'selling_price' => 50,
            'unit' => 'قطعة',
            'quantity' => 100,
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success'], 'Should auto-increment code on duplicate');

        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$result['id']]);
        $this->assertNotEqual('PRD-001', $product['code'], 'Should have generated a different code');
    }

    public function testCreateProductWithExpiryDates(): void
    {
        $data = [
            'name' => 'مضاد حيوي',
            'purchase_price' => 200,
            'selling_price' => 300,
            'unit' => 'علبة',
            'quantity' => 100,
            'has_expiry' => 1,
            'expiry_dates' => [
                ['date' => '2026-06-30', 'quantity' => 60],
                ['date' => '2026-12-31', 'quantity' => 40],
            ],
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success']);

        // التحقق من إنشاء الدفعات
        $batches = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ? ORDER BY expiry_date", [$result['id']]);
        $this->assertEqual(2, count($batches));
        $this->assertEqual(60.0, floatval($batches[0]['original_quantity']));
        $this->assertEqual('2026-06-30', $batches[0]['expiry_date']);
        $this->assertEqual(40.0, floatval($batches[1]['original_quantity']));
        $this->assertEqual('2026-12-31', $batches[1]['expiry_date']);
    }

    public function testCreateProductWithoutExpirySingleBatch(): void
    {
        $data = [
            'name' => 'شاش طبي',
            'purchase_price' => 15,
            'selling_price' => 25,
            'unit' => 'لفة',
            'quantity' => 200,
            'has_expiry' => 0,
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success']);

        // يجب أن تُنشأ دفعة واحدة بدون تاريخ انتهاء
        $batches = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ?", [$result['id']]);
        $this->assertEqual(1, count($batches));
        $this->assertEqual(200.0, floatval($batches[0]['original_quantity']));
        $this->assertNull($batches[0]['expiry_date']);
    }

    public function testCreateProductWithAdditionalUnits(): void
    {
        $data = [
            'name' => 'فيتامين C',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
            'additional_units' => [
                ['name' => 'شريط', 'factor' => 10, 'price' => 15],
                ['name' => 'قرص', 'factor' => 100, 'price' => 1.5],
            ],
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success']);

        $units = fetchAll("SELECT * FROM additional_units WHERE product_id = ? ORDER BY conversion_factor", [$result['id']]);
        $this->assertEqual(2, count($units));
        $this->assertEqual('شريط', $units[0]['unit_name']);
        $this->assertEqual(10.0, floatval($units[0]['conversion_factor']));
    }

    public function testCreateProductValidationFailure(): void
    {
        $data = [
            'name' => '',
            'purchase_price' => 0,
            'selling_price' => 0,
            'unit' => '',
        ];

        $this->assertThrows(AppException::class, function() use ($data) {
            ProductService::create($data);
        }, 'Validation failure should throw AppException');
    }

    public function testCreateProductZeroQuantityNoBatch(): void
    {
        $data = [
            'name' => 'منتج بدون مخزون',
            'purchase_price' => 50,
            'selling_price' => 80,
            'unit' => 'قطعة',
            'quantity' => 0,
        ];

        $result = ProductService::create($data);
        $this->assertTrue($result['success']);

        // لا يجب أن تُنشأ أي دفعة للكمية صفر
        $batches = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ?", [$result['id']]);
        $this->assertEqual(0, count($batches));
    }

    // ========================================================================
    // اختبارات ProductService::update
    // ========================================================================

    public function testUpdateProductSuccess(): void
    {
        // إنشاء منتج أولاً
        $createResult = ProductService::create([
            'name' => 'منتج أصلي',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);
        $this->assertTrue($createResult['success']);
        $id = $createResult['id'];

        // تحديث المنتج
        $updateResult = ProductService::update($id, [
            'name' => 'منتج محدث',
            'purchase_price' => 120,
            'selling_price' => 180,
            'unit' => 'علبة',
            'quantity' => 50,
            'code' => fetchOne("SELECT code FROM products WHERE id = ?", [$id])['code'],
        ]);

        $this->assertTrue($updateResult['success']);

        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
        $this->assertEqual('منتج محدث', $product['name']);
        $this->assertEqual(120.0, floatval($product['purchase_price']));
        $this->assertEqual(180.0, floatval($product['selling_price']));
    }

    public function testUpdateProductPriceChangeLogsHistory(): void
    {
        $createResult = ProductService::create([
            'name' => 'منتج اختبار',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);
        $id = $createResult['id'];
        $code = fetchOne("SELECT code FROM products WHERE id = ?", [$id])['code'];

        // تحديث السعر
        ProductService::update($id, [
            'name' => 'منتج اختبار',
            'purchase_price' => 130,
            'selling_price' => 190,
            'unit' => 'علبة',
            'quantity' => 50,
            'code' => $code,
            'change_reason' => 'ارتفاع الأسعار',
        ]);

        // التحقق من تسجيل تغيير السعر
        $history = fetchAll("SELECT * FROM price_history WHERE product_id = ?", [$id]);
        $this->assertGreaterThan(0, count($history), 'Price change should be logged');
        $this->assertEqual(100.0, floatval($history[0]['old_purchase_price']));
        $this->assertEqual(130.0, floatval($history[0]['new_purchase_price']));
        $this->assertEqual(150.0, floatval($history[0]['old_selling_price']));
        $this->assertEqual(190.0, floatval($history[0]['new_selling_price']));
    }

    public function testUpdateProductNoPriceChangeNoLog(): void
    {
        $createResult = ProductService::create([
            'name' => 'منتج اختبار',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);
        $id = $createResult['id'];
        $code = fetchOne("SELECT code FROM products WHERE id = ?", [$id])['code'];

        // تحديث بدون تغيير السعر
        ProductService::update($id, [
            'name' => 'اسم جديد',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
            'code' => $code,
        ]);

        $history = fetchAll("SELECT * FROM price_history WHERE product_id = ?", [$id]);
        $this->assertEqual(0, count($history), 'No price change should not log history');
    }

    public function testUpdateProductQuantityIncreaseCreatesBatch(): void
    {
        $createResult = ProductService::create([
            'name' => 'منتج اختبار',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);
        $id = $createResult['id'];
        $code = fetchOne("SELECT code FROM products WHERE id = ?", [$id])['code'];

        // زيادة الكمية من 50 إلى 80
        ProductService::update($id, [
            'name' => 'منتج اختبار',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 80,
            'code' => $code,
        ]);

        // يجب أن تُنشأ دفعة جديدة بكمية 30 (الفرق)
        $batches = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ? ORDER BY id", [$id]);
        $totalBatchQty = array_sum(array_map(fn($b) => floatval($b['original_quantity']), $batches));
        $this->assertGreaterThanOrEqual(80.0, $totalBatchQty, 'Total batch quantity should reflect increased stock');
    }

    public function testUpdateNonExistentProductFails(): void
    {
        $this->assertThrows(AppException::class, function() {
            ProductService::update(99999, [
                'name' => 'غير موجود',
                'purchase_price' => 100,
                'selling_price' => 150,
                'unit' => 'علبة',
                'quantity' => 50,
            ]);
        }, 'Updating non-existent product should throw AppException');
    }

    // ========================================================================
    // اختبارات ProductService::delete
    // ========================================================================

    public function testDeleteProductSuccess(): void
    {
        $createResult = ProductService::create([
            'name' => 'منتج للحذف',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);
        $id = $createResult['id'];

        $deleteResult = ProductService::delete($id);
        $this->assertTrue($deleteResult['success']);

        // التحقق من حذف المنتج
        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
        $this->assertNull($product);
    }

    public function testDeleteProductWithSalesBlocked(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-DEL', 'منتج مع مبيعات', 10, 20, 'علبة', 50)");
        $id = self::$sqlite->lastInsertId();

        self::$sqlite->exec("INSERT INTO sales (product_id, quantity, selling_price, total, cost_total, profit, sale_date) VALUES ({$id}, 5, 20, 100, 50, 50, '2026-01-01')");

        $this->assertThrows(AppException::class, function() use ($id) {
            ProductService::delete($id);
        }, 'Deleting product with sales should throw AppException');
    }

    public function testDeleteProductWithPurchasesBlocked(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('PRD-DEL2', 'منتج مع مشتريات', 10, 20, 'علبة', 50)");
        $id = self::$sqlite->lastInsertId();

        self::$sqlite->exec("INSERT INTO purchases (product_id, quantity, purchase_price, total, purchase_date) VALUES ({$id}, 10, 10, 100, '2026-01-01')");

        $this->assertThrows(AppException::class, function() use ($id) {
            ProductService::delete($id);
        }, 'Deleting product with purchases should throw AppException');
    }

    public function testDeleteNonExistentProductFails(): void
    {
        $this->assertThrows(AppException::class, function() {
            ProductService::delete(99999);
        }, 'Deleting non-existent product should throw AppException');
    }

    public function testDeleteProductRemovesRelatedData(): void
    {
        $createResult = ProductService::create([
            'name' => 'منتج كامل',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
            'additional_units' => [
                ['name' => 'شريط', 'factor' => 10, 'price' => 15],
            ],
        ]);
        $id = $createResult['id'];

        // التحقق من وجود البيانات المرتبطة
        $units = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$id]);
        $this->assertGreaterThan(0, count($units));

        $batches = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ?", [$id]);
        $this->assertGreaterThan(0, count($batches));

        // حذف المنتج
        $deleteResult = ProductService::delete($id);
        $this->assertTrue($deleteResult['success']);

        // التحقق من حذف البيانات المرتبطة
        $unitsAfter = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$id]);
        $this->assertEqual(0, count($unitsAfter));

        $batchesAfter = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ?", [$id]);
        $this->assertEqual(0, count($batchesAfter));
    }

    // ========================================================================
    // اختبارات ProductService::getById
    // ========================================================================

    public function testGetByIdReturnsProductWithRelatedData(): void
    {
        $createResult = ProductService::create([
            'name' => 'منتج شامل',
            'purchase_price' => 100,
            'selling_price' => 150,
            'unit' => 'علبة',
            'quantity' => 50,
            'additional_units' => [
                ['name' => 'شريط', 'factor' => 10, 'price' => 15],
            ],
        ]);
        $id = $createResult['id'];

        $product = ProductService::getById($id);
        $this->assertNotNull($product);
        $this->assertEqual('منتج شامل', $product['name']);
        $this->assertArrayHasKey('additional_units', $product);
        $this->assertArrayHasKey('batches', $product);
        $this->assertArrayHasKey('price_history', $product);
    }

    public function testGetByIdReturnsNullForNonExistent(): void
    {
        $result = ProductService::getById(99999);
        $this->assertNull($result);
    }

    // ========================================================================
    // اختبارات ProductService::search
    // ========================================================================

    public function testSearchByName(): void
    {
        ProductService::create([
            'name' => 'باراسيتامول 500',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 100,
        ]);
        ProductService::create([
            'name' => 'أموكسيسيلين 250',
            'purchase_price' => 120,
            'selling_price' => 180,
            'unit' => 'علبة',
            'quantity' => 50,
        ]);

        $result = ProductService::search(['search' => 'باراسيتامول']);
        $this->assertEqual(1, count($result['products']));
        $this->assertStringContains('باراسيتامول', $result['products'][0]['name']);
    }

    public function testSearchByCode(): void
    {
        ProductService::create([
            'name' => 'منتج اختبار',
            'code' => 'MED-001',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 100,
        ]);

        $result = ProductService::search(['search' => 'MED-001']);
        $this->assertEqual(1, count($result['products']));
    }

    public function testSearchByStockStatusOut(): void
    {
        ProductService::create([
            'name' => 'منتج نفد',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 0,
        ]);
        ProductService::create([
            'name' => 'منتج متوفر',
            'purchase_price' => 50,
            'selling_price' => 75,
            'unit' => 'علبة',
            'quantity' => 100,
        ]);

        $result = ProductService::search(['stock_status' => 'out']);
        $this->assertEqual(1, count($result['products']));
        $this->assertEqual('منتج نفد', $result['products'][0]['name']);
    }

    public function testSearchPagination(): void
    {
        // إنشاء عدة منتجات
        for ($i = 1; $i <= 5; $i++) {
            ProductService::create([
                'name' => "منتج {$i}",
                'purchase_price' => 50 + $i,
                'selling_price' => 75 + $i,
                'unit' => 'علبة',
                'quantity' => $i * 10,
            ]);
        }

        $result = ProductService::search([], 1, 2);
        $this->assertEqual(2, count($result['products']));
        $this->assertEqual(5, $result['total']);
        $this->assertEqual(3, $result['total_pages']);
    }

    // ========================================================================
    // اختبارات SearchService (LIKE fallback على SQLite)
    // ========================================================================

    public function testSearchServiceBuildSearchWhere(): void
    {
        // اختبار بناء شرط WHERE باستخدام SearchService
        $result = SearchService::buildSearchWhere('باراسيتامول', 'p', false);

        // على SQLite، يجب أن يتراجع إلى LIKE
        $this->assertTrue(
            str_contains($result['where'], 'LIKE'),
            'SQLite should use LIKE fallback'
        );
        $this->assertGreaterThan(0, count($result['params']));
    }

    public function testSearchServiceShortTermFallback(): void
    {
        // المصطلحات القصيرة (< 3 أحرف) يجب أن تستخدم LIKE دائماً
        $result = SearchService::buildSearchWhere('AB', 'p', false);

        $this->assertTrue(
            str_contains($result['where'], 'LIKE'),
            'Short search terms should use LIKE'
        );
    }

    public function testSearchServiceRelevanceOrderBy(): void
    {
        // اختبار ترتيب النتائج بالصلة
        $result = SearchService::buildRelevanceOrderBy('باراسيتامول', 'p', false);

        $this->assertTrue(
            str_contains($result['order_by'], 'CASE') || str_contains($result['order_by'], 'relevance_score'),
            'Should have relevance ordering'
        );
    }

    public function testSearchServiceWithBarcode(): void
    {
        // اختبار البحث مع عمود الباركود
        $result = SearchService::buildSearchWhere('123456', 'p', true);

        // يجب أن يتضمن الباركود في البحث
        $this->assertTrue(
            str_contains($result['where'], 'barcode'),
            'Should include barcode in search'
        );
    }

    public function testSearchServiceEmptyTerm(): void
    {
        // اختبار مصطلح بحث فارغ
        $result = SearchService::buildSearchWhere('', 'p', false);

        $this->assertEqual('', $result['where']);
        $this->assertEqual([], $result['params']);
    }

    public function testSearchServiceResetCache(): void
    {
        // اختبار إعادة تعيين الكشف المخزن
        SearchService::resetCache();

        // يجب أن يعمل بدون أخطاء
        $result = SearchService::buildSearchWhere('اختبار', 'p', false);
        $this->assertTrue(strlen($result['where']) > 0);
    }

    // ========================================================================
    // اختبارات ProductService::transferStock
    // ========================================================================

    public function testTransferStockBasicConversion(): void
    {
        // إنشاء منتج
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('TS-001', 'منتج تحويل', 10, 20, 'علبة', 100)");

        // إنشاء وحدات إضافية: علبة = 10 أشرطة
        self::$sqlite->exec("INSERT INTO additional_units (product_id, unit_name, conversion_factor, selling_price) VALUES (1, 'شريط', 0.1, 2.5)");

        // تحويل من الوحدة الأساسية إلى الشريط: 5 علب × 10 = 50 شريط
        $result = ProductService::transferStock(1, 0, 1, 5);
        $this->assertTrue($result['success']);
        $this->assertEqual(50.0, $result['result']);
        $this->assertEqual(5.0, $result['base_quantity']);
    }

    public function testTransferStockInsufficientQuantity(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('TS-002', 'منتح محدود', 10, 20, 'علبة', 5)");

        // محاولة تحويل 10 علب لكن المتوفر فقط 5
        $this->assertThrows(AppException::class, function() {
            ProductService::transferStock(1, 0, 0, 10);
        }, 'Insufficient stock transfer should throw AppException');
    }

    public function testTransferStockInvalidFromUnit(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('TS-003', 'منتج وحدة', 10, 20, 'علبة', 50)");

        // وحدة مصدر غير موجودة
        $this->assertThrows(AppException::class, function() {
            ProductService::transferStock(1, 999, 0, 5);
        }, 'Invalid from unit should throw AppException');
    }

    public function testTransferStockNonExistentProduct(): void
    {
        $this->assertThrows(AppException::class, function() {
            ProductService::transferStock(99999, 0, 0, 5);
        }, 'Non-existent product transfer should throw AppException');
    }

    public function testTransferStockZeroOrNegativeQuantity(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('TS-004', 'منتج اختبار', 10, 20, 'علبة', 50)");

        $this->assertThrows(AppException::class, function() {
            ProductService::transferStock(1, 0, 0, 0);
        }, 'Zero quantity transfer should throw AppException');

        $this->assertThrows(AppException::class, function() {
            ProductService::transferStock(1, 0, 0, -5);
        }, 'Negative quantity transfer should throw AppException');
    }

    // ========================================================================
    // اختبارات ProductService::inventoryCount
    // ========================================================================

    public function testInventoryCountSuccess(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('IC-001', 'منتج جرد', 10, 20, 'علبة', 100)");
        $productId = self::$sqlite->lastInsertId();

        $result = ProductService::inventoryCount([
            ['product_id' => $productId, 'actual_quantity' => 95],
        ], '2026-05-14', 'جرد شهري');

        $this->assertTrue($result['success']);
        $this->assertEqual(1, $result['updated_count']);
        $this->assertEqual(95.0, $result['items'][0]['actual_quantity']);
        $this->assertEqual(-5.0, $result['items'][0]['difference']);

        // التحقق من تحديث كمية المنتج
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$productId]);
        $this->assertEqual(95.0, floatval($product['quantity']));
    }

    public function testInventoryCountNoDifferenceNoUpdate(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('IC-002', 'منتج مطابق', 10, 20, 'علبة', 100)");
        $productId = self::$sqlite->lastInsertId();

        $result = ProductService::inventoryCount([
            ['product_id' => $productId, 'actual_quantity' => 100],
        ], '2026-05-14');

        $this->assertTrue($result['success']);
        $this->assertEqual(0, $result['updated_count'], 'No update when quantities match');
    }

    public function testInventoryCountEmptyItems(): void
    {
        $this->assertThrows(AppException::class, function() {
            ProductService::inventoryCount([], '2026-05-14');
        }, 'Empty inventory count should throw AppException');
    }
}
