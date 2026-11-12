<?php
/**
 * ===== اختبارات خدمة المشتريات (PurchaseService) =====
 * يغطي:
 * - تسجيل عمليات الشراء مع إنشاء الدفعات وتحديث المخزون
 * - حساب الضريبة (نسبة مئوية ومبلغ ثابت)
 * - تحديث عمليات الشراء (الكمية، السعر، التاريخ)
 * - حذف عمليات الشراء مع إعادة تعديل المخزون والدفعات
 * - التخفيضات (مادية ومالية)
 * - تسجيل تغييرات الأسعار
 * - التحقق من صحة بيانات الشراء
 */

class PurchaseServiceTest extends TestCase
{
    private static ?PDO $sqlite = null;

    protected function setUp(): void
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
            actual_purchase_price REAL NOT NULL DEFAULT 0,
            purchase_date TEXT DEFAULT NULL,
            expiry_date TEXT DEFAULT NULL
        )");

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER DEFAULT NULL,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 0,
            purchase_price REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            tax_rate REAL NOT NULL DEFAULT 0,
            tax_amount REAL NOT NULL DEFAULT 0,
            tax_name TEXT DEFAULT NULL,
            grand_total REAL NOT NULL DEFAULT 0,
            purchase_date TEXT DEFAULT NULL,
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

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS purchase_remises (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_id INTEGER NOT NULL,
            order_id INTEGER DEFAULT NULL,
            type TEXT NOT NULL DEFAULT '',
            product_id INTEGER DEFAULT NULL,
            quantity REAL DEFAULT NULL,
            unit_price REAL DEFAULT 0,
            amount REAL DEFAULT NULL,
            description TEXT DEFAULT NULL
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

        self::$sqlite->exec("CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )");

        $this->injectSqliteConnection(self::$sqlite);
    }

    protected function tearDown(): void
    {
        if (self::$sqlite !== null) {
            self::$sqlite->exec("DELETE FROM sale_batch_items");
            self::$sqlite->exec("DELETE FROM sales");
            self::$sqlite->exec("DELETE FROM purchase_remises");
            self::$sqlite->exec("DELETE FROM purchase_batches");
            self::$sqlite->exec("DELETE FROM purchases");
            self::$sqlite->exec("DELETE FROM price_history");
            self::$sqlite->exec("DELETE FROM products");
        }
    }

    private function injectSqliteConnection(PDO $sqlite): void
    {
        $instance = AppDatabase::getInstance();
        $connProp = new ReflectionProperty(AppDatabase::class, 'connection');
        $connProp->setAccessible(true);
        $connProp->setValue($instance, $sqlite);
    }

    /**
     * مساعد: إنشاء منتج أساسي للاختبارات
     */
    private function createBasicProduct(string $name = 'منتج اختبار', float $purchasePrice = 50, float $sellingPrice = 75, int $hasExpiry = 0): int
    {
        self::$sqlite->exec("INSERT INTO products (code, name, purchase_price, selling_price, unit, quantity, has_expiry) VALUES ('P-" . uniqid() . "', '{$name}', {$purchasePrice}, {$sellingPrice}, 'علبة', 0, {$hasExpiry})");
        return intval(self::$sqlite->lastInsertId());
    }

    // ========================================================================
    // اختبارات إنشاء عملية شراء (PurchaseService::create)
    // ========================================================================

    public function testCreatePurchaseSuccess(): void
    {
        $pid = $this->createBasicProduct();

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['purchase_id']);

        // التحقق من تحديث كمية المنتج
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($product['quantity']));

        // التحقق من تحديث سعر الشراء
        $productPrice = fetchOne("SELECT purchase_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(50.0, floatval($productPrice['purchase_price']));
    }

    public function testCreatePurchaseUpdatesProductPurchasePrice(): void
    {
        $pid = $this->createBasicProduct('منتج سعر', 30, 50);

        // شراء بسعر جديد
        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 50,
            'cost_price' => 45,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        // سعر الشراء يجب أن يُحدَّث للسعر الجديد
        $product = fetchOne("SELECT purchase_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(45.0, floatval($product['purchase_price']));
    }

    public function testCreatePurchaseUpdatesSellingPriceIfProvided(): void
    {
        $pid = $this->createBasicProduct('منتج سعر بيع', 50, 75);

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 50,
            'cost_price' => 55,
            'sale_price' => 90,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        $product = fetchOne("SELECT selling_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(90.0, floatval($product['selling_price']));
    }

    public function testCreatePurchaseDoesNotUpdateSellingPriceIfNotProvided(): void
    {
        $pid = $this->createBasicProduct('منتج بدون سعر بيع', 50, 75);

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 50,
            'cost_price' => 55,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        $product = fetchOne("SELECT selling_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(75.0, floatval($product['selling_price']));
    }

    public function testCreatePurchaseCreatesSingleBatch(): void
    {
        $pid = $this->createBasicProduct();

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        $batches = fetchAll("SELECT * FROM purchase_batches WHERE purchase_id = ?", [$result['purchase_id']]);
        $this->assertEqual(1, count($batches));
        $this->assertEqual(100.0, floatval($batches[0]['original_quantity']));
        $this->assertEqual(100.0, floatval($batches[0]['remaining_quantity']));
        $this->assertEqual(50.0, floatval($batches[0]['purchase_price']));
    }

    public function testCreatePurchaseWithExpiryBatches(): void
    {
        $pid = $this->createBasicProduct('منتج انتهاء', 50, 75, 1);

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'batch_items' => [
                ['quantity' => 60, 'expiry_date' => '2026-06-30'],
                ['quantity' => 40, 'expiry_date' => '2026-12-31'],
            ],
        ]);

        $this->assertTrue($result['success']);

        $batches = fetchAll("SELECT * FROM purchase_batches WHERE purchase_id = ? ORDER BY expiry_date", [$result['purchase_id']]);
        $this->assertEqual(2, count($batches));
        $this->assertEqual(60.0, floatval($batches[0]['original_quantity']));
        $this->assertEqual('2026-06-30', $batches[0]['expiry_date']);
        $this->assertEqual(40.0, floatval($batches[1]['original_quantity']));
        $this->assertEqual('2026-12-31', $batches[1]['expiry_date']);
    }

    public function testCreatePurchaseMultiplePurchasesAddToStock(): void
    {
        $pid = $this->createBasicProduct();

        // شراء أول
        PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 50,
            'cost_price' => 40,
            'purchase_date' => '2026-01-01',
        ]);

        // شراء ثاني
        PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 30,
            'cost_price' => 45,
            'purchase_date' => '2026-02-01',
        ]);

        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(80.0, floatval($product['quantity']));
    }

    public function testCreatePurchaseLogsPriceChange(): void
    {
        $pid = $this->createBasicProduct('منتج سجل أسعار', 50, 75);

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 60,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        // التحقق من تسجيل تغيير سعر الشراء
        $history = fetchAll("SELECT * FROM price_history WHERE product_id = ?", [$pid]);
        $this->assertGreaterThan(0, count($history));
        $this->assertEqual(50.0, floatval($history[0]['old_purchase_price']));
        $this->assertEqual(60.0, floatval($history[0]['new_purchase_price']));
    }

    public function testCreatePurchaseNoPriceChangeNoLog(): void
    {
        $pid = $this->createBasicProduct('منتج بدون تغيير', 50, 75);

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50, // نفس السعر الحالي
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        $history = fetchAll("SELECT * FROM price_history WHERE product_id = ?", [$pid]);
        $this->assertEqual(0, count($history), 'No price change should not log history');
    }

    public function testCreatePurchaseNonExistentProductFails(): void
    {
        $this->assertThrows(AppException::class, function() {
            PurchaseService::create([
                'product_id' => 99999,
                'quantity' => 100,
                'cost_price' => 50,
                'purchase_date' => '2026-05-14',
            ]);
        }, 'Non-existent product purchase should throw AppException');
    }

    // ========================================================================
    // اختبارات حساب الضريبة
    // ========================================================================

    public function testCreatePurchasePercentageTax(): void
    {
        $pid = $this->createBasicProduct();

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'tax_enabled' => true,
            'tax_type' => 'percentage',
            'tax_value' => 20,
            'tax_name' => 'VAT',
        ]);

        $this->assertTrue($result['success']);

        // الإجمالي = 100 × 50 = 5000
        // الضريبة = 5000 × 20% = 1000
        $purchase = fetchOne("SELECT * FROM purchases WHERE id = ?", [$result['purchase_id']]);
        $this->assertEqual(5000.0, floatval($purchase['total']));
        $this->assertEqual(1000.0, floatval($purchase['tax_amount']));
        $this->assertEqual(20.0, floatval($purchase['tax_rate']));
        $this->assertEqual('VAT', $purchase['tax_name']);
    }

    public function testCreatePurchaseFixedTax(): void
    {
        $pid = $this->createBasicProduct();

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'tax_enabled' => true,
            'tax_type' => 'fixed',
            'tax_value' => 500,
            'tax_name' => 'رسوم ثابتة',
        ]);

        $this->assertTrue($result['success']);

        $purchase = fetchOne("SELECT * FROM purchases WHERE id = ?", [$result['purchase_id']]);
        $this->assertEqual(500.0, floatval($purchase['tax_amount']));
    }

    public function testCreatePurchaseNoTax(): void
    {
        $pid = $this->createBasicProduct();

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ]);

        $this->assertTrue($result['success']);

        $purchase = fetchOne("SELECT * FROM purchases WHERE id = ?", [$result['purchase_id']]);
        $this->assertEqual(0.0, floatval($purchase['tax_amount']));
        $this->assertNull($purchase['tax_name']);
    }

    public function testTaxCalculationLogic(): void
    {
        // اختبار منطق حساب الضريبة المئوية مباشرة
        $total = 100 * 50; // 5000
        $taxRate = 19; // 19% TVA Algeria
        $taxAmount = $total * ($taxRate / 100);
        $this->assertEqual(950.0, $taxAmount);

        // اختبار الضريبة الثابتة
        $fixedTax = 200;
        $expectedRate = ($fixedTax / $total) * 100;
        $this->assertEqual(4.0, $expectedRate);
    }

    // ========================================================================
    // اختبارات التخفيضات (Remises)
    // ========================================================================

    public function testCreatePurchaseWithMaterialRemise(): void
    {
        $pid = $this->createBasicProduct('منتج أساسي', 50, 75);
        $pid2 = $this->createBasicProduct('منتج مجاني', 20, 35);

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'remises' => [
                [
                    'type' => 'material',
                    'product_id' => $pid2,
                    'quantity' => 10,
                    'description' => 'تخفيض مادي - 10 علب مجانية',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);

        // التحقق من إضافة كمية التخفيض المادي للمنتج المجاني
        $product2 = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid2]);
        $this->assertEqual(10.0, floatval($product2['quantity']));

        // التحقق من إنشاء سجل التخفيض
        $remise = fetchOne("SELECT * FROM purchase_remises WHERE purchase_id = ?", [$result['purchase_id']]);
        $this->assertNotNull($remise);
        $this->assertEqual('material', $remise['type']);
        $this->assertEqual(10.0, floatval($remise['quantity']));
    }

    public function testCreatePurchaseWithFinancialRemise(): void
    {
        $pid = $this->createBasicProduct();

        $result = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'remises' => [
                [
                    'type' => 'financial',
                    'amount' => 500,
                    'description' => 'خصم مالي 500 د.م.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);

        $remise = fetchOne("SELECT * FROM purchase_remises WHERE purchase_id = ? AND type = 'financial'", [$result['purchase_id']]);
        $this->assertNotNull($remise);
        $this->assertEqual(500.0, floatval($remise['amount']));
    }

    // ========================================================================
    // اختبارات تحديث عملية شراء (PurchaseService::update)
    // ========================================================================

    public function testUpdatePurchaseQuantityUnconsumedBatches(): void
    {
        $pid = $this->createBasicProduct();

        // إنشاء عملية شراء
        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $this->assertTrue($createResult['success']);
        $purchaseId = $createResult['purchase_id'];

        // تحديث الكمية (الدفعات غير مستهلكة)
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 150,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertTrue($updateResult['success']);

        // التحقق من تحديث كمية المنتج (الفرق = 50)
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(150.0, floatval($product['quantity']));

        // التحقق من تحديث الدفعات
        $batches = fetchAll("SELECT * FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $totalBatchQty = array_sum(array_map(fn($b) => floatval($b['original_quantity']), $batches));
        $this->assertEqual(150.0, $totalBatchQty);
    }

    public function testUpdatePurchaseQuantityDecreaseUnconsumed(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // تقليل الكمية
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 60,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertTrue($updateResult['success']);

        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(60.0, floatval($product['quantity']));
    }

    public function testUpdatePurchaseQuantityConsumedBatchesBlocked(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // محاكاة استهلاك جزئي للدفعة (بيع سابق)
        self::$sqlite->exec("UPDATE purchase_batches SET remaining_quantity = 50 WHERE purchase_id = {$purchaseId}");

        // محاولة تحديث الكمية - يجب أن تفشل
        $this->assertThrows(AppException::class, function() use ($purchaseId) {
            PurchaseService::update($purchaseId, [
                'quantity' => 150,
                'cost_price' => 50,
                'purchase_date' => '2026-01-01',
            ]);
        }, 'Updating purchase with consumed batches should throw AppException');
    }

    public function testUpdatePurchasePriceChange(): void
    {
        $pid = $this->createBasicProduct('منتج تغيير سعر', 50, 75);

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // تغيير سعر الشراء
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 100,
            'cost_price' => 60,
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertTrue($updateResult['success']);

        // التحقق من تحديث سعر شراء المنتج
        $product = fetchOne("SELECT purchase_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(60.0, floatval($product['purchase_price']));

        // التحقق من تحديث سعر الشراء في الدفعات
        $batch = fetchOne("SELECT purchase_price FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual(60.0, floatval($batch['purchase_price']));

        // التحقق من تسجيل تغيير السعر
        $history = fetchAll("SELECT * FROM price_history WHERE product_id = ?", [$pid]);
        $this->assertGreaterThan(0, count($history));
    }

    public function testUpdatePurchaseDateChange(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // تغيير تاريخ الشراء فقط
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-02-15',
        ]);

        $this->assertTrue($updateResult['success']);

        // التحقق من تحديث تاريخ الشراء في الدفعات
        $batch = fetchOne("SELECT purchase_date FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual('2026-02-15', $batch['purchase_date']);
    }

    public function testUpdatePurchaseUpdatesTotal(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // تحديث الكمية والسعر
        PurchaseService::update($purchaseId, [
            'quantity' => 80,
            'cost_price' => 60,
            'purchase_date' => '2026-01-01',
        ]);

        $purchase = fetchOne("SELECT * FROM purchases WHERE id = ?", [$purchaseId]);
        $this->assertEqual(80.0, floatval($purchase['quantity']));
        $this->assertEqual(60.0, floatval($purchase['purchase_price']));
        $this->assertEqual(4800.0, floatval($purchase['total'])); // 80 × 60
    }

    public function testUpdateNonExistentPurchaseFails(): void
    {
        $this->assertThrows(AppException::class, function() {
            PurchaseService::update(99999, [
                'quantity' => 100,
                'cost_price' => 50,
                'purchase_date' => '2026-01-01',
            ]);
        }, 'Updating non-existent purchase should throw AppException');
    }

    public function testUpdatePurchaseWithExpiryBatchesRedistributesQuantity(): void
    {
        $pid = $this->createBasicProduct('منتج انتهاء تحديث', 50, 75, 1);

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'batch_items' => [
                ['quantity' => 60, 'expiry_date' => '2026-06-30'],
                ['quantity' => 40, 'expiry_date' => '2026-12-31'],
            ],
        ]);
        $purchaseId = $createResult['purchase_id'];

        // تحديث الكمية إلى 200 (الدفعات غير مستهلكة)
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 200,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertTrue($updateResult['success']);

        // التحقق من إعادة توزيع الكمية بنفس النسب (60% و 40%)
        $batches = fetchAll("SELECT * FROM purchase_batches WHERE purchase_id = ? ORDER BY expiry_date", [$purchaseId]);
        $this->assertEqual(2, count($batches));
        // 60% من 200 = 120
        $this->assertEqual(120.0, floatval($batches[0]['original_quantity']));
        // 40% من 200 = 80
        $this->assertEqual(80.0, floatval($batches[1]['original_quantity']));
    }

    // ========================================================================
    // اختبارات حذف عملية شراء (PurchaseService::delete)
    // ========================================================================

    public function testDeletePurchaseRestoresInventory(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // التحقق من إضافة الكمية
        $productBefore = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($productBefore['quantity']));

        // حذف عملية الشراء
        $deleteResult = PurchaseService::delete($purchaseId);
        $this->assertTrue($deleteResult['success']);

        // التحقق من استرجاع الكمية
        $productAfter = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(0.0, floatval($productAfter['quantity']));
    }

    public function testDeletePurchaseRemovesBatches(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // التحقق من وجود الدفعات
        $batchesBefore = fetchAll("SELECT * FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertGreaterThan(0, count($batchesBefore));

        // حذف عملية الشراء
        PurchaseService::delete($purchaseId);

        // التحقق من حذف الدفعات
        $batchesAfter = fetchAll("SELECT * FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual(0, count($batchesAfter));
    }

    public function testDeletePurchaseRemovesRemises(): void
    {
        $pid = $this->createBasicProduct('منتج تخفيضات', 50, 75);
        $pid2 = $this->createBasicProduct('منتج مجاني', 20, 35);

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'remises' => [
                [
                    'type' => 'financial',
                    'amount' => 200,
                    'description' => 'خصم',
                ],
            ],
        ]);
        $purchaseId = $createResult['purchase_id'];

        // حذف عملية الشراء
        PurchaseService::delete($purchaseId);

        // التحقق من حذف التخفيضات
        $remises = fetchAll("SELECT * FROM purchase_remises WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual(0, count($remises));
    }

    public function testDeletePurchaseWithPartiallyConsumedBatches(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // محاكاة استهلاك جزئي
        self::$sqlite->exec("UPDATE purchase_batches SET remaining_quantity = 60 WHERE purchase_id = {$purchaseId}");
        // تحديث كمية المنتج لتعكس الاستهلاك
        self::$sqlite->exec("UPDATE products SET quantity = 60 WHERE id = {$pid}");

        // حذف عملية الشراء
        $deleteResult = PurchaseService::delete($purchaseId);
        $this->assertTrue($deleteResult['success']);

        // يجب أن تُخصم الكمية المتبقية فقط (60) وليس الكمية الأصلية (100)
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(0.0, floatval($product['quantity']), 'Only remaining batch quantity should be deducted');
    }

    public function testDeleteNonExistentPurchaseFails(): void
    {
        $this->assertThrows(AppException::class, function() {
            PurchaseService::delete(99999);
        }, 'Deleting non-existent purchase should throw AppException');
    }

    public function testDeletePurchaseRemovesSaleBatchItems(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // جلب معرف الدفعة
        $batch = fetchOne("SELECT id FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $batchId = intval($batch['id']);

        // محاكاة وجود عناصر دفعات مرتبطة بالمبيعات
        self::$sqlite->exec("INSERT INTO sale_batch_items (sale_id, batch_id, quantity_taken, purchase_price, cost_total) VALUES (1, {$batchId}, 10, 50, 500)");

        // حذف عملية الشراء
        $deleteResult = PurchaseService::delete($purchaseId);
        $this->assertTrue($deleteResult['success']);

        // التحقق من حذف عناصر الدفعات المرتبطة
        $sbi = fetchAll("SELECT * FROM sale_batch_items WHERE batch_id = ?", [$batchId]);
        $this->assertEqual(0, count($sbi));
    }

    // ========================================================================
    // اختبارات التحقق من صحة بيانات الشراء
    // ========================================================================

    public function testValidatePurchaseDataValidInput(): void
    {
        $pid = $this->createBasicProduct();

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ];

        $result = $method->invoke(null, $data);
        $this->assertTrue($result);
    }

    public function testValidatePurchaseDataMissingProduct(): void
    {
        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => 0,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataNonExistentProduct(): void
    {
        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => 99999,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataZeroQuantity(): void
    {
        $pid = $this->createBasicProduct();

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 0,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataZeroCostPrice(): void
    {
        $pid = $this->createBasicProduct();

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 0,
            'purchase_date' => '2026-05-14',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataMissingDate(): void
    {
        $pid = $this->createBasicProduct();

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataBatchQtyMismatch(): void
    {
        $pid = $this->createBasicProduct('منتج انتهاء', 50, 75, 1);

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'batch_items' => [
                ['quantity' => 60, 'expiry_date' => '2026-06-30'],
                ['quantity' => 30, 'expiry_date' => '2026-12-31'],
                // المجموع = 90 ≠ 100
            ],
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataInvalidTaxType(): void
    {
        $pid = $this->createBasicProduct();

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'tax_enabled' => true,
            'tax_type' => 'invalid_type',
            'tax_value' => 20,
            'tax_name' => 'VAT',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    public function testValidatePurchaseDataTaxEnabledMissingValue(): void
    {
        $pid = $this->createBasicProduct();

        $method = new ReflectionMethod(PurchaseService::class, 'validatePurchaseData');
        $method->setAccessible(true);

        $data = [
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-05-14',
            'tax_enabled' => true,
            'tax_value' => 0,
            'tax_name' => '',
            'tax_type' => 'percentage',
        ];

        $result = $method->invoke(null, $data);
        $this->assertIsArray($result);
    }

    // ========================================================================
    // اختبارات سيناريوهات متكاملة
    // ========================================================================

    public function testFullPurchaseSalesReversalCycle(): void
    {
        $pid = $this->createBasicProduct('منتج دورة كاملة', 50, 75, 1);

        // 1. شراء 100 وحدة بدفعات
        $purchase = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'batch_items' => [
                ['quantity' => 60, 'expiry_date' => '2026-06-30'],
                ['quantity' => 40, 'expiry_date' => '2026-12-31'],
            ],
        ]);
        $this->assertTrue($purchase['success']);

        // التحقق من المخزون
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($product['quantity']));

        // 2. بيع 30 وحدة
        $sale = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75],
            ],
            'sale_date' => '2026-02-01',
        ]);
        $this->assertTrue($sale['success']);

        // التحقق من المخزون بعد البيع
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($product['quantity']));

        // 3. عكس البيع
        SaleService::delete($sale['sale_ids'][0]);

        // التحقق من استرجاع المخزون
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($product['quantity']));

        // التحقق من استرجاع كميات الدفعات
        $batches = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY expiry_date", [$pid]);
        $this->assertEqual(60.0, floatval($batches[0]['remaining_quantity']));
        $this->assertEqual(40.0, floatval($batches[1]['remaining_quantity']));
    }

    public function testMultiplePurchasesAndPartialSale(): void
    {
        $pid = $this->createBasicProduct('منتج مشتريات متعددة', 50, 75);

        // شراء أول: 100 وحدة بسعر 50
        PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);

        // شراء ثاني: 50 وحدة بسعر 60
        PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 50,
            'cost_price' => 60,
            'purchase_date' => '2026-02-01',
        ]);

        // التحقق من المخزون الكلي
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(150.0, floatval($product['quantity']));

        // بيع 120 وحدة (FIFO: 100 بسعر 50 + 20 بسعر 60)
        $sale = SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 120, 'selling_price' => 80],
            ],
            'sale_date' => '2026-03-01',
        ]);
        $this->assertTrue($sale['success']);

        // التكلفة: 100×50 + 20×60 = 5000 + 1200 = 6200
        $this->assertEqual(6200.0, $sale['summary']['cost']);

        // الربح: 120×80 - 6200 = 9600 - 6200 = 3400
        $this->assertEqual(3400.0, $sale['summary']['profit']);

        // المخزون المتبقي
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(30.0, floatval($product['quantity']));
    }

    public function testPurchaseUpdateAfterSaleCreatesNewPurchase(): void
    {
        $pid = $this->createBasicProduct();

        // شراء أولي
        $purchase1 = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);

        // بيع جزئي
        SaleService::create([
            'items' => [
                ['product_id' => $pid, 'quantity' => 40, 'selling_price' => 75],
            ],
            'sale_date' => '2026-02-01',
        ]);

        // شراء إضافي
        $purchase2 = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 50,
            'cost_price' => 55,
            'purchase_date' => '2026-03-01',
        ]);

        // المخزون: 100 - 40 + 50 = 110
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(110.0, floatval($product['quantity']));

        // سعر الشراء يجب أن يكون 55 (آخر تحديث)
        $productPrice = fetchOne("SELECT purchase_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(55.0, floatval($productPrice['purchase_price']));
    }

    public function testPurchaseTotalCalculation(): void
    {
        // اختبار مباشر لحساب الإجمالي
        $quantity = 100;
        $costPrice = 47.5;
        $expectedTotal = $quantity * $costPrice;
        $this->assertEqual(4750.0, $expectedTotal);

        // اختبار مع أرقام عشرية
        $quantity2 = 33.33;
        $costPrice2 = 15.75;
        $expectedTotal2 = $quantity2 * $costPrice2;
        $this->assertGreaterThan(524.0, $expectedTotal2);
        $this->assertLessThan(526.0, $expectedTotal2);
    }

    // ========================================================================
    // اختبارات إضافية - تحديث المشتريات (حالات متقدمة)
    // ========================================================================

    // 1. Simultaneous quantity and price change
    public function testUpdatePurchaseBothQuantityAndPriceChange(): void
    {
        $pid = $this->createBasicProduct('منتج كمية+سعر', 50, 75);

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // Change both quantity and price
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 150,
            'cost_price' => 60,
            'purchase_date' => '2026-01-15',
        ]);

        $this->assertTrue($updateResult['success']);

        // Verify product quantity: 0 + 150 = 150 (was 100, +50 diff)
        $product = fetchOne("SELECT quantity, purchase_price FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(150.0, floatval($product['quantity']));
        $this->assertEqual(60.0, floatval($product['purchase_price']));

        // Verify batch updated
        $batch = fetchOne("SELECT purchase_price, original_quantity, remaining_quantity FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual(60.0, floatval($batch['purchase_price']));
        $this->assertEqual(150.0, floatval($batch['original_quantity']));

        // Verify purchase total
        $purchase = fetchOne("SELECT total FROM purchases WHERE id = ?", [$purchaseId]);
        $this->assertEqual(9000.0, floatval($purchase['total'])); // 150 × 60
    }

    // 2. Update notes only
    public function testUpdatePurchaseNotesOnly(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'notes' => 'ملاحظة أولية',
        ]);
        $purchaseId = $createResult['purchase_id'];

        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'notes' => 'ملاحظة محدثة',
        ]);

        $this->assertTrue($updateResult['success']);

        $purchase = fetchOne("SELECT notes FROM purchases WHERE id = ?", [$purchaseId]);
        $this->assertEqual('ملاحظة محدثة', $purchase['notes']);
    }

    // 3. Price change on expiry batches updates batch prices too
    public function testUpdatePurchaseExpiryBatchesPriceChange(): void
    {
        $pid = $this->createBasicProduct('منتج انتهاء سعر', 50, 75, 1);

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'batch_items' => [
                ['quantity' => 60, 'expiry_date' => '2026-06-30'],
                ['quantity' => 40, 'expiry_date' => '2026-12-31'],
            ],
        ]);
        $purchaseId = $createResult['purchase_id'];

        // Change price only (batches unconsumed)
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 100,
            'cost_price' => 65,
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertTrue($updateResult['success']);

        // Verify batch prices updated
        $batches = fetchAll("SELECT purchase_price FROM purchase_batches WHERE purchase_id = ? ORDER BY expiry_date", [$purchaseId]);
        $this->assertEqual(65.0, floatval($batches[0]['purchase_price']));
        $this->assertEqual(65.0, floatval($batches[1]['purchase_price']));

        // Verify expiry dates preserved
        $batchExpiry = fetchAll("SELECT expiry_date FROM purchase_batches WHERE purchase_id = ? ORDER BY expiry_date", [$purchaseId]);
        $this->assertEqual('2026-06-30', $batchExpiry[0]['expiry_date']);
        $this->assertEqual('2026-12-31', $batchExpiry[1]['expiry_date']);
    }

    // 4. Price update allowed even when batches partially consumed
    public function testUpdatePurchasePriceOnlyWithPartialConsumption(): void
    {
        $pid = $this->createBasicProduct();

        $createResult = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $createResult['purchase_id'];

        // Simulate partial consumption
        self::$sqlite->exec("UPDATE purchase_batches SET remaining_quantity = 60 WHERE purchase_id = {$purchaseId}");

        // Price change should succeed (quantity unchanged)
        $updateResult = PurchaseService::update($purchaseId, [
            'quantity' => 100,  // same quantity
            'cost_price' => 55, // new price
            'purchase_date' => '2026-01-01',
        ]);

        $this->assertTrue($updateResult['success'], 'Price-only update should succeed even with consumed batches');

        // Verify batch price updated
        $batch = fetchOne("SELECT purchase_price FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual(55.0, floatval($batch['purchase_price']));
    }

    // ========================================================================
    // اختبارات إضافية - سيناريوهات متكاملة متقدمة
    // ========================================================================

    // 5. Complete Purchase-Sale-Reversal cycle with FEFO
    public function testCompleteFEFOPurchaseSaleReversalCycle(): void
    {
        $pid = $this->createBasicProduct('منتج FEFO كامل', 50, 75, 1);

        // Purchase with expiry batches
        $purchase = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
            'batch_items' => [
                ['quantity' => 60, 'expiry_date' => '2026-06-30'],
                ['quantity' => 40, 'expiry_date' => '2026-12-31'],
            ],
        ]);
        $this->assertTrue($purchase['success']);

        // Verify stock
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($product['quantity']));

        // Sale using FEFO - should take from June batch first
        $sale = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 30, 'selling_price' => 75]],
            'sale_date' => '2026-02-01',
        ]);
        $this->assertTrue($sale['success']);

        // Verify FEFO: June batch should be reduced
        $batches = fetchAll("SELECT remaining_quantity, expiry_date FROM purchase_batches WHERE product_id = ? ORDER BY expiry_date", [$pid]);
        $this->assertEqual(30.0, floatval($batches[0]['remaining_quantity']), 'June batch: 60-30=30');
        $this->assertEqual(40.0, floatval($batches[1]['remaining_quantity']), 'Dec batch: unchanged');

        // Stock: 100-30 = 70
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(70.0, floatval($product['quantity']));

        // Reverse the sale
        SaleService::delete($sale['sale_ids'][0]);

        // Verify complete restoration
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(100.0, floatval($product['quantity']));

        $batches = fetchAll("SELECT remaining_quantity FROM purchase_batches WHERE product_id = ? ORDER BY expiry_date", [$pid]);
        $this->assertEqual(60.0, floatval($batches[0]['remaining_quantity']));
        $this->assertEqual(40.0, floatval($batches[1]['remaining_quantity']));
    }

    // 6. Multiple purchases, partial sales, partial reversal
    public function testMultiplePurchasesPartialSalesReversals(): void
    {
        $pid = $this->createBasicProduct('منتج سيناريو معقد', 50, 75);

        // Purchase 1: 100 units at 50
        $p1 = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);

        // Purchase 2: 80 units at 60
        $p2 = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 80,
            'cost_price' => 60,
            'purchase_date' => '2026-02-01',
        ]);

        // Total stock: 180
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(180.0, floatval($product['quantity']));

        // Sale 1: 50 units (FIFO: from purchase 1)
        $s1 = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 50, 'selling_price' => 80]],
            'sale_date' => '2026-03-01',
        ]);

        // Sale 2: 70 units (FIFO: 50 from purchase 1 + 20 from purchase 2)
        $s2 = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 70, 'selling_price' => 85]],
            'sale_date' => '2026-03-15',
        ]);

        // Stock: 180 - 50 - 70 = 60
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(60.0, floatval($product['quantity']));

        // Verify sale 1 cost (50 × 50 = 2500)
        $sale1Record = fetchOne("SELECT cost_total FROM sales WHERE id = ?", [$s1['sale_ids'][0]]);
        $this->assertEqual(2500.0, floatval($sale1Record['cost_total']));

        // Reverse sale 1
        SaleService::delete($s1['sale_ids'][0]);

        // Stock: 60 + 50 = 110
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(110.0, floatval($product['quantity']));

        // Verify batch state: sale 1 restored 50 to batch 1
        $batch1 = fetchOne("SELECT remaining_quantity FROM purchase_batches WHERE purchase_id = ? ORDER BY id", [$p1['purchase_id']]);
        $this->assertEqual(50.0, floatval($batch1['remaining_quantity']), 'Purchase 1 batch should have 50 restored');
    }

    // 7. Purchase delete after partial sale
    public function testPurchaseDeleteAfterPartialSale(): void
    {
        $pid = $this->createBasicProduct();

        // Purchase 100 units
        $purchase = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 100,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $purchase['purchase_id'];

        // Sell 40 units
        $sale = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 40, 'selling_price' => 75]],
            'sale_date' => '2026-02-01',
        ]);
        $this->assertTrue($sale['success']);

        // Stock: 100 - 40 = 60
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(60.0, floatval($product['quantity']));

        // Batch remaining: 100 - 40 = 60
        $batch = fetchOne("SELECT remaining_quantity FROM purchase_batches WHERE purchase_id = ?", [$purchaseId]);
        $this->assertEqual(60.0, floatval($batch['remaining_quantity']));

        // Delete the purchase - should deduct only remaining (60)
        $deleteResult = PurchaseService::delete($purchaseId);
        $this->assertTrue($deleteResult['success']);

        // Stock: 60 - 60 = 0
        $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$pid]);
        $this->assertEqual(0.0, floatval($product['quantity']));
    }

    // 8. Update purchase after sale - price change affects future costs
    public function testPurchasePriceUpdateAfterSaleAffectsFutureCosts(): void
    {
        $pid = $this->createBasicProduct('منتج تتبع تكلفة', 50, 75);

        $purchase = PurchaseService::create([
            'product_id' => $pid,
            'quantity' => 200,
            'cost_price' => 50,
            'purchase_date' => '2026-01-01',
        ]);
        $purchaseId = $purchase['purchase_id'];

        // First sale at price 50
        $sale1 = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 50, 'selling_price' => 80]],
            'sale_date' => '2026-02-01',
        ]);
        $sale1Cost = floatval(fetchOne("SELECT cost_total FROM sales WHERE id = ?", [$sale1['sale_ids'][0]])['cost_total']);
        $this->assertEqual(2500.0, $sale1Cost); // 50 × 50

        // Update purchase price to 60 (batches partially consumed: 150 remaining)
        // Price-only change should still work
        PurchaseService::update($purchaseId, [
            'quantity' => 200, // same
            'cost_price' => 60, // changed
            'purchase_date' => '2026-01-01',
        ]);

        // Second sale - should now use updated price 60 for remaining batch cost
        $sale2 = SaleService::create([
            'items' => [['product_id' => $pid, 'quantity' => 30, 'selling_price' => 80]],
            'sale_date' => '2026-03-01',
        ]);
        $sale2Cost = floatval(fetchOne("SELECT cost_total FROM sales WHERE id = ?", [$sale2['sale_ids'][0]])['cost_total']);
        $this->assertEqual(1800.0, $sale2Cost); // 30 × 60
    }
}
