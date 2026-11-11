<?php
$page_title = 'الصندوق';
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
requireLogin();

$pdo = getDBConnection();
$today = date('Y-m-d');

// === إنشاء جدول الصندوق اليومي ===
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS caisse (
        id INT AUTO_INCREMENT PRIMARY KEY,
        record_date DATE NOT NULL,
        start_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        current_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        note TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_date (record_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// === إضافة عمود actual_amount ===
$has_actual_column = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM caisse")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('actual_amount', $cols)) {
        $pdo->exec("ALTER TABLE caisse ADD COLUMN actual_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER current_amount");
    }
    $has_actual_column = true;
} catch (Exception $e) {}

// === إنشاء جدول حركات الصندوق (إيداع / سحب) ===
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS caisse_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('deposit', 'withdrawal') NOT NULL COMMENT 'deposit=إيداع, withdrawal=سحب',
        amount DECIMAL(12,2) NOT NULL COMMENT 'المبلغ',
        description VARCHAR(255) DEFAULT NULL COMMENT 'وصف الحركة',
        transaction_date DATE NOT NULL COMMENT 'تاريخ الحركة',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

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
                ->execute(['project_start_amount', $project_start, 'مبلغ الإنطلاق العام للمشروع']);
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
        
        autoCreateTodayRecord($pdo, $today);
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

/**
 * حساب صافي حركات الصندوق (إيداعات - مسحوبات) لتاريخ معين
 */
function getNetTransactions($pdo, $date, $before = false) {
    try {
        if ($before) {
            $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) as deposits,
                COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) as withdrawals
                FROM caisse_transactions WHERE transaction_date < ?");
        } else {
            $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) as deposits,
                COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) as withdrawals
                FROM caisse_transactions WHERE transaction_date = ?");
        }
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        return [
            'deposits' => floatval($row['deposits'] ?? 0),
            'withdrawals' => floatval($row['withdrawals'] ?? 0)
        ];
    } catch (Exception $e) {
        return ['deposits' => 0, 'withdrawals' => 0];
    }
}

/**
 * إنشاء/تحديث سجل الصندوق اليومي تلقائياً
 */
function autoCreateTodayRecord($pdo, $today) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute(['project_start_amount']);
    $project_start_row = $stmt->fetch();
    $project_start_amount = $project_start_row ? floatval($project_start_row['setting_value']) : 0;
    
    // === معاملات قبل اليوم ===
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE(sale_date) < ?");
    $stmt->execute([$today]); $prev_sales = floatval($stmt->fetchColumn());
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE DATE(purchase_date) < ?");
    $stmt->execute([$today]); $prev_purchases = floatval($stmt->fetchColumn());
    
    try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) < ?");
        $stmt->execute([$today]); $prev_expenses = floatval($stmt->fetchColumn());
    } catch (Exception $e) { $prev_expenses = 0; }
    
    $prev_tx = getNetTransactions($pdo, $today, true);
    
    $today_start = $project_start_amount + $prev_sales - $prev_purchases - $prev_expenses + $prev_tx['deposits'] - $prev_tx['withdrawals'];
    
    // === معاملات اليوم ===
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE(sale_date) = ?");
    $stmt->execute([$today]); $today_sales = floatval($stmt->fetchColumn());
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE DATE(purchase_date) = ?");
    $stmt->execute([$today]); $today_purchases = floatval($stmt->fetchColumn());
    
    try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) = ?");
        $stmt->execute([$today]); $today_expenses = floatval($stmt->fetchColumn());
    } catch (Exception $e) { $today_expenses = 0; }
    
    $today_tx = getNetTransactions($pdo, $today, false);
    
    $today_current = $today_start + $today_sales - $today_purchases - $today_expenses + $today_tx['deposits'] - $today_tx['withdrawals'];
    
    $existing_stmt = $pdo->prepare("SELECT id, note FROM caisse WHERE record_date = ?");
    $existing_stmt->execute([$today]);
    $existing = $existing_stmt->fetch();
    
    if ($existing) {
        $pdo->prepare("UPDATE caisse SET start_amount = ?, current_amount = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$today_start, $today_current, $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO caisse (record_date, start_amount, current_amount) VALUES (?, ?, ?)")
            ->execute([$today, $today_start, $today_current]);
    }
    
    return [
        'start' => $today_start, 'current' => $today_current, 
        'sales' => $today_sales, 'purchases' => $today_purchases, 'expenses' => $today_expenses,
        'deposits' => $today_tx['deposits'], 'withdrawals' => $today_tx['withdrawals']
    ];
}

