<?php
/**
 * اختبارات فئة BaseController - التحقق من المدخلات
 * - كل قواعد التحقق: required, numeric, integer, min, max, in, date, email
 * - label: إعادة تسمية الحقول
 */

class BaseControllerTest extends TestCase
{
    /** @var TestableBaseController المتحكم الاختباري */
    private TestableBaseController $controller;

    protected function setUp(): void
    {
        $this->controller = new TestableBaseController();
    }

    // === اختبارات required ===

    public function testRequiredPassesWhenPresent(): void
    {
        $result = $this->controller->validateField(['name' => 'أحمد'], ['name' => 'required']);
        $this->assertTrue($result);
    }

    public function testRequiredFailsWhenEmpty(): void
    {
        $result = $this->controller->validateField(['name' => ''], ['name' => 'required']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
    }

    public function testRequiredFailsWhenMissing(): void
    {
        $result = $this->controller->validateField([], ['name' => 'required']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
    }

    public function testRequiredFailsWhenNull(): void
    {
        $result = $this->controller->validateField(['name' => null], ['name' => 'required']);
        $this->assertIsArray($result);
    }

    // === اختبارات numeric ===

    public function testNumericPassesWithNumber(): void
    {
        $result = $this->controller->validateField(['price' => '100.50'], ['price' => 'numeric']);
        $this->assertTrue($result);
    }

    public function testNumericPassesWithIntegerString(): void
    {
        $result = $this->controller->validateField(['qty' => '42'], ['qty' => 'numeric']);
        $this->assertTrue($result);
    }

    public function testNumericFailsWithString(): void
    {
        $result = $this->controller->validateField(['price' => 'abc'], ['price' => 'numeric']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('price', $result);
    }

    public function testNumericSkipsEmpty(): void
    {
        $result = $this->controller->validateField(['price' => ''], ['price' => 'numeric']);
        $this->assertTrue($result);
    }

    public function testNumericSkipsNull(): void
    {
        $result = $this->controller->validateField([], ['price' => 'numeric']);
        $this->assertTrue($result);
    }

    // === اختبارات integer ===

    public function testIntegerPassesWithIntString(): void
    {
        $result = $this->controller->validateField(['qty' => '10'], ['qty' => 'integer']);
        $this->assertTrue($result);
    }

    public function testIntegerFailsWithFloat(): void
    {
        $result = $this->controller->validateField(['qty' => '10.5'], ['qty' => 'integer']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('qty', $result);
    }

    public function testIntegerFailsWithString(): void
    {
        $result = $this->controller->validateField(['qty' => 'abc'], ['qty' => 'integer']);
        $this->assertIsArray($result);
    }

    // === اختبارات min (أرقام) ===

    public function testMinNumericPasses(): void
    {
        $result = $this->controller->validateField(['price' => '100'], ['price' => 'min:10']);
        $this->assertTrue($result);
    }

    public function testMinNumericFails(): void
    {
        $result = $this->controller->validateField(['price' => '5'], ['price' => 'min:10']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('price', $result);
    }

    public function testMinNumericExact(): void
    {
        $result = $this->controller->validateField(['price' => '10'], ['price' => 'min:10']);
        $this->assertTrue($result);
    }

    // === اختبارات min (سلاسل نصية) ===

    public function testMinStringPasses(): void
    {
        $result = $this->controller->validateField(['name' => 'أحمد'], ['name' => 'min:3']);
        $this->assertTrue($result);
    }

    public function testMinStringFails(): void
    {
        $result = $this->controller->validateField(['name' => 'أب'], ['name' => 'min:3']);
        $this->assertIsArray($result);
    }

    // === اختبارات max (أرقام) ===

    public function testMaxNumericPasses(): void
    {
        $result = $this->controller->validateField(['discount' => '50'], ['discount' => 'max:100']);
        $this->assertTrue($result);
    }

    public function testMaxNumericFails(): void
    {
        $result = $this->controller->validateField(['discount' => '150'], ['discount' => 'max:100']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('discount', $result);
    }

    public function testMaxNumericExact(): void
    {
        $result = $this->controller->validateField(['discount' => '100'], ['discount' => 'max:100']);
        $this->assertTrue($result);
    }

    // === اختبارات in ===

    public function testInPasses(): void
    {
        $result = $this->controller->validateField(['type' => 'sale'], ['type' => 'in:sale,purchase']);
        $this->assertTrue($result);
    }

    public function testInFails(): void
    {
        $result = $this->controller->validateField(['type' => 'other'], ['type' => 'in:sale,purchase']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
    }

    public function testInSkipsEmpty(): void
    {
        $result = $this->controller->validateField(['type' => ''], ['type' => 'in:sale,purchase']);
        $this->assertTrue($result);
    }

    // === اختبارات date ===

    public function testDatePasses(): void
    {
        $result = $this->controller->validateField(['date' => '2024-01-15'], ['date' => 'date']);
        $this->assertTrue($result);
    }

    public function testDateFails(): void
    {
        $result = $this->controller->validateField(['date' => 'not-a-date'], ['date' => 'date']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('date', $result);
    }

    public function testDateSkipsEmpty(): void
    {
        $result = $this->controller->validateField(['date' => ''], ['date' => 'date']);
        $this->assertTrue($result);
    }

    // === اختبارات email ===

    public function testEmailPasses(): void
    {
        $result = $this->controller->validateField(['email' => 'test@example.com'], ['email' => 'email']);
        $this->assertTrue($result);
    }

    public function testEmailFails(): void
    {
        $result = $this->controller->validateField(['email' => 'invalid'], ['email' => 'email']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('email', $result);
    }

    public function testEmailSkipsEmpty(): void
    {
        $result = $this->controller->validateField(['email' => ''], ['email' => 'email']);
        $this->assertTrue($result);
    }

    // === اختبارات label ===

    public function testLabelChangesErrorMessage(): void
    {
        $result = $this->controller->validateField(
            ['product_name' => ''],
            ['product_name' => 'required|label:اسم المنتج']
        );
        $this->assertIsArray($result);
        $this->assertStringContains('اسم المنتج', $result['product_name']);
    }

    // === اختبارات قواعد متعددة ===

    public function testMultipleRulesPipe(): void
    {
        $result = $this->controller->validateField(
            ['name' => 'أحمد', 'price' => '50'],
            ['name' => 'required', 'price' => 'required|numeric|min:1']
        );
        $this->assertTrue($result);
    }

    public function testMultipleRulesArray(): void
    {
        $result = $this->controller->validateField(
            ['name' => 'أحمد', 'price' => '50'],
            ['name' => ['required'], 'price' => ['required', 'numeric', 'min:1']]
        );
        $this->assertTrue($result);
    }

    public function testMultipleRulesFirstFail(): void
    {
        $result = $this->controller->validateField(
            ['price' => 'abc'],
            ['price' => 'numeric|min:10']
        );
        $this->assertIsArray($result);
        // يجب أن يتوقف عند أول خطأ (numeric فشل)
        $this->assertStringContains('رقماً', $result['price']);
    }

    public function testMultipleFieldsWithErrors(): void
    {
        $result = $this->controller->validateField(
            ['name' => '', 'email' => 'invalid'],
            ['name' => 'required', 'email' => 'email']
        );
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
    }

    public function testEmptyRulesReturnsTrue(): void
    {
        $result = $this->controller->validateField(['name' => 'test'], []);
        $this->assertTrue($result);
    }

    // === اختبار getPaginationParams ===

    public function testPaginationParamsDefaults(): void
    {
        $params = $this->controller->testPaginationParams();
        $this->assertEqual(1, $params['page']);
        $this->assertEqual(20, $params['per_page']);
        $this->assertEqual(0, $params['offset']);
    }

    public function testPaginationParamsCustom(): void
    {
        $_GET['page'] = '3';
        $_GET['per_page'] = '50';
        $params = $this->controller->testPaginationParams();
        $this->assertEqual(3, $params['page']);
        $this->assertEqual(50, $params['per_page']);
        $this->assertEqual(100, $params['offset']);
        unset($_GET['page'], $_GET['per_page']);
    }

    public function testPaginationParamsClamped(): void
    {
        $_GET['page'] = '0';
        $_GET['per_page'] = '200';
        $params = $this->controller->testPaginationParams();
        $this->assertEqual(1, $params['page']);
        $this->assertEqual(100, $params['per_page']);
        unset($_GET['page'], $_GET['per_page']);
    }
}

/**
 * فئة اختبارية تغلف BaseController للوصول للدوال المحمية
 */
class TestableBaseController extends BaseController
{
    public function validateField(array $data, array $rules): array|bool
    {
        return $this->validate($data, $rules);
    }

    public function testPaginationParams(int $defaultPerPage = 20): array
    {
        return $this->getPaginationParams($defaultPerPage);
    }
}
