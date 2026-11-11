<?php
// صفحة تحديث النظام - تطبيق التحديثات التدريجية دون حذف البيانات
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config/upgrades.php';

// التحقق من تسجيل الدخول وصلاحيات المدير
require_once __DIR__ . '/includes/auth.php';
requireAdmin();
require_once __DIR__ . '/includes/csrf.php';

$message = '';
$success = false;
$error_msg = '';
$update_log = [];
$current_db_version = '0.0.0';
$can_connect = false;
$pdo = null;

// محاولة الاتصال بقاعدة البيانات
if (file_exists(__DIR__ . '/config/database.php')) {
    try {
        require_once __DIR__ . '/config/database.php';
        $pdo = getDBConnection();
        $can_connect = true;
        $current_db_version = getCurrentDBVersion($pdo);
    } catch (Exception $e) {
        $can_connect = false;
        $error_msg = 'لا يمكن الاتصال بقاعدة البيانات. يرجى التثبيت أولاً.';
    }
}

// تطبيق التحديثات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade']) && $can_connect) {
    $pending = getPendingUpdates($current_db_version);
    
    if (empty($pending)) {
        $message = 'النظام محدّث بالفعل! لا توجد تحديثات متاحة.';
        $success = true;
    } else {
        $has_error = false;
        $last_error = '';
        
        foreach ($pending as $update) {
            $func = $update['function'];
            try {
                if (!function_exists($func)) {
                    throw new Exception("دالة التحديث غير موجودة: " . $func);
                }
                
                call_user_func($func, $pdo);
                updateDBVersion($pdo, $update['version']);
                
                $update_log[] = [
                    'version' => $update['version'],
                    'description' => $update['description'],
                    'status' => 'success'
                ];
            } catch (Exception $e) {
                $has_error = true;
                ErrorHandler::logException($e, 'upgrade_step');
                $last_error = ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء التحديث');
                $update_log[] = [
                    'version' => $update['version'],
                    'description' => $update['description'],
                    'status' => 'error',
                    'error' => ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء التحديث')
                ];
                // إيقاف التنفيذ عند أول خطأ
                break;
            }
        }
        
        if (!$has_error) {
            // تحديث الإصدار النهائي
            updateDBVersion($pdo, APP_VERSION);
            $current_db_version = APP_VERSION;
            $success = true;
            $message = 'تم تحديث النظام بنجاح! تم تطبيق ' . count($update_log) . ' تحديث(ات).';
        } else {
            // تحديث الإصدار إلى آخر إصدار ناجح
            $current_db_version = getCurrentDBVersion($pdo);
            $error_msg = 'حدث خطأ أثناء التحديث (الإصدار ' . $update['version'] . '): ' . $last_error;
            $success = false;
        }
    }
}

