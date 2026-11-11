<?php
/**
 * ===== جزء تنبيهات الصلاحية =====
 * يعرض تنبيهات المنتجات المنتهية والقاربة على الانتهاء
 * يشمل: بطاقة الملخص + جدول المنتهية + جدول القاربة + رسالة الإعداد
 *
 * @since v4.17.0 — تفكيك God File index.php
 *
 * المتغيرات المتوقعة:
 *   $expiry_alert_enabled, $expired_products_count, $expiring_soon_count,
 *   $expiry_alert_days_setting, $expired_financial_loss,
 *   $expired_products_list, $expiring_soon_list,
 *   $expiry_tracked_count, $expiry_batches_count
 */
?>
<?php if ($expiry_alert_enabled && ($expired_products_count > 0 || $expiring_soon_count > 0)): ?>
<div class="card" style="border: 2px solid <?php echo $expired_products_count > 0 ? '#fca5a5' : '#fcd34d'; ?>; margin-bottom: 1rem;">
    <div class="card-header" style="background:<?php echo $expired_products_count > 0 ? '#fee2e2' : '#fef3c7'; ?>; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <h2 style="margin:0;">
            <?php if ($expired_products_count > 0): ?>
                🚨 تنبيه عاجل: <?php echo number_format($expired_products_count); ?> منتج منتهي الصلاحية
            <?php else: ?>
                ⚠️ تنبيه: <?php echo number_format($expiring_soon_count); ?> منتج قارب على الانتهاء (خلال <?php echo $expiry_alert_days_setting; ?> يوم)
            <?php endif; ?>
        </h2>
        <div style="display:flex; gap:8px; align-items:center;">
            <?php if ($expired_financial_loss > 0): ?>
            <span style="background:#dc2626; color:#fff; padding:4px 12px; border-radius:6px; font-size:0.82rem; font-weight:700;">
                💰 خسارة مالية: <?php echo formatMoney($expired_financial_loss); ?>
            </span>
            <?php endif; ?>
            <a href="products/expiry-report.php" class="btn btn-sm" style="background:<?php echo $expired_products_count > 0 ? '#dc2626' : '#d97706'; ?>; color:#fff; border:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                تقرير مفصل
            </a>
        </div>
    </div>
    <div class="card-body" style="padding:12px 16px;">
        <!-- بطاقة ملخص سريع -->
        <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:12px; padding:10px 14px; background:<?php echo $expired_products_count > 0 ? '#fef2f2' : '#fffbeb'; ?>; border-radius:10px; border:1px solid <?php echo $expired_products_count > 0 ? '#fecaca' : '#fde68a'; ?>;">
            <?php if ($expired_products_count > 0): ?>
            <div style="text-align:center;">
                <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;">منتهية</div>
                <div style="font-size:1.4rem; font-weight:900; color:#dc2626;"><?php echo number_format($expired_products_count); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($expiring_soon_count > 0): ?>
            <div style="text-align:center;">
                <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;">قاربت الانتهاء</div>
                <div style="font-size:1.4rem; font-weight:900; color:#d97706;"><?php echo number_format($expiring_soon_count); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($expired_financial_loss > 0): ?>
            <div style="text-align:center;">
                <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;">الخسارة</div>
                <div style="font-size:1.4rem; font-weight:900; color:#dc2626;"><?php echo formatMoney($expired_financial_loss); ?></div>
            </div>
            <?php endif; ?>
            <div style="text-align:center;">
                <div style="font-size:0.7rem; color:#94a3b8; font-weight:600;">أيام التنبيه</div>
                <div style="font-size:1.4rem; font-weight:900; color:#64748b;"><?php echo $expiry_alert_days_setting; ?></div>
            </div>
        </div>

        <?php if (!empty($expired_products_list)): ?>
        <!-- جدول المنتجات المنتهية -->
        <div style="margin-bottom:<?php echo !empty($expiring_soon_list) ? '16px' : '0'; ?>;">
            <h4 style="color:#dc2626; margin:0 0 8px; font-size:0.9rem;">
                🚨 منتهية الصلاحية (<?php echo number_format($expired_products_count); ?> منتج):
            </h4>
            <div class="table-responsive mobile-card-view" style="max-height:280px; overflow-y:auto; border:1px solid #fecaca; border-radius:8px;">
                <table>
                    <thead style="position:sticky; top:0; z-index:1;">
                        <tr style="background:#dc2626; color:#fff;">
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية المنتهية</th>
                            <th>الخسارة</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expired_products_list as $ep): ?>
                        <tr style="background:#fef2f2; border-bottom:1px solid #fecaca;">
                            <td data-label="الكود"><strong><?php echo htmlspecialchars($ep['code']); ?></strong></td>
                            <td data-label="المنتج"><?php echo htmlspecialchars($ep['name']); ?></td>
                            <td data-label="الكمية المنتهية" class="text-danger" style="font-weight:800;"><?php echo number_format($ep['expired_qty'], 2); ?> <?php echo htmlspecialchars($ep['unit']); ?></td>
                            <td data-label="الخسارة" style="font-weight:700; color:#dc2626;"><?php echo formatMoney($ep['expired_value'] ?? 0); ?></td>
                            <td data-label="إجراء"><a href="products/view-product.php?id=<?php echo $ep['id']; ?>" class="btn btn-sm" style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5;">عرض الدفعات</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($expiring_soon_list)): ?>
        <!-- جدول المنتجات القاربة على الانتهاء -->
        <div>
            <h4 style="color:#d97706; margin:0 0 8px; font-size:0.9rem;">
                ⚠️ قاربت الانتهاء خلال <?php echo $expiry_alert_days_setting; ?> يوم (<?php echo number_format($expiring_soon_count); ?> منتج):
            </h4>
            <div class="table-responsive mobile-card-view" style="max-height:280px; overflow-y:auto; border:1px solid #fde68a; border-radius:8px;">
                <table>
                    <thead style="position:sticky; top:0; z-index:1;">
                        <tr style="background:#d97706; color:#fff;">
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>أقرب انتهاء</th>
                            <th>الأيام المتبقية</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expiring_soon_list as $es):
                            $nearest = $es['nearest_expiry'] ?? '';
                            $days_left = $nearest ? (strtotime($nearest) - strtotime(date('Y-m-d'))) / 86400 : 0;
                            $urgency_color = $days_left <= 7 ? '#dc2626' : ($days_left <= 14 ? '#d97706' : '#ca8a04');
                        ?>
                        <tr style="background:#fffbeb; border-bottom:1px solid #fde68a;">
                            <td data-label="الكود"><strong><?php echo htmlspecialchars($es['code']); ?></strong></td>
                            <td data-label="المنتج"><?php echo htmlspecialchars($es['name']); ?></td>
                            <td data-label="الكمية" style="font-weight:700; color:#d97706;"><?php echo number_format($es['expiring_qty'], 2); ?> <?php echo htmlspecialchars($es['unit']); ?></td>
                            <td data-label="أقرب انتهاء" style="font-weight:600;"><?php echo $nearest ? $nearest : '—'; ?></td>
                            <td data-label="الأيام المتبقية" style="font-weight:800; color:<?php echo $urgency_color; ?>;">
                                <?php if ($days_left <= 0): ?>
                                    🚨 منتهي!
                                <?php elseif ($days_left <= 7): ?>
                                    🚨 <?php echo intval($days_left); ?> يوم
                                <?php elseif ($days_left <= 14): ?>
                                    ⚠️ <?php echo intval($days_left); ?> يوم
                                <?php else: ?>
                                    📅 <?php echo intval($days_left); ?> يوم
                                <?php endif; ?>
                            </td>
                            <td data-label="إجراء"><a href="products/view-product.php?id=<?php echo $es['id']; ?>" class="btn btn-sm" style="background:#fef3c7; color:#d97706; border:1px solid #fcd34d;">عرض الدفعات</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($expiry_alert_enabled && $expired_products_count == 0 && $expiring_soon_count == 0): ?>
