<?php
/**
 * Upgrade Rollback / Backup Subsystem
 *
 * Provides functions for creating pre-upgrade database backups,
 * restoring (rolling back) from those backups, and managing
 * the lifecycle of backup files.
 *
 * Extracted from upgrade.php — rollback/backup subsystem.
 */

// ====================================================================
//                نظام التراجع (Rollback) عن الترقيات
// ====================================================================

/**
 * إنشاء نسخة احتياطية تلقائية قبل الترقية
 * يحفظ هيكل وبيانات جميع الجداول + رقم الإصدار الحالي
 *
 * @param PDO $pdo اتصال قاعدة البيانات
 * @param string $currentVersion رقم الإصدار الحالي قبل الترقية
 * @return array ['success' => bool, 'file' => string, 'error' => string]
 */
function createUpgradeBackup($pdo, $currentVersion) {
    $backupDir = __DIR__ . '/upgrade_backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
        // حماية مجلد النسخ الاحتياطية
        if (!file_exists($backupDir . '/.htaccess')) {
            file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
        }
        if (!file_exists($backupDir . '/index.php')) {
            file_put_contents($backupDir . '/index.php', "<?php http_response_code(403); exit;\n");
        }
    }

    $timestamp = date('Ymd_His');
    $backupFile = $backupDir . "/pre_upgrade_v{$currentVersion}_{$timestamp}.sql";

    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            return ['success' => false, 'file' => '', 'error' => 'لا توجد جداول لنسخها'];
        }

        $sql = "-- نسخة احتياطية تلقائية قبل الترقية\n";
        $sql .= "-- الإصدار الحالي: {$currentVersion}\n";
        $sql .= "-- التاريخ: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- عدد الجداول: " . count($tables) . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            // هيكل الجدول
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? $createRow[array_keys($createRow)[1]] ?? '';
            if ($createSql) {
                $sql .= "-- هيكل الجدول: {$table}\n";
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createSql . ";\n\n";
            }

            // بيانات الجدول
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $sql .= "-- بيانات الجدول: {$table} (" . count($rows) . " صف)\n";
                $columns = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $columns) . '`';

                // تقسيم البيانات إلى مجموعات لتجنب استهلاك الذاكرة
                $batchSize = 100;
                $batches = array_chunk($rows, $batchSize);
                foreach ($batches as $batch) {
                    $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
                    $values = [];
                    foreach ($batch as $row) {
                        $vals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = $pdo->quote($val);
                            }
                        }
                        $values[] = '(' . implode(', ', $vals) . ')';
                    }
                    $sql .= implode(",\n", $values) . ";\n\n";
                }
            } else {
                $sql .= "-- الجدول {$table} فارغ\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        // ضغط البيانات لتوفير المساحة
        $compressed = gzencode($sql, 6);
        if ($compressed === false) {
            // إذا فشل الضغط، احفظ بدون ضغط
            $backupFile = $backupDir . "/pre_upgrade_v{$currentVersion}_{$timestamp}.sql";
            $written = file_put_contents($backupFile, $sql);
        } else {
            $backupFile = $backupDir . "/pre_upgrade_v{$currentVersion}_{$timestamp}.sql.gz";
            $written = file_put_contents($backupFile, $compressed);
        }

        if ($written === false) {
            return ['success' => false, 'file' => '', 'error' => 'فشل كتابة ملف النسخة الاحتياطية'];
        }

        // حفظ بيانات وصفية (metadata) عن النسخة الاحتياطية
        $metadata = [
            'version_before' => $currentVersion,
            'timestamp' => date('Y-m-d H:i:s'),
            'file' => basename($backupFile),
            'compressed' => $compressed !== false,
            'tables_count' => count($tables),
            'tables' => $tables,
            'file_size' => filesize($backupFile),
            'app_version_at_backup' => APP_VERSION,
        ];
        file_put_contents($backupDir . "/pre_upgrade_v{$currentVersion}_{$timestamp}.json", json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // تنظيف النسخ القديمة (الاحتفاظ بآخر 5 نسخ فقط)
        cleanOldUpgradeBackups($backupDir, 5);

        return ['success' => true, 'file' => $backupFile, 'error' => '', 'metadata' => $metadata];

    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'CreateUpgradeBackup');
        return ['success' => false, 'file' => '', 'error' => ErrorHandler::safeExceptionMessage($e, 'خطأ في إنشاء النسخة الاحتياطية.')];
    }
}

