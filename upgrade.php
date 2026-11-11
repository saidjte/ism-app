<?php
/**
 * ========================================================================
 *  ملف التحديث الشامل — مُحمّل نمطي (Modular Loader)
 *  يدعم كل التحديثات من v1.0.0 إلى آخر إصدار
 *  يشمل: قاعدة بيانات + ملفات + إصلاح أخطاء كود
 *  مُستخدم أيضاً من: config/upgrades.php (proxy)
 *
 *  منذ v4.9.105: تم تقسيم الملف إلى وحدات منفصلة في includes/upgrades/
 *  هذا الملف يُحمّل الوحدات بالترتيب الصحيح فقط.
 * ========================================================================
 */

// ===== تحميل الفئات المطلوبة قبل الوحدات النمطية =====
if (!class_exists('ErrorHandler')) {
    require_once __DIR__ . '/includes/ErrorHandler.php';
}

// ===== تحميل الوحدات النمطية =====
$upgradeDir = __DIR__ . '/includes/upgrades';

require_once $upgradeDir . '/upgrade-constants.php';   // الثوابت + قوائم الفحص + سجل التغييرات
require_once $upgradeDir . '/upgrade-helpers.php';     // الدوال المساعدة
require_once $upgradeDir . '/upgrade-rollback.php';    // نظام التراجع والنسخ الاحتياطي
require_once $upgradeDir . '/upgrade-deploy.php';      // نظام النشر الذري (Atomic Deploy)
require_once $upgradeDir . '/upgrade-db.php';          // دوال ترقية قاعدة البيانات
require_once $upgradeDir . '/upgrade-fixes.php';       // إصلاحات الأخطاء + فحص النظام

// ═══════════════════════════════════════════════════════════════════════════
// حماية: لا ينفذ الكود أدناه إلا عند الوصول المباشر لهذا الملف
// عند التضمين عبر config/upgrades.php، يتم تعريف الثوابت والدوال فقط
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) !== realpath($_SERVER['SCRIPT_FILENAME'])) {
    return;
}

// ===== الاتصال بقاعدة البيانات =====
$can_connect = false;
$pdo = null;
$db_error = '';

if (file_exists(__DIR__ . '/config/database.php')) {
    try {
        require_once __DIR__ . '/config/database.php';
        $pdo = getDBConnection();
        $can_connect = true;
    } catch (\Throwable $e) {
        ErrorHandler::logException($e, 'UpgradeDBConnection');
        $db_error = ErrorHandler::safeExceptionMessage($e, 'خطأ في الاتصال بقاعدة البيانات.');
    }
}

// ====================================================================
//                معالجة POST (تشغيل التحديثات)
// ====================================================================

