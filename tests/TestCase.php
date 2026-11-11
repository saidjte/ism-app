<?php
/**
 * ===== إطار اختبارات خفيف =====
 * لا يحتاج أي تبعيات خارجية - يعمل مباشرة مع PHP >= 8.1
 * 
 * الاستخدام:
 *   php tests/run.php
 */

class TestCase
{
    protected string $name = '';
    protected int $passed = 0;
    protected int $failed = 0;
    protected int $errors = 0;
    protected array $failMessages = [];
    protected array $errorMessages = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * تشغيل كل اختبارات الصنف
     */
    public function runAll(): array
    {
        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($m) => str_starts_with($m, 'test'));

        foreach ($testMethods as $method) {
            // تشغيل output buffering لمنع أي تحذيرات (مثل session_start)
            // من العبث بإخراج الاختبارات على WAMP/Windows
            ob_start();
            try {
                if (method_exists($this, 'setUp')) {
                    $this->setUp();
                }
                $this->$method();
                $this->passed++;
            } catch (AssertionError $e) {
                $this->failed++;
                $this->failMessages[] = "  ✗ {$this->name}::{$method}: {$e->getMessage()}";
            } catch (\Throwable $e) {
                $this->errors++;
                $this->errorMessages[] = "  ⚠ {$this->name}::{$method}: " . get_class($e) . ": {$e->getMessage()}";
            } finally {
                if (method_exists($this, 'tearDown')) {
                    try { $this->tearDown(); } catch (\Throwable $e) {}
                }
                ob_end_clean();
            }
        }

        return [
            'name'    => $this->name,
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'errors'  => $this->errors,
            'failures' => $this->failMessages,
            'error_details' => $this->errorMessages,
        ];
    }

    // === دوال التأكيد (Assertions) ===

    protected function assertEqual(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected {$expStr}, got {$actStr}"
            );
        }
    }

    protected function assertNotEqual(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected === $actual) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Values should not be equal: " . var_export($expected, true)
            );
        }
    }

    protected function assertTrue(mixed $value, string $msg = ''): void
    {
        if ($value !== true) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected true, got " . var_export($value, true)
            );
        }
    }

    protected function assertFalse(mixed $value, string $msg = ''): void
    {
        if ($value !== false) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected false, got " . var_export($value, true)
            );
        }
    }

    protected function assertNull(mixed $value, string $msg = ''): void
    {
        if ($value !== null) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected null, got " . var_export($value, true)
            );
        }
    }

    protected function assertNotNull(mixed $value, string $msg = ''): void
    {
        if ($value === null) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected non-null value"
            );
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $msg = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Array does not contain key: {$key}"
            );
        }
    }

    protected function assertCount(int $expected, array|\Countable $value, string $msg = ''): void
    {
        $actual = count($value);
        if ($actual !== $expected) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected count {$expected}, got {$actual}"
            );
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $msg = ''): void
    {
        if (!in_array($needle, $haystack, false)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Array does not contain " . var_export($needle, true)
            );
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $msg = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "String does not contain \"{$needle}\""
            );
        }
    }

    protected function assertGreaterThan(int|float $expected, int|float $actual, string $msg = ''): void
    {
        if (!($actual > $expected)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected {$actual} > {$expected}"
            );
        }
    }

    protected function assertLessThan(int|float $expected, int|float $actual, string $msg = ''): void
    {
        if (!($actual < $expected)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected {$actual} < {$expected}"
            );
        }
    }

    protected function assertGreaterThanOrEqual(int|float $expected, int|float $actual, string $msg = ''): void
    {
        if (!($actual >= $expected)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected {$actual} >= {$expected}"
            );
        }
    }

    protected function assertIsArray(mixed $value, string $msg = ''): void
    {
        if (!is_array($value)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected array, got " . gettype($value)
            );
        }
    }

    protected function assertIsString(mixed $value, string $msg = ''): void
    {
        if (!is_string($value)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected string, got " . gettype($value)
            );
        }
    }

    protected function assertIsNumeric(mixed $value, string $msg = ''): void
    {
        if (!is_numeric($value)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected numeric, got " . var_export($value, true)
            );
        }
    }

    protected function assertInstanceOf(string $class, mixed $object, string $msg = ''): void
    {
        if (!($object instanceof $class)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected instance of {$class}, got " . get_class($object)
            );
        }
    }

    protected function assertThrows(string $exceptionClass, callable $callback, string $msg = ''): void
    {
        $caught = false;
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                $caught = true;
            } else {
                throw new AssertionError(
                    ($msg ? "{$msg}: " : '') . "Expected {$exceptionClass}, but caught " . get_class($e)
                );
            }
        }
        if (!$caught) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected {$exceptionClass} to be thrown, but nothing was thrown"
            );
        }
    }

    protected function assertDoesNotThrow(callable $callback, string $msg = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected no exception, but " . get_class($e) . " was thrown: {$e->getMessage()}"
            );
        }
    }

    protected function assertMatchesRegex(string $pattern, string $string, string $msg = ''): void
    {
        if (!preg_match($pattern, $string)) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "String does not match pattern {$pattern}"
            );
        }
    }

    protected function assertFloatEqual(float $expected, float $actual, float $epsilon = 0.01, string $msg = ''): void
    {
        if (abs($expected - $actual) > $epsilon) {
            throw new AssertionError(
                ($msg ? "{$msg}: " : '') . "Expected float {$expected} ± {$epsilon}, got {$actual}"
            );
        }
    }

    /**
     * Helper: إنشاء مجلد مؤقت للاختبارات
     */
    protected function createTempDir(string $prefix = 'test_'): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Helper: حذف مجلد مؤقت
     */
    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
}