/**
 * توليد سجلات تلقائية للأيام التي فيها معاملات
 */
function generateMissingRecords($pdo, $project_start_amount) {
    $dates = [];
    
    $stmt = $pdo->query("SELECT DISTINCT DATE(sale_date) as d FROM sales ORDER BY d DESC LIMIT 30");
    while ($row = $stmt->fetch()) $dates[] = $row['d'];
    
    $stmt = $pdo->query("SELECT DISTINCT DATE(purchase_date) as d FROM purchases ORDER BY d DESC LIMIT 30");
    while ($row = $stmt->fetch()) $dates[] = $row['d'];
    
    try { $stmt = $pdo->query("SELECT DISTINCT DATE(expense_date) as d FROM expenses ORDER BY d DESC LIMIT 30");
        while ($row = $stmt->fetch()) $dates[] = $row['d'];
    } catch (Exception $e) {}
    
    try { $stmt = $pdo->query("SELECT DISTINCT DATE(transaction_date) as d FROM caisse_transactions ORDER BY d DESC LIMIT 30");
        while ($row = $stmt->fetch()) $dates[] = $row['d'];
    } catch (Exception $e) {}
    
    $dates = array_unique($dates);
    sort($dates);
    
    foreach ($dates as $date) {
        $exists = $pdo->prepare("SELECT id FROM caisse WHERE record_date = ?");
        $exists->execute([$date]);
        if ($exists->fetch()) continue;
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE(sale_date) < ?");
        $stmt->execute([$date]); $prev_sales = floatval($stmt->fetchColumn());
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE DATE(purchase_date) < ?");
        $stmt->execute([$date]); $prev_purchases = floatval($stmt->fetchColumn());
        
        try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) < ?");
            $stmt->execute([$date]); $prev_expenses = floatval($stmt->fetchColumn());
        } catch (Exception $e) { $prev_expenses = 0; }
        
        $prev_tx = getNetTransactions($pdo, $date, true);
        
        $day_start = $project_start_amount + $prev_sales - $prev_purchases - $prev_expenses + $prev_tx['deposits'] - $prev_tx['withdrawals'];
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE(sale_date) = ?");
        $stmt->execute([$date]); $day_sales = floatval($stmt->fetchColumn());
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE DATE(purchase_date) = ?");
        $stmt->execute([$date]); $day_purchases = floatval($stmt->fetchColumn());
        
        try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) = ?");
            $stmt->execute([$date]); $day_expenses = floatval($stmt->fetchColumn());
        } catch (Exception $e) { $day_expenses = 0; }
        
        $day_tx = getNetTransactions($pdo, $date, false);
        
        $day_current = $day_start + $day_sales - $day_purchases - $day_expenses + $day_tx['deposits'] - $day_tx['withdrawals'];
        
        $pdo->prepare("INSERT INTO caisse (record_date, start_amount, current_amount) VALUES (?, ?, ?)")
            ->execute([$date, $day_start, $day_current]);
    }
}

// === إنشاء/تحديث سجل اليوم تلقائياً ===
$today_data = autoCreateTodayRecord($pdo, $today);

// === إجماليات المشروع ===
$project_start_row = fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = ?", ['project_start_amount']);
$project_start_amount = $project_start_row ? floatval($project_start_row['setting_value']) : 0;

