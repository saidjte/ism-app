<?php if ($print_mode): ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('products_price_list'); ?></title>
    <style>
    @import url('../assets/fonts/cairo.css');

    @page {
        size: A4 portrait;
        margin: 8mm;
    }

    /* فقط عند الطباعة الفعلية نخفي عناصر التطبيق */
    @media print {
        /* فرض طباعة الألوان كما تظهر على الشاشة */
        *, *::before, *::after {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }

        .no-print, .sidebar, .main-wrapper > .top-navbar, .mobile-bottom-nav {
            display: none !important;
        }
        .main-wrapper {
            margin-right: 0 !important;
            padding: 0 !important;
        }
        .main-wrapper > .content-wrapper {
            padding: 0 !important;
            margin: 0 !important;
        }
        body { margin: 0; padding: 0; background: #fff; }
        .price-list-print-area { 
            width: 100% !important; 
            min-height: auto !important; 
            padding: 4mm !important; 
            box-shadow: none !important;
            border-radius: 0 !important;
            margin: 0 !important;
        }
    }

    .price-list-print-area {
        direction: rtl;
        font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
        color: #2c3e50;
        background: #fff;
        max-width: 210mm;
        min-height: 280mm;
        margin: 0 auto;
        padding: 6mm 7mm;
        box-sizing: border-box;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
    }

    /* خلفية المنطقة المحيطة بالمعاينة */
    .container > .no-print + .price-list-print-area,
    .container > div:last-child {
        margin-top: 0;
    }

    /* === الهيدر === */
    .pl-header {
        text-align: center;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 3px double #cbd5e1;
        position: relative;
    }
    .pl-header::before,
    .pl-header::after {
        content: '';
        position: absolute;
        bottom: -3px;
        height: 3px;
        width: 30%;
        background: #dc2626;
    }
    .pl-header::before { right: 0; }
    .pl-header::after { left: 0; }

    .pl-store-name {
        font-size: 2.2rem;
        font-weight: 900;
        color: #1e293b;
        margin: 0;
        letter-spacing: 1px;
    }
    .pl-title-bar {
        display: inline-block;
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: #fff;
        padding: 6px 36px;
        border-radius: 20px;
        font-size: 1.4rem;
        font-weight: 800;
        margin: 8px 0 6px;
        letter-spacing: 2px;
        box-shadow: 0 2px 8px rgba(220,38,38,0.25);
    }
    .pl-contact-info {
        display: flex;
        justify-content: center;
        gap: 18px;
        font-size: 1rem;
        color: #64748b;
        flex-wrap: wrap;
    }
    .pl-contact-info span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* === عنوان التصنيف === */
    .pl-cat-banner {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        color: #1e3a5f;
        padding: 10px 16px;
        border-radius: 8px;
        margin: 14px 0 0;
        font-size: 1.3rem;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
        page-break-after: avoid;
        border-right: 5px solid #3b82f6;
        border-bottom: 2px solid #93c5fd;
    }
    .pl-cat-banner:first-child { margin-top: 4px; }
    .pl-cat-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .pl-cat-count {
        margin-right: auto;
        font-size: 0.9rem;
        font-weight: 600;
        color: #1e40af;
        background: #fff;
        padding: 2px 12px;
        border-radius: 10px;
    }

    /* === شبكة المنتجات (بطاقات) === */
    .pl-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 5px;
    }
    .pl-card {
        border: 1.5px solid #dbeafe;
        border-radius: 6px;
        padding: 7px 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 6px;
        background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        page-break-inside: avoid;
        box-sizing: border-box;
        width: 100%;
        overflow: hidden;
    }
    .pl-card-info {
        flex: 1;
        min-width: 0;
        overflow: hidden;
    }
    .pl-card-name {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
    }
    .pl-card-code {
        font-family: 'Courier New', monospace;
        font-size: 0.72rem;
        color: #94a3b8;
        direction: ltr;
        display: inline-block;
        margin-top: 1px;
    }
    .pl-card-price {
        flex-shrink: 0;
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: #fff;
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 1rem;
        font-weight: 900;
        text-align: center;
        white-space: nowrap;
        box-shadow: 0 1px 4px rgba(220,38,38,0.3);
    }
    .pl-card-price.currency-right {
        direction: rtl;
    }
    .pl-card-price.currency-left {
        direction: ltr;
    }

    /* === نمط الجدول === */
    .pl-list-table {
        width: 100%;
        border-collapse: collapse;
    }
    .pl-list-table thead {
        display: none;
    }
    .pl-list-table tbody td {
        padding: 10px 14px;
        border-bottom: 1.5px solid #94a3b8;
        font-size: 1.05rem;
    }
    .pl-list-table tbody tr:last-child td {
        border-bottom: none;
    }
    .pl-list-table tbody tr:nth-child(even) { background: #f0f9ff; }
    .pl-list-table .price-cell {
        font-weight: 900;
        color: #dc2626;
        font-size: 1.2rem;
        text-align: center;
        white-space: nowrap;
        background: inherit;
    }
    .pl-list-table .price-cell.currency-right {
        direction: rtl;
    }
    .pl-list-table .price-cell.currency-left {
        direction: ltr;
    }
    .pl-list-table .name-cell {
        font-weight: 700;
    }
    .pl-list-table .code-cell {
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        color: #94a3b8;
        direction: ltr;
    }

    /* === التذييل === */
    .pl-footer {
        margin-top: 14px;
        padding-top: 8px;
        border-top: 2px dashed #d1d5db;
        text-align: center;
        font-size: 0.85rem;
        color: #94a3b8;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 4px;
    }

    .pl-section-break {
        page-break-inside: avoid;
    }

    /* === فصل الصفحات عند طلب تصنيف لكل صفحة === */
    .pl-page-separator {
        page-break-before: always;
    }
    .pl-page-separator .pl-header {
        border-top: none;
    }

    /* === ميني هيدر للصفحات المكررة === */
    .pl-mini-header {
        text-align: center;
        margin-bottom: 8px;
        padding-bottom: 6px;
        border-bottom: 2px solid #e2e8f0;
        position: relative;
    }
    .pl-mini-header .pl-mini-store-name {
        font-size: 1.1rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0;
    }
    .pl-mini-header .pl-mini-title {
        display: inline-block;
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: #fff;
        padding: 2px 18px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 700;
        margin-top: 4px;
        box-shadow: 0 1px 4px rgba(220,38,38,0.25);
    }
    .pl-mini-header .pl-mini-contact {
        display: flex;
        justify-content: center;
        gap: 14px;
        font-size: 0.75rem;
        color: #94a3b8;
        margin-top: 3px;
        flex-wrap: wrap;
    }

    /* === وحدة القياس بجانب السعر === */
    .pl-card-price .pl-unit-before {
        font-size: 0.65rem;
        font-weight: 400;
        opacity: 0.85;
    }
    .pl-card-price .pl-unit-after {
        font-size: 0.65rem;
        font-weight: 400;
        opacity: 0.85;
    }
    .pl-card-price .pl-unit-below {
        font-size: 0.6rem;
        font-weight: 400;
        opacity: 0.75;
        margin-top: -1px;
        line-height: 1.1;
    }
    .pl-card-price .pl-unit-above {
        font-size: 0.6rem;
        font-weight: 400;
        opacity: 0.75;
        margin-bottom: -1px;
        line-height: 1.1;
    }
    /* عندما تكون الوحدة فوق أو تحت، نحتاج عرض block */
    .pl-card-price.has-unit-block {
        display: flex;
        flex-direction: column;
        justify-content: center;
        line-height: 1.2;
        padding: 4px 10px;
    }

    /* === وحدات القياس الإضافية === */
    .pl-additional-price {
        font-size: 0.7rem;
        font-weight: 600;
        color: #fff;
        opacity: 0.9;
        border-top: 1px solid rgba(255,255,255,0.3);
        margin-top: 2px;
        padding-top: 2px;
        line-height: 1.2;
    }
    .pl-additional-unit {
        font-size: 0.6rem;
        font-weight: 400;
        opacity: 0.7;
    }
    .pl-additional-more {
        font-size: 0.6rem;
        text-align: center;
        opacity: 0.6;
        margin-top: 1px;
    }

    /* === وحدات إضافية خارج الشارة (فوق/تحت في وضع البطاقات) === */
    .pl-card-au-external {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        width: 100%;
        padding: 3px 0;
        justify-content: center;
    }
    .pl-card-au-item {
        font-size: 0.65rem;
        font-weight: 700;
        color: #2563eb;
        background: rgba(37, 99, 235, 0.08);
        padding: 1px 6px;
        border-radius: 4px;
        white-space: nowrap;
    }
    .pl-card-au-name {
        font-weight: 400;
        font-size: 0.58rem;
        color: #64748b;
        margin-right: 1px;
    }
    .pl-card-au-more {
        font-size: 0.58rem;
        color: #94a3b8;
        opacity: 0.7;
    </style>
</head>
<body style="margin:0; padding:0; background:#fff;">
<div class="no-print">
    <!-- شريط إجراءات المعاينة -->
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:1rem; padding:12px 16px; background:linear-gradient(135deg, #f8fafc, #f1f5f9); border:1.5px solid #e2e8f0; border-radius:10px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" width="22" height="22"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span style="font-size:0.95rem; font-weight:700; color:#1e293b;"><?php echo t('products_price_list'); ?></span>
            <span style="font-size:0.78rem; color:#64748b; background:#e2e8f0; padding:2px 10px; border-radius:12px;"><?php echo $total_products; ?> منتج</span>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button onclick="window.print()" style="background:linear-gradient(135deg, #dc2626, #b91c1c); color:#fff; border:none; padding:10px 24px; border-radius:8px; cursor:pointer; font-family:inherit; font-size:0.9rem; font-weight:700; display:flex; align-items:center; gap:6px; box-shadow:0 2px 8px rgba(220,38,38,0.3); transition:all 0.15s;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                طباعة اللائحة
            </button>
            <a href="price-list.php" style="background:#fff; color:#475569; border:1.5px solid #e2e8f0; padding:10px 24px; border-radius:8px; text-decoration:none; font-family:inherit; font-size:0.9rem; font-weight:700; display:flex; align-items:center; gap:6px; transition:all 0.15s;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                تعديل الإعدادات
            </a>
        </div>
    </div>
</div>

<div class="price-list-print-area">
    <?php if (empty($products)): ?>
    <!-- الهيدر -->
    <div class="pl-header">
        <?php if ($store_name): ?>
            <h1 class="pl-store-name"><?php echo htmlspecialchars($store_name); ?></h1>
        <?php else: ?>
            <h1 class="pl-store-name">لائحة الأسعار</h1>
        <?php endif; ?>
        <div class="pl-title-bar">لائحة الأسعار</div>
    </div>
    <p style="text-align:center; color:#94a3b8; padding:4rem 0; font-size:1.1rem;">لا توجد منتجات لعرضها</p>
    <div class="pl-footer">
        <span>لا توجد منتجات</span>
        <span>تاريخ التحديث: <?php echo date('Y/m/d'); ?></span>
    </div>

    <?php else: ?>
        <?php
    // دالة لإنشاء الهيدر
    function renderHeader($store_name, $store_phone, $store_address) {
        ob_start();
    ?>
        <div class="pl-header">
            <?php if ($store_name): ?>
                <h1 class="pl-store-name"><?php echo htmlspecialchars($store_name); ?></h1>
            <?php else: ?>
                <h1 class="pl-store-name">لائحة الأسعار</h1>
            <?php endif; ?>
            <div class="pl-title-bar">لائحة الأسعار</div>
            <?php if ($store_phone || $store_address): ?>
                <div class="pl-contact-info">
                    <?php if ($store_phone): ?>
                        <span>📞 <?php echo htmlspecialchars($store_phone); ?></span>
                    <?php endif; ?>
                    <?php if ($store_address): ?>
                        <span>📍 <?php echo htmlspecialchars($store_address); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php
        return ob_get_clean();
    }

    // دالة لإنشاء ميني هيدر للصفحات المكررة
    function renderMiniHeader($store_name, $store_phone, $store_address) {
        ob_start();
    ?>
        <div class="pl-mini-header">
            <?php if ($store_name): ?>
                <div class="pl-mini-store-name"><?php echo htmlspecialchars($store_name); ?></div>
            <?php else: ?>
                <div class="pl-mini-store-name">لائحة الأسعار</div>
            <?php endif; ?>
            <div class="pl-mini-title">لائحة الأسعار</div>
            <?php if ($store_phone || $store_address): ?>
                <div class="pl-mini-contact">
                    <?php if ($store_phone): ?>
                        <span>📞 <?php echo htmlspecialchars($store_phone); ?></span>
                    <?php endif; ?>
                    <?php if ($store_address): ?>
                        <span>📍 <?php echo htmlspecialchars($store_address); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php
        return ob_get_clean();
    }

    // دالة لإنشاء قسم التصنيف
    function renderCategorySection($catName, $catData, $layout, $show_code, $currency_position, $currency, $show_unit, $unit_position, $show_additional_units, $additional_units_count, $au_position) {
        ob_start();
    ?>
        <div class="pl-section-break">
            <div class="pl-cat-banner" style="border-right-color:<?php echo htmlspecialchars($catData['color']); ?>">
                <span class="pl-cat-dot" style="background:<?php echo htmlspecialchars($catData['color']); ?>"></span>
                <?php echo htmlspecialchars($catName); ?>
                <span class="pl-cat-count"><?php echo count($catData['products']); ?></span>
            </div>

            <?php if ($layout === 'list'): ?>
            <?php
            // حساب عرض عمود السعر ديناميكياً حسب عدد الوحدات الإضافية
            $price_col_width = 150;
            if ($show_additional_units) {
                if ($au_position === 'above' || $au_position === 'below') {
                    // الوضع العمودي: الوحدات تظهر فوق/تحت السعر، تحتاج عرض أقل
                    $price_col_width = 170 + ($additional_units_count * 10);
                } else {
                    // الوضع الأفقي (قبل/بعد): الوحدات بجانب السعر، تحتاج عرض أكثر
                    $price_col_width = 150 + ($additional_units_count * 42);
                }
                $price_col_width = min($price_col_width, 480);
            }
            ?>
            <table class="pl-list-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <?php if ($show_code): ?>
                        <th style="width:100px;">الكود</th>
                        <?php endif; ?>
                        <th>اسم المنتج</th>
                        <th style="width:<?php echo $price_col_width; ?>px; text-align:center;">السعر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catData['products'] as $i => $p): ?>
                    <tr>
                        <td style="color:#94a3b8; font-size:0.9rem; text-align:center;"><?php echo $i + 1; ?></td>
                        <?php if ($show_code): ?>
                        <td class="code-cell"><?php echo htmlspecialchars($p['code']); ?></td>
                        <?php endif; ?>
                        <td class="name-cell"><?php echo htmlspecialchars($p['name']); ?></td>
                        <td class="price-cell currency-<?php echo $currency_position; ?>"<?php echo $show_additional_units ? ' style="white-space:normal;"' : ''; ?>><?php
                            // بناء السعر الأساسي
                            $price_str = ($currency_position === 'left' ? htmlspecialchars($currency) . ' ' : '') . number_format($p['selling_price'], 2) . ($currency_position === 'right' ? ' ' . htmlspecialchars($currency) : '');
                            if ($show_unit && !empty($p['unit'])) {
                                $price_str .= ' <span style="font-size:0.75rem; font-weight:400; color:#64748b;">/ ' . htmlspecialchars($p['unit']) . '</span>';
                            }

                            // بناء وحدات إضافية
                            $au_html = '';
                            $au_inline = '';
                            if ($show_additional_units && !empty($p['_additional_units'])) {
                                $au_display = array_slice($p['_additional_units'], 0, $additional_units_count);
                                $au_parts = [];
                                foreach ($au_display as $au) {
                                    $au_price = ($currency_position === 'left' ? htmlspecialchars($currency) . ' ' : '') . number_format($au['selling_price'], 2) . ($currency_position === 'right' ? ' ' . htmlspecialchars($currency) : '');
                                    $au_parts[] = '<span style="font-size:0.8rem; font-weight:700; color:#2563eb;">' . $au_price . '</span><span style="font-size:0.68rem; color:#64748b;">/' . htmlspecialchars($au['unit_name']) . '</span>';
                                }
                                $remaining = count($p['_additional_units']) - $additional_units_count;
                                if ($remaining > 0) {
                                    $au_parts[] = '<span style="font-size:0.65rem; color:#94a3b8;">+' . $remaining . '</span>';
                                }
                                // نسخة مع فواصل بين الأسطر (للوضع العمودي)
                                $au_html = implode('<br>', $au_parts);
                                // نسخة مع فواصل على نفس السطر (للوضع الأفقي)
                                $au_inline = implode(' <span style="color:#cbd5e1; margin:0 2px;">•</span> ', $au_parts);
                            }

                            // عرض حسب المكان المختار للوحدات الإضافية
                            if ($au_html) {
                                if ($au_position === 'before') {
                                    // قبل السعر = الوحدات الإضافية على نفس السطر ثم السعر
                                    echo '<div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px; direction:rtl;">' . $au_inline . '<span style="color:#cbd5e1; margin:0 2px;">|</span><span style="font-size:1.05rem; font-weight:900; color:#dc2626;">' . $price_str . '</span></div>';
                                } elseif ($au_position === 'after') {
                                    // بعد السعر = السعر ثم الوحدات الإضافية على نفس السطر
                                    echo '<div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px; direction:rtl;">' . $price_str . '<span style="color:#cbd5e1; margin:0 2px;">|</span>' . $au_inline . '</div>';
                                } elseif ($au_position === 'below') {
                                    // تحت السعر = السعر بخط كبير ثم الوحدات بخط صغير تحته
                                    echo '<div style="line-height:1.6;">' . $price_str . '<div style="border-top:1px dashed #94a3b8; margin-top:3px; padding-top:3px;">' . $au_html . '</div></div>';
                                } elseif ($au_position === 'above') {
                                    // فوق السعر = الوحدات بخط صغير فوق ثم السعر بخط كبير
                                    echo '<div style="line-height:1.6;"><div style="border-bottom:1px dashed #94a3b8; margin-bottom:3px; padding-bottom:3px;">' . $au_html . '</div>' . $price_str . '</div>';
                                } else {
                                    echo $price_str . '<br>' . $au_html;
                                }
                            } else {
                                echo $price_str;
                            }
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="pl-grid">
                <?php foreach ($catData['products'] as $p): ?>
                <div class="pl-card" style="<?php echo ($au_position === 'above' || $au_position === 'below') && $has_au ? 'flex-wrap:wrap; gap:4px;' : ''; ?>">
                    <div class="pl-card-info">
                        <div class="pl-card-name" title="<?php echo htmlspecialchars($p['name']); ?>"><?php echo htmlspecialchars($p['name']); ?></div>
                        <?php if ($show_code): ?>
                            <div class="pl-card-code"><?php echo htmlspecialchars($p['code']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php
                        $card_price = ($currency_position === 'left' ? '<span style="font-size:0.75rem; font-weight:600;">' . htmlspecialchars($currency) . '</span> ' : '') . number_format($p['selling_price'], 2) . ($currency_position === 'right' ? ' <span style="font-size:0.75rem; font-weight:600;">' . htmlspecialchars($currency) . '</span>' : '');
                        $unit_html = ($show_unit && !empty($p['unit'])) ? htmlspecialchars($p['unit']) : '';
                        $has_au = ($show_additional_units && !empty($p['_additional_units']));
                    ?>
                    <?php if ($has_au && $au_position === 'above'): ?>
                    <!-- وحدات إضافية فوق البطاقة -->
                    <div class="pl-card-au-external">
                        <?php
                            $au_display = array_slice($p['_additional_units'], 0, $additional_units_count);
                            foreach ($au_display as $au) {
                                echo '<span class="pl-card-au-item">' . number_format($au['selling_price'], 2) . ' <span class="pl-card-au-name">' . htmlspecialchars($au['unit_name']) . '</span></span>';
                            }
                            $remaining = count($p['_additional_units']) - $additional_units_count;
                            if ($remaining > 0) {
                                echo '<span class="pl-card-au-more">+' . $remaining . '</span>';
                            }
                        ?>
                    </div>
                    <?php endif; ?>
                    <div class="pl-card-price currency-<?php echo $currency_position; ?><?php echo ($has_au && ($au_position === 'above' || $au_position === 'below' || $au_position === 'before' || $au_position === 'after')) ? ' has-unit-block' : ''; ?>"><?php
                        // وحدات إضافية قبل السعر داخل الشارة
                        if ($has_au && $au_position === 'before') {
                            $au_display = array_slice($p['_additional_units'], 0, $additional_units_count);
                            foreach ($au_display as $au) {
                                $au_price = number_format($au['selling_price'], 2);
                                echo '<div class="pl-additional-price"><span class="pl-additional-unit">' . htmlspecialchars($au['unit_name']) . '</span> ' . $au_price . '</div>';
                            }
                            $remaining = count($p['_additional_units']) - $additional_units_count;
                            if ($remaining > 0) {
                                echo '<div class="pl-additional-more">+' . $remaining . '</div>';
                            }
                            echo '<div style="height:1px; background:rgba(255,255,255,0.25); margin:2px 0;"></div>';
                        }
                        if ($unit_html) {
                            if ($unit_position === 'before') {
                                echo '<span class="pl-unit-before">' . $unit_html . ' /</span> ' . $card_price;
                            } elseif ($unit_position === 'after') {
                                echo $card_price . ' <span class="pl-unit-after">/ ' . $unit_html . '</span>';
                            } elseif ($unit_position === 'below') {
                                echo $card_price . '<div class="pl-unit-below">' . $unit_html . '</div>';
                            } elseif ($unit_position === 'above') {
                                echo '<div class="pl-unit-above">' . $unit_html . '</div>' . $card_price;
                            } else {
                                echo $card_price . ' <span class="pl-unit-after">/ ' . $unit_html . '</span>';
                            }
                        } else {
                            echo $card_price;
                        }
                        // وحدات إضافية بعد السعر داخل الشارة
                        if ($has_au && $au_position === 'after') {
                            echo '<div style="height:1px; background:rgba(255,255,255,0.25); margin:2px 0;"></div>';
                            $au_display = array_slice($p['_additional_units'], 0, $additional_units_count);
                            foreach ($au_display as $au) {
                                $au_price = number_format($au['selling_price'], 2);
                                echo '<div class="pl-additional-price"><span class="pl-additional-unit">' . htmlspecialchars($au['unit_name']) . '</span> ' . $au_price . '</div>';
                            }
                            $remaining = count($p['_additional_units']) - $additional_units_count;
                            if ($remaining > 0) {
                                echo '<div class="pl-additional-more">+' . $remaining . '</div>';
                            }
                        }
                    ?></div>
                    <?php if ($has_au && $au_position === 'below'): ?>
                    <!-- وحدات إضافية تحت البطاقة -->
                    <div class="pl-card-au-external">
                        <?php
                            $au_display = array_slice($p['_additional_units'], 0, $additional_units_count);
                            foreach ($au_display as $au) {
                                echo '<span class="pl-card-au-item">' . number_format($au['selling_price'], 2) . ' <span class="pl-card-au-name">' . htmlspecialchars($au['unit_name']) . '</span></span>';
                            }
                            $remaining = count($p['_additional_units']) - $additional_units_count;
                            if ($remaining > 0) {
                                echo '<span class="pl-card-au-more">+' . $remaining . '</span>';
                            }
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php
        return ob_get_clean();
    }

        $catIndex = 0;
    foreach ($grouped as $catName => $catData):
        $catIndex++;
        $isFirst = ($catIndex === 1);
    ?>
        <?php if ($use_page_break && !$isFirst): ?>
        <!-- بداية صفحة جديدة لكل تصنيف -->
        <div class="pl-page-separator">
            <?php echo renderMiniHeader($store_name, $store_phone, $store_address); ?>
            <?php echo renderCategorySection($catName, $catData, $layout, $show_code, $currency_position, $currency, $show_unit, $unit_position, $show_additional_units, $additional_units_count, $au_position); ?>
        </div>
        <?php else: ?>
        <?php if ($isFirst): ?>
            <!-- الهيدر الرئيسي -->
            <?php echo renderHeader($store_name, $store_phone, $store_address); ?>
        <?php endif; ?>
            <?php echo renderCategorySection($catName, $catData, $layout, $show_code, $currency_position, $currency, $show_unit, $unit_position, $show_additional_units, $additional_units_count, $au_position); ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- التذييل -->
    <div class="pl-footer">
        <span><?php echo $total_products; ?> منتج في <?php echo $total_categories; ?> تصنيف</span>
        <span>تاريخ التحديث: <?php echo date('Y/m/d'); ?></span>
    </div>
    <?php endif; ?>
</div>

</body>
</html>
<?php else: ?>
<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                <path d="M12 11v6m-3-3h6" stroke-dasharray="2 2"/>
            </svg>
            <?php echo t('products_price_list'); ?>
            <span style="font-size:0.75rem; font-weight:400; color:#94a3b8; margin-right:8px;">للطباعة وعرضها في المحل</span>
        </h1>
    </div>

    <form method="POST" action="price-list.php">
        <?php echo csrfField(); ?>

        <!-- إحصائيات سريعة -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:1.5rem;">
            <div style="background:linear-gradient(135deg, #fef2f2, #fecaca); border:1px solid #fca5a5; border-radius:12px; padding:14px; text-align:center;">
                <div style="font-size:1.8rem; font-weight:900; color:#c0392b;"><?php echo $total_products; ?></div>
                <div style="font-size:0.8rem; color:#dc2626; font-weight:600;">منتج جاهز للطباعة</div>
            </div>
            <div style="background:linear-gradient(135deg, #f0fdf4, #dcfce7); border:1px solid #bbf7d0; border-radius:12px; padding:14px; text-align:center;">
                <div style="font-size:1.8rem; font-weight:900; color:#16a34a;"><?php echo $total_categories; ?></div>
                <div style="font-size:0.8rem; color:#22c55e; font-weight:600;">تصنيف</div>
            </div>
            <div style="background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #bfdbfe; border-radius:12px; padding:14px; text-align:center;">
                <div style="font-size:1.8rem; font-weight:900; color:#2563eb;" id="pageCountDisplay">A4</div>
                <div style="font-size:0.8rem; color:#3b82f6; font-weight:600;" id="pageCountLabel">حجم الورقة</div>
            </div>
        </div>

        <!-- معلومات المتجر -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    معلومات المتجر
                </h2>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">اسم المتجر *</label>
                        <input type="text" name="store_name" class="form-control" placeholder="مثال: متجر النور" value="<?php echo htmlspecialchars($store_name); ?>">
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">رقم الهاتف</label>
                        <input type="text" name="store_phone" class="form-control" placeholder="06XXXXXXXX" value="<?php echo htmlspecialchars($store_phone); ?>" style="direction:ltr; text-align:right;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.85rem;">العنوان</label>
                        <input type="text" name="store_address" class="form-control" placeholder="المدينة، الحي..." value="<?php echo htmlspecialchars($store_address); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- إعدادات اللائحة -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                    إعدادات اللائحة
                </h2>
            </div>
            <div class="card-body">
                <!-- التصنيفات -->
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-weight:600; margin-bottom:4px; font-size:0.9rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M4 7h16M4 12h16M4 17h10"/><circle cx="18" cy="17" r="2"/></svg>
                        التصنيفات المطلوبة
                    </label>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:6px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; background:#fef2f2; padding:8px 14px; border-radius:8px; border:1.5px solid #fca5a5; font-size:0.85rem; font-weight:600; color:#c0392b;">
                            <input type="checkbox" id="selectAllCats" onchange="toggleAllCategories(this.checked)" <?php echo empty($selected_categories) ? 'checked' : ''; ?>>
                            تحديد الكل
                        </label>
                        <?php foreach ($categories as $cat): ?>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border-radius:8px; border:1.5px solid #e2e8f0; font-size:0.85rem; font-weight:500; transition:all 0.15s;"
                               onmouseover="this.style.borderColor='<?php echo htmlspecialchars($cat['color'] ?? '#c0392b'); ?>'"
                               onmouseout="if(!this.querySelector('input').checked) this.style.borderColor='#e2e8f0'">
                            <input type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" class="category-checkbox"
                                   onchange="updateSelectAll()"
                                   <?php echo (empty($selected_categories) || in_array($cat['id'], $selected_categories)) ? 'checked' : ''; ?>>
                            <span style="width:8px; height:8px; border-radius:50%; background:<?php echo htmlspecialchars($cat['color'] ?? '#64748b'); ?>; display:inline-block;"></span>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- توزيع التصنيفات على الصفحات -->
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-weight:600; margin-bottom:8px; font-size:0.9rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        توزيع التصنيفات على الصفحات
                    </label>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <label style="flex:1; display:flex; align-items:center; gap:10px; padding:14px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s; min-width:250px;" id="modeOneLabel">
                            <input type="radio" name="categories_mode" value="one" <?php echo $categories_mode === 'one' ? 'checked' : ''; ?> onchange="updateModeStyle()" style="accent-color:#c0392b;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">📄 كل التصنيفات في صفحة واحدة</div>
                                <div style="font-size:0.72rem; color:#94a3b8;">تجمع كل التصنيفات المختارة في ورقة طباعة واحدة متتالية</div>
                            </div>
                        </label>
                        <label style="flex:1; display:flex; align-items:center; gap:10px; padding:14px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s; min-width:250px;" id="modeSepLabel">
                            <input type="radio" name="categories_mode" value="separate" <?php echo $categories_mode === 'separate' ? 'checked' : ''; ?> onchange="updateModeStyle()" style="accent-color:#c0392b;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">📄📄 تصنيف في كل صفحة</div>
                                <div style="font-size:0.72rem; color:#94a3b8;">كل تصنيف يبدأ في صفحة جديدة مع تكرار الهيدر</div>
                            </div>
                        </label>
                    </div>
                    <!-- عداد التصنيفات المختارة -->
                    <div style="margin-top:8px; padding:8px 12px; background:#f8fafc; border-radius:6px; font-size:0.82rem; color:#64748b; display:flex; align-items:center; gap:6px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span id="selectedCatInfo">سيتم طباعة جميع التصنيفات في صفحة واحدة</span>
                    </div>
                </div>

                <!-- نمط العرض -->
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-weight:600; margin-bottom:8px; font-size:0.9rem;">نمط العرض</label>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <label style="flex:1; display:flex; align-items:center; gap:10px; padding:14px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s; min-width:250px;" id="layoutGridLabel">
                            <input type="radio" name="layout" value="grid" <?php echo $layout === 'grid' ? 'checked' : ''; ?> onchange="updateLayoutStyle()" style="accent-color:#c0392b;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">⬜ بطاقات (شبكة)</div>
                                <div style="font-size:0.72rem; color:#94a3b8;">منتج + سعر في بطاقات — مثالي للعرض</div>
                            </div>
                        </label>
                        <label style="flex:1; display:flex; align-items:center; gap:10px; padding:14px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s; min-width:250px;" id="layoutListLabel">
                            <input type="radio" name="layout" value="list" <?php echo $layout === 'list' ? 'checked' : ''; ?> onchange="updateLayoutStyle()" style="accent-color:#c0392b;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">☰ جدول</div>
                                <div style="font-size:0.72rem; color:#94a3b8;">قائمة مرقمة — مناسب للمنتجات الكثيرة</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- مكان ظهور العملة -->
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-weight:600; margin-bottom:8px; font-size:0.9rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                        مكان ظهور العملة في ورقة الطباعة
                    </label>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <label style="flex:1; display:flex; align-items:center; gap:10px; padding:14px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s; min-width:250px;" id="currencyRightLabel">
                            <input type="radio" name="currency_position" value="right" <?php echo $currency_position === 'right' ? 'checked' : ''; ?> onchange="updateCurrencyStyle()" style="accent-color:#c0392b;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">عملة على اليمين ←</div>
                                <div style="font-size:0.72rem; color:#94a3b8;">السعر ثم العملة: <span style="direction:ltr; display:inline-block;">150.00 <?php echo htmlspecialchars($currency); ?></span></div>
                            </div>
                        </label>
                        <label style="flex:1; display:flex; align-items:center; gap:10px; padding:14px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s; min-width:250px;" id="currencyLeftLabel">
                            <input type="radio" name="currency_position" value="left" <?php echo $currency_position === 'left' ? 'checked' : ''; ?> onchange="updateCurrencyStyle()" style="accent-color:#c0392b;">
                            <div>
                                <div style="font-weight:700; font-size:0.88rem;">→ عملة على اليسار</div>
                                <div style="font-size:0.72rem; color:#94a3b8;">العملة ثم السعر: <span style="direction:ltr; display:inline-block;"><?php echo htmlspecialchars($currency); ?> 150.00</span></div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- وحدة القياس بجانب السعر -->
                <div style="margin-bottom:1rem;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.85rem; font-weight:600; transition:all 0.15s; margin-bottom:10px;" class="option-card" id="showUnitCard">
                        <input type="checkbox" name="show_unit" <?php echo $show_unit ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#c0392b;" onchange="toggleUnitOptions(this.checked)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                        إظهار وحدة القياس بجانب السعر
                    </label>
                    <div id="unitPositionOptions" style="<?php echo $show_unit ? '' : 'display:none;'; ?>">
                        <div style="font-size:0.82rem; font-weight:600; color:#475569; margin-bottom:8px;">مكان ظهور الوحدة بالنسبة للسعر:</div>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="unitAfterLabel" onclick="selectUnitPos('after')">
                                <input type="radio" name="unit_position" value="after" <?php echo $unit_position === 'after' ? 'checked' : ''; ?> style="accent-color:#c0392b;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">← بعد السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8; direction:ltr; text-align:right;">150.00 <?php echo htmlspecialchars($currency); ?> / كغ</div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="unitBeforeLabel" onclick="selectUnitPos('before')">
                                <input type="radio" name="unit_position" value="before" <?php echo $unit_position === 'before' ? 'checked' : ''; ?> style="accent-color:#c0392b;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">قبل السعر →</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">كغ / 150.00 <?php echo htmlspecialchars($currency); ?></div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="unitBelowLabel" onclick="selectUnitPos('below')">
                                <input type="radio" name="unit_position" value="below" <?php echo $unit_position === 'below' ? 'checked' : ''; ?> style="accent-color:#c0392b;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">↓ تحت السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">السعر بخط كبير<br>الوحدة بخط صغير تحته</div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="unitAboveLabel" onclick="selectUnitPos('above')">
                                <input type="radio" name="unit_position" value="above" <?php echo $unit_position === 'above' ? 'checked' : ''; ?> style="accent-color:#c0392b;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">↑ فوق السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">الوحدة بخط صغير<br>السعر بخط كبير تحته</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- خيارات إضافية -->
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.85rem; font-weight:600; transition:all 0.15s;" class="option-card">
                        <input type="checkbox" name="show_code" <?php echo $show_code ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#c0392b;">
                        إظهار كود المنتج بجانب الاسم
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.85rem; font-weight:600; transition:all 0.15s;" class="option-card" id="showAUCard">
                        <input type="checkbox" name="show_additional_units" <?php echo $show_additional_units ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#c0392b;" onchange="toggleAUOptions(this.checked)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                        إظهار أسعار الوحدات الإضافية
                    </label>
                    <div id="auCountOptions" style="<?php echo $show_additional_units ? '' : 'display:none;'; ?>">
                        <div style="font-size:0.82rem; font-weight:600; color:#475569; margin-bottom:8px;">أقصى عدد وحدات إضافية لكل منتج:</div>
                        <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px;">
                            <input type="range" name="additional_units_count" min="1" max="10" value="<?php echo $additional_units_count; ?>" id="auCountRange" oninput="updateAUPreview()" style="flex:1; accent-color:#2563eb; cursor:pointer;">
                            <span id="auCountValue" style="font-size:1.1rem; font-weight:800; color:#2563eb; min-width:24px; text-align:center;"><?php echo $additional_units_count; ?></span>
                            <span style="font-size:0.75rem; color:#94a3b8;">وحدات</span>
                        </div>
                        <!-- مؤشر عرض عمود السعر في وضع الجدول -->
                        <div id="auWidthPreview" style="margin-top:8px; padding:8px 12px; background:linear-gradient(135deg, #f0f9ff, #e0f2fe); border:1px solid #bfdbfe; border-radius:8px; display:flex; align-items:center; gap:8px; font-size:0.78rem; color:#1e40af;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" width="14" height="14"><path d="M4 6h16M4 12h8m-8 6h16"/></svg>
                            <span>عرض عمود السعر في الجدول:</span>
                            <span id="auWidthValue" style="font-weight:800; font-size:0.9rem;"><?php
                                $preview_width = 150;
                                if ($show_additional_units) {
                                    $current_au_pos = $au_position;
                                    if ($current_au_pos === 'above' || $current_au_pos === 'below') {
                                        $preview_width = 170 + ($additional_units_count * 10);
                                    } else {
                                        $preview_width = 150 + ($additional_units_count * 42);
                                    }
                                    $preview_width = min($preview_width, 480);
                                }
                                echo $preview_width;
                            ?></span>
                            <span>px</span>
                            <div id="auWidthBar" style="flex:1; height:6px; background:#dbeafe; border-radius:3px; overflow:hidden; margin-right:4px;">
                                <div id="auWidthFill" style="height:100%; background:linear-gradient(90deg, #2563eb, #1d4ed8); border-radius:3px; transition:width 0.3s; width:<?php echo min(($preview_width / 480) * 100, 100); ?>%;"></div>
                            </div>
                        </div>

                        <!-- مكان ظهور أسعار الوحدات الإضافية -->
                        <div style="font-size:0.82rem; font-weight:600; color:#475569; margin-bottom:8px; margin-top:12px;">مكان ظهور أسعار الوحدات الإضافية بالنسبة للسعر الأساسي:</div>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="auAfterLabel" onclick="selectAUPos('after')">
                                <input type="radio" name="au_position" value="after" <?php echo $au_position === 'after' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">← بعد السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">السعر الأساسي ثم أسعار الوحدات الإضافية</div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="auBeforeLabel" onclick="selectAUPos('before')">
                                <input type="radio" name="au_position" value="before" <?php echo $au_position === 'before' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">→ قبل السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">أسعار الوحدات الإضافية ثم السعر الأساسي</div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="auBelowLabel" onclick="selectAUPos('below')">
                                <input type="radio" name="au_position" value="below" <?php echo $au_position === 'below' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">↓ تحت السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">السعر بخط كبير ثم الوحدات بخط صغير تحته</div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; padding:12px; border:2px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:all 0.15s;" id="auAboveLabel" onclick="selectAUPos('above')">
                                <input type="radio" name="au_position" value="above" <?php echo $au_position === 'above' ? 'checked' : ''; ?> style="accent-color:#2563eb;">
                                <div>
                                    <div style="font-weight:700; font-size:0.82rem; color:#1e293b;">↑ فوق السعر</div>
                                    <div style="font-size:0.7rem; color:#94a3b8;">الوحدات بخط صغير فوق السعر بخط كبير</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- زر التوليد -->
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
            <button type="submit" name="print_price_list" value="1" class="btn btn-primary btn-lg" style="min-width:240px; background:#c0392b; border-color:#c0392b;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                إنشاء لائحة الأسعار
            </button>
            <a href="list-products.php" class="btn btn-outline btn-lg">العودة للمنتجات</a>
        </div>
    </form>
</div>

<style>
.option-card:hover { border-color: #c0392b !important; background: #fef2f2; }
</style>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
function toggleAllCategories(checked) {
    document.querySelectorAll('.category-checkbox').forEach(function(cb) {
        cb.checked = checked;
        var label = cb.closest('label');
        if (label) {
            label.style.borderColor = checked ? '#c0392b' : '#e2e8f0';
            label.style.background = checked ? '#fef2f2' : '';
        }
    });
    updateSelectedCatInfo();
    updatePageCount();
}
function updateSelectAll() {
    var all = document.querySelectorAll('.category-checkbox');
    var checked = document.querySelectorAll('.category-checkbox:checked');
    document.getElementById('selectAllCats').checked = (all.length === checked.length);
    updateSelectedCatInfo();
    updatePageCount();

    document.querySelectorAll('.category-checkbox').forEach(function(cb) {
        var label = cb.closest('label');
        if (label) {
            if (cb.checked) {
                label.style.borderColor = '#c0392b';
                label.style.background = '#fef2f2';
            } else {
                label.style.borderColor = '#e2e8f0';
                label.style.background = '';
            }
        }
    });
}
function updatePageCount() {
    var checked = document.querySelectorAll('.category-checkbox:checked');
    var mode = document.querySelector('input[name="categories_mode"]:checked');
    var layout = document.querySelector('input[name="layout"]:checked');
    var isSeparate = mode && mode.value === 'separate';
    var isGrid = layout && layout.value === 'grid';
    var display = document.getElementById('pageCountDisplay');
    var label = document.getElementById('pageCountLabel');
    if (!display || !label) return;

    // حساب عدد المنتجات للتصنيفات المختارة
    var totalCheckedProducts = 0;
    var catData = <?php echo json_encode($categoriesJson, JSON_UNESCAPED_UNICODE); ?>;
    var catIds = [];
    checked.forEach(function(cb) { catIds.push(parseInt(cb.value)); });

    var checkedCats = catData.filter(function(c) { return catIds.indexOf(c.id) !== -1; });
    var checkedCatCount = checkedCats.length;

    checkedCats.forEach(function(c) { totalCheckedProducts += c.products; });

    if (checkedCatCount === 0) {
        display.textContent = '0';
        label.textContent = 'صفحة A4';
        return;
    }

    // تقدير عدد المنتجات في صفحة A4 واحدة
    // نمط البطاقات (شبكة): ~20-24 منتج في صفحة (مع الهيدر والتصنيفات)
    // نمط الجدول: ~28-32 منتج في صفحة
    var productsPerCatPage = 18; // تقدير متحفظ (مع هيدر + عنوان تصنيف + تذييل)
    var productsPerPage = isGrid ? 22 : 30;

    var pages = 0;
    if (isSeparate) {
        // كل تصنيف في صفحة منفصلة
        checkedCats.forEach(function(c) {
            var catPages = Math.max(1, Math.ceil(c.products / productsPerCatPage));
            pages += catPages;
        });
    } else {
        // كل التصنيفات في صفحة واحدة
        // نحسب مساحة التصنيفات (كل تصنيف ~1.5 منتج بسبب البانر)
        var effectiveProducts = totalCheckedProducts + (checkedCatCount * 2);
        pages = Math.max(1, Math.ceil(effectiveProducts / productsPerPage));
    }

    display.textContent = pages;
    label.textContent = 'صفحة A4 متوقعة';
}
function updateSelectedCatInfo() {
    var all = document.querySelectorAll('.category-checkbox');
    var checked = document.querySelectorAll('.category-checkbox:checked');
    var info = document.getElementById('selectedCatInfo');
    var mode = document.querySelector('input[name="categories_mode"]:checked');
    var isSeparate = mode && mode.value === 'separate';
    if (!info) return;
    if (checked.length === 0) {
        info.textContent = 'لم يتم اختيار أي تصنيف — لن يتم عرض منتجات';
        info.style.color = '#dc2626';
    } else if (checked.length === 1) {
        info.textContent = 'سيتم طباعة تصنيف واحد';
        info.style.color = '#64748b';
    } else if (checked.length === all.length) {
        if (isSeparate) {
            info.textContent = 'سيتم طباعة ' + checked.length + ' تصنيفات — كل تصنيف في صفحة منفصلة';
            info.style.color = '#7c3aed';
        } else {
            info.textContent = 'سيتم طباعة جميع التصنيفات (' + checked.length + ') في صفحة واحدة';
            info.style.color = '#64748b';
        }
    } else {
        if (isSeparate) {
            info.textContent = 'سيتم طباعة ' + checked.length + ' تصنيفات — كل تصنيف في صفحة منفصلة';
            info.style.color = '#7c3aed';
        } else {
            info.textContent = 'سيتم طباعة ' + checked.length + ' تصنيفات في صفحة واحدة';
            info.style.color = '#16a34a';
        }
    }
}
function updateLayoutStyle() {
    var grid = document.querySelector('input[value="grid"]');
    var list = document.querySelector('input[value="list"]');
    document.getElementById('layoutGridLabel').style.borderColor = grid.checked ? '#c0392b' : '#e2e8f0';
    document.getElementById('layoutGridLabel').style.background = grid.checked ? '#fef2f2' : '';
    document.getElementById('layoutListLabel').style.borderColor = list.checked ? '#c0392b' : '#e2e8f0';
    document.getElementById('layoutListLabel').style.background = list.checked ? '#fef2f2' : '';
    updatePageCount();
}
function updateCurrencyStyle() {
    var right = document.querySelector('input[value="right"]');
    var left = document.querySelector('input[value="left"]');
    document.getElementById('currencyRightLabel').style.borderColor = right.checked ? '#c0392b' : '#e2e8f0';
    document.getElementById('currencyRightLabel').style.background = right.checked ? '#fef2f2' : '';
    document.getElementById('currencyLeftLabel').style.borderColor = left.checked ? '#c0392b' : '#e2e8f0';
    document.getElementById('currencyLeftLabel').style.background = left.checked ? '#fef2f2' : '';
}
function updateModeStyle() {
    var one = document.querySelector('input[value="one"]');
    var sep = document.querySelector('input[value="separate"]');
    document.getElementById('modeOneLabel').style.borderColor = one.checked ? '#c0392b' : '#e2e8f0';
    document.getElementById('modeOneLabel').style.background = one.checked ? '#fef2f2' : '';
    document.getElementById('modeSepLabel').style.borderColor = sep.checked ? '#c0392b' : '#e2e8f0';
    document.getElementById('modeSepLabel').style.background = sep.checked ? '#fef2f2' : '';
    updateSelectedCatInfo();
    updatePageCount();
}
document.addEventListener('DOMContentLoaded', function() {
    updateLayoutStyle();
    updateCurrencyStyle();
    updateModeStyle();
    updateSelectedCatInfo();
    updatePageCount();
    updateUnitPosStyle();
    document.querySelectorAll('.category-checkbox').forEach(function(cb) {
        var label = cb.closest('label');
        if (cb.checked && label) {
            label.style.borderColor = '#c0392b';
            label.style.background = '#fef2f2';
        }
    });
});
function toggleUnitOptions(checked) {
    var opts = document.getElementById('unitPositionOptions');
    if (opts) {
        opts.style.display = checked ? '' : 'none';
    }
    var card = document.getElementById('showUnitCard');
    if (card) {
        card.style.borderColor = checked ? '#c0392b' : '#e2e8f0';
        card.style.background = checked ? '#fef2f2' : '';
    }
}
function selectUnitPos(pos) {
    var labels = ['unitAfterLabel', 'unitBeforeLabel', 'unitBelowLabel', 'unitAboveLabel'];
    var positions = ['after', 'before', 'below', 'above'];
    for (var i = 0; i < labels.length; i++) {
        var el = document.getElementById(labels[i]);
        if (el) {
            if (positions[i] === pos) {
                el.style.borderColor = '#c0392b';
                el.style.background = '#fef2f2';
            } else {
                el.style.borderColor = '#e2e8f0';
                el.style.background = '';
            }
        }
    }
}
function updateUnitPosStyle() {
    var radios = document.querySelectorAll('input[name="unit_position"]');
    radios.forEach(function(r) {
        if (r.checked) selectUnitPos(r.value);
    });
}
function toggleAUOptions(checked) {
    var opts = document.getElementById('auCountOptions');
    if (opts) opts.style.display = checked ? '' : 'none';
    var card = document.getElementById('showAUCard');
    if (card) {
        card.style.borderColor = checked ? '#2563eb' : '#e2e8f0';
        card.style.background = checked ? '#eff6ff' : '';
    }
}
function getAUPosition() {
    var radios = document.querySelectorAll('input[name="au_position"]');
    for (var i = 0; i < radios.length; i++) {
        if (radios[i].checked) return radios[i].value;
    }
    return 'after';
}
function calcAUWidth(count, position) {
    var width = 150;
    if (position === 'above' || position === 'below') {
        width = 170 + (count * 10);
    } else {
        width = 150 + (count * 42);
    }
    return Math.min(width, 480);
}
function updateAUPreview() {
    var range = document.getElementById('auCountRange');
    var countVal = document.getElementById('auCountValue');
    var widthVal = document.getElementById('auWidthValue');
    var widthFill = document.getElementById('auWidthFill');
    var widthPreview = document.getElementById('auWidthPreview');
    if (!range) return;
    var count = parseInt(range.value);
    if (countVal) countVal.textContent = count;
    var pos = getAUPosition();
    var width = calcAUWidth(count, pos);
    if (widthVal) widthVal.textContent = width;
    if (widthFill) widthFill.style.width = Math.min((width / 480) * 100, 100) + '%';
    if (widthPreview) widthPreview.style.display = '';
}
function selectAUPos(pos) {
    var labels = ['auAfterLabel', 'auBeforeLabel', 'auBelowLabel', 'auAboveLabel'];
    var positions = ['after', 'before', 'below', 'above'];
    for (var i = 0; i < labels.length; i++) {
        var el = document.getElementById(labels[i]);
        if (el) {
            if (positions[i] === pos) {
                el.style.borderColor = '#2563eb';
                el.style.background = '#eff6ff';
            } else {
                el.style.borderColor = '#e2e8f0';
                el.style.background = '';
            }
        }
    }
    updateAUPreview();
}
document.addEventListener('DOMContentLoaded', function() {
    var auCheck = document.querySelector('input[name="show_additional_units"]');
    if (auCheck && auCheck.checked) { toggleAUOptions(true); updateAUPreview(); }
    var auRadios = document.querySelectorAll('input[name="au_position"]');
    auRadios.forEach(function(r) {
        if (r.checked) selectAUPos(r.value);
    });
    // تهيئة عرض عمود السعر عند تحميل الصفحة
    updateAUPreview();
});
</script>


<?php endif; ?>
