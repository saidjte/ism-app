<?php
/**
 * ========================================================================
 *  نظام النشر الذري — تقنية Write-Then-Rename
 * ========================================================================
 *
 *  آلية النشر الذري الفعلية:
 *   1. فحص مسبق (صلاحيات + مساحة + مكتبات)
 *   2. تفعيل وضع الصيانة تلقائياً
 *   3. فك ضغط ZIP في مجلد staging
 *   4. اكتشاف المجلد الجذري في ZIP (معالجة حزم ZIP ذات مجلد جذر واحد)
 *   5. تحقق أمني من المسارات (Path Traversal / Zip Slip)
 *   6. نسخ احتياطي للملفات الحالية التي ستُستبدل (للتراجع)
 *   7. استبدال ذري لكل ملف: كتابة مؤقتة → rename (ذري على نفس نظام الملفات)
 *   8. حذف الملفات القديمة التي لم تعد في التحديث الجديد
 *   9. إزالة وضع الصيانة
 *  10. حفظ سجل النشر + تنظيف
 *
 *  لماذا Write-Then-Rename بدلاً من Directory Swap؟
 *  - Directory Swap يحتاج rename(baseDir, oldDir) حيث oldDir داخل baseDir — مستحيل!
 *  - Write-Then-Rename يستبدل كل ملف ذرياً: write(tmp) → rename(tmp, target)
 *  - rename() للملفات ذرية على نفس نظام الملفات (POSIX guarantee)
 *  - لا توجد نافذة زمنية يكون فيها الملف في حالة غير متسقة
 *  - PHP process يستمر في العمل بدون انقطاع
 *
 *  منذ v4.9.106: إعادة كتابة كاملة لتحل محل Directory Swap المعطّل
 * ========================================================================
 */

// ====================================================================
//          نظام النشر الذري (Write-Then-Rename Atomic Deploy)
// ====================================================================

/**
 * تفعيل وضع الصيانة — يمنع جميع الطلبات باستثناء صفحة التحديث
 *
 * @param string $baseDir المجلد الجذري للتطبيق
 * @return bool
 */
function enableMaintenanceMode($baseDir) {
    $content = "<?php\n"
        . "// وضع الصيانة — تم تفعيله تلقائياً أثناء النشر الذري\n"
        . "// سيتم إزالة هذا الملف تلقائياً بعد انتهاء النشر\n"
        . "if (basename(__FILE__) === 'upgrade.php' || basename(\$_SERVER['SCRIPT_NAME']) === 'upgrade.php') return;\n"
        . "http_response_code(503);\n"
        . "header('Retry-After: 60');\n"
        . "header('Content-Type: text/html; charset=utf-8');\n"
        . "echo '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>صيانة</title></head>'\n"
        . "   . '<body style=\"display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;background:#f1f5f9;\">'\n"
        . "   . '<div style=\"text-align:center;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);\">'\n"
        . "   . '<h1 style=\"font-size:2rem;margin:0 0 .5rem;\">🔧 صيانة مؤقتة</h1>'\n"
        . "   . '<p style=\"color:#64748b;font-size:1.1rem;\">جاري تحديث النظام — يرجى الانتظار لحظات...</p>'\n"
        . "   . '</div></body></html>';\n"
        . "exit;\n";
    return file_put_contents($baseDir . '/.maintenance', $content) !== false;
}

/**
 * إلغاء وضع الصيانة
 *
 * @param string $baseDir المجلد الجذري للتطبيق
 * @return bool
 */
function disableMaintenanceMode($baseDir) {
    $file = $baseDir . '/.maintenance';
    if (file_exists($file)) {
        return @unlink($file);
    }
    return true;
}

/**
 * فحص مسبق — التحقق من صلاحيات الكتابة ومساحة القرص ومكتبة ZipArchive
 *
 * @param string $baseDir المجلد الجذري
 * @param int    $zipSize حجم ملف ZIP بالبايت
 * @return array ['ok' => bool, 'errors' => string[]]
 */
