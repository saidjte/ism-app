<?php
/**
 * ===== المتحكم الأساسي لـ API =====
 * يمدد BaseController ويضيف دوال مشتركة لكل متحكمات API
 *
 * كل متحكمات API ترث من هذا الصنف ليحصلوا على:
 *   - قراءة JSON body مع دعم POST العادي
 *   - التحقق من المدخلات (validate)
 *   - معاملات الصفحات (pagination)
 *   - تسجيل الأخطاء الموحد (appLog)
 */

require_once __DIR__ . '/../BaseController.php';

class ApiController extends BaseController
{
    /** @var array|null ذاكرة مؤقتة لبيانات JSON المُحلّلة */
    private ?array $jsonCache = null;

    /**
     * قراءة جسم طلب JSON (مع تخزين مؤقت)
     * يتجنب قراءة php://input عدة مرات
     */
    protected function getJsonInput(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $raw = file_get_contents('php://input');

        if (stripos($contentType, 'application/json') !== false || !empty($raw)) {
            $data = json_decode($raw, true);
            $this->jsonCache = is_array($data) ? $data : [];
        } else {
            $this->jsonCache = [];
        }

        return $this->jsonCache;
    }

    /**
     * الحصول على معامل من أي مصدر (JSON → POST → GET)
     * يبحث بالترتيب: JSON body أولاً، ثم POST، ثم GET
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        $json = $this->getJsonInput();
        if (isset($json[$key])) {
            return $json[$key];
        }
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        return $_GET[$key] ?? $default;
    }

    /**
     * الحصول على كل المدخلات (JSON + POST + GET)
     */
    protected function all(): array
    {
        $json = $this->getJsonInput();
        return array_merge($_GET, $_POST, $json);
    }

    /**
     * نقل بيانات JSON إلى $_POST للتوافق مع الدوال القديمة
     * مفيد للدوال التي تتوقع البيانات في $_POST
     */
    protected function mergeJsonToPost(): void
    {
        $json = $this->getJsonInput();
        foreach ($json as $key => $value) {
            if (!isset($_POST[$key])) {
                $_POST[$key] = $value;
            }
        }
    }

    /**
     * التحقق من صحة المدخلات مع إرجاع خطأ API تلقائي
     * إذا فشل التحقق، يُرجع ApiResponse::validationError() تلقائياً
     *
     * @return array|true البيانات المُنظَّفة أو true إذا نجح
     */
    protected function validateOrError(array $data, array $rules): array|bool
    {
        $result = $this->validate($data, $rules);
        if ($result !== true) {
            ApiResponse::validationError($result);
        }
        return $result;
    }

    /**
     * الحصول على معرف المستخدم الحالي
     */
    protected function userId(): int
    {
        return intval($_SESSION['user_id'] ?? 0);
    }

    /**
     * التحقق من أن الطلب من نوع POST
     */
    protected function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('طريقة الطلب غير مسموحة', 'method_not_allowed', 405);
            return; // v5.11.2: إضافة return دفاعية
        }
    }

    /**
     * تجديد رمز CSRF وإرجاعه
     */
    protected function regenerateCsrf(): string
    {
        $newCsrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $newCsrf;
        $_SESSION['csrf_token_time'] = time();
        return $newCsrf;
    }
}
