<?php
/**
 * صفحة سجل التحديثات — تعرض جميع التحديثات والإصلاحات
 */
require_once 'includes/bootstrap.php';
$page_title = t('changelog_title');
require_once 'includes/auth.php';
require_once 'config/upgrades.php';

// لا نحتاج requireLogin لأن upgrade.php يُحمّل bootstrap الذي يحتاج auth
// لكن header.php يُحمّل عبر include لذلك نحتاج auth
requireLogin();
require_once 'includes/header.php';

// ترتيب حسب الأحدث
$changelog = isset($CHANGELOG) ? $CHANGELOG : [];
$bugFixes = isset($BUG_FIXES) ? $BUG_FIXES : [];

// أيقونات وألوان حسب النوع
$typeConfig = [
    'feature'      => ['icon' => '✨', 'label' => 'ميزة جديدة',      'color' => '#059669', 'bg' => '#f0fdf4', 'border' => '#bbf7d0'],
    'fix'          => ['icon' => '🔧', 'label' => 'إصلاح خطأ',      'color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca'],
    'improvement'  => ['icon' => '⚡', 'label' => 'تحسين',          'color' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#bfdbfe'],
    'security'     => ['icon' => '🔒', 'label' => 'أمان',           'color' => '#92400e', 'bg' => '#fef3c7', 'border' => '#fde68a'],
    'refactor'     => ['icon' => '🔨', 'label' => 'إعادة هيكلة',   'color' => '#7c3aed', 'bg' => '#f5f3ff', 'border' => '#ddd6fe'],
    'breaking'     => ['icon' => '⚠️', 'label' => 'تغيير مهم',     'color' => '#ea580c', 'bg' => '#fff7ed', 'border' => '#fed7aa'],
];

function getTypeConfig($type) {
    global $typeConfig;
    return $typeConfig[$type] ?? $typeConfig['improvement'];
}

// إحصائيات
$totalFeatures = 0;
$totalFixes = 0;
$totalImprovements = 0;
$totalSecurity = 0;
$totalRefactors = 0;
foreach ($changelog as $entry) {
    foreach ($entry['changes'] as $change) {
        switch ($change['type']) {
            case 'feature': $totalFeatures++; break;
            case 'fix': $totalFixes++; break;
            case 'improvement': $totalImprovements++; break;
            case 'security': $totalSecurity++; break;
            case 'refactor': $totalRefactors++; break;
            default: $totalImprovements++; break;
        }
    }
}
$totalChanges = $totalFeatures + $totalFixes + $totalImprovements + $totalSecurity + $totalRefactors;
?>

<div class="container" style="max-width: 1001px;">

    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?php echo t('changelog_title'); ?>
        </h1>
        <span style="background:linear-gradient(135deg,#059669,#10b981); color:white; padding:6px 16px; border-radius:20px; font-size:0.85rem; font-weight:700;">
            الإصدار <?php echo APP_VERSION; ?>
        </span>
    </div>

    <!-- إحصائيات سريعة -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:1.5rem;">
        <div style="background:#f0fdf4; border:1.5px solid #bbf7d0; border-radius:10px; padding:14px; text-align:center;">
            <div style="font-size:1.5rem; font-weight:800; color:#059669;"><?php echo $totalChanges; ?></div>
            <div style="font-size:0.78rem; color:#6b7280; margin-top:2px;">إجمالي التغييرات</div>
        </div>
        <div style="background:#f0fdf4; border:1.5px solid #bbf7d0; border-radius:10px; padding:14px; text-align:center;">
            <div style="font-size:1.5rem; font-weight:800; color:#059669;"><?php echo $totalFeatures; ?></div>
            <div style="font-size:0.78rem; color:#6b7280; margin-top:2px;">✨ ميزات جديدة</div>
        </div>
        <div style="background:#fef2f2; border:1.5px solid #fecaca; border-radius:10px; padding:14px; text-align:center;">
            <div style="font-size:1.5rem; font-weight:800; color:#dc2626;"><?php echo $totalFixes; ?></div>
            <div style="font-size:0.78rem; color:#6b7280; margin-top:2px;">🔧 إصلاحات</div>
        </div>
        <div style="background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:10px; padding:14px; text-align:center;">
            <div style="font-size:1.5rem; font-weight:800; color:#2563eb;"><?php echo $totalImprovements + $totalRefactors; ?></div>
            <div style="font-size:0.78rem; color:#6b7280; margin-top:2px;">⚡ تحسينات</div>
        </div>
        <div style="background:#fef3c7; border:1.5px solid #fde68a; border-radius:10px; padding:14px; text-align:center;">
            <div style="font-size:1.5rem; font-weight:800; color:#92400e;"><?php echo $totalSecurity; ?></div>
            <div style="font-size:0.78rem; color:#6b7280; margin-top:2px;">🔒 أمان</div>
        </div>
    </div>

    <!-- قائمة الإصدارات -->
    <?php foreach ($changelog as $entryIndex => $entry): 
        $isLatest = ($entryIndex === 0);
    ?>
    <div style="margin-bottom:1.5rem; <?php echo $isLatest ? '' : 'opacity:0.9;'; ?>">
        <!-- رأس الإصدار -->
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:42px; height:42px; border-radius:50%; background:<?php echo $isLatest ? 'linear-gradient(135deg,#059669,#10b981)' : '#e5e7eb'; ?>; display:flex; align-items:center; justify-content:center; color:<?php echo $isLatest ? 'white' : '#6b7280'; ?>; font-weight:800; font-size:0.75rem;">
                    <?php echo $entry['version']; ?>
                </div>
                <div>
                    <div style="font-weight:700; font-size:1rem; color:<?php echo $isLatest ? '#059669' : '#374151'; ?>;">
                        الإصدار <?php echo $entry['version']; ?>
                        <?php if ($isLatest): ?>
                            <span style="background:#dcfce7; color:#059669; padding:2px 10px; border-radius:12px; font-size:0.7rem; font-weight:700; margin-right:6px;">الأحدث</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.78rem; color:#9ca3af;"><?php echo $entry['date']; ?> — <?php echo count($entry['changes']); ?> تغيير</div>
                </div>
            </div>
        </div>

        <!-- قائمة التغييرات -->
        <div style="background:white; border:1.5px solid <?php echo $isLatest ? '#bbf7d0' : '#e5e7eb'; ?>; border-radius:12px; overflow:hidden;">
            <?php foreach ($entry['changes'] as $changeIndex => $change):
                $cfg = getTypeConfig($change['type']);
                $isLast = ($changeIndex === count($entry['changes']) - 1);
            ?>
            <div style="display:flex; align-items:flex-start; gap:12px; padding:12px 16px; <?php echo !$isLast ? 'border-bottom:1px solid #f3f4f6;' : ''; ?> transition: background 0.15s;" onmouseover="this.style.background='<?php echo $cfg['bg']; ?>'" onmouseout="this.style.background='transparent'">
                <div style="min-width:32px; height:32px; border-radius:8px; background:<?php echo $cfg['bg']; ?>; border:1px solid <?php echo $cfg['border']; ?>; display:flex; align-items:center; justify-content:center; font-size:0.95rem;">
                    <?php echo $cfg['icon']; ?>
                </div>
                <div style="flex:1;">
                    <div style="font-size:0.88rem; color:#374151; line-height:1.6;">
                        <?php echo htmlspecialchars($change['text']); ?>
                    </div>
                    <span style="display:inline-block; margin-top:4px; font-size:0.68rem; font-weight:600; color:<?php echo $cfg['color']; ?>; background:<?php echo $cfg['bg']; ?>; padding:1px 8px; border-radius:4px; border:1px solid <?php echo $cfg['border']; ?>;">
                        <?php echo $cfg['label']; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- دليل الألوان -->
    <div class="card" style="margin-top:1.5rem;">
        <div class="card-header"><h2>دليل أنواع التغييرات</h2></div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
                <?php foreach ($typeConfig as $key => $cfg): ?>
                <div style="display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; background:<?php echo $cfg['bg']; ?>; border:1px solid <?php echo $cfg['border']; ?>;">
                    <span style="font-size:1rem;"><?php echo $cfg['icon']; ?></span>
                    <span style="font-size:0.82rem; font-weight:600; color:<?php echo $cfg['color']; ?>;"><?php echo $cfg['label']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<style>
/* === تنسيق صفحة سجل التحديثات === */
@media (max-width: 768px) {
    .container { padding: 0 8px !important; }
    div[style*="grid-template-columns"] {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
@media (max-width: 480px) {
    div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
