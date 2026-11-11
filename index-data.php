<?php
/**
 * ===== بيانات لوحة التحكم (Controller) =====
 * يفصل منطق جلب البيانات عن العرض (HTML) والتفاعل (JS)
 *
 * المسؤوليات:
 * - التحقق من المصادقة والصلاحيات
 * - جلب إحصائيات لوحة التحكم من DashboardData
 * - جلب بيانات الصلاحية (❌ بدون كاش — بيانات حقيقية دائماً)
 * - جلب أفضل المنتجات مبيعاً
 * - تحديد حالة نافذة التنبيه
 *
 * الاستخدام:
 *   require_once __DIR__ . '/index-data.php';
 *   // المتغيرات متاحة الآن: $stats, $expiry_data, $top_selling_products, ...
 *
 * @since v4.17.0 — تفكيك God File index.php
 * @since v5.6.0 — إزالة التخزين المؤقت نهائياً + استخدام SaleService
 */

// === حماية من الاستدعاء المباشر بدون bootstrap ===
if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/includes/bootstrap.php';
}
require_once __DIR__ . '/config/upgrades.php'; // APP_VERSION, getCurrentDBVersion, compareVersions
requireLogin();

// === التحقق من النسخ التلقائي (للمدير فقط) ===
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    require_once __DIR__ . '/config/backup.php';
    checkAutoBackup();
}

// === جلب رقم إصدار قاعدة البيانات الحالي ===
try {
    $pdo_conn = getDBConnection();
    $db_version = getCurrentDBVersion($pdo_conn);
    $needs_upgrade = $pdo_conn ? (compareVersions($db_version, APP_VERSION) < 0) : false;
} catch (\Throwable $e) {
    $db_version = '0.0.0';
    $needs_upgrade = false;
}

// === فلاتر التاريخ ===
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');

// === جلب إحصائيات لوحة التحكم من DashboardData ===
require_once __DIR__ . '/includes/DashboardData.php';
$stats = DashboardData::getStats($date_from, $date_to);

// === استخراج المتغيرات من مصفوفة الإحصائيات ===
// (نستخرجها كمتغيرات مستقلة للتوافق مع القالب الحالي)
$total_products       = $stats['total_products'];
$total_stock_value    = $stats['total_stock_value'];
$total_stock_value_retail = $stats['total_stock_value_retail'];
$total_purchases      = $stats['total_purchases'];
$total_sales          = $stats['total_sales'];
$low_stock_count      = $stats['low_stock_count'];
$gross_profit         = $stats['gross_profit'];
$net_profit           = $stats['net_profit'];
$total_expenses       = $stats['total_expenses'];
$expenses_table_exists = $stats['expenses_table_exists'];
$prev_sales           = $stats['prev_sales'];
$prev_purchases       = $stats['prev_purchases'];
$prev_net_profit      = $stats['prev_net_profit'];
$prev_expenses        = $stats['prev_expenses'];
$monthly_stats        = $stats['monthly_stats'];
$recent_products      = $stats['recent_products'];
$recent_purchases     = $stats['recent_purchases'];
$recent_sales         = $stats['recent_sales'];
$recent_expenses      = $stats['recent_expenses'];

// === بيانات الصلاحية ===
$expiry_data = IndexDataExpiry::getExpiryData();

// استخراج متغيرات الصلاحية للتوافق مع القالب
$expired_products_count   = $expiry_data['expired_count'];
$expired_products_list    = $expiry_data['expired_list'];
$expiring_soon_count      = $expiry_data['expiring_count'];
$expiring_soon_list       = $expiry_data['expiring_list'];
$expired_financial_loss   = $expiry_data['financial_loss'];
$expiry_alert_enabled     = $expiry_data['alert_enabled'];
$expiry_alert_days_setting = $expiry_data['alert_days'];
$expiry_tracked_count     = $expiry_data['tracked_count'];
$expiry_batches_count     = $expiry_data['batches_count'];
$show_expiry_popup        = $expiry_data['show_popup'];
$expiry_popup_signature   = $expiry_data['popup_signature'];

// === أفضل المنتجات مبيعاً ===
$top_selling_products = IndexDataTopSelling::getTopSelling($date_from, $date_to);

// === تنسيق الفترة للعرض ===
$period_label_from = formatDate($date_from);
$period_label_to   = formatDate($date_to);

// === علم تحميل Chart.js ===
$load_chartjs = true;


// ===================================================================
// ===== دوال مساعدة لجلب البيانات (مغلقة في أصناف) =====
// ===================================================================

