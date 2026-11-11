<?php
/**
 * اختبارات نظام القائمة البيضاء لـ ORDER BY
 * - التحقق من أن المفاتيح المسموحة تُرجع الشرط الصحيح
 * - التحقق من رفض المفاتيح غير المصرح بها (بما في ذلك محاولات حقن SQL)
 * - التحقق من رفض السياقات غير المعروفة
 * - التحقق من آلية التراجع للقيم الافتراضية
 * - التحقق من دوال المساعدة (getAllowedSortKeys, isSortKeyAllowed, registerOrderByMapping)
 */

class OrderByWhitelistTest extends TestCase
{
    // ========================================================================
    //  اختبارات السياق 'batches' — النقطة الرئيسية المُصلحة
    // ========================================================================

    public function testBatchesFifoReturnsCorrectClause(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertStringContains('purchase_date ASC', $result);
        $this->assertStringContains('id ASC', $result);
    }

    public function testBatchesFefoReturnsCorrectClause(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'fefo');
        $this->assertStringContains('expiry_date', $result);
        $this->assertStringContains('purchase_date ASC', $result);
        $this->assertStringContains('id ASC', $result);
    }

    public function testBatchesFifoStartsWithPurchaseDate(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertTrue(
            str_starts_with($result, 'purchase_date ASC'),
            'FIFO يجب أن يبدأ بـ purchase_date ASC'
        );
    }

    public function testBatchesFefoStartsWithExpiryDate(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'fefo');
        $this->assertTrue(
            str_starts_with($result, 'CASE WHEN expiry_date'),
            'FEFO يجب أن يبدأ بـ CASE WHEN expiry_date'
        );
    }

    public function testBatchesExpiryFirstReturnsCorrectClause(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'expiry_first');
        $this->assertStringContains('expiry_date', $result);
        $this->assertStringContains('purchase_date ASC', $result);
    }

    // ========================================================================
    //  اختبارات رفض القيم غير المصرح بها — حماية من حقن SQL
    // ========================================================================

    public function testInvalidSortKeyFallsBackToDefault(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'nonexistent_key', 'fifo');
        $fifoResult = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertEqual($fifoResult, $result, 'المفتاح غير الموجود يجب أن يعود للقيمة الافتراضية');
    }

    public function testSqlInjectionInSortKeyIsRejected(): void
    {
        // محاولة حقن SQL كلاسيكية
        $injectionAttempts = [
            'id; DROP TABLE users--',
            '1 OR 1=1',
            'id DESC; --',
            'CASE WHEN 1=1 THEN id ELSE name END',
            "id'; INSERT INTO users --",
            'id UNION SELECT * FROM passwords--',
        ];

        foreach ($injectionAttempts as $attempt) {
            $result = AppDatabase::buildOrderBy('batches', $attempt, 'fifo');
            $fifoResult = AppDatabase::buildOrderBy('batches', 'fifo');
            $this->assertEqual(
                $fifoResult,
                $result,
                "محاولة الحقن '{$attempt}' يجب أن تُرفض وتعود للقيمة الافتراضية"
            );
        }
    }

    public function testSqlInjectionWithoutDefaultFallsBackToFirstEntry(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'DROP TABLE users');
        $fifoResult = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertEqual($fifoResult, $result, 'بدون قيمة افتراضية، يجب العودة لأول عنصر في القائمة البيضاء');
    }

    public function testEmptySortKeyFallsBackToDefault(): void
    {
        $result = AppDatabase::buildOrderBy('batches', '', 'fefo');
        $fefoResult = AppDatabase::buildOrderBy('batches', 'fefo');
        $this->assertEqual($fefoResult, $result, 'المفتاح الفارغ يجب أن يعود للقيمة الافتراضية');
    }

    public function testInvalidDefaultFallsBackToFirstEntry(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'invalid_key', 'also_invalid');
        $fifoResult = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertEqual($fifoResult, $result, 'إذا كانت القيمة الافتراضية غير صالحة أيضاً، يجب العودة لأول عنصر');
    }

    // ========================================================================
    //  اختبارات السياقات غير المعروفة
    // ========================================================================

    public function testUnknownContextThrowsException(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function () {
            AppDatabase::buildOrderBy('unknown_table', 'name_asc');
        });
    }

    public function testEmptyContextThrowsException(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function () {
            AppDatabase::buildOrderBy('', 'name_asc');
        });
    }

    public function testSqlInjectionInContextThrowsException(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function () {
            AppDatabase::buildOrderBy('products; DROP TABLE users--', 'name_asc');
        });
    }

    // ========================================================================
    //  اختبارات السياقات الأخرى
    // ========================================================================

    public function testProductsContextWorks(): void
    {
        $result = AppDatabase::buildOrderBy('products', 'name_asc');
        $this->assertEqual('p.name ASC', $result);
    }

    public function testProductsNameDescWorks(): void
    {
        $result = AppDatabase::buildOrderBy('products', 'name_desc');
        $this->assertEqual('p.name DESC', $result);
    }

    public function testProductsInvalidKeyFallsBack(): void
    {
        $result = AppDatabase::buildOrderBy('products', 'invalid', 'name_asc');
        $this->assertEqual('p.name ASC', $result);
    }

    public function testSalesContextWorks(): void
    {
        $result = AppDatabase::buildOrderBy('sales', 'date_desc');
        $this->assertEqual('sale_date DESC', $result);
    }

    public function testPurchasesContextWorks(): void
    {
        $result = AppDatabase::buildOrderBy('purchases', 'price_desc');
        $this->assertEqual('purchase_price DESC', $result);
    }

    public function testAuditLogContextWorks(): void
    {
        $result = AppDatabase::buildOrderBy('audit_log', 'date_desc');
        $this->assertEqual('al.created_at DESC', $result);
    }

    public function testNotificationsContextWorks(): void
    {
        $result = AppDatabase::buildOrderBy('notifications', 'date_desc');
        $this->assertEqual('created_at DESC', $result);
    }

    // ========================================================================
    //  اختبارات دوال المساعدة
    // ========================================================================

    public function testGetAllowedSortKeysReturnsCorrectKeys(): void
    {
        $keys = AppDatabase::getAllowedSortKeys('batches');
        $this->assertContains('fifo', $keys);
        $this->assertContains('fefo', $keys);
        $this->assertContains('expiry_first', $keys);
        $this->assertCount(3, $keys);
    }

    public function testGetAllowedSortKeysThrowsForUnknownContext(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function () {
            AppDatabase::getAllowedSortKeys('nonexistent');
        });
    }

    public function testIsSortKeyAllowedReturnsTrueForValidKey(): void
    {
        $this->assertTrue(AppDatabase::isSortKeyAllowed('batches', 'fifo'));
        $this->assertTrue(AppDatabase::isSortKeyAllowed('batches', 'fefo'));
        $this->assertTrue(AppDatabase::isSortKeyAllowed('products', 'name_asc'));
    }

    public function testIsSortKeyAllowedReturnsFalseForInvalidKey(): void
    {
        $this->assertFalse(AppDatabase::isSortKeyAllowed('batches', 'invalid'));
        $this->assertFalse(AppDatabase::isSortKeyAllowed('batches', 'DROP TABLE users'));
        $this->assertFalse(AppDatabase::isSortKeyAllowed('batches', ''));
    }

    public function testIsSortKeyAllowedReturnsFalseForUnknownContext(): void
    {
        $this->assertFalse(AppDatabase::isSortKeyAllowed('nonexistent', 'name_asc'));
    }

    // ========================================================================
    //  اختبارات تسجيل سياقات جديدة (registerOrderByMapping)
    // ========================================================================

    public function testRegisterNewContextWorks(): void
    {
        AppDatabase::registerOrderByMapping('test_context', [
            'custom_asc'  => 'custom_column ASC',
            'custom_desc' => 'custom_column DESC',
        ]);

        $result = AppDatabase::buildOrderBy('test_context', 'custom_asc');
        $this->assertEqual('custom_column ASC', $result);

        $this->assertTrue(AppDatabase::isSortKeyAllowed('test_context', 'custom_desc'));
    }

    public function testRegisterAddsToExistingContext(): void
    {
        AppDatabase::registerOrderByMapping('batches', [
            'custom_sort' => 'remaining_quantity ASC',
        ]);

        $this->assertTrue(AppDatabase::isSortKeyAllowed('batches', 'custom_sort'));
        $result = AppDatabase::buildOrderBy('batches', 'custom_sort');
        $this->assertEqual('remaining_quantity ASC', $result);

        // المفاتيح الأصلية يجب أن تظل تعمل
        $fifoResult = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertStringContains('purchase_date ASC', $fifoResult);
    }

    public function testRegisterEmptyMappingThrowsException(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, function () {
            AppDatabase::registerOrderByMapping('test_context', []);
        });
    }

    // ========================================================================
    //  اختبارات أمنية شاملة — محاولات حقن متنوعة
    // ========================================================================

    public function testSqlInjectionSubqueryRejected(): void
    {
        $result = AppDatabase::buildOrderBy('products', '(SELECT password FROM users LIMIT 1)', 'name_asc');
        $expected = AppDatabase::buildOrderBy('products', 'name_asc');
        $this->assertEqual($expected, $result, 'الاستعلامات الفرعية يجب أن تُرفض');
    }

    public function testSqlInjectionIfFunctionRejected(): void
    {
        $result = AppDatabase::buildOrderBy('sales', 'IF(1=1,sale_date,profit)', 'date_desc');
        $expected = AppDatabase::buildOrderBy('sales', 'date_desc');
        $this->assertEqual($expected, $result, 'دالة IF يجب أن تُرفض');
    }

    public function testSqlInjectionSleepRejected(): void
    {
        $result = AppDatabase::buildOrderBy('products', 'SLEEP(5)', 'name_asc');
        $expected = AppDatabase::buildOrderBy('products', 'name_asc');
        $this->assertEqual($expected, $result, 'دالة SLEEP يجب أن تُرفض');
    }

    public function testSqlInjectionBenchmarkRejected(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'BENCHMARK(10000000,SHA1(\'test\'))', 'fifo');
        $expected = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertEqual($expected, $result, 'دالة BENCHMARK يجب أن تُرفض');
    }

    public function testNumericSortKeyRejected(): void
    {
        // بعض قواعد البيانات تسمح بـ ORDER BY 1 (ترتيب حسب العمود الأول)
        $result = AppDatabase::buildOrderBy('products', '1', 'name_asc');
        $expected = AppDatabase::buildOrderBy('products', 'name_asc');
        $this->assertEqual($expected, $result, 'الترتيب بالأرقام يجب أن يُرفض');
    }

    public function testColumnDirectReferenceRejected(): void
    {
        // محاولة استخدام اسم عمود مباشر بدلاً من المفتاح المنطقي
        $result = AppDatabase::buildOrderBy('products', 'p.name', 'name_asc');
        $expected = AppDatabase::buildOrderBy('products', 'name_asc');
        $this->assertEqual($expected, $result, 'أسماء الأعمدة المباشرة يجب أن تُرفض');
    }

    public function testCaseVariationRejected(): void
    {
        // المفاتيح حساسة لحالة الأحرف
        $result = AppDatabase::buildOrderBy('batches', 'FIFO', 'fifo');
        $expected = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertEqual($expected, $result, 'المفاتيح حساسة لحالة الأحرف — FIFO ≠ fifo');
    }

    // ========================================================================
    //  اختبارات التكامل مع SaleService
    // ========================================================================

    public function testBuildOrderByReturnsNonEmptyString(): void
    {
        $result = AppDatabase::buildOrderBy('batches', 'fifo');
        $this->assertTrue(strlen($result) > 0, 'يجب أن يُرجع شرط ORDER BY غير فارغ');
        $this->assertIsString($result);
    }

    public function testAllBatchSortOptionsReturnValidClauses(): void
    {
        $keys = AppDatabase::getAllowedSortKeys('batches');
        foreach ($keys as $key) {
            $result = AppDatabase::buildOrderBy('batches', $key);
            $this->assertTrue(strlen($result) > 0, "المفتاح '{$key}' يجب أن يُرجع شرطاً غير فارغ");
            $this->assertIsString($result);
        }
    }

    public function testAllProductSortOptionsReturnValidClauses(): void
    {
        $keys = AppDatabase::getAllowedSortKeys('products');
        foreach ($keys as $key) {
            $result = AppDatabase::buildOrderBy('products', $key);
            $this->assertTrue(strlen($result) > 0, "المفتاح '{$key}' يجب أن يُرجع شرطاً غير فارغ");
            $this->assertIsString($result);
        }
    }
}
