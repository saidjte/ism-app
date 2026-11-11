<?php
/**
 * ===== استجابة API موحدة =====
 * يضمن تنسيقاً ثابتاً لكل استجابات JSON
 */

class ApiResponse
{
    /**
     * استجابة ناجحة
     */
    public static function success(mixed $data = null, string $message = '', int $statusCode = 200): void
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * استجابة ناجحة مع إنشاء مورد جديد
     */
    public static function created(mixed $data = null, string $message = 'تم الإنشاء بنجاح'): void
    {
        self::success($data, $message, 201);
    }

    /**
     * استجابة خطأ
     */
    public static function error(string $message, string $type = 'general', int $statusCode = 400, array $details = []): void
    {
        self::send([
            'success' => false,
            'error'   => [
                'type'    => $type,
                'message' => $message,
                'details' => $details,
            ],
        ], $statusCode);
    }

    /**
     * خطأ تحقق (422)
     */
    public static function validationError(array $errors, string $message = 'خطأ في البيانات المدخلة'): void
    {
        self::error($message, 'validation', 422, $errors);
    }

    /**
     * غير مصرح (401)
     */
    public static function unauthorized(string $message = 'غير مصرح به. يرجى تسجيل الدخول'): void
    {
        self::error($message, 'auth', 401);
    }

    /**
     * ممنوع (403)
     */
    public static function forbidden(string $message = 'ليس لديك صلاحية للوصول'): void
    {
        self::error($message, 'forbidden', 403);
    }

    /**
     * غير موجود (404)
     */
    public static function notFound(string $message = 'المورد غير موجود'): void
    {
        self::error($message, 'not_found', 404);
    }

    /**
     * خطأ في الخادم (500)
     */
    public static function serverError(string $message = 'خطأ داخلي في الخادم'): void
    {
        self::error($message, 'server_error', 500);
    }

    /**
     * استجابة مع ترقيم الصفحات
     */
    public static function paginated(array $items, int $total, int $page, int $perPage, string $message = ''): void
    {
        $totalPages = max(1, (int) ceil($total / $perPage));

        self::send([
            'success'    => true,
            'message'    => $message,
            'data'       => $items,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'has_next'     => $page < $totalPages,
                'has_prev'     => $page > 1,
            ],
        ], 200);
    }

    /**
     * استجابة مخصصة (للتوافق مع الأنماط القديمة)
     * v4.16.9: إضافة دعم البنية الحرة
     */
    public static function custom(array $response, int $statusCode = 200): void
    {
        self::send($response, $statusCode);
    }

    /**
     * إرسال الاستجابة النهائية
     */
    private static function send(array $response, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
