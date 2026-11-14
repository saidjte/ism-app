<?php
/**
 * Database Upgrade Functions
 *
 * This file contains all database upgrade functions (db_upgrade_*)
 * extracted from upgrade.php as part of the modular restructuring (v4.9.105).
 *
 * Each function corresponds to a specific version and handles the necessary
 * schema changes (CREATE TABLE, ALTER TABLE, indexes, data migrations, etc.)
 * to upgrade the database from one version to the next.
 *
 * These functions are called by the upgrade system in version order.
 * They must remain in the same order as they were in the original upgrade.php.
 */

// ====================================================================
//                دوال تحديث قاعدة البيانات (v1.0.0 → v4.9.0)
// ====================================================================

function db_upgrade_1_0_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL, description VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0, selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit VARCHAR(50) NOT NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 0, min_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1, selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchases (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL, total DECIMAL(12,2) NOT NULL, purchase_date DATE NOT NULL, notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, quantity DECIMAL(12,2) NOT NULL,
        selling_price DECIMAL(12,2) NOT NULL, total DECIMAL(12,2) NOT NULL, sale_date DATE NOT NULL, notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS inventory_counts (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, actual_quantity DECIMAL(12,2) NOT NULL,
        system_quantity DECIMAL(12,2) NOT NULL, difference DECIMAL(12,2) NOT NULL, count_date DATE NOT NULL, notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_1_1_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS price_history (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL,
        old_purchase_price DECIMAL(12,2) DEFAULT NULL, new_purchase_price DECIMAL(12,2) DEFAULT NULL,
        old_selling_price DECIMAL(12,2) DEFAULT NULL, new_selling_price DECIMAL(12,2) DEFAULT NULL,
        change_reason VARCHAR(255) DEFAULT NULL, changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_1_2_0($pdo) {
    ensureCol($pdo, 'sales', 'purchase_price_at_sale', "purchase_price_at_sale DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER selling_price");
    ensureCol($pdo, 'sales', 'cost_total', "cost_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total");
    ensureCol($pdo, 'sales', 'profit', "profit DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_total");
    try { $pdo->exec("UPDATE sales s JOIN products p ON s.product_id = p.id SET s.purchase_price_at_sale = p.purchase_price, s.cost_total = s.quantity * p.purchase_price, s.profit = s.total - (s.quantity * p.purchase_price) WHERE s.purchase_price_at_sale = 0"); } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::1_2_0_updateSales'); }
}

function db_upgrade_2_0_0($pdo) {}

function db_upgrade_2_1_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, purchase_id INT DEFAULT NULL,
        original_quantity DECIMAL(12,2) NOT NULL, remaining_quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL, purchase_date DATE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, batch_id INT NOT NULL,
        quantity_taken DECIMAL(12,2) NOT NULL, purchase_price DECIMAL(12,2) NOT NULL, cost_total DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        $oldP = $pdo->query("SELECT id, product_id, quantity, purchase_price, purchase_date FROM purchases ORDER BY purchase_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($oldP as $op) $st->execute([$op['product_id'], $op['id'], $op['quantity'], $op['quantity'], $op['purchase_price'], $op['purchase_date']]);
        $prods = $pdo->query("SELECT id, quantity FROM products")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prods as $prod) {
            $batches = $pdo->prepare("SELECT id, original_quantity FROM purchase_batches WHERE product_id = ? ORDER BY purchase_date ASC, id ASC");
            $batches->execute([$prod['id']]); $batches = $batches->fetchAll(PDO::FETCH_ASSOC);
            $ts = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE product_id = ?");
            $ts->execute([$prod['id']]); $rem = floatval($ts->fetchColumn());
            foreach ($batches as $b) { $orig = floatval($b['original_quantity']); if ($rem >= $orig) { $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = 0 WHERE id = ?")->execute([$b['id']]); $rem -= $orig; } else { $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = ? WHERE id = ?")->execute([$orig - $rem, $b['id']]); $rem = 0; } }
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::2_1_0_batchMigration'); }
}

function db_upgrade_3_0_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL, email VARCHAR(150) DEFAULT NULL,
        role ENUM('admin','manager','user') NOT NULL DEFAULT 'user', is_active TINYINT(1) NOT NULL DEFAULT 1,
        failed_attempts INT NOT NULL DEFAULT 0, last_failed_attempt DATETIME DEFAULT NULL,
        last_login DATETIME DEFAULT NULL, login_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        if ($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, password_changed_at) VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'مدير النظام', 'admin@example.com', 'admin']);
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::3_0_0_insertAdmin'); }
}

function db_upgrade_3_1_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) DEFAULT NULL,
        color VARCHAR(7) DEFAULT '#3b82f6', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureCol($pdo, 'products', 'category_id', "category_id INT DEFAULT NULL AFTER unit");
    try {
        if ($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() == 0) {
            foreach ([['أغذية ومواد غذائية','منتجات غذائية','#059669',1],['مشروبات','مشروبات ساخنة وباردة','#2563eb',2],['تنظيف ومنظفات','مواد التنظيف والصيانة','#7c3aed',3],['حلويات ومخبوزات','حلويات ومخبوزات','#dc2626',4],['مواد التعبئة','أكياس وعبوات','#d97706',5],['أخرى','منتجات أخرى','#64748b',99]] as $c)
                $pdo->prepare("INSERT INTO categories (name, description, color, sort_order) VALUES (?, ?, ?, ?)")->execute($c);
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::3_1_0_insertCategories'); }
}

function db_upgrade_3_2_0($pdo) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $s->execute(['currency_symbol']);
        if (!$s->fetchColumn()) $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")->execute(['currency_symbol', 'د.م.', 'رمز العملة']);
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::3_2_0_insertCurrency'); }
}

