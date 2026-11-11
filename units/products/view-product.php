<?php
$page_title = 'تفاصيل المنتج';
require_once '../config/database.php';
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/header.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header("Location: list-products.php"); exit; }

$product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
if (!$product) { header("Location: list-products.php"); exit; }

$additional_units = [];
try {
    $additional_units = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$id]);
} catch (Exception $e) {}
$purchase_additional_units = [];
try {
    $purchase_additional_units = fetchAll("SELECT * FROM purchase_additional_units WHERE product_id = ?", [$id]);
} catch (Exception $e) {}
$recent_purchases = fetchAll("SELECT * FROM purchases WHERE product_id = ? ORDER BY purchase_date DESC LIMIT 10", [$id]);
$recent_sales = fetchAll("SELECT * FROM sales WHERE product_id = ? ORDER BY sale_date DESC LIMIT 10", [$id]);
$price_history = [];
try {
    $price_history = fetchAll("SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT 10", [$id]);
} catch (Exception $e) {}

// جلب دفعات الشراء المتوفرة
$available_batches = [];
try {
    $pdo_tmp = getDBConnection();
    $batchStmt = $pdo_tmp->prepare("SELECT pb.*, pur.notes as purchase_notes FROM purchase_batches pb LEFT JOIN purchases pur ON pb.purchase_id = pur.id WHERE pb.product_id = ? ORDER BY pb.purchase_date ASC, pb.id ASC");
    $batchStmt->execute([$id]);
    $available_batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // جدول الدفعات قد لا يكون موجوداً بعد
}


// === جلب صور المنتج ===
$productImages = [];
$primaryImage = null;
try {
    require_once __DIR__ . '/../../includes/ProductImageService.php';
    $productImages = ProductImageService::getActiveImages($id);
    $primaryImage = ProductImageService::getPrimaryImage($id);
} catch (Exception $e) {}

// حساب إجمالي المشتريات والمبيعات
$total_purchased = fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM purchases WHERE product_id = ?", [$id])['total'];
$total_sold = fetchOne("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE product_id = ?", [$id])['total'];
$total_qty_purchased = fetchOne("SELECT COALESCE(SUM(quantity), 0) as total FROM purchases WHERE product_id = ?", [$id])['total'];
$total_qty_sold = fetchOne("SELECT COALESCE(SUM(quantity), 0) as total FROM sales WHERE product_id = ?", [$id])['total'];

// استخدام الأرباح المحفوظة وقت البيع
$total_profit = fetchOne("SELECT COALESCE(SUM(profit), 0) as total FROM sales WHERE product_id = ?", [$id])['total'];
$total_cost = fetchOne("SELECT COALESCE(SUM(cost_total), 0) as total FROM sales WHERE product_id = ?", [$id])['total'];
?>

