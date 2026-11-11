<?php
/**
 * ===== متحكم النسخ الاحتياطية للهاتف (Mobile Backup Controller) =====
 * يوفر واجهة API لإدارة النسخ الاحتياطية من تطبيق الهاتف
 *
 * نقاط النهاية:
 *   GET    /api/mobile/backups            — قائمة النسخ الاحتياطية
 *   POST   /api/mobile/backups/create     — إنشاء نسخة احتياطية
 *   POST   /api/mobile/backups/delete     — حذف نسخة احتياطية
 *   POST   /api/mobile/backups/restore    — استعادة نسخة احتياطية
 *   GET    /api/mobile/backups/download   — تحميل نسخة احتياطية
 *   GET    /api/mobile/backups/status     — حالة النسخ الاحتياطية
 *
 * ملاحظة: جميع العمليات تتطلب صلاحيات المدير
 *
 * v5.13.0: إنشاء وحدة تحكم لتغطية وظائف النسخ الاحتياطية
 */

require_once __DIR__ . '/../ApiController.php';

class MobileBackupController extends ApiController
{
    /**
     * قائمة النسخ الاحتياطية ومعلومات الحالة
     *
     * الاستجابة:
     *   { success, data: { backups, db_info, storage_info } }
     */
    public function index(array $params = []): void
    {
        try {
            $this->requireAdmin();

            // جلب قائمة النسخ الاحتياطية
            $backups = [];
            $backupDir = defined('BACKUP_DIR') ? BACKUP_DIR : __DIR__ . '/../../../backups';

            if (is_dir($backupDir)) {
                $files = glob($backupDir . '/*.sql');
                if ($files === false) $files = [];
                $gzFiles = glob($backupDir . '/*.sql.gz');
                if ($gzFiles === false) $gzFiles = [];
                $files = array_merge($files, $gzFiles);

                foreach ($files as $file) {
                    $backups[] = [
                        'filename' => basename($file),
                        'size' => filesize($file),
                        'size_formatted' => $this->formatSize(filesize($file)),
                        'date' => date('Y-m-d H:i:s', filemtime($file)),
                        'is_gz' => str_ends_with($file, '.gz'),
                    ];
                }

                // ترتيب تنازلي حسب التاريخ
                usort($backups, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
            }

            // معلومات قاعدة البيانات
            $dbInfo = [];
            try {
                $db = AppDatabase::getInstance();
                $pdo = $db->getConnection();

                $dbSize = fetchOne("SELECT SUM(data_length + index_length) as size
                                    FROM information_schema.tables
                                    WHERE table_schema = DATABASE()");
                $dbInfo['size'] = $this->formatSize(intval($dbSize['size'] ?? 0));

                $tableCount = fetchOne("SELECT COUNT(*) as c FROM information_schema.tables WHERE table_schema = DATABASE()");
                $dbInfo['table_count'] = intval($tableCount['c'] ?? 0);

                $productCount = fetchOne("SELECT COUNT(*) as c FROM products");
                $dbInfo['product_count'] = intval($productCount['c'] ?? 0);
            } catch (\Throwable $e) {
                $dbInfo = ['error' => 'تعذر جلب معلومات قاعدة البيانات'];
            }

            // معلومات التخزين
            $storageUsed = 0;
            foreach ($backups as $backup) {
                $storageUsed += $backup['size'];
            }

            ApiResponse::success([
                'backups' => $backups,
                'total_backups' => count($backups),
                'storage_used' => $this->formatSize($storageUsed),
                'db_info' => $dbInfo,
                'backup_method' => $this->getBackupMethod(),
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBackupController::index');
            ApiResponse::serverError('حدث خطأ أثناء جلب النسخ الاحتياطية');
        }
    }

    /**
     * إنشاء نسخة احتياطية
     *
     * الجسم (JSON):
     *   description — وصف النسخة (اختياري)
     *
     * الاستجابة:
     *   { success, data: { filename, size, message } }
     */
    public function create(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $description = trim($this->input('description', ''));

            if (function_exists('createBackup')) {
                $result = createBackup($description);

                if (isset($result['success']) && $result['success']) {
                    // تسجيل في سجل التدقيق
                    if (function_exists('addAuditLog')) {
                        addAuditLog('backup_create', 'إنشاء نسخة احتياطية: ' . ($result['filename'] ?? ''));
                    }

                    ApiResponse::created([
                        'filename' => $result['filename'] ?? '',
                        'size' => $result['size'] ?? 0,
                    ], 'تم إنشاء النسخة الاحتياطية بنجاح');
                } else {
                    ApiResponse::error($result['message'] ?? 'فشل إنشاء النسخة الاحتياطية', 'backup_error', 500);
                }
            } else {
                // إنشاء نسخة احتياطية يدوية
                $result = $this->manualBackup($description);
                if ($result['success']) {
                    if (function_exists('addAuditLog')) {
                        addAuditLog('backup_create', 'إنشاء نسخة احتياطية يدوية: ' . $result['filename']);
                    }
                    ApiResponse::created($result, 'تم إنشاء النسخة الاحتياطية بنجاح');
                } else {
                    ApiResponse::error($result['message'], 'backup_error', 500);
                }
            }

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBackupController::create');
            ApiResponse::serverError('حدث خطأ أثناء إنشاء النسخة الاحتياطية');
        }
    }

    /**
     * حذف نسخة احتياطية
     *
     * الجسم (JSON):
     *   filename — اسم ملف النسخة (مطلوب)
     *
     * الاستجابة:
     *   { success, message }
     */
    public function delete(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $filename = trim($this->input('filename', ''));
            if (empty($filename)) {
                ApiResponse::validationError(['filename' => 'اسم الملف مطلوب']);
                return;
            }

            if (function_exists('deleteBackup')) {
                $result = deleteBackup($filename);
            } else {
                $result = $this->manualDelete($filename);
            }

            if ($result['success'] ?? false) {
                if (function_exists('addAuditLog')) {
                    addAuditLog('backup_delete', 'حذف نسخة احتياطية: ' . $filename);
                }
                ApiResponse::success(null, 'تم حذف النسخة الاحتياطية بنجاح');
            } else {
                ApiResponse::error($result['message'] ?? 'فشل حذف النسخة الاحتياطية', 'backup_error', 400);
            }

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBackupController::delete');
            ApiResponse::serverError('حدث خطأ أثناء حذف النسخة الاحتياطية');
        }
    }

    /**
     * استعادة نسخة احتياطية
     *
     * الجسم (JSON):
     *   filename — اسم ملف النسخة (مطلوب)
     *
     * الاستجابة:
     *   { success, message }
     */
    public function restore(array $params = []): void
    {
        try {
            $this->requirePost();
            $this->requireAdmin();

            $filename = trim($this->input('filename', ''));
            if (empty($filename)) {
                ApiResponse::validationError(['filename' => 'اسم الملف مطلوب']);
                return;
            }

            if (function_exists('restoreBackup')) {
                $result = restoreBackup($filename);
            } else {
                $result = $this->manualRestore($filename);
            }

            if ($result['success'] ?? false) {
                if (function_exists('addAuditLog')) {
                    addAuditLog('backup_restore', 'استعادة نسخة احتياطية: ' . $filename);
                }
                ApiResponse::success(null, 'تم استعادة النسخة الاحتياطية بنجاح');
            } else {
                ApiResponse::error($result['message'] ?? 'فشل استعادة النسخة الاحتياطية', 'backup_error', 500);
            }

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBackupController::restore');
            ApiResponse::serverError('حدث خطأ أثناء استعادة النسخة الاحتياطية');
        }
    }

    /**
     * تحميل نسخة احتياطية
     *
     * معاملات GET:
     *   filename — اسم الملف (مطلوب)
     *
     * الاستجابة: ملف SQL أو SQL.GZ
     */
    public function download(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $filename = basename(trim($this->input('filename', '')));
            if (empty($filename)) {
                ApiResponse::validationError(['filename' => 'اسم الملف مطلوب']);
                return;
            }

            $backupDir = defined('BACKUP_DIR') ? BACKUP_DIR : __DIR__ . '/../../../backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                ApiResponse::notFound('الملف غير موجود');
                return;
            }

            // التحقق من أن الملف في مجلد النسخ الاحتياطية فقط
            $realPath = realpath($filepath);
            $realDir = realpath($backupDir);
            if ($realPath === false || $realDir === false || !str_starts_with($realPath, $realDir)) {
                ApiResponse::forbidden('مسار الملف غير مسموح');
                return;
            }

            $mimeType = str_ends_with($filename, '.gz') ? 'application/gzip' : 'text/plain';

            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBackupController::download');
            ApiResponse::serverError('حدث خطأ أثناء تحميل النسخة الاحتياطية');
        }
    }

