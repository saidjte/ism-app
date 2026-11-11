<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$page_title = t('nav_system_logs');
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
requirePermission('logs.view');

require_once __DIR__ . '/../includes/header.php';

define('LOG_DIR_VIEW', dirname(__DIR__) . '/logs');

// جلب ملفات السجلات المتاحة
$logFiles = [];
if (is_dir(LOG_DIR_VIEW)) {
    // البحث في كل أنماط ملفات السجل (app-*.log من logMessage + error-*.log من ErrorHandler)
    $appFiles = glob(LOG_DIR_VIEW . '/app-*.log') ?: [];
    $errorFiles = glob(LOG_DIR_VIEW . '/error-*.log') ?: [];
    $files = array_merge($appFiles, $errorFiles);
    if ($files) {
        // إزالة التكرارات
        $files = array_unique($files);
        usort($files, 'strcmp'); // ترتيب أبجدي (الأقدم أولاً)
        $logFiles = array_reverse($files);
    }
}

// قراءة السجل المحدد
$selectedLog = $_GET['file'] ?? '';
$logContent = '';
$logLines = [];
$logEntries = [];

if ($selectedLog && in_array(LOG_DIR_VIEW . '/' . $selectedLog, $logFiles) && preg_match('/^(app|error)-\d{4}-\d{2}-\d{2}\.log$/', $selectedLog)) {
    // Security: basename لمنع اختراق المسارات
    $safeFile = basename($selectedLog);
    $fullPath = LOG_DIR_VIEW . '/' . $safeFile;
    if (file_exists($fullPath)) {
        $logContent = file_get_contents($fullPath);
        $logLines = explode("\n", trim($logContent));
        
        // Parse log entries
        foreach ($logLines as $line) {
            if (empty(trim($line))) continue;
            
            $entry = ['raw' => $line];
            
            // تحليل: [2025-01-15 10:30:00] [error] رسالة | سياق | URL | IP
            if (preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/U', $line, $matches)) {
                $entry['date'] = $matches[1];
                $entry['level'] = $matches[2];
                $rest = $matches[3];
                
                // Split by |
                $parts = explode(' | ', $rest);
                $entry['message'] = $parts[0] ?? '';
                if (isset($parts[1]) && strpos($parts[1], '{"') === 0) {
                    $entry['context'] = json_decode($parts[1], true) ?: $parts[1];
                }
                if (isset($parts[2]) && strpos($parts[2], 'URL:') === 0) {
                    $entry['url'] = $parts[2];
                }
                if (isset($parts[3]) && strpos($parts[3], 'IP:') === 0) {
                    $entry['ip'] = $parts[3];
                }
            }
            
            $logEntries[] = $entry;
        }
    }
}

// Filter by level
$levelFilter = $_GET['level'] ?? '';
if ($levelFilter) {
    $logEntries = array_filter($logEntries, function($e) use ($levelFilter) {
        return ($e['level'] ?? '') === $levelFilter;
    });
    $logEntries = array_values($logEntries); // إعادة ترقيم الفهرس
}

