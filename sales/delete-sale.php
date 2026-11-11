<?php
require_once '../includes/bootstrap.php';
$page_title = t('sales_delete');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('sales.delete');

$error = '';

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $sale_id = intval($_POST['sale_id'] ?? 0);

    if ($sale_id > 0) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // Get sale details
            $saleStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
            $saleStmt->execute([$sale_id]);
            $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

            if ($sale) {
                // Restore product quantity
                $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([
                    floatval($sale['quantity']),
                    intval($sale['product_id'])
                ]);

                // Restore batch quantities from sale_batch_items
                $batchItems = $pdo->prepare("SELECT batch_id, quantity_taken FROM sale_batch_items WHERE sale_id = ?");
                $batchItems->execute([$sale_id]);
                $batchRestoreStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity + ? WHERE id = ?");
                while ($bi = $batchItems->fetch(PDO::FETCH_ASSOC)) {
                    $batchRestoreStmt->execute([floatval($bi['quantity_taken']), intval($bi['batch_id'])]);
                }
                // Delete sale_batch_items
                $pdo->prepare("DELETE FROM sale_batch_items WHERE sale_id = ?")->execute([$sale_id]);

                // Delete the sale
                $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$sale_id]);

                $pdo->commit();

                // إشعار حذف عملية بيع
                try {
                    $productName = fetchOne("SELECT name FROM products WHERE id = ?", [intval($sale['product_id'])]);
                    $productName = $productName ? $productName['name'] : '';
                    $productUnit = fetchOne("SELECT unit FROM products WHERE id = ?", [intval($sale['product_id'])]);
                    $productUnit = $productUnit ? $productUnit['unit'] : '';
                    $amountFormatted = number_format(floatval($sale['quantity']), 2); AppNotification::add(NOTIF_SALE, t('notif_title_sale_deleted'), t('notif_msg_sale_deleted', $productName, $amountFormatted, $productUnit), null, null, 'notif_title_sale_deleted', 'notif_msg_sale_deleted', ['product' => $productName, 'amount' => $amountFormatted, 'unit' => $productUnit]);
                } catch (Exception $e) {}
                
                header("Location: add-sale.php?msg=sale_deleted");
                exit;
            } else {
                $error = t('sales_product_not_found');
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Sale Delete Error: ' . $e->getMessage());
            $error = t('sales_error_deleting');
        }
    }
}

// Get sale info for confirmation
$sale_id = intval($_GET['id'] ?? 0);
$sale_info = null;
if ($sale_id > 0) {
    $sale_info = fetchOne(
        "SELECT s.*, p.name as product_name, p.code, p.unit FROM sales s JOIN products p ON s.product_id = p.id WHERE s.id = ?",
        [$sale_id]
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
            <?php echo t('sales_delete'); ?>
        </h1>
        <a href="add-sale.php" class="btn btn-outline">
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

    <?php if (!$sale_info): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: 3rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--gray-300)" stroke-width="2" width="64" height="64">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <h3 style="margin-top: 1rem; color: var(--gray-500);"><?php echo t('sales_product_not_found'); ?></h3>
                <a href="add-sale.php" class="btn btn-outline" style="margin-top: 1rem;"><?php echo t('back'); ?></a>
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
                    <?php echo t('sales_delete_confirm'); ?>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('code'); ?></div>
                            <div style="font-weight: 700; font-family: monospace; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; display: inline-block;"><?php echo htmlspecialchars($sale_info['code']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('product'); ?></div>
                            <div style="font-weight: 700;"><?php echo htmlspecialchars($sale_info['product_name']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('quantity'); ?></div>
                            <div style="font-weight: 700;"><?php echo number_format($sale_info['quantity'], 2) . ' ' . htmlspecialchars($sale_info['unit']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('sales_price'); ?></div>
                            <div style="font-weight: 700;"><?php echo formatMoney($sale_info['selling_price']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('total'); ?></div>
                            <div style="font-weight: 800; font-size: 1.1rem; color: #1e40af;"><?php echo formatMoney($sale_info['total']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('date'); ?></div>
                            <div style="font-weight: 700;"><?php echo $sale_info['sale_date']; ?></div>
                        </div>
                        <?php if (!empty($sale_info['notes'])): ?>
                        <div style="grid-column: 1 / -1;">
                            <div style="font-size: 0.8rem; color: var(--gray-400); margin-bottom: 2px;"><?php echo t('notes'); ?></div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($sale_info['notes']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                    <a href="add-sale.php" class="btn btn-outline" style="min-width: 120px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php echo t('cancel'); ?>
                    </a>
                    <form method="POST" action="" onsubmit="return confirm('<?php echo t('sales_confirm_full_delete'); ?>');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                        <button type="submit" class="btn" style="min-width: 120px; background: #dc2626; color: white; border: none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            <?php echo t('sales_delete'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