// جلب قائمة التحديثات المعلقة
$pending_updates = $can_connect ? getPendingUpdates($current_db_version) : [];
$is_up_to_date = empty($pending_updates);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحديث النظام</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            direction: rtl;
        }
        .upgrade-card {
            background: #fff;
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 650px;
            width: 100%;
        }
        h1 { text-align: center; font-size: 1.5rem; margin-bottom: 0.5rem; color: #1e293b; }
        .subtitle { text-align: center; color: #64748b; margin-bottom: 2rem; font-size: 0.9rem; }

        .version-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f1f5f9;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .version-box .ver-item {
            text-align: center;
        }
        .version-box .ver-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 4px;
        }
        .version-box .ver-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            font-family: monospace;
        }
        .version-box .ver-value.current { color: #059669; }
        .version-box .ver-value.available { color: #2563eb; }
        .version-box .ver-arrow {
            font-size: 1.5rem;
            color: #94a3b8;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        .status-badge.up-to-date { background: #d1fae5; color: #065f46; }
        .status-badge.needs-update { background: #fef3c7; color: #92400e; }
        .status-badge.error { background: #fee2e2; color: #dc2626; }

        .updates-list {
            margin-bottom: 1.5rem;
        }
        .updates-list h3 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: #334155;
        }
        .update-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #f8fafc;
        }
        .update-item .ver {
            background: #dbeafe;
            color: #1d4ed8;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            font-family: monospace;
            white-space: nowrap;
        }
        .update-item .desc {
            color: #475569;
            font-size: 0.9rem;
        }
        .update-item .check {
            color: #059669;
            font-size: 1.2rem;
        }

        .update-result {
            margin-bottom: 1.5rem;
        }
        .update-result h3 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: #334155;
        }
        .result-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 6px;
        }
        .result-item.success { background: #d1fae5; color: #065f46; }
        .result-item.error { background: #fee2e2; color: #dc2626; }

        .btn {
            display: block; width: 100%; padding: 14px; border: none; border-radius: 10px;
            font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s;
            text-align: center; text-decoration: none;
        }
        .btn-upgrade { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
        .btn-upgrade:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.4); }
        .btn-upgrade:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-home { background: #059669; color: #fff; margin-top: 10px; }
        .btn-home:hover { background: #047857; }

        .alert {
            padding: 14px 18px; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.9rem;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        .divider { border-top: 1px solid #e2e8f0; margin: 1.5rem 0; }

        .info-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
        }
        .info-box h4 { font-size: 0.9rem; color: #334155; margin-bottom: 6px; }
        .info-box p { font-size: 0.8rem; color: #64748b; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="upgrade-card">
        <h1>🔄 تحديث النظام</h1>
        <p class="subtitle">تطبيق التحديثات التدريجية على قاعدة البيانات مع الحفاظ على جميع البيانات</p>

        <?php if (!$can_connect): ?>
            <!-- لا يمكن الاتصال بقاعدة البيانات -->
            <div class="status-badge error">❌ لا يمكن الاتصال بقاعدة البيانات</div>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
            <div class="alert alert-info">
                يرجى <a href="install.php" style="color:#1d4ed8; font-weight:bold;">تثبيت التطبيق أولاً</a> قبل التحديث.
            </div>

        <?php elseif ($is_up_to_date && !$success): ?>
            <!-- النظام محدّث -->
            <div class="version-box">
                <div class="ver-item">
                    <div class="ver-label">الإصدار الحالي</div>
                    <div class="ver-value current"><?php echo $current_db_version; ?></div>
                </div>
                <div class="ver-arrow">✓</div>
                <div class="ver-item">
                    <div class="ver-label">آخر إصدار متاح</div>
                    <div class="ver-value available"><?php echo APP_VERSION; ?></div>
                </div>
            </div>

            <div class="status-badge up-to-date">✅ النظام محدّث بالفعل</div>

            <a href="index.php" class="btn btn-home">🚀 العودة للتطبيق</a>

        <?php elseif ($success): ?>
            <!-- تم التحديث بنجاح -->
            <div class="version-box">
                <div class="ver-item">
                    <div class="ver-label">الإصدار القديم</div>
                    <div class="ver-value"><?php echo $update_log[0]['version'] ?? $current_db_version; ?></div>
                </div>
                <div class="ver-arrow">→</div>
                <div class="ver-item">
                    <div class="ver-label">الإصدار الجديد</div>
                    <div class="ver-value current"><?php echo APP_VERSION; ?></div>
                </div>
            </div>

            <div class="alert alert-success">
                ✅ <?php echo htmlspecialchars($message); ?>
            </div>

            <?php if (!empty($update_log)): ?>
            <div class="update-result">
                <h3>تفاصيل التحديثات المطبقة:</h3>
                <?php foreach ($update_log as $item): ?>
                <div class="result-item <?php echo $item['status']; ?>">
                    <?php if ($item['status'] === 'success'): ?>
                        ✅ <strong>v<?php echo $item['version']; ?></strong> — <?php echo htmlspecialchars($item['description']); ?>
                    <?php else: ?>
                        ❌ <strong>v<?php echo $item['version']; ?></strong> — <?php echo htmlspecialchars($item['description']); ?>
                        <br><small>الخطأ: <?php echo htmlspecialchars($item['error']); ?></small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <a href="index.php" class="btn btn-home">🚀 الدخول للتطبيق</a>

        <?php else: ?>
            <!-- توجد تحديثات متاحة -->
            <div class="version-box">
                <div class="ver-item">
                    <div class="ver-label">الإصدار الحالي</div>
                    <div class="ver-value"><?php echo $current_db_version; ?></div>
                </div>
                <div class="ver-arrow">→</div>
                <div class="ver-item">
                    <div class="ver-label">الإصدار المتاح</div>
                    <div class="ver-value available"><?php echo APP_VERSION; ?></div>
                </div>
            </div>

            <div class="status-badge needs-update">⚠️ توجد <?php echo count($pending_updates); ?> تحديث(ات) متاحة</div>

            <?php if ($error_msg): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <div class="updates-list">
                <h3>التحديثات التي سيتم تطبيقها:</h3>
                <?php foreach ($pending_updates as $update): ?>
                <div class="update-item">
                    <span class="check">○</span>
                    <span class="ver">v<?php echo $update['version']; ?></span>
                    <span class="desc"><?php echo htmlspecialchars($update['description']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <button type="submit" name="upgrade" value="1" class="btn btn-upgrade">
                    🔄 تحديث النظام إلى الإصدار <?php echo APP_VERSION; ?>
                </button>
            </form>

        <?php endif; ?>

        <div class="info-box">
            <h4>ℹ️ حول نظام التحديث</h4>
            <p>
                يتم تطبيق التحديثات بشكل تدريجي مع الحفاظ على جميع البيانات الموجودة.
                لن يتم حذف أو تعديل أي بيانات، بل تُضاف الجداول والأعمدة الجديدة فقط.
                يُنصح بأخذ نسخة احتياطية من قاعدة البيانات قبل التحديث.
            </p>
        </div>
    </div>
</body>
</html>