function db_upgrade_3_3_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS measurement_units (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, abbreviation VARCHAR(20) NOT NULL,
        unit_type ENUM('quantity','weight','volume','length','area','other') NOT NULL DEFAULT 'quantity',
        description VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
        conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1, is_base_unit TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        if ($pdo->query("SELECT COUNT(*) FROM measurement_units")->fetchColumn() == 0) {
            foreach ([['قطعة','قط','quantity','وحدة العد',1,1,1],['كيلوغرام','كلغ','weight','وحدة الوزن',2,1,1],['غرام','غ','weight','وزن صغير',3,0,0.001],['لتر','لتر','volume','وحدة الحجم',4,1,1],['مليلتر','مل','volume','حجم صغير',5,0,0.001],['متر','م','length','وحدة الطول',6,1,1],['سنتيمتر','سم','length','طول صغير',7,0,0.01],['علبة','علبة','quantity','وحدة تغليف',8,0,1],['كرتونة','كرت','quantity','تغليف كبيرة',9,0,1],['كيس','كيس','quantity','تعبئة بالجملة',10,0,1],['زجاجة','زج','volume','تعبئة زجاجية',11,0,1],['طرد','طرد','quantity','بيع بالطرد',12,0,1],['رول','بالة','quantity','بالة أو رول',13,0,1],['أخرى','-','other','وحدات أخرى',99,0,1]] as $u)
                $pdo->prepare("INSERT INTO measurement_units (name, abbreviation, unit_type, description, sort_order, is_base_unit, conversion_to_base) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($u);
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::3_3_0_insertMeasurementUnits'); }
}

function db_upgrade_3_4_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY, category VARCHAR(100) NOT NULL, amount DECIMAL(12,2) NOT NULL,
        description TEXT DEFAULT NULL, expense_date DATE NOT NULL, created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_3_5_0($pdo) {
    ensureCol($pdo, 'measurement_units', 'conversion_to_base', "conversion_to_base DECIMAL(12,6) NOT NULL DEFAULT 1 AFTER is_active");
    ensureCol($pdo, 'measurement_units', 'is_base_unit', "is_base_unit TINYINT(1) NOT NULL DEFAULT 0 AFTER conversion_to_base");
    foreach (['كيلوغرام'=>'1,1','غرام'=>'0,0.001','لتر'=>'1,1','مليلتر'=>'0,0.001','قطعة'=>'1,1','متر'=>'1,1','سنتيمتر'=>'0,0.01'] as $k => $v) {
        try { $pdo->exec("UPDATE measurement_units SET is_base_unit = " . explode(',', $v)[0] . ", conversion_to_base = " . explode(',', $v)[1] . " WHERE name = '$k'"); } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::3_5_0_updateMeasurementUnits'); }
    }
}

function db_upgrade_3_6_0($pdo) {
    ensureCol($pdo, 'products', 'purchase_unit', "purchase_unit VARCHAR(50) DEFAULT NULL AFTER unit");
    ensureCol($pdo, 'products', 'purchase_unit_factor', "purchase_unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1 AFTER purchase_unit");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_additional_units (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, unit_name VARCHAR(50) NOT NULL,
        conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_3_7_0($pdo) {
    foreach (['additional_units','purchase_additional_units','price_history','categories','measurement_units','purchase_batches','sale_batch_items','expenses','users'] as $t)
        ensureTable($pdo, "CREATE TABLE IF NOT EXISTS `$t` (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureCol($pdo, 'products', 'category_id', "category_id INT DEFAULT NULL");
    ensureCol($pdo, 'products', 'purchase_unit', "purchase_unit VARCHAR(50) DEFAULT NULL");
    ensureCol($pdo, 'products', 'purchase_unit_factor', "purchase_unit_factor DECIMAL(12,4) NOT NULL DEFAULT 1");
    ensureCol($pdo, 'sales', 'purchase_price_at_sale', "purchase_price_at_sale DECIMAL(12,2) NOT NULL DEFAULT 0");
    ensureCol($pdo, 'sales', 'cost_total', "cost_total DECIMAL(12,2) NOT NULL DEFAULT 0");
    ensureCol($pdo, 'sales', 'profit', "profit DECIMAL(12,2) NOT NULL DEFAULT 0");
}

function db_upgrade_3_8_0($pdo) {}

function db_upgrade_3_9_0($pdo) {
    ensureCol($pdo, 'products', 'has_expiry', "has_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER min_quantity");
    ensureCol($pdo, 'purchase_batches', 'expiry_date', "expiry_date DATE DEFAULT NULL AFTER purchase_date");
}

function db_upgrade_4_0_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT DEFAULT NULL, username VARCHAR(50) DEFAULT NULL,
        action VARCHAR(50) NOT NULL, description VARCHAR(500) DEFAULT NULL, details TEXT DEFAULT NULL,
        entity_type VARCHAR(50) DEFAULT NULL, entity_id INT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_action (action), INDEX idx_entity (entity_type, entity_id), INDEX idx_user (user_id), INDEX idx_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS user_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, selector VARCHAR(20) NOT NULL,
        token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_selector (selector), INDEX idx_expires (expires_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_4_1_0($pdo) {}
function db_upgrade_4_2_0($pdo) {}
function db_upgrade_4_3_0($pdo) {}

function db_upgrade_4_3_1($pdo) {
    ensureCol($pdo, 'users', 'must_change_password', "must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count > 0) {
            $users = $pdo->query("SELECT id, password FROM users WHERE must_change_password = 0")->fetchAll(PDO::FETCH_ASSOC);
            $st = $pdo->prepare("UPDATE users SET must_change_password = 1 WHERE id = ?");
            foreach ($users as $u) { if (password_verify('admin123', $u['password'])) $st->execute([$u['id']]); }
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::4_3_1_forcePasswordChange'); }
}

function db_upgrade_4_4_0($pdo) {}
function db_upgrade_4_5_0($pdo) {}

function db_upgrade_4_6_0($pdo) {
    foreach ([
        "ALTER TABLE sales ADD INDEX idx_sale_date (sale_date)",
        "ALTER TABLE sales ADD INDEX idx_product_date (product_id, sale_date)",
        "ALTER TABLE purchases ADD INDEX idx_purchase_date (purchase_date)",
        "ALTER TABLE purchases ADD INDEX idx_product_date (product_id, purchase_date)",
        "ALTER TABLE expenses ADD INDEX idx_expense_date (expense_date)",
        "ALTER TABLE expenses ADD INDEX idx_category_date (category, expense_date)",
        "ALTER TABLE purchase_batches ADD INDEX idx_batch_date (purchase_date)",
        "ALTER TABLE purchase_batches ADD INDEX idx_batch_product_date (product_id, purchase_date)",
        "ALTER TABLE inventory_counts ADD INDEX idx_count_date (count_date)",
    ] as $sql) ensureIndex($pdo, $sql);
}

function db_upgrade_4_7_0($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL, message TEXT NOT NULL, data JSON DEFAULT NULL,
        title_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة العنوان',
        message_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة الرسالة',
        message_params JSON DEFAULT NULL COMMENT 'معاملات الترجمة ({param} → value)',
        is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user_read (user_id, is_read), INDEX idx_notif_user_created (user_id, created_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $s->execute(['notifications_enabled']);
        if (!$s->fetchColumn()) $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")->execute(['notifications_enabled', '1', 'تفعيل/تعطيل نظام الإشعارات']);
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::4_7_0_insertNotifSetting'); }
}

function db_upgrade_4_8_0($pdo) {
    // التغييرات في الملفات والكود فقط — لا تغييرات في قاعدة البيانات
}

function db_upgrade_4_9_0($pdo) {
    // جدول التخفيضات
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_remises (
        id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT NOT NULL,
        type ENUM('material', 'financial') NOT NULL,
        product_id INT NULL, quantity DECIMAL(12,2) NULL, unit_price DECIMAL(12,2) NULL,
        amount DECIMAL(12,2) NULL, description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // أعمدة الضرائب
    ensureCol($pdo, 'purchases', 'tax_rate', "tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'نسبة الضريبة' AFTER total");
    ensureCol($pdo, 'purchases', 'tax_amount', "tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مبلغ الضريبة' AFTER tax_rate");
    ensureCol($pdo, 'purchases', 'tax_name', "tax_name VARCHAR(100) DEFAULT NULL COMMENT 'اسم الضريبة' AFTER tax_amount");
}

function db_upgrade_4_9_1($pdo) {
    // لا تغييرات في قاعدة البيانات — التحديثات في الملفات فقط
}

function db_upgrade_4_9_2($pdo) {
    // لا تغييرات في قاعدة البيانات — التحديثات في الملفات فقط
    // عمليات DDL كانت في includes/db-schema.php (أُوقفت في v4.9.76 ونُقلت إلى هنا)
}

function db_upgrade_4_9_3($pdo) {
    // إضافة عمود password_changed_at لتتبع آخر تغيير لكلمة المرور
    ensureCol($pdo, 'users', 'password_changed_at',
        "password_changed_at DATETIME DEFAULT NULL COMMENT 'تاريخ آخر تغيير لكلمة المرور' AFTER must_change_password");
    try {
        // تعبئة تاريخ الإنشاء للمستخدمين الحاليين
        $pdo->exec("UPDATE users SET password_changed_at = COALESCE(created_at, NOW()) WHERE password_changed_at IS NULL");
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::4_9_3_updatePasswordChangedAt'); }
}

function db_upgrade_4_9_4($pdo) {
    // v4.9.4: إصلاح انتهاء صلاحية كلمة المرور + تفعيل نظام اللغات + إصلاح صفحة التحديث
    // لا تغييرات إضافية في قاعدة البيانات — password_changed_at أُضيف في v4.9.3
    // هذا الإصدار يضمّن إصلاحات الكود التي كانت في BUG_FIXES (fix_i18n_inactive)
    // والتحديثات التالية:
    // 1. auth.php: إضافة checkPasswordExpiry() + checkPasswordExpiryForUser() + enforcePasswordChange()
    // 2. bootstrap.php: تحميل i18n.php
    // 3. system-settings.php: force_password_change_interval_days كعدد صحيح
    // 4. change-password.php + add-user.php + edit-user.php + install.php: password_changed_at = NOW()
}

function db_upgrade_4_9_5($pdo) {
    // v4.9.6: تحسين شامل لطباعة ملصقات الأرفف
    // لا تغييرات في قاعدة البيانات — التحديثات في products/print-labels.php فقط
    // 1. دعم رمز QR + تنسيقات باركود متعددة (CODE128, EAN-13, CODE39, UPC-A)
    // 2. ختم التاريخ على الملصقات
    // 3. حفظ واستعادة إعدادات القالب (localStorage)
    // 4. تصفية حسب حالة المخزون
    // 5. كشف التكرار ودمج الكميات
    // 6. تحسين جودة الكود (استبدال GLOBALS)
}

function db_upgrade_4_9_6($pdo) {
    // v4.9.6: لا تغييرات في قاعدة البيانات
    // جميع التحسينات في products/print-labels.php فقط
}

function db_upgrade_4_9_7($pdo) {
    // v4.9.7: لا تغييرات في قاعدة البيانات
    // تقرير الأرباح المفصّل الجديد في reports/profit-report.php
    // صافي الربح = مبيعات - تكلفة البضاعة (COGS) - مصاريف - ضرائب
}

function db_upgrade_4_9_70($pdo) {
    // v4.9.70: اتفاقية ترقيم جديدة
    // الجزء الأخير من رقم الإصدار يتكون من رقمين أو ثلاثة (70, 71, 72...)
    // لا تغييرات في قاعدة البيانات — فقط تحديث رقم الإصدار المخزّن
    // compareVersions('4.9.70', '4.9.7') = 1 (أحدث) ← intval(70) > intval(7)
}

function db_upgrade_4_9_71($pdo) {
    // v4.9.71: تفعيل إعدادات POS + تنسيق التاريخ حسب date_format
    // إعدادات POS كانت مُخزّنة لكن غير مُستخدمة — الآن caisse.php يقرأها
    // إعداد date_format كان مُخزّناً لكن التواريخ كانت بصيغة ثابتة — الآن formatDate() تستخدمه
    // لا تغييرات في قاعدة البيانات — فقط تحديث رقم الإصدار المخزّن
    // الملفات المُحدّثة: config/database.php, caisse.php, includes/header.php,
    //                  assets/js/utils.js, index.php, reports/period-comparison.php
}

function db_upgrade_4_9_72($pdo) {
    // v4.9.72: واجهة نقطة البيع المحسّنة (POS)
    // صفحة pos.php جديدة — كاشير متكامل مع:
    // - بحث سريع بالباركود أو اسم المنتج مع قائمة منسدلة حية
    // - كتالوج منتجات بالتصنيفات (بطاقات ملونة)
    // - سلة تسوق تفاعلية (+/-/حذف/إفراغ)
    // - قسم دفع مع حساب الباقي ومبالغ سريعة
    // - إيصال بيع قابل للطباعة
    // - خصم مالي + تعليق طلب
    // - اختصارات لوحة المفاتيح (F2/F4/F8)
    // - سمة داكنة/فاتحة + أصوات + إكمال تلقائي
    // API جديد: api/pos-process-sale.php — معالجة مبيعات POS بـ AJAX
    // الملفات الجديدة: pos.php, api/pos-process-sale.php
    // الملفات المُحدّثة: includes/header.php (إضافة رابط POS),
    //                  includes/languages/ar.php, fr.php, en.php (ترجمة nav_pos)
    // لا تغييرات في قاعدة البيانات
}

function db_upgrade_4_9_73($pdo) {
    // v4.9.73: إصلاح أخطاء حرجة في واجهة نقطة البيع (POS)
    // 1. إصلاح: رمز CSRF لا يتجدد بعد الدفع — المبيعات المتتالية كانت تفشل (403)
    //    - POS_CSRF حُوّل من const إلى let ليسمح بتحديثه
    //    - بعد كل عملية دفع ناجحة، يُحدَّث POS_CSRF بالرمز الجديد من الخادم
    // 2. إصلاح: رسالة "السلة فارغة" تختفي نهائياً بعد أول إضافة
    //    - innerHTML كان يدمّر عنصر cartEmpty من DOM
    //    - أصبحت رسالة السلة الفارغة تُولَّد ديناميكياً في renderCart()
    // لا تغييرات في قاعدة البيانات
}

function db_upgrade_4_9_74($pdo) {
    // v4.9.74: إصلاح عداد سلة التسوق لا يتحدث عند الإفراغ
    // المشكلة: في renderCart()، كان تحديث cartCount بعد return
    // فعندما تكون السلة فارغة، الدالة تعود مبكراً دون تحديث العداد
    // الحل: نقل سطر تحديث cartCount إلى بداية renderCart() قبل الشرط
    // يؤثر على: clearCart()، holdCart()، newSale()
    // لا تغييرات في قاعدة البيانات — إصلاح في JavaScript فقط (pos.php)
}

function db_upgrade_4_9_75($pdo) {
    // v4.9.75: توحيد كود اتصال قاعدة البيانات + إزالة متغيرات global من ماسح الباركود
    //
    // == الجزء 1: توحيد bootstrap.php ==
    // المشكلة: 33 ملف كانت تستخدم require_once database.php مباشرة
    // بدلاً من المرور عبر نقطة الدخول الموحدة bootstrap.php
    // هذا يؤدي إلى: تكرار كود، عدم تحميل أنظمة أساسية (i18n, ErrorHandler, Cache)
    // الحل: استبدال database.php بـ bootstrap.php في كل الملفات
    // + إزالة الاستيرادات المكررة (auth.php, csrf.php, notifications.php)
    // + الاحتفاظ بالاستيرادات غير الموجودة في bootstrap (permissions.php, etc.)
    // الملفات المستثناة (تبقى كما هي): install.php, upgrade.php, uninstall.php
    //
    // == الجزء 2: إزالة متغيرات global من ماسح الباركود ==
    // المشكلة: lastActiveSaleRow, lastActiveProductRow, lastActiveRemiseCounter,
    // lastActiveSearchField كانت متغيرات global تُستخدم لنقل الهدف بين
    // زر المسح وcallback المسح — نمط هش وعرضة للأخطاء
    // الحل: استخدام data-scan-target على الزر + تمرير الهدف كمعامل ثاني
    // لـ window.onBarcodeScanned(code, target)
    // الملفات المتأثرة: includes/barcode-scanner.php, sales/add-sale.php,
    // purchases/add-purchase.php, products/print-labels.php
    //
    // لا تغييرات في قاعدة البيانات — إعادة هيكلة كود فقط
}

function db_upgrade_4_9_76($pdo) {
    // v4.9.76: نقل كل DDL من db-schema.php إلى upgrade.php
    // المشكلة: db-schema.php كان يُحمَّل في كل طلب صفحة (من config/database.php)
    // ويُنفِّذ CREATE TABLE IF NOT EXISTS + SHOW COLUMNS + ALTER TABLE في كل زيارة
    // هذا يسبب عبئاً على قاعدة البيانات بدون داعٍ
    // الحل: نقل كل DDL إلى هنا + إيقاف initDBSchema() التلقائي

    // --- جدول الصندوق اليومي (caisse) ---
    // كان يُنشأ فقط من db-schema.php أثناء الطلبات العادية
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS caisse (
        id INT AUTO_INCREMENT PRIMARY KEY,
        record_date DATE NOT NULL,
        start_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        current_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        actual_amount DECIMAL(12,2) NULL DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_date (record_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- جدول حركات الصندوق (caisse_transactions) ---
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS caisse_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('deposit', 'withdrawal') NOT NULL COMMENT 'deposit=إيداع, withdrawal=سحب',
        amount DECIMAL(12,2) NOT NULL COMMENT 'المبلغ',
        description VARCHAR(255) DEFAULT NULL COMMENT 'وصف الحركة',
        transaction_date DATE NOT NULL COMMENT 'تاريخ الحركة',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- جدول تقييد محاولات الدخول حسب IP (ip_rate_limits) ---
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS ip_rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL COMMENT 'عنوان IP (يدعم IPv6)',
        attempts INT NOT NULL DEFAULT 0 COMMENT 'عدد المحاولات الفاشلة',
        last_attempt DATETIME DEFAULT NULL COMMENT 'آخر محاولة',
        blocked_until INT DEFAULT NULL COMMENT 'طابع زمني لحظة انتهاء الحظر',
        username VARCHAR(50) DEFAULT NULL COMMENT 'اسم المستخدم المراد تسجيل الدخول به',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_ip (ip),
        INDEX idx_blocked (blocked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- تحديث جدول inventory_counts بإضافة الفهارس المفقودة ---
    // الفهرس idx_product_id
    try {
        $indexes = $pdo->query("SHOW INDEX FROM inventory_counts WHERE Key_name = 'idx_product_id'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($indexes)) {
            $pdo->exec("ALTER TABLE inventory_counts ADD INDEX idx_product_id (product_id)");
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::4_9_76_idxProductId'); }

    // الفهرس idx_count_date — موجود في db_upgrade_4_6_0 لكن نتحقق
    try {
        $indexes = $pdo->query("SHOW INDEX FROM inventory_counts WHERE Key_name = 'idx_count_date'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($indexes)) {
            $pdo->exec("ALTER TABLE inventory_counts ADD INDEX idx_count_date (count_date)");
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::4_9_76_idxCountDate'); }

    // --- التأكد من وجود أعمدة الضرائب (كانت في db-schema.php) ---
    // مُضافة سابقاً في db_upgrade_4_9_0 لكن نتحقق
    ensureCol($pdo, 'purchases', 'tax_rate',
        "tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'نسبة الضريبة' AFTER total");
    ensureCol($pdo, 'purchases', 'tax_amount',
        "tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مبلغ الضريبة' AFTER tax_rate");
    ensureCol($pdo, 'purchases', 'tax_name',
        "tax_name VARCHAR(100) DEFAULT NULL COMMENT 'اسم الضريبة' AFTER tax_amount");

    // --- التأكد من وجود password_changed_at (كان في db-schema.php) ---
    // مُضاف سابقاً في db_upgrade_4_9_3 لكن نتحقق
    ensureCol($pdo, 'users', 'password_changed_at',
        "password_changed_at DATETIME DEFAULT NULL COMMENT 'تاريخ آخر تغيير لكلمة المرور' AFTER must_change_password");

    // --- تعبئة password_changed_at للمستخدمين الحاليين ---
    try {
        $pdo->exec("UPDATE users SET password_changed_at = COALESCE(created_at, NOW()) WHERE password_changed_at IS NULL");
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'upgrade-db::4_9_76_updatePasswordChangedAt'); }
}

function db_upgrade_4_9_77($pdo) {
    // v4.9.77: إصلاح كشف إصلاح fix_notif_include مع bootstrap.php
    // المشكلة: بعد توحيد bootstrap.php في v4.9.75، الملفات لم تعد تحتوي
    // على إدراج notifications.php مباشرة — لأن bootstrap.php يُحمّلها تلقائياً
    // لكن runFullCheck() كان يبحث عن السطر الحرفي "notifications.php" في كل ملف
    // فيُبلغ أن الإصلاح "معلّق" رغم أنه مُصلح بالفعل عبر bootstrap.php
    // الحل: تحديث منطق الكشف ليعتبر الملف مُصلحاً إذا يستخدم bootstrap.php
    // + تحديث دالة fix_notif_include لعدم إضافة إدراج مكرر
    // لا تغييرات في قاعدة البيانات — إصلاح منطق كشف فقط
}

function db_upgrade_4_9_78($pdo) {
    // v4.9.78: تفعيل النسخ الاحتياطي التلقائي الحقيقي
    //
    // == المشكلة 1: اسم إعداد خاطئ ==
    // checkAutoBackup() كان يقرأ auto_backup_interval
    // لكن صفحة الإعدادات تحفظه كـ auto_backup_frequency
    // فالنسخ التلقائي لم يكن يعمل أبداً
    // الحل: تغيير auto_backup_interval إلى auto_backup_frequency في backup.php
    //
    // == المشكلة 2: لا نقطة نهاية cron ==
    // checkAutoBackup() يعمل فقط عند زيارة المدير للوحة التحكم
    // إذا لم يزر أحد الموقع، لا تُنشأ نسخة
    // الحل: إنشاء cron/auto-backup.php يمكن جدولته
    //
    // == المشكلة 3: backup_retention_count غير مُستخدم ==
    // cleanOldBackups() كان يستخدم MAX_BACKUP_FILES الثابت
    // الحل: قراءة backup_retention_count من الإعدادات
    //
    // لا تغييرات في قاعدة البيانات — تحسينات كود فقط
}

function db_upgrade_4_9_88($pdo) {
    // v4.9.88: إصلاح فشل تسجيل المبيعات في نقطة البيع
    // لا تغييرات في قاعدة البيانات — الإصلاحات في api/pos-process-sale.php فقط
    // (استعلامات التحقق خارج try-catch + فحص وجود purchase_batches + catch Throwable)
}

function db_upgrade_4_9_93($pdo) {
    // v4.9.93: إصلاح شامل لنقطة البيع
    // التأكد من وجود كل الجداول والأعمدة المطلوبة لعملية البيع

    // التأكد من وجود جدول sales
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, quantity DECIMAL(12,2) NOT NULL,
        selling_price DECIMAL(12,2) NOT NULL, total DECIMAL(12,2) NOT NULL, sale_date DATE NOT NULL, notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التأكد من وجود الأعمدة المضافة لاحقاً لجدول sales
    ensureCol($pdo, 'sales', 'purchase_price_at_sale', "purchase_price_at_sale DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER selling_price");
    ensureCol($pdo, 'sales', 'cost_total', "cost_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total");
    ensureCol($pdo, 'sales', 'profit', "profit DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_total");

    // التأكد من وجود جدول products
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, code VARCHAR(100) DEFAULT NULL,
        category_id INT DEFAULT NULL, purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0, quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit VARCHAR(50) DEFAULT 'piece', min_quantity DECIMAL(12,2) DEFAULT 5,
        description TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التأكد من وجود جدول system_settings
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL,
        description VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التأكد من وجود جدول notifications (مخطط كامل — يشمل i18n + priority + group_key)
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL, message TEXT NOT NULL, data JSON DEFAULT NULL,
        title_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة العنوان',
        message_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة الرسالة',
        message_params JSON DEFAULT NULL COMMENT 'معاملات الترجمة',
        priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'مستوى الأولوية',
        group_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح التجميع',
        group_count INT NOT NULL DEFAULT 1 COMMENT 'عدد الإشعارات المجمّعة',
        is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user_read (user_id, is_read), INDEX idx_notif_user_created (user_id, created_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التأكد من وجود جدول purchase_batches
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, purchase_id INT DEFAULT NULL,
        original_quantity DECIMAL(12,2) NOT NULL, remaining_quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL, purchase_date DATE NOT NULL, expiry_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التأكد من وجود جدول sale_batch_items
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, batch_id INT NOT NULL,
        quantity_taken DECIMAL(12,2) NOT NULL, purchase_price DECIMAL(12,2) NOT NULL,
        cost_total DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // التأكد من وجود جدول purchase_remises
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_remises (
        id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT NOT NULL,
        type ENUM('material', 'financial') NOT NULL,
        product_id INT NULL, quantity DECIMAL(12,2) NULL, unit_price DECIMAL(12,2) NULL,
        amount DECIMAL(12,2) NULL, description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_4_9_96($pdo) {
    // v4.9.96: العمل بدون إنترنت — جميع المكتبات الخارجية أصبحت محلية
    // لا تغييرات في قاعدة البيانات — التعديلات في ملفات PHP و CSS و JS فقط
    // الملفات المضافة: assets/vendor/*.js + assets/fonts/cairo-*.ttf + assets/fonts/cairo.css
    // الملفات المعدلة: includes/header.php, includes/barcode-scanner.php, products/print-labels.php, products/price-list.php
}

function db_upgrade_4_9_97($pdo) {
    // v4.9.97: نقطة البيع — الكمية قابلة للتعديل يدوياً + دعم الكميات الجزئية
    // لا تغييرات في قاعدة البيانات — التعديلات في pos.php فقط (JavaScript + CSS)
    // التغييرات:
    //   - تحويل <span> الكمية إلى <input type="number" step="0.01">
    //   - إضافة دالة handleQtyInput() لمعالجة الإدخال اليدوي
    //   - CSS جديد لحقل إدخال الكمية (.pos-cart-item-qty-input)
    //   - عرض الكميات الجزئية بشكل صحيح في تفاصيل السلة
}

function db_upgrade_4_9_98($pdo) {
    // v4.9.98: إصلاح جرس التنبيهات (CSRF + JSON API)
    // لا تغييرات في قاعدة البيانات — التعديلات في JS + PHP فقط
    // التغييرات:
    //   - إصلاح حرج: رمز CSRF غير متوفر في header.php → إضافة حقل مخفي شامل
    //   - استبدال requireCSRF() بـ AppCsrf::verify() في API الإشعارات (إرجاع JSON بدلاً من die())
    //   - تحسين getCsrfToken() في notifications.js (بحث في حقل مخفي + meta tag)
    //   - إصلاح NotifSystem.init() لا يُستدعى لأن DOMContentLoaded سبق وانتهى
    //   - دعم بيانات JSON في API الإشعارات (App.post يرسل JSON لكن $_POST فارغ)
    //   - إصلاح أسماء المعاملات: JS يرسل id لكن API يتوقع notification_id
}

function db_upgrade_4_9_95($pdo) {
    // v4.9.95: دعم الطابعة الحرارية
    // الإعدادات تُحفظ في system_settings عبر saveSetting() عند الحفظ من صفحة الإعدادات
    // لا تغييرات في هيكل قاعدة البيانات
}

function db_upgrade_4_9_99($pdo) {
    // v4.9.99: إصلاح نظام التنبيهات
    // لا تغييرات في قاعدة البيانات — التعديلات في JS + CSS + PHP فقط
    // التغييرات:
    //   - إضافة زر إغلاق (X) في رأس القائمة المنسدلة للتنبيهات
    //   - إضافة خلفية شفافة (overlay) على الهاتف لإغلاق القائمة عند النقر خارجها
    //   - القائمة المنسدلة على الهاتف أصبحت تنبثق من الأسفل (bottom sheet) بدلاً من تغطية الشاشة بالكامل
    //   - استعادة زر "تفعيل تنبيهات المتصفح" (كان مخفياً بشرط push_notifications_enabled)
    //   - إعادة كتابة صفحة التنبيهات (notifications/index.php) باستخدام أصناف CSS الصحيحة
    //   - إصلاح تعارض أسماء الأصناف بين القائمة المنسدلة وصفحة التنبيهات الكاملة
}

function db_upgrade_4_9_104($pdo) {
    // v4.9.104: نشر ذري حقيقي (True Atomic Deploy)
    // لا تغييرات في قاعدة البيانات — الميزات في:
    //   - upgrade.php: إعادة كتابة atomicDeployZip() بتقنية Directory Swap
    //   - upgrade.php: إضافة enableMaintenanceMode() + disableMaintenanceMode()
    //   - upgrade.php: إضافة preflightCheck() + hardlinkTree()
    //   - upgrade.php: إعادة كتابة rollbackFileDeploy() بتراجع ذري (directory swap)
    //   - حماية مجلدات staging و upgrade_old بـ .htaccess
}

function db_upgrade_4_9_103($pdo) {
    // v4.9.103: نظام النشر الذري (Atomic Deploy)
    // لا تغييرات في قاعدة البيانات — الميزات في:
    //   - upgrade.php: إضافة atomicDeployZip() + rollbackFileDeploy() + واجهة رفع ZIP
    //   - حماية من Path Traversal و Zip Slip في ملفات ZIP
}

function db_upgrade_4_9_102($pdo) {
    // v4.9.102: إصلاح تعارض دالة cleanOldBackups + نظام التراجع Rollback
    // لا تغييرات في قاعدة البيانات — الإصلاحات في:
    //   - upgrade.php: إعادة تسمية cleanOldBackups() إلى cleanOldUpgradeBackups()
    //   - upgrade.php: إضافة نظام تراجع تلقائي (createUpgradeBackup + rollbackFromBackup + cleanOldUpgradeBackups)
}

function db_upgrade_4_9_101($pdo) {
    // v4.9.101: إصلاحات أمنية — حقن SQL + انتحال IP + رؤوس CSP
    // لا تغييرات في قاعدة البيانات — الإصلاحات في:
    //   - config/backup.php: استبدال addslashes() بـ Prepared Statements + دالة validateDatabaseName()
    //   - includes/auth.php: نظام الوكلاء الموثوقين (Trusted Proxies) + CIDR + session.use_strict_mode
    //   - .htaccess: رؤوس CSP + Referrer-Policy + Permissions-Policy
}

function db_upgrade_4_9_100($pdo) {
    // v4.9.100: نقطة البيع — شريط علوي عائم (Glassmorphism)
    // لا تغييرات في قاعدة البيانات — التعديلات في pos.php فقط (HTML + CSS)
    // التغييرات:
    //   - تحويل الشريط العلوي إلى شريط عائم بتأثير زجاجي (backdrop-filter: blur)
    //   - إضافة غلاف pos-topbar-float-wrapper مع position: sticky
    //   - حواف مدورة (border-radius: 16px) مع مسافة من حواف الصفحة
    //   - خلفية شبه شفافة + ظلال متدرجة
    //   - عنوان الصفحة بخلفية متدرجة خفيفة
    //   - مربع البحث بتأثير توهج عند التركيز
    //   - زر المسح بتدرج بنفسجي
    //   - أزرار موحدة (pos-icon-btn) مع تأثير رفع عند التحويم
    //   - قائمة نتائج البحث بتأثير زجاجي
    //   - دعم كامل للسمة الداكنة
    //   - تحسين تنسيق الهاتف
}

function db_upgrade_4_9_94($pdo) {
    // v4.9.94: إصلاح حرج: hasPermission() غير معرّفة في API
    // لا تغييرات في قاعدة البيانات — الإصلاح في includes/bootstrap.php (إضافة تحميل permissions.php)
    // و api/pos-process-sale.php (إضافة حماية function_exists)
}

function db_upgrade_4_9_92($pdo) {
    // v4.9.92: إصلاح فشل تسجيل المبيعات — ضمان JSON + تحسين معالجة الأخطاء
    // لا تغييرات في قاعدة البيانات — الإصلاحات في api/pos-process-sale.php و pos.php فقط
}

function db_upgrade_4_9_91($pdo) {
    // v4.9.91: إصلاح صفحة التحديثات — إضافة زر إصلاح الجداول المفقودة
    // لا تغييرات في قاعدة البيانات — التحسينات في upgrade.php فقط
    // (إضافة دالة repairTables + زر إصلاح الجداول + إصلاح is_all_ok)
}

function db_upgrade_4_9_90($pdo) {
    // v4.9.90: إصلاح حرج: COLLATE=utf8mb4 غير صالح يمنع إنشاء جدول purchase_remises
    // إعادة إنشاء الجدول بالـ COLLATE الصحيح إذا لم يكن موجوداً
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_remises (
        id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT NOT NULL,
        type ENUM('material', 'financial') NOT NULL,
        product_id INT NULL, quantity DECIMAL(12,2) NULL, unit_price DECIMAL(12,2) NULL,
        amount DECIMAL(12,2) NULL, description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // كذلك نضمن وجود purchase_batches و sale_batch_items
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, purchase_id INT DEFAULT NULL,
        original_quantity DECIMAL(12,2) NOT NULL, remaining_quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL, purchase_date DATE NOT NULL, expiry_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, batch_id INT NOT NULL,
        quantity_taken DECIMAL(12,2) NOT NULL, purchase_price DECIMAL(12,2) NOT NULL, cost_total DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function db_upgrade_4_9_89($pdo) {
    // v4.9.89: إصلاح خطأ جدول purchase_remises غير موجود + حماية صفحات البيع والشراء

    // إنشاء جدول purchase_batches إذا لم يكن موجوداً
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_batches (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, purchase_id INT DEFAULT NULL,
        original_quantity DECIMAL(12,2) NOT NULL, remaining_quantity DECIMAL(12,2) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL, purchase_date DATE NOT NULL, expiry_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // إنشاء جدول sale_batch_items إذا لم يكن موجوداً
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS sale_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, batch_id INT NOT NULL,
        quantity_taken DECIMAL(12,2) NOT NULL, purchase_price DECIMAL(12,2) NOT NULL, cost_total DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES purchase_batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // إنشاء جدول purchase_remises إذا لم يكن موجوداً
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_remises (
        id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT NOT NULL,
        type ENUM('material', 'financial') NOT NULL,
        product_id INT NULL, quantity DECIMAL(12,2) NULL, unit_price DECIMAL(12,2) NULL,
        amount DECIMAL(12,2) NULL, description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // إضافة عمود expiry_date لجدول purchase_batches إذا لم يكن موجوداً
    ensureCol($pdo, 'purchase_batches', 'expiry_date', "expiry_date DATE DEFAULT NULL AFTER purchase_date");
}

function db_upgrade_4_9_87($pdo) {
    // v4.9.87: إصلاح خطأ قاتل: جدول users غير موجود + تحسين مقاومة نظام المصادقة
    // لا تغييرات في قاعدة البيانات — الإصلاحات في uninstall.php + auth.php + Database.php فقط
    // (فحص وجود الجدول + مسار بديل للمصادقة + catch Throwable + دالة tableExists)
}

function db_upgrade_4_9_86($pdo) {
    // v4.9.86: إعادة تصميم سلة التسوق في نقطة البيع للهاتف
    // لا تغييرات في قاعدة البيانات — الإصلاحات في ملف pos.php فقط
    // (شريط إجمالي عائم + تمرر اللوحة كوحدة واحدة + تصميم مكدس للعناصر + استغلال كامل للمساحة)
}

function db_upgrade_4_9_85($pdo) {
    // v4.9.85: إعادة تصميم بطاقات المنتجات في نقطة البيع
    // لا تغييرات في قاعدة البيانات — الإصلاحات في ملف pos.php فقط
    // (شريط لون التصنيف علوي + فاصل بين المحتوى والسعر + تخطيط احترافي + إصلاح تداخل شريط التنقل)
}

function db_upgrade_4_9_84($pdo) {
    // v4.9.84: إعادة تصميم توافق نقطة البيع مع الهاتف
    // لا تغييرات في قاعدة البيانات — الإصلاحات في ملف pos.php فقط
    // (شريط تبويب سفلي + عرض كامل للمنتجات/السلة + تحسين عناصر السلة + أزرار أكبر)
}

function db_upgrade_4_9_83($pdo) {
    // v4.9.83: إصلاح خطأ دالة db_upgrade_4_9_82 المفقودة
    // لا تغييرات في قاعدة البيانات — إصلاح في upgrade.php فقط
    // (إضافة الدالة الفارغة db_upgrade_4_9_82 التي كانت مفقودة)
}

function db_upgrade_4_9_82($pdo) {
    // v4.9.82: مراجعة شاملة لتوافق أحجام الشاشات
    // لا تغييرات في قاعدة البيانات — الإصلاحات في ملفات CSS فقط
    // (إصلاح min-width القسري + 100dvh + POS 768px + زر الطباعة + القائمة المنسدلة + التقارير + inline styles)
}

function db_upgrade_4_9_81($pdo) {
    // v4.9.81: إصلاح تكرار الإصلاحات في صفحة التحديث
    // لا تغييرات في قاعدة البيانات — الإصلاح في منطق upgrade.php فقط
    // (تخطي الإصلاحات المُطبّقة بالفعل بدل تشغيلها في كل مرة)
}

function db_upgrade_4_9_80($pdo) {
    // v4.9.80: إصلاح ماسح الباركود في نقطة البيع
    // لا تغييرات في قاعدة البيانات — الإصلاح في ملف pos.php فقط
    // (تضمين barcode-scanner.php + callback المسح + مستمع ماسح الأجهزة)
}

function db_upgrade_4_9_79($pdo) {
    // v4.9.79: إشعارات المتصفح (Browser Push Notifications)
    //
    // == جدول اشتراكات Push ==
    // يخزن بيانات اشتراكات Web Push لكل مستخدم
    // يدعم VAPID الكامل مستقبلاً (endpoint + p256dh + auth)
    //
    // == إعداد push_notifications_enabled ==
    // يُفعّل/يُعطّل إشعارات المتصفح من صفحة الإعدادات

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL DEFAULT '',
        auth_key VARCHAR(255) NOT NULL DEFAULT '',
        browser VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_push_user (user_id),
        INDEX idx_push_endpoint (endpoint(255)),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // إعداد تفعيل إشعارات المتصفح — معطّل افتراضياً (يجب تفعيله من الإعدادات)
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
    $stmt->execute(['push_notifications_enabled', '0', 'تفعيل إشعارات المتصفح (Browser Push)']);
}

function db_upgrade_4_9_111($pdo) {
    // v4.9.111: إصلاحات أمنية عاجلة (PATCH — تحديث أمني عاجل)
    // 1. حماية deployer.php: فرض المصادقة دائماً + تقييد المحاولات + كشف القيمة الضعيفة
    // 2. إعدادات Secure Cookie: HttpOnly + SameSite + Strict Mode + Secure flag
    // 3. إخفاء أخطاء SQL: PDOException + RuntimeException في الإنتاج
    // 4. تعطيل display_errors افتراضياً + نظام APP_DEBUG
    // لا تغييرات في هيكل قاعدة البيانات — إصلاحات أمنية في الكود فقط
}

function db_upgrade_4_9_110($pdo) {
    // v4.9.110: إصلاح جرس التنبيهات — إزالة overlay بالكامل
    // المشكلة: overlay كان يغطي الصفحة ويحجب القائمة المنسدلة بالكامل
    // الحل: إزالة overlay — القائمة تُغلق بالنقر خارجها أو زر الإغلاق أو Escape
    // لا تغييرات في قاعدة البيانات — إصلاح CSS + JS + HTML فقط
}

function db_upgrade_4_9_112($pdo) {
    // v4.9.112: تعزيز الأمان — CSP ديناميكي + HSTS + تشفير .env
    // لا تغييرات في قاعدة البيانات — تحسينات أمنية في الرؤوس والتشفير فقط
    // الملفات الجديدة: SecurityHeaders.php, EnvEncryptor.php
    // التعديلات: bootstrap.php, header.php, config/database.php, .htaccess, .env
}

function db_upgrade_4_10_0($pdo) {
    // v4.10.0: تحسين البنية — Service Layer + Redis Cache + Router
    // لا تغييرات في قاعدة البيانات — طبقات برمجية جديدة فقط
    // الملفات الجديدة:
    //   - includes/Services/ProductService.php
    //   - includes/Services/SaleService.php
    //   - includes/Services/PurchaseService.php
    //   - includes/Services/ServiceFactory.php
    //   - includes/Cache/CacheInterface.php
    //   - includes/Cache/RedisCache.php
    //   - includes/Router/Router.php
    //   - includes/Router/Middleware/AuthMiddleware.php
    //   - includes/Router/Middleware/CsrfMiddleware.php
    //   - includes/routes.php
}

function db_upgrade_4_9_109($pdo) {
    // v4.9.109: تم تجاوزه — دمج مع v4.9.110
}

function db_upgrade_4_9_108($pdo) {
    // v4.9.108: إصلاح جرس التنبيهات — الخلفية (overlay) كانت تغطي القائمة المنسدلة
    // السبب: overlay z-index:1999 > dropdown z-index:1500 على سطح المكتب
    // الحل: تخفيض z-index الخلفية إلى 1499 + تفعيلها على الهاتف فقط
    // لا تغييرات في قاعدة البيانات — إصلاح CSS + JS فقط
}

function db_upgrade_4_9_107($pdo) {
    // v4.9.107: الناشر الذري المستقل (deployer.php)
    // يحل معضلة الدجاجة والبيضة: النشر الذري في upgrade.php كان يحتاج نشر الملفات أولاً
    // لكن deployer.php مستقل تماماً ومتاح دائماً بدون أي تحديث مسبق
    // لا تغييرات في قاعدة البيانات — الملفات الجديدة: deployer.php + تحديث upgrade-ui.php
}

function db_upgrade_4_9_106($pdo) {
    // v4.9.106: إصلاح حرج — إعادة كتابة نظام النشر الذري
    // المشكلة: Directory Swap يحتاج rename(baseDir, baseDir/upgrade_old) — مستحيل!
    // لا يمكن إعادة تسمية مجلد إلى مسار بداخله
    // الحل: استبدال بتقنية Write-Then-Rename — كل ملف يُستبدل ذرياً بـ rename()
    // لا تغييرات في قاعدة البيانات — إعادة كتابة upgrade-deploy.php فقط
}

function db_upgrade_4_10_1($pdo) {
    // v4.10.1: إصلاح حرج — خطأ الاتصال بالخادم بسبب CSP
    // المشكلة: سياسة CSP الصارمة كانت تحظر كل السكربتات المضمّنة (inline scripts)
    // بدون سمة nonce، مما يمنع window.BASE_URL من التعيين ويفسد كل استدعاءات AJAX
    // الحل: إضافة nonce لـ 48 سكربت مضمّن في 44 ملف + وضع CSP Report-Only
    // + قراءة APP_DEBUG من .env + تحميل RedisCache/Services في bootstrap
    // لا تغييرات في قاعدة البيانات — إصلاحات في ملفات PHP + JS + CSS فقط
}

function db_upgrade_4_10_2($pdo) {
    // v4.10.2: إصلاح حرج — دالة db_upgrade_4_10_1 المفقودة
    // المشكلة: دالة db_upgrade_4_10_1 لم تكن معرّفة في upgrade-db.php
    // مما كان يوقف الترقية بالكامل عند الوصول لهذا الإصدار
    // مع فشل التراجع التلقائي أيضاً
    // الحل: إضافة الدالة المفقودة + تحسين مرونة نظام الترقية
    // (تخطي الدوال المفقودة بدلاً من الفشل الكامل)
    // لا تغييرات في قاعدة البيانات — إصلاح في نظام الترقية فقط
}

function db_upgrade_4_11_0($pdo) {
    // v4.11.0: ميزة جديدة — تطبيق "أسعاري" PWA مستقل
    // لا تغييرات في قاعدة البيانات — ملفات جديدة فقط:
    // - price-check.php (صفحة البحث عن الأسعار)
    // - price-check-sw.js (Service Worker)
    // - price-check-manifest.json (PWA manifest)
    // - api/price-check-data.php (API بيانات المنتجات)
    // - assets/img/price-check-icon-*.png (أيقونات PWA)
}

function db_upgrade_4_15_6($pdo) {
    // v4.15.6: إصلاح حرج — products.forEach is not a function + HTTP 500 في نقطة البيع
    // لا تغييرات في قاعدة البيانات — إصلاحات في JavaScript و PHP فقط:
    // - pos.php: تحليل JSON متين (res.text()+JSON.parse) + Array.isArray() + عرض رسائل الخطأ
    // - api/search_products.php: ob_start/ob_clean لمنع تحذيرات PHP من إفساد JSON
    // - includes/ErrorHandler.php: إضافة products+data في استجابة JSON للـ API
}

function db_upgrade_4_15_7($pdo) {
    // v4.15.7: إصلاح شامل لجميع نقاط API
    // لا تغييرات في قاعدة البيانات — إصلاحات في PHP و JavaScript فقط:
    // - api/price-check-data.php: ob_start + products/data في استجابة الخطأ + تسجيل الأخطاء
    // - price-check.php: استخراج آمن للمصفوفات من استجابة API (Array.isArray)
    // - api/pos-process-sale.php: إضافة products+data في posJsonError
    // - api/notifications.php: ob_start + notifications فارغة في الخطأ
    // - api/get_batches.php: ob_start + __DIR__ + batches في الخطأ + requireLogin
    // - api/get_units.php: ob_start + __DIR__
    // - api/get_purchase_units.php: ob_start + __DIR__
    // - api/dismiss-expiry-popup.php: ob_start + try-catch
    // - api/push-subscribe.php: ob_start + تنظيف output
    // - api/send-whatsapp-alert.php: ob_start + try-catch + مصادقة
    // - api/test-ultramsg.php: ob_start + try-catch + مصادقة
    // - api/export.php: ob_start + __DIR__
}

function db_upgrade_4_15_8($pdo) {
    // v4.15.8: تحسين واجهة نقطة البيع (سلة التسوق + بطاقات المنتجات)
    // لا تغييرات في قاعدة البيانات — تحسينات CSS/JS فقط
}

function db_upgrade_4_15_9($pdo) {
    // v4.15.9: إصلاح زر "تطبيق الخصم" كبير جداً على الهاتف
    // لا تغييرات في قاعدة البيانات — إصلاح CSS فقط
}

function db_upgrade_4_15_10($pdo) {
    // v4.15.10: تحسين أداء التخزين المؤقت في لوحة التحكم والرأس
    // لا تغييرات في قاعدة البيانات — تحسينات PHP فقط (Cache::getInstance())
}

function db_upgrade_4_15_11($pdo) {
    // v4.15.11: إصلاح مهلة الجلسة المشفرة + تخزين مؤقت SHOW COLUMNS
    // لا تغييرات في قاعدة البيانات — إصلاح منطق PHP فقط:
    // - includes/auth.php: قراءة session_timeout_minutes من الإعدادات بدلاً من 1800 مشفرة
    // - api/search_products.php: تخزين مؤقت SHOW COLUMNS (24 ساعة)
    // - api/pos-process-sale.php: تخزين مؤقت SHOW COLUMNS (24 ساعة)
}

function db_upgrade_4_9_105($pdo) {
    // v4.9.105: إعادة هيكلة نظام التحديث — تقسيم upgrade.php إلى وحدات
    // لا تغييرات في قاعدة البيانات — التغييرات هيكلية فقط
}

// ====================================================================
//         دوال تحديث قاعدة البيانات (v4.15.12 → v4.16.1)
//         تمت الإضافة لإصلاح عدم تطابق الإصدارات
// ====================================================================

function db_upgrade_4_15_12($pdo) {
    // v4.15.12: إصلاح صفحة أسعاري PWA
    // لا تغييرات في قاعدة البيانات — التحديثات في الملفات فقط:
    // - price-check.php: إخفاء رسالة التثبيت عند تثبيت التطبيق + كشف standalone
    // - price-check-sw.js: تحسين التخزين المسبق + Cache First للثوابت + Network First للبيانات
    // - price-check-manifest.json: إعادة تضمين الملف (كان مفقوداً في v4.15.11)
}

function db_upgrade_4_16_0($pdo) {
    // v4.16.0: ميزة جديدة — اختبارات وحدية شاملة للمنطق التجاري
    // لا تغييرات في قاعدة البيانات — التحديثات في الملفات فقط:
    // - tests/ProductServiceTest.php: 47 اختبار (التحقق، CRUD، الأكواد، الدفعات، البحث، تحويل المخزون، الجرد)
    // - tests/SaleServiceTest.php: 48 اختبار (FIFO/FEFO، إنشاء المبيعات، عكس المبيعات، حساب الأرباح)
    // - tests/PurchaseServiceTest.php: 47 اختبار (الإنشاء، الضرائب، التخفيضات، التحديث، الحذف، السيناريوهات المتكاملة)
    // - tests/TestCase.php: إضافة assertFloatEqual() helper
}

function db_upgrade_4_16_1($pdo) {
    // v4.16.1: إصلاح كشف عدم تطابق الإصدارات في كلا الاتجاهين
    // لا تغييرات في قاعدة البيانات — التحديثات في الملفات فقط:
    // - deployer.php: كشف عدم تطابق الإصدارات (ملفات أحدث من DB أو العكس) + رسالة مناسبة + منع الترقية العكسية
    // - includes/upgrades/upgrade-constants.php: إضافة مدخلات مفقودة في $DB_UPDATES لإصلاح الفجوة بين 4.15.6 و4.16.1
}

function db_upgrade_4_19_0($pdo) {
    // v4.19.0: تحسين استعلامات البحث وفهرسة قاعدة البيانات

    // 1. إضافة فهرس FULLTEXT على أعمدة البحث الرئيسية (name, code)
    // هذا يسمح باستخدام MATCH...AGAINST بدلاً من LIKE '%term%'
    // مما يحسن أداء البحث بشكل كبير على الجداول الكبيرة
    $fulltextIndexes = $pdo->query("SHOW INDEX FROM products WHERE Index_type = 'FULLTEXT'")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($fulltextIndexes)) {
        try {
            // فهرس FULLTEXT على الأعمدة الرئيسية للبحث
            $pdo->exec("CREATE FULLTEXT INDEX idx_ft_products_search ON products(name, code)");
        } catch (Exception $e) {
            // إذا فشل إنشاء الفهرس (مثلاً عمود غير موجود)، نتجاوز الصمت
            error_log("v4.19.0: فشل إنشاء فهرس FULLTEXT على products(name,code): " . $e->getMessage());
        }
    }

    // 2. إضافة فهرس FULLTEXT على الباركود إذا كان العمود موجوداً
    // نتحقق أولاً من وجود العمود
    $columns = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c) => strtolower($c['Field']), $columns);

    if (in_array('barcode', $colNames)) {
        // حذف فهرس FULLTEXT القديم وإعادة إنشائه مع الباركود
        try {
            $pdo->exec("ALTER TABLE products DROP INDEX idx_ft_products_search");
            $pdo->exec("CREATE FULLTEXT INDEX idx_ft_products_search ON products(name, code, barcode)");
        } catch (Exception $e) {
            // إذا فشل، نحاول إنشاء فهرس منفصل للباركود
            try {
                $pdo->exec("CREATE FULLTEXT INDEX idx_ft_products_barcode ON products(barcode)");
            } catch (Exception $e2) {
                error_log("v4.19.0: فشل إنشاء فهرس FULLTEXT على barcode: " . $e2->getMessage());
            }
        }

        // فهرس B-tree على الباركود (للبحث الدقيق والمسح)
        try {
            $pdo->exec("CREATE INDEX idx_products_barcode ON products(barcode)");
        } catch (Exception $e) {
            error_log("v4.19.0: فهرس barcode موجود مسبقاً أو فشل: " . $e->getMessage());
        }
    }

    // 3. فهرس B-tree على category_id (للتصفية حسب التصنيف)
    try {
        $pdo->exec("CREATE INDEX idx_products_category ON products(category_id)");
    } catch (Exception $e) {
        error_log("v4.19.0: فهرس category_id موجود مسبقاً أو فشل: " . $e->getMessage());
    }

    // 4. فهرس B-tree على has_expiry (لتقارير الانتهاء)
    if (in_array('has_expiry', $colNames)) {
        try {
            $pdo->exec("CREATE INDEX idx_products_expiry ON products(has_expiry)");
        } catch (Exception $e) {
            error_log("v4.19.0: فهرس has_expiry موجود مسبقاً أو فشل: " . $e->getMessage());
        }
    }

    // 5. فهرس مركب على (quantity, min_quantity) لتصفية حالة المخزون
    try {
        $pdo->exec("CREATE INDEX idx_products_stock_status ON products(quantity, min_quantity)");
    } catch (Exception $e) {
        error_log("v4.19.0: فهرس stock_status موجود مسبقاً أو فشل: " . $e->getMessage());
    }
}

function db_upgrade_4_25_0($pdo) {
    // v4.25.0: تحسين حسابات الأرباح — فهارس مركبة + عرض مُجسَّد (Materialized View)
    //
    // التحسينات:
    //   1. إضافة فهارس مركبة على أعمدة التصفية والتجميع المستخدمة في استعلامات الأرباح
    //   2. إنشاء جدول mv_daily_profits كعرض مُجسَّد للإحصائيات اليومية
    //   3. تحسين أداء GROUP BY و SUM عبر فهارس تغطية (Covering Indexes)
    //
    // الفهارس المركبة الجديدة:
    //   - idx_sales_date_profit: (sale_date, product_id, total, cost_total, profit) — فهرس تغطي كامل لاستعلام أرباح المبيعات
    //   - idx_purchases_date_tax: (purchase_date, tax_amount) — فهرس لاستعلام الضرائب
    //   - idx_expenses_date_amount: (expense_date, amount) — فهرس لاستعلام المصاريف
    //   - idx_batches_product_remaining: (product_id, remaining_quantity, purchase_price) — فهرس لقيمة المخزون
    //
    // العرض المُجسَّد (mv_daily_profits):
    //   يخزّن إحصائيات الأرباح اليومية مسبقاً لتسريع استعلامات الفترات الطويلة
    //   يُحدَّث عبر cron أو عند تسجيل مبيعات جديدة (SaleService::refreshMaterializedView)

    // === 1. فهارس مركبة على جدول sales ===
    // فهرس تغطي كامل لاستعلام calculateProfits — يغطي sale_date + product_id + الأعمدة المحسوبة
    ensureIndex($pdo, "ALTER TABLE sales ADD INDEX idx_sales_date_profit (sale_date, product_id, total, cost_total, profit)");

    // === 2. فهرس مركب على جدول purchases ===
    ensureIndex($pdo, "ALTER TABLE purchases ADD INDEX idx_purchases_date_tax (purchase_date, tax_amount)");

    // === 3. فهرس مركب على جدول expenses ===
    ensureIndex($pdo, "ALTER TABLE expenses ADD INDEX idx_expenses_date_amount (expense_date, amount)");

    // === 4. فهرس مركب على جدول purchase_batches ===
    ensureIndex($pdo, "ALTER TABLE purchase_batches ADD INDEX idx_batches_product_remaining (product_id, remaining_quantity, purchase_price)");

    // === 5. فهرس مركب على جدول sale_batch_items ===
    ensureIndex($pdo, "ALTER TABLE sale_batch_items ADD INDEX idx_sbi_sale_batch (sale_id, batch_id)");

    // === 6. إنشاء العرض المُجسَّد (Materialized View) ===
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS mv_daily_profits (
        profit_date DATE NOT NULL COMMENT 'تاريخ الأرباح',
        total_sales DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي المبيعات',
        total_cost DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي التكلفة',
        gross_profit DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'الربح الإجمالي',
        total_expenses DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي المصاريف',
        total_taxes DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي الضرائب',
        net_profit DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'صافي الربح',
        sales_count INT NOT NULL DEFAULT 0 COMMENT 'عدد عمليات البيع',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (profit_date),
        INDEX idx_profit_date (profit_date),
        INDEX idx_net_profit (net_profit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عرض مجسد للإحصائيات اليومية - يحدث عبر cron أو SaleService::refreshMaterializedView'");

    // === 7. تعبئة أولية للعرض المُجسَّد ===
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM mv_daily_profits")->fetchColumn();
        if (intval($count) === 0) {
            $pdo->exec(
                "INSERT INTO mv_daily_profits (profit_date, total_sales, total_cost, gross_profit, total_expenses, total_taxes, net_profit, sales_count)
                 SELECT
                    s.sale_date,
                    COALESCE(SUM(s.total), 0),
                    COALESCE(SUM(s.cost_total), 0),
                    COALESCE(SUM(s.profit), 0),
                    COALESCE((SELECT SUM(amount) FROM expenses WHERE expense_date = s.sale_date), 0),
                    COALESCE((SELECT SUM(tax_amount) FROM purchases WHERE purchase_date = s.sale_date), 0),
                    COALESCE(SUM(s.profit), 0) - COALESCE((SELECT SUM(amount) FROM expenses WHERE expense_date = s.sale_date), 0),
                    COUNT(*)
                 FROM sales s
                 GROUP BY s.sale_date"
            );

            // تسجيل وقت التحديث
            $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, description)
                 VALUES ('mv_daily_profits_last_refresh', ?, 'آخر تحديث للعرض المجسد للأرباح')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([date('Y-m-d H:i:s')]);
        }
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'upgrade-db::4_25_0_populateMV');
    }

    // === 8. إعداد تحديث العرض المجسد عبر cron ===
    try {
        $pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, description)
             VALUES ('mv_profits_auto_refresh', '1', 'تحديث تلقائي للعرض المجسد للأرباح عند تسجيل مبيعات')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute(['1']);
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'upgrade-db::4_25_0_autoRefreshSetting');
    }
}

function db_upgrade_5_13_0($pdo) {
    // Add discount and paid_amount columns to sales table
    ensureCol($pdo, 'sales', 'discount', "ALTER TABLE sales ADD COLUMN discount DECIMAL(12,2) DEFAULT 0");
    ensureCol($pdo, 'sales', 'paid_amount', "ALTER TABLE sales ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0");

    // Add index on sale_batch_items for faster lookups
    ensureIndex($pdo, 'sale_batch_items', 'idx_sbi_sale_id', 'CREATE INDEX idx_sbi_sale_id ON sale_batch_items(sale_id)');
    ensureIndex($pdo, 'sale_batch_items', 'idx_sbi_batch_id', 'CREATE INDEX idx_sbi_batch_id ON sale_batch_items(batch_id)');

    // Add original_quantity column to purchase_batches if missing (schema consistency)
    ensureCol($pdo, 'purchase_batches', 'original_quantity', "ALTER TABLE purchase_batches ADD COLUMN original_quantity DECIMAL(15,3) DEFAULT 0");

    // Update original_quantity from quantity column for existing records
    try {
        $pdo->exec("UPDATE purchase_batches SET original_quantity = remaining_quantity WHERE original_quantity = 0 OR original_quantity IS NULL");
    } catch (Exception $e) {}
}

function db_upgrade_5_22_0($pdo) {
    // تحسينات الفهارس (بدون توقف — InnoDB Online DDL)

    // 1. فهرس تواريخ الصلاحية في المنتجات — استعلامات الصلاحيات القريبة
    ensureIndex($pdo, "CREATE INDEX idx_expiry ON products(expiry_date)");

    // 2. فهرس تاريخ المبيعات — تقارير المبيعات اليومية والشهرية
    ensureIndex($pdo, "CREATE INDEX idx_sale_date ON sales(sale_date)");

    // 3. فهرس مركب للإشعارات — جلب غير المقروءة مرتبة بالتاريخ
    //    يستبدل idx_user_read القديم بفهرس مركب أفضل
    try { $pdo->exec("ALTER TABLE notifications DROP INDEX idx_user_read"); } catch (\Throwable $e) {}
    ensureIndex($pdo, "CREATE INDEX idx_notif_user_read_date ON notifications(user_id, is_read, created_at)");
}

function db_upgrade_5_23_0($pdo) {
    // v5.23.0: تحسينات المخطط (Schema Improvements)
    //
    // 1. إضافة مفاتيح أجنبية مفقودة (FKs)
    //    - sales.product_id → products(id) ON DELETE RESTRICT
    //    - purchases.product_id → products(id) ON DELETE RESTRICT
    //    - purchase_batches.purchase_id → purchases(id) ON DELETE CASCADE
    //    - caisse_transactions.caisse_id → caisse(id) ON DELETE CASCADE
    //
    // 2. تحويل mv_daily_profits من جدول (Materialized View) إلى VIEW حقيقي
    //    — يضمن دقة الأرباح اليومية دائماً دون تحديث يدوي
    //
    // 3. إضافة عمود caisse_id لجدول caisse_transactions (مع ربط بالبيانات الموجودة)

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return; // هذه التحسينات خاصة بـ MySQL
    }

    // ====================================================================
    // 1. FK مفقودة: sales.product_id → products(id)
    // ====================================================================
    try {
        $pdo->exec("ALTER TABLE sales ADD CONSTRAINT fk_sales_product
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE");
    } catch (\Throwable $e) {
        error_log("v5.23.0: FK fk_sales_product موجود مسبقاً أو فشل: " . $e->getMessage());
    }

    // ====================================================================
    // 2. FK مفقودة: purchases.product_id → products(id)
    // ====================================================================
    try {
        $pdo->exec("ALTER TABLE purchases ADD CONSTRAINT fk_purchases_product
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE");
    } catch (\Throwable $e) {
        error_log("v5.23.0: FK fk_purchases_product موجود مسبقاً أو فشل: " . $e->getMessage());
    }

    // ====================================================================
    // 3. FK على purchase_batches.purchase_id — CASCADE بدلاً من لا شيء
    // ====================================================================
    // أولاً: حذف أي FK موجود على purchase_id إن وُجد
    try {
        $existingFks = $pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_batches'
             AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($existingFks as $fkName) {
            // التحقق من أن هذا FK يخص عمود purchase_id
            $cols = $pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_batches'
                 AND CONSTRAINT_NAME = '{$fkName}'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (in_array('purchase_id', $cols)) {
                $pdo->exec("ALTER TABLE purchase_batches DROP FOREIGN KEY {$fkName}");
            }
        }
    } catch (\Throwable $e) {
        error_log("v5.23.0: فحص FK الحالية على purchase_batches فشل: " . $e->getMessage());
    }

    // ثانياً: إضافة FK مع CASCADE
    try {
        $pdo->exec("ALTER TABLE purchase_batches ADD CONSTRAINT fk_batches_purchase
                    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE ON UPDATE CASCADE");
    } catch (\Throwable $e) {
        error_log("v5.23.0: FK fk_batches_purchase فشل: " . $e->getMessage());
    }

    // ====================================================================
    // 4. FK مفقودة: caisse_transactions.caisse_id → caisse(id)
    //    أولاً: إضافة العمود إن لم يكن موجوداً
    //    ثانياً: ربط البيانات الموجودة بجدول caisse
    //    ثالثاً: إضافة FK
    // ====================================================================
    try {
        // التحقق من وجود العمود
        $colExists = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caisse_transactions'
             AND COLUMN_NAME = 'caisse_id'"
        )->fetch();

        if (!$colExists) {
            $pdo->exec("ALTER TABLE caisse_transactions ADD COLUMN caisse_id INT DEFAULT NULL
                        COMMENT 'معرف سجل الصندوق المرتبط' AFTER transaction_date");

            // ربط البيانات الموجودة: مطابقة transaction_date مع record_date في caisse
            $pdo->exec(
                "UPDATE caisse_transactions ct
                 INNER JOIN caisse c ON c.record_date = ct.transaction_date
                 SET ct.caisse_id = c.id
                 WHERE ct.caisse_id IS NULL"
            );
        }

        // إضافة FK
        $pdo->exec("ALTER TABLE caisse_transactions ADD CONSTRAINT fk_ct_caisse
                    FOREIGN KEY (caisse_id) REFERENCES caisse(id) ON DELETE CASCADE ON UPDATE CASCADE");
    } catch (\Throwable $e) {
        error_log("v5.23.0: FK fk_ct_caisse فشل: " . $e->getMessage());
    }

    // ====================================================================
    // 5. تحويل mv_daily_profits من جدول (Materialized View) إلى VIEW حقيقي
    //    — VIEW يضمن دقة البيانات تلقائياً دون تحديث يدوي أو cron
    // ====================================================================
    try {
        // التحقق مما إذا كان mv_daily_profits جدولاً (وليس VIEW)
        $row = $pdo->query(
            "SELECT TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mv_daily_profits'"
        )->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['TABLE_TYPE'] === 'BASE TABLE') {
            $pdo->exec("DROP TABLE IF EXISTS mv_daily_profits");

            $pdo->exec(
                "CREATE VIEW mv_daily_profits AS
                 SELECT
                    s.sale_date AS profit_date,
                    COALESCE(SUM(s.total), 0) AS total_sales,
                    COALESCE(SUM(s.cost_total), 0) AS total_cost,
                    COALESCE(SUM(s.profit), 0) AS gross_profit,
                    COALESCE((SELECT SUM(amount) FROM expenses WHERE expense_date = s.sale_date), 0) AS total_expenses,
                    COALESCE((SELECT SUM(tax_amount) FROM purchases WHERE purchase_date = s.sale_date), 0) AS total_taxes,
                    COALESCE(SUM(s.profit), 0) - COALESCE((SELECT SUM(amount) FROM expenses WHERE expense_date = s.sale_date), 0) AS net_profit,
                    COUNT(*) AS sales_count
                 FROM sales s
                 GROUP BY s.sale_date"
            );
        }
        // إذا كان VIEW بالفعل، لا شيء يُفعل
    } catch (\Throwable $e) {
        error_log("v5.23.0: تحويل mv_daily_profits إلى VIEW فشل: " . $e->getMessage());
    }

    // تنظيف إعداد التحديث التلقائي للعرض المجسد (لم يعد ضرورياً)
    try {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key IN ('mv_daily_profits_last_refresh', 'mv_profits_auto_refresh')");
    } catch (\Throwable $e) {}
}

function db_upgrade_5_24_0($pdo) {
    // v5.24.0: تحسينات متقدمة على المخطط (Advanced Schema Improvements)
    //
    // 1. Trigger AFTER INSERT على sales: تسجيل price_history عند اختلاف السعر
    //    ⚠️ تحديث المخزون يبقى في PHP (SaleService) لتجنب الخصم المزدوج
    //
    // 2. أعمدة الطوابع الزمنية المفقودة: created_at / updated_at
    //
    // 3. استبدال ENUM بجداول مرجعية: caisse_transactions.type + purchase_remises.type
    //    — التحول: ENUM → VARCHAR(20) + FK إلى جدول مرجعي (code كـ PK)
    //    — الميزة: إضافة أنواع جديدة بإدراج صف في الجدول المرجعي (بدون ALTER TABLE)
    //    — التوافق: الاستعلامات الحالية (WHERE type='deposit') تستمر بالعمل
    //
    // 4. تطبيع caisse_transactions: sale_id / purchase_id / expense_id مع FK

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // ====================================================================
    // 1. Trigger: تسجيل price_history عند بيع بسعر مختلف عن سعر المنتج
    // ====================================================================
    try {
        // حذف الترقر القديم إن وُجد
        $pdo->exec("DROP TRIGGER IF EXISTS trg_sales_price_history");

        $pdo->exec(
            "CREATE TRIGGER trg_sales_price_history AFTER INSERT ON sales
             FOR EACH ROW
             BEGIN
                 DECLARE cur_selling DECIMAL(12,2);
                 DECLARE cur_purchase DECIMAL(12,2);
                 SELECT selling_price, purchase_price INTO cur_selling, cur_purchase
                 FROM products WHERE id = NEW.product_id;
                 IF cur_selling IS NOT NULL AND (NEW.selling_price <> cur_selling OR NEW.purchase_price_at_sale <> cur_purchase) THEN
                     INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason, changed_at)
                     VALUES (NEW.product_id, cur_purchase, NEW.purchase_price_at_sale, cur_selling, NEW.selling_price,
                             CONCAT('بيع #', NEW.id, ' بسعر مختلف عن السعر الحالي'), NOW());
                 END IF;
             END"
        );
    } catch (\Throwable $e) {
        error_log("v5.24.0: Trigger trg_sales_price_history فشل: " . $e->getMessage());
    }

    // ====================================================================
    // 2. أعمدة الطوابع الزمنية المفقودة
    // ====================================================================

    // created_at للجداول التي تفتقده
    $missingCreated = [
        'sale_batch_items'     => "ALTER TABLE sale_batch_items ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER cost_total",
        'additional_units'     => "ALTER TABLE additional_units ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER selling_price",
        'purchase_additional_units' => "ALTER TABLE purchase_additional_units ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER conversion_factor",
    ];

    // updated_at للجداول التي يُعدَّل محتواها (وليست readonly)
    $missingUpdated = [
        'purchase_batches'     => "ALTER TABLE purchase_batches ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        'purchase_remises'     => "ALTER TABLE purchase_remises ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        'system_settings'      => "ALTER TABLE system_settings ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER description",
    ];

    foreach ($missingCreated as $table => $sql) {
        try {
            $colExists = $pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'created_at'"
            )->fetch();
            if (!$colExists) $pdo->exec($sql);
        } catch (\Throwable $e) {
            error_log("v5.24.0: created_at لـ {$table} فشل: " . $e->getMessage());
        }
    }

    foreach ($missingUpdated as $table => $sql) {
        try {
            $colExists = $pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'updated_at'"
            )->fetch();
            if (!$colExists) $pdo->exec($sql);
        } catch (\Throwable $e) {
            error_log("v5.24.0: updated_at لـ {$table} فشل: " . $e->getMessage());
        }
    }

    // ====================================================================
    // 3. استبدال ENUM بجداول مرجعية
    // ====================================================================

    // --- 3a. transaction_types (بدلاً من caisse_transactions.type ENUM) ---
    try {
        ensureTable($pdo, "CREATE TABLE IF NOT EXISTS transaction_types (
            code VARCHAR(20) PRIMARY KEY,
            name_ar VARCHAR(100) NOT NULL,
            name_fr VARCHAR(100) NOT NULL,
            name_en VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع حركات الصندوق (بديل ENUM)'");

        // بذر البيانات
        $pdo->exec("INSERT IGNORE INTO transaction_types (code, name_ar, name_fr, name_en, sort_order) VALUES
            ('deposit', 'إيداع', 'Dépôt', 'Deposit', 1),
            ('withdrawal', 'سحب', 'Retrait', 'Withdrawal', 2)");

        // تحويل ENUM إلى VARCHAR(20)
        $colInfo = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caisse_transactions' AND COLUMN_NAME = 'type'"
        )->fetch(PDO::FETCH_ASSOC);

        if ($colInfo && stripos($colInfo['COLUMN_TYPE'], 'enum') !== false) {
            $pdo->exec("ALTER TABLE caisse_transactions MODIFY COLUMN type VARCHAR(20) NOT NULL");
        }

        // إضافة FK: type → transaction_types(code)
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD CONSTRAINT fk_ct_type
                        FOREIGN KEY (type) REFERENCES transaction_types(code) ON UPDATE CASCADE");
        } catch (\Throwable $e) {
            error_log("v5.24.0: FK fk_ct_type موجود مسبقاً أو فشل: " . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log("v5.24.0: transaction_types فشل: " . $e->getMessage());
    }

    // --- 3b. remise_types (بدلاً من purchase_remises.type ENUM) ---
    try {
        ensureTable($pdo, "CREATE TABLE IF NOT EXISTS remise_types (
            code VARCHAR(20) PRIMARY KEY,
            name_ar VARCHAR(100) NOT NULL,
            name_fr VARCHAR(100) NOT NULL,
            name_en VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع التخفيضات (بديل ENUM)'");

        // بذر البيانات
        $pdo->exec("INSERT IGNORE INTO remise_types (code, name_ar, name_fr, name_en, sort_order) VALUES
            ('material', 'تخفيض مادي', 'Remise matérielle', 'Material', 1),
            ('financial', 'تخفيض مالي', 'Remise financière', 'Financial', 2)");

        // تحويل ENUM إلى VARCHAR(20)
        $colInfo = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_remises' AND COLUMN_NAME = 'type'"
        )->fetch(PDO::FETCH_ASSOC);

        if ($colInfo && stripos($colInfo['COLUMN_TYPE'], 'enum') !== false) {
            $pdo->exec("ALTER TABLE purchase_remises MODIFY COLUMN type VARCHAR(20) NOT NULL");
        }

        // إضافة FK: type → remise_types(code)
        try {
            $pdo->exec("ALTER TABLE purchase_remises ADD CONSTRAINT fk_pr_type
                        FOREIGN KEY (type) REFERENCES remise_types(code) ON UPDATE CASCADE");
        } catch (\Throwable $e) {
            error_log("v5.24.0: FK fk_pr_type موجود مسبقاً أو فشل: " . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log("v5.24.0: remise_types فشل: " . $e->getMessage());
    }

    // ====================================================================
    // 4. تطبيع caisse_transactions: أعمدة FK بدلاً من reference_type المرن
    // ====================================================================
    try {
        // sale_id → sales(id)
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD COLUMN sale_id INT DEFAULT NULL
                        COMMENT 'معرف عملية البيع المرتبطة' AFTER caisse_id");
        } catch (\Throwable $e) {} // العمود موجود مسبقاً
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD CONSTRAINT fk_ct_sale
                        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL ON UPDATE CASCADE");
        } catch (\Throwable $e) {
            error_log("v5.24.0: FK fk_ct_sale موجود مسبقاً أو فشل: " . $e->getMessage());
        }

        // purchase_id → purchases(id)
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD COLUMN purchase_id INT DEFAULT NULL
                        COMMENT 'معرف عملية الشراء المرتبطة' AFTER sale_id");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD CONSTRAINT fk_ct_purchase
                        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL ON UPDATE CASCADE");
        } catch (\Throwable $e) {
            error_log("v5.24.0: FK fk_ct_purchase موجود مسبقاً أو فشل: " . $e->getMessage());
        }

        // expense_id → expenses(id)
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD COLUMN expense_id INT DEFAULT NULL
                        COMMENT 'معرف المصروف المرتبط' AFTER purchase_id");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE caisse_transactions ADD CONSTRAINT fk_ct_expense
                        FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL ON UPDATE CASCADE");
        } catch (\Throwable $e) {
            error_log("v5.24.0: FK fk_ct_expense موجود مسبقاً أو فشل: " . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log("v5.24.0: تطبيع caisse_transactions فشل: " . $e->getMessage());
    }
}

function db_upgrade_5_32_1($pdo) {
    // v5.32.1: تحسينات مالية — الإجمالي مع الضريبة + السعر الفعلي للدفعات
    //
    // 1. grand_total في purchases: الإجمالي النهائي = صافي بعد الخصم + الضريبة
    //    - يحل مشكلة أن SUM(total) لا يشمل الضرائب
    //
    // 2. actual_purchase_price في purchase_batches: التكلفة الحقيقية بالوحدة
    //    = (صافي خط الشراء + حصة الضريبة) ÷ الكمية
    //    - يُعطي تكلفة دقيقة لحساب الأرباح

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // إضافة grand_total إلى purchases
    try {
        $pdo->exec("ALTER TABLE purchases ADD COLUMN grand_total DECIMAL(12,2) NOT NULL DEFAULT 0
                    COMMENT 'الإجمالي النهائي (صافي بعد الخصم + الضريبة)' AFTER tax_name");
    } catch (\Throwable $e) {}

    // تعبئة grand_total للسجلات الموجودة: total + tax_amount
    try {
        $pdo->exec("UPDATE purchases SET grand_total = total + tax_amount WHERE grand_total = 0 OR grand_total IS NULL");
    } catch (\Throwable $e) {}

    // إضافة actual_purchase_price إلى purchase_batches
    try {
        $pdo->exec("ALTER TABLE purchase_batches ADD COLUMN actual_purchase_price DECIMAL(12,6) DEFAULT NULL
                    COMMENT 'التكلفة الحقيقية بالوحدة (بعد توزيع الخصم والضريبة)' AFTER purchase_price");
    } catch (\Throwable $e) {}

    // تعبئة actual_purchase_price للدفعات الموجودة
    // من عمليات الشراء المرتبطة: (total + tax_amount) / quantity
    try {
        $pdo->exec("
            UPDATE purchase_batches pb
            JOIN purchases p ON pb.purchase_id = p.id
            SET pb.actual_purchase_price = 
                CASE 
                    WHEN p.quantity > 0 THEN ROUND((p.total + p.tax_amount) / p.quantity, 6)
                    ELSE pb.purchase_price
                END
            WHERE pb.actual_purchase_price IS NULL
        ");
    } catch (\Throwable $e) {}

    // للدفعات بدون عملية شراء مرتبطة: نسخ purchase_price
    try {
        $pdo->exec("UPDATE purchase_batches SET actual_purchase_price = purchase_price WHERE actual_purchase_price IS NULL");
    } catch (\Throwable $e) {}
}

function db_upgrade_5_33_0($pdo) {
    // v5.33.0: تحسينات مالية حرجة — توحيد حساب total + الإجمالي مع الضريبة + السعر الفعلي للدفعات
    //
    // المشكلة:
    //   1. add-purchase.php يخزّن total بعد الخصم المالي، PurchaseService يخزّنه بدونه → بيانات غير متسقة
    //   2. total = قبل الضريبة، SUM(total) في التقارير لا يشمل الضرائب → أرقام مالية ناقصة
    //   3. purchase_batches.purchase_price = السعر الأصلي فقط، الخصم والضريبة غير مُوزَّعين → أرباح غير دقيقة
    //
    // الحل:
    //   1. توحيد منطق الحساب في كلا المسارين (الويب + الجوال) — تم في add-purchase.php و PurchaseService.php
    //   2. إضافة grand_total = صافي بعد الخصم + الضريبة
    //   3. إضافة actual_purchase_price = (صافي + ضريبة) ÷ الكمية لكل دفعة

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. إضافة grand_total إلى purchases
    try {
        $pdo->exec("ALTER TABLE purchases ADD COLUMN grand_total DECIMAL(12,2) NOT NULL DEFAULT 0
                    COMMENT 'الإجمالي النهائي (صافي بعد الخصم + الضريبة)' AFTER tax_name");
    } catch (\Throwable $e) {
        // العمود قد يكون موجوداً من تنفيذ سابق يدوي
    }

    // تعبئة grand_total للسجلات الموجودة: total + tax_amount
    try {
        $pdo->exec("UPDATE purchases SET grand_total = total + IFNULL(tax_amount, 0) WHERE grand_total = 0 OR grand_total IS NULL");
    } catch (\Throwable $e) {}

    // 2. إضافة actual_purchase_price إلى purchase_batches
    try {
        $pdo->exec("ALTER TABLE purchase_batches ADD COLUMN actual_purchase_price DECIMAL(12,6) DEFAULT NULL
                    COMMENT 'التكلفة الحقيقية بالوحدة (بعد توزيع الخصم والضريبة)' AFTER purchase_price");
    } catch (\Throwable $e) {
        // العمود قد يكون موجوداً من تنفيذ سابق يدوي
    }

    // تعبئة actual_purchase_price للدفعات الموجودة
    // من عمليات الشراء المرتبطة: (total + tax_amount) / quantity
    try {
        $pdo->exec("
            UPDATE purchase_batches pb
            JOIN purchases p ON pb.purchase_id = p.id
            SET pb.actual_purchase_price =
                CASE
                    WHEN p.quantity > 0 THEN ROUND((p.total + IFNULL(p.tax_amount, 0)) / p.quantity, 6)
                    ELSE pb.purchase_price
                END
            WHERE pb.actual_purchase_price IS NULL
        ");
    } catch (\Throwable $e) {}

    // للدفعات بدون عملية شراء مرتبطة: نسخ purchase_price
    try {
        $pdo->exec("UPDATE purchase_batches SET actual_purchase_price = purchase_price WHERE actual_purchase_price IS NULL");
    } catch (\Throwable $e) {}
}

function db_upgrade_6_0_0($pdo) {
    // v6.0.0: هيكلة فواتير الشراء — جدول purchase_orders + ربط المنتجات والتخفيضات بالفاتورة
    //
    // المشكلة:
    //   كل منتج في عملية شراء يُخزّن كصف منفصل في purchases بدون رابط مشترك.
    //   التخفيضات العامة تُربط بأول منتج فقط -> تبدو كأنها خاصة بمنتج واحد.
    //
    // الحل:
    //   1. جدول purchase_orders (فواتير الشراء) يحتوي على الإجماليات والضريبة والتخفيض
    //   2. ربط purchases بـ order_id
    //   3. ربط purchase_remises بـ order_id
    //   4. البيانات القديمة تبقى بدون order_id (NULL) — متوافق تماماً

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. إنشاء جدول فواتير الشراء
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            total DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'الإجمالي قبل الخصم والضريبة',
            financial_discount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي التخفيضات المالية',
            net_total DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'الصافي بعد الخصم',
            tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'نسبة الضريبة',
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مبلغ الضريبة',
            tax_name VARCHAR(100) DEFAULT NULL COMMENT 'اسم الضريبة',
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'الإجمالي النهائي (صافي + ضريبة)',
            purchase_date DATE NOT NULL COMMENT 'تاريخ الشراء',
            notes TEXT DEFAULT NULL COMMENT 'ملاحظات',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order_date (purchase_date),
            KEY idx_order_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير الشراء'");
    } catch (\Throwable $e) {}

    // 2. إضافة order_id إلى جدول purchases
    try {
        $pdo->exec("ALTER TABLE purchases ADD COLUMN order_id INT DEFAULT NULL COMMENT 'رقم فاتورة الشراء' AFTER id");
    } catch (\Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE purchases ADD INDEX idx_purchases_order (order_id)");
    } catch (\Throwable $e) {}

    // 3. إضافة order_id إلى جدول purchase_remises
    try {
        $pdo->exec("ALTER TABLE purchase_remises ADD COLUMN order_id INT DEFAULT NULL COMMENT 'رقم فاتورة الشراء' AFTER purchase_id");
    } catch (\Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE purchase_remises ADD INDEX idx_remises_order (order_id)");
    } catch (\Throwable $e) {}

    // 4. إنشاء جدول الموردين (suppliers)
    //    مطلوب للاستعلامات في: list-purchases.php, purchases-report.php
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL COMMENT 'اسم المورد',
            phone VARCHAR(50) DEFAULT NULL COMMENT 'الهاتف',
            email VARCHAR(255) DEFAULT NULL COMMENT 'البريد الإلكتروني',
            address TEXT DEFAULT NULL COMMENT 'العنوان',
            notes TEXT DEFAULT NULL COMMENT 'ملاحظات',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_suppliers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموردين'");
    } catch (\Throwable $e) {}

    // 5. إضافة supplier_id إلى جدول purchases
    //    يُستخدم في فلتر list-purchases.php (p.supplier_id = ?)
    try {
        $pdo->exec("ALTER TABLE purchases ADD COLUMN supplier_id INT DEFAULT NULL COMMENT 'رقم المورد' AFTER id");
    } catch (\Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE purchases ADD INDEX idx_purchases_supplier (supplier_id)");
    } catch (\Throwable $e) {}

    // 6. إضافة supplier_id إلى جدول purchase_orders
    //    يُستخدم في purchases-report.php (po.supplier_id, LEFT JOIN suppliers)
    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN supplier_id INT DEFAULT NULL COMMENT 'رقم المورد' AFTER id");
    } catch (\Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD INDEX idx_orders_supplier (supplier_id)");
    } catch (\Throwable $e) {}
}

function db_upgrade_6_1_2($pdo) {
    // v6.1.2: إصلاح أمني حرج — التحقق من ضريبة POS جانب الخادم (W14)
    //
    // المشكلة:
    //   ضريبة POS كانت تُحسَب في JS فقط (POS_TAX_RATE) والخادم يثق بها دون فحص.
    //   أي مستخدم يمكنه تعطيل الضريبة عبر DevTools وسرقة مبلغ الضريبة.
    //
    // الحل:
    //   1. إضافة عمود tax_amount إلى جدول sales لتخزين مبلغ الضريبة لكل صف
    //   2. حساب الضريبة في api/pos-process-sale.php من system_settings
    //   3. مقارنة الضريبة المُرسَلة من العميل مع المحسوبة في الخادم

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. إضافة tax_amount إلى جدول sales (إن لم يكن موجوداً)
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مبلغ الضريبة على الصنف' AFTER profit");
    } catch (\Throwable $e) {}

    // 2. فهرس لتقارير الضرائب السريعة
    try {
        $pdo->exec("ALTER TABLE sales ADD INDEX idx_sales_tax (sale_date, tax_amount)");
    } catch (\Throwable $e) {}

    // 3. ضمان وجود إعدادات الضريبة الافتراضية في system_settings
    //    (إن لم تكن موجودة، نستخدم القيم الافتراضية: معطلة 0% في الأنظمة الجديدة)
    try {
        $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
            ('tax_enabled', '0', 'تفعيل الضريبة على المبيعات'),
            ('tax_rate', '0', 'نسبة ضريبة المبيعات (%)')
        ");
    } catch (\Throwable $e) {}
}

function db_upgrade_6_1_3($pdo) {
    // v6.1.3: إزالة تكرار CaisseHelper.php (W5) — تغييرات في PHP فقط
    //         لا يوجد تغيير في قاعدة البيانات.
    //         الدالة الفارغة ضرورية لتسلسل الإصدار ومنع التخطي الصامت في repairTables().
}

function db_upgrade_6_1_4($pdo) {
    // v6.1.4: تتبع طريقة الدفع في POS (W6)
    //
    // المشكلة:
    //   - الـ payload يرسل paid_amount فقط بدون payment_method
    //   - عمود paid_amount كان يُخزَّن دائماً = 0 على مستوى الصنف
    //   - لا يمكن معرفة هل الدفع نقداً أم بطاقة أم مقسّم
    //
    // الحل:
    //   1. إضافة payment_method إلى sales (cash/card/split) — نفس القيمة على كل أصناف الفاتورة
    //   2. إضافة change_amount إلى sales (الباقي للعميل) — مخزّن على أول صنف فقط
    //   3. إضافة card_amount و cash_amount لدعم الدفع المقسّم — مخزّنين على أول صنف فقط
    //   4. تحديث paid_amount فعلياً بمبلغ الدفع الكلي للفاتورة (كان دائماً 0)

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. payment_method — طريقة الدفع (cash/card/split)
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' COMMENT 'طريقة الدفع: cash/card/split' AFTER paid_amount");
    } catch (\Throwable $e) {}

    // 2. change_amount — الباقي للعميل
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN change_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'الباقي للعميل (مخزّن على أول صنف من الفاتورة)' AFTER payment_method");
    } catch (\Throwable $e) {}

    // 3. cash_amount و card_amount لدعم الدفع المقسّم
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'المبلغ النقدي في الدفع المقسّم (أول صنف)' AFTER change_amount");
    } catch (\Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN card_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'مبلغ البطاقة في الدفع المقسّم (أول صنف)' AFTER cash_amount");
    } catch (\Throwable $e) {}

    // 5. فهرس لتقارير طرق الدفع السريعة
    try {
        $pdo->exec("ALTER TABLE sales ADD INDEX idx_sales_payment_method (sale_date, payment_method)");
    } catch (\Throwable $e) {}
}

function db_upgrade_6_1_5($pdo) {
    // v6.1.5: إصلاح تجمّد POS عند تفعيل الفرنسية (تغييرات في JavaScript فقط)
    //         لا يوجد تغيير في قاعدة البيانات.
    //         الدالة الفارغة ضرورية لتسلسل الإصدار ومنع التخطي الصامت في repairTables().
}

function db_upgrade_6_1_6($pdo) {
    // v6.1.6: W11 (خصم بالنسبة المئوية) + W12 (خصم على صنف فردي) + رقم الإيصال
    // إضافة عمود receipt_no لتخزين رقم الإيصال التلقائي (صيغة: YYYYMMDD-NNNN)
    ensureCol($pdo, 'sales', 'receipt_no', "receipt_no VARCHAR(20) DEFAULT NULL AFTER sale_date");
    // إنشاء فهرس فريد لمنع التكرار (مع السماح بـ NULL للمبيعات القديمة)
    try {
        $pdo->exec("CREATE UNIQUE INDEX idx_sales_receipt_no ON sales (receipt_no)");
    } catch (\Throwable $e) {
        // الفهرس موجود مسبقاً — تجاهل
    }
    // تعبئة المبيعات القديمة برقم إيصال مبني على التاريخ + id
    try {
        $pdo->exec("UPDATE sales SET receipt_no = CONCAT(DATE_FORMAT(sale_date, '%Y%m%d'), '-', LPAD(id, 4, '0')) WHERE receipt_no IS NULL");
    } catch (\Throwable $e) {
        // تجاهل خطأ في حال وجود مشكلة
    }
}

function db_upgrade_6_1_7($pdo) {
    // v6.1.7: إصلاح طباعة رقم الإيصال في @media print + بديل sale_id
    //         لا يوجد تغيير في قاعدة البيانات — تغييرات في CSS و JavaScript فقط.
    //         الدالة الفارغة ضرورية لتسلسل الإصدار ومنع التخطي الصامت في repairTables().
}

function db_upgrade_6_1_8($pdo) {
    // v6.1.8: إصلاح حرج — receipt_no: NULL بدل '' للأصناف غير الأولى
    //         فهرس UNIQUE يرفض تكرار '' لكنه يسمح بتكرار NULL
    try {
        $pdo->exec("UPDATE sales SET receipt_no = NULL WHERE receipt_no = ''");
    } catch (\Throwable $e) {
        // تجاهل — العمود قد لا يكون موجوداً
    }
}

function db_upgrade_6_1_9($pdo) {
    // v6.1.9: إصلاح جراحي — فحص ديناميكي لكل الأعمدة في pos-search-sale.php
    //         لا يوجد تغيير في قاعدة البيانات — تغييرات في PHP فقط.
    //         الدالة الفارغة ضرورية لتسلسل الإصدار ومنع التخطي الصامت في repairTables().
}

function db_upgrade_6_1_10($pdo) {
    // v6.1.10: إصلاح جراحي — رفع z-index نافذة التأكيد فوق نافذة الإرجاع
    //          لا يوجد تغيير في قاعدة البيانات — تغيير في CSS فقط.
    //          الدالة الفارغة ضرورية لتسلسل الإصدار ومنع التخطي الصامت في repairTables().
}

// ====================================================================
//                دوال ترقية قاعدة البيانات (v5.34.0 → v6.1.0)
// ====================================================================
// ملاحظة: هذه الإصدارات تتضمن تغييرات في ملفات PHP فقط (لا يوجد DDL)
//        الدوال الفارغة ضرورية لتسلسل الإصدارات بشكل صحيح ومنع
//        التخطي الصامت في repairTables() و install.php

function db_upgrade_5_34_0($pdo) {
    // v5.34.0: تعديل الضريبة/الخصومات بعد الحفظ (تغييرات في PHP فقط)
}

function db_upgrade_5_34_1($pdo) {
    // v5.34.1: إصلاح Parse error في edit-purchase.php (تغييرات في PHP فقط)
}

function db_upgrade_5_34_2($pdo) {
    // v5.34.2: تصحيح دفعات بسعر 0 للتخفيض المادي (تغييرات في PHP فقط)
}

function db_upgrade_6_0_1($pdo) {
    // v6.0.1: عرض سياق الفاتورة في تعديل الشراء + رقم الفاتورة في المنتج (تغييرات في PHP فقط)
}

function db_upgrade_6_0_2($pdo) {
    // v6.0.2: إصلاح تكرار سياق الفاتورة + جدول فواتير التقرير (تغييرات في PHP فقط)
}

function db_upgrade_6_1_0($pdo) {
    // v6.1.0: صفحة سجل المشتريات list-purchases.php (تغييرات في PHP فقط)
}

function db_upgrade_6_1_1($pdo) {
    // v6.1.1: إصلاح حرج — إنشاء جدول suppliers + إضافة supplier_id
    //         إلى purchases و purchase_orders
    //
    // السبب: الاستعلامات في list-purchases.php و purchases-report.php
    //        تستخدم supplier_id لكن العمود لم يكن مُنشأً في v6.0.0

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. إنشاء جدول الموردين (إن لم يكن موجوداً)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL COMMENT 'اسم المورد',
            phone VARCHAR(50) DEFAULT NULL COMMENT 'الهاتف',
            email VARCHAR(255) DEFAULT NULL COMMENT 'البريد الإلكتروني',
            address TEXT DEFAULT NULL COMMENT 'العنوان',
            notes TEXT DEFAULT NULL COMMENT 'ملاحظات',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_suppliers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموردين'");
    } catch (\Throwable $e) {}

    // 2. إضافة supplier_id إلى جدول purchases (إن لم يكن موجوداً)
    try {
        $pdo->exec("ALTER TABLE purchases ADD COLUMN supplier_id INT DEFAULT NULL COMMENT 'رقم المورد' AFTER id");
    } catch (\Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE purchases ADD INDEX idx_purchases_supplier (supplier_id)");
    } catch (\Throwable $e) {}

    // 3. إضافة supplier_id إلى جدول purchase_orders (إن لم يكن موجوداً)
    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN supplier_id INT DEFAULT NULL COMMENT 'رقم المورد' AFTER id");
    } catch (\Throwable $e) {}

    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD INDEX idx_orders_supplier (supplier_id)");
    } catch (\Throwable $e) {}
}

function db_upgrade_6_1_20($pdo) {
    // v6.1.20: تحسينات نظام الإشعارات
    // 1. إضافة عمود priority (مستوى الأولوية)
    // 2. إضافة عمود group_key (مفتاح التجميع)
    // 3. إضافة عمود group_count (عدد الإشعارات المجمّعة)
    // 4. إضافة فهرس على group_key لتحسين أداء التجميع
    // 5. إضافة فهرس على priority لتحسين الفرز

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. عمود الأولوية
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'مستوى الأولوية: urgent/normal/info' AFTER type");
    } catch (\Throwable $e) {}

    // 2. مفتاح التجميع
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN group_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح التجميع — الإشعارات بنفس المفتاح تُدمج' AFTER priority");
    } catch (\Throwable $e) {}

    // 3. عدد المجمّع
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN group_count INT NOT NULL DEFAULT 1 COMMENT 'عدد الإشعارات المجمّعة' AFTER group_key");
    } catch (\Throwable $e) {}

    // 4. فهرس التجميع
    try {
        $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notif_group (user_id, group_key, is_read)");
    } catch (\Throwable $e) {}

    // 5. فهرس الأولوية
    try {
        $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notif_priority (priority, created_at)");
    } catch (\Throwable $e) {}
}

function db_upgrade_6_2_0($pdo) {
    // v6.2.0: إصلاحات متوسطة — جدولان جديدان
    // 1. جدول تفضيلات إشعارات المستخدم (user_notification_prefs)
    // 2. جدول تتبع تفاعل المستخدم (notification_analytics)

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. جدول تفضيلات إشعارات المستخدم
    // يسمح لكل مستخدم بالتحكم بأنواع الإشعارات التي يستقبلها
    // prefs: JSON يحتوي على {sale: true, purchase: false, sound: "default", dnd: false, ...}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_prefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE COMMENT 'معرف المستخدم — علاقة واحد لواحد',
            prefs JSON NOT NULL COMMENT 'تفضيلات الإشعارات بصيغة JSON: {sale: true, purchase: false, sound: \"default\", dnd: false, dnd_start: \"22:00\", dnd_end: \"07:00\"}',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تحديث',
            INDEX idx_unp_user (user_id),
            CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفضيلات إشعارات المستخدم — يتحكم كل مستخدم بأنواع الإشعارات التي يستقبلها'");
    } catch (\Throwable $e) {
        // الجدول قد يكون موجوداً مسبقاً
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_0::user_notification_prefs');
        }
    }

    // 2. جدول تتبع تفاعل المستخدم (Analytics)
    // يسجّل أحداث: قراءة (read)، نقر (click)، إغلاق (dismiss)، حذف (delete)
    // يسمح بتقييم فعالية الإشعارات وقياس وقت الاستجابة
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_analytics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL COMMENT 'معرف الإشعار',
            user_id INT NOT NULL COMMENT 'معرف المستخدم الذي تفاعل مع الإشعار',
            action VARCHAR(20) NOT NULL COMMENT 'نوع التفاعل: read / click / dismiss / delete / closed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'وقت التفاعل',
            INDEX idx_na_notif (notification_id),
            INDEX idx_na_user (user_id),
            INDEX idx_na_action (action),
            INDEX idx_na_created (created_at),
            UNIQUE INDEX idx_na_unique (notification_id, user_id, action) COMMENT 'منع تكرار نفس التفاعل لنفس الإشعار'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تتبع تفاعل المستخدم مع الإشعارات — قراءة/نقر/إغلاق/حذف'");
    } catch (\Throwable $e) {
        // الجدول قد يكون موجوداً مسبقاً
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_0::notification_analytics');
        }
    }
}

function db_upgrade_6_2_1($pdo) {
    // v6.2.1: ضمان تطبيق ترقية قاعدة البيانات — إنشاء الجدولين إذا لم يوجدا
    // هذه الدالة idempotent: CREATE TABLE IF NOT EXISTS لا تُسبب أخطاء إذا الجداول موجودة
    // الغرض: ضمان أن نظام الترقية يُسجّل الإصدار 6.2.1 في قاعدة البيانات

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. جدول تفضيلات إشعارات المستخدم
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_prefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE COMMENT 'معرف المستخدم — علاقة واحد لواحد',
            prefs JSON NOT NULL COMMENT 'تفضيلات الإشعارات بصيغة JSON',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تحديث',
            INDEX idx_unp_user (user_id),
            CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفضيلات إشعارات المستخدم'");
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_1::user_notification_prefs');
        }
    }

    // 2. جدول تتبع تفاعل المستخدم (Analytics)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_analytics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL COMMENT 'معرف الإشعار',
            user_id INT NOT NULL COMMENT 'معرف المستخدم',
            action VARCHAR(20) NOT NULL COMMENT 'نوع التفاعل: read / click / dismiss / delete / closed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'وقت التفاعل',
            INDEX idx_na_notif (notification_id),
            INDEX idx_na_user (user_id),
            INDEX idx_na_action (action),
            INDEX idx_na_created (created_at),
            UNIQUE INDEX idx_na_unique (notification_id, user_id, action) COMMENT 'منع تكرار نفس التفاعل'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تتبع تفاعل المستخدم مع الإشعارات'");
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_1::notification_analytics');
        }
    }
}

function db_upgrade_6_2_2($pdo) {
    // v6.2.2: إصلاحات مخططية
    // 1. جدول أرشيف الإشعارات (archive_notifications)
    // 2. فهرس FULLTEXT على notifications(title, message) للبحث
    // 3. أعمدة i18n في notifications إذا لم تكن موجودة (تثبيتات قديمة)
    // 4. إعداد notification_ttl_days

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. جدول أرشيف الإشعارات — يحفظ الإشعارات القديمة قبل الحذف
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS archive_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT 'معرف المستخدم',
            type VARCHAR(50) NOT NULL COMMENT 'نوع الإشعار',
            title VARCHAR(255) NOT NULL COMMENT 'العنوان',
            message TEXT NOT NULL COMMENT 'الرسالة',
            data JSON DEFAULT NULL COMMENT 'بيانات إضافية',
            title_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة العنوان',
            message_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة الرسالة',
            message_params JSON DEFAULT NULL COMMENT 'معاملات الترجمة',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'مستوى الأولوية',
            group_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح التجميع',
            group_count INT NOT NULL DEFAULT 1 COMMENT 'عدد الإشعارات المجمّعة',
            original_created_at TIMESTAMP DEFAULT NULL COMMENT 'تاريخ إنشاء الإشعار الأصلي',
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الأرشفة',
            INDEX idx_arch_user (user_id),
            INDEX idx_arch_type (type),
            INDEX idx_arch_date (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرشيف الإشعارات القديمة — يُحفظ قبل الحذف لأغراض التدقيق'");
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_2::archive_notifications');
        }
    }

    // 2. فهرس FULLTEXT على notifications(title, message) للبحث
    try {
        $pdo->exec("ALTER TABLE notifications ADD FULLTEXT INDEX ft_notif_search (title, message)");
    } catch (\Throwable $e) {
        // الفهرس قد يكون موجوداً مسبقاً
    }

    // 3. أعمدة i18n — إضافتها إذا لم تكن موجودة (تثبيتات قديمة)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('title_key', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN title_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة العنوان' AFTER data");
        }
        if (!in_array('message_key', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN message_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة الرسالة' AFTER title_key");
        }
        if (!in_array('message_params', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN message_params JSON DEFAULT NULL COMMENT 'معاملات الترجمة' AFTER message_key");
        }
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_2::i18n_columns');
        }
    }

    // 4. إعداد TTL للإشعارات غير المقروءة (90 يوم افتراضياً)
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $s->execute(['notification_ttl_days']);
        if (!$s->fetchColumn()) {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                ->execute(['notification_ttl_days', '90', 'عدد الأيام قبل تعيين الإشعارات غير المقروءة كمقروءة تلقائياً (TTL)']);
        }
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_2_2::ttl_setting');
        }
    }
}

function db_upgrade_6_3_0($pdo) {
    // v6.3.0: تحسينات الإشعارات الفورية
    // 1. أعمدة مفقودة في installations القديمة (priority, group_key, group_count)
    // 2. فهرس FULLTEXT للبحث إذا لم يُنشأ في v6.2.2
    // 3. إعداد SSE heartbeat

    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return;
    }

    // 1. أعمدة مفقودة — إضافتها إذا لم تكن موجودة (تثبيتات قديمة)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('priority', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'مستوى الأولوية' AFTER message_params");
        }
        if (!in_array('group_key', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN group_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح التجميع' AFTER priority");
        }
        if (!in_array('group_count', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN group_count INT NOT NULL DEFAULT 1 COMMENT 'عدد الإشعارات المجمّعة' AFTER group_key");
        }
        // i18n columns — مرة أخرى لضمان الوجود (قد تكون مفقودة في تثبيتات v5.0.0)
        if (!in_array('title_key', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN title_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة العنوان' AFTER data");
        }
        if (!in_array('message_key', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN message_key VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح ترجمة الرسالة' AFTER title_key");
        }
        if (!in_array('message_params', $cols)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN message_params JSON DEFAULT NULL COMMENT 'معاملات الترجمة' AFTER message_key");
        }
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_3_0::missing_columns');
        }
    }

    // 2. فهرس FULLTEXT — إذا لم يُنشأ في v6.2.2
    try {
        $fulltextExists = false;
        $indexes = $pdo->query("SHOW INDEX FROM notifications WHERE Index_type = 'FULLTEXT'")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($indexes)) {
            $fulltextExists = true;
        }
        if (!$fulltextExists) {
            $pdo->exec("ALTER TABLE notifications ADD FULLTEXT INDEX ft_notif_search (title, message)");
        }
    } catch (\Throwable $e) {
        // الفهرس قد يكون موجوداً مسبقاً
    }

    // 3. إعداد SSE — لا يتغييرات في قاعدة البيانات (SSE endpoint يعمل عبر HTTP)
    // يمكن إضافة إعداد لتفعيل/تعطيل SSE
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $s->execute(['notifications_sse_enabled']);
        if (!$s->fetchColumn()) {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                ->execute(['notifications_sse_enabled', '0', 'تفعيل Server-Sent Events للإشعارات الفورية (1 = مفعل، 0 = polling فقط) — معطّل افتراضياً']);
        }
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_3_0::sse_setting');
        }
    }
}