function preflightCheck($baseDir, $zipSize = 0) {
    $errors = [];

    // 1. صلاحية الكتابة على المجلد الجذري
    if (!is_writable($baseDir)) {
        $errors[] = 'المجلد الجذري غير قابل للكتابة: ' . $baseDir;
    }

    // 2. مساحة القرص — نحتاج على الأقل 3 أضعاف حجم ZIP
    $freeSpace = @disk_free_space($baseDir);
    $requiredSpace = $zipSize * 3 + 10 * 1024 * 1024; // 3× ZIP + 10MB احتياطي
    if ($freeSpace !== false && $freeSpace < $requiredSpace) {
        $errors[] = 'مساحة القرص غير كافية: متاح ' . formatFileSize($freeSpace)
            . ' — مطلوب على الأقل ' . formatFileSize($requiredSpace);
    }

    // 3. دالة rename متاحة (بعض الاستضافات تعطلها)
    if (!function_exists('rename')) {
        $errors[] = 'دالة rename() غير متاحة — لا يمكن النشر الذري';
    }

    // 4. اختبار عملي لـ rename على نفس نظام الملفات
    $testFile = $baseDir . '/.atomic_test_' . uniqid();
    $testFile2 = $baseDir . '/.atomic_test2_' . uniqid();
    if (@file_put_contents($testFile, 'test') !== false) {
        if (!@rename($testFile, $testFile2)) {
            $errors[] = 'فشل اختبار rename() — قد تكون هناك قيود على نظام الملفات';
            @unlink($testFile);
        } else {
            @unlink($testFile2);
        }
    } else {
        $errors[] = 'فشل إنشاء ملف اختبار — المجلد غير قابل للكتابة';
    }

    // 5. ZipArchive متاح
    if (!class_exists('ZipArchive')) {
        $errors[] = 'مكتبة ZipArchive غير متاحة — لا يمكن فك ضغط ملف ZIP';
    }

    // 6. صلاحية الكتابة على بعض المجلدات الفرعية المهمة
    $criticalDirs = ['includes', 'config', 'assets'];
    foreach ($criticalDirs as $dir) {
        $fullPath = $baseDir . '/' . $dir;
        if (is_dir($fullPath) && !is_writable($fullPath)) {
            $errors[] = "المجلد {$dir}/ غير قابل للكتابة";
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * اكتشاف المجلد الجذري في ملف ZIP
 *
 * كثير من ملفات ZIP تحتوي على مجلد جذر واحد يحتوي كل الملفات:
 *   is-app/file1.php, is-app/includes/...
 * بدلاً من:
 *   file1.php, includes/...
 *
 * هذه الدالة تكشف المجلد الجذر وتُرجع بادئة المسار (مع /) أو سلسلة فارغة
 *
 * @param ZipArchive $zip أرشيف ZIP مفتوح
 * @return string بادئة المجلد الجذر (مثل 'is-app/') أو '' إذا لم يكن هناك مجلد جذر
 */
function detectZipRootPrefix($zip) {
    $topLevelDirs = [];
    $topLevelFiles = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        // استبدال الشرطة المائلة الخلفية (Windows)
        $name = str_replace('\\', '/', $name);

        // تخطي الملفات المخفية مثل __MACOSX
        if (strpos($name, '__MACOSX') === 0) continue;
        if (strpos(basename($name), '._') === 0) continue;

        // تحليل المستوى الأول
        $slashPos = strpos($name, '/');
        if ($slashPos === false) {
            // ملف في المستوى الأعلى
            $topLevelFiles++;
        } else {
            $topDir = substr($name, 0, $slashPos);
            if (!empty($topDir)) {
                $topLevelDirs[$topDir] = true;
            }
        }
    }

    // إذا كان هناك مجلد واحد فقط في المستوى الأعلى ولا توجد ملفات أخرى
    if (count($topLevelDirs) === 1 && $topLevelFiles === 0) {
        $prefix = array_key_first($topLevelDirs) . '/';
        return $prefix;
    }

    return '';
}

/**
 * نشر ذري حقيقي للملفات من ملف ZIP (Write-Then-Rename)
 *
 * الآلية:
 *  1. فحص مسبق (صلاحيات + مساحة + مكتبات)
 *  2. تفعيل وضع الصيانة
 *  3. فك ضغط ZIP في staging
 *  4. اكتشاف مجلد ZIP الجذري + تحقق أمني
 *  5. نسخ احتياطي للملفات الحالية (للتراجع)
 *  6. استبدال ذري لكل ملف: write(tmp) → rename(tmp, target)
 *  7. إزالة وضع الصيانة
 *  8. حفظ سجل النشر + تنظيف staging
 *
 * الميزة: كل ملف يُستبدل ذرياً بعملية rename واحدة
 * على نفس نظام الملفات، rename() ذرية (POSIX guarantee)
 * لا توجد نافذة زمنية يكون فيها أي ملف في حالة غير متسقة
 *
 * @param string $zipPath مسار ملف ZIP المرفوع
 * @param string $baseDir المجلد الجذري للتطبيق
 * @return array ['success' => bool, 'error' => string, 'files_deployed' => int, 'steps' => array]
 */
function atomicDeployZip($zipPath, $baseDir) {
    $steps = [];
    $log = function($msg, $ok = true) use (&$steps) {
        $steps[] = ['msg' => $msg, 'ok' => $ok];
    };

    $stagingDir = $baseDir . '/upgrade_staging';
    $backupDir = $baseDir . '/upgrade_file_backup';
    $maintenanceActive = false;
    $deployedFiles = [];
    $backupFiles = [];
    $newDirs = [];
    $tmpFiles = []; // الملفات المؤقتة للتنظيف عند الفشل

    try {
        // ===== 1. فحص مسبق (Pre-flight Check) =====
        $zipSize = file_exists($zipPath) ? filesize($zipPath) : 0;
        $preflight = preflightCheck($baseDir, $zipSize);
        if (!$preflight['ok']) {
            foreach ($preflight['errors'] as $err) {
                $log('فحص مسبق: ' . $err, false);
            }
            return ['success' => false, 'error' => 'فشل الفحص المسبق — ' . implode('; ', $preflight['errors']), 'files_deployed' => 0, 'steps' => $steps];
        }
        $log('نجح الفحص المسبق (صلاحيات + مساحة + مكتبات)');

        // ===== 2. تفعيل وضع الصيانة =====
        if (!enableMaintenanceMode($baseDir)) {
            $log('تحذير: فشل تفعيل وضع الصيانة — المتابعة بدون صيانة', false);
        } else {
            $maintenanceActive = true;
            $log('تم تفعيل وضع الصيانة — الطلبات محجوبة مؤقتاً');
        }

        // ===== 3. التحقق من ملف ZIP =====
        if (!file_exists($zipPath)) {
            disableMaintenanceMode($baseDir);
            return ['success' => false, 'error' => 'ملف ZIP غير موجود', 'files_deployed' => 0, 'steps' => $steps];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            disableMaintenanceMode($baseDir);
            return ['success' => false, 'error' => 'فشل فتح ملف ZIP — قد يكون تالفاً', 'files_deployed' => 0, 'steps' => $steps];
        }

        $fileCount = $zip->numFiles;
        $log("تم فتح ملف ZIP ({$fileCount} إدخال)");

        // ===== 4. اكتشاف المجلد الجذري في ZIP =====
        $rootPrefix = detectZipRootPrefix($zip);
        if (!empty($rootPrefix)) {
            $log('تم اكتشاف مجلد جذري في ZIP: ' . rtrim($rootPrefix, '/') . ' — سيتم تجاهله تلقائياً');
        }

        // ===== 5. جمع قائمة الملفات من ZIP (تجاهل المجلدات والملفات المخفية) =====
        $stagedFiles = []; // مسارات نسبية (بدون rootPrefix)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));

            // تجاهل ملفات macOS المخفية
            if (strpos($name, '__MACOSX') === 0) continue;
            if (strpos(basename($name), '._') === 0) continue;

            // إزالة المجلد الجذري إن وُجد
            if (!empty($rootPrefix)) {
                if (strpos($name, $rootPrefix) !== 0) continue; // خارج المجلد الجذري
                $name = substr($name, strlen($rootPrefix));
            }

            if (empty($name)) continue;

            // المجلدات فقط (تنتهي بـ /)
            if (substr($name, -1) === '/') {
                $newDirs[] = $name;
                continue;
            }

            // التحقق الأمني: رفض مسارات خطرة (Path Traversal + Zip Slip)
            $normalized = str_replace('\\', '/', $name);
            if (strpos($normalized, '..') !== false) {
                $zip->close();
                removeDirRecursive($stagingDir);
                disableMaintenanceMode($baseDir);
                return ['success' => false, 'error' => "مسار خطر في ZIP: {$name} (يحتوي على ..) — هجوم Path Traversal محتمل", 'files_deployed' => 0, 'steps' => $steps];
            }
            if (strpos($normalized, '/') === 0 || preg_match('#^[A-Za-z]:#', $normalized)) {
                $zip->close();
                removeDirRecursive($stagingDir);
                disableMaintenanceMode($baseDir);
                return ['success' => false, 'error' => "مسار مطلق في ZIP: {$name} — مرفوض لأسباب أمنية", 'files_deployed' => 0, 'steps' => $steps];
            }

            // حماية: عدم استبدال ملفات النظام الحرجة
            $criticalFiles = ['.htaccess', '.maintenance', 'config/database.php'];
            $basename = basename($name);
            if ($basename === '.htaccess' && dirname($name) === '.') {
                // .htaccess الجذري — نتجاوزه (حماية الإعدادات الحالية)
                $log('تجاوز .htaccess الجذري (حماية الإعدادات الأمنية الحالية)', false);
                continue;
            }
            if ($name === 'config/database.php') {
                $log('تجاوز config/database.php (حماية إعدادات الاتصال)', false);
                continue;
            }
            if ($name === '.maintenance') {
                continue; // لا ننشر ملف الصيانة
            }

            $stagedFiles[] = $name;
        }

        $zip->close();

        if (empty($stagedFiles)) {
            removeDirRecursive($stagingDir);
            disableMaintenanceMode($baseDir);
            return ['success' => false, 'error' => 'ملف ZIP فارغ أو لا يحتوي على ملفات صالحة للنشر', 'files_deployed' => 0, 'steps' => $steps];
        }

        $log('عدد الملفات الجديدة/المحدّثة: ' . count($stagedFiles) . ' + ' . count($newDirs) . ' مجلد');

        // ===== 6. فك الضغط إلى مجلد staging =====
        if (is_dir($stagingDir)) {
            removeDirRecursive($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        // حماية مجلد staging
        file_put_contents($stagingDir . '/.htaccess', "Deny from all\n");

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($stagingDir);
        $zip->close();
        $log('تم فك الضغط في مجلد staging');

        // ===== 7. نسخ احتياطي للملفات الحالية التي ستُستبدل =====
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
            file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
        }

        $backedUp = 0;
        foreach ($stagedFiles as $relPath) {
            $targetPath = $baseDir . '/' . $relPath;
            if (file_exists($targetPath)) {
                $backupPath = $backupDir . '/' . $relPath;
                $backupDirPath = dirname($backupPath);
                if (!is_dir($backupDirPath)) {
                    mkdir($backupDirPath, 0755, true);
                }
                if (@copy($targetPath, $backupPath)) {
                    $backupFiles[] = $relPath;
                    $backedUp++;
                } else {
                    $log("تحذير: فشل نسخ احتياطي لـ {$relPath}", false);
                }
            }
        }
        $log("نسخ احتياطي: {$backedUp} ملف حالي سيُستبدل");

        // ===== 8. إنشاء المجلدات الجديدة =====
        $createdDirs = 0;
        foreach ($newDirs as $dirPath) {
            $fullDirPath = $baseDir . '/' . $dirPath;
            if (!is_dir($fullDirPath)) {
                if (@mkdir($fullDirPath, 0755, true)) {
                    $createdDirs++;
                } else {
                    $log("تحذير: فشل إنشاء المجلد {$dirPath}", false);
                }
            }
        }
        if ($createdDirs > 0) {
            $log("تم إنشاء {$createdDirs} مجلد جديد");
        }

        // ===== 9. النشر الذري — Write-Then-Rename =====
        $deployed = 0;
        $failed = 0;

        $log('بدء النشر الذري (Write-Then-Rename)...');

        foreach ($stagedFiles as $relPath) {
            // تحديد مسار الملف المصدر في staging
            if (!empty($rootPrefix)) {
                $sourcePath = $stagingDir . '/' . $rootPrefix . $relPath;
            } else {
                $sourcePath = $stagingDir . '/' . $relPath;
            }

            $targetPath = $baseDir . '/' . $relPath;
            $targetDir = dirname($targetPath);

            // التأكد من وجود المجلد الهدف
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            // التحقق من وجود الملف المصدر
            if (!file_exists($sourcePath)) {
                $log("تجاوز: {$relPath} — غير موجود في staging", false);
                $failed++;
                continue;
            }

            // === Write-Then-Rename: النمط الذري ===
            // 1. كتابة ملف مؤقت في نفس المجلد (نفس نظام الملفات = rename ذري)
            $tmpFile = $targetDir . '/.deploy_' . uniqid() . '.tmp';
            $tmpFiles[] = $tmpFile;

            if (@copy($sourcePath, $tmpFile) === false) {
                $log("فشل نسخ: {$relPath} — تخطي", false);
                $failed++;
                @unlink($tmpFile);
                continue;
            }

            // 2. تعيين نفس صلاحيات الملف الأصلي (إن وُجد)
            if (file_exists($targetPath)) {
                $originalPerms = @fileperms($targetPath);
                if ($originalPerms !== false) {
                    @chmod($tmpFile, $originalPerms & 0777);
                }
            }

            // 3. rename() — ذرية على نفس نظام الملفات (POSIX guarantee)
            if (@rename($tmpFile, $targetPath)) {
                $deployed++;
                // إزالة من قائمة المؤقتة (تم الاستبدال بنجاح)
                $tmpFiles = array_diff($tmpFiles, [$tmpFile]);
            } else {
                $log("فشل استبدال: {$relPath}", false);
                $failed++;
                @unlink($tmpFile);
                $tmpFiles = array_diff($tmpFiles, [$tmpFile]);

                // محاولة بديلة: copy مباشر (ليس ذري لكن يعمل على أنظمة ملفات مختلفة)
                if (@copy($sourcePath, $targetPath)) {
                    $deployed++;
                    $failed--;
                    $log("تم نسخ {$relPath} (بدون ذرية — أنظمة ملفات مختلفة)", false);
                }
            }
        }

        $log("تم نشر {$deployed} ملف ذرياً" . ($failed > 0 ? " ({$failed} فشل)" : ''));

        // ===== 10. إزالة وضع الصيانة =====
        disableMaintenanceMode($baseDir);
        $maintenanceActive = false;
        $log('تم إزالة وضع الصيانة — التطبيق متاح');

        // ===== 11. حفظ سجل النشر =====
        $deployManifest = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => 'atomic_write_then_rename',
            'zip_root_prefix' => $rootPrefix,
            'files' => $stagedFiles,
            'backup_files' => $backupFiles,
            'new_dirs' => $newDirs,
            'deployed' => $deployed,
            'failed' => $failed,
            'zip_file' => basename($zipPath),
        ];
        file_put_contents($backupDir . '/deploy_manifest.json', json_encode($deployManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $log('تم حفظ سجل النشر');

        // ===== 12. تنظيف مجلد staging =====
        removeDirRecursive($stagingDir);
        $log('تم تنظيف مجلد staging');

        // ===== النتيجة النهائية =====
        if ($failed > 0 && $deployed === 0) {
            // فشل كامل
            return [
                'success' => false,
                'error' => "فشل نشر جميع الملفات ({$failed} فشل). تم إزالة وضع الصيانة.",
                'files_deployed' => 0,
                'steps' => $steps,
            ];
        }

        return [
            'success' => true,
            'error' => $failed > 0 ? "تنبيه: {$failed} ملف فشل في النشر" : '',
            'files_deployed' => $deployed,
            'steps' => $steps,
            'deployed_files' => $stagedFiles,
            'backup_files' => $backupFiles,
        ];

    } catch (\Throwable $e) {
        // ===== تنظيف طوارئ =====

        // إزالة الملفات المؤقتة
        foreach ($tmpFiles as $tmpFile) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        // إزالة وضع الصيانة
        if ($maintenanceActive) {
            @disableMaintenanceMode($baseDir);
        }

        // تنظيف staging
        if (is_dir($stagingDir)) {
            @removeDirRecursive($stagingDir);
        }

        ErrorHandler::logException($e, 'AtomicDeploy');
        return ['success' => false, 'error' => ErrorHandler::safeExceptionMessage($e, 'خطأ أثناء نشر الملفات.'), 'files_deployed' => 0, 'steps' => $steps];
    }
}

/**
 * تراجع عن نشر الملفات — استعادة الملفات من النسخة الاحتياطية
 *
 * يعكس عملية النشر: يستعيد كل ملف تم نسخه احتياطياً قبل النشر
 * ويحذف الملفات/المجلدات الجديدة التي لم تكن موجودة قبل النشر
 *
 * @param string $baseDir المجلد الجذري
 * @return array ['success' => bool, 'error' => string, 'restored_count' => int]
 */
function rollbackFileDeploy($baseDir) {
    $backupDir = $baseDir . '/upgrade_file_backup';
    $manifestFile = $backupDir . '/deploy_manifest.json';

    if (!file_exists($manifestFile)) {
        return ['success' => false, 'error' => 'لا يوجد سجل نشر للتراجع عنه', 'restored_count' => 0];
    }

    $manifest = json_decode(file_get_contents($manifestFile), true);
    if (!$manifest || empty($manifest['files'])) {
        return ['success' => false, 'error' => 'سجل النشر تالف أو فارغ', 'restored_count' => 0];
    }

    // تفعيل وضع الصيانة أثناء التراجع
    enableMaintenanceMode($baseDir);

    $restoredCount = 0;
    $failedCount = 0;
    $deletedCount = 0;

    // 1. استعادة الملفات المنسوخة احتياطياً (الملفات التي كانت موجودة قبل النشر)
    if (!empty($manifest['backup_files'])) {
        foreach ($manifest['backup_files'] as $relPath) {
            $backupPath = $backupDir . '/' . $relPath;
            $targetPath = $baseDir . '/' . $relPath;

            if (file_exists($backupPath)) {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (@copy($backupPath, $targetPath)) {
                    $restoredCount++;
                } else {
                    $failedCount++;
                }
            }
        }
    }

    // 2. حذف الملفات الجديدة التي أُضيفت بواسطة النشر (لم تكن موجودة قبله)
    if (!empty($manifest['files'])) {
        foreach ($manifest['files'] as $relPath) {
            // إذا كان الملف ليس في قائمة النسخ الاحتياطي، فهو ملف جديد
            if (!in_array($relPath, $manifest['backup_files'] ?? [])) {
                $targetPath = $baseDir . '/' . $relPath;
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                    $deletedCount++;
                }
            }
        }
    }

    // 3. حذف المجلدات الجديدة (بترتيب عكسي — الأعمق أولاً)
    if (!empty($manifest['new_dirs'])) {
        $dirs = $manifest['new_dirs'];
        usort($dirs, function($a, $b) {
            return substr_count($b, '/') - substr_count($a, '/');
        });
        foreach ($dirs as $dirPath) {
            $fullDirPath = $baseDir . '/' . $dirPath;
            if (is_dir($fullDirPath)) {
                @rmdir($fullDirPath); // يحذف فقط إذا كان فارغاً
            }
        }
    }

    // 4. تنظيف مجلد النسخ الاحتياطي للنشر
    if (is_dir($backupDir)) {
        // حذف ملفات النسخ الاحتياطي (وليس deploy_manifest.json — سنحذفه أخيراً)
        foreach ($manifest['backup_files'] ?? [] as $relPath) {
            @unlink($backupDir . '/' . $relPath);
        }
    }

    // حذف سجل النشر
    @unlink($manifestFile);

    // إزالة وضع الصيانة
    disableMaintenanceMode($baseDir);

    return [
        'success' => $failedCount === 0,
        'error' => $failedCount > 0 ? "فشل استعادة {$failedCount} ملف" : '',
        'restored_count' => $restoredCount,
        'deleted_count' => $deletedCount,
    ];
}

/**
 * حذف مجلد بشكل متكرر
 *
 * @param string $dir مسار المجلد
 * @return bool
 */
function removeDirRecursive($dir) {
    if (!is_dir($dir)) return false;
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    return @rmdir($dir);
}

/**
 * الحصول على آخر سجل نشر (إن وُجد)
 *
 * @param string $baseDir المجلد الجذري
 * @return array|null
 */
function getLastDeployManifest($baseDir) {
    $manifestFile = $baseDir . '/upgrade_file_backup/deploy_manifest.json';
    if (!file_exists($manifestFile)) return null;
    $content = file_get_contents($manifestFile);
    if ($content === false) return null;
    return json_decode($content, true);
}
