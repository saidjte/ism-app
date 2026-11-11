<?php
/**
 * ===== اختبارات خدمة المبيعات (SaleService) =====
 * يغطي:
 * - خصم الدفعات بنظام FIFO (أول ما دخل أول ما خرج)
 * - خصم الدفعات بنظام FEFO (أول ما ينتهي أول ما خرج)
 * - تسجيل عمليات البيع مع حساب التكلفة من الدفعات
 * - عكس عملية البيع (حذف) مع إعادة الكميات والدفعات
 * - التحقق من صحة بيانات البيع
 * - حساب الأرباح
 * - منع بيع المنتجات المنتهية الصلاحية
 */

class SaleServiceTest extends TestCase
{
    private static ?PDO $sqlite = null;

    public function setUp(): void
    {
        self::$sqlite = new PDO('sqlite::memory:');
        self::$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // إنشاء الجداول
        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            purchase_price REAL NOT NULL DEFAULT 0,
            selling_price REAL NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT '',
            quantity REAL NOT NULL DEFAULT 0,
            min_quantity REAL NOT NULL DEFAULT 0,
            has_expiry INTEGER NOT NULL DEFAULT 0,
            purchase_unit TEXT DEFAULT NULL,
            purchase_unit_factor REAL NOT NULL DEFAULT 1,
            category_id INTEGER DEFAULT NULL
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

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS sale_batch_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            batch_id INTEGER NOT NULL,
            quantity_taken REAL NOT NULL DEFAULT 0,
            purchase_price REAL NOT NULL DEFAULT 0,
            cost_total REAL NOT NULL DEFAULT 0
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 0,
            purchase_price REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            tax_rate REAL NOT NULL DEFAULT 0,
            tax_amount REAL NOT NULL DEFAULT 0,
            tax_name TEXT DEFAULT NULL,
            purchase_date TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            amount REAL NOT NULL DEFAULT 0,
            expense_date TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT DEFAULT '',
            description TEXT DEFAULT NULL,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )");

