<?php
/**
 * API لإدارة الدفعات (مسح، تفريغ، تعديل الكمية)
 * v5.13.0: ميزة جديدة — إدارة الدفعات في نظام FEFO
 */

ob_start();
require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

if (empty($action)) {
    if (ob_get_level()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'الإجراء مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!AppCsrf::verify($csrf)) {
        if (ob_get_level()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'رمز الأمان غير صالح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $pdo = getDBConnection();

    switch ($action) {
        case 'delete_batch':
            // حذف دفعة واحدة
            if (!hasPermission('products.edit')) {
                jsonResponse(false, 'ليس لديك صلاحية تعديل المنتجات');
            }
            $batch_id = intval($_POST['batch_id'] ?? 0);
            if ($batch_id <= 0) {
                jsonResponse(false, 'معرف الدفعة مطلوب');
            }

            $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$batch_id]);
            if (!$batch) {
                jsonResponse(false, 'الدفعة غير موجودة');
            }

            $pdo->beginTransaction();

            // تحديث كمية المنتج (خصم الكمية المتبقية)
            $remainingQty = floatval($batch['remaining_quantity'] ?? 0);
            if ($remainingQty > 0) {
                $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
                    ->execute([$remainingQty, intval($batch['product_id'])]);
            }

            // حذف سجلات sale_batch_items المرتبطة
            try {
                $pdo->prepare("DELETE FROM sale_batch_items WHERE batch_id = ?")->execute([$batch_id]);
            } catch (\Throwable $e) {}

            // حذف الدفعة
            $pdo->prepare("DELETE FROM purchase_batches WHERE id = ?")->execute([$batch_id]);

            // تحديث سعر شراء المنتج
            syncProductPurchasePrice($pdo, intval($batch['product_id']));

            $pdo->commit();

            addAuditLog('batch_delete', "حذف دفعة #{$batch_id} للمنتج");
            jsonResponse(true, 'تم حذف الدفعة بنجاح وتحديث المخزون');
            break;

        case 'clear_batches':
            // تفريغ كل دفعات منتج معين
            if (!hasPermission('products.edit')) {
                jsonResponse(false, 'ليس لديك صلاحية تعديل المنتجات');
            }
            $product_id = intval($_POST['product_id'] ?? 0);
            if ($product_id <= 0) {
                jsonResponse(false, 'معرف المنتج مطلوب');
            }

            $product = fetchOne("SELECT id FROM products WHERE id = ?", [$product_id]);
            if (!$product) {
                jsonResponse(false, 'المنتج غير موجود');
            }

            $pdo->beginTransaction();

            // حساب إجمالي الكمية المتبقية في الدفعات
            $totalRemaining = fetchOne(
                "SELECT COALESCE(SUM(remaining_quantity), 0) as total FROM purchase_batches WHERE product_id = ?",
                [$product_id]
            );
            $totalQty = floatval($totalRemaining['total'] ?? 0);

            // حذف سجلات sale_batch_items المرتبطة
            try {
                $batchIds = fetchAll("SELECT id FROM purchase_batches WHERE product_id = ?", [$product_id]);
                foreach ($batchIds as $bid) {
                    try {
                        $pdo->prepare("DELETE FROM sale_batch_items WHERE batch_id = ?")->execute([intval($bid['id'])]);
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {}

            // حذف كل الدفعات
            $pdo->prepare("DELETE FROM purchase_batches WHERE product_id = ?")->execute([$product_id]);

            // v5.13.1: تحديث كمية المنتج (خصم إجمالي الكميات المتبقية من الدفعات)
            if ($totalQty > 0) {
                $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
                    ->execute([$totalQty, $product_id]);
            }

            // تحديث سعر الشراء
            syncProductPurchasePrice($pdo, $product_id);

            $pdo->commit();

            addAuditLog('batch_clear', "تفريغ كل دفعات المنتج #{$product_id}");
            jsonResponse(true, 'تم تفريغ كل الدفعات بنجاح');
            break;

        case 'update_batch_quantity':
            // تعديل كمية دفعة
            if (!hasPermission('products.edit')) {
                jsonResponse(false, 'ليس لديك صلاحية تعديل المنتجات');
            }
            $batch_id = intval($_POST['batch_id'] ?? 0);
            $new_quantity = floatval($_POST['remaining_quantity'] ?? -1);
            if ($batch_id <= 0) {
                jsonResponse(false, 'معرف الدفعة مطلوب');
            }
            if ($new_quantity < 0) {
                jsonResponse(false, 'الكمية يجب أن تكون أكبر من أو تساوي صفر');
            }

            $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$batch_id]);
            if (!$batch) {
                jsonResponse(false, 'الدفعة غير موجودة');
            }

            $pdo->beginTransaction();

            $oldRemaining = floatval($batch['remaining_quantity'] ?? 0);
            $diff = $new_quantity - $oldRemaining;

            // تحديث كمية الدفعة
            $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = ? WHERE id = ?")
                ->execute([$new_quantity, $batch_id]);

            // مزامنة كمية المنتج
            $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity + ?) WHERE id = ?")
                ->execute([$diff, intval($batch['product_id'])]);

            // تحديث سعر الشراء
            syncProductPurchasePrice($pdo, intval($batch['product_id']));

            $pdo->commit();

            addAuditLog('batch_edit', "تعديل كمية الدفعة #{$batch_id}: {$oldRemaining} → {$new_quantity}");
            jsonResponse(true, 'تم تعديل كمية الدفعة بنجاح وتحديث المخزون');
            break;

        case 'update_batch_expiry':
            // تعديل تاريخ انتهاء دفعة
            if (!hasPermission('products.edit')) {
                jsonResponse(false, 'ليس لديك صلاحية تعديل المنتجات');
            }
            $batch_id = intval($_POST['batch_id'] ?? 0);
            $expiry_date = trim($_POST['expiry_date'] ?? '');
            if ($batch_id <= 0) {
                jsonResponse(false, 'معرف الدفعة مطلوب');
            }

            $batch = fetchOne("SELECT * FROM purchase_batches WHERE id = ?", [$batch_id]);
            if (!$batch) {
                jsonResponse(false, 'الدفعة غير موجودة');
            }

            // التحقق من صيغة التاريخ إذا تم إدخاله
            if (!empty($expiry_date)) {
                $dateCheck = DateTime::createFromFormat('Y-m-d', $expiry_date);
                if (!$dateCheck || $dateCheck->format('Y-m-d') !== $expiry_date) {
                    jsonResponse(false, 'صيغة تاريخ الانتهاء غير صحيحة');
                }
            }

            $pdo->prepare("UPDATE purchase_batches SET expiry_date = ? WHERE id = ?")
                ->execute([$expiry_date ?: null, $batch_id]);

            addAuditLog('batch_edit', "تعديل تاريخ انتهاء الدفعة #{$batch_id}: " . ($batch['expiry_date'] ?? 'بدون') . " → " . ($expiry_date ?: 'بدون'));
            jsonResponse(true, 'تم تعديل تاريخ انتهاء الدفعة بنجاح');
            break;

        case 'sync_product_quantity':
            // مزامنة كمية المنتج مع مجموع الدفعات
            if (!hasPermission('products.edit')) {
                jsonResponse(false, 'ليس لديك صلاحية تعديل المنتجات');
            }
            $product_id = intval($_POST['product_id'] ?? 0);
            if ($product_id <= 0) {
                jsonResponse(false, 'معرف المنتج مطلوب');
            }

            $pdo->beginTransaction();

            $batchTotal = fetchOne(
                "SELECT COALESCE(SUM(remaining_quantity), 0) as total FROM purchase_batches WHERE product_id = ?",
                [$product_id]
            );
            $totalFromBatches = floatval($batchTotal['total'] ?? 0);

            $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?")
                ->execute([$totalFromBatches, $product_id]);

            syncProductPurchasePrice($pdo, $product_id);

            $pdo->commit();

            jsonResponse(true, "تمت مزامنة كمية المنتج: {$totalFromBatches}");
            break;

        default:
            jsonResponse(false, 'إجراء غير معروف');
    }
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_level()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء المعالجة'], JSON_UNESCAPED_UNICODE);
}

function jsonResponse(bool $success, string $message): void
{
    if (ob_get_level()) ob_clean();
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function syncProductPurchasePrice($pdo, int $product_id): void
{
    try {
        // v5.32.1: استخدام actual_purchase_price (التكلفة الحقيقية) إن وُجد
        $newPrice = fetchOne(
            "SELECT SUM(remaining_quantity * COALESCE(actual_purchase_price, purchase_price)) / NULLIF(SUM(remaining_quantity), 0) as wp
             FROM purchase_batches WHERE product_id = ? AND remaining_quantity > 0",
            [$product_id]
        );
        if ($newPrice && floatval($newPrice['wp'] ?? 0) > 0) {
            $pdo->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")
                ->execute([floatval($newPrice['wp']), $product_id]);
        }
    } catch (\Throwable $e) {}
}
