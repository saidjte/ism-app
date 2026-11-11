<?php
/**
 * ===== متحكم صلاحية المنتجات للموبايل (MobileExpiryController) =====
 * يوفر عمليات إدارة صلاحية المنتجات عبر API الموبايل
 *
 * المسارات:
 *   GET  /api/mobile/expiry/report          — تقرير المنتجات منتهية/قريبة الصلاحية
 *   GET  /api/mobile/expiry/alerts          — تنبيهات الصلاحية الحالية
 *   GET  /api/mobile/expiry/tracking        — نظام تتبع الصلاحية المتقدم
 *   GET  /api/mobile/expiry/timeline        — جدول زمني للصلاحيات
 *   POST /api/mobile/expiry/dismiss         — إخفاء نافذة تنبيه الصلاحية
 *   GET  /api/mobile/expiry/dismissed       — حالة إخفاء التنبيه
 *   POST /api/mobile/expiry/batch-update    — تحديث تواريخ صلاحية متعددة
 *
 * الصلاحيات:
 *   expiry.view — عرض تقرير الصلاحية
 *
 * v5.14.0: إنشاء متحكم صلاحية المنتجات للموبايل
 * v5.16.0: إصلاح fetchAll() + إضافة تتبع الصلاحية المتقدم + جدول زمني
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileExpiryController extends ApiController
{
    /**
     * تقرير المنتجات منتهية/قريبة الصلاحية
     *
     * GET /api/mobile/expiry/report?status=expired|expiring|all&days=30&page=1&per_page=20
     *
     * - expired: منتهية الصلاحية
     * - expiring: قريبة الصلاحية (خلال X يوم)
     * - all: الكل
     */
    public function report(array $params = []): void
    {
        try {
            if (!hasPermission('expiry.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض تقرير الصلاحية');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // معاملات التصفية
            $status = $this->input('status', 'all'); // expired, expiring, all
            $days = max(1, intval($this->input('days', 30)));
            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            // إعدادات الصلاحية
            $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30'));
            $expiryBlockSale = $db->getSetting('expiry_block_sale', '0') === '1';

            // بناء الاستعلام
            $where = ["p.has_expiry = 1", "p.expiry_date IS NOT NULL"];
            $bindings = [];

            switch ($status) {
                case 'expired':
                    $where[] = "p.expiry_date < CURDATE()";
                    break;
                case 'expiring':
                    $where[] = "p.expiry_date >= CURDATE()";
                    $where[] = "p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
                    $bindings[] = $days;
                    break;
                case 'all':
                default:
                    // بدون تصفية إضافية
                    break;
            }

            $whereClause = 'WHERE ' . implode(' AND ', $where);

            // عدد الإجمالي
            $countSql = "SELECT COUNT(*) as total FROM products p {$whereClause}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($bindings);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $sql = "SELECT p.id, p.name, p.code, p.quantity, p.unit,
                    p.expiry_date, p.purchase_price, p.selling_price,
                    p.category_id, c.name as category_name,
                    DATEDIFF(p.expiry_date, CURDATE()) as days_remaining,
                    CASE
                        WHEN p.expiry_date < CURDATE() THEN 'expired'
                        WHEN p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'expiring'
                        ELSE 'valid'
                    END as expiry_status
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    {$whereClause}
                    ORDER BY p.expiry_date ASC
                    LIMIT {$perPage} OFFSET {$offset}";

            // إضافة معامل days_remaining في بداية الـ bindings
            $bindingsWithDays = array_merge([$days], $bindings);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindingsWithDays);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تنسيق البيانات
            $products = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'code' => $row['code'] ?? '',
                    'quantity' => floatval($row['quantity']),
                    'unit' => $row['unit'] ?? '',
                    'expiry_date' => $row['expiry_date'],
                    'days_remaining' => intval($row['days_remaining']),
                    'expiry_status' => $row['expiry_status'],
                    'purchase_price' => floatval($row['purchase_price']),
                    'selling_price' => floatval($row['selling_price']),
                    'category_name' => $row['category_name'] ?? '',
                ];
            }, $products);

            // إحصائيات سريعة
            $statsSql = "SELECT
                COUNT(*) as total_tracked,
                COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END), 0) as expired_count,
                COALESCE(SUM(CASE WHEN expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) as expiring_count,
                COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN quantity * purchase_price ELSE 0 END), 0) as expired_value
                FROM products
                WHERE has_expiry = 1 AND expiry_date IS NOT NULL";
            $statsStmt = $pdo->prepare($statsSql);
            $statsStmt->execute([$expiryAlertDays]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            ApiResponse::success([
                'data' => $products,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
                'stats' => [
                    'total_tracked' => intval($stats['total_tracked'] ?? 0),
                    'expired_count' => intval($stats['expired_count'] ?? 0),
                    'expiring_count' => intval($stats['expiring_count'] ?? 0),
                    'expired_value' => floatval($stats['expired_value'] ?? 0),
                    'alert_days' => $expiryAlertDays,
                    'block_sale' => $expiryBlockSale,
                ],
                'filters' => [
                    'status' => $status,
                    'days' => $days,
                ],
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::report');
            }
            ApiResponse::serverError('خطأ في جلب تقرير الصلاحية');
        }
    }

    /**
     * تنبيهات الصلاحية الحالية
     *
     * GET /api/mobile/expiry/alerts
     * يرجع المنتجات منتهية أو قريبة الصلاحية مع توقيع فريد
     */
    public function alerts(array $params = []): void
    {
        try {
            if (!hasPermission('expiry.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض تنبيهات الصلاحية');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30'));
            $expiryAlertEnabled = $db->getSetting('expiry_alert_enabled', '1') === '1';

            if (!$expiryAlertEnabled) {
                ApiResponse::success([
                    'enabled' => false,
                    'alerts' => [],
                    'signature' => '',
                    'message' => 'تنبيهات الصلاحية معطّلة في الإعدادات',
                ]);
                return;
            }

            // المنتجات منتهية الصلاحية
            $expired = $db->fetchAll(
                "SELECT id, name, code, quantity, unit, expiry_date,
                        purchase_price, selling_price,
                        COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                        DATEDIFF(CURDATE(), expiry_date) as days_expired
                 FROM products
                 WHERE has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
                 ORDER BY expiry_date ASC
                 LIMIT 50"
            ) ?: [];

            // المنتجات قريبة الصلاحية
            $expiring = $db->fetchAll(
                "SELECT id, name, code, quantity, unit, expiry_date,
                        purchase_price, selling_price,
                        COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                        DATEDIFF(expiry_date, CURDATE()) as days_remaining
                 FROM products
                 WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                 AND expiry_date >= CURDATE()
                 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 ORDER BY expiry_date ASC
                 LIMIT 50",
                [$expiryAlertDays]
            ) ?: [];

            // إنشاء توقيع فريد للتنبيهات (يتغير عند وجود تنبيهات جديدة)
            $signature = md5(serialize($expired) . serialize($expiring) . date('Y-m-d'));

            // حساب قيمة الخسارة للمنتجات المنتهية
            $expiredLossValue = 0;
            foreach ($expired as $item) {
                $expiredLossValue += floatval($item['quantity'] ?? 0) * floatval($item['purchase_price'] ?? 0);
            }

            // حساب القيمة المُعرضة للخطر للمنتجات قريبة الصلاحية
            $atRiskValue = 0;
            foreach ($expiring as $item) {
                $atRiskValue += floatval($item['quantity'] ?? 0) * floatval($item['purchase_price'] ?? 0);
            }

            // مستوى الخطورة
            $severity = 'normal';
            if (count($expired) > 0) {
                $severity = 'critical';
            } elseif (count($expiring) > 5) {
                $severity = 'warning';
            } elseif (count($expiring) > 0) {
                $severity = 'info';
            }

            ApiResponse::success([
                'enabled' => true,
                'expired' => $expired,
                'expiring' => $expiring,
                'expired_count' => count($expired),
                'expiring_count' => count($expiring),
                'expired_loss_value' => round($expiredLossValue, 2),
                'at_risk_value' => round($atRiskValue, 2),
                'alert_days' => $expiryAlertDays,
                'severity' => $severity,
                'signature' => $signature,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::alerts');
            }
            ApiResponse::serverError('خطأ في جلب تنبيهات الصلاحية');
        }
    }

    /**
     * نظام تتبع الصلاحية المتقدم
     *
     * GET /api/mobile/expiry/tracking?days_ahead=90&group_by=category
     *
     * يوفر تتبعاً شاملاً للصلاحيات:
     *   - نظرة عامة (إحصائيات مجمّعة)
     *   - تصنيف حسب مستوى الإلحاح
     *   - تصنيف حسب التصنيف (اختياري)
     *   - قائمة الدفعات قريبة الصلاحية
     *   - توصيات الإجراء
     *
     * معاملات الاستعلام:
     *   days_ahead — نطاق الأيام القادمة (7, 15, 30, 60, 90, 180, 365) — افتراضي: 90
     *   group_by   — تجميع حسب: category, urgency, none — افتراضي: urgency
     */
    public function tracking(array $params = []): void
    {
        try {
            if (!hasPermission('expiry.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض تتبع الصلاحية');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $daysAhead = intval($this->input('days_ahead', 90));
            $daysAhead = in_array($daysAhead, [7, 15, 30, 60, 90, 180, 365]) ? $daysAhead : 90;
            $groupBy = $this->input('group_by', 'urgency');
            $groupBy = in_array($groupBy, ['category', 'urgency', 'none']) ? $groupBy : 'urgency';

            // إعدادات الصلاحية
            $expiryAlertDays = intval($db->getSetting('expiry_alert_days', '30'));
            $expiryBlockSale = $db->getSetting('expiry_block_sale', '0') === '1';
            $expiryAlertEnabled = $db->getSetting('expiry_alert_enabled', '1') === '1';

            if (!$expiryAlertEnabled) {
                ApiResponse::success([
                    'enabled' => false,
                    'message' => 'تنبيهات الصلاحية معطّلة في الإعدادات',
                ]);
                return;
            }

            // === نظرة عامة: إحصائيات مجمّعة ===
            $overview = $db->fetchOne(
                "SELECT
                    COUNT(*) as total_tracked,
                    COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END), 0) as expired_count,
                    COALESCE(SUM(CASE WHEN expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) as urgent_count,
                    COALESCE(SUM(CASE WHEN expiry_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) as warning_count,
                    COALESCE(SUM(CASE WHEN expiry_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) as notice_count,
                    COALESCE(SUM(CASE WHEN expiry_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) as safe_count,
                    COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN quantity * purchase_price ELSE 0 END), 0) as total_loss_value,
                    COALESCE(SUM(CASE WHEN expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN quantity * purchase_price ELSE 0 END), 0) as at_risk_value
                 FROM products
                 WHERE has_expiry = 1 AND expiry_date IS NOT NULL",
                [7, $expiryAlertDays, $daysAhead, $daysAhead, $daysAhead]
            );

            // === المنتجات حسب مستوى الإلحاح ===
            $urgencyGroups = [
                'expired' => [],
                'urgent' => [],
                'warning' => [],
                'notice' => [],
            ];

            if ($groupBy === 'urgency' || $groupBy === 'none') {
                // منتهية الصلاحية
                $urgencyGroups['expired'] = $db->fetchAll(
                    "SELECT id, name, code, quantity, unit, expiry_date,
                            purchase_price, selling_price,
                            COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                            DATEDIFF(CURDATE(), expiry_date) as days_expired
                     FROM products
                     WHERE has_expiry = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
                     ORDER BY expiry_date ASC
                     LIMIT 20"
                ) ?: [];

                // عاجلة (خلال 7 أيام)
                $urgencyGroups['urgent'] = $db->fetchAll(
                    "SELECT id, name, code, quantity, unit, expiry_date,
                            purchase_price, selling_price,
                            COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                            DATEDIFF(expiry_date, CURDATE()) as days_remaining
                     FROM products
                     WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                     AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     ORDER BY expiry_date ASC
                     LIMIT 20"
                ) ?: [];

                // تحذير (7 - alert_days)
                $urgencyGroups['warning'] = $db->fetchAll(
                    "SELECT id, name, code, quantity, unit, expiry_date,
                            purchase_price, selling_price,
                            COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                            DATEDIFF(expiry_date, CURDATE()) as days_remaining
                     FROM products
                     WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                     AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                     ORDER BY expiry_date ASC
                     LIMIT 20",
                    [$expiryAlertDays]
                ) ?: [];

                // إشعار (alert_days - days_ahead)
                $urgencyGroups['notice'] = $db->fetchAll(
                    "SELECT id, name, code, quantity, unit, expiry_date,
                            purchase_price, selling_price,
                            COALESCE((SELECT name FROM categories WHERE id = products.category_id), '') as category_name,
                            DATEDIFF(expiry_date, CURDATE()) as days_remaining
                     FROM products
                     WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                     AND expiry_date > DATE_ADD(CURDATE(), INTERVAL ? DAY)
                     AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                     ORDER BY expiry_date ASC
                     LIMIT 20",
                    [$expiryAlertDays, $daysAhead]
                ) ?: [];
            }

            // === تجميع حسب التصنيف ===
            $categoryGroups = [];
            if ($groupBy === 'category') {
                $categoryGroups = $db->fetchAll(
                    "SELECT 
                        COALESCE((SELECT name FROM categories WHERE id = products.category_id), 'غير مصنف') as category_name,
                        COUNT(*) as total_count,
                        COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END), 0) as expired_count,
                        COALESCE(SUM(CASE WHEN expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) as expiring_count,
                        COALESCE(SUM(CASE WHEN expiry_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) as safe_count,
                        MIN(expiry_date) as earliest_expiry,
                        COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN quantity * purchase_price ELSE 0 END), 0) as loss_value
                     FROM products
                     WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                     GROUP BY category_id
                     HAVING expired_count > 0 OR expiring_count > 0
                     ORDER BY expired_count DESC, expiring_count DESC",
                    [$expiryAlertDays, $expiryAlertDays]
                ) ?: [];
            }

            // === تنبيهات الدفعات ===
            $batchAlerts = [];
            try {
                $check = $pdo->query("SHOW TABLES LIKE 'purchase_batches'");
                if (!empty($check->fetch())) {
                    $batchAlerts = $db->fetchAll(
                        "SELECT pb.id, pb.product_id, pb.batch_number,
                                pb.remaining_quantity, pb.unit_price,
                                pb.expiry_date, pb.purchase_date,
                                p.name as product_name, p.code as product_code, p.unit,
                                DATEDIFF(pb.expiry_date, CURDATE()) as days_to_expiry,
                                CASE
                                    WHEN pb.expiry_date < CURDATE() THEN 'expired'
                                    WHEN pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'urgent'
                                    WHEN pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'warning'
                                    ELSE 'notice'
                                END as urgency
                         FROM purchase_batches pb
                         JOIN products p ON pb.product_id = p.id
                         WHERE pb.remaining_quantity > 0
                         AND pb.expiry_date IS NOT NULL
                         AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                         ORDER BY pb.expiry_date ASC
                         LIMIT 20",
                        [$expiryAlertDays, $daysAhead]
                    ) ?: [];
                }
            } catch (\Throwable $e) {}

            // === توصيات الإجراء ===
            $recommendations = [];
            if (intval($overview['expired_count'] ?? 0) > 0) {
                $recommendations[] = [
                    'type' => 'critical',
                    'action' => 'remove_expired',
                    'message' => 'إزالة المنتجات المنتهية الصلاحية من الرفوف فوراً',
                    'count' => intval($overview['expired_count']),
                ];
            }
            if (intval($overview['urgent_count'] ?? 0) > 0) {
                $recommendations[] = [
                    'type' => 'urgent',
                    'action' => 'discount_urgent',
                    'message' => 'تخفيض المنتجات التي تنتهي صلاحيتها خلال أسبوع',
                    'count' => intval($overview['urgent_count']),
                ];
            }
            if (intval($overview['warning_count'] ?? 0) > 0) {
                $recommendations[] = [
                    'type' => 'warning',
                    'action' => 'promote_warning',
                    'message' => 'ترويج المنتجات التي تنتهي صلاحيتها قريباً',
                    'count' => intval($overview['warning_count']),
                ];
            }
            if ($expiryBlockSale) {
                $recommendations[] = [
                    'type' => 'info',
                    'action' => 'block_sale_active',
                    'message' => 'حظر بيع المنتجات المنتهية الصلاحية مفعّل',
                    'count' => 0,
                ];
            }

            ApiResponse::success([
                'enabled' => true,
                'overview' => [
                    'total_tracked' => intval($overview['total_tracked'] ?? 0),
                    'expired_count' => intval($overview['expired_count'] ?? 0),
                    'urgent_count' => intval($overview['urgent_count'] ?? 0),
                    'warning_count' => intval($overview['warning_count'] ?? 0),
                    'notice_count' => intval($overview['notice_count'] ?? 0),
                    'safe_count' => intval($overview['safe_count'] ?? 0),
                    'total_loss_value' => round(floatval($overview['total_loss_value'] ?? 0), 2),
                    'at_risk_value' => round(floatval($overview['at_risk_value'] ?? 0), 2),
                    'alert_days' => $expiryAlertDays,
                    'days_ahead' => $daysAhead,
                    'block_sale' => $expiryBlockSale,
                ],
                'urgency_groups' => $urgencyGroups,
                'category_groups' => $categoryGroups,
                'batches' => $batchAlerts,
                'recommendations' => $recommendations,
                'group_by' => $groupBy,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::tracking');
            }
            ApiResponse::serverError('خطأ في جلب تتبع الصلاحية');
        }
    }

    /**
     * جدول زمني للصلاحيات
     *
     * GET /api/mobile/expiry/timeline?months=6
     *
     * يوفر عرضاً زمنياً للصلاحيات:
     *   - عدد المنتجات التي تنتهي صلاحيتها كل شهر
     *   - قيمة المنتجات حسب الشهر
     *   - توزيع مستويات الإلحاح بالشهر
     *
     * معاملات الاستعلام:
     *   months — عدد الأشهر القادمة (3-24, افتراضي: 6)
     */
    public function timeline(array $params = []): void
    {
        try {
            if (!hasPermission('expiry.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض الجدول الزمني');
                return;
            }

            $db = AppDatabase::getInstance();
            $months = min(24, max(3, intval($this->input('months', 6))));

            // إحصائيات شهرية للمنتجات ذات صلاحية
            $monthlyData = $db->fetchAll(
                "SELECT 
                    DATE_FORMAT(expiry_date, '%Y-%m') as month,
                    COUNT(*) as total_count,
                    COALESCE(SUM(quantity * purchase_price), 0) as total_value,
                    COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END), 0) as already_expired,
                    COALESCE(SUM(CASE WHEN expiry_date >= CURDATE() THEN 1 ELSE 0 END), 0) as upcoming,
                    MIN(expiry_date) as first_expiry,
                    MAX(expiry_date) as last_expiry
                 FROM products
                 WHERE has_expiry = 1 AND expiry_date IS NOT NULL
                 AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? MONTH)
                 GROUP BY DATE_FORMAT(expiry_date, '%Y-%m')
                 ORDER BY month ASC",
                [$months]
            ) ?: [];

            // تنسيق البيانات مع أسماء الأشهر
            $monthNames = [
                1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
            ];

            $formatted = [];
            foreach ($monthlyData as $row) {
                $ym = $row['month'];
                $timestamp = strtotime($ym . '-01');
                $monthNum = intval(date('m', $timestamp));
                $isPast = $ym < date('Y-m');

                $formatted[] = [
                    'month' => $ym,
                    'month_label' => ($monthNames[$monthNum] ?? date('M', $timestamp)) . ' ' . date('Y', $timestamp),
                    'total_count' => intval($row['total_count']),
                    'total_value' => round(floatval($row['total_value']), 2),
                    'already_expired' => intval($row['already_expired']),
                    'upcoming' => intval($row['upcoming']),
                    'first_expiry' => $row['first_expiry'],
                    'last_expiry' => $row['last_expiry'],
                    'status' => $isPast ? 'past' : ($ym === date('Y-m') ? 'current' : 'future'),
                ];
            }

            // إجماليات عامة
            $totalExpired = 0;
            $totalUpcoming = 0;
            $totalValue = 0;
            foreach ($formatted as $item) {
                $totalExpired += $item['already_expired'];
                $totalUpcoming += $item['upcoming'];
                $totalValue += $item['total_value'];
            }

            ApiResponse::success([
                'timeline' => $formatted,
                'months' => $months,
                'totals' => [
                    'expired' => $totalExpired,
                    'upcoming' => $totalUpcoming,
                    'total_value' => round($totalValue, 2),
                ],
                'currency' => class_exists('AppDatabase') ? AppDatabase::getInstance()->getCurrencySymbol() : 'د.م.',
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::timeline');
            }
            ApiResponse::serverError('خطأ في جلب الجدول الزمني للصلاحيات');
        }
    }

    /**
     * إخفاء نافذة تنبيه الصلاحية
     *
     * POST /api/mobile/expiry/dismiss
     * Body: { "sig": "abc123..." }
     *
     * يسجل وقت الإخفاء في الجلسة - التنبيه لن يظهر لمدة ساعتين
     * ما لم يتغير التوقيع (تنبيه جديد)
     */
    public function dismiss(array $params = []): void
    {
        $this->requirePost();

        try {
            if (!hasPermission('expiry.view')) {
                ApiResponse::forbidden('ليس لديك صلاحية عرض تنبيهات الصلاحية');
                return;
            }

            $sig = trim($this->input('sig', ''));
            if (empty($sig)) {
                ApiResponse::validationError(['sig' => 'معامل التوقيع مطلوب']);
                return;
            }

            // تخزين وقت الإخفاء
            $_SESSION['expiry_popup_dismissed_at'] = time();

            // تخزين توقيع التنبيه الحالي للكشف عن التنبيهات الجديدة
            $_SESSION['expiry_popup_signature'] = $sig;

            ApiResponse::success([
                'dismissed_until' => date('Y-m-d H:i:s', time() + 7200),
                'signature' => $sig,
            ], 'تم إخفاء التنبيه. سيظهر مجدداً بعد ساعتين أو عند وجود تنبيه جديد.');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::dismiss');
            }
            ApiResponse::serverError('خطأ في إخفاء التنبيه');
        }
    }

    /**
     * حالة إخفاء التنبيه
     *
     * GET /api/mobile/expiry/dismissed
     * يرجع ما إذا كان التنبيه مخفياً حالياً
     */
    public function dismissed(array $params = []): void
    {
        try {
            $dismissedAt = $_SESSION['expiry_popup_dismissed_at'] ?? 0;
            $storedSig = $_SESSION['expiry_popup_signature'] ?? '';
            $currentSig = trim($this->input('sig', ''));

            // التحقق: هل التنبيه مخفى؟
            $isDismissed = false;
            $reason = 'not_dismissed';

            if ($dismissedAt > 0) {
                $twoHours = 7200; // ثانيتين
                $elapsed = time() - $dismissedAt;

                if ($elapsed >= $twoHours) {
                    // مرت أكثر من ساعتين
                    $reason = 'timeout';
                } elseif (!empty($currentSig) && $currentSig !== $storedSig) {
                    // التوقيع تغير (تنبيه جديد)
                    $reason = 'new_alert';
                } else {
                    $isDismissed = true;
                    $reason = 'dismissed';
                }
            }

            ApiResponse::success([
                'is_dismissed' => $isDismissed,
                'reason' => $reason,
                'dismissed_at' => $dismissedAt > 0 ? date('Y-m-d H:i:s', $dismissedAt) : null,
                'expires_at' => $dismissedAt > 0 ? date('Y-m-d H:i:s', $dismissedAt + 7200) : null,
                'stored_signature' => $storedSig,
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::dismissed');
            }
            ApiResponse::serverError('خطأ في جلب حالة التنبيه');
        }
    }

    /**
     * تحديث تواريخ صلاحية متعددة
     *
     * POST /api/mobile/expiry/batch-update
     * Body: { "updates": [ { "product_id": 1, "expiry_date": "2026-12-31" }, ... ] }
     */
    public function batchUpdate(array $params = []): void
    {
        $this->requirePost();

        try {
            if (!hasPermission('products.edit')) {
                ApiResponse::forbidden('ليس لديك صلاحية تعديل المنتجات');
                return;
            }

            $updates = $this->input('updates', []);
            if (!is_array($updates) || empty($updates)) {
                ApiResponse::validationError(['updates' => 'قائمة التحديثات مطلوبة']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $successCount = 0;
            $errors = [];

            foreach ($updates as $i => $update) {
                $productId = intval($update['product_id'] ?? 0);
                $expiryDate = trim($update['expiry_date'] ?? '');

                if ($productId <= 0) {
                    $errors[] = "العنصر #{$i}: معرف المنتج غير صالح";
                    continue;
                }

                if (empty($expiryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
                    $errors[] = "العنصر #{$i}: تاريخ الصلاحية غير صالح";
                    continue;
                }

                // التحقق من وجود المنتج
                $product = $db->fetchOne("SELECT id, name FROM products WHERE id = ?", [$productId]);
                if (!$product) {
                    $errors[] = "العنصر #{$i}: المنتج غير موجود";
                    continue;
                }

                $stmt = $pdo->prepare("UPDATE products SET expiry_date = ?, has_expiry = 1 WHERE id = ?");
                $stmt->execute([$expiryDate, $productId]);
                $successCount++;
            }

            // تسجيل في سجل المراجعة
            if ($successCount > 0 && function_exists('addAuditLog')) {
                addAuditLog('expiry_batch_update', "تحديث صلاحية {$successCount} منتج(ات)");
            }

            ApiResponse::success([
                'updated' => $successCount,
                'errors' => $errors,
                'total' => count($updates),
            ], $successCount > 0 ? 'تم تحديث تواريخ الصلاحية بنجاح' : 'لم يتم تحديث أي منتج');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileExpiryController::batchUpdate');
            }
            ApiResponse::serverError('خطأ في تحديث تواريخ الصلاحية');
        }
    }
}