        $this->injectSqliteConnection(self::$sqlite);
    }

    public function tearDown(): void
    {
        if (self::$sqlite !== null) {
            self::$sqlite->exec("DELETE FROM sale_batch_items");
            self::$sqlite->exec("DELETE FROM sales");
            self::$sqlite->exec("DELETE FROM purchase_batches");
            self::$sqlite->exec("DELETE FROM purchases");
            self::$sqlite->exec("DELETE FROM products");
            self::$sqlite->exec("DELETE FROM expenses");
            self::$sqlite->exec("DELETE FROM system_settings");
        }
        // مسح ذاكرة الإعدادات المؤقتة بعد كل اختبار
        AppDatabase::clearCache();
    }

    private function injectSqliteConnection(PDO $sqlite): void
    {
        $instance = AppDatabase::getInstance();
        $connProp = new ReflectionProperty(AppDatabase::class, 'connection');
        $connProp->setAccessible(true);
        $connProp->setValue($instance, $sqlite);
    }

    /**
     * مساعد: إنشاء منتج مع دفعات شراء
     */
    private function createProductWithBatches(string $name, float $qty, float $purchasePrice, float $sellingPrice, array $batches = []): int
    {
        $hasExpiry = !empty($batches) ? 1 : 0;
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('T-" . uniqid() . "', '{$name}', {$purchasePrice}, {$sellingPrice}, 'علبة', {$qty}, {$hasExpiry})");
        $productId = intval(self::$sqlite->lastInsertId());

        if (empty($batches)) {
            self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$productId}, {$qty}, {$qty}, {$purchasePrice}, '2026-01-01')");
        } else {
            foreach ($batches as $batch) {
                $expiry = !empty($batch['expiry_date']) ? "'{$batch['expiry_date']}'" : 'NULL';
                self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$productId}, {$batch['quantity']}, {$batch['remaining']}, {$purchasePrice}, '{$batch['purchase_date']}', {$expiry})");
            }
        }

        return $productId;
    }

    // ========================================================================
    // اختبارات خصم الدفعات - FIFO (أول ما دخل أول ما خرج)
    // ========================================================================

    public function testDeductFromBatchesFIFOSingleBatch(): void
    {
        $pid = $this->createProductWithBatches('منتج FIFO', 100, 50, 75, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
        ]);

        $result = SaleService::deductFromBatches($pid, 30, 'fifo');
        $this->assertEqual(1, count($result));
        $this->assertEqual(30.0, $result[0]['quantity']);
        $this->assertEqual(50.0, $result[0]['cost_price']);
    }

    public function testDeductFromBatchesFIFOMultipleBatches(): void
    {
        $pid = $this->createProductWithBatches('منتج FIFO متعدد', 200, 0, 0, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-02-01', 'expiry_date' => null],
        ]);

        // خصم 150 وحدة - يجب أن يأخذ 100 من الدفعة الأولى + 50 من الثانية
        $result = SaleService::deductFromBatches($pid, 150, 'fifo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(100.0, $result[0]['quantity'], 'First batch should contribute 100');
        $this->assertEqual(50.0, $result[1]['quantity'], 'Second batch should contribute 50');
    }

    public function testDeductFromBatchesFIFOExactlyFirstBatch(): void
    {
        $pid = $this->createProductWithBatches('منتج FIFO مطابق', 200, 0, 0, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-02-01', 'expiry_date' => null],
        ]);

        // خصم 100 بالضبط - يجب أن يأخذ كل الدفعة الأولى فقط
        $result = SaleService::deductFromBatches($pid, 100, 'fifo');
        $this->assertEqual(1, count($result));
        $this->assertEqual(100.0, $result[0]['quantity']);
    }

    public function testDeductFromBatchesFIFOPartialFromSecondBatch(): void
    {
        $pid = $this->createProductWithBatches('منتج FIFO جزئي', 300, 0, 0, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
            ['quantity' => 200, 'remaining' => 200, 'purchase_date' => '2026-02-01', 'expiry_date' => null],
        ]);

        // خصم 250 - 100 من الأولى + 150 من الثانية
        $result = SaleService::deductFromBatches($pid, 250, 'fifo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(100.0, $result[0]['quantity']);
        $this->assertEqual(150.0, $result[1]['quantity']);
    }

    public function testDeductFromBatchesFIFOThreeBatches(): void
    {
        $pid = $this->createProductWithBatches('منتج FIFO ثلاثي', 300, 0, 0, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-02-01', 'expiry_date' => null],
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-03-01', 'expiry_date' => null],
        ]);

        // خصم 250 - 100 + 100 + 50
        $result = SaleService::deductFromBatches($pid, 250, 'fifo');
        $this->assertEqual(3, count($result));
        $this->assertEqual(100.0, $result[0]['quantity']);
        $this->assertEqual(100.0, $result[1]['quantity']);
        $this->assertEqual(50.0, $result[2]['quantity']);
    }

    // ========================================================================
    // اختبارات خصم الدفعات - FEFO (أول ما ينتهي أول ما خرج)
    // ========================================================================

    public function testDeductFromBatchesFEFOSingleBatch(): void
    {
        $pid = $this->createProductWithBatches('منتج FEFO', 100, 50, 75, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => '2026-12-31'],
        ]);

        $result = SaleService::deductFromBatches($pid, 30, 'fefo');
        $this->assertEqual(1, count($result));
        $this->assertEqual(30.0, $result[0]['quantity']);
    }

    public function testDeductFromBatchesFEFOPrioritizesExpiringFirst(): void
    {
        $pid = $this->createProductWithBatches('منتج FEFO أولوية', 200, 0, 0, [
            // دفعة تنتهي لاحقاً (مُشتراة أولاً)
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => '2026-12-31'],
            // دفعة تنتهي أولاً (مُشتراة لاحقاً)
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-02-01', 'expiry_date' => '2026-06-30'],
        ]);

        // خصم 50 - FEFO يجب أن يأخذ من الدفعة الأقرب انتهاءً أولاً (2026-06-30)
        $result = SaleService::deductFromBatches($pid, 50, 'fefo');
        $this->assertEqual(1, count($result));
        $this->assertEqual(50.0, $result[0]['quantity']);

        // التحقق من أن الدفعة المأخوذة هي الأقرب انتهاءً
        $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$result[0]['batch_id']]);
        $this->assertEqual('2026-06-30', $batch['expiry_date'], 'FEFO should take from earliest expiry first');
    }

    public function testDeductFromBatchesFEFOMultipleExpiryDates(): void
    {
        $pid = $this->createProductWithBatches('منتج FEFO متعدد', 300, 0, 0, [
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => '2026-12-31'],
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-15', 'expiry_date' => '2026-06-30'],
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-02-01', 'expiry_date' => '2026-03-31'],
        ]);

        // خصم 150 - يأخذ من الأقرب انتهاءً: 100 (مارس) + 50 (يونيو)
        $result = SaleService::deductFromBatches($pid, 150, 'fefo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(100.0, $result[0]['quantity']);

        // الدفعة الأولى يجب أن تكون الأقرب انتهاءً
        $batch1 = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$result[0]['batch_id']]);
        $this->assertEqual('2026-03-31', $batch1['expiry_date'], 'First deduction should be from earliest expiry');
    }

    public function testDeductFromBatchesFEFOBatchesWithoutExpiryLast(): void
    {
        $pid = $this->createProductWithBatches('منتج FEFO مختلط', 200, 0, 0, [
            // دفعة بدون تاريخ انتهاء
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
            // دفعة مع تاريخ انتهاء
            ['quantity' => 100, 'remaining' => 100, 'purchase_date' => '2026-01-15', 'expiry_date' => '2026-06-30'],
        ]);

        // خصم 50 - FEFO يجب أن يأخذ من الدفعة ذات تاريخ الانتهاء أولاً
        $result = SaleService::deductFromBatches($pid, 50, 'fefo');
        $this->assertEqual(1, count($result));

        $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$result[0]['batch_id']]);
        $this->assertEqual('2026-06-30', $batch['expiry_date'], 'FEFO should prioritize batches with expiry dates over those without');
    }

    // ========================================================================
    // اختبارات خصم الدفعات - حالات خاصة
    // ========================================================================

    public function testDeductFromBatchesInsufficientQuantity(): void
    {
        $pid = $this->createProductWithBatches('منتج محدود', 50, 0, 0, [
            ['quantity' => 50, 'remaining' => 50, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
        ]);

        // طلب 100 لكن المتوفر 50 فقط
        $result = SaleService::deductFromBatches($pid, 100, 'fifo');
        $this->assertEqual(0, count($result), 'Should return empty when batches insufficient');
    }

    public function testDeductFromBatchesZeroQuantity(): void
    {
        $result = SaleService::deductFromBatches(1, 0, 'fifo');
        $this->assertEqual(0, count($result));
    }

    public function testDeductFromBatchesNegativeQuantity(): void
    {
        $result = SaleService::deductFromBatches(1, -10, 'fifo');
        $this->assertEqual(0, count($result));
    }

    public function testDeductFromBatchesInvalidProductId(): void
    {
        $result = SaleService::deductFromBatches(0, 10, 'fifo');
        $this->assertEqual(0, count($result));

        $result2 = SaleService::deductFromBatches(-1, 10, 'fifo');
        $this->assertEqual(0, count($result2));
    }

    public function testDeductFromBatchesNoBatchesAvailable(): void
    {
        // منتج بدون أي دفعات
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity) VALUES ('NB-001', 'منتج بدون دفعات', 50, 75, 'علبة', 100)");
        $pid = intval(self::$sqlite->lastInsertId());

        $result = SaleService::deductFromBatches($pid, 10, 'fifo');
        $this->assertEqual(0, count($result));
    }

    public function testDeductFromBatchesAllBatchesConsumed(): void
    {
        // دفعات مستهلكة بالكامل
        $pid = $this->createProductWithBatches('منتج مستهلك', 0, 0, 0, [
            ['quantity' => 100, 'remaining' => 0, 'purchase_date' => '2026-01-01', 'expiry_date' => null],
        ]);

        $result = SaleService::deductFromBatches($pid, 10, 'fifo');
        $this->assertEqual(0, count($result));
    }

    public function testDeductFromBatchesDifferentCostPrices(): void
    {
        // دفعتان بسعري شراء مختلفين
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('DP-001', 'منتج أسعار مختلفة', 60, 80, 'علبة', 200, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 70, '2026-02-01')");

        // خصم 150 - 100 بسعر 50 + 50 بسعر 70
        $result = SaleService::deductFromBatches($pid, 150, 'fifo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(50.0, $result[0]['cost_price'], 'First batch cost should be 50');
        $this->assertEqual(70.0, $result[1]['cost_price'], 'Second batch cost should be 70');
        $this->assertEqual(100.0, $result[0]['quantity']);
        $this->assertEqual(50.0, $result[1]['quantity']);
    }

    // ========================================================================
    // اختبارات إنشاء عملية بيع (SaleService::create)
    // ========================================================================

    public function testCreateSaleSuccess(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-001', 'منتج بيع', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        $result = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 10, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['sale_id']);
        $this->assertArrayHasKey('summary', $result);

        // التحقق من حساب المجموع
        $this->assertEqual(750.0, $result['summary']['total']); // 10 × 75
        $this->assertEqual(500.0, $result['summary']['cost']);  // 10 × 50
        $this->assertEqual(250.0, $result['summary']['profit']); // 750 - 500
    }

    public function testCreateSaleMultipleItems(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-002', 'منتج A', 50, 75, 'علبة', 100, 0)");
        $pid1 = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid1}, 100, 100, 50, '2026-01-01')");

        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-003', 'منتج B', 30, 50, 'علبة', 200, 0)");
        $pid2 = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid2}, 200, 200, 30, '2026-01-01')");

        $result = SaleService::create([
            'items' => [
                ['product_id' => $pid1, 'quantity' => 10, 'selling_price' => 75],
                ['product_id' => $pid2, 'quantity' => 20, 'selling_price' => 50],
            ],
            'sale_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEqual(2, $result['summary']['count']);
        // المجموع: 10×75 + 20×50 = 750 + 1000 = 1750
        $this->assertEqual(1750.0, $result['summary']['total']);
        // التكلفة: 10×50 + 20×30 = 500 + 600 = 1100
        $this->assertEqual(1100.0, $result['summary']['cost']);
        // الربح: 1750 - 1100 = 650
        $this->assertEqual(650.0, $result['summary']['profit']);
    }

    public function testCreateSaleDeductsFromInventory(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-004', 'منتج مخزون', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        $result = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        // التحقق من خصم الكمية من المخزون
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($product['quantity']));

        // التحقق من خصم الكمية من الدفعة
        $batch = fetchOne("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($batch['remaining_quantity']));
    }

    public function testCreateSaleInsufficientStock(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-005', 'منتج محدود', 50, 75, 'علبة', 10, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        $this->assertThrows(AppException::class, function() use ($pid) {
            SaleService::create([
                'items' => [
                    ['product_id' => $pid, 'quantity' => 50, 'selling_price' => 75],
                ],
                'sale_date' => '2026-05-14',
            ]);
        }, 'Insufficient stock should throw AppException');
    }

    public function testCreateSaleNonExistentProduct(): void
    {
        $this->assertThrows(AppException::class, function() {
            SaleService::create([
                'items' => [
                    ['product_id' => 99999, 'quantity' => 10, 'selling_price' => 75],
                ],
                'sale_date' => '2026-05-14',
            ]);
        }, 'Non-existent product sale should throw AppException');
    }

    public function testCreateSaleZeroQuantity(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-006', 'منتج كمية صفر', 50, 75, 'علبة', 100, 0)");

        $this->assertThrows(AppException::class, function() {
            SaleService::create([
                'items' => [
                    ['product_id' => 1, 'quantity' => 0, 'selling_price' => 75],
                ],
                'sale_date' => '2026-05-14',
            ]);
        }, 'Zero quantity sale should throw AppException');
    }

    public function testCreateSaleZeroSellingPrice(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-007', 'منتج سعر صفر', 50, 75, 'علبة', 100, 0)");

        $this->assertThrows(AppException::class, function() {
            SaleService::create([
                'items' => [
                    ['product_id' => 1, 'quantity' => 10, 'selling_price' => 0],
                ],
                'sale_date' => '2026-05-14',
            ]);
        }, 'Zero selling price sale should throw AppException');
    }

    public function testCreateSaleDuplicateProduct(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-008', 'منتج مكرر', 50, 75, 'علبة', 100, 0)");

        $this->assertThrows(AppException::class, function() {
            SaleService::create([
                'items' => [
                    ['product_id' => 1, 'quantity' => 10, 'selling_price' => 75],
                    ['product_id' => 1, 'quantity' => 5, 'selling_price' => 75],
                ],
                'sale_date' => '2026-05-14',
            ]);
        }, 'Duplicate product in sale should throw AppException');
    }

    public function testCreateSaleEmptyItems(): void
    {
        $this->assertThrows(AppException::class, function() {
            SaleService::create([
                'items' => [],
                'sale_date' => '2026-05-14',
            ]);
        }, 'Empty items sale should throw AppException');
    }

    public function testCreateSaleWithBatchCostTracking(): void
    {
        // منتج بدفعتين بسعري شراء مختلفين
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('S-009', 'منتج تتبع تكلفة', 60, 100, 'علبة', 200, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 70, '2026-02-01')");

        $result = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 150, 'selling_price' => 100],
            ],
            'sale_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        // التكلفة: 100×50 + 50×70 = 5000 + 3500 = 8500
        $this->assertEqual(8500.0, $result['summary']['cost']);
        // المبيعات: 150×100 = 15000
        $this->assertEqual(15000.0, $result['summary']['total']);
        // الربح: 15000 - 8500 = 6500
        $this->assertEqual(6500.0, $result['summary']['profit']);
    }

    // ========================================================================
    // اختبارات عكس عملية البيع (SaleService::delete)
    // ========================================================================

    public function testDeleteSaleRestoresProductQuantity(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('D-001', 'منتج عكس', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        // إنشاء بيع
        $saleResult = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($saleResult['success']);
        $saleId = $saleResult['sale_ids'][0];

        // التحقق من خصم الكمية
        $productBefore = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($productBefore['quantity']));

        // حذف البيع (عكس العملية)
        $deleteResult = SaleService::delete($saleId);
        $this->assertTrue($deleteResult['success']);

        // التحقق من استرجاع الكمية
        $productAfter = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($productAfter['quantity']), 'Product quantity should be restored after sale reversal');
    }

    public function testDeleteSaleRestoresBatchQuantities(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('D-002', 'منتج دفعات عكس', 50, 75, 'علبة', 200, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 70, '2026-02-01')");

        // إنشاء بيع يستهلك من الدفعتين
        $saleResult = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 150, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($saleResult['success']);
        $saleId = $saleResult['sale_ids'][0];

        // التحقق من خصم الدفعات
        $batchesBefore = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY id", [$pid]);
        $this->assertEqual(0.0, floatval($batchesBefore[0]['remaining_quantity']));
        $this->assertEqual(50.0, floatval($batchesBefore[1]['remaining_quantity']));

        // عكس البيع
        $deleteResult = SaleService::delete($saleId);
        $this->assertTrue($deleteResult['success']);

        // التحقق من استرجاع كميات الدفعات
        $batchesAfter = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY id", [$pid]);
        $this->assertEqual(100.0, floatval($batchesAfter[0]['remaining_quantity']), 'First batch should be fully restored');
        $this->assertEqual(100.0, floatval($batchesAfter[1]['remaining_quantity']), 'Second batch should be fully restored');
    }

    public function testDeleteSaleRemovesBatchItems(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('D-003', 'منتج عناصر دفعات', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        $saleResult = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);
        $saleId = $saleResult['sale_ids'][0];

        // التحقق من وجود عناصر الدفعات
        $sbiBefore = fetchAll("SELECT * FROM sale_batch_items WHERE sale_id = ?", [$saleId]);
        $this->assertGreaterThan(0, count($sbiBefore));

        // عكس البيع
        SaleService::delete($saleId);

        // التحقق من حذف عناصر الدفعات
        $sbiAfter = fetchAll("SELECT * FROM sale_batch_items WHERE sale_id = ?", [$saleId]);
        $this->assertEqual(0, count($sbiAfter));
    }

    public function testDeleteSaleDeletesSaleRecord(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('D-004', 'منتج حذف سجل', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        $saleResult = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 10, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);
        $saleId = $saleResult['sale_ids'][0];

        // التحقق من وجود سجل البيع
        $saleBefore = fetchOne("SELECT * FROM sales WHERE id = ?", [$saleId]);
        $this->assertNotNull($saleBefore);

        // عكس البيع
        SaleService::delete($saleId);

        // التحقق من حذف سجل البيع
        $saleAfter = fetchOne("SELECT * FROM sales WHERE id = ?", [$saleId]);
        $this->assertNull($saleAfter);
    }

    public function testDeleteNonExistentSaleFails(): void
    {
        $this->assertThrows(AppException::class, function() {
            SaleService::delete(99999);
        }, 'Deleting non-existent sale should throw AppException');
    }

    public function testDeleteSaleThenResellSameQuantity(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('D-005', 'منتج إعادة بيع', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        // بيع 30 وحدة
        $sale1 = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($sale1['success']);

        // عكس البيع
        SaleService::delete($sale1['sale_ids'][0]);

        // إعادة بيع 30 وحدة
        $sale2 = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75],
            ],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($sale2['success']);

        // التحقق من أن المخزون انخفض بشكل صحيح
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($product['quantity']));
    }

    // ========================================================================
    // اختبارات التحقق من صحة بيانات البيع
    // ========================================================================

    public function testValidateSaleDataEmptyItems(): void
    {
        $method = new ReflectionMethod(SaleService::class, 'validateSaleData');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['items' => [], 'sale_date' => '2026-05-14']);
        $this->assertIsArray($result);
    }

    public function testValidateSaleDataMissingDate(): void
    {
        $method = new ReflectionMethod(SaleService::class, 'validateSaleData');
        $method->setAccessible(true);

        $result = $method->invoke(null, ['items' => [['product_id' => 1, 'quantity' => 5, 'selling_price' => 75]]]);
        $this->assertIsArray($result);
    }

    public function testValidateSaleDataValidInput(): void
    {
        $method = new ReflectionMethod(SaleService::class, 'validateSaleData');
        $method->setAccessible(true);

        $result = $method->invoke(null, [
            'items' => [['product_id' => 1, 'quantity' => 5, 'selling_price' => 75]],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($result);
    }

    // ========================================================================
    // اختبارات حساب الأرباح
    // ========================================================================

    public function testCalculateProfitsBasicCalculation(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('P-001', 'منتج ربح', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        // إدراج مبيعات يدوياً
        self::$sqlite->exec("INSERT INTO sales (product_id, quantity, selling_price, total, cost_total, profit, sale_date) VALUES ({$pid}, 10, 75, 750, 500, 250, '2026-05-01')");
        self::$sqlite->exec("INSERT INTO sales (product_id, quantity, selling_price, total, cost_total, profit, sale_date) VALUES ({$pid}, 20, 75, 1500, 1000, 500, '2026-05-10')");

        $result = SaleService::calculateProfits('2026-05-01', '2026-05-31');

        $this->assertEqual(2250.0, $result['total_sales']);  // 750 + 1500
        $this->assertEqual(1500.0, $result['total_cost']);   // 500 + 1000
        $this->assertEqual(750.0, $result['gross_profit']);  // 250 + 500
    }

    public function testCalculateProfitsWithExpenses(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('P-002', 'منتج مصاريف', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO sales (product_id, quantity, selling_price, total, cost_total, profit, sale_date) VALUES ({$pid}, 10, 75, 750, 500, 250, '2026-05-01')");
        self::$sqlite->exec("INSERT INTO expenses (amount, expense_date) VALUES (100, '2026-05-05')");

        $result = SaleService::calculateProfits('2026-05-01', '2026-05-31');

        $this->assertEqual(250.0, $result['gross_profit']);
        $this->assertEqual(100.0, $result['total_expenses']);
        $this->assertEqual(150.0, $result['net_profit']); // 250 - 100
    }

    public function testCalculateProfitsEmptyPeriod(): void
    {
        $result = SaleService::calculateProfits('2020-01-01', '2020-01-31');
        $this->assertEqual(0.0, $result['total_sales']);
        $this->assertEqual(0.0, $result['total_cost']);
        $this->assertEqual(0.0, $result['gross_profit']);
        $this->assertEqual(0.0, $result['net_profit']);
    }

    public function testCalculateProfitsMargins(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('P-003', 'منتج هوامش', 50, 100, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO sales (product_id, quantity, selling_price, total, cost_total, profit, sale_date) VALUES ({$pid}, 10, 100, 1000, 500, 500, '2026-05-01')");

        $result = SaleService::calculateProfits('2026-05-01', '2026-05-31');

        // هامش الربح الإجمالي = 500/1000 × 100 = 50%
        $this->assertEqual(50.0, $result['gross_margin']);
    }

    public function testCalculateProfitsMissingDates(): void
    {
        $result = SaleService::calculateProfits('', '');
        $this->assertEqual(0, $result['total_sales']);
        $this->assertArrayHasKey('message', $result);
    }

    // ========================================================================
    // اختبارات تكلفة الدفعات الموزونة
    // ========================================================================

    public function testWeightedAverageCostFromBatches(): void
    {
        // منتج بدفعتين بسعري شراء مختلفين
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('W-001', 'منتج متوسط موزون', 60, 100, 'علبة', 200, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 70, '2026-02-01')");

        $result = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 150, 'selling_price' => 100],
            ],
            'sale_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        // سعر الشراء الموزون = (100×50 + 50×70) / 150 = 8500/150 = 56.67
        $sale = fetchOne("SELECT purchase_price_at_sale FROM sales WHERE id = ?", [$result['sale_ids'][0]]);
        $weightedPrice = floatval($sale['purchase_price_at_sale']);
        $this->assertGreaterThan(56.0, $weightedPrice);
        $this->assertLessThan(57.5, $weightedPrice);
    }

    // ========================================================================
    // اختبارات إضافية - FEFO/FIFO حالات متقدمة
    // ========================================================================

    // 1. FIFO with partially consumed batches
    public function testDeductFromBatchesFIFOPartiallyConsumedFirstBatch(): void
    {
        // Product with 2 batches, first one partially consumed
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('PC-001', 'منتج مستهلك جزئيا', 50, 75, 'علبة', 150, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        // First batch: 100 total, only 40 remaining (60 sold)
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 40, 50, '2026-01-01')");
        // Second batch: 100 total, all remaining
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 55, '2026-02-01')");

        // Deduct 70: should take 40 from first + 30 from second
        $result = SaleService::deductFromBatches($pid, 70, 'fifo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(40.0, $result[0]['quantity'], 'Should take remaining 40 from first batch');
        $this->assertEqual(50.0, $result[0]['cost_price'], 'First batch cost should be 50');
        $this->assertEqual(30.0, $result[1]['quantity'], 'Should take 30 from second batch');
        $this->assertEqual(55.0, $result[1]['cost_price'], 'Second batch cost should be 55');
    }

    // 2. FEFO with same expiry date but different purchase dates
    public function testDeductFromBatchesFEFOSameExpiryDifferentPurchaseDate(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('SE-001', 'منتج نفس الانتهاء', 50, 75, 'علبة', 200, 1)");
        $pid = intval(self::$sqlite->lastInsertId());

        // Both expire same date, but purchased at different times
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01', '2026-06-30')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 55, '2026-02-01', '2026-06-30')");

        // FEFO with same expiry should fall back to purchase_date order
        $result = SaleService::deductFromBatches($pid, 50, 'fefo');
        $this->assertEqual(1, count($result));
        $this->assertEqual(50.0, $result[0]['cost_price'], 'FEFO with same expiry should take from earlier purchase first');
    }

    // 3. FIFO with decimal quantities
    public function testDeductFromBatchesFIFODecimalQuantities(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('DQ-001', 'منتج كميات عشرية', 100, 150, 'كغ', 2.5, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 1.5, 1.5, 100, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 1.0, 1.0, 110, '2026-02-01')");

        // Deduct 2.0: 1.5 from first + 0.5 from second
        $result = SaleService::deductFromBatches($pid, 2.0, 'fifo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(1.5, $result[0]['quantity']);
        $this->assertEqual(0.5, $result[1]['quantity']);
    }

    // 4. FEFO vs FIFO comparison - verify different deduction orders
    public function testDeductFromBatchesFEFOVsFIFOOrder(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('FF-001', 'منتج مقارنة', 50, 75, 'علبة', 200, 1)");
        $pid = intval(self::$sqlite->lastInsertId());

        // Batch A: purchased first, expires later
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01', '2026-12-31')");
        // Batch B: purchased later, expires sooner
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 60, '2026-02-01', '2026-06-30')");

        // FIFO should take from batch A (earlier purchase)
        $fifoResult = SaleService::deductFromBatches($pid, 50, 'fifo');
        $fifoBatch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$fifoResult[0]['batch_id']]);
        $this->assertEqual('2026-01-01', $fifoBatch['purchase_date'], 'FIFO should take from earlier purchase');

        // FEFO should take from batch B (earlier expiry)
        $fefoResult = SaleService::deductFromBatches($pid, 50, 'fefo');
        $fefoBatch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$fefoResult[0]['batch_id']]);
        $this->assertEqual('2026-02-01', $fefoBatch['purchase_date'], 'FEFO should take from earlier expiry (later purchase)');
    }

    // 5. FEFO spanning 4+ batches
    public function testDeductFromBatchesFEFOFourBatches(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('4B-001', 'منتج 4 دفعات', 50, 75, 'علبة', 400, 1)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01', '2026-12-31')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 55, '2026-01-15', '2026-03-31')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 60, '2026-02-01', '2026-06-30')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 65, '2026-03-01', '2026-09-30')");

        // Deduct 250: 100 (Mar) + 100 (Jun) + 50 (Sep) — FEFO order
        $result = SaleService::deductFromBatches($pid, 250, 'fefo');
        $this->assertEqual(3, count($result));

        // Verify order by checking batch expiry dates
        $batch1 = fetchOne("SELECT expiry_date FROM purchase_batches WHERE id = ?", [$result[0]['batch_id']]);
        $batch2 = fetchOne("SELECT expiry_date FROM purchase_batches WHERE id = ?", [$result[1]['batch_id']]);
        $batch3 = fetchOne("SELECT expiry_date FROM purchase_batches WHERE id = ?", [$result[2]['batch_id']]);

        $this->assertEqual('2026-03-31', $batch1['expiry_date'], 'First should be earliest expiry');
        $this->assertEqual('2026-06-30', $batch2['expiry_date'], 'Second should be next expiry');
        $this->assertEqual('2026-09-30', $batch3['expiry_date'], 'Third should be next expiry');
    }

    // ========================================================================
    // اختبارات إضافية - عكس المبيعات (حالات متقدمة)
    // ========================================================================

    // 6. Reversal of sale that consumed from multiple batches
    public function testDeleteSaleMultiBatchDeductionReversal(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('MR-001', 'منتج عكس متعدد', 50, 75, 'علبة', 200, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 70, '2026-02-01')");

        // Create sale that consumes from both batches (100 from first + 50 from second = 150)
        $sale = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 150, 'selling_price' => 100]],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($sale['success']);

        // Verify batch consumption
        $batchesBefore = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY id", [$pid]);
        $this->assertEqual(0.0, floatval($batchesBefore[0]['remaining_quantity']));
        $this->assertEqual(50.0, floatval($batchesBefore[1]['remaining_quantity']));

        // Reverse the sale
        $deleteResult = SaleService::delete($sale['sale_ids'][0]);
        $this->assertTrue($deleteResult['success']);

        // Verify ALL batches are fully restored
        $batchesAfter = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY id", [$pid]);
        $this->assertEqual(100.0, floatval($batchesAfter[0]['remaining_quantity']), 'First batch should be fully restored');
        $this->assertEqual(100.0, floatval($batchesAfter[1]['remaining_quantity']), 'Second batch should be fully restored');

        // Verify product quantity is restored
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(200.0, floatval($product['quantity']));
    }

    // 7. Reversal of one sale among multiple sales
    public function testDeleteSpecificSaleAmongMultiple(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('SS-001', 'منتج مبيعات متعددة', 50, 75, 'علبة', 200, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 200, 200, 50, '2026-01-01')");

        // Sale 1: 30 units
        $sale1 = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75]],
            'sale_date' => '2026-05-01',
        ]);
        $this->assertTrue($sale1['success']);

        // Sale 2: 50 units
        $sale2 = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 50, 'selling_price' => 80]],
            'sale_date' => '2026-05-10',
        ]);
        $this->assertTrue($sale2['success']);

        // Verify quantity: 200 - 30 - 50 = 120
        $productBefore = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(120.0, floatval($productBefore['quantity']));

        // Reverse only sale 1
        SaleService::delete($sale1['sale_ids'][0]);

        // Verify quantity: 120 + 30 = 150
        $productAfter = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(150.0, floatval($productAfter['quantity']));

        // Verify sale 2 still exists
        $sale2Record = fetchOne("SELECT * FROM sales WHERE id = ?", [$sale2['sale_ids'][0]]);
        $this->assertNotNull($sale2Record, 'Sale 2 should still exist');
    }

    // 8. Multiple sequential reversals
    public function testMultipleSequentialReversals(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('SQ-001', 'منتج عكس متتابع', 50, 75, 'علبة', 100, 0)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01')");

        // Create 3 sales
        $sale1 = SaleService::create(['items' => [['product_id' => $pid, 'quantity' => 20, 'selling_price' => 75]], 'sale_date' => '2026-05-01']);
        $sale2 = SaleService::create(['items' => [['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75]], 'sale_date' => '2026-05-05']);
        $sale3 = SaleService::create(['items' => [['product_id' => $pid, 'quantity' => 10, 'selling_price' => 75]], 'sale_date' => '2026-05-10']);

        // Quantity should be: 100 - 20 - 30 - 10 = 40
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(40.0, floatval($product['quantity']));

        // Reverse sale 2
        SaleService::delete($sale2['sale_ids'][0]);
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($product['quantity']), 'After reversing sale2: 40 + 30 = 70');

        // Reverse sale 1
        SaleService::delete($sale1['sale_ids'][0]);
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(90.0, floatval($product['quantity']), 'After reversing sale1: 70 + 20 = 90');

        // Reverse sale 3
        SaleService::delete($sale3['sale_ids'][0]);
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($product['quantity']), 'After reversing sale3: 90 + 10 = 100');
    }

    // 9. Sale reversal then resell with FEFO
    public function testSaleReversalThenResellFEFO(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('FR-001', 'منتج FEFO إعادة', 50, 75, 'علبة', 200, 1)");
        $pid = intval(self::$sqlite->lastInsertId());
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 50, '2026-01-01', '2026-12-31')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES ({$pid}, 100, 100, 60, '2026-02-01', '2026-06-30')");

        // تفعيل طريقة FEFO في الإعدادات
        self::$sqlite->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('batch_method', 'fefo')");

        // Sale using FEFO (takes from earliest expiry)
        $sale1 = SaleService::create(['items' => [['product_id' => $pid, 'quantity' => 50, 'selling_price' => 100]], 'sale_date' => '2026-03-01']);
        $this->assertTrue($sale1['success']);

        // Reverse it
        SaleService::delete($sale1['sale_ids'][0]);

        // Verify batches restored
        $batches = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY purchase_date", [$pid]);
        $this->assertEqual(100.0, floatval($batches[0]['remaining_quantity']));
        $this->assertEqual(100.0, floatval($batches[1]['remaining_quantity']));

        // Resell — now FEFO should take from the batch expiring sooner
        $sale2 = SaleService::create(['items' => [['product_id' => $pid, 'quantity' => 50, 'selling_price' => 100]], 'sale_date' => '2026-03-15']);
        $this->assertTrue($sale2['success']);

        // Verify FEFO order: cost should be from the batch with earlier expiry
        $sbi = fetchAll("SELECT sbi.*, pb.expiry_date FROM sale_batch_items sbi JOIN purchase_batches pb ON sbi.batch_id = pb.id WHERE sbi.sale_id = ?", [$sale2['sale_ids'][0]]);
        $this->assertGreaterThan(0, count($sbi));
        $this->assertEqual('2026-06-30', $sbi[0]['expiry_date'], 'FEFO should take from earliest expiry after reversal');
    }

    // 10. FIFO deduction exactly matches total available
    public function testDeductFromBatchesFIFOExactTotalAvailable(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('ET-001', 'منتج مطابق إجمالي', 50, 75, 'علبة', 150, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 50, 50, 40, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 55, '2026-02-01')");

        // Deduct exactly 150 = total available
        $result = SaleService::deductFromBatches($pid, 150, 'fifo');
        $this->assertEqual(2, count($result));
        $this->assertEqual(50.0, $result[0]['quantity']);
        $this->assertEqual(100.0, $result[1]['quantity']);

        // Verify total from batches equals requested
        $totalDeducted = array_sum(array_column($result, 'quantity'));
        $this->assertEqual(150.0, $totalDeducted);
    }

    // 11. Weighted average cost calculation with 3 batches
    public function testWeightedAverageCostThreeBatches(): void
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('WA3-001', 'متوسط 3 دفعات', 60, 100, 'علبة', 300, 0)");
        $pid = intval(self::$sqlite->lastInsertId());

        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 40, '2026-01-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 55, '2026-02-01')");
        self::$sqlite->exec("INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES ({$pid}, 100, 100, 70, '2026-03-01')");

        $sale = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 200, 'selling_price' => 100]],
            'sale_date' => '2026-05-14',
        ]);
        $this->assertTrue($sale['success']);

        // Cost: 100×40 + 100×55 = 4000 + 5500 = 9500
        $this->assertEqual(9500.0, $sale['summary']['cost']);

        // Weighted avg = 9500 / 200 = 47.5
        $saleRecord = fetchOne("SELECT purchase_price_at_sale FROM sales WHERE id = ?", [$sale['sale_ids'][0]]);
        $weightedPrice = floatval($saleRecord['purchase_price_at_sale']);
        $this->assertEqual(47.5, $weightedPrice, 'Weighted average should be 47.5');
    }
}
