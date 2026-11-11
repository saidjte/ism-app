<?php
/**
 * Upgrade Helper Functions
 *
 * Helper functions for the upgrade system. These utilities handle version
 * comparison, database version tracking, and safe schema migrations
 * (ensuring columns, tables, and indexes exist without causing fatal errors).
 */

$_ensureTable_errors = [];

function compareVersions($v1, $v2) {
    $p1 = array_map('intval', explode('.', $v1));
    $p2 = array_map('intval', explode('.', $v2));
    for ($i = 0; max(count($p1), count($p2)) > $i; $i++) {
        $a = $p1[$i] ?? 0; $b = $p2[$i] ?? 0;
        if ($a !== $b) return $a < $b ? -1 : 1;
    }
    return 0;
}

function getCurrentDBVersion($pdo) {
    if (!$pdo) return '0.0.0';
    try {
        if ($pdo->query("SHOW TABLES LIKE 'system_settings'")->rowCount() == 0) return '0.0.0';
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute(['app_version']);
        $r = $s->fetch();
        return $r ? $r['setting_value'] : '0.0.0';
    } catch (\Throwable $e) { return '0.0.0'; }
}

function updateDBVersion($pdo, $version) {
    if ($pdo->query("SHOW TABLES LIKE 'system_settings'")->rowCount() == 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL, description VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP")
        ->execute(['app_version', $version, 'رقم إصدار التطبيق']);
}

function ensureCol($pdo, $table, $col, $def) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($col, $cols)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN $def");
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'upgrade-helpers::ensureCol');
    }
}

function ensureTable($pdo, $sql) {
    global $_ensureTable_errors;
    try {
        $pdo->exec($sql);
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'EnsureTable');
        $_ensureTable_errors[] = ErrorHandler::safeExceptionMessage($e, 'خطأ في إنشاء الجدول.');
    }
}

function ensureIndex($pdo, $sql) {
    try { $pdo->exec($sql); } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'upgrade-helpers::ensureIndex');
    }
}

function getEnsureTableErrors() {
    global $_ensureTable_errors;
    return $_ensureTable_errors;
}
