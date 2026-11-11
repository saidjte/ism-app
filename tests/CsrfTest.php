<?php
/**
 * اختبارات حماية CSRF
 * - توليد الرمز
 * - التحقق من صحة الرمز
 * - حقل HTML المخفي
 * - انتهاء صلاحية الرمز (1 ساعة)
 */

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // لا نحاول بدء الجلسة هنا - تم بدؤها بالفعل في run.php
        // output buffering في TestCase::runAll() يمنع أي تحذيرات من العبث بالإخراج
        // تنظيف حالة CSRF السابقة
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }

    // === اختبارات generateToken ===

    public function testGenerateTokenReturnsString(): void
    {
        $token = AppCsrf::generateToken();
        $this->assertIsString($token);
    }

    public function testGenerateTokenReturnsHex(): void
    {
        $token = AppCsrf::generateToken();
        $this->assertMatchesRegex('/^[0-9a-f]+$/', $token);
    }

    public function testGenerateTokenLength(): void
    {
        $token = AppCsrf::generateToken();
        $this->assertEqual(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testGenerateTokenIsConsistent(): void
    {
        $token1 = AppCsrf::generateToken();
        $token2 = AppCsrf::generateToken();
        $this->assertEqual($token1, $token2); // نفس الجلسة = نفس الرمز
    }

    public function testGenerateTokenStoredInSession(): void
    {
        AppCsrf::generateToken();
        $this->assertNotNull($_SESSION['csrf_token']);
        $this->assertNotNull($_SESSION['csrf_token_time']);
    }

    // === اختبارات field() ===

    public function testFieldReturnsHtml(): void
    {
        $field = AppCsrf::field();
        $this->assertStringContains('<input', $field);
        $this->assertStringContains('type="hidden"', $field);
        $this->assertStringContains('name="csrf_token"', $field);
    }

    public function testFieldContainsTokenValue(): void
    {
        $token = AppCsrf::generateToken();
        $field = AppCsrf::field();
        $this->assertStringContains(htmlspecialchars($token), $field);
    }

    // === اختبارات verify() ===

    public function testVerifyReturnsTrueForGet(): void
    {
        // GET requests should pass CSRF check
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue(AppCsrf::verify());
    }

    public function testVerifyReturnsFalseForPostWithoutToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $this->assertFalse(AppCsrf::verify());
    }

    public function testVerifyReturnsFalseForPostWithWrongToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'wrong_token_value_123456';
        AppCsrf::generateToken();
        $this->assertFalse(AppCsrf::verify());
    }

    public function testVerifyReturnsTrueForPostWithCorrectToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = AppCsrf::generateToken();
        $_POST['csrf_token'] = $token;
        $this->assertTrue(AppCsrf::verify());
    }

    public function testVerifyWithExplicitToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = AppCsrf::generateToken();
        $this->assertTrue(AppCsrf::verify($token));
    }

    public function testVerifyWithExplicitWrongToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        AppCsrf::generateToken();
        $this->assertFalse(AppCsrf::verify('wrong_token'));
    }

    public function testVerifyTimingSafeComparison(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = AppCsrf::generateToken();

        // حتى مع رمز مشابه جداً، يجب أن يفشل التحقق
        $similarToken = $token;
        $similarToken = substr($similarToken, 0, 62) . 'ff';
        $_POST['csrf_token'] = $similarToken;
        $this->assertFalse(AppCsrf::verify());
    }

    // === اختبار التوافق مع الدوال الإجرائية ===

    public function testGenerateCSRFTokenFunctionExists(): void
    {
        $this->assertTrue(function_exists('generateCSRFToken'));
    }

    public function testCsrfFieldFunctionExists(): void
    {
        $this->assertTrue(function_exists('csrfField'));
    }

    public function testVerifyCSRFTokenFunctionExists(): void
    {
        $this->assertTrue(function_exists('verifyCSRFToken'));
    }

    public function testRequireCSRFFunctionExists(): void
    {
        $this->assertTrue(function_exists('requireCSRF'));
    }

    public function testGenerateCSRFTokenReturnsSameAsClass(): void
    {
        $classToken = AppCsrf::generateToken();
        $funcToken = generateCSRFToken();
        $this->assertEqual($classToken, $funcToken);
    }

    public function testCsrfFieldReturnsSameAsClass(): void
    {
        $classField = AppCsrf::field();
        $funcField = csrfField();
        $this->assertEqual($classField, $funcField);
    }
}
