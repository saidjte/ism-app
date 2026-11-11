<?php
/**
 * ===== متحكم تنبيهات الصلاحية API =====
 * إخفاء نافذة تنبيه الصلاحية مع آلية إخفاء ذكي
 */

require_once __DIR__ . '/ApiController.php';

class ExpiryController extends ApiController
{
    /**
     * إخفاء نافذة تنبيه الصلاحية
     * POST /api/dismiss-expiry-popup?sig=xxx
     */
    public function dismissPopup(array $params): void
    {
        $sig = $_GET['sig'] ?? '';
        if (empty($sig)) {
            ApiResponse::error('معامل التوقيع مطلوب', 'validation', 422);
        }

        // تخزين وقت الإخفاء
        $_SESSION['expiry_popup_dismissed_at'] = time();
        // تخزين توقيع التنبيه الحالي للكشف عن التنبيهات الجديدة
        $_SESSION['expiry_popup_signature'] = $sig;

        ApiResponse::success(
            ['dismissed_until' => date('Y-m-d H:i:s', time() + 7200)],
            'تم إخفاء التنبيه. سيظهر مجدداً بعد ساعتين أو عند وجود تنبيه جديد.'
        );
    }
}
