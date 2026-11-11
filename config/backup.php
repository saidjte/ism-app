<?php
// ===== نظام النسخ الاحتياطي =====
// v4.26.0 — دعم mysqldump مع تراجع تلقائي + ضغط gzip أصلي
// v4.31.0 — إضافة حماية من إعادة تعريف الدوال (Cannot redeclare)

// منع التحميل المزدوج — يمنع خطأ "Cannot redeclare" عند تضمين الملف من عدة أماكن
if (defined('BACKUP_FUNCTIONS_LOADED')) return;
define('BACKUP_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/../includes/notifications.php';
if (!class_exists('ErrorHandler')) { require_once __DIR__ . '/../includes/ErrorHandler.php'; }

// ملفات النسخ تُحفظ في مجلد فرعي حتى لا تتأثر بـ .htaccess
define('BACKUP_DIR', dirname(__DIR__) . '/backups/files');
define('MAX_BACKUP_FILES', 30);
define('BACKUP_BATCH_SIZE', 200);

// === طرق النسخ الاحتياطي ===
define('BACKUP_METHOD_MYSQLDUMP', 'mysqldump');
define('BACKUP_METHOD_PHP', 'php');
define('BACKUP_METHOD_AUTO', 'auto'); // محاولة mysqldump أولاً، تراجع إلى PHP

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
 * التحقق من أن اسم قاعدة البيانات آمن للاستخدام في استعلامات SQL
 * يسمح فقط بالأحرف الأبجدية الرقمية والشرطة السفلية والشرطة العادية
 * @param string $name اسم قاعدة البيانات
 * @return string الاسم بعد التحقق
 * @throws RuntimeException إذا كان الاسم غير آمن
 */
function validateDatabaseName($name) {
    // السماح فقط بالأحرف الأبجدية الرقمية والشرطة السفلية والشرطة العادية
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        throw new RuntimeException('اسم قاعدة البيانات يحتوي على أحرف غير مسموح بها');
    }
    return $name;
}

/**
 * حجم قاعدة البيانات (MB)
 */