/**
 * تنظيف النسخ الاحتياطية القديمة لعمليات الترقية
 *
 * @param string $backupDir مسار مجلد النسخ
 * @param int $keepCount عدد النسخ للإبقاء عليها
 */
function cleanOldUpgradeBackups($backupDir, $keepCount = 5) {
    $backups = [];
    foreach (glob($backupDir . '/pre_upgrade_*.json') as $metaFile) {
        $backups[] = [
            'meta' => $metaFile,
            'time' => filemtime($metaFile),
            'base' => basename($metaFile, '.json'),
        ];
    }

    // ترتيب من الأقدم للأحدث
    usort($backups, fn($a, $b) => $a['time'] - $b['time']);

    // حذف النسخ الزائدة
    $toDelete = array_slice($backups, 0, max(0, count($backups) - $keepCount));
    foreach ($toDelete as $backup) {
        $baseName = $backup['base'];
        // حذف ملف metadata
        @unlink($backup['meta']);
        // حذف ملف SQL (مضغوط أو عادي)
        @unlink($backupDir . '/' . $baseName . '.sql');
        @unlink($backupDir . '/' . $baseName . '.sql.gz');
    }
}

/**
 * استعادة من نسخة احتياطية (Rollback)
 *
 * @param PDO $pdo اتصال قاعدة البيانات
 * @param string $backupFile مسار ملف النسخة الاحتياطية
 * @return array ['success' => bool, 'error' => string, 'steps' => array]
 */
function rollbackFromBackup($pdo, $backupFile) {
    $steps = [];
    $log = function($msg, $ok = true) use (&$steps) {
        $steps[] = ['msg' => $msg, 'ok' => $ok];
    };

    try {
        if (!file_exists($backupFile)) {
            return ['success' => false, 'error' => 'ملف النسخة الاحتياطية غير موجود', 'steps' => $steps];
        }

        // قراءة محتوى الملف
        $content = file_get_contents($backupFile);
        if ($content === false) {
            return ['success' => false, 'error' => 'فشل قراءة ملف النسخة الاحتياطية', 'steps' => $steps];
        }

        // فك الضغط إذا كان مضغوطاً
        if (substr($backupFile, -3) === '.gz') {
            $decompressed = gzdecode($content);
            if ($decompressed === false) {
                return ['success' => false, 'error' => 'فشل فك ضغط النسخة الاحتياطية', 'steps' => $steps];
            }
            $content = $decompressed;
        }

        $log('تم قراءة ملف النسخة الاحتياطية (' . round(strlen($content) / 1024) . ' KB)');

        // تعطيل فحص المفاتيح الخارجية أثناء الاستعادة
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $log('تم تعطيل فحص المفاتيح الخارجية');

        // تقسيم SQL إلى استعلامات وتنفيذها
        // دعم الفواصل: ; مع مراعاة النصوص بين علامات الاقتباس
        $queries = splitSqlQueries($content);
        $totalQueries = count($queries);
        $successQueries = 0;
        $failedQueries = 0;

        $log("عدد الاستعلامات: {$totalQueries}");

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query) || strpos($query, '--') === 0) continue;

            try {
                $pdo->exec($query);
                $successQueries++;
            } catch (\Throwable $e) {
                $failedQueries++;
                // تسجيل الخطأ لكن نستمر (بعض الأخطاء غير حرجة مثل DROP TABLE لجدول غير موجود)
                if ($failedQueries <= 10) {
                    ErrorHandler::logException($e, 'RollbackQueryWarning');
                    $log("تحذير: " . ErrorHandler::safeExceptionMessage($e, 'خطأ في تنفيذ استعلام.'), false);
                }
            }
        }

        // إعادة تفعيل فحص المفاتيح الخارجية
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        $log('تم إعادة تفعيل فحص المفاتيح الخارجية');

        $log("اكتملت الاستعادة: {$successQueries} نجح، {$failedQueries} فشل");

        // قراءة metadata لاستعادة رقم الإصدار
        $metaFile = substr($backupFile, 0, -4) . '.json';
        if (!file_exists($metaFile) && substr($backupFile, -3) === '.gz') {
            $metaFile = substr($backupFile, 0, -7) . '.json';
        }

        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if ($meta && isset($meta['version_before'])) {
                updateDBVersion($pdo, $meta['version_before']);
                $log('تم استعادة رقم الإصدار إلى v' . $meta['version_before']);
            }
        }

        // تسجيل عملية التراجع في سجل النشاطات
        $log('تم التراجع عن الترقية بنجاح');

        return [
            'success' => $failedQueries < $successQueries, // نجاح إذا كانت أغلب الاستعلامات ناجحة
            'error' => $failedQueries > 0 ? "تنبيه: {$failedQueries} استعلام فشل من أصل {$totalQueries}" : '',
            'steps' => $steps,
            'success_queries' => $successQueries,
            'failed_queries' => $failedQueries,
        ];

    } catch (\Throwable $e) {
        // محاولة إعادة تفعيل المفاتيح الخارجية حتى عند الخطأ
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Throwable $e2) { ErrorHandler::logException($e2, 'upgrade-rollback::reEnableFK'); }

        ErrorHandler::logException($e, 'RollbackFromBackup');
        return ['success' => false, 'error' => ErrorHandler::safeExceptionMessage($e, 'خطأ أثناء التراجع.'), 'steps' => $steps];
    }
}

