<?php
/**
 * ===== نظام الإشعارات الداخلي =====
 * إشعارات شاملة تغطي جميع أحداث التطبيق
 */

// أنواع الإشعارات
if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/ErrorHandler.php'; }
define('NOTIF_SALE', 'sale');
define('NOTIF_PURCHASE', 'purchase');
define('NOTIF_PRODUCT', 'product');
define('NOTIF_STOCK', 'stock');
define('NOTIF_EXPIRY', 'expiry');
define('NOTIF_EXPENSE', 'expense');
define('NOTIF_USER', 'user');
define('NOTIF_SYSTEM', 'system');
define('NOTIF_BACKUP', 'backup');

// مستويات الأولوية
define('NOTIF_URGENT', 'urgent');     // عاجل — إشعار حرج (انتهاء صلاحية، خطأ نظام)
define('NOTIF_NORMAL', 'normal');     // عادي — إشعار قياسي (بيع، شراء)
define('NOTIF_INFO', 'info');         // إعلامي — إشعار خفيف (تعديل، معلومة)

// خريطة توجيه الإشعارات حسب الأدوار (type → roles)
// null = جميع الأدوار، ['admin','manager'] = أدوار محددة فقط
$NOTIF_TYPE_ROLES = [
    NOTIF_SALE     => null,                           // البيع: للجميع
    NOTIF_PURCHASE => ['admin', 'manager'],           // الشراء: مدير ومسؤول فقط
    NOTIF_PRODUCT  => ['admin', 'manager'],           // المنتجات: مدير ومسؤول فقط
    NOTIF_STOCK    => ['admin', 'manager'],           // المخزون: مدير ومسؤول فقط
    NOTIF_EXPIRY   => ['admin', 'manager'],           // الصلاحية: مدير ومسؤول فقط
    NOTIF_EXPENSE  => ['admin', 'manager'],           // المصروفات: مدير ومسؤول فقط
    NOTIF_USER     => ['admin'],                      // المستخدمين: مسؤول فقط
    NOTIF_SYSTEM   => ['admin'],                      // النظام: مسؤول فقط
    NOTIF_BACKUP   => ['admin'],                      // النسخ الاحتياطي: مسؤول فقط
];

// خريطة الأولوية الافتراضية حسب النوع
define('NOTIF_DEFAULT_PRIORITY_MAP', null); // يُستخدم في add() كـ fallback

class AppNotification {

    private static $icon_map = [
        'sale' => '🛒',
        'purchase' => '📦',
        'product' => '🏷️',
        'stock' => '⚠️',
        'expiry' => '📅',
        'expense' => '💸',
        'user' => '👤',
        'system' => '⚙️',
        'backup' => '💾',
    ];

    private static $color_map = [
        'sale' => '#16a34a',
        'purchase' => '#2563eb',
        'product' => '#7c3aed',
        'stock' => '#dc2626',
        'expiry' => '#d97706',
        'expense' => '#ea580c',
        'user' => '#0891b2',
        'system' => '#475569',
        'backup' => '#059669',
    ];

    private static $type_labels = [
        'sale' => '',  // populated via getTypeLabels()
        'purchase' => '',
        'product' => '',
        'stock' => '',
        'expiry' => '',
        'expense' => '',
        'user' => '',
        'system' => '',
        'backup' => '',
    ];

    /**
     * Get translated type labels
     */
    private static function getTranslatedTypeLabels(): array
    {
        return [
            'sale' => t('notif_type_sale'),
            'purchase' => t('notif_type_purchase'),
            'product' => t('notif_type_product'),
            'stock' => t('notif_type_stock'),
            'expiry' => t('notif_type_expiry'),
            'expense' => t('notif_type_expense'),
            'user' => t('notif_type_user'),
            'system' => t('notif_type_system'),
            'backup' => t('notif_type_backup'),
        ];
    }

    /**
     * هل فهرس FULLTEXT متاح على جدول notifications؟
     * null = لم يُفحص بعد، true = متاح، false = غير متاح
     */
    private static ?bool $hasFulltext = null;

    /**
     * خريطة الأولوية الافتراضية حسب النوع
     */
    private static $priority_map = [
        'sale'     => NOTIF_NORMAL,
        'purchase' => NOTIF_NORMAL,
        'product'  => NOTIF_INFO,
        'stock'    => NOTIF_URGENT,
        'expiry'   => NOTIF_URGENT,
        'expense'  => NOTIF_NORMAL,
        'user'     => NOTIF_INFO,
        'system'   => NOTIF_NORMAL,
        'backup'   => NOTIF_INFO,
    ];