// ترقيم الصفحات
$perPage = 100;
$page = max(1, intval($_GET['page'] ?? 1));
$totalEntries = count($logEntries);
$totalPages = max(1, ceil($totalEntries / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$logEntries = array_slice($logEntries, $offset, $perPage);

// Level colors and labels
$levelStyles = [
    'error'   => ['bg' => '#fef2f2', 'border' => '#dc2626', 'text' => '#dc2626', 'label' => 'خطأ'],
    'warning' => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'text' => '#d97706', 'label' => 'تحذير'],
    'info'    => ['bg' => '#eff6ff', 'border' => '#2563eb', 'text' => '#2563eb', 'label' => 'معلومات'],
    'debug'   => ['bg' => '#f9fafb', 'border' => '#6b7280', 'text' => '#6b7280', 'label' => 'تصحيح'],
];
?>
<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
            <?php echo t('nav_system_logs'); ?>
        </h1>
    </div>

    <!-- ملفات اللوغ المتاحة -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">
            <h2>📁 <?php echo t('admin_log_files'); ?></h2>
        </div>
        <div class="card-body">
            <?php if (empty($logFiles)): ?>
                <p class="text-muted" style="text-align:center; padding:1rem;">
                    <?php echo t('admin_no_log_files'); ?>
                </p>
            <?php else: ?>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ($logFiles as $lf):
                        $fname = basename($lf);
                        $fdate = preg_replace('/^(app|error)-|\.(log)$/', '', $fname);
                        $fsize = filesize($lf);
                        $fsizeLabel = $fsize > 1048576 ? round($fsize / 1048576, 1) . ' MB' : round($fsize / 1024, 1) . ' KB';
                        $isActive = ($selectedLog === $fname);
                    ?>
                    <a href="?file=<?php echo urlencode($fname); ?><?php echo $levelFilter ? '&level=' . urlencode($levelFilter) : ''; ?>"
                       class="btn btn-sm <?php echo $isActive ? 'btn-primary' : 'btn-outline'; ?>">
                        <?php echo $fdate; ?>
                        <small style="opacity:0.7;">(<?php echo $fsizeLabel; ?>)</small>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedLog && !empty($logEntries)): ?>
    <!-- فلاتر المستوى -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">
            <h2><?php echo t('admin_filter_by_level'); ?></h2>
        </div>
        <div class="card-body">
            <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                <?php
                $currentParams = [];
                if ($selectedLog) $currentParams['file'] = $selectedLog;
                $baseUrl = '?' . http_build_query($currentParams);
                ?>
                <a href="<?php echo $baseUrl; ?>" class="btn btn-sm <?php echo empty($levelFilter) ? 'btn-primary' : 'btn-outline'; ?>">
                    الكل
                </a>
                <?php foreach ($levelStyles as $lvl => $style): ?>
                <a href="<?php echo $baseUrl . '&level=' . $lvl; ?>"
                   class="btn btn-sm <?php echo $levelFilter === $lvl ? 'btn-primary' : 'btn-outline'; ?>"
                   <?php if ($levelFilter !== $lvl): ?>style="border-color:<?php echo $style['border']; ?>; color:<?php echo $style['text']; ?>;"<?php endif; ?>>
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?php echo $style['border']; ?>; margin-left:6px;"></span>
                    <?php echo $style['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- عرض السجلات -->
    <div class="card">
        <div class="card-header">
            <h2>
                <?php echo htmlspecialchars(basename($selectedLog)); ?>
                — <?php echo $totalEntries; ?> سجل
                <?php if ($levelFilter): ?>
                    <span style="color: <?php echo $levelStyles[$levelFilter]['text'] ?? '#666'; ?>;">
                        (<?php echo $levelStyles[$levelFilter]['label'] ?? $levelFilter; ?>)
                    </span>
                <?php endif; ?>
            </h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table style="font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th style="white-space: nowrap;">التاريخ</th>
                            <th style="width: 80px;">المستوى</th>
                            <th>الرسالة</th>
                            <th style="width: 60px;">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logEntries as $idx => $entry):
                            $level = $entry['level'] ?? 'info';
                            $style = $levelStyles[$level] ?? $levelStyles['info'];
                            $hasDetails = !empty($entry['context']) || !empty($entry['url']) || !empty($entry['ip']);
                            $rowId = 'log-row-' . $offset + $idx;
                        ?>
                        <tr style="border-right: 3px solid <?php echo $style['border']; ?>; background: <?php echo $style['bg']; ?>;">
                            <td style="color: #999; font-size: 0.8rem;"><?php echo $offset + $idx + 1; ?></td>
                            <td style="white-space: nowrap; font-size: 0.8rem; direction: ltr; text-align: right;" title="<?php echo htmlspecialchars($entry['date'] ?? ''); ?>">
                                <?php 
                                    $dateOnly = substr($entry['date'] ?? '', 0, 10);
                                    $timeOnly = substr($entry['date'] ?? '', 11);
                                    echo htmlspecialchars($dateOnly); 
                                ?>
                                <br>
                                <small style="color: #666;"><?php echo htmlspecialchars($timeOnly); ?></small>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; background: <?php echo $style['bg']; ?>; color: <?php echo $style['text']; ?>; border: 1px solid <?php echo $style['border']; ?>;">
                                    <?php echo $style['label']; ?>
                                </span>
                            </td>
                            <td style="word-break: break-word;">
                                <?php echo htmlspecialchars($entry['message'] ?? $entry['raw']); ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($hasDetails): ?>
                                <button onclick="document.getElementById('<?php echo $rowId; ?>').style.display = document.getElementById('<?php echo $rowId; ?>').style.display === 'none' ? 'table-row' : 'none'" 
                                        class="btn btn-outline" style="padding: 2px 8px; font-size: 0.75rem;">
                                    ▼
                                </button>
                                <?php else: ?>
                                    <span style="color: #ccc;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($hasDetails): ?>
                        <tr id="<?php echo $rowId; ?>" style="display: none; background: #fff;">
                            <td colspan="5" style="padding: 0.75rem 1rem;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 0.5rem; font-size: 0.8rem;">
                                    <?php if (!empty($entry['context'])): ?>
                                    <div style="padding: 0.5rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                        <strong style="color: #64748b;">البيانات الإضافية:</strong>
                                        <pre style="margin: 0.25rem 0 0 0; white-space: pre-wrap; word-break: break-word; direction: ltr; text-align: left; font-size: 0.78rem; color: #334155;"><?php echo htmlspecialchars(is_array($entry['context']) ? json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $entry['context']); ?></pre>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['url'])): ?>
                                    <div style="padding: 0.5rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                        <strong style="color: #64748b;">المسار:</strong>
                                        <div style="direction: ltr; text-align: left; margin-top: 0.25rem; font-family: monospace; color: #334155; word-break: break-all;"><?php echo htmlspecialchars($entry['url']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['ip'])): ?>
                                    <div style="padding: 0.5rem; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                                        <strong style="color: #64748b;">عنوان IP:</strong>
                                        <div style="direction: ltr; text-align: left; margin-top: 0.25rem; font-family: monospace; color: #334155;"><?php echo htmlspecialchars($entry['ip']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($entry['raw'])): ?>
                                <div style="margin-top: 0.5rem; padding: 0.5rem; background: #fefce8; border-radius: 6px; border: 1px solid #fde68a; font-size: 0.75rem; color: #92400e; direction: ltr; text-align: left;">
                                    <strong>النص الأصلي:</strong> <?php echo htmlspecialchars($entry['raw']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ترقيم الصفحات -->
            <?php if ($totalPages > 1): ?>
            <div style="display:flex; justify-content:center; gap:6px; margin-top:1.25rem; flex-wrap:wrap;">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-sm btn-outline">→ السابق</a>
                <?php endif; ?>

                <span class="badge badge-info" style="padding:6px 14px; font-size:0.85rem; font-weight:600;">
                    صفحة <?php echo $page; ?> من <?php echo $totalPages; ?>
                </span>

                <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-sm btn-outline">التالي ←</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($selectedLog && empty($logEntries)): ?>
    <div class="card">
        <div class="card-body">
            <div style="text-align:center; padding:3rem; color:var(--gray-400);">
                <div style="font-size:3rem; margin-bottom:12px;">📋</div>
                <div style="font-size:1.1rem; font-weight:600; color:var(--gray-600);">لا توجد سجلات مطابقة للفلتر المحدد</div>
                <div style="font-size:0.85rem; margin-top:8px;">جرّب تغيير فلتر المستوى أو اختيار ملف آخر</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
