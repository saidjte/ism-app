<?php
/**
 * patch-dashboard-view.php — تحديث قالب لوحة التحكم لعرض القيمة المزدوجة
 */
$baseDir = __DIR__;
$log = [];
$ok = 0;
$err = 0;

function msg($m, $e=false) { global $log,$ok,$err; $log[]=($e?'X ':'OK ').$m; if($e)$err++;else$ok++; }

//////////////////////////////////////////////////////////
// 1. تحديث view.php — استبدال بطاقة المخزون بنسخة مزدوجة
//////////////////////////////////////////////////////////
$f = $baseDir . '/templates/dashboard/view.php';
if (!file_exists($f)) { msg('view.php غير موجود!', true); }

$c = file_get_contents($f);
$o = $c;
$changed = false;

// === الطريقة 1: البحث عن النمط القديم (قيمة واحدة) واستبداله ===
// نبحث عن بطاقة قيمة المخزون ذات القيمة الواحدة
$pattern1 = '/<div class="stat-card green">\s*<div class="stat-icon">.*?<\/div>\s*<div class="stat-info">\s*<h3><\?php echo t\([\'"]dashboard_stock_value[\'"]\); \?><\/h3>\s*<div class="stat-value"><\?php echo formatMoney\(\$total_stock_value\); \?><\/div>\s*<\/div>\s*<\/div>/s';

