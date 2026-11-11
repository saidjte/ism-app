<?php
require_once '../includes/bootstrap.php';
$page_title = t('purchases_delete');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('purchases.delete');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $purchase_id = intval($_POST['purchase_id'] ?? 0);

    if ($purchase_id > 0) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $purchaseStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
            $purchaseStmt->execute([$purchase_id]);
            $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);

            if ($purchase) {
                $quantity_to_remove = floatval($purchase['quantity']);
                $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")
                    ->execute([$quantity_to_remove, intval($purchase['product_id'])]);

                // Delete purchase_batches (دفعات المنتج)
                try {
                    $pdo->prepare("DELETE FROM purchase_batches WHERE purchase_id = ?")->execute([$purchase_id]);
                } catch (Exception $e) {
                    // Table may not exist
                }

                // Delete purchase_remises if table exists
                try {
                    $pdo->prepare("DELETE FROM purchase_remises WHERE purchase_id = ?")->execute([$purchase_id]);
                } catch (Exception $e) {
                    // Table may not exist
                }

                // Delete the purchase
                $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$purchase_id]);

                // إذا كانت آخر منتج في الفاتورة، احذف الفاتورة أيضاً
                $order_id = $purchase['order_id'] ?? null;
                if ($order_id) {
                    try {
                        $remaining = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE order_id = ?");
                        $remaining->execute([$order_id]);
                        if ($remaining->fetchColumn() == 0) {
                            $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$order_id]);
                        }
                    } catch (\Throwable $e) {}
                }

                $pdo->commit();

                // إشعار حذف عملية شراء
                try {
                    $productName = fetchOne("SELECT name FROM products WHERE id = ?", [intval($purchase['product_id'])]);
                    $productName = $productName ? $productName['name'] : '';
                    AppNotification::add(NOTIF_PURCHASE, t('notif_title_purchase_deleted'), t('notif_msg_purchase_deleted', $productName), null, null, 'notif_title_purchase_deleted', 'notif_msg_purchase_deleted', ['product' => $productName]);
            } catch (Exception $e) {}
                header("Location: add-purchase.php?msg=purchase_deleted");
                exit;
            } else {
                $error = t('purchases_product_not_found_id');
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Purchase Delete Error: ' . $e->getMessage());
            $error = t('purchases_error_deleting');
        }
    }
}

// Get purchase info for confirmation
$purchase_id = intval($_GET['id'] ?? 0);
$purchase_info = null;
if ($purchase_id > 0) {
    $purchase_info = fetchOne(
        "SELECT p.*, pr.name as product_name, pr.code, pr.unit FROM purchases p JOIN products pr ON p.product_id = pr.id WHERE p.id = ?",
        [$purchase_id]
    );
}

require_once '../includes/header.php';
?>

<div class="container" style="max-width: 600px; margin-top: 2rem;">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                <line x1="10" y1="11" x2="10" y2="17"/>
                <line x1="14" y1="11" x2="14" y2="17"/>
            </svg>
            <?php echo t('purchases_delete'); ?>
        </h1>
        <a href="add-purchase.php" class="btn btn-outline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?php echo t('back'); ?>
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error">
            <ul style="margin: 5px 20px;">
                <li><?php echo htmlspecialchars($error); ?></li>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$purchase_info): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: 3rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--gray-300)" stroke-width="2" width="64" height="64">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <h3 style="margin-top: 1rem; color: var(--gray-500);"><?php echo t('purchases_product_not_found_id'); ?></h3>
                <a href="add-purchase.php" class="btn btn-outline" style="margin-top: 1rem;"><?php echo t('back'); ?></a>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="border: 2px solid #fecaca;">
            <div class="card-header" style="background: #fef2f2;">
                <h2 style="color: #dc2626;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 6px;">
                        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <?php echo t('confirm_delete'); ?>
                </h2>
            </div>
            <div class="card-body">
                <div class="alert alert-error" style="font-weight: 700;">
                    <?php echo t('purchases_confirm_delete_with_stock'); ?>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('code'); ?></div>
                            <div style="font-weight: 700; font-family: monospace; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; display: inline-block;"><?php echo htmlspecialchars($purchase_info['code']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('product'); ?></div>
                            <div style="font-weight: 700;"><?php echo htmlspecialchars($purchase_info['product_name']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('quantity'); ?></div>
                            <div style="font-weight: 700;"><?php echo number_format($purchase_info['quantity'], 2) . ' ' . htmlspecialchars($purchase_info['unit']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('purchases_price'); ?></div>
                            <div style="font-weight: 700;"><?php echo formatMoney($purchase_info['purchase_price']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('total'); ?></div>
                            <div style="font-weight: 800; font-size: 1.1rem; color: #1e40af;"><?php echo formatMoney($purchase_info['total']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('date'); ?></div>
                            <div style="font-weight: 700;"><?php echo $purchase_info['purchase_date']; ?></div>
                        </div>
                        <?php if (!empty($purchase_info['notes'])): ?>
                        <div style="grid-column: 1 / -1;">
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('notes'); ?></div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($purchase_info['notes']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                    <a href="add-purchase.php" class="btn btn-outline" style="min-width: 120px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php echo t('cancel'); ?>
                    </a>
                    <form method="POST" action="" onsubmit="return confirm('<?php echo t('purchases_confirm_delete'); ?>');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="purchase_id" value="<?php echo $purchase_id; ?>">
                        <button type="submit" class="btn" style="min-width: 120px; background: #dc2626; color: white; border: none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            <?php echo t('purchases_delete'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
