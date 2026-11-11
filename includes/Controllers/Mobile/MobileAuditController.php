<?php
/**
 * ===== متحكم التدقيق والسجلات للهاتف (Mobile Audit Controller) =====
 * يوفر واجهة API لسجل التدقيق وسجل الأخطاء من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET /api/mobile/audit/logs          — سجل التدقيق (المصادقة + مدير)
 *   GET /api/mobile/audit/error-logs    — سجل الأخطاء (المصادقة + مدير)
 *   GET /api/mobile/audit/stats         — إحصائيات التدقيق (المصادقة + مدير)
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف سجل التدقيق وسجل الأخطاء
 */

require_once __DIR__ . '/../ApiController.php';

class MobileAuditController extends ApiController
{
    /** أنواع الأحداث في سجل التدقيق */
    private const ACTION_TYPES = [
        'login' => ['label' => 'تسجيل دخول', 'color' => 'green'],
        'logout' => ['label' => 'تسجيل خروج', 'color' => 'blue'],
        'login_failed' => ['label' => 'محاولة دخول فاشلة', 'color' => 'red'],
        'product_add' => ['label' => 'إضافة منتج', 'color' => 'green'],
        'product_edit' => ['label' => 'تعديل منتج', 'color' => 'blue'],
        'product_delete' => ['label' => 'حذف منتج', 'color' => 'red'],
        'sale_add' => ['label' => 'إضافة بيع', 'color' => 'green'],
        'sale_edit' => ['label' => 'تعديل بيع', 'color' => 'blue'],
        'sale_delete' => ['label' => 'حذف بيع', 'color' => 'red'],
        'purchase_add' => ['label' => 'إضافة شراء', 'color' => 'green'],
        'purchase_edit' => ['label' => 'تعديل شراء', 'color' => 'blue'],
        'purchase_delete' => ['label' => 'حذف شراء', 'color' => 'red'],
        'expense_add' => ['label' => 'إضافة مصروف', 'color' => 'green'],
        'expense_delete' => ['label' => 'حذف مصروف', 'color' => 'red'],
        'user_add' => ['label' => 'إضافة مستخدم', 'color' => 'green'],
        'user_edit' => ['label' => 'تعديل مستخدم', 'color' => 'blue'],
        'user_delete' => ['label' => 'حذف مستخدم', 'color' => 'red'],
        'backup_create' => ['label' => 'إنشاء نسخة', 'color' => 'green'],
        'backup_restore' => ['label' => 'استعادة نسخة', 'color' => 'blue'],
        'settings_update' => ['label' => 'تحديث إعدادات', 'color' => 'blue'],
        'api_token_create' => ['label' => 'إنشاء رمز API', 'color' => 'green'],
        'api_token_revoke' => ['label' => 'إلغاء رمز API', 'color' => 'red'],
        'api_token_delete' => ['label' => 'حذف رمز API', 'color' => 'red'],
        'stock_transfer' => ['label' => 'تحويل مخزون', 'color' => 'blue'],
        'stock_update' => ['label' => 'تحديث مخزون', 'color' => 'blue'],
        'inventory_count' => ['label' => 'جرد المخزون', 'color' => 'blue'],
    ];

