<?php
/**
 * Bug Fix Functions & System Health Check
 *
 * Contains functions for applying targeted bug fixes and running
 * comprehensive system health checks on the application.
 *
 * Dependencies:
 *   - upgrade-constants.php : $FILE_CHECKS, $DB_UPDATES, $BUG_FIXES, $REQUIRED_TABLES
 *   - upgrade-helpers.php  : getCurrentDBVersion(), compareVersions(), ensureTable(), getEnsureTableErrors()
 */

// ====================================================================
//                دوال إصلاح الأخطاء
// ====================================================================

function fix_notif_include($baseDir) {
    // الملفات التي تستخدم AppNotification — تحتاج إدراج notifications.php
    // بعد v4.9.75: الملفات التي تستخدم bootstrap.php لا تحتاج إدراج منفصل
    // لأن bootstrap.php يُحمّل notifications.php تلقائياً
    $files = [
        'sales/edit-sale.php',
        'sales/delete-sale.php',
        'purchases/edit-purchase.php',
        'purchases/delete-purchase.php',
        'users/list-users.php',
        'users/add-user.php',
    ];
    $line = "require_once '../includes/notifications.php';";
    $backupLine = "require_once __DIR__ . '/../includes/notifications.php';";
    $fixed = [];
    foreach ($files as $file) {
        $path = $baseDir . '/' . $file;
        if (!file_exists($path)) { $fixed[] = ['file' => $file, 'status' => 'missing']; continue; }
        $content = file_get_contents($path);
        // إذا الملف يستخدم bootstrap.php → notifications.php مُحمّل تلقائياً → لا حاجة لإصلاح
        if (strpos($content, 'bootstrap.php') !== false) {
            $fixed[] = ['file' => $file, 'status' => 'already_fixed_via_bootstrap'];
            continue;
        }
        if (strpos($content, "require_once '../includes/notifications.php'") !== false ||
            strpos($content, "require_once __DIR__ . '/../includes/notifications.php'") !== false) {
            $fixed[] = ['file' => $file, 'status' => 'already_fixed'];
            continue;
        }
        // أضف السطر بعد آخر require_once في أعلى الملف
        $includeLine = (dirname($file) === '.' || dirname($file) === 'config')
            ? $backupLine : $line;
        $content = str_replace(
            "require_once '../includes/csrf.php';",
            "require_once '../includes/csrf.php';\n" . $includeLine,
            $content
        );
        if (strpos($content, $includeLine) !== false) {
            file_put_contents($path, $content);
            $fixed[] = ['file' => $file, 'status' => 'fixed'];
        } else {
            $fixed[] = ['file' => $file, 'status' => 'failed'];
        }
    }
    // config/backup.php — لا يستخدم bootstrap.php، يحتاج إدراج مباشر
    $bp = $baseDir . '/config/backup.php';
    if (file_exists($bp)) {
        $content = file_get_contents($bp);
        if (strpos($content, 'notifications.php') === false) {
            $content = str_replace(
                "<?php\n// ===== نظام النسخ الاحتياطي =====",
                "<?php\n// ===== نظام النسخ الاحتياطي =====\n\nrequire_once __DIR__ . '/../includes/notifications.php';",
                $content
            );
            file_put_contents($bp, $content);
            $fixed[] = ['file' => 'config/backup.php', 'status' => 'fixed'];
        } else {
            $fixed[] = ['file' => 'config/backup.php', 'status' => 'already_fixed'];
        }
    }
    return $fixed;
}