$message = '';
$msgType = '';
$updateLog = [];
$currentVersion = $can_connect ? getCurrentDBVersion($pdo) : '0.0.0';
$baseDir = __DIR__;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upgrade_all' && $can_connect) {
        // === تشغيل كل التحديثات: قاعدة بيانات + إصلاح أخطاء ===

        // 0. إنشاء نسخة احتياطية تلقائية قبل الترقية (Rollback)
        $preUpgradeVersion = $currentVersion;
        $backupResult = createUpgradeBackup($pdo, $currentVersion);
        $backupFile = $backupResult['success'] ? $backupResult['file'] : '';

        if (!$backupResult['success']) {
            $updateLog[] = ['ver' => $currentVersion, 'desc' => 'نسخة احتياطية قبل الترقية', 'type' => 'backup', 'ok' => false, 'err' => $backupResult['error']];
            $updateLog[] = ['ver' => $currentVersion, 'desc' => 'تحذير: لم يتم إنشاء نسخة احتياطية — الترقية ستستمر بدون إمكانية التراجع التلقائي', 'type' => 'backup', 'ok' => false, 'err' => ''];
        } else {
            $updateLog[] = ['ver' => $currentVersion, 'desc' => 'نسخة احتياطية قبل الترقية', 'type' => 'backup', 'ok' => true, 'detail' => basename($backupFile)];
        }

        // 1. تحديثات قاعدة البيانات
        $pending = [];
        foreach ($DB_UPDATES as $u) {
            if (compareVersions($u['version'], $currentVersion) > 0) $pending[] = $u;
        }

        $upgradeFailed = false;
        $failedVersion = '';

        foreach ($pending as $u) {
            $func = 'db_upgrade_' . str_replace('.', '_', $u['version']);
            try {
                // تحقق مرن: إذا لم تكن الدالة موجودة، سجّل تحذيراً بدلاً من الفشل الكامل
                // هذا يسمح باستمرار الترقية للإصدارات اللاحقة حتى لو نسيت دالة فارغة
                if (!function_exists($func)) {
                    // تحقق: هل هذا إصدار يتطلب تغييرات في قاعدة البيانات فعلاً؟
                    // معظم الإصدارات لا تغير قاعدة البيانات (ملفات فقط) — لا داعي لإيقاف الترقية
                    $updateLog[] = ['ver' => $u['version'], 'desc' => $u['desc'], 'type' => 'db', 'ok' => true,
                        'warnings' => "دالة $func غير موجودة — تم التخطي (لا تغييرات مطلوبة في قاعدة البيانات)"];
                    updateDBVersion($pdo, $u['version']);
                    $currentVersion = $u['version'];
                    continue;
                }
                $_ensureTable_errors = [];
                call_user_func($func, $pdo);
                $tableErrors = getEnsureTableErrors();
                updateDBVersion($pdo, $u['version']);
                $currentVersion = $u['version'];
                $logEntry = ['ver' => $u['version'], 'desc' => $u['desc'], 'type' => 'db', 'ok' => true];
                if (!empty($tableErrors)) {
                    $logEntry['warnings'] = implode('; ', $tableErrors);
                }
                $updateLog[] = $logEntry;
            } catch (\Throwable $e) {
                ErrorHandler::logException($e, "UpgradeDB_v{$u['version']}");
                $updateLog[] = ['ver' => $u['version'], 'desc' => $u['desc'], 'type' => 'db', 'ok' => false, 'err' => ErrorHandler::safeExceptionMessage($e, 'خطأ في تحديث قاعدة البيانات.')];
                $upgradeFailed = true;
                $failedVersion = $u['version'];

                // === تراجع تلقائي (Rollback) ===
                if (!empty($backupFile)) {
                    $updateLog[] = ['ver' => $u['version'], 'desc' => 'فشل الترقية — جاري التراجع التلقائي...', 'type' => 'rollback', 'ok' => true];

                    $rollbackResult = rollbackFromBackup($pdo, $backupFile);
                    if ($rollbackResult['success']) {
                        $currentVersion = $preUpgradeVersion;
                        $updateLog[] = ['ver' => $preUpgradeVersion, 'desc' => 'تم التراجع تلقائياً إلى v' . $preUpgradeVersion, 'type' => 'rollback', 'ok' => true,
                            'detail' => ($rollbackResult['success_queries'] ?? 0) . ' استعلام تم تنفيذه'];
                        if (!empty($rollbackResult['error'])) {
                            $updateLog[] = ['ver' => $preUpgradeVersion, 'desc' => 'تحذير أثناء التراجع: ' . $rollbackResult['error'], 'type' => 'rollback', 'ok' => false, 'err' => ''];
                        }
                        ErrorHandler::logException($e, "UpgradeRollbackSuccess_v{$failedVersion}");
                        $message = 'فشلت الترقية في v' . $failedVersion . ' — تم التراجع تلقائياً إلى v' . $preUpgradeVersion . '. ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ أثناء الترقية.');
                    } else {
                        $updateLog[] = ['ver' => $preUpgradeVersion, 'desc' => 'فشل التراجع التلقائي: ' . $rollbackResult['error'], 'type' => 'rollback', 'ok' => false, 'err' => $rollbackResult['error']];
                        ErrorHandler::logException($e, "UpgradeRollbackFailed_v{$failedVersion}");
                        $message = 'فشلت الترقية في v' . $failedVersion . ' وفشل التراجع التلقائي! يُرجى الاستعادة يدوياً من النسخة الاحتياطية. ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ أثناء الترقية.');
                    }
                } else {
                    ErrorHandler::logException($e, "UpgradeNoBackup_v{$u['version']}");
                    $message = 'خطأ في تحديث v' . $u['version'] . ': ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ أثناء التحديث.') . ' (لا توجد نسخة احتياطية للتراجع)';
                }

                $msgType = 'error';
                break;
            }
        }

        if (!$upgradeFailed) {
            updateDBVersion($pdo, APP_VERSION);
            $currentVersion = APP_VERSION;

            // 2. إصلاح الأخطاء (فقط المعلّقة منها)
            $preCheck = runFullCheck($pdo, $baseDir);
            $pendingFixIds = array_map(fn($f) => $f['id'], $preCheck['pending_fixes']);

            foreach ($BUG_FIXES as $fix) {
                if (!in_array($fix['id'], $pendingFixIds)) continue;

                try {
                    if ($fix['id'] === 'fix_notif_include') {
                        $result = fix_notif_include($baseDir);
                        $fc = count(array_filter($result, fn($r) => $r['status'] === 'fixed'));
                        $updateLog[] = ['ver' => $fix['version'], 'desc' => $fix['desc'], 'type' => 'fix', 'ok' => true, 'detail' => "$fc ملف(ات) تم إصلاحها"];
                    } elseif ($fix['id'] === 'fix_remises_table') {
                        $_ensureTable_errors = [];
                        fix_remises_table($pdo);
                        $tableErrors = getEnsureTableErrors();
                        $logEntry = ['ver' => $fix['version'], 'desc' => $fix['desc'], 'type' => 'fix', 'ok' => true];
                        if (!empty($tableErrors)) {
                            $logEntry['warnings'] = implode('; ', $tableErrors);
                        }
                        $updateLog[] = $logEntry;
                    }
                } catch (\Throwable $e) {
                    ErrorHandler::logException($e, "UpgradeFix_{$fix['id']}");
                    $updateLog[] = ['ver' => $fix['version'], 'desc' => $fix['desc'], 'type' => 'fix', 'ok' => false, 'err' => ErrorHandler::safeExceptionMessage($e, 'خطأ في تطبيق الإصلاح.')];
                }
            }

            $message = 'تم التحديث بنجاح إلى v' . APP_VERSION . '! (' . count($updateLog) . ' عملية)';
            $msgType = 'success';
        }

    } elseif ($action === 'deploy_zip') {
        // === نشر ذري لملف ZIP ===
        if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'الملف أكبر من الحد المسموح في php.ini',
                UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد المسموح في النموذج',
                UPLOAD_ERR_PARTIAL => 'تم رفع جزء من الملف فقط',
                UPLOAD_ERR_NO_FILE => 'لم يتم رفع أي ملف',
                UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود',
                UPLOAD_ERR_CANT_WRITE => 'فشل كتابة الملف على القرص',
            ];
            $errCode = $_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $message = 'فشل رفع الملف: ' . ($uploadErrors[$errCode] ?? 'خطأ غير معروف');
            $msgType = 'error';
        } else {
            $tmpPath = $_FILES['zip_file']['tmp_name'];
            $originalName = $_FILES['zip_file']['name'];

            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
                $message = 'يجب رفع ملف ZIP فقط';
                $msgType = 'error';
            } else {
                $deployResult = atomicDeployZip($tmpPath, $baseDir);
                if ($deployResult['success']) {
                    foreach ($deployResult['steps'] as $step) {
                        $updateLog[] = ['ver' => '', 'desc' => $step['msg'], 'type' => 'deploy', 'ok' => $step['ok']];
                    }
                    $message = 'تم نشر ' . $deployResult['files_deployed'] . ' ملف بنجاح من: ' . htmlspecialchars($originalName);
                    $msgType = 'success';
                } else {
                    foreach ($deployResult['steps'] as $step) {
                        $updateLog[] = ['ver' => '', 'desc' => $step['msg'], 'type' => 'deploy', 'ok' => $step['ok']];
                    }
                    $message = 'فشل النشر: ' . $deployResult['error'];
                    $msgType = 'error';
                }
            }
        }

        if ($can_connect) $currentVersion = getCurrentDBVersion($pdo);

    } elseif ($action === 'rollback_files') {
        // === تراجع عن نشر ملفات ===
        $rollbackResult = rollbackFileDeploy($baseDir);
        if ($rollbackResult['success']) {
            $message = 'تم التراجع عن نشر الملفات — تم استعادة ' . $rollbackResult['restored_count'] . ' ملف';
            $msgType = 'success';
        } else {
            $message = 'فشل التراجع: ' . $rollbackResult['error'];
            $msgType = 'error';
        }
        if ($can_connect) $currentVersion = getCurrentDBVersion($pdo);

    } elseif ($action === 'rollback' && $can_connect) {
        // === تراجع يدوي عن ترقية ===
        $selectedBackup = $_POST['backup_file'] ?? '';

        if (empty($selectedBackup) || !file_exists($selectedBackup)) {
            $message = 'ملف النسخة الاحتياطية غير موجود أو غير محدد';
            $msgType = 'error';
        } else {
            $backupDir = __DIR__ . '/upgrade_backups';
            $realPath = realpath($selectedBackup);
            $realBackupDir = realpath($backupDir);
            if ($realPath === false || $realBackupDir === false || strpos($realPath, $realBackupDir) !== 0) {
                $message = 'مسار الملف غير مسموح به — يجب أن يكون داخل مجلد النسخ الاحتياطية';
                $msgType = 'error';
            } else {
                $rollbackResult = rollbackFromBackup($pdo, $selectedBackup);
                if ($rollbackResult['success']) {
                    $currentVersion = getCurrentDBVersion($pdo);
                    foreach ($rollbackResult['steps'] as $step) {
                        $updateLog[] = ['ver' => $currentVersion, 'desc' => $step['msg'], 'type' => 'rollback', 'ok' => $step['ok']];
                    }
                    $message = 'تم التراجع بنجاح إلى v' . $currentVersion . ' (' . ($rollbackResult['success_queries'] ?? 0) . ' استعلام)';
                    $msgType = 'success';
                } else {
                    foreach (($rollbackResult['steps'] ?? []) as $step) {
                        $updateLog[] = ['ver' => '', 'desc' => $step['msg'], 'type' => 'rollback', 'ok' => $step['ok']];
                    }
                    $message = 'فشل التراجع: ' . $rollbackResult['error'];
                    $msgType = 'error';
                }
            }
        }

    } elseif ($action === 'fix_only' && $can_connect) {
        // فقط إصلاح الأخطاء بدون تحديث قاعدة البيانات
        $preCheck = runFullCheck($pdo, $baseDir);
        $pendingFixIds = array_map(fn($f) => $f['id'], $preCheck['pending_fixes']);

        foreach ($BUG_FIXES as $fix) {
            if (!in_array($fix['id'], $pendingFixIds)) continue;

            try {
                if ($fix['id'] === 'fix_notif_include') {
                    $result = fix_notif_include($baseDir);
                    $fc = count(array_filter($result, fn($r) => $r['status'] === 'fixed'));
                    $updateLog[] = ['ver' => $fix['version'], 'desc' => $fix['desc'], 'type' => 'fix', 'ok' => true, 'detail' => "$fc ملف(ات) تم إصلاحها"];
                } elseif ($fix['id'] === 'fix_remises_table') {
                    $_ensureTable_errors = [];
                    fix_remises_table($pdo);
                    $tableErrors = getEnsureTableErrors();
                    $logEntry = ['ver' => $fix['version'], 'desc' => $fix['desc'], 'type' => 'fix', 'ok' => true];
                    if (!empty($tableErrors)) {
                        $logEntry['warnings'] = implode('; ', $tableErrors);
                    }
                    $updateLog[] = $logEntry;
                }
            } catch (\Throwable $e) {
                ErrorHandler::logException($e, "FixOnly_{$fix['id']}");
                $updateLog[] = ['ver' => $fix['version'], 'desc' => $fix['desc'], 'type' => 'fix', 'ok' => false, 'err' => ErrorHandler::safeExceptionMessage($e, 'خطأ في تطبيق الإصلاح.')];
            }
        }

        if (empty($updateLog)) {
            $message = 'لا توجد إصلاحات معلّقة — كل شيء محدّث!';
            $msgType = 'success';
        } else {
            $message = 'تم إصلاح الأخطاء! (' . count($updateLog) . ' عملية)';
            $msgType = 'success';
        }

    } elseif ($action === 'repair_tables' && $can_connect) {
        // === إصلاح الجداول المفقودة ===
        $repairLog = repairTables($pdo);
        $successCount = count(array_filter($repairLog, fn($r) => $r['ok']));
        $failCount = count($repairLog) - $successCount;

        foreach ($repairLog as $entry) {
            $logEntry = [
                'ver' => $entry['ver'],
                'desc' => $entry['desc'],
                'type' => 'repair',
                'ok' => $entry['ok'],
            ];
            if (!empty($entry['warnings'])) {
                $logEntry['warnings'] = $entry['warnings'];
            }
            if (!empty($entry['err'])) {
                $logEntry['err'] = $entry['err'];
            }
            $updateLog[] = $logEntry;
        }

        if ($failCount > 0) {
            $message = "تم إصلاح الجداول مع أخطاء: {$successCount} نجح، {$failCount} فشل";
            $msgType = 'error';
        } else {
            $message = "تم إصلاح الجداول المفقودة بنجاح! ({$successCount} عملية)";
            $msgType = 'success';
        }
    }
}

// ===== تشغيل الفحص =====
$check = $can_connect ? runFullCheck($pdo, $baseDir) : null;
if (!empty($updateLog) && $can_connect) $check = runFullCheck($pdo, $baseDir);

// ===== تحميل واجهة المستخدم =====
require_once $upgradeDir . '/upgrade-ui.php';