/**
 * تقسيم نص SQL إلى استعلامات منفصلة
 * يراعي النصوص بين علامات الاقتباس والتعليقات
 *
 * @param string $sql نص SQL كامل
 * @return array مصفوفة من الاستعلامات
 */
function splitSqlQueries($sql) {
    $queries = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        // معالجة النصوص بين علامات الاقتباس
        if (!$inString && ($char === "'" || $char === '"')) {
            $inString = true;
            $stringChar = $char;
            $current .= $char;
        } elseif ($inString && $char === $stringChar) {
            // التحقق من أنه ليس حرف هروب
            if ($i > 0 && $sql[$i - 1] !== '\\') {
                $inString = false;
            }
            $current .= $char;
        } elseif (!$inString && $char === ';') {
            // نهاية استعلام
            $query = trim($current);
            if (!empty($query)) {
                $queries[] = $query;
            }
            $current = '';
        } else {
            $current .= $char;
        }
    }

    // إضافة آخر استعلام إذا لم ينتهِ بفاصلة منقوطة
    $lastQuery = trim($current);
    if (!empty($lastQuery)) {
        $queries[] = $lastQuery;
    }

    return $queries;
}

/**
 * الحصول على قائمة النسخ الاحتياطية المتاحة للتراجع
 *
 * @return array مصفوفة من بيانات النسخ الاحتياطية
 */
function getAvailableRollbacks() {
    $backupDir = __DIR__ . '/upgrade_backups';
    if (!is_dir($backupDir)) return [];

    $backups = [];
    foreach (glob($backupDir . '/pre_upgrade_*.json') as $metaFile) {
        $content = file_get_contents($metaFile);
        if ($content === false) continue;
        $meta = json_decode($content, true);
        if (!$meta) continue;

        // التحقق من وجود ملف SQL المقابل
        $sqlFile = $backupDir . '/' . $meta['file'];
        if (!file_exists($sqlFile)) continue;

        $meta['sql_file'] = $sqlFile;
        $meta['meta_file'] = $metaFile;
        $meta['file_size_formatted'] = formatFileSize(filesize($sqlFile));
        $backups[] = $meta;
    }

    // ترتيب من الأحدث للأقدم
    usort($backups, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

    return $backups;
}

/**
 * تنسيق حجم الملف
 *
 * @param int $bytes حجم الملف بالبايت
 * @return string الحجم المنسق
 */
function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
