<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
                <line x1="9" y1="14" x2="15" y2="14"/>
            </svg>
            <?php echo t('expiry_report_title'); ?>
        </h1>
        <div class="btn-group">
            <a href="list-products.php" class="btn btn-outline"><?php echo t('back'); ?></a>
            <a href="expiry-report.php?status=all&days=<?php echo $filter_days; ?>" class="btn btn-outline" style="<?php echo $filter_status == 'all' ? 'background:#eff6ff; color:#2563eb; border-color:#2563eb;' : ''; ?>">📋 الكل</a>
            <a href="expiry-report.php?status=expired&days=<?php echo $filter_days; ?>" class="btn btn-outline" style="<?php echo $filter_status == 'expired' ? 'background:#fee2e2; color:#dc2626; border-color:#dc2626;' : 'color:#dc2626; border-color:#fca5a5;'; ?>">🚨 منتهية</a>
            <a href="expiry-report.php?status=expiring&days=<?php echo $filter_days; ?>" class="btn btn-outline" style="<?php echo $filter_status == 'expiring' ? 'background:#fef3c7; color:#d97706; border-color:#d97706;' : 'color:#d97706; border-color:#fcd34d;'; ?>">⚠️ قاربت</a>
            <a href="expiry-report.php?status=valid&days=<?php echo $filter_days; ?>" class="btn btn-outline" style="<?php echo $filter_status == 'valid' ? 'background:#f0fdf4; color:#059669; border-color:#059669;' : 'color:#059669; border-color:#86efac;'; ?>">✅ صالحة</a>
        </div>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:1rem;">
        <!-- منتهية -->
        <div style="background:linear-gradient(135deg, #fef2f2, #fee2e2); border:2px solid #fca5a5; border-radius:12px; padding:16px; text-align:center; <?php echo $filter_status == 'expired' ? 'box-shadow: 0 0 0 3px #dc2626;' : ''; ?>">
            <div style="font-size:2rem;">🚨</div>
            <div style="font-size:1.6rem; font-weight:900; color:#dc2626;"><?php echo number_format($total_expired_count); ?></div>
            <div style="font-size:0.78rem; color:#dc2626; font-weight:600;">منتج منتهي</div>
            <div style="font-size:0.72rem; color:#991b1b; margin-top:2px;">
                <?php echo number_format($total_expired_qty, 1); ?> وحدة — خسارة <?php echo formatMoney($total_expired_value); ?>
            </div>
        </div>

        <!-- قاربت الانتهاء -->
        <div style="background:linear-gradient(135deg, #fffbeb, #fef3c7); border:2px solid #fcd34d; border-radius:12px; padding:16px; text-align:center; <?php echo $filter_status == 'expiring' ? 'box-shadow: 0 0 0 3px #d97706;' : ''; ?>">
            <div style="font-size:2rem;">⚠️</div>
            <div style="font-size:1.6rem; font-weight:900; color:#d97706;"><?php echo number_format($total_expiring_count); ?></div>
            <div style="font-size:0.78rem; color:#d97706; font-weight:600;">قاربت الانتهاء (<?php echo $filter_days; ?> يوم)</div>
            <div style="font-size:0.72rem; color:#92400e; margin-top:2px;">
                <?php echo number_format($total_expiring_qty, 1); ?> وحدة — قيمة <?php echo formatMoney($total_expiring_value); ?>
            </div>
        </div>

        <!-- صالحة -->
        <div style="background:linear-gradient(135deg, #f0fdf4, #dcfce7); border:2px solid #86efac; border-radius:12px; padding:16px; text-align:center; <?php echo $filter_status == 'valid' ? 'box-shadow: 0 0 0 3px #059669;' : ''; ?>">
            <div style="font-size:2rem;">✅</div>
            <div style="font-size:1.6rem; font-weight:900; color:#059669;"><?php echo number_format($total_valid_count); ?></div>
            <div style="font-size:0.78rem; color:#059669; font-weight:600;">منتج صالح</div>
            <div style="font-size:0.72rem; color:#166534; margin-top:2px;">
                <?php echo number_format($total_valid_qty, 1); ?> وحدة — قيمة <?php echo formatMoney($total_valid_value); ?>
            </div>
        </div>

        <!-- إجمالي -->
        <div style="background:linear-gradient(135deg, #f8fafc, #e2e8f0); border:2px solid #cbd5e1; border-radius:12px; padding:16px; text-align:center;">
            <div style="font-size:2rem;">📦</div>
            <div style="font-size:1.6rem; font-weight:900; color:#1e293b;"><?php echo number_format($display_total_batches); ?></div>
            <div style="font-size:0.78rem; color:#475569; font-weight:600;">دفعة في النتائج</div>
            <div style="font-size:0.72rem; color:#64748b; margin-top:2px;">
                <?php echo number_format($display_unique_products); ?> منتج — قيمة <?php echo formatMoney($display_total_value); ?>
            </div>
        </div>
    </div>

    <!-- فلاتر -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><h2>🔍 فلترة الدفعات</h2></div>
        <div class="card-body" style="padding:12px 16px;">
            <form method="GET" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <select name="status" class="form-control" style="max-width:150px; padding:6px 10px; font-size:0.85rem;">
                    <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>الكل (ضمن التنبيه)</option>
                    <option value="expired" <?php echo $filter_status == 'expired' ? 'selected' : ''; ?>>🚨 منتهية فقط</option>
                    <option value="expiring" <?php echo $filter_status == 'expiring' ? 'selected' : ''; ?>>⚠️ قاربت الانتهاء</option>
                    <option value="valid" <?php echo $filter_status == 'valid' ? 'selected' : ''; ?>>✅ صالحة فقط</option>
                </select>

                <div style="display:flex; align-items:center; gap:6px;">
                    <label style="font-size:0.82rem; color:var(--gray-500); white-space:nowrap;">خلال:</label>
                    <input type="number" name="days" class="form-control" value="<?php echo $filter_days; ?>" min="1" max="365" style="max-width:80px; padding:6px 10px; font-size:0.85rem;">
                    <span style="font-size:0.82rem; color:var(--gray-400);">يوم</span>
                </div>

                <?php if (!empty($categories)): ?>
                <select name="category" class="form-control" style="max-width:150px; padding:6px 10px; font-size:0.85rem;">
                    <option value="0">كل التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="<?php echo t('expiry_search_product'); ?>" style="max-width:180px; padding:6px 10px; font-size:0.85rem;">

                <button type="submit" class="btn btn-primary" style="padding:6px 16px; font-size:0.85rem;"><?php echo t('update'); ?></button>
                <a href="expiry-report.php" class="btn btn-outline" style="padding:6px 12px; font-size:0.82rem;">مسح الفلاتر</a>
            </form>
        </div>
    </div>

    <!-- جدول النتائج -->
    <div class="card">
        <div class="card-header">
            <h2>
                <?php if ($filter_status == 'expired'): ?>
                    🚨 الدفعات المنتهية الصلاحية
                <?php elseif ($filter_status == 'expiring'): ?>
                    ⚠️ الدفعات القاربة على الانتهاء
                <?php elseif ($filter_status == 'valid'): ?>
                    ✅ الدفعات الصالحة
                <?php else: ?>
                    📋 جميع الدفعات (ضمن نطاق التنبيه)
                <?php endif; ?>
                <span style="font-size:0.8rem; font-weight:400; color:#94a3b8;">(<?php echo number_format($display_total_batches); ?> دفعة)</span>
            </h2>
        </div>
        <div class="card-body">
            <?php if (empty($batches)): ?>
                <div style="text-align:center; padding:3rem; color:var(--gray-400);">
                    <div style="font-size:3rem; margin-bottom:12px;">✅</div>
                    <div style="font-size:1.1rem; font-weight:600; color:var(--gray-600);">لا توجد دفعات تطابق الفلتر المحدد</div>
                    <div style="font-size:0.85rem; margin-top:8px;">جميع المنتجات ذات الصلاحية في حالة جيدة</div>
                </div>
            <?php else: ?>
            <div class="table-responsive" style="max-height:70vh; overflow-y:auto;">
                <table>
                    <thead style="position:sticky; top:0; z-index:2;">
                        <tr style="background:var(--gray-800); color:#fff;">
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>التصنيف</th>
                            <th>تاريخ الشراء</th>
                            <th>تاريخ الانتهاء</th>
                            <th>الأيام المتبقية</th>
                            <th>الكمية المتبقية</th>
                            <th>سعر الشراء</th>
                            <th>قيمة الدفعة</th>
                            <th>الحالة</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 1;
                        foreach ($batches as $b):
                            $remaining = floatval($b['remaining_quantity']);
                            $status = $b['status'] ?? 'valid';
                            $days_left = intval($b['days_left'] ?? 0);

                            // لون الصف حسب الحالة
                            $row_bg = '#fff';
                            if ($status === 'expired') $row_bg = '#fef2f2';
                            elseif ($status === 'critical') $row_bg = '#fef2f2';
                            elseif ($status === 'warning') $row_bg = '#fffbeb';
                            elseif ($status === 'expiring') $row_bg = '#fffbeb';
                            elseif ($status === 'valid') $row_bg = '#f0fdf4';

                            // شارة الحالة
                            $status_badge = '';
                            if ($status === 'expired') {
                                $status_badge = '<span style="background:#dc2626; color:#fff; padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; white-space:nowrap;">🚨 منتهي</span>';
                            } elseif ($status === 'critical') {
                                $status_badge = '<span style="background:#dc2626; color:#fff; padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; white-space:nowrap;">🔴 حرج</span>';
                            } elseif ($status === 'warning') {
                                $status_badge = '<span style="background:#d97706; color:#fff; padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; white-space:nowrap;">⚠️ تحذير</span>';
                            } elseif ($status === 'expiring') {
                                $status_badge = '<span style="background:#ca8a04; color:#fff; padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; white-space:nowrap;">🟡 قارب</span>';
                            } else {
                                $status_badge = '<span style="background:#059669; color:#fff; padding:3px 10px; border-radius:6px; font-size:0.75rem; font-weight:700; white-space:nowrap;">✅ صالح</span>';
                            }

                            // لون الأيام المتبقية
                            $days_color = '#059669';
                            $days_text = $days_left . ' يوم';
                            if ($days_left < 0) { $days_color = '#dc2626'; $days_text = 'منتهي منذ ' . abs($days_left) . ' يوم'; }
                            elseif ($days_left == 0) { $days_color = '#dc2626'; $days_text = 'ينتهي اليوم!'; }
                            elseif ($days_left <= 7) { $days_color = '#dc2626'; $days_text = '🔴 ' . $days_left . ' يوم'; }
                            elseif ($days_left <= 14) { $days_color = '#d97706'; $days_text = '⚠️ ' . $days_left . ' يوم'; }
                            elseif ($days_left <= 30) { $days_color = '#ca8a04'; $days_text = '🟡 ' . $days_left . ' يوم'; }
                        ?>
                        <tr style="background:<?php echo $row_bg; ?>; border-bottom:1px solid #e2e8f0;">
                            <td style="color:#94a3b8; font-size:0.8rem;"><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($b['code']); ?></strong></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($b['name']); ?></td>
                            <td style="font-size:0.82rem; color:#64748b;"><?php echo htmlspecialchars($b['category_name'] ?? '—'); ?></td>
                            <td style="font-size:0.82rem; direction:ltr;"><?php echo $b['purchase_date']; ?></td>
                            <td>
                                <span style="font-weight:700; color:<?php echo $days_color; ?>; background:<?php echo $status === 'expired' || $status === 'critical' ? '#fee2e2' : ($status === 'warning' || $status === 'expiring' ? '#fef3c7' : '#f0fdf4'); ?>; padding:2px 8px; border-radius:4px; font-size:0.82rem;">
                                    <?php echo $b['expiry_date']; ?>
                                </span>
                            </td>
                            <td style="font-weight:800; color:<?php echo $days_color; ?>; font-size:0.85rem;"><?php echo $days_text; ?></td>
                            <td style="font-weight:700;"><?php echo number_format($remaining, 2); ?> <?php echo htmlspecialchars($b['unit']); ?></td>
                            <td style="font-size:0.85rem;"><?php echo formatMoney($b['purchase_price']); ?></td>
                            <td style="font-weight:700; color:<?php echo ($status === 'expired' || $status === 'critical') ? '#dc2626' : '#1e293b'; ?>;">
                                <?php echo formatMoney($b['batch_value']); ?>
                            </td>
                            <td><?php echo $status_badge; ?></td>
                            <td>
                                <a href="view-product.php?id=<?php echo $b['product_id']; ?>" class="btn btn-sm btn-outline" style="padding:4px 10px; font-size:0.78rem;">عرض المنتج</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--gray-100); font-weight:800;">
                            <td colspan="6">الإجمالي</td>
                            <td>—</td>
                            <td><?php echo number_format($display_total_qty, 2); ?> وحدة</td>
                            <td>—</td>
                            <td style="color:<?php echo $total_expired_value > 0 ? '#dc2626' : '#1e293b'; ?>;"><?php echo formatMoney($display_total_value); ?></td>
                            <td colspan="2">
                                <?php echo number_format($display_unique_products); ?> منتج |
                                <?php echo number_format($display_total_batches); ?> دفعة
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>