/**
 * v6.3.3 — تنظيف مخططي: إسقاط جدول user_notification_preferences المهمل
 * (المرحلة 1 من خطة الإصلاح — REPAIR_PLAN.md)
 *
 * الجدول الحي هو user_notification_prefs (بنية JSON: user_id + prefs)
 * منذ v6.2.1 ويستهلكه api/notifications.php — بينما الجدول القديم
 * (بنية قياسية: notif_type/channel/enabled) لا يشير إليه أي كود إطلاقاً،
 * وهو فارغ في أحدث نسخة إنتاجية (backup_2026-11-10).
 * للأمان: إن وُجدت بيانات في تركيبة ما، يُؤرشف الجدول بإعادة تسمية
 * بدل إسقاطه نهائياً.
 */
function db_upgrade_6_3_3($pdo) {
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'user_notification_preferences'")->fetchColumn();
        if (!$exists) {
            return; // غير موجود — لا شيء للتنظيف (idempotent)
        }
        $count = (int) $pdo->query("SELECT COUNT(*) FROM user_notification_preferences")->fetchColumn();
        if ($count > 0) {
            // احتياط أمان: أرشفة بدل فقدان بيانات غير متوقعة
            $pdo->exec("RENAME TABLE `user_notification_preferences` TO `user_notification_preferences_archived_6_3_3`");
        } else {
            $pdo->exec("DROP TABLE `user_notification_preferences`");
        }
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_3_3::dropLegacyPrefsTable');
        }
        // الإسقاط غير حرج لعمل التطبيق — لا نرمي الاستثناء حتى لا يفشل الترقيم
    }
}

