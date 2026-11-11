<?php
/**
 * ===== استثناءات التطبيق المخصصة =====
 * نظام استثناءات موحد للتعامل مع الأخطاء
 */

class AppException extends Exception
{
    /** @var string نوع الخطأ: validation, auth, database, not_found, general */
    private string $errorType;
    /** @var array بيانات إضافية */
    private array $details;

    public function __construct(string $message, string $type = 'general', int $code = 0, array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorType = $type;
        $this->details = $details;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * HTTP status code المناسب لنوع الخطأ
     */
    public function getHttpStatusCode(): int
    {
        return match ($this->errorType) {
            'validation'  => 422,
            'auth'        => 401,
            'forbidden'   => 403,
            'not_found'   => 404,
            'database'    => 500,
            'rate_limit'  => 429,
            default       => 500,
        };
    }

    // === factory methods سريعة ===

    public static function validation(string $message, array $details = []): self
    {
        return new self($message, 'validation', 422, $details);
    }

    public static function auth(string $message = 'غير مصرح به'): self
    {
        return new self($message, 'auth', 401);
    }

    public static function forbidden(string $message = 'ليس لديك صلاحية'): self
    {
        return new self($message, 'forbidden', 403);
    }

    public static function notFound(string $message = 'غير موجود'): self
    {
        return new self($message, 'not_found', 404);
    }

    public static function database(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 'database', 500, [], $previous);
    }

    public static function rateLimit(string $message = 'طلبات كثيرة، حاول لاحقاً'): self
    {
        return new self($message, 'rate_limit', 429);
    }
}
