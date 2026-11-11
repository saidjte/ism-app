<?php
/**
 * ===== خدمة البحث المركزية (SearchService) =====
 *
 * طبقة بحث موحّدة لجميع استعلامات البحث في التطبيق.
 *
 * الميزات:
 * - فهرس FULLTEXT (MySQL): MATCH...AGAINST لبحث سريع بدلاً من LIKE '%term%'
 * - توافق SQLite: تراجع تلقائي إلى LIKE عند تشغيل الاختبارات على SQLite
 * - ترتيب بالصلة: النتائج المطابقة للأكواد تظهر أولاً، ثم الأسماء، ثم الباركود
 * - توحيد استعلام العد: COUNT(*) OVER() (MySQL 8+) أو استعلام منفصل (MySQL 5.7)
 * - دعم عمود الباركود الاختياري
 *
 * @since v4.19.0
 */

class SearchService
{
    /** @var bool|null نتيجة كشف FULLTEXT مخزّنة مؤقتاً */
    private static ?bool $fulltextAvailable = null;

    /** @var bool|null نتيجة كشف MySQL 8+ مخزّنة مؤقتاً */
    private static ?bool $windowFunctionAvailable = null;

    /** @var bool|null نتيجة كشف عمود color في جدول التصنيفات */
    private static ?bool $categoryColorAvailable = null;

    /** @var bool|null نتيجة كشف عمود deleted_at في جدول المنتجات */
    private static ?bool $deletedAtAvailable = null;

    /** @var int الحد الأدنى لطول كلمة البحث لاستخدام FULLTEXT */
    private const FULLTEXT_MIN_LENGTH = 3;

    /**
     * كشف ما إذا كان فهرس FULLTEXT متاحاً على جدول المنتجات
     *
     * يتحقق من:
     * 1. نوع قاعدة البيانات (MySQL مطلوب)
     * 2. وجود فهرس FULLTEXT على الأعمدة المطلوبة
     *
     * @return bool
     */
    public static function isFulltextAvailable(): bool
    {
        if (self::$fulltextAvailable !== null) {
            return self::$fulltextAvailable;
        }

        try {
            $pdo = AppDatabase::getInstance()->getConnection();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            // SQLite لا يدعم FULLTEXT بنفس طريقة MySQL
            if ($driver === 'sqlite') {
                self::$fulltextAvailable = false;
                return false;
            }

            // التحقق من وجود فهرس FULLTEXT على جدول المنتجات
            $indexes = AppDatabase::getInstance()->fetchAll("SHOW INDEX FROM products WHERE Index_type = 'FULLTEXT'");
            if (!empty($indexes)) {
                self::$fulltextAvailable = true;
                return true;
            }

            self::$fulltextAvailable = false;
            return false;
        } catch (\Exception $e) {
            self::$fulltextAvailable = false;
            return false;
        }
    }