$total_sales = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales")['total'] ?? 0);
$total_purchases = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM purchases")['total'] ?? 0);
try { $total_expenses = floatval(fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM expenses")['total'] ?? 0);
} catch (Exception $e) { $total_expenses = 0; }

// إجمالي حركات الصندوق
$total_tx = getNetTransactions($pdo, '2099-12-31', true);
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
generateMissingRecords($pdo, $project_start_amount);

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
            الصندوق (Caisse)
        </h1>
        <?php if (isset($_GET['saved_project'])): ?>
        <span class="badge badge-success caisse-badge-notification">✓ تم حفظ مبلغ المشروع</span>
        <?php elseif (isset($_GET['tx_saved'])): ?>
        <span class="badge badge-success caisse-badge-notification">✓ تمت إضافة الحركة بنجاح</span>
        <?php elseif (isset($_GET['tx_deleted'])): ?>
        <span class="badge caisse-badge-danger">✓ تم حذف الحركة</span>
        <?php elseif (isset($_GET['saved_actual'])): ?>
        <span class="badge badge-success caisse-badge-notification">✓ تم تسجيل العد الفعلي</span>
        <?php endif; ?>
    </div>

    <!-- ===== رصيد المشروع الكلي ===== -->
    <div class="card caisse-card--project">
        <div class="card-header caisse-card-header--project">
            <h2 class="caisse-card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
                رصيد المشروع الكلي
            </h2>
        </div>
        <div class="card-body caisse-card-body">
            <div class="stats-grid mb-5">
                <div class="stat-card caisse-stat-card--amber">
                    <div class="stat-icon caisse-stat-icon--amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>مبلغ الإنطلاق</h3>
                        <div class="stat-value caisse-stat-value--amber"><?php echo formatMoney($project_start_amount); ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>إجمالي المبيعات</h3>
                        <div class="stat-value text-success"><?php echo formatMoney($total_sales); ?></div>
                    </div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>إجمالي المشتريات</h3>
                        <div class="stat-value text-danger"><?php echo formatMoney($total_purchases); ?></div>
                    </div>
                </div>
                <div class="stat-card caisse-stat-card--rose">
                    <div class="stat-icon caisse-stat-icon--rose">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>إجمالي المصاريف</h3>
                        <div class="stat-value caisse-stat-value--rose"><?php echo formatMoney($total_expenses); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- إيداعات ومسحوبات -->
            <div class="caisse-grid-summary">
                <div class="caisse-summary-box--deposit">
                    <div class="caisse-summary-label">💰 إجمالي الإيداعات</div>
                    <div class="caisse-summary-value--deposit"><?php echo formatMoney($total_deposits); ?></div>
                </div>
                <div class="caisse-summary-box--withdrawal">
                    <div class="caisse-summary-label">💸 إجمالي المسحوبات</div>
                    <div class="caisse-summary-value--withdrawal"><?php echo formatMoney($total_withdrawals); ?></div>
                </div>
            </div>

            <!-- الرصيد الحالي -->
            <div class="caisse-balance-box"
                style="background: <?php echo $project_current_amount >= 0 ? 'linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%)' : 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)'; ?>;
                border: 2px solid <?php echo $project_current_amount >= 0 ? '#86efac' : '#fca5a5'; ?>;">
                <div class="caisse-balance-label">💰 الرصيد الحالي للمشروع</div>
                <div class="caisse-balance-value"
                    style="color: <?php echo $project_current_amount >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php echo number_format($project_current_amount, 2); ?> <?php echo getCurrencySymbol(); ?>
                </div>
                <div class="caisse-balance-breakdown">
                    الإنطلاق (<?php echo number_format($project_start_amount, 2); ?>)
                    + المبيعات (<?php echo number_format($total_sales, 2); ?>)
                    + الإيداعات (<?php echo number_format($total_deposits, 2); ?>)
                    - المشتريات (<?php echo number_format($total_purchases, 2); ?>)
                    - المصاريف (<?php echo number_format($total_expenses, 2); ?>)
                    - المسحوبات (<?php echo number_format($total_withdrawals, 2); ?>)
                </div>
            </div>

            <!-- نموذج تعديل مبلغ الإنطلاق -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_project_amount">
                <?php echo csrfField(); ?>
                <div class="caisse-form-center">
                    <div class="form-group mb-3">
                        <label class="caisse-label--lg">مبلغ الإنطلاق العام (رأس المال الأولي)</label>
                        <input type="number" name="project_start_amount" class="form-control caisse-input--amber" step="0.01" min="0" 
                               value="<?php echo number_format($project_start_amount, 2, '.', ''); ?>" placeholder="0.00" required>
                    </div>
                    <button type="submit" class="btn caisse-btn--amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="vertical-align: middle; margin-left: 4px;"><path d="M5 13l4 4L19 7"/></svg>
                        حفظ مبلغ الإنطلاق
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== إضافة حركة صندوق (إيداع / سحب) ===== -->
    <div class="card caisse-card--transaction">
        <div class="card-header caisse-card-header--transaction">
            <h2 class="caisse-card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22" style="vertical-align: middle; margin-left: 6px;">
                    <path d="M12 4v16m8-8H4"/>
                </svg>
                إضافة مبلغ إلى الصندوق
            </h2>
        </div>
        <div class="card-body caisse-card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_transaction">
                <?php echo csrfField(); ?>
                <div class="caisse-grid-form">
                    <!-- نوع الحركة -->
                    <div class="form-group mb-0">
                        <label class="caisse-label">نوع العملية</label>
                        <select name="tx_type" class="form-control caisse-input--select" required>
                            <option value="deposit" style="color: #059669;">💰 إيداع (إضافة مبلغ)</option>
                            <option value="withdrawal" style="color: #dc2626;">💸 سحب (أخذ مبلغ)</option>
                        </select>
                    </div>
                    <!-- المبلغ -->
                    <div class="form-group mb-0">
                        <label class="caisse-label">المبلغ</label>
                        <input type="number" name="tx_amount" class="form-control caisse-input--green" step="0.01" min="0.01" 
                               placeholder="0.00" required>
                    </div>
                    <!-- التاريخ -->
                    <div class="form-group mb-0">
                        <label class="caisse-label">التاريخ</label>
                        <input type="date" name="tx_date" class="form-control caisse-input--date" value="<?php echo $today; ?>" required>
                    </div>
                </div>
                <!-- الوصف -->
                <div class="form-group mb-4">
                    <label class="caisse-label">الوصف / السبب</label>
                    <input type="text" name="tx_description" class="form-control caisse-input--note" 
                           placeholder="مثال: إيداع من صاحب المشروع، سحب لشراء مواد..."
                           maxlength="255">
                </div>
                <button type="submit" class="btn caisse-btn--green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;"><path d="M5 13l4 4L19 7"/></svg>
                    تسجيل العملية
                </button>
            </form>
        </div>
    </div>

    <!-- ===== فاصل ===== -->
    <div class="caisse-divider">
        <div class="caisse-divider-line"></div>
        <span class="caisse-divider-label">
            📋 متابعة الصندوق اليومية — <span style="color: #059669;">⚡ تلقائية</span>
        </span>
    </div>

    <!-- ===== حالة الصندوق اليوم ===== -->
    <div class="card caisse-card--daily">
        <div class="card-header d-flex justify-between items-center">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                </svg>
                حالة الصندوق — <?php echo date('d/m/Y'); ?>
                <span class="badge caisse-badge--auto">⚡ تلقائي</span>
            </h2>
        </div>
        <div class="card-body caisse-card-body">
            <div class="stats-grid mb-4">
                <div class="stat-card blue">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>مبلغ الإنطلاق</h3>
                        <div class="stat-value"><?php echo formatMoney($start_amount); ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>+ مبيعات</h3>
                        <div class="stat-value text-success">+ <?php echo formatMoney($today_sales); ?></div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon caisse-stat-icon--deposit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>+ إيداعات</h3>
                        <div class="stat-value text-success">+ <?php echo formatMoney($today_deposits); ?></div>
                    </div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>- مشتريات</h3>
                        <div class="stat-value text-danger">- <?php echo formatMoney($today_purchases); ?></div>
                    </div>
                </div>
                <div class="stat-card caisse-stat-card--rose">
                    <div class="stat-icon caisse-stat-icon--rose">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>- مصاريف</h3>
                        <div class="stat-value caisse-stat-value--rose">- <?php echo formatMoney($today_expenses); ?></div>
                    </div>
                </div>
                <div class="stat-card caisse-stat-card--purple">
                    <div class="stat-icon caisse-stat-icon--purple">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                    </div>
                    <div class="stat-info">
                        <h3>- مسحوبات</h3>
                        <div class="stat-value caisse-stat-value--purple">- <?php echo formatMoney($today_withdrawals); ?></div>
                    </div>
                </div>
            </div>

            <!-- النتيجة المتوقعة -->
            <div class="caisse-expected-box">
                <div class="caisse-balance-label">💰 المبلغ المتوقع في الصندوق الآن</div>
                <div class="caisse-balance-value--lg">
                    <?php echo number_format($current_amount, 2); ?> <?php echo getCurrencySymbol(); ?>
                </div>
            </div>

            <?php if ($has_actual_column): ?>
            <?php if ($difference !== null): ?>
            <div class="caisse-difference-box"
                style="background: <?php echo $difference >= 0 ? '#f0fdf4' : '#fef2f2'; ?>;
                border: 2px solid <?php echo $difference >= 0 ? '#86efac' : '#fca5a5'; ?>;">
                <div style="font-size: 0.85rem; color: var(--gray-600); margin-bottom: 4px;">الفرق (العد الفعلي - المتوقع)</div>
                <div class="caisse-difference-value"
                    style="color: <?php echo $difference >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php echo ($difference >= 0 ? '+' : ''); ?><?php echo number_format($difference, 2); ?> <?php echo getCurrencySymbol(); ?>
                </div>
                <?php if (abs($difference) > 0.01): ?>
                <div class="caisse-difference-status"
                    style="color: <?php echo $difference > 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php if ($difference > 0): ?>✅ فائض<?php else: ?>⚠️ نقص<?php endif; ?>
                </div>
                <?php else: ?>
                <div class="caisse-difference-status--balanced">✅ متوازن</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="caisse-manual-check">
                <div class="caisse-manual-check-label">
                    <span>🔧 تحقق يدوي (اختياري)</span>
                </div>
                <form method="POST" action="" class="caisse-form-center">
                    <input type="hidden" name="action" value="save_actual">
                    <?php echo csrfField(); ?>
                    <div class="caisse-grid-form-2col">
                        <div class="form-group mb-0">
                            <label class="caisse-label--sm">العد الفعلي</label>
                            <input type="number" name="actual_amount" class="form-control caisse-input--amount" step="0.01" min="0" 
                                   value="<?php echo $actual_amount !== null ? number_format($actual_amount, 2, '.', '') : ''; ?>" 
                                   placeholder="0.00">
                        </div>
                        <div class="form-group mb-0">
                            <label class="caisse-label--sm">ملاحظة</label>
                            <input type="text" name="note" class="form-control caisse-input--note" value="<?php echo htmlspecialchars($note); ?>" 
                                   placeholder="اختياري">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-full font-bold">
                        تسجيل العد الفعلي
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
                سجل حركات الصندوق (إيداعات / مسحوبات)
            </h2>
        </div>
        <div class="card-body caisse-card-body--flush">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>المبلغ</th>
                            <th>الوصف</th>
                            <th>بواسطة</th>
                            <th>حذف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $tx): ?>
                        <?php $is_deposit = ($tx['type'] === 'deposit'); ?>
                        <tr style="<?php echo $is_deposit ? 'background:#f0fdf4;' : 'background:#fef2f2;'; ?>">
                            <td style="white-space:nowrap;"><?php echo $tx['transaction_date']; ?></td>
                            <td>
                                <span class="badge" style="background: <?php echo $is_deposit ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $is_deposit ? '#059669' : '#dc2626'; ?>; font-weight: 700;">
                                    <?php echo $is_deposit ? '💰 إيداع' : '💸 سحب'; ?>
                                </span>
                            </td>
                            <td style="font-weight: 800; font-family: monospace; color: <?php echo $is_deposit ? '#059669' : '#dc2626'; ?>;">
                                <?php echo $is_deposit ? '+' : '-'; ?><?php echo formatMoney($tx['amount']); ?>
                            </td>
                            <td style="max-width: 200px; font-size: 0.85rem; color: var(--gray-600);"><?php echo htmlspecialchars($tx['description'] ?? '-'); ?></td>
                            <td style="font-size: 0.8rem; color: var(--gray-400);"><?php echo htmlspecialchars($tx['creator_name'] ?? '-'); ?></td>
                            <td>
                                <a href="?confirm_delete=<?php echo $tx['id']; ?>" 
                                   class="caisse-tx-delete-link"
                                   title="حذف">
                                    🗑️ حذف
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
    <div class="caisse-modal-overlay">
        <div class="caisse-modal-dialog">
            <div class="caisse-modal-header">
                <div class="caisse-modal-icon">⚠️</div>
                <h3 class="caisse-modal-title">تأكيد الحذف</h3>
            </div>
            <div class="caisse-modal-info">
                <div style="font-size: 0.85rem; color: var(--gray-500);">هل تريد حذف هذه الحركة؟</div>
                <div style="font-weight: 800; font-size: 1.1rem; margin-top: 4px; color: <?php echo $confirm_delete_info['type'] === 'deposit' ? '#059669' : '#dc2626'; ?>;">
                    <?php echo $confirm_delete_info['type'] === 'deposit' ? '💰 إيداع' : '💸 سحب'; ?>
                    — <?php echo formatMoney($confirm_delete_info['amount']); ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--gray-400); margin-top: 4px;">
                    <?php echo htmlspecialchars($confirm_delete_info['description'] ?? 'بدون وصف'); ?>
                    (<?php echo $confirm_delete_info['transaction_date']; ?>)
                </div>
            </div>
            <div class="caisse-modal-actions--2col">
                <a href="caisse.php" class="btn caisse-btn--cancel">
                    إلغاء
                </a>
                <form method="POST" action="caisse.php">
                    <input type="hidden" name="action" value="delete_transaction">
                    <input type="hidden" name="tx_id" value="<?php echo $confirm_delete_id; ?>">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn caisse-btn--danger">
                        🗑️ نعم، احذف
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== سجل الصندوق اليومي ===== -->
    <?php if (!empty($recent_records)): ?>
    <div class="card mt-6">
        <div class="card-header">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                سجل الصندوق اليومي
            </h2>
        </div>
        <div class="card-body caisse-card-body--flush">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>مبلغ الإنطلاق</th>
                            <th>+ مبيعات</th>
                            <th>+ إيداعات</th>
                            <th>- مشتريات</th>
                            <th>- مصاريف</th>
                            <th>- مسحوبات</th>
                            <th>المتوقع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_records as $record): ?>
                        <?php
                            $r_date = $record['record_date'];
                            $r_sales = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(sale_date) = ?", [$r_date])['total'] ?? 0);
                            $r_purchases = floatval(fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM purchases WHERE DATE(purchase_date) = ?", [$r_date])['total'] ?? 0);
                            try { $r_expenses = floatval(fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE(expense_date) = ?", [$r_date])['total'] ?? 0); } catch (Exception $e) { $r_expenses = 0; }
                            $r_tx = getNetTransactions($pdo, $r_date, false);
                            $is_today = ($r_date === $today);
                            $r_current = floatval($record['current_amount']);
                        ?>
                        <tr class="<?php echo $is_today ? 'caisse-daily-row--today' : ''; ?>">
                            <td style="white-space:nowrap;">
                                <?php echo $r_date; ?>
                                <?php if ($is_today): ?><span class="badge badge-primary" style="font-size:0.65rem;">اليوم</span><?php endif; ?>
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

<?php require_once 'includes/footer.php'; ?>
