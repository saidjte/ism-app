<style>
/* === أنماط صور المنتج === */
.vp-product-top {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.vp-primary-img-wrap {
    flex-shrink: 0;
    width: 220px;
    height: 220px;
    border-radius: 14px;
    overflow: hidden;
    border: 2px solid var(--gray-200);
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}
.vp-primary-img-wrap:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 16px rgba(37,99,235,0.15);
}
.vp-primary-img-wrap img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.vp-primary-img-placeholder {
    flex-shrink: 0;
    width: 220px;
    height: 220px;
    border-radius: 14px;
    overflow: hidden;
    border: 2px dashed var(--gray-200);
    background: var(--gray-50);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray-300);
}
.vp-gallery {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
}
.vp-gallery-item {
    position: relative;
    width: 72px;
    height: 72px;
    border-radius: 10px;
    overflow: hidden;
    border: 2px solid var(--gray-200);
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}
.vp-gallery-item:hover {
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(37,99,235,0.12);
}
.vp-gallery-item.is-primary {
    border-color: var(--primary);
    border-width: 2px;
}
.vp-gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.vp-gallery-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    font-size: 0.6rem;
    padding: 1px 4px;
    border-radius: 4px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    z-index: 2;
}
.vp-gallery-badge.badge-primary {
    background: var(--primary);
    color: #fff;
}
.vp-gallery-badge.badge-nobg {
    background: #8b5cf6;
    color: #fff;
}

/* === Lightbox === */
.vp-lightbox-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0,0,0,0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    align-items: center;
    justify-content: center;
    cursor: zoom-out;
}
.vp-lightbox-overlay.active {
    display: flex;
    animation: vpFadeIn 0.25s ease;
}
.vp-lightbox-overlay img {
    max-width: 92vw;
    max-height: 90vh;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.5);
    cursor: default;
}
.vp-lightbox-close {
    position: absolute;
    top: 18px;
    left: 18px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: none;
    color: #fff;
    font-size: 1.4rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    z-index: 10;
}
.vp-lightbox-close:hover {
    background: rgba(255,255,255,0.3);
}
@keyframes vpFadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
</style>

