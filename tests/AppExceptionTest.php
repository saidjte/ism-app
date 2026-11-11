<?php
/**
 * اختبارات فئة AppException
 * - HTTP status codes المناسبة لكل نوع خطأ
 * - Factory methods
 * - Error type & details
 */

class AppExceptionTest extends TestCase
{
    // === اختبارات HTTP Status Codes ===

    public function testValidationErrorReturns422(): void
    {
        $e = AppException::validation('خطأ في البيانات');
        $this->assertEqual(422, $e->getHttpStatusCode());
    }

    public function testAuthErrorReturns401(): void
    {
        $e = AppException::auth('غير مصرح');
        $this->assertEqual(401, $e->getHttpStatusCode());
    }

    public function testForbiddenErrorReturns403(): void
    {
        $e = AppException::forbidden('ممنوع');
        $this->assertEqual(403, $e->getHttpStatusCode());
    }

    public function testNotFoundErrorReturns404(): void
    {
        $e = AppException::notFound('غير موجود');
        $this->assertEqual(404, $e->getHttpStatusCode());
    }

    public function testDatabaseErrorReturns500(): void
    {
        $e = AppException::database('خطأ في قاعدة البيانات');
        $this->assertEqual(500, $e->getHttpStatusCode());
    }

    public function testRateLimitErrorReturns429(): void
    {
        $e = AppException::rateLimit('طلبات كثيرة');
        $this->assertEqual(429, $e->getHttpStatusCode());
    }

    public function testGeneralErrorReturns500(): void
    {
        $e = new AppException('خطأ عام', 'general');
        $this->assertEqual(500, $e->getHttpStatusCode());
    }

    // === اختبارات Error Type ===

    public function testValidationErrorType(): void
    {
        $e = AppException::validation('خطأ');
        $this->assertEqual('validation', $e->getErrorType());
    }

    public function testAuthErrorType(): void
    {
        $e = AppException::auth();
        $this->assertEqual('auth', $e->getErrorType());
    }

    public function testForbiddenErrorType(): void
    {
        $e = AppException::forbidden();
        $this->assertEqual('forbidden', $e->getErrorType());
    }

    public function testNotFoundErrorType(): void
    {
        $e = AppException::notFound();
        $this->assertEqual('not_found', $e->getErrorType());
    }

    public function testDatabaseErrorType(): void
    {
        $e = AppException::database('خطأ');
        $this->assertEqual('database', $e->getErrorType());
    }

    public function testRateLimitErrorType(): void
    {
        $e = AppException::rateLimit();
        $this->assertEqual('rate_limit', $e->getErrorType());
    }

    // === اختبارات Details ===

    public function testValidationWithDetails(): void
    {
        $details = ['field' => 'name', 'rule' => 'required'];
        $e = AppException::validation('خطأ', $details);
        $this->assertEqual($details, $e->getDetails());
    }

    public function testDefaultDetailsEmptyArray(): void
    {
        $e = AppException::auth();
        $this->assertEqual([], $e->getDetails());
    }

    // === اختبارات Message ===

    public function testCustomMessage(): void
    {
        $e = AppException::notFound('المنتج غير موجود');
        $this->assertEqual('المنتج غير موجود', $e->getMessage());
    }

    public function testDefaultAuthMessage(): void
    {
        $e = AppException::auth();
        $this->assertEqual('غير مصرح به', $e->getMessage());
    }

    public function testDefaultForbiddenMessage(): void
    {
        $e = AppException::forbidden();
        $this->assertEqual('ليس لديك صلاحية', $e->getMessage());
    }

    public function testDefaultNotFoundMessage(): void
    {
        $e = AppException::notFound();
        $this->assertEqual('غير موجود', $e->getMessage());
    }

    public function testDefaultRateLimitMessage(): void
    {
        $e = AppException::rateLimit();
        $this->assertEqual('طلبات كثيرة، حاول لاحقاً', $e->getMessage());
    }

    // === اختبارات Hierarchy ===

    public function testExtendsException(): void
    {
        $e = new AppException('test');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testDatabaseErrorWithPrevious(): void
    {
        $prev = new \RuntimeException('DB connection failed');
        $e = AppException::database('خطأ', $prev);
        $this->assertEqual($prev, $e->getPrevious());
    }
}
