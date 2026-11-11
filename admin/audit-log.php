<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$page_title = t('nav_audit_log');
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
requirePermission('audit.view');
require_once __DIR__ . '/../includes/header.php';

// الفلاتر
$filters = [];
if (!empty($_GET['user_id'])) {
    $filters['user_id'] = intval($_GET['user_id']);
}
if (!empty($_GET['action'])) {
    $filters['action'] = $_GET['action'];
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

// عدد السجلات
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
if ($limit < 10) $limit = 10;
if ($limit > 1000) $limit = 1000;

$logs = getAuditLogs($limit, $filters);

// جلب المستخدمين لقائمة الفلتر
$users = [];
try {
    $users = fetchAll("SELECT id, full_name FROM users ORDER BY full_name ASC");
} catch (Exception $e) {
    ErrorHandler::logException($e, 'audit-log::fetchUsers');
}

// قائمة أنواع العمليات
$action_types = [
    'login' => t('audit_action_login'),
    'logout' => t('audit_action_logout'),
    'login_failed' => t('audit_action_login_failed'),
    'product_add' => t('audit_action_product_add'),
    'product_edit' => t('audit_action_product_edit'),
    'product_delete' => t('audit_action_product_delete'),
    'sale_add' => t('audit_action_sale_add'),
    'sale_delete' => t('audit_action_sale_delete'),
    'purchase_add' => t('audit_action_purchase_add'),
    'purchase_delete' => t('audit_action_purchase_delete'),
    'expense_add' => t('audit_action_expense_add'),
    'expense_delete' => t('audit_action_expense_delete'),
    'user_add' => t('audit_action_user_add'),
    'user_edit' => t('audit_action_user_edit'),
    'user_delete' => t('audit_action_user_delete'),
    'backup_create' => t('audit_action_backup_create'),
    'backup_restore' => t('audit_action_backup_restore'),
    'settings_update' => t('audit_action_settings_update'),
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
            <?php echo t('nav_audit_log'); ?>
        </h1>
    </div>

    <!-- فلاتر البحث -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">
            <h2>🔍 <?php echo t('admin_filter_logs'); ?></h2>
        </div>
        <div class="card-body">
            <form method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; align-items:end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo t('users_username'); ?></label>
                    <select name="user_id" class="form-control">
                        <option value=""><?php echo t('all'); ?></option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo (isset($_GET['user_id']) && $_GET['user_id'] == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo t('admin_action_type'); ?></label>
                    <select name="action" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($action_types as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo (isset($_GET['action']) && $_GET['action'] == $key) ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo t('admin_date_from'); ?></label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo t('admin_date_to'); ?></label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo t('admin_record_count'); ?></label>
                    <select name="limit" class="form-control">
                        <?php foreach ([50, 100, 200, 500, 1000] as $l): ?>
                        <option value="<?php echo $l; ?>" <?php echo $limit == $l ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:0.5rem; align-items:end;">
                    <button type="submit" class="btn btn-primary"><?php echo t('search'); ?></button>
                    <a href="audit-log.php" class="btn btn-outline"><?php echo t('admin_clear_filters'); ?></a>
                </div>
            </form>
        </div>
    </div>

    <!-- جدول السجلات -->
    <div class="card">
        <div class="card-header">
            <h2><?php echo t('admin_recent_activity'); ?> <span style="font-size:0.8rem; font-weight:400; color:#94a3b8;">(<?php echo count($logs); ?> <?php echo t('admin_records'); ?>)</span></h2>
        </div>
        <div class="card-body">
            <?php if (empty($logs)): ?>
                <div style="text-align:center; padding:3rem; color:var(--gray-400);">
                    <div style="font-size:3rem; margin-bottom:12px;">📋</div>
                    <div style="font-size:1.1rem; font-weight:600; color:var(--gray-600);?><?php echo t('admin_no_matching_records'); ?></div>
                    <div style="font-size:0.85rem; margin-top:8px;"><?php echo t('admin_try_changing_filters'); ?></div>
                </div>
            <?php else: ?>
            <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('admin_datetime'); ?></th>
                            <th><?php echo t('users_username'); ?></th>
                            <th><?php echo t('admin_operation'); ?></th>
                            <th><?php echo t('description'); ?></th>
                            <th><?php echo t('admin_ip_address'); ?></th>
                            <th><?php echo t('admin_browser'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($logs as $log):
                            $action_class = '';
                            if (strpos($log['action'], 'delete') !== false || $log['action'] === 'login_failed') {
                                $action_class = 'style="color: #dc2626;"';
                            } elseif (strpos($log['action'], 'add') !== false || $log['action'] === 'login') {
                                $action_class = 'style="color: #059669;"';
                            } elseif (strpos($log['action'], 'edit') !== false || $log['action'] === 'update') {
                                $action_class = 'style="color: #2563eb;"';
                            }
                        ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td style="white-space: nowrap;"><?php echo $log['created_at']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? t('system')); ?>
                            </td>
                            <td <?php echo $action_class; ?>>
                                <strong><?php echo getActionLabel($log['action']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($log['description'] ?? '-'); ?></td>
                            <td style="direction: ltr; text-align: right; font-size: 0.85rem;"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                            <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8rem;" title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>">
                                <?php
                                    $ua = $log['user_agent'] ?? '';
                                    // استخراج اسم المتصفح
                                    if (preg_match('/Chrome/i', $ua)) echo 'Chrome';
                                    elseif (preg_match('/Firefox/i', $ua)) echo 'Firefox';
                                    elseif (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) echo 'Safari';
                                    elseif (preg_match('/Edge/i', $ua)) echo 'Edge';
                                    elseif (preg_match('/Opera/i', $ua)) echo 'Opera';
                                    else echo substr($ua, 0, 30);
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
