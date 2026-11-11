?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحديث النظام - v<?php echo APP_VERSION; ?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:2rem 1rem;direction:rtl}
        .main{max-width:780px;width:100%}
        .card{background:#fff;border-radius:16px;padding:2rem;margin-bottom:1.5rem;box-shadow:0 10px 40px rgba(0,0,0,.2)}
        h1{text-align:center;font-size:1.4rem;margin-bottom:.3rem;color:#1e293b}
        .sub{text-align:center;color:#64748b;margin-bottom:1.5rem;font-size:.85rem}
        .vbar{display:flex;justify-content:center;align-items:center;gap:1rem;background:#f1f5f9;border-radius:12px;padding:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
        .vbox{text-align:center}
        .vlbl{font-size:.75rem;color:#64748b}
        .vnum{font-size:1.3rem;font-weight:800;font-family:monospace}
        .v-cur{color:#059669}.v-tar{color:#2563eb}.v-old{color:#dc2626}
        .varr{font-size:1.3rem;color:#94a3b8}
        .badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:.8rem;font-weight:600}
        .b-ok{background:#d1fae5;color:#065f46}.b-warn{background:#fef3c7;color:#92400e}.b-err{background:#fee2e2;color:#dc2626}.b-info{background:#dbeafe;color:#1e40af}
        .sec{margin-bottom:1.5rem}
        .stitle{display:flex;align-items:center;gap:8px;font-size:1rem;font-weight:700;color:#334155;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:2px solid #e2e8f0}
        .item{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;background:#f8fafc;font-size:.88rem}
        .item.ok{background:#d1fae5;border-color:#a7f3d0}.item.err{background:#fee2e2;border-color:#fecaca}
        .vtag{background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:6px;font-size:.75rem;font-weight:700;font-family:monospace;white-space:nowrap}
        .ttag{font-size:.7rem;padding:2px 6px;border-radius:4px;font-weight:600}
        .t-db{background:#e0e7ff;color:#3730a3}.t-fix{background:#fef3c7;color:#92400e}.t-file{background:#fce7f3;color:#9d174d}
        .desc{color:#475569;flex:1}
        .srow{display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
        .sitem{flex:1;min-width:120px;background:#f8fafc;border-radius:10px;padding:1rem;text-align:center;border:1px solid #e2e8f0}
        .snum{font-size:1.5rem;font-weight:800}.slbl{font-size:.75rem;color:#64748b;margin-top:2px}
        .g{color:#059669}.r{color:#dc2626}.b{color:#2563eb}.p{color:#7c3aed}
        .alert{padding:12px 16px;border-radius:8px;margin-bottom:1rem;font-size:.85rem}
        .a-ok{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
        .a-err{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
        .a-warn{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
        .a-info{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
        .btn{display:block;width:100%;padding:13px;border:none;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none}
        .btn-p{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff}
        .btn-p:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.4)}
        .btn-p:disabled{background:#94a3b8;cursor:not-allowed;transform:none;box-shadow:none}
        .btn-s{background:#059669;color:#fff;margin-top:8px}.btn-s:hover{background:#047857}
        .btn-w{background:#d97706;color:#fff;margin-top:8px}.btn-w:hover{background:#b45309}
        .btn-d{background:#dc2626;color:#fff;margin-top:8px}.btn-d:hover{background:#b91c1c}
        .mlist{max-height:300px;overflow-y:auto}
        .mi{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fff5f5;border-radius:6px;margin-bottom:4px;font-size:.82rem}
        .mi .mf{font-family:monospace;color:#dc2626;font-size:.78rem;direction:ltr;text-align:right}
        .mi .mv{color:#64748b;font-size:.7rem;white-space:nowrap}
        .scr{max-height:400px;overflow-y:auto}
        .btns-row{display:flex;gap:8px;margin-top:10px}
        .btns-row .btn{flex:1}
        .info-box{margin-top:1.5rem;background:#f1f5f9;border-radius:10px;padding:1rem}
        .info-box h4{font-size:.9rem;color:#334155;margin-bottom:6px}
        .info-box p{font-size:.78rem;color:#64748b;line-height:1.7}
        @media(max-width:600px){.card{padding:1.25rem}.srow{flex-direction:column}.vbar{flex-direction:column;gap:.5rem}.btns-row{flex-direction:column}}
        .cl-section{margin-top:1.5rem}
        .cl-toggle{width:100%;padding:12px;border:none;border-radius:10px;background:#f1f5f9;color:#334155;font-size:.95rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s}
        .cl-toggle:hover{background:#e2e8f0}
        .cl-content{display:none;margin-top:1rem}
        .cl-content.active{display:block}
        .cl-version{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px;overflow:hidden}
        .cl-version-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
        .cl-version-num{font-weight:800;font-size:1rem;color:#1e293b;font-family:monospace}
        .cl-version-date{font-size:.78rem;color:#94a3b8}
        .cl-version.latest{border-color:#059669;box-shadow:0 0 0 1px #059669}
        .cl-version.latest .cl-version-head{background:#f0fdf4;border-bottom-color:#bbf7d0}
        .cl-version.latest .cl-version-num{color:#059669}
        .cl-change{display:flex;align-items:flex-start;gap:8px;padding:8px 16px;font-size:.85rem;border-bottom:1px solid #f1f5f9}
        .cl-change:last-child{border-bottom:none}
        .cl-type{display:inline-flex;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:700;white-space:nowrap;margin-top:1px}
        .cl-type-feature{background:#dbeafe;color:#1e40af}
        .cl-type-fix{background:#fee2e2;color:#dc2626}
        .cl-type-improvement{background:#d1fae5;color:#065f46}
        .cl-type-security{background:#fef3c7;color:#92400e}
        .cl-type-refactor{background:#f3e8ff;color:#7c3aed}
        .cl-labels{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;font-size:.72rem}
        .cl-labels span{padding:3px 8px;border-radius:12px;background:#f1f5f9;color:#64748b}
    </style>
</head>
<body>
<div class="main">
    <div class="card">
        <h1>🔄 مركز التحديث والإصلاح</h1>
        <p class="sub">ملف واحد — كل التحديثات + إصلاح الأخطاء من v1.0.0 إلى v<?php echo APP_VERSION; ?></p>

        <?php if (!$can_connect): ?>
            <div class="alert a-err">❌ لا يمكن الاتصال بقاعدة البيانات<?php if ($db_error): ?> — <?php echo htmlspecialchars($db_error); ?><?php endif; ?></div>
            <div class="alert a-info">يرجى <a href="install.php" style="color:#1d4ed8;font-weight:bold;">تثبيت التطبيق أولاً</a>.</div>
        <?php elseif ($check && $check['is_all_ok'] && empty($updateLog)): ?>
            <!-- === محدث بالكامل === -->
            <div class="vbar">
                <div class="vbox"><div class="vlbl">الإصدار الحالي</div><div class="vnum v-cur"><?php echo $check['db_version']; ?></div></div>
                <div class="varr">✓</div>
                <div class="vbox"><div class="vlbl">آخر إصدار</div><div class="vnum v-cur"><?php echo APP_VERSION; ?></div></div>
            </div>
            <div style="text-align:center;margin-bottom:1rem;">
                <span class="badge b-ok">✅ النظام محدّث بالكامل — بدون أخطاء</span>
            </div>
            <div style="text-align:center;margin-bottom:1rem;font-size:.85rem;color:#64748b;">
                الجداول: <?php echo count($check['existing_tables']); ?>/<?php echo $check['total_tables']; ?> |
                الملفات: <?php echo $check['present_files']; ?>/<?php echo $check['total_files']; ?>
            </div>
            <a href="index.php" class="btn btn-s">🚀 العودة للتطبيق</a>
            <?php if (file_exists($baseDir . '/deployer.php')): ?>
            <a href="deployer.php" class="btn btn-w" style="margin-top:8px;">🚀 نشر تحديث من ZIP (الناشر الذري المستقل)</a>
            <?php endif; ?>

        <?php elseif ($msgType === 'success'): ?>
            <!-- === تم التحديث === -->
            <div class="vbar">
                <div class="vbox"><div class="vlbl">الإصدار السابق</div><div class="vnum v-old"><?php echo $updateLog[0]['ver'] ?? $currentVersion; ?></div></div>
                <div class="varr">→</div>
                <div class="vbox"><div class="vlbl">الإصدار الجديد</div><div class="vnum v-cur"><?php echo APP_VERSION; ?></div></div>
            </div>
            <div class="alert a-ok">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php if (!empty($updateLog)): ?>
            <div class="sec">
                <div class="stitle">📋 تفاصيل العمليات</div>
                <div class="scr">
                    <?php foreach ($updateLog as $i): ?>
                    <div class="item <?php echo $i['ok'] ? 'ok' : 'err'; ?>">
                        <span><?php echo $i['ok'] ? '✅' : '❌'; ?></span>
                        <span class="vtag">v<?php echo $i['ver']; ?></span>
                        <?php
                            $typeLabel = 'DB';
                            $typeClass = 't-db';
                            if ($i['type'] === 'fix') { $typeLabel = 'إصلاح'; $typeClass = 't-fix'; }
                            elseif ($i['type'] === 'repair') { $typeLabel = 'إصلاح جداول'; $typeClass = 't-fix'; }
                            elseif ($i['type'] === 'backup') { $typeLabel = 'نسخة احتياطية'; $typeClass = 't-file'; }
                            elseif ($i['type'] === 'rollback') { $typeLabel = 'تراجع'; $typeClass = 't-fix'; }
                            elseif ($i['type'] === 'deploy') { $typeLabel = 'نشر'; $typeClass = 't-file'; }
                        ?>
                        <span class="ttag <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span>
                        <span class="desc"><?php echo htmlspecialchars($i['desc']); ?></span>
                        <?php if (!empty($i['detail'])): ?><br><small style="color:#065f46;"><?php echo htmlspecialchars($i['detail']); ?></small><?php endif; ?>
                        <?php if (!empty($i['warnings'])): ?><br><small style="color:#92400e;">⚠️ <?php echo htmlspecialchars($i['warnings']); ?></small><?php endif; ?>
                        <?php if (!empty($i['err'])): ?><br><small style="color:#dc2626;">❌ <?php echo htmlspecialchars($i['err']); ?></small><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($check && $check['missing_files']): ?>
            <div class="alert a-warn">⚠️ يوجد <?php echo count($check['missing_files']); ?> ملف(ات) مفقود(ة) — يجب نسخها يدوياً</div>
            <?php endif; ?>
            <a href="index.php" class="btn btn-s">🚀 الدخول للتطبيق</a>

        <?php else: ?>
            <!-- === توجد تحديثات / أخطاء / جداول مفقودة === -->
            <?php if ($check && $check['db_version'] === APP_VERSION): ?>
            <div class="vbar">
                <div class="vbox"><div class="vlbl">الإصدار الحالي</div><div class="vnum v-cur"><?php echo $check['db_version']; ?></div></div>
                <div class="varr">✓</div>
                <div class="vbox"><div class="vlbl">آخر إصدار</div><div class="vnum v-cur"><?php echo APP_VERSION; ?></div></div>
            </div>
            <?php else: ?>
            <div class="vbar">
                <div class="vbox"><div class="vlbl">إصدار قاعدة البيانات</div><div class="vnum v-old"><?php echo $check ? $check['db_version'] : $currentVersion; ?></div></div>
                <div class="varr">→</div>
                <div class="vbox"><div class="vlbl">الإصدار المطلوب</div><div class="vnum v-tar"><?php echo APP_VERSION; ?></div></div>
            </div>
            <?php endif; ?>

            <?php if ($msgType === 'error'): ?>
            <div class="alert a-err">❌ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <!-- ملخص -->
            <?php if ($check): ?>
            <div class="srow">
                <div class="sitem"><div class="snum b"><?php echo count($check['pending_db']); ?></div><div class="slbl">تحديثات DB</div></div>
                <div class="sitem"><div class="snum <?php echo $check['missing_files'] ? 'r' : 'g'; ?>"><?php echo count($check['missing_files']); ?></div><div class="slbl">ملفات مفقودة</div></div>
                <div class="sitem"><div class="snum <?php echo $check['missing_tables'] ? 'r' : 'g'; ?>"><?php echo count($check['missing_tables']); ?></div><div class="slbl">جداول مفقودة</div></div>
                <div class="sitem"><div class="snum <?php echo $check['pending_fixes'] ? 'p' : 'g'; ?>"><?php echo count($check['pending_fixes']); ?></div><div class="slbl">أخطاء كود</div></div>
                <div class="sitem"><div class="snum g"><?php echo $check['present_files']; ?>/<?php echo $check['total_files']; ?></div><div class="slbl">ملفات موجودة</div></div>
            </div>
            <?php endif; ?>

            <!-- 1. تحديثات قاعدة البيانات -->
            <?php if ($check && !empty($check['pending_db'])): ?>
            <div class="sec">
                <div class="stitle">🗄️ تحديثات قاعدة البيانات (<?php echo count($check['pending_db']); ?>)</div>
                <div class="scr">
                    <?php foreach ($check['pending_db'] as $u): ?>
                    <div class="item">
                        <span style="color:#94a3b8">○</span>
                        <span class="vtag">v<?php echo $u['version']; ?></span>
                        <span class="ttag t-db">DB</span>
                        <span class="desc"><?php echo htmlspecialchars($u['desc']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif ($check): ?>
            <div class="sec"><div class="stitle">🗄️ قاعدة البيانات</div><div class="alert a-ok">✅ محدّثة (v<?php echo $check['db_version']; ?>)</div></div>
            <?php endif; ?>

            <!-- 2. إصلاح الأخطاء -->
            <?php if ($check && !empty($check['pending_fixes'])): ?>
            <div class="sec">
                <div class="stitle">🔧 إصلاح أخطاء الكود (<?php echo count($check['pending_fixes']); ?>)</div>
                <div class="scr">
                    <?php foreach ($check['pending_fixes'] as $f): ?>
                    <div class="item" style="background:#fef3c7;border-color:#fde68a;">
                        <span style="color:#d97706">⚠️</span>
                        <span class="vtag">إصلاح</span>
                        <span class="ttag t-fix">BUG</span>
                        <span class="desc"><?php echo htmlspecialchars($f['desc']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="alert a-warn">⚠️ هذه الأخطاء يمكن إصلاحها تلقائياً بالضغط على زر "تحديث الكل" أدناه</div>
            </div>
            <?php elseif ($check): ?>
            <div class="sec"><div class="stitle">🔧 إصلاح الأخطاء</div><div class="alert a-ok">✅ لا توجد أخطاء معروفة</div></div>
            <?php endif; ?>

            <!-- 3. ملفات مفقودة -->
            <?php if ($check && $check['missing_files']): ?>
            <div class="sec">
                <div class="stitle">📄 ملفات مفقودة (<?php echo count($check['missing_files']); ?>)</div>
                <div class="alert a-warn">يجب نسخ هذه الملفات يدوياً من حزمة التحديث</div>
                <div class="mlist scr">
                    <?php foreach ($check['missing_files'] as $m): ?>
                    <div class="mi">
                        <span>❌</span>
                        <span class="mf"><?php echo htmlspecialchars($m['file']); ?></span>
                        <span class="mv">v<?php echo $m['version']; ?></span>
                        <span class="desc"><?php echo htmlspecialchars($m['desc']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="sec"><div class="stitle">📄 فحص الملفات</div><div class="alert a-ok">✅ كل الملفات موجودة (<?php echo $check ? $check['present_files'] . '/' . $check['total_files'] : ''; ?>)</div></div>
            <?php endif; ?>

            <!-- 4. جداول مفقودة -->
            <?php if ($check && $check['missing_tables']): ?>
            <div class="sec">
                <div class="stitle">📊 جداول مفقودة (<?php echo count($check['missing_tables']); ?>)</div>
                <div class="alert a-err" style="font-weight:600;">❌ هناك <?php echo count($check['missing_tables']); ?> جدول(ات) مفقود(ة) — قد يمنع ذلك بعض الوظائف من العمل بشكل صحيح</div>
                <div class="scr">
                    <?php foreach ($check['missing_tables'] as $t): ?>
                    <div class="item" style="background:#fee2e2;border-color:#fecaca;"><span style="color:#dc2626">❌</span><span class="desc" style="font-family:monospace;direction:ltr;text-align:right;font-weight:600;"><?php echo htmlspecialchars($t); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="sec"><div class="stitle">📊 فحص الجداول</div><div class="alert a-ok">✅ كل الجداول موجودة (<?php echo $check ? count($check['existing_tables']) . '/' . $check['total_tables'] : ''; ?>)</div></div>
            <?php endif; ?>

            <!-- 5. نشر ذري حقيقي (True Atomic Deploy) -->
            <div class="sec">
                <div class="stitle">📦 نشر تحديث من ملف ZIP (True Atomic Deploy)</div>
                <?php if (file_exists($baseDir . '/deployer.php')): ?>
                <div class="alert a-ok" style="margin-bottom:10px;">
                    ✅ <strong>الناشر الذري المستقل (deployer.php) متاح!</strong> — يمكنك نشر أي تحديث ZIP بدون الحاجة لنشر الملفات يدوياً أولاً.
                    <a href="deployer.php" style="color:#065f46;font-weight:bold;text-decoration:underline;">→ الانتقال للناشر المستقل</a>
                </div>
                <?php else: ?>
                <div class="alert a-warn" style="margin-bottom:10px;">
                    ⚠️ <strong>الناشر الذري المستقل (deployer.php) غير موجود!</strong> — للحصول على النشر الذري المتاح دائماً، يرجى رفع ملف deployer.php إلى المجلد الجذري للتطبيق.
                </div>
                <?php endif; ?>
                <div class="alert a-info">
                    <strong>آلية النشر الذري (Write-Then-Rename):</strong><br>
                    1. فحص مسبق (صلاحيات + مساحة قرص + مكتبة ZipArchive)<br>
                    2. تفعيل وضع الصيانة تلقائياً (حجب الطلبات)<br>
                    3. فك ضغط ZIP في staging + اكتشاف المجلد الجذري تلقائياً<br>
                    4. تحقق أمني من المسارات (حماية Path Traversal / Zip Slip)<br>
                    5. نسخ احتياطي للملفات الحالية التي ستُستبدل (للتراجع)<br>
                    6. <strong>استبدال ذري لكل ملف: write(tmp) ← rename(tmp, target)</strong> — ذري على نفس نظام الملفات حسب POSIX<br>
                    7. حماية الملفات الحرجة (.htaccess + config/database.php لا تُستبدل)<br>
                    8. إزالة وضع الصيانة + تراجع تلقائي عند الفشل
                </div>
                <form method="POST" action="" enctype="multipart/form-data" style="margin-top:10px;">
                    <input type="hidden" name="action" value="deploy_zip">
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="file" name="zip_file" accept=".zip" required style="flex:1;min-width:200px;padding:10px;border:2px dashed #94a3b8;border-radius:10px;font-size:.9rem;background:#f8fafc;">
                        <button type="submit" class="btn btn-p" style="width:auto;padding:10px 24px;font-size:.9rem;white-space:nowrap;" onclick="return confirm('سيتم نشر ملفات التحديث بشكل ذري (Write-Then-Rename).\n\n• يتم تفعيل وضع الصيانة تلقائياً\n• يتم نسخ الملفات الحالية احتياطياً قبل الاستبدال\n• كل ملف يُستبدل ذرياً بعملية rename (POSIX guarantee)\n• الملفات الحرجة (.htaccess + config/database.php) لا تُستبدل\n• في حالة الفشل يتم التراجع تلقائياً\n\nسيتم حجب الطلبات مؤقتاً أثناء النشر.\n\nهل تريد المتابعة؟')">
                            🚀 نشر ذري
                        </button>
                    </div>
                </form>
                <div style="margin-top:6px;font-size:.72rem;color:#94a3b8;text-align:center;">💡 يدعم حزم ZIP ذات مجلد جذر واحد تلقائياً — لا حاجة لاستخراج الملفات يدوياً</div>
                <?php
                $lastDeploy = getLastDeployManifest($baseDir);
                if ($lastDeploy):
                ?>
                <div style="margin-top:10px;padding:10px;background:#f1f5f9;border-radius:8px;font-size:.82rem;">
                    <?php if ($lastDeploy): ?>
                    <strong>آخر نشر:</strong> <?php echo htmlspecialchars($lastDeploy['timestamp'] ?? ''); ?> |
                    <strong>الطريقة:</strong> <?php echo htmlspecialchars($lastDeploy['method'] ?? 'file-by-file'); ?> |
                    <strong>الملفات:</strong> <?php echo count($lastDeploy['files'] ?? []); ?>
                    <?php if (isset($lastDeploy['zip_root_prefix']) && !empty($lastDeploy['zip_root_prefix'])): ?> |
                    <strong>مجلد ZIP:</strong> <?php echo htmlspecialchars(rtrim($lastDeploy['zip_root_prefix'], '/')); ?>
                    <?php endif; ?>
                    <?php endif; ?>
                    <form method="POST" action="" style="display:inline;margin:0;padding:0;margin-top:6px;" onsubmit="return confirm('⚠️ سيتم استعادة الملفات القديمة وحذف ملفات التحديث المنشورة.\n\nيتم التراجع تلقائياً: استعادة النسخ الاحتياطية + حذف الملفات الجديدة.\n\nهل أنت متأكد؟');">
                        <input type="hidden" name="action" value="rollback_files">
                        <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:4px 12px;border-radius:6px;font-size:.78rem;cursor:pointer;font-weight:600;">
                            ⏪ تراجع عن نشر الملفات
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- 6. قسم التراجع (Rollback) -->
            <?php
            $availableRollbacks = $can_connect ? getAvailableRollbacks() : [];
            ?>
            <?php if (!empty($availableRollbacks)): ?>
            <div class="sec">
                <div class="stitle">⏪ التراجع عن ترقية (Rollback)</div>
                <div class="alert a-info">يمكنك التراجع عن ترقية سابقة واستعادة قاعدة البيانات إلى حالة ما قبل الترقية. يتم إنشاء نسخة احتياطية تلقائياً قبل كل ترقية.</div>
                <div class="scr" style="margin-top:8px">
                    <?php foreach ($availableRollbacks as $rb): ?>
                    <div class="item" style="background:#eff6ff;border-color:#bfdbfe;flex-wrap:wrap;">
                        <span style="color:#2563eb">💾</span>
                        <span class="vtag">v<?php echo htmlspecialchars($rb['version_before'] ?? ''); ?></span>
                        <span class="ttag" style="background:#dbeafe;color:#1e40af;">نسخة</span>
                        <span class="desc">
                            قبل الترقية — <?php echo htmlspecialchars($rb['timestamp'] ?? ''); ?>
                            <br><small style="color:#64748b;">
                                <?php echo $rb['tables_count'] ?? 0; ?> جدول |
                                <?php echo $rb['file_size_formatted'] ?? ''; ?>
                                <?php if (!empty($rb['compressed'])): ?> | مضغوط<?php endif; ?>
                            </small>
                        </span>
                        <form method="POST" action="" style="margin:0;padding:0;" onsubmit="return confirm('⚠️ سيتم حذف قاعدة البيانات الحالية واستبدالها بالنسخة الاحتياطية v<?php echo htmlspecialchars($rb['version_before'] ?? ''); ?>.\n\nهذا الإجراء لا يمكن التراجع عنه!\n\nهل أنت متأكد؟');">
                            <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($rb['sql_file']); ?>">
                            <button type="submit" name="action" value="rollback" class="btn btn-d" style="width:auto;padding:8px 16px;font-size:.8rem;display:inline-block;margin:0;">
                                ⏪ تراجع إلى v<?php echo htmlspecialchars($rb['version_before'] ?? ''); ?>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- أزرار التحديث -->
            <div class="sec" style="border-top:2px solid #e2e8f0; padding-top:1rem;">
                <?php if (!empty($check['pending_db']) || !empty($check['pending_fixes'])): ?>
                <form method="POST" action="">
                    <button type="submit" name="action" value="upgrade_all" class="btn btn-p">
                        🔄 تحديث الكل (قاعدة بيانات + إصلاح أخطاء) إلى v<?php echo APP_VERSION; ?>
                    </button>
                </form>
                <div style="margin-top:6px;font-size:.78rem;color:#64748b;text-align:center;">💡 سيتم إنشاء نسخة احتياطية تلقائياً قبل التحديث — يمكن التراجع إذا فشلت الترقية</div>
                <?php endif; ?>
                <?php if (empty($check['pending_db']) && !empty($check['pending_fixes'])): ?>
                <form method="POST" action="" style="margin-top:8px">
                    <button type="submit" name="action" value="fix_only" class="btn btn-w">
                        🔧 إصلاح الأخطاء فقط (بدون تحديث DB)
                    </button>
                </form>
                <?php endif; ?>
                <?php if (!empty($check['missing_tables'])): ?>
                <form method="POST" action="" style="margin-top:8px">
                    <button type="submit" name="action" value="repair_tables" class="btn btn-p" style="background:#dc2626;" onclick="return confirm('سيتم إنشاء الجداول المفقودة. هل تريد المتابعة؟')">
                        🔧 إصلاح الجداول المفقودة (<?php echo count($check['missing_tables']); ?> جدول)
                    </button>
                </form>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        <!-- معلومات -->
        <div class="info-box">
            <h4>ℹ️ حول مركز التحديث والإصلاح</h4>
            <p>
                هذا الملف الوحيد الشامل الذي يدعم <strong>كل التحديثات من v1.0.0 إلى v<?php echo APP_VERSION; ?></strong>:<br>
                <strong>1. قاعدة البيانات</strong> — جداول، أعمدة، فهارس، بيانات افتراضية<br>
                <strong>2. إصلاح أخطاء الكود</strong> — إصلاح تلقائي لملفات PHP (مثل AppNotification غير موجود)<br>
                <strong>3. فحص الملفات</strong> — التأكد من وجود كل ملفات كل إصدار<br>
                <strong>4. فحص الجداول</strong> — التأكد من وجود كل الجداول الأساسية<br>
                <strong>5. تراجع تلقائي (Rollback)</strong> — نسخة احتياطية تلقائية قبل الترقية + تراجع تلقائي عند الفشل + تراجع يدوي<br>
                <strong>6. نشر ذري (True Atomic Deploy)</strong> — Write-Then-Rename + وضع صيانة تلقائي + نسخ احتياطي قبل النشر + تراجع عند الفشل + حماية الملفات الحرجة<br><br>
                💡 يتم إنشاء <strong>نسخة احتياطية تلقائياً</strong> قبل كل ترقية — لست بحاجة لنسخ يدوي.<br>
                🔄 إذا فشلت الترقية في المنتصف، يتم <strong>التراجع تلقائياً</strong> إلى الحالة السابقة.<br>
                📦 لا حاجة لملف <code>config/upgrades.php</code> — هذا الملف مستقل تماماً.
            </p>
        </div>

        <!-- سجل التغييرات -->
        <div class="cl-section">
            <button type="button" class="cl-toggle" onclick="document.getElementById('changelogContent').classList.toggle('active'); this.textContent = document.getElementById('changelogContent').classList.contains('active') ? '📜 إخفاء سجل التغييرات' : '📜 عرض سجل التغييرات (CHANGELOG)';">
                📜 عرض سجل التغييرات (CHANGELOG)
            </button>
            <div class="cl-content" id="changelogContent">
                <div class="cl-labels">
                    <span>🏷️ أنواع التغييرات:</span>
                    <span class="cl-type cl-type-feature">ميزة جديدة</span>
                    <span class="cl-type cl-type-fix">إصلاح خطأ</span>
                    <span class="cl-type cl-type-improvement">تحسين</span>
                    <span class="cl-type cl-type-security">أمان</span>
                    <span class="cl-type cl-type-refactor">إعادة هيكلة</span>
                </div>
                <?php
                $typeLabels = ['feature'=>'ميزة جديدة','fix'=>'إصلاح','improvement'=>'تحسين','security'=>'أمان','refactor'=>'هيكلة'];
                foreach ($CHANGELOG as $entry):
                    $isLatest = ($entry['version'] === APP_VERSION);
                ?>
                <div class="cl-version <?php echo $isLatest ? 'latest' : ''; ?>">
                    <div class="cl-version-head">
                        <span class="cl-version-num">v<?php echo $entry['version']; ?><?php echo $isLatest ? ' ✨ أحدث إصدار' : ''; ?></span>
                        <span class="cl-version-date"><?php echo $entry['date']; ?> — <?php echo count($entry['changes']); ?> تغيير(ات)</span>
                    </div>
                    <?php foreach ($entry['changes'] as $c): ?>
                    <div class="cl-change">
                        <span class="cl-type cl-type-<?php echo $c['type']; ?>"><?php echo $typeLabels[$c['type']] ?? $c['type']; ?></span>
                        <span style="color:#334155;"><?php echo htmlspecialchars($c['text']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