function getDatabaseSizeMB($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = ?");
        $stmt->execute([DB_NAME]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? floatval($row['size']) : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

// ========================================================
// === كشف توفر mysqldump ===
// ========================================================

/**
 * البحث عن مسار mysqldump المتاح على النظام
 *
 * يبحث بالترتيب:
 * 1. مسار مخصص من الإعدادات (backup_mysqldump_path)
 * 2. مسارات WAMP الشائعة على Windows
 * 3. المسار في PATH عبر which/where
 *
 * @return string|false مسار mysqldump أو false إذا لم يتوفر
 */
function findMysqldumpPath() {
    // 1. مسار مخصص من الإعدادات
    $customPath = getSetting('backup_mysqldump_path', '');
    if (!empty($customPath)) {
        if (is_executable($customPath)) {
            return $customPath;
        }
        // محاولة إضافة .exe على Windows
        if (DIRECTORY_SEPARATOR === '\\' && !str_ends_with(strtolower($customPath), '.exe')) {
            if (is_executable($customPath . '.exe')) {
                return $customPath . '.exe';
            }
        }
    }

    // 2. مسارات WAMP الشائعة على Windows
    if (DIRECTORY_SEPARATOR === '\\') {
        $wampPaths = selfFindWampMysqldump();
        if ($wampPaths !== false) {
            return $wampPaths;
        }
    }

    // 3. البحث في PATH عبر which (Linux/Mac) أو where (Windows)
    $cmd = DIRECTORY_SEPARATOR === '\\' ? 'where mysqldump' : 'which mysqldump 2>/dev/null';
    $output = [];
    $returnCode = -1;
    @exec($cmd, $output, $returnCode);
    if ($returnCode === 0 && !empty($output[0])) {
        $found = trim($output[0]);
        if (is_executable($found)) {
            return $found;
        }
    }

    return false;
}

/**
 * البحث عن mysqldump في مسارات WAMP الشائعة
 * @return string|false
 */
function selfFindWampMysqldump() {
    // المسارات الشائعة لـ WAMP
    $possiblePaths = [];

    // WAMP64
    $wampDir = 'C:\\wamp64';
    if (is_dir($wampDir)) {
        // البحث في مجلدات MySQL الإصدارات
        $mysqlBase = $wampDir . '\\bin\\mysql';
        if (is_dir($mysqlBase)) {
            $dirs = glob($mysqlBase . '\\mysql*', GLOB_ONLYDIR);
            if ($dirs) {
                usort($dirs, function($a, $b) {
                    return version_compare(basename($b), basename($a), '>');
                });
                foreach ($dirs as $dir) {
                    $possiblePaths[] = $dir . '\\bin\\mysqldump.exe';
                }
            }
        }
    }

    // WAMP32
    $wampDir = 'C:\\wamp';
    if (is_dir($wampDir)) {
        $mysqlBase = $wampDir . '\\bin\\mysql';
        if (is_dir($mysqlBase)) {
            $dirs = glob($mysqlBase . '\\mysql*', GLOB_ONLYDIR);
            if ($dirs) {
                usort($dirs, function($a, $b) {
                    return version_compare(basename($b), basename($a), '>');
                });
                foreach ($dirs as $dir) {
                    $possiblePaths[] = $dir . '\\bin\\mysqldump.exe';
                }
            }
        }
    }

    // XAMPP
    $xamppPaths = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'D:\\xampp\\mysql\\bin\\mysqldump.exe',
    ];
    $possiblePaths = array_merge($possiblePaths, $xamppPaths);

    // Laragon
    $laragonDir = 'C:\\laragon';
    if (is_dir($laragonDir)) {
        $mysqlBase = $laragonDir . '\\bin\\mysql';
        if (is_dir($mysqlBase)) {
            $dirs = glob($mysqlBase . '\\mysql*', GLOB_ONLYDIR);
            if ($dirs) {
                usort($dirs, function($a, $b) {
                    return version_compare(basename($b), basename($a), '>');
                });
                foreach ($dirs as $dir) {
                    $possiblePaths[] = $dir . '\\bin\\mysqldump.exe';
                }
            }
        }
    }

    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    return false;
}

/**
 * التحقق من توفر mysqldump وإمكانية استخدامه
 *
 * @return array ['available' => bool, 'path' => string, 'version' => string, 'error' => string]
 */
function checkMysqldumpAvailability() {
    $result = [
        'available' => false,
        'path' => '',
        'version' => '',
        'error' => '',
    ];

    $path = findMysqldumpPath();
    if ($path === false) {
        $result['error'] = 'لم يتم العثور على mysqldump — سيتم استخدام طريقة PHP';
        return $result;
    }

    // التحقق من أن الملف قابل للتنفيذ
    if (!is_executable($path)) {
        $result['error'] = "mysqldump موجود في {$path} لكنه غير قابل للتنفيذ";
        return $result;
    }

    // التحقق من أن exec() متاح
    if (!function_exists('exec')) {
        $result['path'] = $path;
        $result['error'] = 'دالة exec() معطّلة في PHP — لا يمكن استخدام mysqldump';
        return $result;
    }

    // التحقق من أن proc_open متاح (للتحكم الأفضل)
    if (!function_exists('proc_open')) {
        // يمكن العمل بـ exec() فقط لكن proc_open أفضل
    }

    // محاولة تشغيل mysqldump --version
    $output = [];
    $returnCode = -1;
    @exec('"' . $path . '" --version 2>&1', $output, $returnCode);
    if ($returnCode !== 0) {
        $result['path'] = $path;
        $result['error'] = 'فشل تنفيذ mysqldump (رمز الخروج: ' . $returnCode . ')';
        return $result;
    }

    $versionStr = implode("\n", $output);
    // استخراج رقم الإصدار
    if (preg_match('/(\d+\.\d+\.\d+)/', $versionStr, $m)) {
        $result['version'] = $m[1];
    }

    $result['available'] = true;
    $result['path'] = $path;
    return $result;
}

/**
 * الحصول على طريقة النسخ الفعّالة حسب الإعدادات والتوفر
 *
 * @return array ['method' => string, 'mysqldump_path' => string, 'fallback_used' => bool]
 */
function getEffectiveBackupMethod() {
    $configured = getSetting('backup_method', BACKUP_METHOD_AUTO);

    if ($configured === BACKUP_METHOD_PHP) {
        return ['method' => BACKUP_METHOD_PHP, 'mysqldump_path' => '', 'fallback_used' => false];
    }

    // محاولة mysqldump (auto أو mysqldump)
    $check = checkMysqldumpAvailability();
    if ($check['available']) {
        return [
            'method' => BACKUP_METHOD_MYSQLDUMP,
            'mysqldump_path' => $check['path'],
            'fallback_used' => ($configured === BACKUP_METHOD_MYSQLDUMP) ? false : false,
        ];
    }

    // التراجع إلى PHP
    return [
        'method' => BACKUP_METHOD_PHP,
        'mysqldump_path' => '',
        'fallback_used' => ($configured !== BACKUP_METHOD_PHP),
    ];
}

// ========================================================
// === إنشاء نسخة احتياطية ===
// ========================================================

/**
 * إنشاء نسخة احتياطية
 *
 * @param string $description وصف اختياري
 * @return string اسم الملف المنشأ
 * @throws RuntimeException عند الفشل
 */
function createBackup($description = '') {
    ensureBackupDir();

    $pdo = getDBConnection();
    if (!$pdo) {
        throw new RuntimeException('فشل الاتصال بقاعدة البيانات');
    }

    $timestamp = date('Y-m-d_His');
    $filename = "backup_{$timestamp}.sql";
    $filepath = BACKUP_DIR . "/{$filename}";
    $uncompressed_size = 0;
    $backup_method_used = BACKUP_METHOD_PHP;

    // تحديد طريقة النسخ
    $effective = getEffectiveBackupMethod();
    $mysqldumpSuccess = false;

    if ($effective['method'] === BACKUP_METHOD_MYSQLDUMP) {
        // === محاولة النسخ عبر mysqldump ===
        $mysqldumpResult = createBackupWithMysqldump($effective['mysqldump_path'], $filepath);
        if ($mysqldumpResult['success']) {
            $mysqldumpSuccess = true;
            $backup_method_used = BACKUP_METHOD_MYSQLDUMP;
            $filepath = $mysqldumpResult['filepath'];
            $filename = basename($filepath);
            $uncompressed_size = $mysqldumpResult['uncompressed_size'];
        } else {
            // فشل mysqldump — تراجع إلى PHP
            ErrorHandler::logMessage('mysqldump فشل: ' . ($mysqldumpResult['error'] ?? 'خطأ غير معروف') . ' — التراجع إلى طريقة PHP', 'backup::createBackup');
            // حذف أي ملفات جزئية
            if (file_exists($filepath)) @unlink($filepath);
            if (file_exists($filepath . '.gz')) @unlink($filepath . '.gz');
        }
    }

    if (!$mysqldumpSuccess) {
        // === النسخ بطريقة PHP (الأصلية) ===
        generateBackupSQL($pdo, $filepath);

        // التحقق من صحة الملف
        if (!file_exists($filepath) || filesize($filepath) < 50) {
            @unlink($filepath);
            throw new RuntimeException('فشل إنشاء النسخة الاحتياطية');
        }

        $uncompressed_size = filesize($filepath);

        // ضغط الملف عبر gzencode
        if (function_exists('gzencode')) {
            $compressed = compressWithGzencode($filepath);
            if ($compressed !== false) {
                @unlink($filepath);
                $filename .= '.gz';
                $filepath = $compressed;
            }
        }
    }

    // حفظ البيانات الوصفية
    $tables_count = 0;
    try { $tables_count = count($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)); } catch (\Throwable $e) { ErrorHandler::logException($e, 'backup::createBackup_tablesCount'); }

    $metadata = [
        'description' => $description,
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'النظام',
        'app_version' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
        'filesize' => filesize($filepath),
        'filesize_uncompressed' => $uncompressed_size,
        'tables_count' => $tables_count,
        'db_name' => DB_NAME,
        'backup_method' => $backup_method_used,
        'compression' => (substr($filepath, -3) === '.gz') ? 'gzip' : 'none',
    ];
    @file_put_contents($filepath . '.meta.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // تنظيف النسخ القديمة
    cleanOldBackups();

    // تسجيل نشاط إنشاء النسخة الاحتياطية
    try {
        require_once __DIR__ . '/../includes/audit_log.php';
        $methodLabel = $backup_method_used === BACKUP_METHOD_MYSQLDUMP ? 'mysqldump' : 'PHP';
        addAuditLog('backup_create', "إنشاء نسخة احتياطية ({$methodLabel}): " . $filename, '', 'backup', 0);
    } catch (Exception $e) { ErrorHandler::logException($e, 'backup::createBackup_auditLog'); }

    // إشعار نسخة احتياطية
    try {
        $backupSize = formatBackupSize(filesize($filepath));
        $methodLabel = $backup_method_used === BACKUP_METHOD_MYSQLDUMP ? 'mysqldump' : 'PHP';
        $title_key = 'notif_title_backup';
        $message_key = 'notif_msg_backup';
        $message_params = ['method' => $methodLabel, 'filename' => $filename, 'size' => $backupSize];
        AppNotification::add(NOTIF_BACKUP, t($title_key), t($message_key, $methodLabel, $filename, $backupSize), null, null, $title_key, $message_key, $message_params);
    } catch (Exception $e) { ErrorHandler::logException($e, 'backup::createBackup_notification'); }

    return $filename;
}

/**
 * إنشاء نسخة احتياطية باستخدام mysqldump
 *
 * @param string $mysqldumpPath مسار mysqldump
 * @param string $targetFilePath مسار ملف SQL الهدف (بدون .gz)
 * @return array ['success' => bool, 'filepath' => string, 'uncompressed_size' => int, 'error' => string]
 */
function createBackupWithMysqldump($mysqldumpPath, $targetFilePath) {
    $result = [
        'success' => false,
        'filepath' => $targetFilePath,
        'uncompressed_size' => 0,
        'error' => '',
    ];

    // التحقق من أمان اسم قاعدة البيانات
    $safeDbName = validateDatabaseName(DB_NAME);

    // بناء أمر mysqldump
    $command = buildMysqldumpCommand($mysqldumpPath, $safeDbName, $targetFilePath);

    // تنفيذ الأمر
    $output = [];
    $returnCode = -1;

    // استخدام proc_open للتحكم الأفضل في العمليات
    if (function_exists('proc_open')) {
        $procResult = executeWithProcOpen($command);
        $returnCode = $procResult['returnCode'];
        $stderr = $procResult['stderr'];
    } else {
        // تراجع إلى exec()
        @exec($command, $output, $returnCode);
        $stderr = implode("\n", $output);
    }

    if ($returnCode !== 0) {
        $result['error'] = "mysqldump فشل برمز الخروج {$returnCode}: " . mb_substr($stderr, 0, 200);
        return $result;
    }

    // التحقق من أن الملف أُنشئ
    if (!file_exists($targetFilePath) || filesize($targetFilePath) < 50) {
        $result['error'] = 'mysqldump لم يُنشئ ملفاً صالحاً';
        if (file_exists($targetFilePath)) @unlink($targetFilePath);
        return $result;
    }

    $uncompressed_size = filesize($targetFilePath);

    // ضغط gzip — محاولة استخدام gzip الأصلي أولاً، ثم gzencode
    $gzResult = compressBackupFile($targetFilePath);
    if ($gzResult !== false) {
        $result['filepath'] = $gzResult;
    }

    $result['success'] = true;
    $result['uncompressed_size'] = $uncompressed_size;
    return $result;
}

/**
 * بناء أمر mysqldump
 *
 * @param string $mysqldumpPath مسار mysqldump
 * @param string $dbName اسم قاعدة البيانات
 * @param string $targetFilePath مسار الملف الهدف
 * @return string الأمر الكامل
 */
function buildMysqldumpCommand($mysqldumpPath, $dbName, $targetFilePath) {
    // خيارات mysqldump
    $options = [
        '--routines',          // إجراءات مخزنة
        '--triggers',          // مشغلات
        '--events',            // أحداث
        '--single-transaction', // تناسق InnoDB بدون قفل
        '--set-charset',       // تحديد الترميز
        '--default-character-set=utf8mb4',
        '--no-tablespaces',    // تجنب مشاكل الصلاحيات
    ];

    // اتصال قاعدة البيانات
    $host = escapeshellarg(DB_HOST);
    $user = escapeshellarg(DB_USER);
    $pass = DB_PASS;

    // بناء سطر الأوامر
    // كلمة المرور عبر ملف مؤقت (أمان أفضل من سطر الأوامر)
    $usePasswordFile = false;
    $passwordFile = '';

    if (!empty($pass)) {
        // إنشاء ملف مؤقت لكلمة المرور (أمان أفضل من --password= في سطر الأوامر)
        $passwordFile = sys_temp_dir() . '/.mysqldump_' . uniqid() . '.cnf';
        $cnfContent = "[client]\npassword=" . $pass . "\n";
        if (@file_put_contents($passwordFile, $cnfContent) !== false) {
            @chmod($passwordFile, 0600); // صلاحيات مقيدة
            $usePasswordFile = true;
        }
    }

    $cmd = '"' . $mysqldumpPath . '"';

    if ($usePasswordFile) {
        $cmd .= ' --defaults-extra-file=' . escapeshellarg($passwordFile);
    } elseif (!empty($pass)) {
        // تراجع: تمرير كلمة المرور مباشرة (أقل أماناً لكن يعمل)
        $cmd .= ' --password=' . escapeshellarg($pass);
    }

    $cmd .= ' --host=' . $host;
    $cmd .= ' --user=' . $user;
    $cmd .= ' ' . implode(' ', $options);
    $cmd .= ' ' . escapeshellarg($dbName);

    // توجيه الإخراج إلى ملف
    $cmd .= ' > ' . escapeshellarg($targetFilePath);

    // تسجيل مؤقت كلمة المرور للحذف لاحقاً
    if ($usePasswordFile) {
        $GLOBALS['_mysqldump_password_file'] = $passwordFile;
    }

    return $cmd;
}

/**
 * تنفيذ أمر عبر proc_open مع التقاط stderr
 *
 * @param string $command الأمر
 * @return array ['returnCode' => int, 'stdout' => string, 'stderr' => string]
 */
function executeWithProcOpen($command) {
    $result = [
        'returnCode' => -1,
        'stdout' => '',
        'stderr' => '',
    ];

    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $process = @proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        $result['stderr'] = 'فشل تشغيل proc_open';
        return $result;
    }

    // إغلاق stdin
    fclose($pipes[0]);

    // قراءة الإخراج
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $result['returnCode'] = proc_close($process);
    $result['stdout'] = $stdout;
    $result['stderr'] = $stderr;

    // حذف ملف كلمة المرور المؤقت
    if (isset($GLOBALS['_mysqldump_password_file']) && file_exists($GLOBALS['_mysqldump_password_file'])) {
        @unlink($GLOBALS['_mysqldump_password_file']);
        unset($GLOBALS['_mysqldump_password_file']);
    }

    return $result;
}

