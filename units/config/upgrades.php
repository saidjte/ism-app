<?php
// ===== نظام التحديث التدريجي =====
// هذا الملف يحتوي على جميع التحديثات التي يمكن تطبيقها على قاعدة البيانات
// كل تحديث له رقم إصدار ويُطبَّق بالترتيب فقط إذا لم يكن مُطبَّقاً بعد

// الإصدار الحالي للتطبيق
define('APP_VERSION', '3.8.0');

// ===== قائمة التحديثات =====
// كل تحديث يحتوي على: رقم الإصدار، وصف، ودالة التنفيذ
$UPDATES = [

    [
        'version' => '1.0.0',
        'description' => 'الإصدار الأساسي - إنشاء جميع الجداول',
        'function' => 'upgrade_1_0_0'
    ],
    [
        'version' => '1.1.0',
        'description' => 'إضافة جدول سجل تغيير الأسعار (price_history)',
        'function' => 'upgrade_1_1_0'
    ],
    [
        'version' => '1.2.0',
        'description' => 'إضافة حقول سعر الشراء وقت البيع والتكلفة والربح في جدول المبيعات',
        'function' => 'upgrade_1_2_0'
    ],
    // ملاحظة: إصدار 2.0.0 تم دمجه في 1.0.0 (جدول system_settings يُنشأ أولاً)
    // [
    //     'version' => '2.0.0',
    //     'description' => 'إضافة جدول إعدادات النظام (system_settings) لدعم التحديث التدريجي',
    //     'function' => 'upgrade_2_0_0'
    // ],
    [
        'version' => '2.1.0',
        'description' => 'إضافة نظام تتبع دفعات الشراء (FIFO) - حساب التكلفة بسعر الشراء الأصلي لكل دفعة',
        'function' => 'upgrade_2_1_0'
    ],
    [
        'version' => '3.0.0',
        'description' => 'إضافة نظام المستخدمين والمصادقة - تسجيل الدخول وإدارة المستخدمين',
        'function' => 'upgrade_3_0_0'
    ],
    [
        'version' => '3.1.0',
        'description' => 'إضافة نظام تصنيفات المنتجات - جدول التصنيفات + ربطها بالمنتجات',
        'function' => 'upgrade_3_1_0'
    ],
    [
        'version' => '3.2.0',
        'description' => 'إضافة إعداد العملة - د.م. (درهم مغربي) كعملة افتراضية',
        'function' => 'upgrade_3_2_0'
    ],
    [
        'version' => '3.3.0',
        'description' => 'إضافة نظام وحدات القياس المركزية - جدول الوحدات مع CRUD كامل',
        'function' => 'upgrade_3_3_0'
    ],
    [
        'version' => '3.4.0',
        'description' => 'إضافة نظام المصاريف العامة - مواد التغليف، استخلاص الأرباح، مصاريف تشغيلية',
        'function' => 'upgrade_3_4_0'
    ],
    [
        'version' => '3.5.0',
        'function' => 'upgrade_3_5_0',
    ],
    [
        'version' => '3.6.0',
        'description' => 'إضافة نظام وحدات الشراء المستقلة - purchase_unit و purchase_additional_units',
        'function' => 'upgrade_3_6_0'
    ],
    [
        'version' => '3.7.0',
        'description' => 'إصلاح جداول مفقودة - التأكد من وجود جميع الجداول الأساسية',
        'function' => 'upgrade_3_7_0'
    ],
    [
        'version' => '3.8.0',
        'description' => 'إصلاح شامل - إعادة إنشاء جميع الجداول والأعمدة المفقودة',
        'function' => 'upgrade_3_8_0'
    ],
    // ===== أضف التحديثات المستقبلية هنا =====
];

// ===== دوال التحديث =====

/**
 * الإصدار 1.0.0 - إنشاء جميع الجداول الأساسية
 */