    /**
     * سجل التدقيق
     *
     * معاملات GET:
     *   user_id    — تصفية بالمستخدم (اختياري)
     *   action     — تصفية بنوع الحدث (اختياري)
     *   date_from  — تاريخ البداية (اختياري)
     *   date_to    — تاريخ النهاية (اختياري)
     *   limit      — عدد النتائج (10-1000، افتراضي: 200)
     *   page       — رقم الصفحة
     *
     * الاستجابة:
     *   { success, data: [...], pagination: {...} }
     */
    public function logs(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $userId = intval($this->input('user_id', 0));
            $action = trim($this->input('action', ''));
            $dateFrom = trim($this->input('date_from', ''));
            $dateTo = trim($this->input('date_to', ''));
            $limit = min(1000, max(10, intval($this->input('limit', 200))));

            $pagination = $this->getPaginationParams($limit);
            $page = $pagination['page'];
            $perPage = $pagination['per_page'];
            $offset = $pagination['offset'];

            // بناء الاستعلام
            $sql = "SELECT al.*, u.username, u.full_name
                    FROM audit_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    WHERE 1=1";

            $countSql = "SELECT COUNT(*) as total FROM audit_log al WHERE 1=1";
            $bindings = [];

            if ($userId > 0) {
                $sql .= " AND al.user_id = ?";
                $countSql .= " AND al.user_id = ?";
                $bindings[] = $userId;
            }

            if (!empty($action) && isset(self::ACTION_TYPES[$action])) {
                $sql .= " AND al.action = ?";
                $countSql .= " AND al.action = ?";
                $bindings[] = $action;
            }

            if (!empty($dateFrom)) {
                $sql .= " AND DATE(al.created_at) >= ?";
                $countSql .= " AND DATE(al.created_at) >= ?";
                $bindings[] = $dateFrom;
            }

            if (!empty($dateTo)) {
                $sql .= " AND DATE(al.created_at) <= ?";
                $countSql .= " AND DATE(al.created_at) <= ?";
                $bindings[] = $dateTo;
            }

            $sql .= " ORDER BY al.created_at DESC, al.id DESC";
            $sql .= " LIMIT ? OFFSET ?";
            $bindings[] = $perPage;
            $bindings[] = $offset;

            $logs = fetchAll($sql, $bindings);
            $countRow = fetchOne($countSql, array_slice($bindings, 0, -2));
            $total = intval($countRow['total'] ?? 0);

            if (!is_array($logs)) {
                $logs = [];
            }

            // إضافة معلومات نوع الحدث
            foreach ($logs as &$log) {
                $act = $log['action'] ?? '';
                $log['action_label'] = self::ACTION_TYPES[$act]['label'] ?? $act;
                $log['action_color'] = self::ACTION_TYPES[$act]['color'] ?? 'gray';
                $log['browser'] = $this->extractBrowser($log['user_agent'] ?? '');
            }
            unset($log);

            ApiResponse::paginated($logs, $total, $page, $perPage);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileAuditController::logs');
            ApiResponse::serverError('حدث خطأ أثناء جلب سجل التدقيق');
        }
    }

    /**
     * سجل الأخطاء
     *
     * معاملات GET:
     *   file  — اسم ملف السجل (اختياري)
     *   level — مستوى السجل: error|warning|info|debug (اختياري)
     *   page  — رقم الصفحة
     *   per_page — عدد النتائج (افتراضي: 100)
     *
     * الاستجابة:
     *   { success, data: { entries, files, current_file } }
     */
    public function errorLogs(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $logDir = defined('LOG_DIR_VIEW') ? LOG_DIR_VIEW : __DIR__ . '/../../../logs';
            $requestedFile = basename(trim($this->input('file', '')));
            $level = trim($this->input('level', ''));
            $page = max(1, intval($this->input('page', 1)));
            $perPage = 100;

            // جلب ملفات السجل المتاحة
            $logFiles = [];
            if (is_dir($logDir)) {
                $allFiles = glob($logDir . '/app-*.log');
                if ($allFiles === false) $allFiles = [];
                foreach ($allFiles as $f) {
                    $logFiles[] = basename($f);
                }
                rsort($logFiles);
            }

            // تحديد الملف الحالي
            if (empty($requestedFile) || !in_array($requestedFile, $logFiles)) {
                $requestedFile = $logFiles[0] ?? '';
            }

            $entries = [];
            if (!empty($requestedFile)) {
                $filepath = $logDir . '/' . $requestedFile;
                if (file_exists($filepath)) {
                    $content = file_get_contents($filepath);
                    $lines = explode("\n", $content);

                    foreach ($lines as $line) {
                        if (empty(trim($line))) continue;

                        $entry = $this->parseLogLine($line);
                        if ($entry === null) continue;

                        // تصفية بالمستوى
                        if (!empty($level) && ($entry['level'] ?? '') !== $level) {
                            continue;
                        }

                        $entries[] = $entry;
                    }
                }
            }

            // ترتيب عكسي (الأحدث أولاً)
            $entries = array_reverse($entries);

            // ترقيم الصفحات
            $total = count($entries);
            $offset = ($page - 1) * $perPage;
            $pagedEntries = array_slice($entries, $offset, $perPage);

            ApiResponse::success([
                'entries' => $pagedEntries,
                'files' => $logFiles,
                'current_file' => $requestedFile,
                'level_filter' => $level,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'has_next' => ($offset + $perPage) < $total,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileAuditController::errorLogs');
            ApiResponse::serverError('حدث خطأ أثناء جلب سجل الأخطاء');
        }
    }

    /**
     * إحصائيات التدقيق
     *
     * معاملات GET:
     *   date_from — تاريخ البداية (اختياري)
     *   date_to   — تاريخ النهاية (اختياري)
     *
     * الاستجابة:
     *   { success, data: { total, by_action, by_user, recent } }
     */
    public function stats(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $dateFrom = trim($this->input('date_from', ''));
            $dateTo = trim($this->input('date_to', ''));

            $where = "WHERE 1=1";
            $bindings = [];

            if (!empty($dateFrom)) {
                $where .= " AND DATE(created_at) >= ?";
                $bindings[] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $where .= " AND DATE(created_at) <= ?";
                $bindings[] = $dateTo;
            }

            // إجمالي الأحداث
            $totalRow = fetchOne("SELECT COUNT(*) as c FROM audit_log {$where}", $bindings);
            $total = intval($totalRow['c'] ?? 0);

            // حسب نوع الحدث
            $byAction = fetchAll(
                "SELECT action, COUNT(*) as count FROM audit_log {$where} GROUP BY action ORDER BY count DESC",
                $bindings
            );
            if (!is_array($byAction)) $byAction = [];

            // إضافة التسميات
            foreach ($byAction as &$item) {
                $item['label'] = self::ACTION_TYPES[$item['action']]['label'] ?? $item['action'];
            }
            unset($item);

            // حسب المستخدم
            $byUser = fetchAll(
                "SELECT al.user_id, u.username, u.full_name, COUNT(*) as count
                 FROM audit_log al
                 LEFT JOIN users u ON al.user_id = u.id
                 {$where}
                 GROUP BY al.user_id
                 ORDER BY count DESC
                 LIMIT 10",
                $bindings
            );
            if (!is_array($byUser)) $byUser = [];

            // آخر الأحداث
            $recent = fetchAll(
                "SELECT al.*, u.username, u.full_name
                 FROM audit_log al
                 LEFT JOIN users u ON al.user_id = u.id
                 {$where}
                 ORDER BY al.created_at DESC
                 LIMIT 10",
                $bindings
            );
            if (!is_array($recent)) $recent = [];

            ApiResponse::success([
                'total' => $total,
                'by_action' => $byAction,
                'by_user' => $byUser,
                'recent' => $recent,
                'action_types' => self::ACTION_TYPES,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileAuditController::stats');
            ApiResponse::serverError('حدث خطأ أثناء جلب إحصائيات التدقيق');
        }
    }

    /**
     * تحليل سطر من ملف السجل
     */
    private function parseLogLine(string $line): ?array
    {
        // تنسيق: [2024-01-01 12:00:00] [error] message | context | URL:... | IP:...
        if (!preg_match('/^\[([^\]]+)\]\s*\[(\w+)\]\s*(.+)$/', $line, $matches)) {
            return null;
        }

        $entry = [
            'datetime' => $matches[1],
            'level' => strtolower($matches[2]),
            'message' => $matches[3],
            'context' => '',
            'url' => '',
            'ip' => '',
        ];

        // استخراج السياق
        $remaining = $entry['message'];
        if (preg_match('/\|\s*URL:\s*(\S+)/', $remaining, $urlMatch)) {
            $entry['url'] = $urlMatch[1];
            $remaining = str_replace($urlMatch[0], '', $remaining);
        }
        if (preg_match('/\|\s*IP:\s*(\S+)/', $remaining, $ipMatch)) {
            $entry['ip'] = $ipMatch[1];
            $remaining = str_replace($ipMatch[0], '', $remaining);
        }
        if (preg_match('/\|\s*(.+)$/', $remaining, $ctxMatch)) {
            $entry['context'] = trim($ctxMatch[1]);
            $remaining = str_replace($ctxMatch[0], '', $remaining);
        }

        $entry['message'] = trim($remaining, " |");

        return $entry;
    }

    /**
     * استخراج اسم المتصفح من User-Agent
     */
    private function extractBrowser(string $userAgent): string
    {
        if (empty($userAgent)) return 'غير معروف';

        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Edg') !== false) return 'Edge';
        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false) return 'Opera';

        // تطبيقات الهاتف
        if (strpos($userAgent, 'okhttp') !== false) return 'Android App';
        if (strpos($userAgent, 'Alamofire') !== false) return 'iOS App';
        if (strpos($userAgent, 'dart') !== false) return 'Flutter App';

        return 'غير معروف';
    }
}
