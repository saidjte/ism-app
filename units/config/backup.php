<?php
// ===== نظام النسخ الاحتياطي =====

// ملفات النسخ تُحفظ في مجلد فرعي حتى لا تتأثر بـ .htaccess
define('BACKUP_DIR', dirname(__DIR__) . '/backups/files');
define('MAX_BACKUP_FILES', 30);
define('BACKUP_BATCH_SIZE', 200);

/**
 * التأكد من وجود مجلد النسخ
 */
function ensureBackupDir() {
    // إنشاء المجلدات
    $dirs = [
        BACKUP_DIR,
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    // حماية مجلد files فقط (ملفات النسخ) - لا نضع .htaccess في backups/
    $htaccess = BACKUP_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "# منع الوصول المباشر لملفات النسخ\n<FilesMatch \"\\.(sql|sql\\.gz|meta\\.json)$\">\n    Require all denied\n</FilesMatch>\n");
    }

    // ملف فارغ لمنع عرض المجلد
    $index = BACKUP_DIR . '/index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, '');
    }
}

/**
 * تنسيق حجم الملف
 */
function formatBackupSize($bytes) {
    $bytes = max(0, intval($bytes));
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * حجم قاعدة البيانات (MB)
 */
function getDatabaseSizeMB($pdo) {
    try {
        $row = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = '" . addslashes(DB_NAME) . "'")->fetch(PDO::FETCH_ASSOC);
        return $row ? floatval($row['size']) : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

// ========================================================
// === إنشاء نسخة احتياطية ===
// ========================================================

function createBackup($description = '') {
    ensureBackupDir();

    $pdo = getDBConnection();
    if (!$pdo) {
        throw new RuntimeException('فشل الاتصال بقاعدة البيانات');
    }

    $timestamp = date('Y-m-d_His');
    $filename = "backup_{$timestamp}.sql";
    $filepath = BACKUP_DIR . "/{$filename}";

    // إنشاء النسخة باستخدام PHP (أكثر موثوقية عبر الأنظمة)
    generateBackupSQL($pdo, $filepath);

    // التحقق من صحة الملف
    if (!file_exists($filepath) || filesize($filepath) < 50) {
        @unlink($filepath);
        throw new RuntimeException('فشل إنشاء النسخة الاحتياطية');
    }

    // ضغط الملف
    $uncompressed_size = filesize($filepath);
    if (function_exists('gzencode')) {
        $data = @file_get_contents($filepath);
        if ($data !== false && strlen($data) > 0) {
            $compressed = @gzencode($data, 9);
            if ($compressed !== false && strlen($compressed) < strlen($data)) {
                $gz_path = $filepath . '.gz';
                @file_put_contents($gz_path, $compressed);
                @unlink($filepath);
                $filename .= '.gz';
                $filepath = $gz_path;
            }
        }
    }

    // حفظ البيانات الوصفية
    $tables_count = 0;
    try { $tables_count = count($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)); } catch (\Throwable $e) {}

    $metadata = [
        'description' => $description,
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'النظام',
        'app_version' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
        'filesize' => filesize($filepath),
        'filesize_uncompressed' => $uncompressed_size,
        'tables_count' => $tables_count,
        'db_name' => DB_NAME,
    ];
    @file_put_contents($filepath . '.meta.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // تنظيف النسخ القديمة
    cleanOldBackups();

    return $filename;
}

/**
 * إنشاء نسخة احتياطية باستخدام PHP فقط
 */
function generateBackupSQL($pdo, $filepath) {
    $fp = @fopen($filepath, 'w');
    if (!$fp) throw new RuntimeException('فشل إنشاء ملف النسخة الاحتياطية - تحقق من صلاحيات المجلد');

    fwrite($fp, "-- =============================================\n");
    fwrite($fp, "-- نظام إدارة المخزون - نسخة احتياطية\n");
    fwrite($fp, "-- التاريخ: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- قاعدة البيانات: " . DB_NAME . "\n");
    fwrite($fp, "-- الإصدار: " . (defined('APP_VERSION') ? APP_VERSION : 'unknown') . "\n");
    fwrite($fp, "-- =============================================\n\n");

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fp, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    fwrite($fp, "CREATE DATABASE IF NOT EXISTS `" . addslashes(DB_NAME) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
    fwrite($fp, "USE `" . addslashes(DB_NAME) . "`;\n\n");

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $safe_table = addslashes($table);

        // بنية الجدول
        $create_row = $pdo->query("SHOW CREATE TABLE `{$safe_table}`")->fetch(PDO::FETCH_ASSOC);
        if (!$create_row) continue;
        $create_sql = $create_row['Create Table'];

        fwrite($fp, "-- ------------------------------------------\n");
        fwrite($fp, "-- بنية: `{$table}`\n");
        fwrite($fp, "-- ------------------------------------------\n");
        fwrite($fp, "DROP TABLE IF EXISTS `{$safe_table}`;\n");
        fwrite($fp, $create_sql . ";\n\n");

        // بيانات الجدول
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$safe_table}`")->fetchColumn();
        if ($count > 0) {
            fwrite($fp, "-- بيانات: `{$table}` ({$count} سجل)\n");

            $offset = 0;
            $batch = BACKUP_BATCH_SIZE;
            $columns = null;

            while ($offset < $count) {
                $stmt = $pdo->query("SELECT * FROM `{$safe_table}` LIMIT {$offset}, {$batch}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) break;

                if ($columns === null) {
                    $columns = array_keys($rows[0]);
                }

                $col_str = '`' . implode('`, `', array_map('addslashes', $columns)) . '`';

                $values_batch = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = $pdo->quote($val);
                        }
                    }
                    $values_batch[] = '(' . implode(', ', $vals) . ')';
                }

                fwrite($fp, "INSERT INTO `{$safe_table}` ({$col_str}) VALUES\n");
                fwrite($fp, implode(",\n", $values_batch) . ";\n");

                $offset += $batch;
            }
            fwrite($fp, "\n");
        }
    }

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($fp, "-- نهاية النسخة الاحتياطية\n");

    fclose($fp);
}

// ========================================================
// === استعادة نسخة احتياطية ===
// ========================================================

/**
 * محلل SQL ذكي يحترم النصوص الحرفية (string literals)
 * يحل مشكلة explode(';') التي تكسر عند وجود فاصلة منقوطة داخل نص
 */
function parseSqlStatements($sql) {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];

        // تجاهل التعليقات
        if ($ch === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
            // تعليق سطر واحد - تخطي حتى نهاية السطر
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            if ($i < $len) $i++; // تخطي \n
            continue;
        }

        if ($ch === '/' && isset($sql[$i + 1]) && $sql[$i + 1] === '*') {
            // تعليق متعدد الأسطر
            $i += 2;
            while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i += 2; // تخطي */
            continue;
        }

        // نص حرفي (string literal)
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $current .= $ch;
            $i++;
            while ($i < $len) {
                if ($sql[$i] === '\\' && isset($sql[$i + 1])) {
                    $current .= $sql[$i] . $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $current .= $sql[$i];
                if ($sql[$i] === $quote) {
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }

        // فاصل الاستعلام
        if ($ch === ';') {
            $current .= ';';
            $trimmed = trim($current);
            if ($trimmed !== '' && $trimmed !== ';') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    // آخر استعلام إن وُجد
    $trimmed = trim($current);
    if ($trimmed !== '' && $trimmed !== ';') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * استعادة نسخة من ملف موجود
 */
function restoreBackup($filename) {
    ensureBackupDir();

    // أمان: منع المسارات غير المسموح بها
    $safe_name = basename($filename);
    if ($safe_name !== $filename) {
        throw new RuntimeException('اسم الملف غير صالح');
    }

    $filepath = BACKUP_DIR . '/' . $safe_name;
    if (!file_exists($filepath)) {
        throw new RuntimeException('ملف النسخة الاحتياطية غير موجود');
    }

    $sql = readBackupContent($filepath);
    if (empty($sql)) {
        throw new RuntimeException('ملف النسخة الاحتياطية فارغ أو تالف');
    }

    return executeSqlRestore($sql);
}

/**
 * استعادة من ملف مرفوع (upload)
 */
function restoreFromUpload($tmp_path, $original_name) {
    // التحقق من الامتداد
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext === 'gz') {
        $data = @file_get_contents($tmp_path);
        if ($data === false) throw new RuntimeException('فشل قراءة الملف المرفوع');
        $sql = @gzdecode($data);
        if ($sql === false) throw new RuntimeException('فشل فك ضغط الملف');
    } elseif ($ext === 'sql') {
        $sql = @file_get_contents($tmp_path);
        if ($sql === false) throw new RuntimeException('فشل قراءة الملف المرفوع');
    } else {
        throw new RuntimeException('نوع الملف غير مدعوم - يُسمح بـ .sql و .sql.gz فقط');
    }

    // فحص بسيط: هل يبدو كملف SQL صالح؟
    $upper_check = strtoupper(substr(trim($sql), 0, 100));
    if (strpos($upper_check, 'MYSQL') === false && strpos($upper_check, 'CREATE') === false && strpos($upper_check, 'INSERT') === false && strpos($upper_check, 'SET ') === false) {
        throw new RuntimeException('الملف لا يبدو كملف SQL صالح');
    }

    return executeSqlRestore($sql);
}

/**
 * تنفيذ استعادة SQL على قاعدة البيانات
 */
function executeSqlRestore($sql) {
    $pdo = getDBConnection();
    if (!$pdo) {
        throw new RuntimeException('فشل الاتصال بقاعدة البيانات');
    }

    $statements = parseSqlStatements($sql);
    $executed = 0;
    $errors = [];

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

    foreach ($statements as $statement) {
        $upper = strtoupper(trim($statement));
        if (empty($upper) || $upper === ';') continue;

        try {
            $pdo->exec($statement);
            $executed++;
        } catch (\Throwable $e) {
            $rawMsg = $e->getMessage();
            $errMsg = mb_substr($rawMsg, 0, 120);
            // تجاهل بعض الأخطاء المتوقعة
            if (strpos($upper, 'SET ') === 0) continue;
            if (strpos($upper, 'CREATE DATABASE') === 0) continue;
            if (strpos($errMsg, 'already exists') !== false) continue;
            ErrorHandler::logException($e, 'restore_sql_statement');
            $errors[] = mb_substr(ErrorHandler::safeExceptionMessage($e, 'خطأ في تنفيذ استعلام SQL'), 0, 120);
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    return [
        'executed' => $executed,
        'errors' => $errors,
        'total' => count($statements),
    ];
}

/**
 * قراءة محتوى النسخة (مع فك الضغط)
 */
function readBackupContent($filepath) {
    if (function_exists('str_ends_with') && str_ends_with($filepath, '.gz')) {
        $data = @file_get_contents($filepath);
        if ($data === false) return '';
        $content = @gzdecode($data);
        return $content !== false ? $content : '';
    }
    // توافق PHP < 8.0
    if (substr($filepath, -3) === '.gz') {
        $data = @file_get_contents($filepath);
        if ($data === false) return '';
        $content = @gzdecode($data);
        return $content !== false ? $content : '';
    }
    return @file_get_contents($filepath);
}

// ========================================================
// === حذف / تحميل / قائمة ===
// ========================================================

function deleteBackup($filename) {
    $safe_name = basename($filename);
    $filepath = BACKUP_DIR . '/' . $safe_name;

    if (!file_exists($filepath)) {
        throw new RuntimeException('ملف النسخة غير موجود');
    }

    @unlink($filepath);
    @unlink($filepath . '.meta.json');
    return true;
}

function listBackups() {
    ensureBackupDir();

    $backups = [];
    $files = @glob(BACKUP_DIR . '/backup_*');
    if (!$files) $files = [];

    foreach ($files as $filepath) {
        // تخطي مجلدات وملفات meta
        if (is_dir($filepath)) continue;
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($ext === 'json') continue;

        $filename = basename($filepath);
        $meta_file = $filepath . '.meta.json';

        $meta = [];
        if (file_exists($meta_file)) {
            $meta = @json_decode(@file_get_contents($meta_file), true) ?: [];
        }

        $backups[] = [
            'filename' => $filename,
            'filesize' => @filesize($filepath) ?: 0,
            'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s', @filemtime($filepath) ?: time()),
            'description' => $meta['description'] ?? '',
            'created_by' => $meta['created_by'] ?? '',
            'app_version' => $meta['app_version'] ?? '',
            'tables_count' => $meta['tables_count'] ?? 0,
            'filesize_uncompressed' => $meta['filesize_uncompressed'] ?? 0,
        ];
    }

    // ترتيب من الأحدث
    usort($backups, function($a, $b) {
        return strcmp($b['filename'], $a['filename']);
    });

    return $backups;
}

function downloadBackup($filename) {
    $safe_name = basename($filename);
    $filepath = BACKUP_DIR . '/' . $safe_name;

    if (!file_exists($filepath)) {
        throw new RuntimeException('ملف النسخة غير موجود');
    }

    // فك الضغط للتحميل
    if (substr($safe_name, -3) === '.gz') {
        $content = @gzdecode(@file_get_contents($filepath));
        if ($content !== false) {
            $download_name = substr($safe_name, 0, -3);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes($download_name) . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-cache, must-revalidate');
            echo $content;
            exit;
        }
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($safe_name) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($filepath);
    exit;
}

function cleanOldBackups() {
    $backups = listBackups();
    if (count($backups) <= MAX_BACKUP_FILES) return 0;

    $deleted = 0;
    $to_delete = array_slice($backups, MAX_BACKUP_FILES);
    foreach ($to_delete as $backup) {
        try {
            deleteBackup($backup['filename']);
            $deleted++;
        } catch (\Throwable $e) {}
    }
    return $deleted;
}

function getBackupStorageUsed() {
    ensureBackupDir();
    $total = 0;
    $files = @glob(BACKUP_DIR . '/backup_*');
    if ($files) {
        foreach ($files as $f) {
            if (is_file($f) && substr(basename($f), -5) !== '.json') {
                $total += @filesize($f) ?: 0;
            }
        }
    }
    return $total;
}

/**
 * التحقق من النسخ التلقائي وإنشاء نسخة إن لزم
 */
function checkAutoBackup() {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') return;

    $enabled = getSetting('auto_backup_enabled', '0');
    if ($enabled !== '1') return;

    $interval = getSetting('auto_backup_interval', 'daily');
    $last_backup = getSetting('last_auto_backup', '');

    $should_backup = false;
    if (empty($last_backup)) {
        $should_backup = true;
    } else {
        $last_time = strtotime($last_backup);
        $now = time();
        switch ($interval) {
            case 'daily':   $should_backup = ($now - $last_time) >= 86400; break;
            case 'weekly':  $should_backup = ($now - $last_time) >= 604800; break;
            case 'monthly': $should_backup = ($now - $last_time) >= 2592000; break;
        }
    }

    if ($should_backup) {
        try {
            $labels = ['daily' => 'يومية', 'weekly' => 'أسبوعية', 'monthly' => 'شهرية'];
            $filename = createBackup('نسخة تلقائية - ' . ($labels[$interval] ?? $interval));
            saveSetting('last_auto_backup', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {}
    }
}
?>
