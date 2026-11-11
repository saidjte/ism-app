<?php
/**
 * ===== فئة قاعدة البيانات (Database) =====
 * نظام اتصال بقاعدة البيانات مبني على نمط Singleton (نسخة واحدة)
 * 
 * الاستخدام:
 *   $db = Database::getInstance();
 *   $row = $db->fetchOne("SELECT * FROM users WHERE id = ?", [1]);
 *   $rows = $db->fetchAll("SELECT * FROM products");
 *   $id = $db->insert("INSERT INTO users (name) VALUES (?)", ["أحمد"]);
 * 
 * الميزات:
 *   - نمط Singleton (اتصال واحد فقط)
 *   - استعلامات محضرة (Prepared Statements)
 *   - تخزين مؤقت للإعدادات
 *   - توافق كامل مع الدوال العامة القديمة
 */

// ⚠️ لا نستخدم namespace App لأنها تسبب مشاكل على Windows (NTFS)
// الفئة مُسماة AppDatabase مباشرة في المساحة العامة

class AppDatabase
{
    /** @var ?PDO اتصال قاعدة البيانات */
    private ?PDO $connection = null;

    /** @var ?AppDatabase نسخة واحدة من الصنف (Singleton) */
    private static ?self $instance = null;

    /** @var array<string, string> ذاكرة مؤقتة للإعدادات */
    private static array $settingsCache = [];

    /** @var ?string ذاكرة مؤقتة لرمز العملة */
    private static ?string $currencySymbolCache = null;

    /**
     * إنشاء نسخة جديدة - خاص لضمان Singleton
     */
    private function __construct()
    {
        // يتم إنشاء الاتصال عند أول طلب (lazy loading)
    }

    /**
     * منع استنساخ الكائن
     */
    private function __clone() {}

    /**
     * منع إلغاء التسلسل
     */
    public function __wakeup()
    {
        throw new \RuntimeException("Cannot unserialize a singleton.");
    }