<div class="container">
    <div class="page-header">
        <h1><?php echo t('products_details'); ?>: <?php echo htmlspecialchars($product['name']); ?></h1>
        <div class="btn-group">
            <a href="edit-product.php?id=<?php echo $id; ?>" class="btn btn-primary"><?php echo t('edit'); ?></a>
            <a href="list-products.php" class="btn btn-outline"><?php echo t('back'); ?></a>
        </div>
    </div>

    <!-- معلومات المنتج -->
    <div class="card">
        <div class="card-header"><h2><?php echo t('products_details'); ?></h2></div>
        <div class="card-body">
            <div class="vp-product-top">
                <!-- صورة المنتج الرئيسية -->
                <?php
                $_imgBase = (!empty($base_url) ? $base_url . '/' : '/');
                $imgSrc = '';
                $imgThumb = '';
                $imgOriginal = '';
                if ($primaryImage) {
                    $imgSrc = !empty($primaryImage['medium_path']) ? $_imgBase . ProductImageService::getWebPath($primaryImage['medium_path']) : '';
                    $imgThumb = !empty($primaryImage['thumbnail_path']) ? $_imgBase . ProductImageService::getWebPath($primaryImage['thumbnail_path']) : $imgSrc;
                    $imgOriginal = !empty($primaryImage['original_path']) ? $_imgBase . ProductImageService::getWebPath($primaryImage['original_path']) : $imgSrc;
                }
                if (empty($imgSrc) && !empty($product['primary_image'])) {
                    $rawPath = $product['primary_image'];
                    $_webPath = ProductImageService::getWebPath($rawPath);
                    if (!empty($_webPath) && $_webPath !== $rawPath) {
                        $imgSrc = $_imgBase . $_webPath;
                    } else {
                        $imgSrc = (str_starts_with($rawPath, '/') || str_starts_with($rawPath, 'http')) ? $rawPath : $_imgBase . $rawPath;
                    }
                    $imgThumb = $imgSrc;
                    $imgOriginal = $imgSrc;
                }
                ?>
                <?php if ($imgSrc): ?>
                <div class="vp-primary-img-wrap"
                     onclick="openLightbox('<?php echo htmlspecialchars($imgOriginal ?: $imgSrc); ?>')">
                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--gray-100);color:var(--gray-300);\'><svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.5\' width=\'48\' height=\'48\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\'/><circle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'/><path d=\'M21 15l-5-5L5 21\'/></svg></div>'">
                </div>
                <?php else: ?>
                <div class="vp-primary-img-placeholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
                    </svg>
                </div>
                <?php endif; ?>

                <!-- معلومات المنتج -->
                <div class="form-grid" style="flex:1; min-width:280px;">
                    <div><strong><?php echo t('code'); ?>:</strong> <?php echo htmlspecialchars($product['code']); ?></div>
                    <div><strong><?php echo t('name'); ?>:</strong> <?php echo htmlspecialchars($product['name']); ?></div>
                    <div><strong>سعر الشراء الحالي:</strong> <?php echo formatMoney($product['purchase_price']); ?></div>
                    <div><strong>سعر البيع الحالي:</strong> <?php echo formatMoney($product['selling_price']); ?></div>
                    <div><strong>الوحدة:</strong> <?php echo htmlspecialchars($product['unit']); ?></div>
                    <div><strong>الكمية الحالية:</strong> <?php echo number_format($product['quantity'], 2); ?></div>
                    <div><strong><?php echo t('products_has_expiry'); ?>:</strong> <?php echo !empty($product['has_expiry']) ? '<span style="color:#059669; font-weight:700;">' . t('common_enabled_label') . '</span>' : '<span style="color:var(--gray-400);">' . t('common_disabled_label') . '</span>'; ?></div>
                </div>
            </div>

            <?php if (count($productImages) > 1): ?>
            <!-- معرض صور المنتج -->
            <div class="vp-gallery">
                <?php foreach ($productImages as $img): ?>
                    <?php
                    $thumbWeb = !empty($img['thumbnail_path']) ? $_imgBase . ProductImageService::getWebPath($img['thumbnail_path']) : '';
                    $mediumWeb = !empty($img['medium_path']) ? $_imgBase . ProductImageService::getWebPath($img['medium_path']) : $thumbWeb;
                    $origWeb = !empty($img['original_path']) ? $_imgBase . ProductImageService::getWebPath($img['original_path']) : $mediumWeb;
                    if (empty($thumbWeb)) continue;
                    $isPrimary = !empty($img['is_primary']);
                    $isBgRemoved = !empty($img['bg_removed']);
                    ?>
                    <div class="vp-gallery-item<?php echo $isPrimary ? ' is-primary' : ''; ?>"
                         onclick="openLightbox('<?php echo htmlspecialchars($origWeb); ?>')">
                        <img src="<?php echo htmlspecialchars($thumbWeb); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                        <?php if ($isPrimary): ?>
                            <span class="vp-gallery-badge badge-primary">رئيسية</span>
                        <?php endif; ?>
                        <?php if ($isBgRemoved): ?>
                            <span class="vp-gallery-badge badge-nobg" style="<?php echo $isPrimary ? 'top:auto; bottom:2px; right:2px;' : ''; ?>">بدون خلفية</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

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

    <!-- تنبيهات تواريخ الانتهاء -->
    <?php if (!empty($product['has_expiry'])): ?>
    <div class="card" style="border: 2px solid <?php echo $total_expired_qty > 0 ? '#fca5a5' : ($total_expiring_soon_qty > 0 ? '#fcd34d' : '#86efac'); ?>;">
        <div class="card-header" style="background:<?php echo $total_expired_qty > 0 ? '#fee2e2' : ($total_expiring_soon_qty > 0 ? '#fef3c7' : '#f0fdf4'); ?>;">
            <h2>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align:middle; margin-left:4px;">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                حالة تواريخ الانتهاء
            </h2>
        </div>
        <div class="card-body">
            <?php if ($total_expired_qty > 0): ?>
                <div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:10px; padding:12px 16px; margin-bottom:8px; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:1.5rem;">🚨</span>
                    <div>
                        <div style="font-weight:800; color:#dc2626; font-size:1.1rem;">منتهي الصلاحية: <?php echo number_format($total_expired_qty, 2); ?> <?php echo htmlspecialchars($product['unit']); ?></div>
                        <div style="font-size:0.82rem; color:#991b1b;"><?php echo count($expired_batches); ?> دفعة (دفعات) منتهية يجب التخلص منها</div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($total_expiring_soon_qty > 0): ?>
                <div style="background:#fef3c7; border:1px solid #fcd34d; border-radius:10px; padding:12px 16px; margin-bottom:8px; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:1.5rem;">⚠️</span>
                    <div>
                        <div style="font-weight:800; color:#d97706; font-size:1.1rem;">قاربت الانتهاء (خلال <?php echo $expiry_alert_days_view; ?> يوم): <?php echo number_format($total_expiring_soon_qty, 2); ?> <?php echo htmlspecialchars($product['unit']); ?></div>
                        <div style="font-size:0.82rem; color:#92400e;"><?php echo count($expiring_soon_batches); ?> دفعة (دفعات) ستقريباً تنتهي</div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($total_expired_qty == 0 && $total_expiring_soon_qty == 0): ?>
                <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:12px 16px; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:1.5rem;">✅</span>
                    <div style="font-weight:700; color:#059669;">جميع الدفعات صالحة - لا توجد دفعات منتهية أو قاربت الانتهاء</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

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
        <div class="card-header"><h2>دفعات الشراء المتوفرة في المخزون (FEFO)</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>طريقة FEFO:</strong> عند البيع تُستهلك الدفعات بالترتيب من الأقرب انتهاءً للأبعد. كل كمية تُحسب بسعر شرائها الأصلي. الدفعات بدون تاريخ انتهاء تُباع حسب تاريخ الشراء (FIFO).
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>تاريخ الشراء</th>
                            <th>تاريخ الانتهاء</th>
                            <th>الأيام المتبقية</th>
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
                            <td>
                                <?php if (!empty($ab['expiry_date'])):
                                    $is_expired = $ab['expiry_date'] < $today;
                                    $is_expiring = !$is_expired && $ab['expiry_date'] <= $expiry_limit_view;
                                    $date_style = $is_expired ? 'color:#dc2626; font-weight:700; background:#fee2e2; padding:2px 8px; border-radius:4px;' : ($is_expiring ? 'color:#d97706; font-weight:700; background:#fef3c7; padding:2px 8px; border-radius:4px;' : 'color:#059669; font-weight:600; background:#f0fdf4; padding:2px 8px; border-radius:4px;');
                                ?>
                                    <span style="<?php echo $date_style; ?>">
                                        <?php echo $ab['expiry_date']; ?>
                                        <?php if ($is_expired): ?> 🚨 <?php elseif ($is_expiring): ?> ⚠️ <?php else: ?> ✅ <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--gray-300);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($ab['expiry_date'])):
                                    $days_remaining = intval((strtotime($ab['expiry_date']) - strtotime($today)) / 86400);
                                    if ($days_remaining < 0) {
                                        $days_label = '<span style="color:#dc2626; font-weight:800;">منتهي منذ ' . abs($days_remaining) . ' يوم</span>';
                                    } elseif ($days_remaining == 0) {
                                        $days_label = '<span style="color:#dc2626; font-weight:800;">ينتهي اليوم!</span>';
                                    } elseif ($days_remaining <= 7) {
                                        $days_label = '<span style="color:#dc2626; font-weight:800;">🔴 ' . $days_remaining . ' يوم</span>';
                                    } elseif ($days_remaining <= 14) {
                                        $days_label = '<span style="color:#d97706; font-weight:700;">⚠️ ' . $days_remaining . ' يوم</span>';
                                    } elseif ($days_remaining <= 30) {
                                        $days_label = '<span style="color:#ca8a04; font-weight:600;">🟡 ' . $days_remaining . ' يوم</span>';
                                    } else {
                                        $days_label = '<span style="color:#059669; font-weight:600;">' . $days_remaining . ' يوم</span>';
                                    }
                                    echo $days_label;
                                else: ?>
                                    <span style="color:var(--gray-300);">—</span>
                                <?php endif; ?>
                            </td>
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
                            <td colspan="6">إجمالي قيمة المخزون</td>
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

<!-- Lightbox Overlay -->
<div class="vp-lightbox-overlay" id="vpLightbox" onclick="closeLightbox(event)">
    <button class="vp-lightbox-close" onclick="closeLightbox(event)" aria-label="إغلاق">&times;</button>
    <img id="vpLightboxImg" src="" alt="">
</div>

<script>
function openLightbox(src) {
    var overlay = document.getElementById('vpLightbox');
    var img = document.getElementById('vpLightboxImg');
    img.src = src;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox(e) {
    if (e && e.target && e.target.tagName === 'IMG') return;
    var overlay = document.getElementById('vpLightbox');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('vpLightboxImg').src = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLightbox();
    }
});
</script>