    /**
     * إضافة إشعار جديد (يدعم i18n اختيارياً)
     *
     * @param string $type نوع الإشعار (NOTIF_SALE, NOTIF_PURCHASE, ...)
     * @param string $title العنوان (النص الحالي — يُخزن دائماً للتوافق)
     * @param string $message الرسالة (النص الحالي — يُخزن دائماً للتوافق)
     * @param array|null $data بيانات إضافية (JSON)
     * @param int|null $userId معرف المستخدم (null = الكل حسب الأدوار)
     * @param string|null $titleKey مفتاح ترجمة العنوان
     * @param string|null $messageKey مفتاح ترجمة الرسالة
     * @param array|null $messageParams معاملات الترجمة (استبدال {param} بالقيمة)
     * @param string|null $priority مستوى الأولوية (NOTIF_URGENT, NOTIF_NORMAL, NOTIF_INFO) — null = تلقائي حسب النوع
     * @param string|null $groupKey مفتاح التجميع — الإشعارات بنفس المفتاح تُدمج (مثلاً: 'sale_new')
     */
    public static function add($type, $title, $message, $data = null, $userId = null,
                                $titleKey = null, $messageKey = null, $messageParams = null,
                                $priority = null, $groupKey = null) {
        // Check if notifications are enabled
        try {
            $enabled = getSetting('notifications_enabled', '1');
            if ($enabled !== '1') return false;
        } catch (Exception $e) { ErrorHandler::logException($e, 'notifications::add_enabledCheck'); }
        try {
            $type_enabled = getSetting('notif_type_' . $type, '1');
            if ($type_enabled !== '1') return false;
        } catch (Exception $e) { ErrorHandler::logException($e, 'notifications::add_typeCheck'); }

        // تقييد المعدل (Rate Limiting) — منع إغراق قاعدة البيانات
        if (!self::checkRateLimit($type)) return false;

        // تحديد الأولوية: صريحة > تلقائية حسب النوع > عادي
        if ($priority === null) {
            $priority = self::$priority_map[$type] ?? NOTIF_NORMAL;
        }
        if (!in_array($priority, [NOTIF_URGENT, NOTIF_NORMAL, NOTIF_INFO])) {
            $priority = NOTIF_NORMAL;
        }

        $dataJson = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        $paramsJson = $messageParams ? json_encode($messageParams, JSON_UNESCAPED_UNICODE) : null;

        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // If userId is null, notify users based on role routing
            if ($userId === null) {
                global $NOTIF_TYPE_ROLES;
                $allowedRoles = $NOTIF_TYPE_ROLES[$type] ?? null;

                if ($allowedRoles !== null && is_array($allowedRoles)) {
                    // توجيه حسب الأدوار — جلب المستخدمين الذين دورهم في القائمة فقط
                    $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
                    $users = $db->query(
                        "SELECT id FROM users WHERE is_active = 1 AND role IN ($placeholders)",
                        $allowedRoles
                    )->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    // لا توجد خريطة أدوار — إرسال للجميع (توافق مع الإصدارات السابقة)
                    $users = $db->query("SELECT id FROM users WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                }

                if (empty($users)) return false;

                // معاملة (Transaction) لضمان تناسق البيانات
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO notifications (user_id, type, title, message, data, title_key, message_key, message_params, priority, group_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                    );
                    foreach ($users as $uid) {
                        $stmt->execute([$uid, $type, $title, $message, $dataJson, $titleKey, $messageKey, $paramsJson, $priority, $groupKey]);
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                // إرسال Push Notifications للمستخدمين المستهدفين
                self::sendPushToUsers($users, $type, $title, $message, $data, $priority);

                return true;
            }

            // Notify specific user — تجميع إذا وُجد group_key
            if ($groupKey !== null) {
                $existing = $db->query(
                    "SELECT id, group_count FROM notifications WHERE user_id = ? AND group_key = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 1",
                    [$userId, $groupKey]
                )->fetch();

                if ($existing) {
                    // تحديث الإشعار الموجود: زيادة العداد + تحديث الرسالة
                    $newCount = intval($existing['group_count']) + 1;
                    $db->query(
                        "UPDATE notifications SET group_count = ?, message = ?, created_at = NOW() WHERE id = ?",
                        [$newCount, $message, $existing['id']]
                    );
                    return true;
                }
            }

            // إشعار جديد لمستخدم محدد
            $stmt = $pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, message, data, title_key, message_key, message_params, priority, group_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $result = $stmt->execute([$userId, $type, $title, $message, $dataJson, $titleKey, $messageKey, $paramsJson, $priority, $groupKey]);

            // إرسال Push Notification للمستخدم المحدد
            if ($result) {
                self::sendPushToUsers([$userId], $type, $title, $message, $data, $priority);
            }

            return $result;
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'notifications::add');
            return false;
        }
    }

    /**
     * جلب إشعارات المستخدم الحالي
     */
    public static function getForUser($userId, $limit = 50, $offset = 0, $type = null, $unreadOnly = false, $readOnly = false, $search = null) {
        $db = AppDatabase::getInstance();

        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$userId];

        if ($type) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }

        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }

        if ($readOnly) {
            $sql .= " AND is_read = 1";
        }

        // بحث نصي في العنوان والرسالة — FULLTEXT أولاً مع تراجع لـ LIKE
        if (!empty($search)) {
            if (self::$hasFulltext === null) {
                // كشف فهرس FULLTEXT مرة واحدة فقط
                try {
                    $pdo = $db->getConnection();
                    $idx = $pdo->query("SHOW INDEX FROM notifications WHERE Index_type = 'FULLTEXT'")->fetchAll(PDO::FETCH_COLUMN);
                    self::$hasFulltext = !empty($idx);
                } catch (\Throwable $e) {
                    self::$hasFulltext = false;
                }
            }

            if (self::$hasFulltext) {
                $sql .= " AND MATCH(title, message) AGAINST(? IN BOOLEAN MODE)";
                // FULLTEXT BOOLEAN: +word1 +word2 = كل الكلمات، word1 word2 = أي كلمة
                $fulltextQuery = '+' . implode(' +', array_filter(explode(' ', $search)));
                $params[] = $fulltextQuery;
            } else {
                // تراجع لـ LIKE إذا لم يوجد فهرس FULLTEXT
                $sql .= " AND (title LIKE ? OR message LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
        }

        $sql .= " ORDER BY created_at DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset);

        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with icon, color, label, time-ago, and i18n
        foreach ($notifications as &$n) {
            $n['icon'] = self::$icon_map[$n['type']] ?? '🔔';
            $n['color'] = self::$color_map[$n['type']] ?? '#64748b';
            $n['type_label'] = self::getTranslatedTypeLabels()[$n['type']] ?? $n['type'];
            $n['time_ago'] = self::timeAgo($n['created_at']);
            // الأولوية — توافق مع الإشعارات القديمة التي لا تحتوي العمود
            $n['priority'] = $n['priority'] ?? NOTIF_NORMAL;
            // التجميع — عرض عدد المجمّع إذا > 1
            $n['group_count'] = intval($n['group_count'] ?? 1);
            if ($n['data']) {
                $n['data'] = json_decode($n['data'], true);
            }
            // i18n: ترجمة العنوان والرسالة من المفاتيح إن وُجدت
            $n = self::resolveI18n($n);
        }

        return $notifications;
    }

    /**
     * جلب عدد الإشعارات غير المقروءة
     */
    public static function getUnreadCount($userId) {
        $db = AppDatabase::getInstance();
        $stmt = $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
        $count = intval($stmt->fetchColumn());

        return $count;
    }

    /**
     * جلب أحدث الإشعارات غير المقروءة (للـ polling)
     */
    public static function pollNew($userId, $lastId = 0) {
        $db = AppDatabase::getInstance();

        $stmt = $db->query(
            "SELECT * FROM notifications WHERE user_id = ? AND id > ? ORDER BY id DESC LIMIT 50",
            [$userId, $lastId]
        );
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifications as &$n) {
            $n['icon'] = self::$icon_map[$n['type']] ?? '🔔';
            $n['color'] = self::$color_map[$n['type']] ?? '#64748b';
            $n['type_label'] = self::getTranslatedTypeLabels()[$n['type']] ?? $n['type'];
            $n['time_ago'] = self::timeAgo($n['created_at']);
            // الأولوية — توافق مع الإشعارات القديمة
            $n['priority'] = $n['priority'] ?? NOTIF_NORMAL;
            // التجميع
            $n['group_count'] = intval($n['group_count'] ?? 1);
            if ($n['data']) {
                $n['data'] = json_decode($n['data'], true);
            }
            // i18n: ترجمة العنوان والرسالة
            $n = self::resolveI18n($n);
        }

        $count = self::getUnreadCount($userId);

        return [
            'notifications' => $notifications,
            'unread_count' => $count,
        ];
    }

    /**
     * تحديد إشعار كمقروء
     */
    public static function markRead($notificationId, $userId) {
        $db = AppDatabase::getInstance();
        $stmt = $db->query("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$notificationId, $userId]);

        return true;
    }

    /**
     * تحديد جميع إشعارات المستخدم كمقروءة
     */
    public static function markAllRead($userId) {
        $db = AppDatabase::getInstance();
        $db->query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$userId]);

        // Return approximate count
        return 0;
    }

    /**
     * حذف إشعار
     */
    public static function delete($notificationId, $userId) {
        $db = AppDatabase::getInstance();
        $db->query("DELETE FROM notifications WHERE id = ? AND user_id = ?", [$notificationId, $userId]);

        return true;
    }

    /**
     * تنظيف الإشعارات القديمة (أقدم من 30 يوم) — أرشفة بدل حذف
     * الإشعارات المقروءة تُنقل إلى جدول archive_notifications قبل الحذف
     * الإشعارات غير المقروءة المنتهية صلاحيتها (TTL) تُقرأ تلقائياً ثم تُؤرشف
     */
    public static function cleanup($days = 30) {
        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();
        $archived = 0;
        $deleted = 0;

        try {
            // التحقق من وجود جدول الأرشيف
            $hasArchive = false;
            try {
                $pdo->query("SELECT 1 FROM archive_notifications LIMIT 1");
                $hasArchive = true;
            } catch (\Throwable $e) {}

            if ($hasArchive) {
                // أرشفة الإشعارات المقروءة القديمة قبل الحذف
                $pdo->exec(
                    "INSERT INTO archive_notifications (user_id, type, title, message, data, title_key, message_key, message_params, priority, group_key, group_count, original_created_at, archived_at)
                     SELECT user_id, type, title, message, data, title_key, message_key, message_params, priority, group_key, group_count, created_at, NOW()
                     FROM notifications
                     WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
                );
                $archived = intval($pdo->query("SELECT ROW_COUNT()")->fetchColumn());
            }

            // حذف الإشعارات المقروءة القديمة
            $stmt = $db->query("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_read = 1", [$days]);
            $deleted = $stmt->rowCount();

            // TTL: تعيين الإشعارات غير المقروءة المنتهية كمقروءة
            $ttlDays = intval(getSetting('notification_ttl_days', '90'));
            if ($ttlDays > 0) {
                $pdo->exec(
                    "UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND created_at < DATE_SUB(NOW(), INTERVAL {$ttlDays} DAY)"
                );
            }

        } catch (\Throwable $e) {
            // fallback: حذف بدون أرشفة إذا فشلت الأرشفة
            $stmt = $db->query("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_read = 1", [$days]);
            $deleted = $stmt->rowCount();
        }

        return ['archived' => $archived, 'deleted' => $deleted];
    }

    // ========================================================================
    //  تقييد المعدل (Rate Limiting)
    // ========================================================================

    /** @var array تخزين مؤقت للحدود — [type => [timestamp => count]] */
    private static array $rateLimitCache = [];

    /** @var int الحد الأقصى العام (إشعار/دقيقة) */
    private static int $rateLimitGlobal = 60;

    /** @var int الحد الأقصى حسب النوع (إشعار/دقيقة) */
    private static int $rateLimitPerType = 10;

    /** @var int نافذة الزمن بالثواني */
    private static int $rateLimitWindow = 60;

    /**
     * فحص تقييد المعدل — يمنع إغراق قاعدة البيانات بالإشعارات
     *
     * @param string $type نوع الإشعار
     * @return bool true = مسموح، false = تجاوز الحد
     */
    private static function checkRateLimit(string $type): bool {
        $now = time();
        $windowStart = $now - self::$rateLimitWindow;

        // تنظيف السجلات القديمة
        foreach (self::$rateLimitCache as $key => &$entries) {
            $entries = array_filter($entries, fn($ts) => $ts > $windowStart, ARRAY_FILTER_USE_KEY);
            if (empty($entries)) unset(self::$rateLimitCache[$key]);
        }
        unset($entries);

        // فحص الحد العام
        $globalCount = 0;
        foreach (self::$rateLimitCache as $entries) {
            $globalCount += count($entries);
        }
        if ($globalCount >= self::$rateLimitGlobal) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logException(
                    new \RuntimeException("Rate limit: {$globalCount}" . "/" . self::$rateLimitGlobal . " notifications in " . self::$rateLimitWindow . "s"),
                    'notifications::rateLimit::global'
                );
            }
            return false;
        }

        // فحص الحد حسب النوع
        $typeCount = count(self::$rateLimitCache[$type] ?? []);
        if ($typeCount >= self::$rateLimitPerType) {
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logException(
                    new \RuntimeException("Rate limit: {$type} {$typeCount}" . "/" . self::$rateLimitPerType . " in " . self::$rateLimitWindow . "s"),
                    'notifications::rateLimit::type'
                );
            }
            return false;
        }

        // تسجيل هذا الإشعار
        self::$rateLimitCache[$type][$now] = true;
        self::$rateLimitCache['__global__'][$now] = true;

        return true;
    }

    /**
     * الحصول على أكبر ID إشعار للمستخدم
     */
    public static function getLastId($userId) {
        $db = AppDatabase::getInstance();
        $stmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?", [$userId]);
        return intval($stmt->fetchColumn());
    }

    /**
     * حساب الوقت المنقضي (time ago) بالعربية
     */
    private static function timeAgo($datetime) {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        if ($diff->y > 0) return str_replace('{n}', $diff->y, t('notif_time_ago_year'));
        if ($diff->m > 0) return str_replace('{n}', $diff->m, t('notif_time_ago_month'));
        if ($diff->d > 0) return str_replace('{n}', $diff->d, t('notif_time_ago_day'));
        if ($diff->h > 0) return str_replace('{n}', $diff->h, t('notif_time_ago_hour'));
        if ($diff->i > 0) return str_replace('{n}', $diff->i, t('notif_time_ago_minute'));
        return t('common_now');
    }

    /**
     * الحصول على خريطة الأيقونات
     */
    public static function getIconMap() { return self::$icon_map; }
    public static function getColorMap() { return self::$color_map; }
    public static function getTypeLabels() { return self::getTranslatedTypeLabels(); }

    /**
     * ترجمة العنوان والرسالة من المفاتيح المخزنة
     * إذا وُجد title_key/message_key، تُترجم بلغة المستخدم الحالية
     * خلاف ذلك، تُبقى القيم الأصلية (توافق مع الإشعارات القديمة)
     */
    private static function resolveI18n(array $n): array {
        if (!empty($n['title_key']) && function_exists('t')) {
            $n['title'] = t($n['title_key']);
        }
        if (!empty($n['message_key']) && function_exists('t')) {
            $text = t($n['message_key']);
            if (!empty($n['message_params'])) {
                $params = is_string($n['message_params']) ? json_decode($n['message_params'], true) : $n['message_params'];
                if (is_array($params)) {
                    foreach ($params as $key => $value) {
                        $text = str_replace('{' . $key . '}', $value, $text);
                    }
                }
            }
            $n['message'] = $text;
        }
        return $n;
    }

    /**
     * إرسال Push Notifications للمستخدمين المستهدفين
     * يعمل في الخلفية — لا يُعطّل إنشاء الإشعار إذا فشل الإرسال
     *
     * @param array $userIds قائمة معرفات المستخدمين
     * @param string $type نوع الإشعار
     * @param string $title العنوان
     * @param string $message الرسالة
     * @param array|null $data بيانات إضافية
     * @param string $priority مستوى الأولوية
     */
    private static function sendPushToUsers(array $userIds, string $type, string $title, string $message, ?array $data, string $priority): void {
        try {
            // فحص تفعيل Push
            $pushEnabled = getSetting('push_notifications_enabled', '0');
            if ($pushEnabled !== '1') return;

            // تحميل PushSender
            if (!class_exists('PushSender')) {
                $pushSenderFile = __DIR__ . '/push-sender.php';
                if (!file_exists($pushSenderFile)) return;
                require_once $pushSenderFile;
            }

            if (!class_exists('VapidKeys')) {
                $vapidFile = __DIR__ . '/vapid-keys.php';
                if (!file_exists($vapidFile)) return;
                require_once $vapidFile;
            }

            if (!VapidKeys::isConfigured()) return;

            // إعداد الحمولة
            $iconMap = self::$icon_map;
            $payload = [
                'title' => ($iconMap[$type] ?? '🔔') . ' ' . $title,
                'body' => $message,
                'type' => $type,
                'priority' => $priority,
                'tag' => 'ism-' . $type,
                'url' => $data['url'] ?? '',
                'icon' => '/assets/img/price-check-icon-192.png',
            ];

            // إرسال — لا يُعطّل العملية إذا فشل
            PushSender::sendToUsers($userIds, $payload);
        } catch (\Throwable $e) {
            // تسجيل الخطأ دون إعاقة إنشاء الإشعار
            if (class_exists('ErrorHandler')) {
                ErrorHandler::logException($e, 'notifications::sendPushToUsers');
            }
        }
    }
}