    /**
     * الحصول على النسخة الوحيدة من الكائن (Singleton)
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * الحصول على اتصال PDO بقاعدة البيانات
     * يستخدم التوصيل الكسول (Lazy Connection) - يتم الاتصال فقط عند أول استخدام
     *
     * @return PDO
     * @throws \RuntimeException إذا فشل الاتصال
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "خطأ في الاتصال بقاعدة البيانات. تأكد من أن قاعدة البيانات موجودة ومُهيأة بشكل صحيح."
                );
            }
        }
        return $this->connection;
    }

    /**
     * تنفيذ استعلام مع معاملات
     *
     * @param string $sql استعلام SQL
     * @param array $params المعاملات
     * @return PDOStatement
     * @throws \RuntimeException إذا فشل الاتصال
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * جلب صف واحد من نتائج الاستعلام
     *
     * @param string $sql استعلام SQL
     * @param array $params المعاملات
     * @return array|null صف واحد أو null إذا لم يتم العثور على نتائج
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * جلب كل الصفوف من نتائج الاستعلام
     *
     * @param string $sql استعلام SQL
     * @param array $params المعاملات
     * @return array مصفوفة من الصفوف
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * إدراج بيانات وإرجاع آخر معرف
     *
     * @param string $sql استعلام SQL للإدراج
     * @param array $params المعاملات
     * @return string معرف الصف المُدرج
     * @throws \RuntimeException إذا فشل الاتصال
     */
    public function insert(string $sql, array $params = []): string
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $pdo->lastInsertId();
    }

    /**
     * جلب قيمة إعداد من جدول system_settings
     * يستخدم ذاكرة مؤقتة ثابتة لتحسين الأداء
     *
     * @param string $key مفتاح الإعداد
     * @param string $default القيمة الافتراضية
     * @return string قيمة الإعداد
     */
    public function getSetting(string $key, string $default = ''): string
    {
        if (isset(self::$settingsCache[$key])) {
            return self::$settingsCache[$key];
        }
        try {
            $row = $this->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                [$key]
            );
            self::$settingsCache[$key] = $row ? $row['setting_value'] : $default;
        } catch (\Exception $e) {
            self::$settingsCache[$key] = $default;
        }
        return self::$settingsCache[$key];
    }

    /**
     * حفظ قيمة إعداد في جدول system_settings
     * يقوم بتحديث ذاكرة الإعدادات المؤقتة أيضاً
     *
     * @param string $key مفتاح الإعداد
     * @param string $value قيمة الإعداد
     * @param string|null $description وصف اختياري
     */
    public function saveSetting(string $key, string $value, ?string $description = null): void
    {
        if ($description !== null) {
            $this->query(
                "INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_at = CURRENT_TIMESTAMP",
                [$key, $value, $description]
            );
        } else {
            $this->query(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP",
                [$key, $value]
            );
        }
        // تحديث ذاكرة الإعدادات المؤقتة
        self::$settingsCache[$key] = $value;

        // إذا كان الإعداد هو رمز العملة، نحدث ذاكرة العملة المؤقتة
        if ($key === 'currency_symbol') {
            self::$currencySymbolCache = $value;
        }
    }

    /**
     * جلب رمز العملة من إعدادات النظام
     * الافتراضي: د.م. (درهم مغربي)
     * يستخدم ذاكرة مؤقتة ثابتة
     *
     * @return string رمز العملة
     */
    public function getCurrencySymbol(): string
    {
        if (self::$currencySymbolCache !== null) {
            return self::$currencySymbolCache;
        }
        try {
            $row = $this->fetchOne(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'currency_symbol'"
            );
            self::$currencySymbolCache = $row ? $row['setting_value'] : 'د.م.';
        } catch (\Exception $e) {
            self::$currencySymbolCache = 'د.م.';
        }
        return self::$currencySymbolCache;
    }

    /**
     * تنسيق المبلغ المالي مع رمز العملة
     *
     * @param float $amount المبلغ
     * @param int $decimals عدد الأرقام العشرية (افتراضي 2)
     * @return string المبلغ المنسق مع رمز العملة
     */
    public function formatMoney(?float $amount, int $decimals = 2): string
    {
        return number_format($amount ?? 0, $decimals) . ' ' . $this->getCurrencySymbol();
    }

    /**
     * التحقق من وجود جدول في قاعدة البيانات
     *
     * @param string $tableName اسم الجدول
     * @return bool هل الجدول موجود
     */
    public function tableExists(string $tableName): bool
    {
        try {
            $stmt = $this->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
                [$tableName]
            );
            return $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * إعادة تعيين اتصال قاعدة البيانات (مفيد للاختبارات)
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * مسح ذاكرة الإعدادات المؤقتة
     */
    public static function clearCache(): void
    {
        self::$settingsCache = [];
        self::$currencySymbolCache = null;
    }

    /**
     * بناء شرط ORDER BY بشكل آمن من مدخلات المستخدم
     * يتحقق من صحة أسماء الأعمدة والاتجاهات لمنع حقن SQL
     *
     * @param array $orderBy مصفوفة من ['column' => string, 'direction' => 'ASC'|'DESC']
     * @param array $allowedColumns الأعمدة المسموح بها (قائمة بيضاء)
     * @param string $default عمود الترتيب الافتراضي
     * @return string شرط ORDER BY آمن
     */
    public function buildOrderBy(array $orderBy, array $allowedColumns = [], string $default = 'id ASC'): string
    {
        if (empty($orderBy)) {
            return $default;
        }

        $parts = [];
        $allowedDirs = ['ASC', 'DESC'];

        foreach ($orderBy as $item) {
            $col = $item['column'] ?? $item[0] ?? '';
            $dir = strtoupper($item['direction'] ?? $item[1] ?? 'ASC');

            // التحقق من عمود مسموح به
            if (!empty($allowedColumns) && !in_array($col, $allowedColumns, true)) {
                continue;
            }

            // تنظيف اسم العمود (فقط أحرف أبجدية رقمية وشرطة سفلية ونقطة)
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $col)) {
                continue;
            }

            // التحقق من اتجاه الترتيب
            if (!in_array($dir, $allowedDirs, true)) {
                $dir = 'ASC';
            }

            $parts[] = "`{$col}` {$dir}";
        }

        return !empty($parts) ? implode(', ', $parts) : $default;
    }
}