    /**
     * حالة نظام النسخ الاحتياطية
     *
     * الاستجابة:
     *   { success, data: { method, max_files, auto_backup_enabled } }
     */
    public function status(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $maxFiles = defined('MAX_BACKUP_FILES') ? MAX_BACKUP_FILES : 10;

            ApiResponse::success([
                'method' => $this->getBackupMethod(),
                'max_files' => $maxFiles,
                'auto_backup_enabled' => function_exists('getSetting') ? (getSetting('auto_backup_enabled', '0') === '1') : false,
            ]);

        } catch (\Throwable $e) {
            ErrorHandler::logException($e, 'MobileBackupController::status');
            ApiResponse::serverError('حدث خطأ أثناء جلب حالة النسخ الاحتياطية');
        }
    }

    // ===== أدوات مساعدة =====

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    private function getBackupMethod(): string
    {
        // فحص توفر mysqldump
        $mysqldumpPath = function_exists('shell_exec') ? shell_exec('which mysqldump 2>/dev/null') : '';
        return !empty(trim($mysqldumpPath ?? '')) ? 'mysqldump' : 'php_fallback';
    }

    private function manualBackup(string $description): array
    {
        try {
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $backupDir = defined('BACKUP_DIR') ? BACKUP_DIR : __DIR__ . '/../../../backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $filename = 'backup_' . date('Y-m-d_His') . '.sql';
            $filepath = $backupDir . '/' . $filename;

            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $sql = "-- ISM-App Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n-- Description: {$description}\n\n";

            foreach ($tables as $table) {
                $createTable = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createTable['Create Table'] . ";\n\n";

                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $values = array_map(function ($v) use ($pdo) {
                            return $v === null ? 'NULL' : $pdo->quote($v);
                        }, $row);
                        $cols = implode('`, `', array_keys($row));
                        $sql .= "INSERT INTO `{$table}` (`{$cols}`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }

            file_put_contents($filepath, $sql);

            return [
                'success' => true,
                'filename' => $filename,
                'size' => filesize($filepath),
                'message' => 'تم إنشاء النسخة الاحتياطية بنجاح',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'فشل إنشاء النسخة: ' . $e->getMessage()];
        }
    }

    private function manualDelete(string $filename): array
    {
        $backupDir = defined('BACKUP_DIR') ? BACKUP_DIR : __DIR__ . '/../../../backups';
        $filepath = $backupDir . '/' . basename($filename);

        if (!file_exists($filepath)) {
            return ['success' => false, 'message' => 'الملف غير موجود'];
        }

        return unlink($filepath)
            ? ['success' => true, 'message' => 'تم حذف الملف']
            : ['success' => false, 'message' => 'فشل حذف الملف'];
    }

    private function manualRestore(string $filename): array
    {
        try {
            $backupDir = defined('BACKUP_DIR') ? BACKUP_DIR : __DIR__ . '/../../../backups';
            $filepath = $backupDir . '/' . basename($filename);

            if (!file_exists($filepath)) {
                return ['success' => false, 'message' => 'الملف غير موجود'];
            }

            $sql = file_get_contents($filepath);
            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $pdo->exec($sql);

            return ['success' => true, 'message' => 'تم استعادة النسخة الاحتياطية'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'فشل الاستعادة: ' . $e->getMessage()];
        }
    }
}