<div class="container">
    <div class="page-header">
        <h1>تفاصيل المنتج: <?php echo htmlspecialchars($product['name']); ?></h1>
        <div class="btn-group">
            <a href="edit-product.php?id=<?php echo $id; ?>" class="btn btn-primary">تعديل</a>
            <a href="list-products.php" class="btn btn-outline">العودة</a>
        </div>
    </div>

    <!-- معلومات المنتج -->
    <div class="card">
        <div class="card-header"><h2>معلومات المنتج</h2></div>
        <div class="card-body">
            <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
                <?php
                $imgSrc = '';
                $imgOriginal = '';
                if ($primaryImage) {
                    $imgSrc = !empty($primaryImage['medium_path']) ? ProductImageService::getWebPath($primaryImage['medium_path']) : '';
                    $imgOriginal = !empty($primaryImage['original_path']) ? ProductImageService::getWebPath($primaryImage['original_path']) : $imgSrc;
                }
                if (empty($imgSrc) && !empty($product['primary_image'])) {
                    $rawPath = $product['primary_image'];
                    $imgSrc = ProductImageService::getWebPath($rawPath);
                    if (empty($imgSrc) || $imgSrc === $rawPath) { $imgSrc = $rawPath; }
                    $imgOriginal = $imgSrc;
                }
                ?>
                <?php if ($imgSrc): ?>
                <div style="flex-shrink:0; width:200px; height:200px; border-radius:14px; overflow:hidden; border:2px solid var(--gray-200); background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;"
                     onclick="openLightbox('<?php echo htmlspecialchars($imgOriginal ?: $imgSrc); ?>')"
                     onmouseenter="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 4px 16px rgba(37,99,235,0.15)';"
                     onmouseleave="this.style.borderColor='var(--gray-200)'; this.style.boxShadow='none';">
                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"
                         style="max-width:100%; max-height:100%; object-fit:contain;">
                </div>
                <?php else: ?>
                <div style="flex-shrink:0; width:200px; height:200px; border-radius:14px; overflow:hidden; border:2px dashed var(--gray-200); background:var(--gray-50); display:flex; align-items:center; justify-content:center; color:var(--gray-300);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                </div>
                <?php endif; ?>
                <div class="form-grid" style="flex:1; min-width:250px;">
                    <div><strong>الكود:</strong> <?php echo htmlspecialchars($product['code']); ?></div>
                    <div><strong>الاسم:</strong> <?php echo htmlspecialchars($product['name']); ?></div>
                    <div><strong>سعر الشراء الحالي:</strong> <?php echo formatMoney($product['purchase_price']); ?></div>
                    <div><strong>سعر البيع الحالي:</strong> <?php echo formatMoney($product['selling_price']); ?></div>
                    <div><strong>الوحدة:</strong> <?php echo htmlspecialchars($product['unit']); ?></div>
                    <div><strong>الكمية الحالية:</strong> <?php echo number_format($product['quantity'], 2); ?></div>
                </div>
            </div>
        </div>
    </div>


    <?php if (!empty($productImages) && count($productImages) > 0): ?>
    <div class="card">
        <div class="card-header"><h2>صور المنتج (<?php echo count($productImages); ?>)</h2></div>
        <div class="card-body">
            <div style="display:flex; gap:14px; flex-wrap:wrap;">
                <?php foreach ($productImages as $idx => $img):
                    $gThumbWeb = !empty($img['thumbnail_path']) ? ProductImageService::getWebPath($img['thumbnail_path']) : '';
                    $gOrigWeb = !empty($img['original_path']) ? ProductImageService::getWebPath($img['original_path']) : (!empty($img['medium_path']) ? ProductImageService::getWebPath($img['medium_path']) : $gThumbWeb);
                    $isPrimary = !empty($img['is_primary']);
                ?>
                <div style="width:120px; height:120px; border-radius:12px; overflow:hidden; border:2px solid <?php echo $isPrimary ? 'var(--primary)' : 'var(--gray-200)'; ?>; background:#fff; cursor:pointer; position:relative; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                     onclick="openLightbox('<?php echo htmlspecialchars($gOrigWeb); ?>')"
                     onmouseenter="this.style.borderColor='var(--primary)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(37,99,235,0.15)';"
                     onmouseleave="this.style.borderColor='<?php echo $isPrimary ? 'var(--primary)' : 'var(--gray-200)'; ?>'; this.style.transform='none'; this.style.boxShadow='none';">
                    <img src="<?php echo htmlspecialchars($gThumbWeb); ?>" alt="صورة <?php echo $idx + 1; ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                    <?php if ($isPrimary): ?>
                    <div style="position:absolute; top:4px; left:4px; background:var(--primary); color:#fff; font-size:0.6rem; padding:2px 6px; border-radius:5px; font-weight:700;">رئيسية</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lightbox -->
    <div id="imageLightbox" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:10000; align-items:center; justify-content:center; cursor:pointer; backdrop-filter:blur(4px);" onclick="closeLightbox()">
        <div style="position:relative; max-width:90vw; max-height:90vh; display:flex; align-items:center; justify-content:center;" onclick="event.stopPropagation();">
            <img id="lightboxImg" src="" alt="صورة مكبرة" style="max-width:90vw; max-height:85vh; object-fit:contain; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,0.5);">
            <button onclick="closeLightbox()" style="position:absolute; top:-14px; right:-14px; width:36px; height:36px; border-radius:50%; background:#fff; color:#333; border:none; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,0.3);">&times;</button>
        </div>
    </div>
    <script>
    function openLightbox(src) { var lb = document.getElementById('imageLightbox'); document.getElementById('lightboxImg').src = src; lb.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
    function closeLightbox() { document.getElementById('imageLightbox').style.display = 'none'; document.getElementById('lightboxImg').src = ''; document.body.style.overflow = ''; }
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeLightbox(); });
    </script>

    <?php if (!empty($additional_units)): ?>
    <div class="card">
        <div class="card-header"><h2>وحدات قياس إضافية للبيع</h2></div>
        <div class="card-body">
            <table>
                <thead>
                    <tr><th>اسم الوحدة</th><th>معامل التحويل</th><th>سعر البيع</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($additional_units as $au): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($au['unit_name']); ?></td>
                        <td>1 <?php echo htmlspecialchars($au['unit_name']); ?> = <?php echo $au['conversion_factor']; ?> <?php echo htmlspecialchars($product['unit']); ?></td>
                        <td><?php echo formatMoney($au['selling_price']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($product['purchase_unit'])): ?>
    <div class="card">
        <div class="card-header"><h2>وحدة الشراء الأساسية</h2></div>
        <div class="card-body">
            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                <div style="background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #bfdbfe; border-radius:10px; padding:12px 18px;">
                    <div style="font-size:0.8rem; color:#64748b;">وحدة الشراء</div>
                    <div style="font-size:1.2rem; font-weight:800; color:#1e40af;"><?php echo htmlspecialchars($product['purchase_unit']); ?></div>
                </div>
                <div style="background:linear-gradient(135deg, #fef3c7, #fde68a); border:1px solid #fcd34d; border-radius:10px; padding:12px 18px;">
                    <div style="font-size:0.8rem; color:#92400e;">معامل التحويل</div>
                    <div style="font-size:1.2rem; font-weight:800; color:#92400e;">1 <?php echo htmlspecialchars($product['purchase_unit']); ?> = <?php echo floatval($product['purchase_unit_factor']); ?> <?php echo htmlspecialchars($product['unit']); ?></div>
                </div>
            </div>
            <?php if (!empty($purchase_additional_units)): ?>
            <table style="margin-top:12px;">
                <thead>
                    <tr><th>اسم الوحدة</th><th>معامل التحويل</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($purchase_additional_units as $pau): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($pau['unit_name']); ?></td>
                        <td>1 <?php echo htmlspecialchars($pau['unit_name']); ?> = <?php echo $pau['conversion_factor']; ?> <?php echo htmlspecialchars($product['purchase_unit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ملخص -->
    <div class="stats-grid">
        <div class="stat-card cyan">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4"/></svg>
            </div>
            <div class="stat-info"><h3>إجمالي المشتريات</h3><div class="stat-value"><?php echo formatMoney($total_purchased); ?></div></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M16 11V7a4 4 0 00-8 0v4"/></svg>
            </div>
            <div class="stat-info"><h3>إجمالي المبيعات</h3><div class="stat-value"><?php echo formatMoney($total_sold); ?></div></div>
        </div>
        <div class="stat-card <?php echo $total_profit >= 0 ? 'green' : 'red'; ?>">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/></svg>
            </div>
            <div class="stat-info"><h3>إجمالي الربح</h3><div class="stat-value <?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatMoney($total_profit); ?></div></div>
        </div>
    </div>

    <div class="two-col-grid">
        <div class="card">
            <div class="card-header"><h2>سجل المشتريات</h2></div>
            <div class="card-body">
                <?php if (empty($recent_purchases)): ?>
                    <p class="text-muted" style="text-align:center;">لا توجد مشتريات</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>التاريخ</th><th>الكمية</th><th>سعر الشراء</th><th>المبلغ</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_purchases as $p): ?>
                        <tr>
                            <td><?php echo $p['purchase_date']; ?></td>
                            <td><?php echo number_format($p['quantity'], 2); ?></td>
                            <td><?php echo formatMoney($p['purchase_price']); ?></td>
                            <td><?php echo formatMoney($p['total']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>سجل المبيعات</h2></div>
            <div class="card-body">
                <?php if (empty($recent_sales)): ?>
                    <p class="text-muted" style="text-align:center;">لا توجد مبيعات</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>التاريخ</th><th>الكمية</th><th>سعر البيع</th><th>سعر الشراء وقت البيع</th><th>الربح</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_sales as $s): 
                            $sale_profit = $s['profit'] ?? ($s['total'] - ($s['cost_total'] ?? 0));
                        ?>
                        <tr>
                            <td><?php echo $s['sale_date']; ?></td>
                            <td><?php echo number_format($s['quantity'], 2); ?></td>
                            <td><?php echo formatMoney($s['selling_price']); ?></td>
                            <td class="text-muted"><?php echo formatMoney($s['purchase_price_at_sale'] ?? 0); ?></td>
                            <td class="<?php echo $sale_profit >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatMoney($sale_profit); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- سجل تغيير الأسعار -->
    <div class="card">
        <div class="card-header"><h2>سجل تغيير الأسعار</h2></div>
        <div class="card-body">
            <?php if (empty($price_history)): ?>
                <p class="text-muted" style="text-align:center; padding:1.5rem;">لم يتم تسجيل أي تغيير في الأسعار بعد</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ والوقت</th>
                            <th>سعر الشراء القديم</th>
                            <th>سعر الشراء الجديد</th>
                            <th>التغيير</th>
                            <th>سعر البيع القديم</th>
                            <th>سعر البيع الجديد</th>
                            <th>التغيير</th>
                            <th>السبب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($price_history as $ph): 
                            $purchase_diff = $ph['new_purchase_price'] - $ph['old_purchase_price'];
                            $selling_diff = $ph['new_selling_price'] - $ph['old_selling_price'];
                        ?>
                        <tr>
                            <td><?php echo $ph['changed_at']; ?></td>
                            <td><?php echo formatMoney($ph['old_purchase_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($ph['new_purchase_price']); ?></td>
                            <td class="<?php echo $purchase_diff >= 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo ($purchase_diff >= 0 ? '+' : '') . formatMoney($purchase_diff); ?>
                            </td>
                            <td><?php echo formatMoney($ph['old_selling_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($ph['new_selling_price']); ?></td>
                            <td class="<?php echo $selling_diff >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($selling_diff >= 0 ? '+' : '') . formatMoney($selling_diff); ?>
                            </td>
                            <td><?php echo htmlspecialchars($ph['change_reason'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- دفعات الشراء المتوفرة في المخزون -->
    <?php if (!empty($available_batches)): ?>
    <div class="card">
        <div class="card-header"><h2>دفعات الشراء المتوفرة في المخزون (FIFO)</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>طريقة FIFO:</strong> عند البيع تُستهلك الدفعات بالترتيب من الأقدم للأحدث. كل كمية تُحسب بسعر شرائها الأصلي.
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>تاريخ الشراء</th>
                            <th>الكمية الأصلية</th>
                            <th>الكمية المتبقية</th>
                            <th>سعر شراء الوحدة</th>
                            <th>قيمة المتبقي</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1; 
                        $total_batch_value = 0;
                        foreach ($available_batches as $ab): 
                            $remaining = floatval($ab['remaining_quantity']);
                            $original = floatval($ab['original_quantity']);
                            $batch_value = $remaining * floatval($ab['purchase_price']);
                            $total_batch_value += $batch_value;
                            $is_empty = $remaining <= 0;
                        ?>
                        <tr style="<?php echo $is_empty ? 'opacity:0.5;' : ''; ?>">
                            <td><?php echo $i++; ?></td>
                            <td><?php echo $ab['purchase_date']; ?></td>
                            <td><?php echo number_format($original, 2); ?></td>
                            <td class="text-bold"><?php echo number_format($remaining, 2); ?></td>
                            <td><?php echo formatMoney($ab['purchase_price']); ?></td>
                            <td><?php echo formatMoney($batch_value); ?></td>
                            <td>
                                <?php if ($is_empty): ?>
                                    <span class="badge badge-danger">مستنفدة</span>
                                <?php elseif ($remaining < $original): ?>
                                    <span class="badge badge-warning">جزئية</span>
                                <?php else: ?>
                                    <span class="badge badge-success">كاملة</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-50); font-weight:700;">
                            <td colspan="4">إجمالي قيمة المخزون</td>
                            <td>-</td>
                            <td><?php echo formatMoney($total_batch_value); ?></td>
                            <td>-</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
