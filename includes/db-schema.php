<?php
/**
 * ========================================================================
 *  ملف هيكلة قاعدة البيانات المركزي (مُوقَّف — للتوافق فقط)
 *
 *  ⚠️ منذ v4.9.76: كل عمليات DDL (CREATE TABLE, ALTER TABLE)
 *  نُقلت إلى upgrade.php ونظام التحديثات.
 *
 *  هذا الملف يبقى للتوافق مع الإصدارات القديمة فقط.
 *  لا يُنفَّذ أي DDL تلقائياً — يجب تشغيل upgrade.php لإنشاء الجداول.
 *
 *  الجداول التي كانت تُنشأ هنا (نُقلت إلى upgrade.php):
 *  - purchase_remises  → db_upgrade_4_9_0() + db_upgrade_4_9_76()
 *  - caisse            → db_upgrade_4_9_76()
 *  - caisse_transactions → db_upgrade_4_9_76()
 *  - ip_rate_limits    → db_upgrade_4_9_76()
 *  - purchases.tax_*   → db_upgrade_4_9_0() + db_upgrade_4_9_76()
 *  - users.password_changed_at → db_upgrade_4_9_3() + db_upgrade_4_9_76()
 * ========================================================================
 */

// منع التحميل المزدوج
if (defined('DB_SCHEMA_LOADED')) return;
define('DB_SCHEMA_LOADED', true);

if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/ErrorHandler.php'; }

/**
 * دوال مساعدة للتوافق — تُستخدم من بعض الإصدارات القديمة
 * هذه الدوال لا تُنفَّذ تلقائياً بل تُستدعى فقط من upgrade.php
 */
function _ensureCol($pdo, $table, $col, $def) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($col, $cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $def");
        }
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'db-schema::_ensureCol'); }
}

function _ensureTable($pdo, $sql) {
    try { $pdo->exec($sql); } catch (\Throwable $e) { ErrorHandler::logException($e, 'db-schema::_ensureTable'); }
}

function _ensureIndex($pdo, $sql) {
    try { $pdo->exec($sql); } catch (\Throwable $e) { ErrorHandler::logException($e, 'db-schema::_ensureIndex'); }
}

/**
 * initDBSchema() — مُوقَّفة منذ v4.9.76
 * كل DDL نُقل إلى upgrade.php — لا حاجة لإنشاء الجداول في كل طلب
 * الدالة تبقى فارغة للتوافق مع أي كود قديم يستدعيها
 */
function initDBSchema($pdo) {
    // مُوقَّفة — كل DDL نُقل إلى upgrade.php
    // راجع: db_upgrade_4_9_76() لقائمة الجداول المنقولة
}

// === لم يعد يتم التطبيق التلقائي ===
// كان السطر التالي يُنفَّذ في كل طلب:
//   $pdo = AppDatabase::getInstance()->getConnection();
//   initDBSchema($pdo);
// الآن تم إيقافه — يجب تشغيل upgrade.php لإنشاء/تحديث الجداول
