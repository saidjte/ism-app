<?php
/**
 * اختبارات نظام القائمة البيضاء لـ ORDER BY
 * - التحقق من أن الأعمدة المسموحة تُرجع الشرط الصحيح
 * - التحقق من رفض الأعمدة غير المصرح بها (بما في ذلك محاولات حقن SQL)
 * - التحقق من تطبيع اتجاه الترتيب (ASC/DESC فقط)
 * - التحقق من آلية التراجع للقيم الافتراضية
 *
 * @updated v6.3.4 — أُعيدت الكتابة لتطابق واجهة AppDatabase::buildOrderBy()
 *          الحالية (مصفوفة أعمدة + قائمة بيضاء) — كانت الاختبارات القديمة
 *          مكتوبة لواجهة سياقات (contexts) غير موجودة في الكود الحي
 *          ولم تكن مُسجّلة في المشغّل القديم أصلاً.
 */

declare(strict_types=1);

class OrderByWhitelistTest extends TestCase
{
    /**
     * غلاف مختصر للاستدعاء — buildOrderBy دالة كائن (غير ساكنة)
     */
    private function build(array $orderBy, array $allowedColumns = [], string $default = 'id ASC'): string
    {
        return AppDatabase::getInstance()->buildOrderBy($orderBy, $allowedColumns, $default);
    }

    // ========================================================================
    //  الحالات الأساسية
    // ========================================================================

    public function testEmptyOrderByReturnsDefault(): void
    {
        $this->assertEqual('id ASC', $this->build([]));
    }

    public function testCustomDefaultIsReturnedWhenEmpty(): void
    {
        $this->assertEqual('name ASC', $this->build([], [], 'name ASC'));
    }

    public function testValidColumnReturnsQuotedClause(): void
    {
        $result = $this->build([['column' => 'name']]);
        $this->assertEqual('`name` ASC', $result);
    }

    public function testDirectionDescIsPreserved(): void
    {
        $result = $this->build([['column' => 'name', 'direction' => 'DESC']]);
        $this->assertEqual('`name` DESC', $result);
    }

    public function testLowercaseDirectionNormalizedToUppercase(): void
    {
        $result = $this->build([['column' => 'created_at', 'direction' => 'desc']]);
        $this->assertEqual('`created_at` DESC', $result);
    }

    public function testNumericTupleFormatWorks(): void
    {
        // الشكل البديل: [0 => عمود, 1 => اتجاه]
        $result = $this->build([['name', 'DESC']]);
        $this->assertEqual('`name` DESC', $result);
    }

    public function testMissingDirectionFallsBackToAsc(): void
    {
        $result = $this->build([['column' => 'id']]);
        $this->assertStringContains('ASC', $result);
    }

    public function testMultipleValidColumnsJoinedWithComma(): void
    {
        $result = $this->build([
            ['column' => 'name', 'direction' => 'ASC'],
            ['column' => 'id', 'direction' => 'DESC'],
        ]);
        $this->assertEqual('`name` ASC, `id` DESC', $result);
    }

    public function testTableQualifiedColumnIsAccepted(): void
    {
        // النقطة مسموحة (table.column)
        $result = $this->build([['column' => 'products.name', 'direction' => 'ASC']]);
        $this->assertEqual('`products.name` ASC', $result);
    }

    // ========================================================================
    //  القائمة البيضاء (Whitelist)
    // ========================================================================

    public function testWhitelistAcceptsAuthorizedColumn(): void
    {
        $result = $this->build([['column' => 'name', 'direction' => 'DESC']], ['name', 'id']);
        $this->assertEqual('`name` DESC', $result);
    }

    public function testWhitelistRejectsUnauthorizedColumn(): void
    {
        // عمود غير مصرح به → يُتجاهل → نعود للافتراضي
        $result = $this->build([['column' => 'password_hash', 'direction' => 'ASC']], ['name', 'id']);
        $this->assertEqual('id ASC', $result);
    }

    public function testWhitelistKeepsAuthorizedAndDropsUnauthorized(): void
    {
        $result = $this->build([
            ['column' => 'password_hash'],
            ['column' => 'name', 'direction' => 'DESC'],
        ], ['name', 'id']);
        $this->assertEqual('`name` DESC', $result);
    }

    public function testWhitelistComparisonIsStrict(): void
    {
        // تطابق جزئي لا يكفي: 'name' ضمن القائمة لا يمرر 'name; --'
        $result = $this->build([['column' => 'name; --']], ['name']);
        $this->assertEqual('id ASC', $result);
    }

    // ========================================================================
    //  محاولات حقن SQL
    // ========================================================================

    public function testSqlInjectionInColumnIsRejected(): void
    {
        $payloads = [
            'id; DROP TABLE users--',
            "id' --",
            'id UNION SELECT password_hash FROM users',
            'id)); DELETE FROM products--',
        ];
        foreach ($payloads as $payload) {
            $result = $this->build([['column' => $payload]]);
            $this->assertEqual('id ASC', $result, "يجب رفض الحمض: {$payload}");
        }
    }

    public function testSqlInjectionInDirectionFallsBackToAsc(): void
    {
        $result = $this->build([['column' => 'id', 'direction' => 'ASC; DROP TABLE users--']]);
        $this->assertEqual('`id` ASC', $result);
    }

    public function testInvalidDirectionFallsBackToAsc(): void
    {
        $result = $this->build([['column' => 'id', 'direction' => 'SIDEWAYS']]);
        $this->assertEqual('`id` ASC', $result);
    }

    public function testColumnStartingWithDigitIsRejected(): void
    {
        $result = $this->build([['column' => '1abc']]);
        $this->assertEqual('id ASC', $result);
    }

    public function testEmptyColumnNameIsRejected(): void
    {
        $result = $this->build([['column' => '']]);
        $this->assertEqual('id ASC', $result);
    }

    public function testSpecialCharactersAreRejected(): void
    {
        $result = $this->build([['column' => 'na`me']]);
        $this->assertEqual('id ASC', $result);
    }

    // ========================================================================
    //  التراجع الافتراضي (Fallback)
    // ========================================================================

    public function testAllColumnsFilteredFallsBackToDefault(): void
    {
        $result = $this->build([
            ['column' => 'bad col'],
            ['column' => 'another;bad'],
        ], ['name', 'id'], 'id ASC');
        $this->assertEqual('id ASC', $result);
    }

    public function testCustomDefaultUsedWhenAllFiltered(): void
    {
        $result = $this->build([['column' => 'evil--']], ['name'], 'created_at DESC');
        $this->assertEqual('created_at DESC', $result);
    }
}