/**
 * v6.3.6: إصلاح شامل لجدول api_tokens
 * - إضافة الأعمدة المفقودة (token_hash / token_name / scopes) التي يحتاجها
 *   كود تسجيل دخول الهاتف MobileAuthController ووسيط TokenAuthMiddleware
 * - تعبئة token_hash = SHA2(token, 256) للصفوف القديمة — الرموز القديمة تبقى صالحة
 * - فهرس UNIQUE idx_api_tokens_hash على token_hash
 * idempotent — آمن للتنفيذ المتكرر
 */
function db_upgrade_6_3_6($pdo) {
    try {
        // فحص وجود الجدول
        $exists = $pdo->query("SHOW TABLES LIKE 'api_tokens'")->fetchColumn();
        if (!$exists) {
            // الجدول غير موجود — إنشاؤه كاملاً (مطابق لـ sql/schema.sql)
            $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(128) NOT NULL,
                token_hash VARCHAR(64) DEFAULT NULL,
                token_name VARCHAR(255) DEFAULT 'Mobile App',
                scopes VARCHAR(255) DEFAULT 'read,write',
                device_name VARCHAR(255) DEFAULT '',
                is_revoked TINYINT(1) DEFAULT 0,
                expires_at DATETIME DEFAULT NULL,
                last_used_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY token (token),
                UNIQUE KEY idx_api_tokens_hash (token_hash),
                KEY idx_api_tokens_user (user_id),
                KEY idx_api_tokens_expires (expires_at),
                KEY idx_api_tokens_revoked (is_revoked)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        // كشف الأعمدة الموجودة
        $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
        $existing = [];
        while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['Field'];
        }

        // إضافة الأعمدة المفقودة (بدون AFTER — أبسط وأكثر موثوقية)
        $columns = [
            'token_hash' => "ALTER TABLE api_tokens ADD COLUMN token_hash VARCHAR(64) DEFAULT NULL",
            'token_name' => "ALTER TABLE api_tokens ADD COLUMN token_name VARCHAR(255) DEFAULT 'Mobile App'",
            'scopes'     => "ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(255) DEFAULT 'read,write'",
        ];
        foreach ($columns as $col => $sql) {
            if (!in_array($col, $existing, true)) {
                try { $pdo->exec($sql); } catch (\Throwable $e) { /* العمود موجود */ }
            }
        }

        // تعبئة token_hash للرموز القديمة المخزّنة نصاً — تبقى صالحة
        $pdo->exec("UPDATE api_tokens SET token_hash = SHA2(token, 256) WHERE token_hash IS NULL AND token IS NOT NULL AND token != ''");

        // فهرس UNIQUE على token_hash
        try { $pdo->exec("ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_hash (token_hash)"); } catch (\Throwable $e) { /* الفهرس موجود */ }
    } catch (\Throwable $e) {
        if (class_exists('ErrorHandler')) {
            ErrorHandler::logException($e, 'db_upgrade_6_3_6::apiTokensRepair');
        }
        // الإصلاح غير حرج لعمل التطبيق — لا نرمي الاستثناء حتى لا يفشل الترقيم
    }
}
