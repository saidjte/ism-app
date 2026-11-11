<?php
require_once 'includes/bootstrap.php';
// W5 إصلاح: CaisseHelper يُحمّل تلقائياً من includes/bootstrap.php (includes/CaisseHelper.php)
// v6.1.3: تمت إزالة require_once للنسخة المكررة في الجذر — كان سيُسبب "Cannot redeclare class CaisseHelper"
$page_title = t('caisse_title');
require_once __DIR__ . '/includes/permissions.php';
requireLogin();
requirePermission('caisse.view');

$pdo = getDBConnection();
$today = date('Y-m-d');

// === إعدادات نقطة البيع (POS) ===
$pos_theme = getPosSetting('pos_theme', 'light');
$pos_sound_enabled = getPosSetting('pos_sound_enabled', '1') === '1';
$pos_auto_complete = getPosSetting('pos_auto_complete', '0') === '1';

// actual_column مضمون من upgrade.php (db_upgrade_4_9_76)
$has_actual_column = true;

// === POST Handler ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    
    $action = $_POST['action'] ?? '';
    
    // === حفظ مبلغ الإنطلاق العام للمشروع ===
    if ($action === 'save_project_amount') {
        $project_start = floatval($_POST['project_start_amount'] ?? 0);
        
        $existing = fetchOne("SELECT id FROM system_settings WHERE setting_key = ?", ['project_start_amount']);
        if ($existing) {
            $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$project_start, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                ->execute(['project_start_amount', $project_start, t('caisse_project_start_desc')]);
        }
        
        header("Location: caisse.php?saved_project=1");
        exit;
    }
    
    // === إضافة حركة صندوق (إيداع أو سحب) ===
    if ($action === 'add_transaction') {
        $type = $_POST['tx_type'] ?? '';
        $amount = floatval($_POST['tx_amount'] ?? 0);
        $description = trim($_POST['tx_description'] ?? '');
        $tx_date = $_POST['tx_date'] ?? $today;
        
        if (in_array($type, ['deposit', 'withdrawal']) && $amount > 0) {
            $pdo->prepare("INSERT INTO caisse_transactions (type, amount, description, transaction_date, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$type, $amount, $description, $tx_date, $_SESSION['user_id'] ?? null]);
            
            header("Location: caisse.php?tx_saved=1");
            exit;
        }
    }
    
    // === حذف حركة صندوق ===
    if ($action === 'delete_transaction') {
        $tx_id = intval($_POST['tx_id'] ?? 0);
        if ($tx_id > 0) {
            $pdo->prepare("DELETE FROM caisse_transactions WHERE id = ?")->execute([$tx_id]);
            header("Location: caisse.php?tx_deleted=1");
            exit;
        }
    }
    
    // === تسجيل العد الفعلي (اختياري) ===
    if ($action === 'save_actual' && $has_actual_column) {
        $actual_amount = floatval($_POST['actual_amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        
        CaisseHelper::autoCreateTodayRecord($pdo, $today);
        $existing = fetchOne("SELECT id FROM caisse WHERE record_date = ?", [$today]);
        if ($existing) {
            $pdo->prepare("UPDATE caisse SET actual_amount = ?, note = ?, created_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$actual_amount, $note, $_SESSION['user_id'] ?? null, $existing['id']]);
        }
        header("Location: caisse.php?saved_actual=1");
        exit;
    }
}

// === جلب حركة للحذف (تأكيد) ===
$confirm_delete_id = null;
$confirm_delete_info = null;
if (isset($_GET['confirm_delete'])) {
    $confirm_delete_id = intval($_GET['confirm_delete']);
    $confirm_delete_info = fetchOne("SELECT ct.*, u.full_name as creator_name 
                                      FROM caisse_transactions ct 
                                      LEFT JOIN users u ON ct.created_by = u.id 
                                      WHERE ct.id = ?", [$confirm_delete_id]);
}

// W5 إصلاح: تم حذف الدوال المكررة getNetTransactions / autoCreateTodayRecord / generateMissingRecords
// كافة الاستدعاءات الآن موجهة إلى CaisseHelper::methodName() (المُحمَّل في أعلى الملف)

// === إنشاء/تحديث سجل اليوم تلقائياً ===
$today_data = CaisseHelper::autoCreateTodayRecord($pdo, $today);

// === إجماليات المشروع ===
$project_start_row = fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = ?", ['project_start_amount']);
$project_start_amount = $project_start_row ? floatval($project_start_row['setting_value']) : 0;

$total_sales = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales")['total'] ?? 0);
$total_purchases = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM purchases")['total'] ?? 0);
try { $total_expenses = floatval(fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM expenses")['total'] ?? 0);
} catch (Exception $e) { $total_expenses = 0; }

// إجمالي حركات الصندوق
$total_tx = CaisseHelper::getNetTransactions($pdo, '2099-12-31', true);
$total_deposits = $total_tx['deposits'];
$total_withdrawals = $total_tx['withdrawals'];

$project_current_amount = $project_start_amount + $total_sales - $total_purchases - $total_expenses + $total_deposits - $total_withdrawals;

// === بيانات اليوم ===
$today_record = fetchOne("SELECT * FROM caisse WHERE record_date = ?", [$today]);
$start_amount = floatval($today_data['start']);
$current_amount = floatval($today_data['current']);
$today_sales = $today_data['sales'];
$today_purchases = $today_data['purchases'];
$today_expenses = $today_data['expenses'];
$today_deposits = $today_data['deposits'];
$today_withdrawals = $today_data['withdrawals'];

$actual_amount = null;
$note = $today_record ? ($today_record['note'] ?? '') : '';

if ($has_actual_column && $today_record) {
    try {
        $actual_row = fetchOne("SELECT actual_amount FROM caisse WHERE record_date = ?", [$today]);
        if ($actual_row && $actual_row['actual_amount'] !== null) $actual_amount = floatval($actual_row['actual_amount']);
    } catch (Exception $e) {}
}
$difference = ($actual_amount !== null) ? ($actual_amount - $current_amount) : null;

// === توليد السجلات المفقودة ===
CaisseHelper::generateMissingRecords($pdo, $project_start_amount);

// === سجل آخر 15 يوم ===
$recent_records = fetchAll("SELECT c.record_date, c.start_amount, c.current_amount, c.note,
                                   u.full_name as creator_name
                            FROM caisse c 
                            LEFT JOIN users u ON c.created_by = u.id 
                            ORDER BY c.record_date DESC 
                            LIMIT 15");

// === حركات الصندوق الأخيرة (20 حركة) ===
$recent_transactions = fetchAll("SELECT ct.*, u.full_name as creator_name 
                                  FROM caisse_transactions ct 
                                  LEFT JOIN users u ON ct.created_by = u.id 
                                  ORDER BY ct.transaction_date DESC, ct.created_at DESC 
                                  LIMIT 20");

require_once 'includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <rect x="2" y="6" width="20" height="14" rx="2"/>
                <path d="M2 10h20"/>
                <path d="M6 14h.01"/>
                <path d="M10 14h.01"/>
            </svg>
            <?php echo t('caisse_title'); ?> (Caisse)
        </h1>
        <?php if (isset($_GET['saved_project'])): ?>
        <span class="badge badge-success" style="font-size:0.85rem; padding:6px 14px;">✓ <?php echo t('caisse_project_amount_saved'); ?></span>
        <?php elseif (isset($_GET['tx_saved'])): ?>
        <span class="badge badge-success" style="font-size:0.85rem; padding:6px 14px;">✓ <?php echo t('caisse_transaction_added'); ?></span>
        <?php elseif (isset($_GET['tx_deleted'])): ?>
        <span class="badge" style="font-size:0.85rem; padding:6px 14px; background:#fef2f2; color:#dc2626;">✓ <?php echo t('caisse_transaction_deleted'); ?></span>
        <?php elseif (isset($_GET['saved_actual'])): ?>
        <span class="badge badge-success" style="font-size:0.85rem; padding:6px 14px;">✓ <?php echo t('caisse_actual_count_saved'); ?></span>
        <?php endif; ?>
    </div>

    <!-- ===== رصيد المشروع الكلي ===== -->
    <div class="card" style="border: 2px solid #f59e0b; margin-bottom: 1.5rem;">
        <div class="card-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 1rem 1.25rem;">
            <h2 style="color: white; margin: 0; font-size: 1.1rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
                <?php echo t('caisse_project_balance'); ?>
            </h2>
        </div>
        <div class="card-body" style="padding: 1.25rem;">
            <div class="stats-grid" style="margin-bottom: 1.25rem;">
                <div class="stat-card" style="border-right-color: #f59e0b;">
                    <div class="stat-icon" style="background: #fef3c7; color: #f59e0b;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo t('caisse_opening'); ?></h3>
                        <div class="stat-value" style="color: #d97706;"><?php echo formatMoney($project_start_amount); ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo t('caisse_total_sales'); ?></h3>
                        <div class="stat-value text-success"><?php echo formatMoney($total_sales); ?></div>
                    </div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo t('caisse_total_purchases'); ?></h3>
                        <div class="stat-value text-danger"><?php echo formatMoney($total_purchases); ?></div>
                    </div>
                </div>
                <div class="stat-card" style="border-right-color: #be123c;">
                    <div class="stat-icon" style="background: #ffe4e6; color: #be123c;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo t('caisse_total_expenses'); ?></h3>
                        <div class="stat-value" style="color:#be123c;"><?php echo formatMoney($total_expenses); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- إيداعات ومسحوبات -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 0.75rem 1rem; text-align: center;">
                    <div style="font-size: 0.8rem; color: var(--gray-500);">💰 <?php echo t('caisse_total_deposits'); ?></div>
                    <div style="font-size: 1.3rem; font-weight: 800; color: #059669;"><?php echo formatMoney($total_deposits); ?></div>
                </div>
                <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 0.75rem 1rem; text-align: center;">
                    <div style="font-size: 0.8rem; color: var(--gray-500);">💸 <?php echo t('caisse_total_withdrawals'); ?></div>
                    <div style="font-size: 1.3rem; font-weight: 800; color: #dc2626;"><?php echo formatMoney($total_withdrawals); ?></div>
                </div>
            </div>

            <!-- الرصيد الحالي -->
            <div style="padding: 1.5rem; border-radius: 12px; text-align: center; margin-bottom: 1.25rem;
                background: <?php echo $project_current_amount >= 0 ? 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)' : 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)'; ?>;
                border: 2px solid <?php echo $project_current_amount >= 0 ? '#86efac' : '#fca5a5'; ?>;">
                <div style="font-size: 0.9rem; color: var(--gray-600); margin-bottom: 6px; font-weight: 600;">💰 <?php echo t('caisse_project_current_balance'); ?></div>
                <div style="font-size: 2.5rem; font-weight: 900; font-family: monospace; letter-spacing: 1px;
                    color: <?php echo $project_current_amount >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php echo number_format($project_current_amount, 2); ?> <?php echo getCurrencySymbol(); ?>
                </div>
                <div style="font-size: 0.72rem; color: var(--gray-400); margin-top: 6px;">
                    <?php echo t('caisse_starting'); ?> (<?php echo number_format($project_start_amount, 2); ?>)
                    + <?php echo t('sales'); ?> (<?php echo number_format($total_sales, 2); ?>)
                    + <?php echo t('caisse_deposits'); ?> (<?php echo number_format($total_deposits, 2); ?>)
                    - <?php echo t('purchases'); ?> (<?php echo number_format($total_purchases, 2); ?>)
                    - <?php echo t('expenses'); ?> (<?php echo number_format($total_expenses, 2); ?>)
                    - <?php echo t('caisse_withdrawals'); ?> (<?php echo number_format($total_withdrawals, 2); ?>)
                </div>
            </div>

            <!-- نموذج تعديل مبلغ الإنطلاق -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_project_amount">
                <?php echo csrfField(); ?>
                <div style="max-width: 500px; margin: 0 auto;">
                    <div class="form-group" style="margin-bottom: 0.75rem;">
                        <label style="font-weight: 700; font-size: 0.95rem;"><?php echo t('caisse_project_start_label'); ?></label>
                        <input type="number" name="project_start_amount" class="form-control" step="0.01" min="0" 
                               value="<?php echo number_format($project_start_amount, 2, '.', ''); ?>" placeholder="0.00" required 
                               style="font-size: 1.3rem; font-weight: 800; text-align: center; padding: 14px; border: 2px solid #f59e0b; border-radius: 10px;">
                    </div>
                    <button type="submit" class="btn" 
                            style="width: 100%; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; padding: 12px; font-size: 1rem; font-weight: 700; border-radius: 10px; cursor: pointer;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="vertical-align: middle; margin-left: 4px;"><path d="M5 13l4 4L19 7"/></svg>
                        <?php echo t('caisse_save_start_amount'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== إضافة حركة صندوق (إيداع / سحب) ===== -->
    <div class="card" id="tx_card" style="border: 2px solid #059669; margin-bottom: 1.5rem; transition: border-color 0.3s ease;">
        <div class="card-header" id="tx_card_header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 1rem 1.25rem; transition: background 0.3s ease;">
            <h2 style="color: white; margin: 0; font-size: 1.1rem;">
                <svg id="tx_title_icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M12 4v16m8-8H4"/>
                </svg>
                <span id="tx_title_text"><?php echo t('caisse_add_amount'); ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 1.25rem;">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_transaction">
                <?php echo csrfField(); ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <!-- نوع الحركة -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: 700;"><?php echo t('caisse_tx_type'); ?></label>
                        <select name="tx_type" id="tx_type_select" class="form-control" required style="font-size: 1rem; font-weight: 700; padding: 12px;">
                            <option value="deposit" style="color: #059669;">💰 <?php echo t('caisse_deposit_option'); ?></option>
                            <option value="withdrawal" style="color: #dc2626;">💸 <?php echo t('caisse_withdrawal_option'); ?></option>
                        </select>
                    </div>
                    <!-- المبلغ -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: 700;"><?php echo t('amount'); ?></label>
                        <input type="number" name="tx_amount" id="tx_amount_input" class="form-control" step="0.01" min="0.01" 
                               placeholder="0.00" required 
                               style="font-size: 1.2rem; font-weight: 800; text-align: center; padding: 12px; border: 2px solid #059669; border-radius: 10px; transition: border-color 0.3s ease;">
                    </div>
                    <!-- التاريخ -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: 700;"><?php echo t('date'); ?></label>
                        <input type="date" name="tx_date" class="form-control" value="<?php echo $today; ?>" required 
                               style="font-size: 1rem; font-weight: 700; padding: 12px;">
                    </div>
                </div>
                <!-- الوصف -->
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="font-weight: 700;"><?php echo t('caisse_tx_description'); ?></label>
                    <input type="text" name="tx_description" class="form-control" 
                           placeholder="<?php echo t('caisse_tx_placeholder'); ?>"
                           style="font-size: 0.95rem; padding: 10px;" maxlength="255">
                </div>
                <button type="submit" class="btn" id="tx_submit_btn"
                        style="width: 100%; max-width: 400px; display: block; margin: 0 auto; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 14px; font-size: 1.1rem; font-weight: 800; border-radius: 10px; cursor: pointer; transition: background 0.3s ease;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;"><path d="M5 13l4 4L19 7"/></svg>
                    <?php echo t('caisse_register_transaction'); ?>
                </button>
            </form>
        </div>
    </div>
    <script>
    (function() {
        var select = document.getElementById('tx_type_select');
        var card = document.getElementById('tx_card');
        var header = document.getElementById('tx_card_header');
        var amountInput = document.getElementById('tx_amount_input');
        var submitBtn = document.getElementById('tx_submit_btn');
        var titleText = document.getElementById('tx_title_text');
        var titleIcon = document.getElementById('tx_title_icon');

        // نصوص العنوان حسب نوع الحركة
        var depositTitle = "<?php echo addslashes(t('caisse_deposit_option')); ?>";
        var withdrawalTitle = "<?php echo addslashes(t('caisse_withdrawal_option')); ?>";

        function updateColors() {
            if (!select) return;
            var type = select.value;
            if (type === 'withdrawal') {
                // أحمر - سحب
                card.style.borderColor = '#dc2626';
                header.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                amountInput.style.borderColor = '#dc2626';
                submitBtn.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                if (titleText) titleText.textContent = withdrawalTitle;
                if (titleIcon) titleIcon.innerHTML = '<path d="M5 12h14"/>';
            } else {
                // أخضر - إيداع
                card.style.borderColor = '#059669';
                header.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                amountInput.style.borderColor = '#059669';
                submitBtn.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                if (titleText) titleText.textContent = depositTitle;
                if (titleIcon) titleIcon.innerHTML = '<path d="M12 4v16m8-8H4"/>';
            }
        }

        if (select) {
            select.addEventListener('change', updateColors);
            updateColors();
        }
    })();
    </script>

    <!-- ===== فاصل ===== -->
    <div style="text-align: center; margin: 2rem 0 1.5rem; position: relative;">
        <div style="position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: var(--gray-200);"></div>
        <span style="position: relative; background: var(--bg-primary, #f9fafb); padding: 0 1rem; font-size: 0.85rem; color: var(--gray-400); font-weight: 600;">
            📋 <?php echo t('caisse_daily_tracking'); ?> — <span style="color: #059669;">⚡ <?php echo t('caisse_automatic'); ?></span>
        </span>
    </div>

    <!-- ===== حالة الصندوق اليوم ===== -->
    <div class="card" style="border-right: 4px solid #6366f1; margin-bottom: 1.5rem;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                </svg>
                <?php echo t('caisse_status_today'); ?> — <?php echo formatDate($today); ?>
                <span class="badge" style="background: #dcfce7; color: #059669; font-size: 0.7rem; margin-right: 6px;">⚡ <?php echo t('caisse_automatic'); ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 1.25rem;">
            <div class="stats-grid" style="margin-bottom: 1rem;">
                <div class="stat-card blue">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo t('caisse_opening'); ?></h3>
                        <div class="stat-value"><?php echo formatMoney($start_amount); ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>+ <?php echo t('sales'); ?></h3>
                        <div class="stat-value text-success">+ <?php echo formatMoney($today_sales); ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon" style="background: #d1fae5; color: #059669;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>+ <?php echo t('caisse_deposits'); ?></h3>
                        <div class="stat-value text-success">+ <?php echo formatMoney($today_deposits); ?></div>
                    </div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>- <?php echo t('purchases'); ?></h3>
                        <div class="stat-value text-danger">- <?php echo formatMoney($today_purchases); ?></div>
                    </div>
                </div>
                <div class="stat-card" style="border-right-color: #be123c;">
                    <div class="stat-icon" style="background: #ffe4e6; color: #be123c;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>- <?php echo t('expenses'); ?></h3>
                        <div class="stat-value" style="color:#be123c;">- <?php echo formatMoney($today_expenses); ?></div>
                    </div>
                </div>
                <div class="stat-card" style="border-right-color: #7c3aed;">
                    <div class="stat-icon" style="background: #f3e8ff; color: #7c3aed;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>- <?php echo t('caisse_withdrawals'); ?></h3>
                        <div class="stat-value" style="color:#7c3aed;">- <?php echo formatMoney($today_withdrawals); ?></div>
                    </div>
                </div>
            </div>

            <!-- النتيجة المتوقعة -->
            <div style="padding: 1.5rem; border-radius: 12px; text-align: center;
                background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
                border: 2px solid #a5b4fc;">
                <div style="font-size: 0.9rem; color: var(--gray-600); margin-bottom: 6px; font-weight: 600;">💰 <?php echo t('caisse_expected_amount_now'); ?></div>
                <div style="font-size: 2.8rem; font-weight: 900; color: #4f46e5; font-family: monospace; letter-spacing: 1px;">
                    <?php echo number_format($current_amount, 2); ?> <?php echo getCurrencySymbol(); ?>
                </div>
            </div>

            <?php if ($has_actual_column): ?>
            <?php if ($difference !== null): ?>
            <div style="margin-top: 1rem; padding: 1.25rem; border-radius: 10px; text-align: center;
                background: <?php echo $difference >= 0 ? '#f0fdf4' : '#fef2f2'; ?>;
                border: 2px solid <?php echo $difference >= 0 ? '#86efac' : '#fca5a5'; ?>;">
                <div style="font-size: 0.85rem; color: var(--gray-600); margin-bottom: 4px;"><?php echo t('caisse_difference_actual_expected'); ?></div>
                <div style="font-size: 2rem; font-weight: 900; font-family: monospace;
                    color: <?php echo $difference >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php echo ($difference >= 0 ? '+' : ''); ?><?php echo number_format($difference, 2); ?> <?php echo getCurrencySymbol(); ?>
                </div>
                <?php if (abs($difference) > 0.01): ?>
                <div style="font-size: 0.85rem; margin-top: 4px; color: <?php echo $difference > 0 ? '#059669' : '#dc2626'; ?>; font-weight: 600;">
                    <?php if ($difference > 0): ?>✅ <?php echo t('inventory_surplus'); ?><?php else: ?>⚠️ <?php echo t('inventory_deficit'); ?><?php endif; ?>
                </div>
                <?php else: ?>
                <div style="font-size: 0.85rem; margin-top: 4px; color: #059669; font-weight: 600;">✅ <?php echo t('inventory_matched'); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px dashed var(--gray-200);">
                <div style="text-align: center; margin-bottom: 0.75rem;">
                    <span style="font-size: 0.82rem; color: var(--gray-400);">🔧 <?php echo t('caisse_manual_check'); ?></span>
                </div>
                <form method="POST" action="" style="max-width: 500px; margin: 0 auto;">
                    <input type="hidden" name="action" value="save_actual">
                    <?php echo csrfField(); ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-weight: 700; font-size: 0.85rem;"><?php echo t('caisse_actual_count'); ?></label>
                            <input type="number" name="actual_amount" class="form-control" step="0.01" min="0" 
                                   value="<?php echo $actual_amount !== null ? number_format($actual_amount, 2, '.', '') : ''; ?>" 
                                   placeholder="0.00" style="font-size: 1.1rem; font-weight: 800; text-align: center; padding: 10px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-weight: 700; font-size: 0.85rem;"><?php echo t('notes'); ?></label>
                            <input type="text" name="note" class="form-control" value="<?php echo htmlspecialchars($note); ?>" 
                                   placeholder="<?php echo t('optional'); ?>" style="font-size: 0.95rem; padding: 10px;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; font-weight: 700;">
                        <?php echo t('caisse_register_actual_count'); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== سجل حركات الصندوق ===== -->
    <?php if (!empty($recent_transactions)): ?>
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <?php echo t('caisse_transactions_log'); ?>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('type'); ?></th>
                            <th><?php echo t('amount'); ?></th>
                            <th><?php echo t('description'); ?></th>
                            <th><?php echo t('common_by'); ?></th>
                            <th><?php echo t('delete'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $tx): ?>
                        <?php $is_deposit = ($tx['type'] === 'deposit'); ?>
                        <tr style="<?php echo $is_deposit ? 'background:#f0fdf4;' : 'background:#fef2f2;'; ?>">
                            <td style="white-space:nowrap;"><?php echo formatDate($tx['transaction_date']); ?></td>
                            <td>
                                <span class="badge" style="background: <?php echo $is_deposit ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $is_deposit ? '#059669' : '#dc2626'; ?>; font-weight: 700;">
                                    <?php echo $is_deposit ? '💰 ' . t('caisse_deposit') : '💸 ' . t('caisse_withdrawal'); ?>
                                </span>
                            </td>
                            <td style="font-weight: 800; font-family: monospace; color: <?php echo $is_deposit ? '#059669' : '#dc2626'; ?>;">
                                <?php echo $is_deposit ? '+' : '-'; ?><?php echo formatMoney($tx['amount']); ?>
                            </td>
                            <td style="max-width: 200px; font-size: 0.85rem; color: var(--gray-600);"><?php echo htmlspecialchars($tx['description'] ?? '-'); ?></td>
                            <td style="font-size: 0.8rem; color: var(--gray-400);"><?php echo htmlspecialchars($tx['creator_name'] ?? '-'); ?></td>
                            <td>
                                <a href="?confirm_delete=<?php echo $tx['id']; ?>" 
                                   style="color: #dc2626; text-decoration: none; font-size: 0.8rem; font-weight: 600;"
                                   title="<?php echo t('delete'); ?>">
                                    🗑️ <?php echo t('delete'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== تأكيد الحذف ===== -->
    <?php if ($confirm_delete_info): ?>
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 400px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">⚠️</div>
                <h3 style="margin: 0; color: #dc2626; font-size: 1.2rem;"><?php echo t('confirm_delete'); ?></h3>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 1rem; margin-bottom: 1.5rem; text-align: center;">
                <div style="font-size: 0.85rem; color: var(--gray-500);"><?php echo t('caisse_confirm_delete_tx'); ?></div>
                <div style="font-weight: 800; font-size: 1.1rem; margin-top: 4px; color: <?php echo $confirm_delete_info['type'] === 'deposit' ? '#059669' : '#dc2626'; ?>;">
                    <?php echo $confirm_delete_info['type'] === 'deposit' ? '💰 ' . t('caisse_deposit') : '💸 ' . t('caisse_withdrawal'); ?>
                    — <?php echo formatMoney($confirm_delete_info['amount']); ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--gray-400); margin-top: 4px;">
                    <?php echo htmlspecialchars($confirm_delete_info['description'] ?? t('common_no_description')); ?>
                    (<?php echo formatDate($confirm_delete_info['transaction_date']); ?>)
                </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                <a href="caisse.php" class="btn" style="display: block; text-align: center; padding: 12px; font-weight: 700; background: var(--gray-100); color: var(--gray-600); border-radius: 10px; text-decoration: none; border: 1px solid var(--gray-200);">
                    <?php echo t('cancel'); ?>
                </a>
                <form method="POST" action="caisse.php">
                    <input type="hidden" name="action" value="delete_transaction">
                    <input type="hidden" name="tx_id" value="<?php echo $confirm_delete_id; ?>">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn" style="width: 100%; padding: 12px; font-weight: 700; background: #dc2626; color: white; border: none; border-radius: 10px; cursor: pointer;">
                        🗑️ <?php echo t('caisse_yes_delete'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== سجل الصندوق اليومي ===== -->
    <?php if (!empty($recent_records)): ?>
    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?php echo t('caisse_daily_log'); ?>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('date'); ?></th>
                            <th><?php echo t('caisse_start_amount_label'); ?></th>
                            <th>+ <?php echo t('sales'); ?></th>
                            <th>+ <?php echo t('caisse_deposits'); ?></th>
                            <th>- <?php echo t('purchases'); ?></th>
                            <th>- <?php echo t('expenses'); ?></th>
                            <th>- <?php echo t('caisse_withdrawals'); ?></th>
                            <th><?php echo t('caisse_expected_label'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_records as $record): ?>
                        <?php
                            $r_date = $record['record_date'];
                            $r_sales = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(sale_date) = ?", [$r_date])['total'] ?? 0);
                            $r_purchases = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM purchases WHERE DATE(purchase_date) = ?", [$r_date])['total'] ?? 0);
                            try { $r_expenses = floatval(fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE(expense_date) = ?", [$r_date])['total'] ?? 0); } catch (Exception $e) { $r_expenses = 0; }
                            $r_tx = CaisseHelper::getNetTransactions($pdo, $r_date, false);
                            $is_today = ($r_date === $today);
                            $r_current = floatval($record['current_amount']);
                        ?>
                        <tr style="<?php echo $is_today ? 'background:#eff6ff; font-weight:600;' : ''; ?>">
                            <td style="white-space:nowrap;">
                                <?php echo $r_date; ?>
                                <?php if ($is_today): ?><span class="badge badge-primary" style="font-size:0.65rem;"><?php echo t('today'); ?></span><?php endif; ?>
                            </td>
                            <td><?php echo formatMoney($record['start_amount']); ?></td>
                            <td style="color:#059669;">+ <?php echo formatMoney($r_sales); ?></td>
                            <td style="color:#059669;">+ <?php echo formatMoney($r_tx['deposits']); ?></td>
                            <td style="color:#dc2626;">- <?php echo formatMoney($r_purchases); ?></td>
                            <td style="color:#be123c;">- <?php echo formatMoney($r_expenses); ?></td>
                            <td style="color:#7c3aed;">- <?php echo formatMoney($r_tx['withdrawals']); ?></td>
                            <td><strong style="color:#4f46e5;"><?php echo formatMoney($r_current); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- POS Settings Support: Theme + Sound + Auto-Complete -->
<?php if ($pos_theme === 'dark'): ?>
<style>
    .card { background: #1e293b !important; color: #e2e8f0 !important; border-color: #334155 !important; }
    .card-header { background: #334155 !important; color: #e2e8f0 !important; }
    .card-body { color: #cbd5e1 !important; }
    .stat-card { background: #1e293b !important; color: #e2e8f0 !important; }
    .stat-info h3 { color: #94a3b8 !important; }
    input, select, textarea { background: #0f172a !important; color: #e2e8f0 !important; border-color: #475569 !important; }
    table { color: #e2e8f0 !important; }
    thead th { background: #334155 !important; color: #e2e8f0 !important; }
    tbody tr { border-bottom-color: #334155 !important; }
</style>
<?php endif; ?>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === POS Sound Effects ===
<?php if ($pos_sound_enabled): ?>
const posSoundDeposit = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbsGczHjqIqN/LdkEwP3Oo2dyxYi0jOYah4tylXTElO5Sm5eCiVi8jL5er6OOhUC0kL5mu6eOkUQ==');
const posSoundWithdrawal = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbsGczHjqIqN/LdkEwP3Oo2dyxYi0jOYah4tylXTElO5Sm5eCiVi8jL5er6OOhUC0kL5mu6eOkUQ==');

// Play sound on save actions
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_GET['tx_saved'])): ?>
    try { posSoundDeposit.currentTime = 0; posSoundDeposit.play(); } catch(e) {}
    <?php elseif (isset($_GET['saved_project']) || isset($_GET['saved_actual'])): ?>
    try { posSoundDeposit.currentTime = 0; posSoundDeposit.play(); } catch(e) {}
    <?php endif; ?>
});
<?php endif; ?>

// === POS Auto-Complete ===
<?php if ($pos_auto_complete): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-focus and auto-complete for transaction form
    const txTypeSelect = document.querySelector('select[name="tx_type"]');
    const txAmountInput = document.querySelector('input[name="tx_amount"]');
    const txDescInput = document.querySelector('input[name="tx_description"]');
    
    if (txTypeSelect && txAmountInput) {
        // Auto-focus amount field when type changes
        txTypeSelect.addEventListener('change', function() {
            txAmountInput.focus();
        });
    }
    
    // Auto-submit on Enter in amount field
    if (txAmountInput) {
        txAmountInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && txDescInput) {
                e.preventDefault();
                txDescInput.focus();
            }
        });
    }
});
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