/**
 * ===== بيانات الصلاحية المنفصلة =====
 * يجمع كل استعلامات الصلاحية في صنف واحد
 * ❌ بدون كاش — بيانات حقيقية دائماً
 *
 * @since v4.17.0
 * @since v5.6.0 — إزالة التخزين المؤقت نهائياً
 */
class IndexDataExpiry
{
    /**
     * جلب كل بيانات الصلاحية دفعة واحدة
     * ❌ بدون كاش — بيانات حقيقية دائماً
     *
     * @return array مصفوفة تحتوي على:
     *   - alert_enabled: bool
     *   - alert_days: int
     *   - tracked_count: int
     *   - batches_count: int
     *   - expired_count: int
     *   - expired_list: array
     *   - financial_loss: float
     *   - expiring_count: int
     *   - expiring_list: array
     *   - show_popup: bool
     *   - popup_signature: string
     */
    public static function getExpiryData(): array
    {
        $result = [
            'alert_enabled'    => true,
            'alert_days'       => 30,
            'tracked_count'    => 0,
            'batches_count'   => 0,
            'expired_count'   => 0,
            'expired_list'    => [],
            'financial_loss'  => 0.0,
            'expiring_count'  => 0,
            'expiring_list'   => [],
            'show_popup'      => false,
            'popup_signature' => '',
        ];

        // إعدادات التنبيه
        try {
            $result['alert_enabled'] = (getSetting('expiry_alert_enabled', '1') === '1');
            $result['alert_days'] = intval(getSetting('expiry_alert_days', '30'));
            if ($result['alert_days'] < 1) $result['alert_days'] = 30;
        } catch (Exception $e) { ErrorHandler::logException($e, 'IndexDataExpiry::getExpiryData.settings'); }

        // إحصائيات تتبع الصلاحية (بدون نظام الدفعات)
        try {
            $result['tracked_count'] = intval(fetchOne("SELECT COUNT(*) as c FROM products WHERE has_expiry = 1")['c'] ?? 0);
            $result['batches_count'] = 0;
        } catch (Exception $e) { ErrorHandler::logException($e, 'IndexDataExpiry::getExpiryData.trackingStats'); }

        // بيانات الصلاحية المفصلة (إذا كان التنبيه مفعّلاً)
        if ($result['alert_enabled']) {
            self::fetchDetailedExpiryData($result);
        }

        // تحديد حالة نافذة التنبيه
        if ($result['alert_enabled'] && ($result['expired_count'] > 0 || $result['expiring_count'] > 0)) {
            $result['popup_signature'] = md5($result['expired_count'] . ':' . $result['expiring_count'] . ':' . round($result['financial_loss']));
            $dismissed_at = intval($_SESSION['expiry_popup_dismissed_at'] ?? 0);
            $old_signature = $_SESSION['expiry_popup_signature'] ?? '';

            if ($dismissed_at === 0 || (time() - $dismissed_at) >= 7200 || $old_signature !== $result['popup_signature']) {
                $result['show_popup'] = true;
            }
        }

        return $result;
    }

    /**
     * جلب بيانات الصلاحية المفصلة
     * ❌ بدون كاش — بيانات حقيقية دائماً
     */
    private static function fetchDetailedExpiryData(array &$result): void
    {
        $today = date('Y-m-d');
        $expiry_days = date('Y-m-d', strtotime('+' . $result['alert_days'] . ' days'));

        // Without purchase batch expiry tracking, detailed expiry data is unavailable.
        $result['expired_count']  = 0;
        $result['expired_list']   = [];
        $result['financial_loss'] = 0.0;
        $result['expiring_count'] = 0;
        $result['expiring_list']  = [];
    }
}


/**
 * ===== أفضل المنتجات مبيعاً =====
 * ❌ بدون كاش — بيانات حقيقية دائماً
 *
 * @since v4.17.0
 * @since v5.6.0 — إزالة التخزين المؤقت نهائياً
 */
class IndexDataTopSelling
{
    /**
     * جلب أفضل المنتجات مبيعاً
     * ❌ بدون كاش — بيانات حقيقية دائماً
     *
     * @param string $dateFrom تاريخ البداية
     * @param string $dateTo تاريخ النهاية
     * @return array قائمة أفضل المنتجات
     */
    public static function getTopSelling(string $dateFrom, string $dateTo): array
    {
        try {
            return fetchAll(
                "SELECT p.name, COALESCE(c.color, '#64748b') as color, SUM(s.total) as total_sales
                 FROM sales s JOIN products p ON p.id = s.product_id
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE s.sale_date BETWEEN ? AND ?
                 GROUP BY s.product_id ORDER BY total_sales DESC LIMIT 8",
                [$dateFrom, $dateTo]
            ) ?: [];
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'IndexDataTopSelling::getTopSelling');
            return [];
        }
    }
}