<?php
    $expiry_needs_setup = false;
    $expiry_setup_msg = '';
    $expiry_setup_icon = '';
    if ($expiry_tracked_count == 0) {
        $expiry_needs_setup = true;
        $expiry_setup_msg = 'لا يوجد أي منتج مفعّل عليه تتبع الصلاحية. فعّل خاصية "يتطلب تاريخ انتهاء الصلاحية" في المنتجات التي تحتاج لذلك.';
        $expiry_setup_icon = '📌';
    } elseif ($expiry_batches_count == 0) {
        $expiry_needs_setup = true;
        $expiry_setup_msg = 'يوجد ' . number_format($expiry_tracked_count) . ' منتج مفعّل عليه تتبع الصلاحية، لكن لا توجد دفعات شراء تحتوي على تواريخ صلاحية. أدخل تواريخ الانتهاء عند تسجيل الشراء.';
        $expiry_setup_icon = '📅';
    }
?>
<?php if ($expiry_needs_setup): ?>
<div class="card" style="margin-bottom: 1rem; border: 2px dashed #93c5fd; background: linear-gradient(135deg, #eff6ff, #f0f9ff);">
    <div class="card-body" style="padding: 16px 20px; display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
        <div style="font-size:2.2rem; line-height:1;"><?php echo $expiry_setup_icon; ?></div>
        <div style="flex:1; min-width:250px;">
            <div style="font-weight:700; font-size:0.92rem; color:#1e40af; margin-bottom:6px;">
                نظام تنبيهات الصلاحية مفعّل — لكن لا توجد بيانات لعرضها
            </div>
            <div style="font-size:0.84rem; color:#3b82f6; line-height:1.7; margin-bottom:10px;">
                <?php echo htmlspecialchars($expiry_setup_msg); ?>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:6px 12px; border-radius:8px; border:1px solid #bfdbfe; font-size:0.8rem;">
                    <span style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:0.75rem;">1</span>
                    <a href="products/list-products.php" style="color:#1e40af; font-weight:600; text-decoration:none;">تعديل المنتج</a>
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="#93c5fd" stroke-width="2" width="18" height="18"><path d="M9 5l7 7-7 7"/></svg>
                <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:6px 12px; border-radius:8px; border:1px solid #bfdbfe; font-size:0.8rem;">
                    <span style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:0.75rem;">2</span>
                    <span style="color:#1e40af; font-weight:600;">فعّل "تتبع الصلاحية"</span>
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="#93c5fd" stroke-width="2" width="18" height="18"><path d="M9 5l7 7-7 7"/></svg>
                <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:6px 12px; border-radius:8px; border:1px solid #bfdbfe; font-size:0.8rem;">
                    <span style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:0.75rem;">3</span>
                    <a href="purchases/add-purchase.php" style="color:#1e40af; font-weight:600; text-decoration:none;">أدخل التواريخ عند الشراء</a>
                </div>
            </div>
        </div>
        <div style="display:flex; flex-direction:column; gap:6px; align-items:center;">
            <div style="background:#fff; padding:8px 14px; border-radius:8px; border:1px solid #bfdbfe; text-align:center;">
                <div style="font-size:0.68rem; color:#94a3b8; font-weight:600;">منتجات مفعّلة</div>
                <div style="font-size:1.1rem; font-weight:900; color:#1e40af;"><?php echo number_format($expiry_tracked_count); ?></div>
            </div>
            <div style="background:#fff; padding:8px 14px; border-radius:8px; border:1px solid #bfdbfe; text-align:center;">
                <div style="font-size:0.68rem; color:#94a3b8; font-weight:600;">دفعات بتواريخ</div>
                <div style="font-size:1.1rem; font-weight:900; color:<?php echo $expiry_batches_count > 0 ? '#059669' : '#dc2626'; ?>;"><?php echo number_format($expiry_batches_count); ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