function fix_remises_table($pdo) {
    ensureTable($pdo, "CREATE TABLE IF NOT EXISTS purchase_remises (
        id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT NOT NULL,
        type ENUM('material', 'financial') NOT NULL,
        product_id INT NULL, quantity DECIMAL(12,2) NULL, unit_price DECIMAL(12,2) NULL,
        amount DECIMAL(12,2) NULL, description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return true;
}

/**
 * إصلاح الجداول المفقودة — يعيد تنفيذ كل دوال الترقية بالترتيب
 * بما أن كل ensureTable() تستخدم CREATE TABLE IF NOT EXISTS،
 * فإن الجداول الموجودة لن تتأثر وسيتم إنشاء المفقودة فقط
 * لا يُغيّر إصدار قاعدة البيانات
 * @return array سجل العمليات
 */
function repairTables($pdo) {
    global $DB_UPDATES;
    $log = [];
    foreach ($DB_UPDATES as $u) {
        $func = 'db_upgrade_' . str_replace('.', '_', $u['version']);
        if (!function_exists($func)) continue;
        $_ensureTable_errors = [];
        try {
            call_user_func($func, $pdo);
            $tableErrors = getEnsureTableErrors();
            $entry = ['ver' => $u['version'], 'desc' => $u['desc'], 'ok' => true];
            if (!empty($tableErrors)) {
                $entry['warnings'] = implode('; ', $tableErrors);
            }
            $log[] = $entry;
        } catch (\Throwable $e) {
            ErrorHandler::logException($e, "RepairTable_v{$u['version']}");
            $log[] = ['ver' => $u['version'], 'desc' => $u['desc'], 'ok' => false, 'err' => ErrorHandler::safeExceptionMessage($e, 'خطأ في الإصلاح.')];
        }
    }
    return $log;
}

// ====================================================================
//                فحص شامل
// ====================================================================

function runFullCheck($pdo, $baseDir) {
    global $REQUIRED_TABLES, $FILE_CHECKS, $DB_UPDATES, $BUG_FIXES;
    $dbVer = getCurrentDBVersion($pdo);

    // فحص الجداول
    $existing = []; $missing = [];
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($REQUIRED_TABLES as $t) {
            if (in_array($t, $tables)) $existing[] = $t; else $missing[] = $t;
        }
    } catch (\Throwable $e) { $missing = $REQUIRED_TABLES; }

    // فحص الملفات
    $totalFiles = 0; $presentFiles = 0; $missingFiles = [];
    foreach ($FILE_CHECKS as $ver => $files) {
        foreach ($files as $file => $desc) {
            $totalFiles++;
            if (file_exists($baseDir . '/' . $file)) $presentFiles++;
            else $missingFiles[] = ['version' => $ver, 'file' => $file, 'desc' => $desc];
        }
    }

    // التحديثات المعلقة
    $pendingDb = [];
    foreach ($DB_UPDATES as $u) {
        if (compareVersions($u['version'], $dbVer) > 0) $pendingDb[] = $u;
    }

    // إصلاحات الأخطاء المعلقة
    $pendingFixes = [];
    foreach ($BUG_FIXES as $fix) {
        // تحقق من أن كل إصلاح تم تطبيقه بناء على الملفات
        $fixApplied = false;
        if ($fix['id'] === 'fix_notif_include') {
            // بعد v4.9.75: bootstrap.php يُحمّل notifications.php تلقائياً
            // فالملف يُعتبر مُصلحاً إذا كان يستخدم bootstrap.php أو يُدرج notifications.php مباشرة
            $checkFiles = ['sales/edit-sale.php', 'sales/delete-sale.php', 'purchases/edit-purchase.php', 'purchases/delete-purchase.php', 'users/list-users.php', 'users/add-user.php', 'config/backup.php'];
            $allFixed = true;
            foreach ($checkFiles as $cf) {
                $path = $baseDir . '/' . $cf;
                if (!file_exists($path)) continue;
                $c = file_get_contents($path);
                $hasNotifInclude = (strpos($c, 'notifications.php') !== false);
                $hasBootstrap = (strpos($c, 'bootstrap.php') !== false);
                if (!$hasNotifInclude && !$hasBootstrap) { $allFixed = false; break; }
            }
            $fixApplied = $allFixed;
        } elseif ($fix['id'] === 'fix_remises_table') {
            try {
                $fixApplied = $pdo->query("SHOW TABLES LIKE 'purchase_remises'")->rowCount() > 0;
            } catch (\Throwable $e) { $fixApplied = false; }
        }
        if (!$fixApplied) $pendingFixes[] = $fix;
    }

    $isDbOk = empty($pendingDb);
    $isFilesOk = empty($missingFiles);
    $isFixesOk = empty($pendingFixes);
    $isTablesOk = empty($missing);
    $isAllOk = $isDbOk && $isFilesOk && $isFixesOk && $isTablesOk;

    // ===== تنظيف الملفات المؤقتة الخطرة =====
    // حذف fix-password.php إذا وُجد (سكربت مؤقت لإصلاح كلمة مرور MySQL)
    $dangerousFiles = [
        $baseDir . '/fix-password.php',
    ];
    foreach ($dangerousFiles as $dangerFile) {
        if (file_exists($dangerFile)) {
            @unlink($dangerFile);
        }
    }

    return [
        'db_version' => $dbVer,
        'app_version' => APP_VERSION,
        'is_db_ok' => $isDbOk,
        'is_files_ok' => $isFilesOk,
        'is_fixes_ok' => $isFixesOk,
        'is_tables_ok' => $isTablesOk,
        'is_all_ok' => $isAllOk,
        'pending_db' => $pendingDb,
        'pending_fixes' => $pendingFixes,
        'existing_tables' => $existing,
        'missing_tables' => $missing,
        'total_tables' => count($REQUIRED_TABLES),
        'present_files' => $presentFiles,
        'total_files' => $totalFiles,
        'missing_files' => $missingFiles,
    ];
}
