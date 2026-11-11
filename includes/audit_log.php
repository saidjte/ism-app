<?php
// سجل النشاطات - تتبع كل العمليات في النظام

function addAuditLog($action, $description, $details = '', $entity_type = '', $entity_id = 0) {
    try {
        $user_id = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        executeQuery(
            "INSERT INTO audit_log (user_id, username, action, description, details, entity_type, entity_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$user_id, $username, $action, $description, $details, $entity_type, $entity_id, $ip, $user_agent]
        );
    } catch (Exception $e) {
        // فشل تسجيل النشاط - لا نوقف العملية
    }
}

function getAuditLogs($limit = 100, $filters = []) {
    $where = [];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[] = "user_id = ?";
        $params[] = intval($filters['user_id']);
    }
    if (!empty($filters['action'])) {
        $where[] = "action = ?";
        $params[] = $filters['action'];
    }
    if (!empty($filters['entity_type'])) {
        $where[] = "entity_type = ?";
        $params[] = $filters['entity_type'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = "created_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = "created_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    $sql = "SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON al.user_id = u.id";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY al.created_at DESC LIMIT " . intval($limit);

    return fetchAll($sql, $params);
}

function getActionLabel($action) {
    $labels = [
        'login' => t('audit_login'),
        'logout' => t('audit_logout'),
        'login_failed' => t('audit_login_failed'),
        'product_add' => t('audit_product_add'),
        'product_edit' => t('audit_product_edit'),
        'product_delete' => t('audit_product_delete'),
        'product_quantity_update' => t('audit_product_quantity_update'),
        'sale_add' => t('audit_sale_add'),
        'sale_delete' => t('audit_sale_delete'),
        'purchase_add' => t('audit_purchase_add'),
        'purchase_delete' => t('audit_purchase_delete'),
        'expense_add' => t('audit_expense_add'),
        'expense_delete' => t('audit_expense_delete'),
        'user_add' => t('audit_user_add'),
        'user_edit' => t('audit_user_edit'),
        'user_delete' => t('audit_user_delete'),
        'backup_create' => t('audit_backup_create'),
        'backup_restore' => t('audit_backup_restore'),
        'settings_update' => t('audit_settings_update'),
        'transfer_stock' => t('audit_transfer_stock'),
        'export_data' => t('audit_export_data'),
    ];
    return $labels[$action] ?? $action;
}
?>