if (preg_match($pattern1, $c)) {
    $newCard = '<div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t(\'dashboard_stock_value\'); ?></h3>
                <div class="stat-value" style="font-size:1.15rem;">
                    <span style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <span style="display:flex; flex-direction:column; align-items:center;">
                            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t(\'stock_value_at_cost\'); ?></span>
                            <span style="font-size:1.1rem;"><?php echo formatMoney($total_stock_value); ?></span>
                        </span>
                        <span style="display:flex; flex-direction:column; align-items:center;">
                            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t(\'stock_value_at_retail\'); ?></span>
                            <span style="font-size:1.1rem; color:#059669;"><?php echo formatMoney($total_stock_value_retail); ?></span>
                        </span>
                    </span>
                </div>
            </div>
        </div>';
    $c = preg_replace($pattern1, $newCard, $c);
    $changed = true;
    msg('view.php: تم استبدال بطاقة المخزون بالنسخة المزدوجة (pattern 1)');
} else {
    msg('view.php: لم يتطابق النمط 1', true);
}

// === الطريقة 2: إذا كانت البطاقة تستخدم SVG مختلف ===
if (!$changed) {
    $pattern2 = '/<div class="stat-card green">\s*<div class="stat-icon">\s*<svg[^>]*>.*?<\/svg>\s*<\/div>\s*<div class="stat-info">\s*<h3>(<\?php echo t\([\'"]dashboard_stock_value[\'"]\); \?>|قيمة المخزون)<\/h3>\s*<div class="stat-value">.*?<\/div>\s*<\/div>\s*<\/div>/s';

    if (preg_match($pattern2, $c)) {
        $newCard = '<div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3><?php echo t(\'dashboard_stock_value\'); ?></h3>
                <div class="stat-value" style="font-size:1.15rem;">
                    <span style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <span style="display:flex; flex-direction:column; align-items:center;">
                            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t(\'stock_value_at_cost\'); ?></span>
                            <span style="font-size:1.1rem;"><?php echo formatMoney($total_stock_value); ?></span>
                        </span>
                        <span style="display:flex; flex-direction:column; align-items:center;">
                            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t(\'stock_value_at_retail\'); ?></span>
                            <span style="font-size:1.1rem; color:#059669;"><?php echo formatMoney($total_stock_value_retail); ?></span>
                        </span>
                    </span>
                </div>
            </div>
        </div>';
        $c = preg_replace($pattern2, $newCard, $c);
        $changed = true;
        msg('view.php: تم استبدال البطاقة (pattern 2)');
    }
}

// === الطريقة 3: البحث الأبسط — فقط h3 + stat-value ===
if (!$changed) {
    // نبحث عن السطر الذي يحتوي على dashboard_stock_value أو قيمة المخزون
    // ونستبدل stat-value التابعة له
    $lines = explode("\n", $c);
    $foundLine = -1;
    foreach ($lines as $i => $ln) {
        if (stripos($ln, 'dashboard_stock_value') !== false || stripos($ln, 'قيمة المخزون') !== false) {
            $foundLine = $i;
            msg('view.php: تم العثور على عنوان المخزون في سطر ' . ($i+1));
            break;
        }
    }

    if ($foundLine >= 0) {
        // نبحث عن stat-value بعد هذا السطر
        for ($j = $foundLine + 1; $j < min($foundLine + 10, count($lines)); $j++) {
            if (stripos($lines[$j], 'stat-value') !== false && stripos($lines[$j], 'formatMoney') !== false) {
                // هذا هو السطر الذي نريد استبداله
                $oldValLine = $lines[$j];
                $indent = str_repeat(' ', 16); // نفس المسافة البادئة
                $lines[$j] = $indent . '<div class="stat-value" style="font-size:1.15rem;">' . "\n"
                    . $indent . '    <span style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">' . "\n"
                    . $indent . '        <span style="display:flex; flex-direction:column; align-items:center;">' . "\n"
                    . $indent . '            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t(\'stock_value_at_cost\'); ?></span>' . "\n"
                    . $indent . '            <span style="font-size:1.1rem;"><?php echo formatMoney($total_stock_value); ?></span>' . "\n"
                    . $indent . '        </span>' . "\n"
                    . $indent . '        <span style="display:flex; flex-direction:column; align-items:center;">' . "\n"
                    . $indent . '            <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500;"><?php echo t(\'stock_value_at_retail\'); ?></span>' . "\n"
                    . $indent . '            <span style="font-size:1.1rem; color:#059669;"><?php echo formatMoney($total_stock_value_retail); ?></span>' . "\n"
                    . $indent . '        </span>' . "\n"
                    . $indent . '    </span>' . "\n"
                    . $indent . '</div>';
                $changed = true;
                msg('view.php: تم استبدال stat-value بالنسخة المزدوجة (pattern 3)');
                break;
            }
        }
        if ($changed) {
            $c = implode("\n", $lines);
        } else {
            msg('view.php: لم يتم العثور على stat-value بعد العنوان', true);
        }
    }
}

if (!$changed) {
    msg('view.php: لم يتم العثور على أي نمط مطابق!', true);
    // تشخيص: نعرض الأسطر المحيطة بـ stock_value
    $lines = explode("\n", $c);
    foreach ($lines as $i => $ln) {
        if (stripos($ln, 'stock') !== false) {
            $start = max(0, $i - 2);
            $end = min(count($lines), $i + 5);
            msg('--- سطر ' . ($i+1) . '及周边 ---');
            for ($k = $start; $k < $end; $k++) {
                msg('  [' . ($k+1) . '] ' . trim($lines[$k]));
            }
        }
    }
}

if ($c !== $o) {
    file_put_contents($f, $c);
    msg('view.php: تم الحفظ');
}

//////////////////////////////////////////////////////////
// 2. التحقق من وجود $total_stock_value_retail في index-data.php
//////////////////////////////////////////////////////////
$f2 = $baseDir . '/index-data.php';
if (file_exists($f2)) {
    $c2 = file_get_contents($f2);
    if (strpos($c2, 'total_stock_value_retail') !== false) {
        msg('index-data.php: المتغير موجود');
    } else {
        msg('index-data.php: المتغير غير موجود! سيتم إضافته', true);
        // إضافته بعد $total_stock_value
        $c2 = str_replace(
            '$total_stock_value = $stats[\'total_stock_value\'];',
            "$total_stock_value = \$stats['total_stock_value'];\n$total_stock_value_retail = \$stats['total_stock_value_retail'];",
            $c2
        );
        file_put_contents($f2, $c2);
        msg('index-data.php: تم إضافة المتغير');
    }
}

//////////////////////////////////////////////////////////
// 3. التحقق من وجود stock_val_retail في DashboardData.php
//////////////////////////////////////////////////////////
$f3 = $baseDir . '/includes/DashboardData.php';
if (file_exists($f3)) {
    $c3 = file_get_contents($f3);
    if (strpos($c3, 'stock_val_retail') !== false) {
        msg('DashboardData.php: stock_val_retail موجود');
    } else {
        msg('DashboardData.php: stock_val_retail غير موجود!', true);
    }
    if (strpos($c3, 'total_stock_value_retail') !== false) {
        msg('DashboardData.php: total_stock_value_retail موجود في getStats');
    } else {
        msg('DashboardData.php: total_stock_value_retail غير موجود في getStats!', true);
    }
}

// مسح OPcache
if (function_exists('opcache_reset')) @opcache_reset();
msg('تم مسح OPcache');

?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>Patch Dashboard View</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Segoe UI,Tahoma,sans-serif;background:#f1f5f9;padding:2rem;direction:rtl}
.box{max-width:800px;margin:0 auto}
h1{font-size:1.4rem;margin-bottom:1rem;color:#1e293b}
.ll{background:#fff;border-radius:10px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.li{padding:.5rem 0;border-bottom:1px solid #f1f5f9;font-size:.85rem;word-break:break-all}
.li:last-child{border:none}
.ft{margin-top:1.5rem;text-align:center;color:#94a3b8;font-size:.85rem}
</style>
</head>
<body>
<div class="box">
<h1>Patch Dashboard View - النتائج</h1>
<div class="ll">
<?php foreach ($log as $i): ?>
<div class="li"><?php echo htmlspecialchars($i); ?></div>
<?php endforeach; ?>
</div>
<div class="ft">يمكن حذف هذا الملف يدوياً</div>
</div>
</body>
</html>
<?php @unlink(__FILE__);