    /**
     * كشف ما إذا كانت الدوال النوافذية (Window Functions) متاحة
     * مدعومة في MySQL 8.0+ و MariaDB 10.2+
     *
     * @return bool
     */
    public static function isWindowFunctionAvailable(): bool
    {
        if (self::$windowFunctionAvailable !== null) {
            return self::$windowFunctionAvailable;
        }

        try {
            $pdo = AppDatabase::getInstance()->getConnection();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                // SQLite 3.25+ يدعم Window Functions
                $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                self::$windowFunctionAvailable = version_compare($version, '3.25.0', '>=');
                return self::$windowFunctionAvailable;
            }

            // MySQL أو MariaDB
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            // كشف MariaDB
            if (stripos($version, 'mariadb') !== false) {
                // MariaDB 10.2+ يدعم Window Functions
                preg_match('/(\d+\.\d+)/', $version, $matches);
                $mariaVersion = $matches[1] ?? '0.0';
                self::$windowFunctionAvailable = version_compare($mariaVersion, '10.2', '>=');
            } else {
                // MySQL 8.0+ يدعم Window Functions
                preg_match('/(\d+\.\d+)/', $version, $matches);
                $mysqlVersion = $matches[1] ?? '0.0';
                self::$windowFunctionAvailable = version_compare($mysqlVersion, '8.0', '>=');
            }

            return self::$windowFunctionAvailable;
        } catch (\Exception $e) {
            self::$windowFunctionAvailable = false;
            return false;
        }
    }

    /**
     * كشف ما إذا كان عمود الباركود موجوداً في جدول المنتجات
     *
     * @return bool
     */
    public static function hasBarcodeColumn(): bool
    {
        try {
            static $hasBarcode = null;
            if ($hasBarcode !== null) {
                return $hasBarcode;
            }

            $pdo = AppDatabase::getInstance()->getConnection();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                // SQLite: التحقق من خلال PRAGMA
                $columns = AppDatabase::getInstance()->fetchAll("PRAGMA table_info(products)");
                $colNames = array_map(fn($c) => strtolower($c['name'] ?? $c['Field'] ?? ''), $columns);
            } else {
                // MySQL: SHOW COLUMNS مع كاش بنية الجدول (لا تتغير إلا نادراً)
                $colNames = SchemaCache::remember('products_columns', function() {
                    $columns = AppDatabase::getInstance()->fetchAll("SHOW COLUMNS FROM products");
                    return array_map(fn($c) => strtolower($c['Field'] ?? ''), $columns);
                });
            }

            $hasBarcode = in_array('barcode', $colNames);
            return $hasBarcode;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * كشف ما إذا كان عمود deleted_at موجوداً في جدول المنتجات
     *
     * @return bool
     */
    public static function hasDeletedAtColumn(): bool
    {
        if (self::$deletedAtAvailable !== null) {
            return self::$deletedAtAvailable;
        }

        try {
            $pdo = AppDatabase::getInstance()->getConnection();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                // SQLite: التحقق من خلال PRAGMA
                $columns = AppDatabase::getInstance()->fetchAll("PRAGMA table_info(products)");
                $colNames = array_map(fn($c) => strtolower($c['name'] ?? $c['Field'] ?? ''), $columns);
            } else {
                // MySQL: SHOW COLUMNS مع كاش بنية الجدول (لا تتغير إلا نادراً)
                $colNames = SchemaCache::remember('products_columns', function() {
                    $columns = AppDatabase::getInstance()->fetchAll("SHOW COLUMNS FROM products");
                    return array_map(fn($c) => strtolower($c['Field'] ?? ''), $columns);
                });
            }

            self::$deletedAtAvailable = in_array('deleted_at', $colNames);
            return self::$deletedAtAvailable;
        } catch (\Exception $e) {
            self::$deletedAtAvailable = false;
            return false;
        }
    }

    /**
     * كشف ما إذا كان عمود color موجوداً في جدول التصنيفات
     *
     * @return bool
     */
    public static function hasCategoryColorColumn(): bool
    {
        if (self::$categoryColorAvailable !== null) {
            return self::$categoryColorAvailable;
        }

        try {
            $pdo = AppDatabase::getInstance()->getConnection();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                // SQLite: التحقق من خلال PRAGMA
                $columns = AppDatabase::getInstance()->fetchAll("PRAGMA table_info(categories)");
                $colNames = array_map(fn($c) => strtolower($c['name'] ?? $c['Field'] ?? ''), $columns);
            } else {
                // MySQL: SHOW COLUMNS مع كاش بنية الجدول (لا تتغير إلا نادراً)
                $colNames = SchemaCache::remember('categories_columns', function() {
                    $columns = AppDatabase::getInstance()->fetchAll("SHOW COLUMNS FROM categories");
                    return array_map(fn($c) => strtolower($c['Field'] ?? ''), $columns);
                });
            }

            self::$categoryColorAvailable = in_array('color', $colNames);
            return self::$categoryColorAvailable;
        } catch (\Exception $e) {
            self::$categoryColorAvailable = false;
            return false;
        }
    }

    /**
     * بناء أعمدة SELECT للتصنيفات بشكل ديناميكي
     * يتحقق من وجود عمود color قبل تضمينه
     *
     * @return string أعمدة SELECT للتصنيفات
     */
    public static function buildCategorySelect(): string
    {
        $parts = ['c.name as category_name'];
        if (self::hasCategoryColorColumn()) {
            $parts[] = 'c.color as category_color';
        }
        return 'p.*, ' . implode(', ', $parts);
    }

    /**
     * بناء شرط WHERE للبحث عن المنتجات
     *
     * يستخدم MATCH...AGAINST إذا كان فهرس FULLTEXT متاحاً وكلمة البحث طويلة بما يكفي،
     * وإلا يتراجع إلى LIKE.
     *
     * @param string $searchTerm نص البحث
     * @param string $tableAlias اسم مستعار الجدول (افتراضي: 'p')
     * @param bool $includeBarcode تضمين الباركود في البحث
     * @return array ['where' => string, 'params' => array, 'relevance_score' => string]
     */
    public static function buildSearchWhere(
        string $searchTerm,
        string $tableAlias = 'p',
        bool $includeBarcode = false
    ): array {
        if (empty(trim($searchTerm))) {
            return ['where' => '', 'params' => [], 'relevance_score' => ''];
        }

        $searchTerm = trim($searchTerm);
        $useFulltext = self::isFulltextAvailable() && mb_strlen($searchTerm) >= self::FULLTEXT_MIN_LENGTH;

        if ($useFulltext) {
            // === FULLTEXT: MATCH...AGAINST للاسم والكود فقط + LIKE للباركود ===
            // [إصلاح v5.12.2] فصل الباركود عن FULLTEXT لأن:
            // 1. فهرس FULLTEXT قد لا يتضمن عمود الباركود ← خطأ SQL
            // 2. FULLTEXT قد لا يطابق السلاسل الرقمية (الباركودات) بشكل صحيح
            // 3. نفس نهج صفحة "أسعاري" — بحث LIKE دائم للباركود
            $columns = "{$tableAlias}.name, {$tableAlias}.code";

            // BOOLEAN MODE مع نجمة لاحقة فقط للمطابقة الجزئية
            $booleanTerm = $searchTerm . '*';
            $where = "(MATCH({$columns}) AGAINST(? IN BOOLEAN MODE))";
            $params = [$booleanTerm];

            // إضافة بحث LIKE للباركود دائماً (مثل صفحة أسعاري)
            if ($includeBarcode) {
                $likeTerm = '%' . $searchTerm . '%';
                $where .= " OR {$tableAlias}.barcode LIKE ?";
                $params[] = $likeTerm;
            }

            // درجة الصلة من FULLTEXT (الاسم والكود فقط)
            $relevanceScore = "MATCH({$columns}) AGAINST(? IN BOOLEAN MODE) as relevance_score";

            return [
                'where' => $where,
                'params' => $params,
                'relevance_score' => $relevanceScore,
            ];
        }

        // === LIKE: تراجع للتوافق ===
        $likeTerm = '%' . $searchTerm . '%';

        $conditions = ["{$tableAlias}.name LIKE ?", "{$tableAlias}.code LIKE ?"];
        $params = [$likeTerm, $likeTerm];

        if ($includeBarcode) {
            $conditions[] = "{$tableAlias}.barcode LIKE ?";
            $params[] = $likeTerm;
        }

        $where = '(' . implode(' OR ', $conditions) . ')';

        // ترتيب بالصلة: مطابقة البداية أولاً (لـ LIKE فقط)
        $startTerm = $searchTerm . '%';
        $orderCases = "CASE WHEN {$tableAlias}.code LIKE ? THEN 0 WHEN {$tableAlias}.name LIKE ? THEN 1";
        $orderParams = [$startTerm, $startTerm];
        if ($includeBarcode) {
            $orderCases .= " WHEN {$tableAlias}.barcode LIKE ? THEN 2";
            $orderParams[] = $startTerm;
        }
        $orderCases .= " ELSE 3 END";

        return [
            'where' => $where,
            'params' => $params,
            'relevance_score' => '',
            'order_by_case' => $orderCases,
            'order_by_params' => $orderParams,
        ];
    }

    /**
     * بناء ترتيب النتائج بالصلة (Relevance Ordering)
     *
     * @param string $searchTerm نص البحث
     * @param string $tableAlias اسم مستعار الجدول
     * @param bool $includeBarcode تضمين الباركود
     * @return array ['order_by' => string, 'params' => array]
     */
    public static function buildRelevanceOrderBy(
        string $searchTerm,
        string $tableAlias = 'p',
        bool $includeBarcode = false
    ): array {
        if (empty(trim($searchTerm))) {
            return ['order_by' => "{$tableAlias}.name ASC", 'params' => []];
        }

        $searchTerm = trim($searchTerm);
        $useFulltext = self::isFulltextAvailable() && mb_strlen($searchTerm) >= self::FULLTEXT_MIN_LENGTH;

        if ($useFulltext) {
            // FULLTEXT: الترتيب بدرجة الصلة (relevance_score) ثم مطابقة الباركود ثم بالاسم
            // [إصلاح v5.12.2] إضافة أولوية للباركود لأنه مفصول عن FULLTEXT
            if ($includeBarcode) {
                $startTerm = $searchTerm . '%';
                return [
                    'order_by' => 'relevance_score DESC, CASE WHEN ' . $tableAlias . '.barcode LIKE ? THEN 0 ELSE 1 END, ' . $tableAlias . '.name ASC',
                    'params' => [$startTerm],
                ];
            }
            return [
                'order_by' => 'relevance_score DESC, ' . $tableAlias . '.name ASC',
                'params' => [],
            ];
        }

        // LIKE: ترتيب بمطابقة البداية
        $startTerm = $searchTerm . '%';
        $orderParts = "CASE WHEN {$tableAlias}.code LIKE ? THEN 0 WHEN {$tableAlias}.name LIKE ? THEN 1";
        $params = [$startTerm, $startTerm];
        if ($includeBarcode) {
            $orderParts .= " WHEN {$tableAlias}.barcode LIKE ? THEN 2";
            $params[] = $startTerm;
        }
        $orderParts .= " ELSE 3 END, {$tableAlias}.name ASC";

        return [
            'order_by' => $orderParts,
            'params' => $params,
        ];
    }

    /**
     * بحث موحّد عن المنتجات مع ترقيم الصفحات
     *
     * يوحد استعلام العد والبيانات في طلب واحد باستخدام COUNT(*) OVER()
     * عندما تكون الدوال النوافذية متاحة، وإلا يستخدم استعلامين منفصلين.
     *
     * @param array $filters فلاتر البحث: search, category_id, has_expiry, stock_status
     * @param int $page رقم الصفحة (ابتداءً من 1)
     * @param int $perPage عدد النتائج في كل صفحة
     * @param array $options خيارات إضافية: include_barcode, select_columns, additional_joins
     * @return array ['products' => array, 'total' => int, 'page' => int, 'per_page' => int, 'total_pages' => int]
     */
    public static function searchProducts(
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
        array $options = []
    ): array {
        $where = [];
        $params = [];
        $orderByParams = [];
        $relevanceSelect = '';
        $orderBy = 'p.name ASC';

        $includeBarcode = $options['include_barcode'] ?? self::hasBarcodeColumn();

        // === بناء شرط البحث ===
        if (!empty($filters['search'])) {
            $searchResult = self::buildSearchWhere($filters['search'], 'p', $includeBarcode);
            $where[] = $searchResult['where'];
            $params = array_merge($params, $searchResult['params']);

            // درجة الصلة (FULLTEXT)
            if (!empty($searchResult['relevance_score'])) {
                $relevanceSelect = ', ' . $searchResult['relevance_score'];
                // إضافة معامل إضافي لدرجة الصلة في SELECT
                $params[] = $searchResult['params'][0]; // نفس مصطلح البحث
            }

            // ترتيب بالصلة
            $orderResult = self::buildRelevanceOrderBy($filters['search'], 'p', $includeBarcode);
            $orderBy = $orderResult['order_by'];
            $orderByParams = $orderResult['params'];
        }

        // === فلاتر إضافية ===
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $params[] = intval($filters['category_id']);
        }

        if (isset($filters['has_expiry']) && $filters['has_expiry'] !== '') {
            $where[] = "p.has_expiry = ?";
            $params[] = intval($filters['has_expiry']);
        }

        if (!empty($filters['stock_status'])) {
            switch ($filters['stock_status']) {
                case 'out':
                    $where[] = "p.quantity <= 0";
                    break;
                case 'low':
                    $where[] = "p.quantity > 0 AND p.quantity <= p.min_quantity";
                    break;
                case 'ok':
                    $where[] = "p.quantity > p.min_quantity";
                    break;
            }
        }

        // === فلتر deleted_at ===
        $includeDeletedAt = $options['include_deleted_at'] ?? true;
        if ($includeDeletedAt && self::hasDeletedAtColumn()) {
            $where[] = "p.deleted_at IS NULL";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // === أعمدة SELECT ===
        $selectColumns = $options['select_columns'] ?? self::buildCategorySelect();

        // === JOINs ===
        $joins = "LEFT JOIN categories c ON p.category_id = c.id";
        if (!empty($options['additional_joins'])) {
            $joins .= ' ' . $options['additional_joins'];
        }

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // === توحيد استعلام العد والبيانات ===
        $useWindowFunction = self::isWindowFunctionAvailable();

        try {
            if ($useWindowFunction && !empty($filters['search'])) {
                // === COUNT(*) OVER(): استعلام واحد للعد والبيانات ===
                $countSelect = str_replace('p.*', 'p.*', $selectColumns); // لا تغيير
                $sql = "SELECT {$selectColumns}{$relevanceSelect}, COUNT(*) OVER() as total_count
                        FROM products p
                        {$joins}
                        {$whereClause}
                        ORDER BY {$orderBy}
                        LIMIT {$perPage} OFFSET {$offset}";

                $allParams = array_merge($params, $orderByParams);
                $products = AppDatabase::getInstance()->fetchAll($sql, $allParams);

                // استخراج العدد الإجمالي من أول صف
                $total = 0;
                if (!empty($products)) {
                    $total = intval($products[0]['total_count'] ?? 0);
                    // إزالة عمود total_count من النتائج
                    foreach ($products as &$product) {
                        unset($product['total_count']);
                        if (isset($product['relevance_score'])) {
                            unset($product['relevance_score']);
                        }
                    }
                    unset($product);
                }
            } else {
                // === استعلامان منفصلان: العد + البيانات ===
                $countSql = "SELECT COUNT(*) as total FROM products p {$whereClause}";
                $countResult = AppDatabase::getInstance()->fetchOne($countSql, $params);
                $total = $countResult ? intval($countResult['total']) : 0;

                $sql = "SELECT {$selectColumns}{$relevanceSelect}
                        FROM products p
                        {$joins}
                        {$whereClause}
                        ORDER BY {$orderBy}
                        LIMIT {$perPage} OFFSET {$offset}";

                $allParams = array_merge($params, $orderByParams);
                $products = AppDatabase::getInstance()->fetchAll($sql, $allParams);

                // إزالة عمود relevance_score من النتائج
                foreach ($products as &$product) {
                    if (isset($product['relevance_score'])) {
                        unset($product['relevance_score']);
                    }
                }
                unset($product);
            }
        } catch (\Exception $e) {
            // في حالة فشل FULLTEXT (مثلاً الفهرس غير موجود بعد)، نتراجع إلى LIKE
            ErrorHandler::logException($e, 'SearchService::searchProducts');

            // إعادة تعيين الكشف المخزن مؤقتاً
            self::$fulltextAvailable = false;

            // إعادة المحاولة بـ LIKE
            if (!empty($filters['search'])) {
                $likeTerm = '%' . trim($filters['search']) . '%';
                $startTerm = trim($filters['search']) . '%';

                // إعادة بناء WHERE بـ LIKE فقط
                $where = [];
                $params = [];

                $likeConditions = ["p.name LIKE ?", "p.code LIKE ?"];
                $params = [$likeTerm, $likeTerm];
                if ($includeBarcode) {
                    $likeConditions[] = "p.barcode LIKE ?";
                    $params[] = $likeTerm;
                }
                $where[] = '(' . implode(' OR ', $likeConditions) . ')';

                // إعادة إضافة الفلاتر
                if (!empty($filters['category_id'])) {
                    $where[] = "p.category_id = ?";
                    $params[] = intval($filters['category_id']);
                }
                if (isset($filters['has_expiry']) && $filters['has_expiry'] !== '') {
                    $where[] = "p.has_expiry = ?";
                    $params[] = intval($filters['has_expiry']);
                }
                if (!empty($filters['stock_status'])) {
                    switch ($filters['stock_status']) {
                        case 'out': $where[] = "p.quantity <= 0"; break;
                        case 'low': $where[] = "p.quantity > 0 AND p.quantity <= p.min_quantity"; break;
                        case 'ok': $where[] = "p.quantity > p.min_quantity"; break;
                    }
                }

                // فلتر deleted_at في LIKE fallback
                if ($includeDeletedAt && self::hasDeletedAtColumn()) {
                    $where[] = "p.deleted_at IS NULL";
                }

                $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

                $countSql = "SELECT COUNT(*) as total FROM products p {$whereClause}";
                $countResult = AppDatabase::getInstance()->fetchOne($countSql, $params);
                $total = $countResult ? intval($countResult['total']) : 0;

                // ترتيب LIKE
                $orderCases = "CASE WHEN p.code LIKE ? THEN 0 WHEN p.name LIKE ? THEN 1";
                $orderParams = [$startTerm, $startTerm];
                if ($includeBarcode) {
                    $orderCases .= " WHEN p.barcode LIKE ? THEN 2";
                    $orderParams[] = $startTerm;
                }
                $orderCases .= " ELSE 3 END, p.name ASC";

                $sql = "SELECT {$selectColumns}
                        FROM products p
                        {$joins}
                        {$whereClause}
                        ORDER BY {$orderCases}
                        LIMIT {$perPage} OFFSET {$offset}";

                $allParams = array_merge($params, $orderParams);
                $products = AppDatabase::getInstance()->fetchAll($sql, $allParams);
            } else {
                $products = [];
                $total = 0;
            }
        }

        return [
            'products' => $products ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * إعادة تعيين الكشف المخزن مؤقتاً (لاختبارات الوحدة)
     */
    public static function resetCache(): void
    {
        self::$fulltextAvailable = null;
        self::$windowFunctionAvailable = null;
        self::$categoryColorAvailable = null;
        self::$deletedAtAvailable = null;
    }
}
