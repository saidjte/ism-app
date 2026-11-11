<?php
/**
 * ===== المتحكم الأساسي (Base Controller) =====
 * يوفر دوال مشتركة لكل متحكمات API
 */

require_once __DIR__ . '/ApiResponse.php';

class BaseController
{
    /**
     * قراءة جسم طلب JSON
     */
    protected function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * الحصول على معامل من GET مع قيمة افتراضية
     */
    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * الحصول على معامل من POST مع قيمة افتراضية
     */
    protected function postParam(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * الحصول على معامل من JSON أو POST
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        $json = $this->getJsonInput();
        if (isset($json[$key])) {
            return $json[$key];
        }
        return $_POST[$key] ?? $default;
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
     * التحقق من صحة المدخلات
     * @return array|true true إذا كانت صحيحة، أو مصفوفة الأخطاء
     */
    protected function validate(array $data, array $rules): array|bool
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $data[$field] ?? null;
            $label = $field;

            // المرحلة 1: استخراج اسم الحقل المعروض (label) أولاً قبل أي تحقق
            foreach ($ruleList as $rule) {
                $rule = trim($rule);
                if (str_starts_with($rule, 'label:')) {
                    $label = substr($rule, 6);
                    break; // أول label فقط
                }
            }

            // المرحلة 2: تنفيذ قواعد التحقق
            foreach ($ruleList as $rule) {
                $rule = trim($rule);

                // تخطي label (تم معالجته في المرحلة 1)
                if (str_starts_with($rule, 'label:')) {
                    continue;
                }

                // التحقق المطلوب
                if ($rule === 'required') {
                    if ($value === null || $value === '') {
                        $errors[$field] = "حقل {$label} مطلوب";
                        break;
                    }
                }

                // رقم
                if ($rule === 'numeric' && $value !== null && $value !== '') {
                    if (!is_numeric($value)) {
                        $errors[$field] = "حقل {$label} يجب أن يكون رقماً";
                        break;
                    }
                }

                // عدد صحيح
                if ($rule === 'integer' && $value !== null && $value !== '') {
                    if (!is_numeric($value) || intval($value) != $value) {
                        $errors[$field] = "حقل {$label} يجب أن يكون عدداً صحيحاً";
                        break;
                    }
                }

                // قيمة دنيا
                if (str_starts_with($rule, 'min:')) {
                    $min = substr($rule, 4);
                    if (is_numeric($value) && !is_bool($value)) {
                        // قيمة رقمية: مقارنة أرقام
                        if (floatval($value) < floatval($min)) {
                            $errors[$field] = "حقل {$label} يجب أن يكون {$min} على الأقل";
                            break;
                        }
                    } elseif (is_string($value) && !is_numeric($value)) {
                        // قيمة نصية: مقارنة طول النص
                        if (mb_strlen($value) < intval($min)) {
                            $errors[$field] = "حقل {$label} يجب أن يكون {$min} حرفاً على الأقل";
                            break;
                        }
                    }
                }

                // قيمة عظمى
                if (str_starts_with($rule, 'max:')) {
                    $max = substr($rule, 4);
                    if (is_numeric($value) && !is_bool($value)) {
                        if (floatval($value) > floatval($max)) {
                            $errors[$field] = "حقل {$label} يجب أن لا يتجاوز {$max}";
                            break;
                        }
                    } elseif (is_string($value) && !is_numeric($value)) {
                        if (mb_strlen($value) > intval($max)) {
                            $errors[$field] = "حقل {$label} يجب أن لا يتجاوز {$max} حرفاً";
                            break;
                        }
                    }
                }

                // في قائمة محددة
                if (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if ($value !== null && $value !== '' && !in_array($value, $allowed)) {
                        $errors[$field] = "قيمة حقل {$label} غير صحيحة";
                        break;
                    }
                }

                // التاريخ
                if ($rule === 'date' && $value !== null && $value !== '') {
                    if (strtotime($value) === false) {
                        $errors[$field] = "حقل {$label} يجب أن يكون تاريخاً صحيحاً";
                        break;
                    }
                }

                // البريد الإلكتروني
                if ($rule === 'email' && $value !== null && $value !== '') {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field] = "حقل {$label} يجب أن يكون بريداً إلكترونياً صحيحاً";
                        break;
                    }
                }
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * التحقق من CSRF token
     */
    protected function verifyCsrf(): bool
    {
        if (!function_exists('verifyCSRFToken')) {
            return true; // CSRF غير مفعل
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        return verifyCSRFToken($token);
    }

    /**
     * التحقق من تسجيل الدخول
     */
    protected function requireAuth(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // إعدادات أمنية مركزية من config/session.php
            if (!function_exists('startSecureSession')) {
                require_once __DIR__ . '/../../config/session.php';
            }
            startSecureSession();
        }

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
            ApiResponse::unauthorized();
        }
    }

    /**
     * التحقق من صلاحية المدير
     */
    protected function requireAdmin(): void
    {
        $this->requireAuth();

        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            ApiResponse::forbidden();
        }
    }

    /**
     * قراءة معامل الصفحات
     */
    protected function getPaginationParams(int $defaultPerPage = 20): array
    {
        $page = max(1, intval($_GET['page'] ?? 1));
        $perPage = max(1, min(100, intval($_GET['per_page'] ?? $defaultPerPage)));

        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }
}