/**
 * الحصول على مجلد tmp النظام
 * @return string
 */
function sys_temp_dir() {
    $tmp = sys_get_temp_dir();
    if (empty($tmp) || !is_writable($tmp)) {
        $tmp = BACKUP_DIR . '/../tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    }
    return $tmp;
}

// ========================================================
// === ضغط النسخ الاحتياطية ===
// ========================================================

/**
 * ضغط ملف النسخة — محاولة gzip الأصلي أولاً، ثم gzencode
 *
 * @param string $filepath مسار ملف SQL
 * @return string|false مسار الملف المضغوط أو false
 */
function compressBackupFile($filepath) {
    // الطريقة 1: استخدام أمر gzip الأصلي (أسرع بكثير مع الملفات الكبيرة)
    $nativeGz = compressWithNativeGzip($filepath);
    if ($nativeGz !== false) {
        return $nativeGz;
    }

    // الطريقة 2: استخدام gzencode في PHP
    if (function_exists('gzencode')) {
        return compressWithGzencode($filepath);
    }

    return false;
}

/**
 * ضغط باستخدام أمر gzip الأصلي (سطر الأوامر)
 *
 * @param string $filepath مسار ملف SQL
 * @return string|false مسار الملف المضغوط أو false عند الفشل
 */
function compressWithNativeGzip($filepath) {
    if (!function_exists('exec')) {
        return false;
    }

    // التحقق من توفر gzip
    $testCmd = DIRECTORY_SEPARATOR === '\\' ? 'where gzip 2>nul' : 'which gzip 2>/dev/null';
    $output = [];
    $returnCode = -1;
    @exec($testCmd, $output, $returnCode);
    if ($returnCode !== 0) {
        return false; // gzip غير متاح
    }

    // تنفيذ الضغط
    // استخدام gzip -c للإبقاء على الملف الأصلي
    $gzPath = $filepath . '.gz';
    $cmd = 'gzip -c -9 ' . escapeshellarg($filepath) . ' > ' . escapeshellarg($gzPath) . ' 2>&1';
    @exec($cmd, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($gzPath) || filesize($gzPath) === 0) {
        // فشل — حذف أي ملف جزئي
        if (file_exists($gzPath)) @unlink($gzPath);
        return false;
    }

    // التحقق من أن الملف المضغوط أصغر
    if (filesize($gzPath) >= filesize($filepath)) {
        @unlink($gzPath);
        return false;
    }

    // حذف الملف الأصلي بعد نجاح الضغط
    @unlink($filepath);

    return $gzPath;
}

/**
 * ضغط باستخدام gzencode في PHP
 *
 * @param string $filepath مسار ملف SQL
 * @return string|false مسار الملف المضغوط أو false عند الفشل
 */
function compressWithGzencode($filepath) {
    $data = @file_get_contents($filepath);
    if ($data === false || strlen($data) === 0) {
        return false;
    }

    $compressed = @gzencode($data, 9);
    if ($compressed === false) {
        return false;
    }

    // التحقق من أن النتيجة المضغوطة أصغر
    if (strlen($compressed) >= strlen($data)) {
        return false;
    }

    $gzPath = $filepath . '.gz';
    $written = @file_put_contents($gzPath, $compressed);
    if ($written === false || $written === 0) {
        @unlink($gzPath);
        return false;
    }

    return $gzPath;
}

// ========================================================
// === إنشاء نسخة احتياطية باستخدام PHP (الأصلية) ===
// ========================================================

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
    fwrite($fp, "-- الطريقة: PHP\n");
    fwrite($fp, "-- =============================================\n\n");

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fp, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    // التحقق من أمان اسم قاعدة البيانات قبل الكتابة في ملف SQL
    $safe_db_name = validateDatabaseName(DB_NAME);
    fwrite($fp, "CREATE DATABASE IF NOT EXISTS `{$safe_db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
    fwrite($fp, "USE `{$safe_db_name}`;\n\n");

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // التحقق من أمان اسم الجدول - السماح فقط بالأحرف الأبجدية الرقمية والشرطة السفلية
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            continue; // تخطي الجداول ذات الأسماء المشبوهة
        }
        $safe_table = $table; // اسم الجدول آمن بعد التحقق

        // بنية الجدول
        $create_row = $pdo->query("SHOW CREATE TABLE `{$safe_table}`")->fetch(PDO::FETCH_ASSOC);
        if (!$create_row) continue;
        $create_sql = $create_row['Create Table'];

        fwrite($fp, "-- ------------------------------------------\n");
        fwrite($fp, "-- بنية: `{$safe_table}`\n");
        fwrite($fp, "-- ------------------------------------------\n");
        fwrite($fp, "DROP TABLE IF EXISTS `{$safe_table}`;\n");
        fwrite($fp, $create_sql . ";\n\n");

        // بيانات الجدول
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$safe_table}`")->fetchColumn();
        if ($count > 0) {
            fwrite($fp, "-- بيانات: `{$safe_table}` ({$count} سجل)\n");

            $offset = 0;
            $batch = BACKUP_BATCH_SIZE;
            $columns = null;

            while ($offset < $count) {
                $stmt = $pdo->query("SELECT * FROM `{$safe_table}` LIMIT {$offset}, {$batch}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) break;

                if ($columns === null) {
                    $columns = array_keys($rows[0]);
                    // التحقق من أمان أسماء الأعمدة
                    $columns = array_filter($columns, function($col) {
                        return preg_match('/^[a-zA-Z0-9_]+$/', $col);
                    });
                }

                $col_str = '`' . implode('`, `', $columns) . '`';

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
            $errMsg = mb_substr($e->getMessage(), 0, 120);
            ErrorHandler::logException($e, 'BackupSQL');
            // تجاهل بعض الأخطاء المتوقعة
            if (strpos($upper, 'SET ') === 0) continue;
            if (strpos($upper, 'CREATE DATABASE') === 0) continue;
            if (strpos($errMsg, 'already exists') !== false) continue;
            $errors[] = ErrorHandler::safeExceptionMessage($e, 'خطأ في تنفيذ استعلام النسخ الاحتياطي.');
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
            'backup_method' => $meta['backup_method'] ?? 'php',
            'compression' => $meta['compression'] ?? 'none',
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
    // استخدام إعداد backup_retention_count إن وُجد، وإلا MAX_BACKUP_FILES
    $maxFiles = MAX_BACKUP_FILES;
    try {
        $settingMax = intval(getSetting('backup_retention_count', '0'));
        if ($settingMax > 0) $maxFiles = $settingMax;
    } catch (\Throwable $e) { ErrorHandler::logException($e, 'backup::cleanOldBackups_getRetention'); }

    $backups = listBackups();
    if (count($backups) <= $maxFiles) return 0;

    $deleted = 0;
    $to_delete = array_slice($backups, $maxFiles);
    foreach ($to_delete as $backup) {
        try {
            deleteBackup($backup['filename']);
            $deleted++;
        } catch (\Throwable $e) { ErrorHandler::logException($e, 'backup::cleanOldBackups_delete'); }
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

    $interval = getSetting('auto_backup_frequency', 'daily');
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
        } catch (\Throwable $e) { ErrorHandler::logException($e, 'backup::checkAutoBackup'); }
    }
}
?>
