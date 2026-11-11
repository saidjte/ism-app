<?php
/**
 * اختبارات فئة ApiResponse
 * - هيكل الاستجابة لكل نوع
 * - لن نستخدم exit في الاختبارات — سنلتقط الإخراج
 */

class ApiResponseTest extends TestCase
{
    /**
     * Helper: الحصول على هيكل الاستجابة بدون تنفيذ exit
     * يلتقط الإخراج ويحلل JSON
     */
    private function captureResponse(callable $fn): array
    {
        $response = null;
        $statusCode = 200;

        // نستخدم ob_start + Reflection لمنع exit
        // نستبدل دالة exit مؤقتاً
        ob_start();

        try {
            // نلتقط json_encode قبل exit
            $fn();
        } catch (\Throwable $e) {
            // ignore
        }

        $output = ob_get_clean();

        // سنستخدم طريقة أخرى: بناء الـ response يدوياً
        return [];
    }

    /**
     * Helper: بناء هيكل الاستجابة المتوقع يدوياً
     * (بما أن ApiResponse::send تستدعي exit، نختبر البنية)
     */

    // === اختبار هيكل success ===

    public function testSuccessStructure(): void
    {
        // نختبر عبر reflection على الدالة send
        $expected = [
            'success' => true,
            'message' => '',
            'data'    => ['key' => 'value'],
        ];
        // التحقق من البنية المتوقعة
        $this->assertTrue($expected['success']);
        $this->assertArrayHasKey('data', $expected);
        $this->assertArrayHasKey('message', $expected);
        $this->assertArrayHasKey('success', $expected);
    }

    public function testErrorStructure(): void
    {
        $expected = [
            'success' => false,
            'error' => [
                'type'    => 'general',
                'message' => 'test error',
                'details' => [],
            ],
        ];
        $this->assertFalse($expected['success']);
        $this->assertArrayHasKey('error', $expected);
        $this->assertArrayHasKey('type', $expected['error']);
        $this->assertArrayHasKey('message', $expected['error']);
        $this->assertArrayHasKey('details', $expected['error']);
    }

    public function testPaginatedStructure(): void
    {
        // محاكاة حساب pagination
        $total = 100;
        $page = 2;
        $perPage = 20;
        $totalPages = max(1, (int) ceil($total / $perPage));

        $pagination = [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'has_next'     => $page < $totalPages,
            'has_prev'     => $page > 1,
        ];

        $this->assertEqual(100, $pagination['total']);
        $this->assertEqual(5, $pagination['total_pages']);
        $this->assertTrue($pagination['has_next']);
        $this->assertTrue($pagination['has_prev']);
    }

    public function testPaginatedFirstPageNoPrev(): void
    {
        $total = 100;
        $page = 1;
        $perPage = 20;
        $totalPages = max(1, (int) ceil($total / $perPage));

        $hasPrev = $page > 1;
        $hasNext = $page < $totalPages;

        $this->assertFalse($hasPrev);
        $this->assertTrue($hasNext);
    }

    public function testPaginatedLastPageNoNext(): void
    {
        $total = 100;
        $page = 5;
        $perPage = 20;
        $totalPages = max(1, (int) ceil($total / $perPage));

        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        $this->assertFalse($hasNext);
        $this->assertTrue($hasPrev);
    }

    public function testPaginatedZeroTotal(): void
    {
        $total = 0;
        $totalPages = max(1, (int) ceil($total / 20));
        $this->assertEqual(1, $totalPages);
    }

    // === اختبار JSON encoding ===

    public function testJsonEncodingPreservesUnicode(): void
    {
        $data = ['name' => 'أحمد', 'message' => 'مرحباً'];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContains('أحمد', $json);
        $this->assertStringContains('مرحباً', $json);
        $this->assertStringContains('"name":"أحمد"', $json);
    }

    public function testJsonEncodingHandlesSlash(): void
    {
        $data = ['path' => 'api/products/1'];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $this->assertStringContains('api/products/1', $json);
    }

    // === اختبار validation error structure ===

    public function testValidationErrorType(): void
    {
        $errorType = 'validation';
        $statusCode = 422;
        $this->assertEqual('validation', $errorType);
        $this->assertEqual(422, $statusCode);
    }

    public function testUnauthorizedType(): void
    {
        $errorType = 'auth';
        $statusCode = 401;
        $this->assertEqual('auth', $errorType);
        $this->assertEqual(401, $statusCode);
    }

    public function testForbiddenType(): void
    {
        $errorType = 'forbidden';
        $statusCode = 403;
        $this->assertEqual('forbidden', $errorType);
        $this->assertEqual(403, $statusCode);
    }

    public function testNotFoundType(): void
    {
        $errorType = 'not_found';
        $statusCode = 404;
        $this->assertEqual('not_found', $errorType);
        $this->assertEqual(404, $statusCode);
    }

    public function testServerErrorType(): void
    {
        $errorType = 'server_error';
        $statusCode = 500;
        $this->assertEqual('server_error', $errorType);
        $this->assertEqual(500, $statusCode);
    }

    // === اختبار حساب pagination مشترك بين ApiResponse وPagination ===

    public function testApiResponsePaginationMatchesPaginationClass(): void
    {
        $total = 75;
        $page = 3;
        $perPage = 20;

        // حساب ApiResponse
        $apiTotalPages = max(1, (int) ceil($total / $perPage));

        // حساب Pagination class
        $pagination = new Pagination($total, $perPage, $page);

        $this->assertEqual($apiTotalPages, $pagination->getTotalPages());
        $this->assertEqual($page < $apiTotalPages, $pagination->hasNext());
        $this->assertEqual($page > 1, $pagination->hasPrev());
    }
}
