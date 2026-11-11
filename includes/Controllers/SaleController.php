<?php
/**
 * ===== متحكم مبيعات API =====
 * يعالج عمليات البيع من نقطة البيع (POS)
 * يفوض كل منطق المعالجة إلى SaleService (المصدر الوحيد)
 */

require_once __DIR__ . '/ApiController.php';

class SaleController extends ApiController
{
    /**
     * معالجة عملية بيع جديدة من POS
     * POST /api/pos-process-sale
     */
    public function processSale(array $params): void
    {
        $this->requirePost();

        // قراءة البيانات من JSON body
        $input = $this->getJsonInput();

        // صلاحية البيع
        if (function_exists('hasPermission') && !hasPermission('sales.add')) {
            ApiResponse::forbidden('ليس لديك صلاحية تسجيل المبيعات');
        }

        // التحقق من وجود عناصر
        if (empty($input['items'])) {
            ApiResponse::validationError(['items' => 'حقل عناصر البيع مطلوب']);
        }

        // تفويض كل منطق المعالجة إلى SaleService (المصدر الوحيد)
        $result = SaleService::create([
            'items' => $input['items'] ?? [],
            'sale_date' => $input['sale_date'] ?? date('Y-m-d'),
            'notes' => trim($input['notes'] ?? ''),
            'discount' => floatval($input['discount'] ?? 0),
            'paid_amount' => floatval($input['paid_amount'] ?? 0),
            'source' => 'pos',
            'send_notification' => true,
        ]);

        if (!$result['success']) {
            $code = 400;
            if (strpos($result['message'], 'غير موجود') !== false) {
                $code = 404;
            }
            ApiResponse::error($result['message'], 'sale_error', $code);
        }

        // تحديث رمز CSRF بعد العملية الناجحة
        $newCsrf = $this->regenerateCsrf();

        ApiResponse::success(
            array_merge($result['data'], ['csrf_token' => $newCsrf]),
            $result['message']
        );
    }
}
