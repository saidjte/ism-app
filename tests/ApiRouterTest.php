<?php
/**
 * اختبارات فئة ApiRouter
 * - تسجيل المسارات (GET, POST, PUT, DELETE)
 * - استخراج المعاملات {param}
 * - القيود ({id:\d+})
 * - تجاوز الطريقة عبر _method
 * - المجموعات (group)
 * - Middleware
 */

class ApiRouterTest extends TestCase
{
    private array $executedHandlers = [];

    protected function setUp(): void
    {
        $this->executedHandlers = [];
    }

    /**
     * Helper: محاكاة بيئة طلب HTTP
     */
    private function mockRequest(string $method, string $uri, array $post = [], array $server = []): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_POST = $post;
        $_GET = [];
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Helper: إنشاء handler يسجّل التنفيذ
     */
    private function trackHandler(string &$result, mixed $expectedParams = null): callable
    {
        return function (array $params) use (&$result, $expectedParams) {
            $result = ['called' => true, 'params' => $params];
            if ($expectedParams !== null) {
                $result['params_match'] = ($params === $expectedParams);
            }
        };
    }

    // === اختبارات تسجيل المسارات ===

    public function testRouteRegistrationReturnsSelf(): void
    {
        $router = new ApiRouter();
        $result = $router->get('test', fn() => null);
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    public function testPostRouteRegistration(): void
    {
        $router = new ApiRouter();
        $result = $router->post('test', fn() => null);
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    public function testPutRouteRegistration(): void
    {
        $router = new ApiRouter();
        $result = $router->put('test', fn() => null);
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    public function testDeleteRouteRegistration(): void
    {
        $router = new ApiRouter();
        $result = $router->delete('test', fn() => null);
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    public function testMatchRouteRegistration(): void
    {
        $router = new ApiRouter();
        $result = $router->match(['GET', 'POST'], 'test', fn() => null);
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    public function testAnyRouteRegistration(): void
    {
        $router = new ApiRouter();
        $result = $router->any('test', fn() => null);
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    // === اختبارات pathToRegex ===

    public function testSimplePathRegex(): void
    {
        $router = new ReflectionClass(ApiRouter::class);
        $method = $router->getMethod('pathToRegex');
        $method->setAccessible(true);

        $instance = new ApiRouter();
        $regex = $method->invoke($instance, '/products');

        $this->assertTrue((bool) preg_match($regex, '/products'));
        $this->assertFalse((bool) preg_match($regex, '/products/1'));
    }

    public function testPathWithParamRegex(): void
    {
        $router = new ReflectionClass(ApiRouter::class);
        $method = $router->getMethod('pathToRegex');
        $method->setAccessible(true);

        $instance = new ApiRouter();
        $regex = $method->invoke($instance, '/products/{id}');

        $matches = [];
        $this->assertTrue((bool) preg_match($regex, '/products/123', $matches));
        $this->assertEqual('123', $matches['id']);
    }

    public function testPathWithNumericConstraint(): void
    {
        $router = new ReflectionClass(ApiRouter::class);
        $method = $router->getMethod('pathToRegex');
        $method->setAccessible(true);

        $instance = new ApiRouter();
        $regex = $method->invoke($instance, '/products/{id:\\d+}');

        $this->assertTrue((bool) preg_match($regex, '/products/123'));
        $this->assertFalse((bool) preg_match($regex, '/products/abc'));
    }

    public function testPathWithMultipleParams(): void
    {
        $router = new ReflectionClass(ApiRouter::class);
        $method = $router->getMethod('pathToRegex');
        $method->setAccessible(true);

        $instance = new ApiRouter();
        $regex = $method->invoke($instance, '/users/{userId}/posts/{postId}');

        $matches = [];
        $this->assertTrue((bool) preg_match($regex, '/users/5/posts/42', $matches));
        $this->assertEqual('5', $matches['userId']);
        $this->assertEqual('42', $matches['postId']);
    }

    // === اختبارات Group ===

    public function testGroupRegistration(): void
    {
        $router = new ApiRouter();
        $result = $router->group('/api', function ($r) {
            $r->get('users', fn() => null);
        });
        $this->assertInstanceOf(ApiRouter::class, $result);
    }

    // === اختبارات Middleware ===

    public function testMiddlewareRegistration(): void
    {
        $router = new ApiRouter();
        $called = false;
        $result = $router->middleware(function ($params) use (&$called) {
            $called = true;
            return true;
        });
        $this->assertInstanceOf(ApiRouter::class, $result);
    }
}