function upgrade_1_0_0($pdo) {
    // جدول إعدادات النظام (يجب إنشاؤه أولاً لأن updateDBVersion تحتاجه)
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المنتجات
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit VARCHAR(50) NOT NULL,
        quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول وحدات القياس الإضافية
    $pdo->exec("CREATE TABLE IF NOT EXISTS additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المشتريات
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        purchase_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المبيعات (النسخة الأساسية)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        selling_price DECIMAL(12,2) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        sale_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول جرد المخزون
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_counts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        actual_quantity DECIMAL(12,2) NOT NULL,
        system_quantity DECIMAL(12,2) NOT NULL,
        difference DECIMAL(12,2) NOT NULL,
        count_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * الإصدار 1.1.0 - إضافة جدول سجل تغيير الأسعار
 */
function upgrade_1_1_0($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        old_purchase_price DECIMAL(12,2) DEFAULT NULL,
        new_purchase_price DECIMAL(12,2) DEFAULT NULL,
        old_selling_price DECIMAL(12,2) DEFAULT NULL,
        new_selling_price DECIMAL(12,2) DEFAULT NULL,
        change_reason VARCHAR(255) DEFAULT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * الإصدار 1.2.0 - إضافة حقول حساب الربح في جدول المبيعات
 */
function upgrade_1_2_0($pdo) {
    // التحقق من وجود الأعمدة قبل إضافتها
    $cols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('purchase_price_at_sale', $cols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN purchase_price_at_sale DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'سعر الشراء وقت البيع' AFTER selling_price");
    }
    if (!in_array('cost_total', $cols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN cost_total DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'تكلفة البضاعة المباعة' AFTER total");
    }
    if (!in_array('profit', $cols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN profit DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'ربح العملية' AFTER cost_total");
    }

    // تحديث البيانات القديمة: حساب التكلفة والأرباح للسجلات الموجودة
    $pdo->exec("UPDATE sales s JOIN products p ON s.product_id = p.id 
                SET s.purchase_price_at_sale = p.purchase_price,
                    s.cost_total = s.quantity * p.purchase_price,
                    s.profit = s.total - (s.quantity * p.purchase_price)
                WHERE s.purchase_price_at_sale = 0");
}

/**
 * الإصدار 2.0.0 - تم دمجه في upgrade_1_0_0
 * جدول system_settings يُنشأ الآن في أول تحديث
 * هذه الدالة تبقى فارغة للتوافق مع التثبيتات القديمة
 */
/**
 * الإصدار 3.0.0 - نظام المستخدمين والمصادقة
 * إنشاء جدول المستخدمين مع أدوار وصلاحيات متعددة
 */
function upgrade_3_0_0($pdo) {
    // جدول المستخدمين
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) DEFAULT NULL,
        role ENUM('admin', 'manager', 'user') NOT NULL DEFAULT 'user' COMMENT 'admin=مدير النظام, manager=مدير, user=مستخدم عادي',
        is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=نشط, 0=معطّل',
        failed_attempts INT NOT NULL DEFAULT 0 COMMENT 'عدد محاولات تسجيل الدخول الفاشلة',
        last_failed_attempt DATETIME DEFAULT NULL COMMENT 'آخر محاولة فاشلة',
        last_login DATETIME DEFAULT NULL COMMENT 'آخر تسجيل دخول ناجح',
        login_count INT NOT NULL DEFAULT 0 COMMENT 'عدد مرات تسجيل الدخول',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التحقق من وجود مستخدمين (لا نضيف المستخدم الافتراضي إذا وُجد مستخدم واحد على الأقل)
    $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    if ($user_count == 0) {
        // إنشاء حساب المدير الافتراضي
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['admin', $admin_password, 'مدير النظام', 'admin@example.com', 'admin']);
    }
}

/**
 * الإصدار 2.0.0 - تم دمجه في upgrade_1_0_0
 * جدول system_settings يُنشأ الآن في أول تحديث
 * هذه الدالة تبقى فارغة للتوافق مع التثبيتات القديمة
 */
function upgrade_2_0_0($pdo) {
    // جدول system_settings يُنشأ الآن في upgrade_1_0_0
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * الإصدار 2.1.0 - نظام تتبع دفعات الشراء (FIFO)
 * كل عملية شراء تُنشئ دفعة بسعرها الخاص
 * عند البيع يتم استهلاك الدفعات بالترتيب (أول ما دخل أول ما خرج)
 */
function upgrade_2_1_0($pdo) {
    // جدول دفعات الشراء - كل عملية شراء تُنشئ دفعة
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        purchase_id INT DEFAULT NULL COMMENT 'رابط بسجل الشراء الأصلي',
        original_quantity DECIMAL(12,2) NOT NULL COMMENT 'الكمية الأصلية للدفعة',
        remaining_quantity DECIMAL(12,2) NOT NULL COMMENT 'الكمية المتبقية غير المباعة',
        purchase_price DECIMAL(12,2) NOT NULL COMMENT 'سعر شراء الوحدة في هذه الدفعة',
        purchase_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول تفاصيل استهلاك الدفعات في كل عملية بيع
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL COMMENT 'رابط بسجل البيع',
        batch_id INT NOT NULL COMMENT 'رابط بدفعة الشراء',
        quantity_taken DECIMAL(12,2) NOT NULL COMMENT 'الكمية المأخوذة من هذه الدفعة',
        purchase_price DECIMAL(12,2) NOT NULL COMMENT 'سعر شراء الوحدة من هذه الدفعة',
        cost_total DECIMAL(12,2) NOT NULL COMMENT 'تكلفة الكمية المأخوذة = quantity_taken × purchase_price',
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ترحيل بيانات المشتريات القديمة إلى دفعات
    // إنشاء دفعة لكل عملية شراء مسجلة سابقاً
    $oldPurchases = $pdo->query("SELECT p.id, p.product_id, p.quantity, p.purchase_price, p.purchase_date FROM purchases p ORDER BY p.purchase_date ASC, p.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($oldPurchases as $op) {
        // حساب الكمية المتبقية من هذه الدفعة
        // نجمع كل المبيعات على هذا المنتج التي حدثت بعد هذا الشراء
        $soldAfter = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) as total_sold FROM sales WHERE product_id = ? AND sale_date >= ? AND id <= (SELECT COALESCE(MAX(s2.id),0) FROM sales s2 WHERE s2.product_id = ?)");
        // طريقة أبسط: نحسب الكمية المتبقية بناءً على المخزون الحالي
        $remaining = $op['quantity']; // افتراضي
        $stmt->execute([$op['product_id'], $op['id'], $op['quantity'], $remaining, $op['purchase_price'], $op['purchase_date']]);
    }
    
    // إعادة حساب الكميات المتبقية بدقة بناءً على FIFO
    // جلب كل المنتجات
    $products = $pdo->query("SELECT id, quantity FROM products")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as $prod) {
        // جلب كل الدفعات لهذا المنتج مرتبة بالتاريخ
        $batches = $pdo->prepare("SELECT id, original_quantity FROM purchase_batches WHERE product_id = ? ORDER BY purchase_date ASC, id ASC");
        $batches->execute([$prod['id']]);
        $batches = $batches->fetchAll(PDO::FETCH_ASSOC);
        
        // جلب إجمالي المبيعات
        $totalSold = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) as total FROM sales WHERE product_id = ?");
        $totalSold->execute([$prod['id']]);
        $totalSold = floatval($totalSold->fetchColumn());
        
        // توزيع المبيعات على الدفعات FIFO
        $remaining_to_deduct = $totalSold;
        foreach ($batches as $batch) {
            $batch_orig = floatval($batch['original_quantity']);
            if ($remaining_to_deduct >= $batch_orig) {
                // الدفعة كلها استُهلكت
                $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = 0 WHERE id = ?")->execute([$batch['id']]);
                $remaining_to_deduct -= $batch_orig;
            } else {
                // الدفعة استُهلكت جزئياً
                $left = $batch_orig - $remaining_to_deduct;
                $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = ? WHERE id = ?")->execute([$left, $batch['id']]);
                $remaining_to_deduct = 0;
            }
        }
    }
}

/**
 * الإصدار 3.1.0 - نظام تصنيفات المنتجات
 * إنشاء جدول التصنيفات وإضافة حقل التصنيف في جدول المنتجات
 */
function upgrade_3_1_0($pdo) {
    // جدول التصنيفات
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        color VARCHAR(7) DEFAULT '#3b82f6' COMMENT 'لون التصنيف بالهيكس',
        sort_order INT NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
        is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=نشط, 0=معطّل',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التحقق من وجود عمود category_id في جدول المنتجات
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_id', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN category_id INT DEFAULT NULL COMMENT 'معرف التصنيف' AFTER unit");
        $pdo->exec("ALTER TABLE products ADD CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
    }

    // التحقق من وجود تصنيفات (إضافة تصنيفات افتراضية إذا فارغة)
    $cat_count = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($cat_count == 0) {
        $default_categories = [
            ['أغذية ومواد غذائية', 'منتجات غذائية متنوعة', '#059669', 1],
            ['مشروبات', 'مشروبات ساخنة وباردة', '#2563eb', 2],
            ['تنظيف ومنظفات', 'مواد التنظيف والصيانة', '#7c3aed', 3],
            ['حلويات ومخبوزات', 'حلويات ومخبوزات متنوعة', '#dc2626', 4],
            ['مواد التعبئة', 'أكياس وعبوات وتغليف', '#d97706', 5],
            ['أخرى', 'منتجات متنوعة أخرى', '#64748b', 99],
        ];
        $stmt = $pdo->prepare("INSERT INTO categories (name, description, color, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($default_categories as $cat) {
            $stmt->execute($cat);
        }
    }
}

/**
 * الإصدار 3.2.0 - إضافة إعداد العملة
 * تعيين د.م. (درهم مغربي) كعملة افتراضية
 */
function upgrade_3_2_0($pdo) {
    // التحقق من وجود الإعداد أولاً
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    $stmt->execute(['currency_symbol']);
    $exists = $stmt->fetchColumn();

    if (!$exists) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        $stmt->execute(['currency_symbol', 'د.م.', 'رمز العملة المستخدم في التطبيق']);
    }
}

/**
 * الإصدار 3.3.0 - نظام وحدات القياس المركزية
 * إنشاء جدول وحدات القياس مع وحدات افتراضية
 */
function upgrade_3_3_0($pdo) {
    // جدول وحدات القياس
    $pdo->exec("CREATE TABLE IF NOT EXISTS measurement_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE COMMENT 'اسم الوحدة (قطعة، كيلوغرام...)',
        abbreviation VARCHAR(20) NOT NULL COMMENT 'الرمز المختصر (قط، كلغ...)',
        unit_type ENUM('quantity', 'weight', 'volume', 'length', 'area', 'other') NOT NULL DEFAULT 'quantity' COMMENT 'نوع الوحدة',
        description VARCHAR(255) DEFAULT NULL COMMENT 'وصف إضافي',
        sort_order INT NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
        is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=نشط, 0=معطّل',
        conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1 COMMENT 'معامل التحويل للوحدة الأساسية',
        is_base_unit TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل هذه هي الوحدة الأساسية لهذا النوع؟',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التحقق من وجود وحدات (إضافة وحدات افتراضية إذا فارغة)
    $unit_count = $pdo->query("SELECT COUNT(*) FROM measurement_units")->fetchColumn();
    if ($unit_count == 0) {
        $default_units = [
            ['قطعة', 'قط', 'quantity', 'وحدة العد الأساسية', 1, 1, 1],
            ['كيلوغرام', 'كلغ', 'weight', 'وحدة قياس الوزن', 2, 1, 1],
            ['غرام', 'غ', 'weight', 'وحدة وزن صغيرة', 3, 0, 0.001],
            ['لتر', 'لتر', 'volume', 'وحدة قياس الحجم السائل', 4, 1, 1],
            ['مليلتر', 'مل', 'volume', 'وحدة حجم صغيرة', 5, 0, 0.001],
            ['متر', 'م', 'length', 'وحدة قياس الطول', 6, 1, 1],
            ['سنتيمتر', 'سم', 'length', 'وحدة طول صغيرة', 7, 0, 0.01],
            ['علبة', 'علبة', 'quantity', 'وحدة تغليف', 8, 0, 1],
            ['كرتونة', 'كرت', 'quantity', 'وحدة تغليف كبيرة', 9, 0, 1],
            ['كيس / شيكارة', 'كيس', 'quantity', 'وحدة تعبئة بالجملة', 10, 0, 1],
            ['زجاجة', 'زج', 'volume', 'وحدة تعبئة زجاجية', 11, 0, 1],
            ['طرد', 'طرد', 'quantity', 'وحدة بيع بالطرد', 12, 0, 1],
            ['رول / بالة', 'بالة', 'quantity', 'وحدة بالة أو رول', 13, 0, 1],
            ['أخرى', '-', 'other', 'وحدات أخرى', 99, 0, 1],
        ];
        $stmt = $pdo->prepare("INSERT INTO measurement_units (name, abbreviation, unit_type, description, sort_order, is_base_unit, conversion_to_base) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($default_units as $unit) {
            $stmt->execute($unit);
        }
    }
}

/**
 * الإصدار 3.4.0 - نظام المصاريف العامة
 * إنشاء جدول المصاريف مع تصنيفات افتراضية
 */
function upgrade_3_4_0($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(100) NOT NULL COMMENT 'تصنيف المصروف',
        amount DECIMAL(12,2) NOT NULL COMMENT 'المبلغ',
        description TEXT DEFAULT NULL COMMENT 'وصف المصروف',
        expense_date DATE NOT NULL COMMENT 'تاريخ المصروف',
        created_by INT DEFAULT NULL COMMENT 'المستخدم الذي أضاف المصروف',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * الإصدار 3.5.0 - نظام الوحدات الأساسية ومعاملات التحويل
 * إضافة أعمدة conversion_to_base و is_base_unit لجدول measurement_units
 * هذا يسمح بتحويل أي وحدة إلى الوحدة الأساسية لنوعها
 */
function upgrade_3_5_0($pdo) {
    // التحقق من وجود الأعمدة قبل إضافتها
    $cols = $pdo->query("SHOW COLUMNS FROM measurement_units")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('conversion_to_base', $cols)) {
        $pdo->exec("ALTER TABLE measurement_units ADD COLUMN conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1 COMMENT 'معامل التحويل للوحدة الأساسية (كم وحدة أساسية = 1 من هذه الوحدة)' AFTER is_active");
    }

    if (!in_array('is_base_unit', $cols)) {
        $pdo->exec("ALTER TABLE measurement_units ADD COLUMN is_base_unit TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل هذه هي الوحدة الأساسية لهذا النوع؟' AFTER conversion_to_base");
    }

    // تحديث الوحدات الموجودة بقيم صحيحة
    // كيلوغرام → الوحدة الأساسية للوزن
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 1, conversion_to_base = 1 WHERE name = 'كيلوغرام'");
    // غرام → 1 غرام = 0.001 كيلوغرام
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 0, conversion_to_base = 0.001 WHERE name = 'غرام'");
    // لتر → الوحدة الأساسية للحجم
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 1, conversion_to_base = 1 WHERE name = 'لتر'");
    // مليلتر → 1 مل = 0.001 لتر
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 0, conversion_to_base = 0.001 WHERE name = 'مليلتر'");
    // قطعة → الوحدة الأساسية للكمية
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 1, conversion_to_base = 1 WHERE name = 'قطعة'");
    // متر → الوحدة الأساسية للطول
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 1, conversion_to_base = 1 WHERE name = 'متر'");
    // سنتيمتر → 1 سم = 0.01 متر
    $pdo->exec("UPDATE measurement_units SET is_base_unit = 0, conversion_to_base = 0.01 WHERE name = 'سنتيمتر'");

    // التأكد من وجود وحدة أساسية واحدة فقط لكل نوع
    // بالنسبة لأنواع بدون وحدة أساسية محددة، ضع أول وحدة كوحدة أساسية
    // (نستخدم SELECT مضمن في جدول مؤقت لتجنب خطأ MySQL 1093)
    $pdo->exec("UPDATE measurement_units mu1
        JOIN (
            SELECT t.unit_type, MIN(t.id) as min_id
            FROM measurement_units t
            WHERE t.unit_type NOT IN (
                SELECT sub.unit_type FROM (
                    SELECT DISTINCT unit_type FROM measurement_units WHERE is_base_unit = 1
                ) sub
            )
            GROUP BY t.unit_type
        ) mu2 ON mu1.id = mu2.min_id
        SET mu1.is_base_unit = 1, mu1.conversion_to_base = 1");
}

// ===== دوال مساعدة =====

/**
 * جلب رقم الإصدار الحالي من قاعدة البيانات
 */
/**
 * الإصدار 3.6.0 - نظام وحدات الشراء المستقلة
 * إضافة purchase_unit للمنتجات وجدول purchase_additional_units
 */
function upgrade_3_6_0($pdo) {
    // إضافة عمود purchase_unit لجدول المنتجات
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_unit', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN purchase_unit VARCHAR(50) DEFAULT NULL COMMENT 'وحدة القياس الأساسية للشراء' AFTER unit");
    }
    if (!in_array('purchase_unit_factor', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN purchase_unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1 COMMENT '1 وحدة شراء = X وحدة بيع أساسية' AFTER purchase_unit");
    }

    // إنشاء جدول وحدات الشراء الإضافية
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1 COMMENT '1 وحدة إضافية شراء = X وحدة شراء أساسية',
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * الإصدار 3.7.0 - إصلاح جداول مفقودة
 * التأكد من وجود جميع الجداول الأساسية (CREATE TABLE IF NOT EXISTS)
 * يعالج مشكلة عدم وجود جداول بسبب تحديثات سابقة لم تُطبَّق بشكل صحيح
 */
function upgrade_3_7_0($pdo) {
    // إعادة إنشاء جميع الجداول الأساسية التي قد تكون مفقودة
    
    // جدول وحدات القياس الإضافية (مهم جداً - يسبب تعطل النظام إذا كان مفقوداً)
    $pdo->exec("CREATE TABLE IF NOT EXISTS additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول وحدات الشراء الإضافية
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول سجل تغيير الأسعار
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        old_purchase_price DECIMAL(12,2) DEFAULT NULL,
        new_purchase_price DECIMAL(12,2) DEFAULT NULL,
        old_selling_price DECIMAL(12,2) DEFAULT NULL,
        new_selling_price DECIMAL(12,2) DEFAULT NULL,
        change_reason VARCHAR(255) DEFAULT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول التصنيفات
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        color VARCHAR(7) DEFAULT '#3b82f6',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول وحدات القياس المركزية
    $pdo->exec("CREATE TABLE IF NOT EXISTS measurement_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        abbreviation VARCHAR(20) NOT NULL,
        unit_type ENUM('quantity', 'weight', 'volume', 'length', 'area', 'other') NOT NULL DEFAULT 'quantity',
        description VARCHAR(255) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1,
        is_base_unit TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول دفعات الشراء
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        purchase_id INT DEFAULT NULL,
        original_quantity DECIMAL(12,2) NOT NULL,
        remaining_quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL,
        purchase_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول تفاصيل استهلاك الدفعات
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        batch_id INT NOT NULL,
        quantity_taken DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL,
        cost_total DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المصاريف
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(100) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description TEXT DEFAULT NULL,
        expense_date DATE NOT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المستخدمين
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) DEFAULT NULL,
        role ENUM('admin', 'manager', 'user') NOT NULL DEFAULT 'user',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        failed_attempts INT NOT NULL DEFAULT 0,
        last_failed_attempt DATETIME DEFAULT NULL,
        last_login DATETIME DEFAULT NULL,
        login_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التحقق من وجود الأعمدة الحديثة في جدول المنتجات
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('category_id', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN category_id INT DEFAULT NULL COMMENT 'معرف التصنيف' AFTER unit");
        }
        if (!in_array('purchase_unit', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN purchase_unit VARCHAR(50) DEFAULT NULL COMMENT 'وحدة القياس الأساسية للشراء' AFTER unit");
        }
        if (!in_array('purchase_unit_factor', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN purchase_unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1 COMMENT '1 وحدة شراء = X وحدة بيع أساسية' AFTER purchase_unit");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء المتعلقة بالأعمدة
    }

    // التحقق من وجود الأعمدة الحديثة في جدول المبيعات
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('purchase_price_at_sale', $cols)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN purchase_price_at_sale DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER selling_price");
        }
        if (!in_array('cost_total', $cols)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN cost_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total");
        }
        if (!in_array('profit', $cols)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN profit DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_total");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }

    // التحقق من وجود الأعمدة الحديثة في جدول وحدات القياس
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM measurement_units")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('conversion_to_base', $cols)) {
            $pdo->exec("ALTER TABLE measurement_units ADD COLUMN conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1 AFTER is_active");
        }
        if (!in_array('is_base_unit', $cols)) {
            $pdo->exec("ALTER TABLE measurement_units ADD COLUMN is_base_unit TINYINT(1) NOT NULL DEFAULT 0 AFTER conversion_to_base");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }
}

/**
 * الإصدار 3.8.0 - إصلاح شامل
 * إعادة إنشاء جميع الجداول والأعمدة المفقودة
 * يعالج مشاكل الترقية السابقة حيث لم تُنشأ بعض الجداول
 */
function upgrade_3_8_0($pdo) {
    // 1. إنشاء جميع الجداول الأساسية إذا لم تكن موجودة
    
    // جدول المنتجات
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit VARCHAR(50) NOT NULL,
        quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول وحدات القياس الإضافية (مهم جداً)
    $pdo->exec("CREATE TABLE IF NOT EXISTS additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول وحدات الشراء الإضافية
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المشتريات
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        purchase_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المبيعات
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        selling_price DECIMAL(12,2) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        sale_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول جرد المخزون
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_counts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        actual_quantity DECIMAL(12,2) NOT NULL,
        system_quantity DECIMAL(12,2) NOT NULL,
        difference DECIMAL(12,2) NOT NULL,
        count_date DATE NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول سجل تغيير الأسعار
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        old_purchase_price DECIMAL(12,2) DEFAULT NULL,
        new_purchase_price DECIMAL(12,2) DEFAULT NULL,
        old_selling_price DECIMAL(12,2) DEFAULT NULL,
        new_selling_price DECIMAL(12,2) DEFAULT NULL,
        change_reason VARCHAR(255) DEFAULT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول التصنيفات
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) DEFAULT NULL,
        color VARCHAR(7) DEFAULT '#3b82f6',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول وحدات القياس المركزية
    $pdo->exec("CREATE TABLE IF NOT EXISTS measurement_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        abbreviation VARCHAR(20) NOT NULL,
        unit_type ENUM('quantity', 'weight', 'volume', 'length', 'area', 'other') NOT NULL DEFAULT 'quantity',
        description VARCHAR(255) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1,
        is_base_unit TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول دفعات الشراء
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        purchase_id INT DEFAULT NULL,
        original_quantity DECIMAL(12,2) NOT NULL,
        remaining_quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL,
        purchase_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول تفاصيل استهلاك الدفعات
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        batch_id INT NOT NULL,
        quantity_taken DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL,
        cost_total DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المصاريف
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(100) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description TEXT DEFAULT NULL,
        expense_date DATE NOT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول المستخدمين
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) DEFAULT NULL,
        role ENUM('admin', 'manager', 'user') NOT NULL DEFAULT 'user',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        failed_attempts INT NOT NULL DEFAULT 0,
        last_failed_attempt DATETIME DEFAULT NULL,
        last_login DATETIME DEFAULT NULL,
        login_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. التحقق من وجود جميع الأعمدة الحديثة في جدول المنتجات
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('category_id', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN category_id INT DEFAULT NULL COMMENT 'معرف التصنيف' AFTER unit");
        }
        if (!in_array('purchase_unit', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN purchase_unit VARCHAR(50) DEFAULT NULL COMMENT 'وحدة القياس الأساسية للشراء' AFTER unit");
        }
        if (!in_array('purchase_unit_factor', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN purchase_unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1 COMMENT '1 وحدة شراء = X وحدة بيع أساسية' AFTER purchase_unit");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء المتعلقة بالأعمدة
    }

    // 3. التحقق من وجود الأعمدة الحديثة في جدول المبيعات
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('purchase_price_at_sale', $cols)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN purchase_price_at_sale DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER selling_price");
        }
        if (!in_array('cost_total', $cols)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN cost_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total");
        }
        if (!in_array('profit', $cols)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN profit DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_total");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }

    // 4. التحقق من وجود الأعمدة الحديثة في جدول وحدات القياس
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM measurement_units")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('conversion_to_base', $cols)) {
            $pdo->exec("ALTER TABLE measurement_units ADD COLUMN conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1 AFTER is_active");
        }
        if (!in_array('is_base_unit', $cols)) {
            $pdo->exec("ALTER TABLE measurement_units ADD COLUMN is_base_unit TINYINT(1) NOT NULL DEFAULT 0 AFTER conversion_to_base");
        }
    } catch (Exception $e) {
        // تجاهل الأخطاء
    }
}

function getCurrentDBVersion($pdo) {
    // التحقق من صحة الاتصال بقاعدة البيانات
    if (!$pdo) {
        return '0.0.0';
    }
    try {
        // التحقق من وجود جدول system_settings
        $result = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        if ($result->rowCount() == 0) {
            return '0.0.0'; // لا يوجد جدول إعدادات = تثبيت قديم
        }

        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute(['app_version']);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : '0.0.0';
    } catch (\Throwable $e) {
        return '0.0.0';
    }
}

/**
 * تحديث رقم الإصدار في قاعدة البيانات
 */
function updateDBVersion($pdo, $version) {
    // التأكد من وجود جدول system_settings قبل الكتابة فيه
    $result = $pdo->query("SHOW TABLES LIKE 'system_settings'");
    if ($result->rowCount() == 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute(['app_version', $version, 'رقم إصدار التطبيق الحالي']);
}

/**
 * مقارنة أرقام الإصدارات
 * يُرجع: -1 إذا كان v1 < v2، 0 إذا تساوى، 1 إذا كان v1 > v2
 */
function compareVersions($v1, $v2) {
    $parts1 = array_map('intval', explode('.', $v1));
    $parts2 = array_map('intval', explode('.', $v2));
    
    for ($i = 0; $i < max(count($parts1), count($parts2)); $i++) {
        $p1 = $parts1[$i] ?? 0;
        $p2 = $parts2[$i] ?? 0;
        if ($p1 < $p2) return -1;
        if ($p1 > $p2) return 1;
    }
    return 0;
}

/**
 * جلب قائمة التحديثات المعلقة
 */
function getPendingUpdates($currentVersion) {
    global $UPDATES;
    $pending = [];
    foreach ($UPDATES as $update) {
        if (compareVersions($update['version'], $currentVersion) > 0) {
            $pending[] = $update;
        }
    }
    return $pending;
}
?>
               