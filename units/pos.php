<?php
/**
 * ========================================================================
 *  واجهة نقطة البيع المحسّنة (POS)
 *  صفحة كاشير متكاملة: بحث سريع، كتالوج منتجات، سلة تسوق، دفع، إيصال
 *  تدعم: سمة داكنة/فاتحة، أصوات، إكمال تلقائي، مسح باركود
 * ========================================================================
 */
require_once 'includes/bootstrap.php';
$page_title = t('nav_pos');
require_once __DIR__ . '/includes/permissions.php';
requireLogin();
requirePermission('sales.add');

// === إعدادات POS ===
$pos_theme = getPosSetting('pos_theme', 'light');
$pos_sound_enabled = getPosSetting('pos_sound_enabled', '1') === '1';
$pos_auto_complete = getPosSetting('pos_auto_complete', '0') === '1';

// === إعدادات الطابعة الحرارية ===
$thermal_printer_enabled = getSetting('thermal_printer_enabled', '0') === '1';
$thermal_printer_type = getSetting('thermal_printer_type', 'browser');
$thermal_printer_ip = getSetting('thermal_printer_ip', '192.168.1.100');
$thermal_printer_port = getSetting('thermal_printer_port', '9100');
$thermal_printer_width = getSetting('thermal_printer_width', '80mm');
$thermal_auto_print = getSetting('thermal_auto_print', '0') === '1';
$thermal_auto_cut = getSetting('thermal_auto_cut', '1') === '1';
$thermal_cash_drawer = getSetting('thermal_cash_drawer', '0') === '1';
$thermal_receipt_footer = getSetting('thermal_receipt_footer', '');

// === جلب التصنيفات ===
$categories = [];
try {
    $categories = fetchAll("SELECT id, name, color FROM categories ORDER BY sort_order ASC, name ASC");
} catch (Exception $e) {}

// === جلب إعدادات المتجر ===
$store_name = getSetting('store_name', '');
$store_phone = getSetting('store_phone', '');
$store_address = getSetting('store_address', '');
$currency = getCurrencySymbol();
$tax_enabled = getSetting('tax_enabled', '0') === '1';
$tax_rate = floatval(getSetting('tax_rate', '20'));

$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

require_once 'includes/header.php';
?>
<!-- v6.1.6 (W15): PWA manifest -->
<link rel="manifest" href="pos-manifest.json">
<meta name="theme-color" content="#1a56db">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="assets/img/price-check-icon-192.png">
>

<!-- === واجهة نقطة البيع === -->
<div class="pos-layout <?php echo $pos_theme === 'dark' ? 'pos-dark' : ''; ?>" id="posLayout">

    <!-- === الشريط العلوي العائم === -->
    <div class="pos-topbar-float-wrapper">
        <div class="pos-topbar">
            <div class="pos-topbar-right">
                <h1 class="pos-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 01-8 0"/>
                    </svg>
                    <?php echo t('nav_pos'); ?>
                </h1>
            </div>
            <div class="pos-topbar-center">
                <!-- بحث سريع -->
                <div class="pos-search-wrapper" id="posSearchWrapper">
                    <svg class="pos-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="posSearchInput" class="pos-search-input"
                           placeholder="<?php echo t('pos_search_placeholder'); ?>"
                           autocomplete="off" autofocus>
                    <button type="button" class="pos-scan-btn" id="posScanBtn" title="مسح الباركود بالكاميرا">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                            <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                            <line x1="7" y1="12" x2="17" y2="12"/>
                        </svg>
                    </button>
                    <div class="pos-search-dropdown" id="posSearchDropdown" style="display:none;">
                        <div class="pos-search-results" id="posSearchResults"></div>
                    </div>
                </div>
            </div>
            <div class="pos-topbar-left">
                <div class="pos-clock" id="posClock"></div>
                <button type="button" class="pos-icon-btn" id="posThemeToggle" title="تبديل السمة">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" id="themeIcon">
                        <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
                    </svg>
                </button>
                <button type="button" class="pos-icon-btn pos-held-btn" id="posHeldBtn" onclick="showHeldCarts()" title="الطلبات المعلقة">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>
                    </svg>
                    <span class="pos-held-badge" id="heldBadge" style="display:none;">0</span>
                </button>
                <!-- v6.1.6 (W16): زر المرتجعات -->
                <button type="button" class="pos-icon-btn" onclick="openReturnModal()" title="<?php echo t('pos_return'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
                    </svg>
                </button>
                <a href="caisse.php" class="pos-icon-btn" title="الصندوق">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 14h.01"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>

    <!-- === المحتوى الرئيسي === -->
    <div class="pos-main">

        <!-- === الجانب الأيسر: كتالوج المنتجات === -->
        <div class="pos-catalog">
            <!-- فلاتر التصنيفات -->
            <div class="pos-categories" id="posCategories">
                <button type="button" class="pos-cat-btn active" data-category="0" onclick="filterCategory(0)">
                    <?php echo t('pos_category_all'); ?>
                </button>
                <?php foreach ($categories as $cat): ?>
                <button type="button" class="pos-cat-btn" data-category="<?php echo $cat['id']; ?>"
                        onclick="filterCategory(<?php echo $cat['id']; ?>)"
                        style="--cat-color: <?php echo htmlspecialchars($cat['color'] ?? '#6366f1'); ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- شبكة المنتجات -->
            <div class="pos-products-grid" id="posProductsGrid">
                <div class="pos-loading">
                    <div class="spinner" style="width:32px;height:32px;"></div>
                    <p><?php echo t('pos_loading_products'); ?></p>
                </div>
            </div>

            <!-- ترقيم الصفحات -->
            <div class="pos-pagination" id="posPagination"></div>
        </div>

        <!-- === الجانب الأيمن: سلة التسوق === -->
        <div class="pos-cart-panel">
            <!-- عنوان السلة -->
            <div class="pos-cart-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
                    </svg>
                    <?php echo t('pos_cart_title'); ?>
                    <span class="pos-cart-count" id="cartCount">0</span>
                </h2>
                <button type="button" class="pos-cart-clear" id="cartClearBtn" onclick="clearCart()" title="إفراغ السلة">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                </button>
            </div>

            <!-- عناصر السلة -->
            <div class="pos-cart-items" id="cartItems">
                <div class="pos-cart-empty" id="cartEmpty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="64" height="64">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
                    </svg>
                    <p><?php echo t('pos_cart_empty'); ?></p>
                    <span><?php echo t('pos_cart_empty_hint'); ?></span>
                </div>
            </div>

            <!-- ملخص السلة -->
            <div class="pos-cart-summary" id="cartSummary" style="display:none;">
                <div class="pos-summary-row">
                    <span>المجموع الفرعي</span>
                    <span id="subtotalAmount">0.00</span>
                </div>
                <?php if ($tax_enabled): ?>
                <div class="pos-summary-row pos-tax-row">
                    <span>الضريبة (<?php echo intval($tax_rate); ?>%)</span>
                    <span id="taxAmount">0.00</span>
                </div>
                <?php endif; ?>
                <div class="pos-summary-row pos-discount-row" id="discountRow" style="display:none;">
                    <span>الخصم</span>
                    <span id="discountAmount">0.00</span>
                </div>
                <div class="pos-summary-row pos-total-row">
                    <span>الإجمالي</span>
                    <span id="totalAmount">0.00</span>
                </div>

                <!-- v6.1.6 (W11 إصلاح): حقل الخصم مع مُبدّل نوع (مبلغ/نسبة) -->
                <div class="pos-discount-input" id="discountInput" style="display:none;">
                    <div class="pos-discount-type-toggle" style="margin-bottom:6px;">
                        <button type="button" id="discountTypeAmount" class="pos-discount-type-btn active" onclick="setDiscountType('amount')"><?php echo t('pos_discount_type_amount'); ?></button>
                        <button type="button" id="discountTypePercent" class="pos-discount-type-btn" onclick="setDiscountType('percent')"><?php echo t('pos_discount_type_percent'); ?></button>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <input type="number" id="discountValue" class="form-control" step="0.01" min="0" placeholder="<?php echo t('pos_discount_value_ph_amount'); ?>" style="flex:1;">
                        <button type="button" class="pos-discount-apply-btn" onclick="applyDiscount()"><?php echo t('pos_discount_type_amount'); ?></button>
                    </div>
                </div>

                <!-- أزرار التحكم -->
                <div class="pos-cart-actions">
                    <button type="button" class="pos-action-btn" onclick="toggleDiscount()" title="<?php echo t('pos_discount'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
                            <line x1="7" y1="7" x2="7.01" y2="7"/>
                        </svg>
                        <?php echo t('pos_discount'); ?>
                    </button>
                    <button type="button" class="pos-action-btn" onclick="holdCart()" title="<?php echo t('pos_hold'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <path d="M12 2v10"/><path d="M16.24 7.76a6 6 0 11-8.49 0"/>
                        </svg>
                        <?php echo t('pos_hold'); ?>
                    </button>
                </div>

                <!-- قسم الدفع -->
                <div class="pos-payment-section" id="paymentSection">
                    <!-- v6.1.4 (W6 إصلاح): مُحدد طريقة الدفع (نقداً/بطاقة/مقسّم) -->
                    <div class="pos-payment-method-row" id="paymentMethodRow">
                        <label style="font-weight:700; font-size:0.85rem; margin-bottom:6px; display:block;"><?php echo t('pos_payment_method_label'); ?></label>
                        <div class="pos-payment-method-buttons">
                            <button type="button" class="pos-pm-btn active" data-pm="cash" onclick="selectPaymentMethod('cash')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/>
                                </svg>
                                <span><?php echo t('pos_pm_cash'); ?></span>
                            </button>
                            <button type="button" class="pos-pm-btn" data-pm="card" onclick="selectPaymentMethod('card')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
                                </svg>
                                <span><?php echo t('pos_pm_card'); ?></span>
                            </button>
                            <button type="button" class="pos-pm-btn" data-pm="split" onclick="selectPaymentMethod('split')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <path d="M16 3h5v5"/><path d="M8 21H3v-5"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
                                </svg>
                                <span><?php echo t('pos_pm_split'); ?></span>
                            </button>
                        </div>
                    </div>
                    <!-- حقل المبلغ النقدي (يظهر لـ cash و split) -->
                    <div class="pos-payment-field" id="cashField">
                        <label><?php echo t('pos_paid_amount'); ?></label>
                        <input type="number" id="paidAmount" class="pos-payment-input" step="0.01" min="0"
                               placeholder="0.00" oninput="calculateChange()" <?php echo $pos_auto_complete ? 'autofocus' : ''; ?>>
                    </div>
                    <!-- v6.1.4 (W6 إصلاح): حقل مبلغ البطاقة (يظهر فقط لـ split) -->
                    <div class="pos-payment-field" id="cardField" style="display:none;">
                        <label><?php echo t('pos_card_amount'); ?></label>
                        <input type="number" id="cardAmount" class="pos-payment-input" step="0.01" min="0"
                               placeholder="0.00" oninput="calculateChange()">
                    </div>
                    <div class="pos-change-display" id="changeDisplay" style="display:none;">
                        <span><?php echo t('pos_change_label'); ?></span>
                        <span class="pos-change-amount" id="changeAmount">0.00</span>
                    </div>
                    <button type="button" class="pos-pay-btn" id="payBtn" onclick="processPayment()" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                            <line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                        <?php echo t('pos_pay_btn'); ?> (<span id="payBtnTotal">0.00</span> <?php echo htmlspecialchars($currency); ?>)
                    </button>
                    <!-- أزرار المبالغ السريعة -->
                    <div class="pos-quick-amounts" id="quickAmounts">
                        <button type="button" onclick="setQuickAmount('exact')"><?php echo t('pos_quick_exact'); ?></button>
                        <button type="button" onclick="setQuickAmount('round')"><?php echo t('pos_quick_round'); ?></button>
                        <button type="button" onclick="setQuickAmount(50)">50</button>
                        <button type="button" onclick="setQuickAmount(100)">100</button>
                        <button type="button" onclick="setQuickAmount(200)">200</button>
                        <button type="button" onclick="setQuickAmount(500)">500</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- === شريط الإجمالي العائم للهاتف === -->
    <div class="pos-mobile-float-bar" id="mobileFloatBar" style="display:none;">
        <div class="pos-mobile-float-total">
            <span class="pos-mobile-float-label"><?php echo t('pos_total_label'); ?></span>
            <span class="pos-mobile-float-amount" id="mobileFloatTotal">0.00</span>
        </div>
        <button type="button" class="pos-mobile-float-pay" id="mobileFloatPayBtn" onclick="processPayment()" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                <line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            <?php echo t('pos_pay_btn'); ?>
        </button>
    </div>

    <!-- === شريط التبويب للهاتف === -->
    <div class="pos-mobile-tabs" id="posMobileTabs">
        <button type="button" class="pos-mobile-tab active" data-tab="catalog" onclick="switchMobileTab('catalog')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            <span><?php echo t('pos_products_tab'); ?></span>
        </button>
        <button type="button" class="pos-mobile-tab" data-tab="cart" onclick="switchMobileTab('cart')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            <span><?php echo t('pos_cart_tab'); ?></span>
            <span class="pos-mobile-cart-badge" id="mobileCartBadge" style="display:none;">0</span>
        </button>
    </div>
</div>


<!-- v6.1.6 (W12 إصلاح): نافذة خصم الصنف الفردي -->
<div class="pos-item-discount-modal" id="itemDiscountModal">
    <div class="pos-item-discount-modal-content">
        <div class="pos-item-discount-modal-title"><?php echo t('pos_item_discount_modal_title'); ?></div>
        <div class="pos-item-discount-modal-item-name" id="itemDiscountItemName">—</div>
        <div class="pos-discount-type-toggle" style="margin-bottom:8px;">
            <button type="button" id="itemDiscountTypeAmount" class="pos-discount-type-btn active" onclick="setItemDiscountType('amount')"><?php echo t('pos_discount_type_amount'); ?></button>
            <button type="button" id="itemDiscountTypePercent" class="pos-discount-type-btn" onclick="setItemDiscountType('percent')"><?php echo t('pos_discount_type_percent'); ?></button>
        </div>
        <input type="number" id="itemDiscountValue" class="form-control" step="0.01" min="0" placeholder="<?php echo t('pos_item_discount_modal_ph_amount'); ?>" style="width:100%;">
        <div class="pos-item-discount-modal-actions">
            <button type="button" class="pos-action-btn" onclick="closeItemDiscountModal()" style="background:#e5e7eb; color:#374151;"><?php echo t('pos_item_discount_modal_cancel'); ?></button>
            <button type="button" class="pos-discount-apply-btn" onclick="saveItemDiscount()"><?php echo t('pos_item_discount_modal_save'); ?></button>
        </div>
    </div>
</div>

<!-- === نافذة الإيصال === -->
<div class="pos-receipt-overlay" id="receiptOverlay" style="display:none;">
    <div class="pos-receipt-modal">
        <div class="pos-receipt" id="receiptContent">
            <div class="pos-receipt-header">
                <h3 id="receiptStoreName"><?php echo htmlspecialchars($store_name ?: getSetting('app_name', 'نقطة البيع')); ?></h3>
                <?php if ($store_address): ?>
                <p><?php echo htmlspecialchars($store_address); ?></p>
                <?php endif; ?>
                <?php if ($store_phone): ?>
                <p>هاتف: <?php echo htmlspecialchars($store_phone); ?></p>
                <?php endif; ?>
                <div class="pos-receipt-divider">━━━━━━━━━━━━━━━━━━━━━━</div>
                <p id="receiptDateTime"></p>
                <p id="receiptNo" style="font-weight:700; font-size:1.05em;"></p>
                <p>الكاشير: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></p>
            </div>
            <div class="pos-receipt-divider">━━━━━━━━━━━━━━━━━━━━━━</div>
            <div class="pos-receipt-items" id="receiptItems"></div>
            <div class="pos-receipt-divider">━━━━━━━━━━━━━━━━━━━━━━</div>
            <div class="pos-receipt-totals" id="receiptTotals"></div>
            <div class="pos-receipt-divider">━━━━━━━━━━━━━━━━━━━━━━</div>
            <div class="pos-receipt-footer">
                <p><?php echo t('pos_receipt_thanks'); ?></p>
                <p style="font-size:0.75rem; color:#999; margin-top:4px;"><?php echo htmlspecialchars(getSetting('app_name', t('pos_app_name_default'))); ?></p>
            </div>
        </div>
        <div class="pos-receipt-actions">
            <?php if ($thermal_printer_enabled): ?>
            <button type="button" class="pos-receipt-btn pos-thermal-btn" onclick="printReceiptThermal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                طباعة حرارية
            </button>
            <?php endif; ?>
            <button type="button" class="pos-receipt-btn pos-print-btn" onclick="printReceipt()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                <?php echo $thermal_printer_enabled ? 'طباعة عادية' : 'طباعة الإيصال'; ?>
            </button>
            <button type="button" class="pos-receipt-btn pos-newsale-btn" onclick="newSale()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                عملية جديدة
            </button>
        </div>
    </div>
</div>

<!-- v6.1.6 (W15): مؤشر حالة الاتصال -->
<div class="pos-offline-banner" id="posOfflineBanner" style="display:none;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0119 12.55"/><path d="M5 12.55a10.94 10.94 0 015.17-2.39"/><path d="M10.71 5.05A16 16 0 0122.56 9"/><path d="M1.42 9a15.91 15.91 0 014.7-2.88"/><path d="M8.53 16.11a6 6 0 016.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
    <span>الوضع Offline — المنتجات من الكاش</span>
</div>

<!-- v6.1.6 (W16): نافذة المرتجعات -->
<div class="pos-return-overlay" id="returnOverlay" style="display:none;" onclick="closeReturnModal(event)">
    <div class="pos-return-modal" onclick="event.stopPropagation();">
        <div class="pos-return-modal-header">
            <h3><?php echo t('pos_return_title'); ?></h3>
            <button type="button" class="pos-held-close-btn" onclick="closeReturnModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="pos-return-modal-body">
            <!-- خطوة 1: البحث عن العملية الأصلية -->
            <div class="pos-return-step" id="returnStep1">
                <label style="font-weight:600; display:block; margin-bottom:8px;"><?php echo t('pos_return_search_label'); ?></label>
                <div style="display:flex; gap:8px; margin-bottom:12px;">
                    <input type="text" id="returnSaleIds" class="form-control" placeholder="<?php echo t('pos_return_search_placeholder'); ?>" style="flex:1;">
                    <button type="button" class="pos-discount-apply-btn" onclick="searchOriginalSale()"><?php echo t('pos_return_search_btn'); ?></button>
                </div>
                <div id="returnSearchResult" style="min-height:60px;"></div>
            </div>
            <!-- خطوة 2: عرض الأصناف واختيار المرتجع -->
            <div class="pos-return-step" id="returnStep2" style="display:none;">
                <div id="returnOriginalItems"></div>
                <div style="margin-top:12px;">
                    <label style="font-weight:600; display:block; margin-bottom:6px;"><?php echo t('pos_return_reason'); ?></label>
                    <select id="returnReason" class="form-control">
                        <option value="defective"><?php echo t('pos_return_reason_defective'); ?></option>
                        <option value="wrong"><?php echo t('pos_return_reason_wrong'); ?></option>
                        <option value="changed_mind"><?php echo t('pos_return_reason_changed_mind'); ?></option>
                        <option value="other"><?php echo t('pos_return_reason_other'); ?></option>
                    </select>
                </div>
                <div style="margin-top:12px; display:flex; gap:8px;">
                    <button type="button" class="pos-discount-apply-btn" style="background:#dc2626;" onclick="processReturn()"><?php echo t('pos_return_confirm_btn'); ?></button>
                    <button type="button" class="pos-discount-apply-btn" onclick="backToReturnStep1()" style="background:#6b7280;"><?php echo t('back'); ?></button>
                </div>
            </div>
            <!-- خطوة 3: نتيجة المرتجع -->
            <div class="pos-return-step" id="returnStep3" style="display:none;">
                <div id="returnResult" style="text-align:center; padding:20px;"></div>
                <button type="button" class="pos-discount-apply-btn" onclick="closeReturnModal()" style="margin-top:12px;"><?php echo t('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- === نافذة الطلبات المعلقة === -->
<div class="pos-held-overlay" id="heldOverlay" style="display:none;" onclick="closeHeldCarts(event)">
    <div class="pos-held-modal" onclick="event.stopPropagation()">
        <div class="pos-held-modal-header">
            <h3>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>
                </svg>
                الطلبات المعلقة
                <span class="pos-held-count" id="heldCount">0</span>
            </h3>
            <button type="button" class="pos-held-close-btn" onclick="closeHeldCarts()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="pos-held-modal-body" id="heldModalBody">
        </div>
        <div class="pos-held-modal-footer" id="heldModalFooter" style="display:none;">
            <button type="button" class="pos-held-delete-all-btn" onclick="deleteAllHeldCarts()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                </svg>
                حذف الكل
            </button>
        </div>
    </div>
</div>

<!-- === أصوات POS (Base64 WAV) === -->
<?php if ($pos_sound_enabled): ?>
<audio id="posSoundBeep" preload="auto">
    <source src="data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=" type="audio/wav">
</audio>
<audio id="posSoundSuccess" preload="auto">
    <source src="data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=" type="audio/wav">
</audio>
<?php endif; ?>

<style>
/* ===================================
   أنماط واجهة نقطة البيع
   POS Interface Styles
   =================================== */

/* === التخطيط العام === */
.pos-layout {
    display: flex;
    flex-direction: column;
    height: calc(100dvh - 70px);
    overflow-y: auto;
    overflow-x: hidden;
    background: var(--gray-100);
}

@supports not (height: 100dvh) {
    .pos-layout {
        height: calc(100vh - 70px);
    }
}

.pos-layout.pos-dark {
    --pos-bg: #0f172a;
    --pos-surface: #1e293b;
    --pos-surface-hover: #334155;
    --pos-text: #f1f5f9;
    --pos-text-secondary: #94a3b8;
    --pos-border: #334155;
    --pos-search-bg: #1e293b;
    --pos-card-bg: #1e293b;
    --pos-input-bg: #334155;
    --pos-accent: #3b82f6;
    background: var(--pos-bg);
}

/* === غلاف الشريط العلوي العائم === */
.pos-topbar-float-wrapper {
    position: sticky;
    top: 0;
    z-index: 100;
    padding: 10px 14px 6px;
    background: transparent;
}

/* === الشريط العلوي العائم === */
.pos-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(226, 232, 240, 0.6);
    border-radius: 16px;
    box-shadow:
        0 4px 24px rgba(0, 0, 0, 0.06),
        0 1px 3px rgba(0, 0, 0, 0.04);
    transition: box-shadow 0.3s, background 0.3s;
}

.pos-topbar:hover {
    box-shadow:
        0 8px 32px rgba(0, 0, 0, 0.09),
        0 2px 6px rgba(0, 0, 0, 0.05);
}

.pos-dark .pos-topbar {
    background: rgba(30, 41, 59, 0.88);
    border-color: rgba(51, 65, 85, 0.6);
    box-shadow:
        0 4px 24px rgba(0, 0, 0, 0.25),
        0 1px 3px rgba(0, 0, 0, 0.15);
}

.pos-dark .pos-topbar:hover {
    box-shadow:
        0 8px 32px rgba(0, 0, 0, 0.35),
        0 2px 6px rgba(0, 0, 0, 0.2);
}

.pos-topbar-right {
    flex-shrink: 0;
}

.pos-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--primary);
    white-space: nowrap;
    padding: 4px 14px 4px 4px;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(99, 102, 241, 0.06) 100%);
    border-radius: 10px;
    border: 1px solid rgba(37, 99, 235, 0.1);
    transition: all 0.2s;
}

.pos-title svg {
    filter: drop-shadow(0 1px 2px rgba(37, 99, 235, 0.2));
}

.pos-dark .pos-title {
    color: #60a5fa;
    background: linear-gradient(135deg, rgba(96, 165, 250, 0.12) 0%, rgba(129, 140, 248, 0.08) 100%);
    border-color: rgba(96, 165, 250, 0.15);
}

.pos-topbar-center {
    flex: 1;
    max-width: 560px;
    margin: 0 auto;
}

.pos-search-wrapper {
    position: relative;
}

.pos-search-icon {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray-400);
    pointer-events: none;
    transition: color 0.2s;
}

.pos-search-input {
    width: 100%;
    padding: 9px 90px 9px 42px;
    border: 1.5px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    font-size: 0.92rem;
    background: rgba(249, 250, 251, 0.6);
    transition: all 0.25s;
    direction: rtl;
}

.pos-search-input:focus {
    outline: none;
    border-color: var(--primary);
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12), 0 2px 8px rgba(37, 99, 235, 0.08);
}

.pos-search-input:focus ~ .pos-search-icon {
    color: var(--primary);
}

.pos-dark .pos-search-input {
    background: rgba(51, 65, 85, 0.5);
    border-color: rgba(51, 65, 85, 0.8);
    color: var(--pos-text);
}

.pos-dark .pos-search-input:focus {
    background: rgba(51, 65, 85, 0.8);
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15), 0 2px 8px rgba(96, 165, 250, 0.1);
}

.pos-scan-btn {
    position: absolute;
    left: 6px;
    top: 50%;
    transform: translateY(-50%);
    background: linear-gradient(135deg, #7c3aed, #6366f1);
    color: #fff;
    border: none;
    border-radius: 9px;
    padding: 5px 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
    box-shadow: 0 1px 4px rgba(99, 102, 241, 0.25);
}

.pos-scan-btn:hover {
    background: linear-gradient(135deg, #6d28d9, #4f46e5);
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.35);
    transform: translateY(-50%) scale(1.03);
}

.pos-search-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(226, 232, 240, 0.7);
    border-radius: 14px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1), 0 2px 8px rgba(0, 0, 0, 0.04);
    max-height: 320px;
    overflow-y: auto;
    z-index: 1000;
}

.pos-dark .pos-search-dropdown {
    background: rgba(30, 41, 59, 0.96);
    border-color: rgba(51, 65, 85, 0.7);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
}

.pos-search-result-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    cursor: pointer;
    transition: background 0.15s;
    border-bottom: 1px solid rgba(243, 244, 246, 0.6);
}

.pos-search-result-item:first-child {
    border-radius: 14px 14px 0 0;
}

.pos-search-result-item:last-child {
    border-bottom: none;
    border-radius: 0 0 14px 14px;
}

.pos-search-result-item:hover,
.pos-search-result-item.highlighted {
    background: rgba(37, 99, 235, 0.06);
}

.pos-dark .pos-search-result-item:hover,
.pos-dark .pos-search-result-item.highlighted {
    background: rgba(96, 165, 250, 0.08);
}

.pos-search-result-name {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.88rem;
}

.pos-dark .pos-search-result-name { color: var(--pos-text); }

.pos-search-result-code {
    font-size: 0.72rem;
    color: var(--gray-400);
    font-family: monospace;
}

.pos-search-result-stock {
    font-size: 0.78rem;
    margin-top: 2px;
}

.pos-search-result-price {
    font-weight: 700;
    color: var(--primary);
    font-size: 0.92rem;
    white-space: nowrap;
}

.pos-topbar-left {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.pos-clock {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--gray-500);
    font-family: 'SF Mono', 'Cascadia Code', 'Courier New', monospace;
    direction: ltr;
    padding: 4px 10px;
    background: rgba(249, 250, 251, 0.5);
    border-radius: 8px;
    letter-spacing: 0.03em;
}

.pos-dark .pos-clock {
    color: var(--pos-text-secondary);
    background: rgba(51, 65, 85, 0.4);
}

.pos-icon-btn {
    background: rgba(249, 250, 251, 0.6);
    border: 1px solid rgba(226, 232, 240, 0.6);
    border-radius: 10px;
    padding: 7px;
    cursor: pointer;
    color: var(--gray-500);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    text-decoration: none;
    width: 36px;
    height: 36px;
}

.pos-icon-btn:hover {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
    color: var(--primary);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.12);
}

.pos-dark .pos-icon-btn {
    background: rgba(51, 65, 85, 0.4);
    border-color: rgba(51, 65, 85, 0.6);
    color: var(--pos-text-secondary);
}

.pos-dark .pos-icon-btn:hover {
    background: rgba(96, 165, 250, 0.1);
    border-color: rgba(96, 165, 250, 0.25);
    color: #60a5fa;
    box-shadow: 0 2px 8px rgba(96, 165, 250, 0.15);
}

/* === المحتوى الرئيسي === */
.pos-main {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* === كتالوج المنتجات === */
.pos-catalog {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding: 12px;
}

.pos-categories {
    display: flex;
    gap: 8px;
    padding: 8px 0;
    overflow-x: auto;
    flex-shrink: 0;
    -webkit-overflow-scrolling: touch;
}

.pos-categories::-webkit-scrollbar { height: 4px; }
.pos-categories::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }

.pos-cat-btn {
    padding: 6px 16px;
    border-radius: 20px;
    border: 1.5px solid var(--gray-200);
    background: #fff;
    color: var(--gray-600);
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.pos-cat-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.pos-cat-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

.pos-dark .pos-cat-btn {
    background: var(--pos-surface);
    border-color: var(--pos-border);
    color: var(--pos-text-secondary);
}

.pos-dark .pos-cat-btn:hover { border-color: #60a5fa; color: #60a5fa; }
.pos-dark .pos-cat-btn.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }

.pos-products-grid {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(195px, 1fr));
    gap: 14px;
    overflow-y: auto;
    padding: 8px 4px;
    align-content: start;
}

.pos-products-grid::-webkit-scrollbar { width: 6px; }
.pos-products-grid::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 6px; }

.pos-product-card {
    background: #fff;
    border: 1.5px solid var(--gray-200);
    border-radius: 14px;
    padding: 0;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    min-height: 225px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.pos-product-card .pos-pimg-wrap {
    width: 100%;
    height: 90px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--gray-50);
    overflow: hidden;
    flex-shrink: 0;
}

.pos-product-card .pos-pimg-wrap img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    transition: transform 0.25s;
}

.pos-product-card:hover .pos-pimg-wrap img {
    transform: scale(1.05);
}

.pos-product-card .pos-pimg-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: var(--gray-300);
}

.pos-product-card:hover {
    border-color: var(--primary);
    box-shadow: 0 8px 28px rgba(37, 99, 235, 0.15), 0 2px 8px rgba(37, 99, 235, 0.08);
    transform: translateY(-3px);
}

.pos-product-card:active {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
}

.pos-product-card.out-of-stock {
    opacity: 0.5;
    cursor: not-allowed;
}

.pos-product-card.out-of-stock:hover {
    border-color: var(--gray-300);
    box-shadow: none;
    transform: none;
}

.pos-dark .pos-product-card {
    background: var(--pos-card-bg);
    border-color: var(--pos-border);
}

.pos-dark .pos-product-card:hover {
    border-color: #60a5fa;
    box-shadow: 0 4px 16px rgba(96, 165, 250, 0.15);
}

.pos-dark .pos-product-card .pos-pimg-wrap {
    background: rgba(255,255,255,0.04);
}

.pos-dark .pos-product-card .pos-pimg-placeholder {
    color: var(--pos-text-secondary);
    opacity: 0.3;
}

.pos-product-card .pos-pcat-bar {
    position: absolute;
    top: 0;
    right: 0;
    left: 0;
    width: 100%;
    height: 5px;
    background: var(--cat-color, var(--primary));
    border-radius: 14px 14px 0 0;
}

.pos-product-card-body {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 14px 10px;
    flex: 1;
}

.pos-product-name {
    font-weight: 700;
    font-size: 0.92rem;
    color: var(--gray-800);
    line-height: 1.4;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    min-height: 2.4em;
}

.pos-dark .pos-product-name { color: var(--pos-text); }

.pos-product-code {
    font-size: 0.72rem;
    color: var(--gray-400);
    font-family: 'Courier New', monospace;
    letter-spacing: 0.03em;
    margin-top: 1px;
}

.pos-product-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    border-top: 1px solid var(--gray-100);
    background: linear-gradient(180deg, var(--gray-50) 0%, rgba(249,250,251,0.5) 100%);
    margin-top: auto;
}

.pos-dark .pos-product-bottom {
    border-color: var(--pos-border);
    background: rgba(255,255,255,0.03);
}

.pos-product-price {
    font-weight: 800;
    color: var(--primary);
    font-size: 1rem;
    letter-spacing: -0.01em;
}

.pos-dark .pos-product-price { color: #60a5fa; }

.pos-product-stock {
    font-size: 0.72rem;
    padding: 3px 8px;
    border-radius: 7px;
    font-weight: 600;
    white-space: nowrap;
}

.pos-product-stock.in-stock {
    background: #dcfce7;
    color: #059669;
}

.pos-product-stock.low-stock {
    background: #fef3c7;
    color: #d97706;
}

.pos-product-stock.no-stock {
    background: #fee2e2;
    color: #dc2626;
}

.pos-dark .pos-product-stock.in-stock { background: #064e3b; color: #6ee7b7; }
.pos-dark .pos-product-stock.low-stock { background: #451a03; color: #fcd34d; }
.pos-dark .pos-product-stock.no-stock { background: #450a0a; color: #fca5a5; }

.pos-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    grid-column: 1 / -1;
    padding: 40px;
    color: var(--gray-400);
}

.pos-pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    padding: 10px 0;
    flex-shrink: 0;
}

.pos-pagination button {
    padding: 6px 14px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    font-size: 0.82rem;
    font-weight: 600;
    transition: all 0.2s;
}

.pos-pagination button:hover { border-color: var(--primary); color: var(--primary); }
.pos-pagination button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.pos-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }

.pos-dark .pos-pagination button {
    background: var(--pos-surface);
    border-color: var(--pos-border);
    color: var(--pos-text-secondary);
}

/* === لوحة سلة التسوق === */
.pos-cart-panel {
    width: 45%;
    min-width: 360px;
    max-width: 824px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    background: #fff;
    border-right: 1px solid var(--gray-200);
    box-shadow: -4px 0 20px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.pos-dark .pos-cart-panel {
    background: var(--pos-surface);
    border-color: var(--pos-border);
}

.pos-cart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 2px solid var(--gray-200);
    background: linear-gradient(180deg, #fff 0%, #fafbfc 100%);
    position: sticky;
    top: 0;
    z-index: 10;
}

.pos-dark .pos-cart-header {
    background: var(--pos-surface);
    border-color: var(--pos-border);
}

.pos-cart-header h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--gray-800);
}

.pos-dark .pos-cart-header h2 { color: var(--pos-text); }

.pos-cart-count {
    background: var(--primary);
    color: #fff;
    font-size: 0.68rem;
    padding: 1px 7px;
    border-radius: 10px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
}

.pos-cart-clear {
    background: none;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 5px 8px;
    cursor: pointer;
    color: var(--gray-400);
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    transition: all 0.2s;
}

.pos-cart-clear:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #dc2626;
}

.pos-dark .pos-cart-clear {
    border-color: var(--pos-border);
    color: var(--pos-text-secondary);
}

.pos-cart-items {
    flex: 1;
    overflow-y: auto;
    padding: 10px 12px;
}

.pos-cart-items::-webkit-scrollbar { width: 5px; }
.pos-cart-items::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 5px; }
.pos-cart-items::-webkit-scrollbar-track { background: transparent; }

.pos-cart-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 50px 20px;
    color: var(--gray-300);
    text-align: center;
}

.pos-cart-empty svg {
    opacity: 0.4;
}

.pos-cart-empty p {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-400);
}

.pos-cart-empty span {
    font-size: 0.78rem;
    color: var(--gray-300);
}

/* === عنصر في السلة === */
.pos-cart-item {
    display: flex;
    align-items: stretch;
    gap: 0;
    margin-bottom: 8px;
    border-radius: 12px;
    background: #fff;
    border: 1.5px solid var(--gray-150, #e8ecf1);
    transition: all 0.2s ease;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.pos-cart-item:hover {
    border-color: var(--primary);
    box-shadow: 0 3px 12px rgba(37, 99, 235, 0.1);
    transform: translateX(-2px);
}

.pos-dark .pos-cart-item {
    background: var(--pos-surface);
    border-color: var(--pos-border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

.pos-dark .pos-cart-item:hover {
    border-color: #60a5fa;
    box-shadow: 0 3px 12px rgba(96, 165, 250, 0.12);
}

.pos-cart-item-info {
    flex: 1;
    min-width: 0;
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    order: 2;
}

.pos-cart-item-name {
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--gray-800);
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    line-height: 1.4;
}

.pos-dark .pos-cart-item-name { color: var(--pos-text); }

.pos-cart-item-detail {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 2px;
}

.pos-cart-item-price {
    font-size: 0.75rem;
    color: var(--gray-500);
    font-family: 'Courier New', monospace;
    font-weight: 600;
}

.pos-cart-item-unit {
    font-size: 0.66rem;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
}

.pos-dark .pos-cart-item-unit {
    background: rgba(255,255,255,0.08);
    color: var(--pos-text-secondary);
}

.pos-cart-item-actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    padding: 8px 10px;
    border-right: 1.5px solid var(--gray-150, #e8ecf1);
    background: linear-gradient(180deg, rgba(249,250,251,0.5) 0%, rgba(243,244,246,0.5) 100%);
    min-width: 44px;
    order: 1;
    /* v6.1.4 (W9 إصلاح): تعليق CSS صالح بدل // — كان سيفشل في CSS validator */
    /* max-width: 80px; */
}

.pos-dark .pos-cart-item-actions {
    border-color: var(--pos-border);
    background: rgba(255,255,255,0.03);
}

.pos-cart-item-qty-btn {
    width: 30px;
    height: 26px;
    border-radius: 6px;
    border: 1.5px solid var(--gray-200);
    background: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--gray-600);
    transition: all 0.15s;
    line-height: 1;
}

.pos-cart-item-qty-btn:hover {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
    transform: scale(1.1);
}

.pos-cart-item-qty-btn.pos-qty-remove:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #dc2626;
}

.pos-dark .pos-cart-item-qty-btn {
    background: var(--pos-surface);
    border-color: var(--pos-border);
    color: var(--pos-text-secondary);
}

.pos-cart-item-qty-input {
    font-weight: 800;
    font-size: 0.92rem;
    color: var(--gray-800);
    min-width: 40px;
    max-width: 56px;
    text-align: center;
    line-height: 1;
    border: 1.5px solid var(--gray-200);
    border-radius: 6px;
    padding: 3px 4px;
    background: #fff;
    outline: none;
    -moz-appearance: textfield;
}

.pos-cart-item-qty-input::-webkit-inner-spin-button,
.pos-cart-item-qty-input::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.pos-cart-item-qty-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--primary-light);
}

.pos-dark .pos-cart-item-qty-input {
    color: var(--pos-text);
    background: var(--pos-surface);
    border-color: var(--pos-border);
}

.pos-dark .pos-cart-item-qty-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(59,130,246,0.3);
}

.pos-cart-item-total-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 8px 14px;
    min-width: 74px;
    order: 3;
}

.pos-cart-item-total {
    font-weight: 800;
    font-size: 0.95rem;
    color: var(--primary);
    white-space: nowrap;
    line-height: 1.2;
}

.pos-dark .pos-cart-item-total { color: #60a5fa; }

.pos-cart-item-delete {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--gray-300);
    padding: 2px;
    margin-top: 2px;
    transition: color 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pos-cart-item-delete:hover {
    color: #dc2626;
}

/* === صورة المنتج في السلة === */
.pos-cart-item-thumb {
    width: 66px;
    min-width: 48px;
    /* v6.1.4 (W9 إصلاح): تعليق CSS صالح بدل // */
    /* height: 48px; */
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--gray-50);
    overflow: hidden;
    border-radius: 8px;
    border: 1px solid var(--gray-150, #e8ecf1);
    order: 0;
}

.pos-cart-item-thumb img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.pos-cart-item-thumb .pos-cart-thumb-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray-300);
}

.pos-dark .pos-cart-item-thumb {
    background: rgba(255,255,255,0.04);
    border-color: var(--pos-border);
}

.pos-dark .pos-cart-item-thumb .pos-cart-thumb-placeholder {
    color: var(--pos-text-secondary);
    opacity: 0.3;
}

/* === صورة في نتائج البحث === */
.pos-search-result-thumb {
    width: 40px;
    min-width: 40px;
    height: 40px;
    border-radius: 8px;
    overflow: hidden;
    background: var(--gray-50);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--gray-150, #e8ecf1);
}

.pos-search-result-thumb img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.pos-dark .pos-search-result-thumb {
    background: rgba(255,255,255,0.04);
    border-color: var(--pos-border);
}

/* === ملخص السلة === */
.pos-cart-summary {
    border-top: 2px solid var(--gray-200);
    padding: 14px 18px;
    flex-shrink: 0;
    background: linear-gradient(180deg, #fafbfc 0%, #fff 100%);
}

.pos-dark .pos-cart-summary {
    border-color: var(--pos-border);
    background: var(--pos-surface);
}

.pos-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    font-size: 0.88rem;
    color: var(--gray-500);
}

.pos-summary-row.pos-tax-row {
    font-size: 0.78rem;
}

.pos-summary-row.pos-discount-row {
    color: #d97706;
    font-size: 0.78rem;
}

.pos-summary-row.pos-total-row {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--gray-800);
    padding: 10px 0 6px;
    border-top: 2px dashed var(--gray-300);
    margin-top: 6px;
}

.pos-dark .pos-summary-row { color: var(--pos-text-secondary); }
.pos-dark .pos-summary-row.pos-total-row { color: var(--pos-text); border-color: var(--pos-border); }

.pos-discount-input {
    margin-top: 6px;
}

.pos-discount-input input.form-control {
    padding: 8px 12px;
    font-size: 0.88rem;
    min-height: 38px;
    border-radius: 8px;
}

.pos-discount-apply-btn {
    padding: 8px 16px !important;
    background: #f59e0b !important;
    color: #fff !important;
    border: none !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    font-size: 0.82rem !important;
    min-height: 38px !important;
    white-space: nowrap;
    transition: all 0.2s;
}

.pos-discount-apply-btn:hover {
    background: #d97706 !important;
    transform: scale(1.02);
}

/* v6.1.6 (W11): مُبدّل نوع الخصم مبلغ/نسبة */
.pos-discount-type-toggle {
    display: flex;
    background: #f1f5f9;
    border-radius: 8px;
    padding: 2px;
    gap: 2px;
}
.pos-discount-type-btn {
    flex: 1;
    padding: 6px 10px;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
}
.pos-discount-type-btn.active {
    background: #f59e0b;
    color: #fff;
}
.pos-dark .pos-discount-type-toggle { background: #1e293b; }
.pos-dark .pos-discount-type-btn { color: #94a3b8; }
.pos-dark .pos-discount-type-btn.active { background: #f59e0b; color: #fff; }

/* v6.1.6 (W12): شارة خصم الصنف */
.pos-cart-item-discount-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
    border-radius: 4px;
    padding: 1px 5px;
    font-size: 0.68rem;
    font-weight: 700;
    margin-top: 2px;
}
.pos-dark .pos-cart-item-discount-badge {
    background: #78350f;
    color: #fde68a;
    border-color: #92400e;
}
.pos-cart-item-discount-actions {
    display: flex;
    gap: 3px;
    margin-top: 3px;
}
.pos-cart-item-discount-btn {
    border: none;
    background: transparent;
    color: #6b7280;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 4px;
    font-size: 0.7rem;
    transition: all 0.15s;
}
.pos-cart-item-discount-btn:hover {
    background: #fef3c7;
    color: #92400e;
}
.pos-dark .pos-cart-item-discount-btn { color: #94a3b8; }
.pos-dark .pos-cart-item-discount-btn:hover { background: #78350f; color: #fde68a; }

/* v6.1.6 (W12): نافذة خصم الصنف */
.pos-item-discount-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 16px;
}
.pos-item-discount-modal.open { display: flex; }
.pos-item-discount-modal-content {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    max-width: 360px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.pos-dark .pos-item-discount-modal-content { background: #1e293b; color: #f1f5f9; }
.pos-item-discount-modal-title {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 4px;
}
.pos-item-discount-modal-item-name {
    color: #6b7280;
    font-size: 0.85rem;
    margin-bottom: 14px;
}
.pos-dark .pos-item-discount-modal-item-name { color: #94a3b8; }
.pos-item-discount-modal-actions {
    display: flex;
    gap: 8px;
    margin-top: 14px;
    justify-content: flex-end;
}

.pos-cart-actions {
    display: flex;
    gap: 6px;
    margin: 8px 0 4px;
}

.pos-action-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 8px 6px;
    border: 1.5px solid var(--gray-200);
    border-radius: 8px;
    background: #fff;
    color: var(--gray-600);
    font-size: 0.76rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.pos-action-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-light);
}

.pos-dark .pos-action-btn {
    background: var(--pos-input-bg);
    border-color: var(--pos-border);
    color: var(--pos-text-secondary);
}

/* === قسم الدفع === */
.pos-payment-section {
    margin-top: 6px;
}

/* === v6.1.4 (W6 إصلاح): أزرار طريقة الدفع === */
.pos-payment-method-row {
    margin-bottom: 10px;
}
.pos-payment-method-buttons {
    display: flex;
    gap: 6px;
}
.pos-pm-btn {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px 6px;
    border: 2px solid var(--gray-200, #e5e7eb);
    background: var(--gray-50, #f9fafb);
    color: var(--gray-600, #4b5563);
    border-radius: 10px;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.8rem;
    transition: all 0.18s ease;
}
.pos-pm-btn:hover {
    border-color: var(--gray-300, #d1d5db);
    background: var(--gray-100, #f3f4f6);
}
.pos-pm-btn.active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #2563eb;
}
.pos-dark .pos-pm-btn {
    border-color: var(--pos-border);
    background: rgba(255,255,255,0.03);
    color: var(--pos-text-muted);
}
.pos-dark .pos-pm-btn.active {
    border-color: #3b82f6;
    background: rgba(59,130,246,0.15);
    color: #93c5fd;
}

.pos-payment-field label {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--gray-500);
    margin-bottom: 4px;
    display: block;
}

.pos-payment-input {
    width: 100%;
    padding: 12px;
    border: 2px solid var(--gray-200);
    border-radius: 10px;
    font-size: 1.3rem;
    font-weight: 800;
    text-align: center;
    background: var(--gray-50);
    transition: all 0.2s;
}

.pos-payment-input:focus {
    outline: none;
    border-color: var(--secondary);
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
}

.pos-dark .pos-payment-input {
    background: var(--pos-input-bg);
    border-color: var(--pos-border);
    color: var(--pos-text);
}

.pos-change-display {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: #f0fdf4;
    border: 2px solid #86efac;
    border-radius: 10px;
    margin: 8px 0;
    font-weight: 700;
}

.pos-change-amount {
    font-size: 1.3rem;
    font-weight: 900;
    color: #059669;
    font-family: monospace;
}

.pos-pay-btn {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: #fff;
    font-size: 1.1rem;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(5, 150, 105, 0.3);
}

.pos-pay-btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4);
}

.pos-pay-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pos-quick-amounts {
    display: flex;
    gap: 6px;
    margin-top: 8px;
    flex-wrap: wrap;
}

.pos-quick-amounts button {
    padding: 6px 12px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: #fff;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    color: var(--gray-600);
    transition: all 0.15s;
    flex: 1;
    min-width: 60px;
}

.pos-quick-amounts button:hover {
    border-color: var(--secondary);
    color: var(--secondary);
}

.pos-dark .pos-quick-amounts button {
    background: var(--pos-input-bg);
    border-color: var(--pos-border);
    color: var(--pos-text-secondary);
}

/* === نافذة الإيصال === */
.pos-receipt-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.pos-receipt-modal {
    background: #fff;
    border-radius: 16px;
    max-width: 400px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.pos-receipt {
    padding: 24px 20px;
    font-family: 'Courier New', monospace;
    direction: rtl;
    text-align: center;
    max-height: 60vh;
    overflow-y: auto;
}

.pos-receipt-header h3 {
    font-size: 1.1rem;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 4px;
}

.pos-receipt-header p {
    font-size: 0.8rem;
    color: #64748b;
}

.pos-receipt-divider {
    color: #cbd5e1;
    font-size: 0.7rem;
    margin: 8px 0;
}

.pos-receipt-items {
    text-align: right;
}

.pos-receipt-item {
    display: flex;
    justify-content: space-between;
    font-size: 0.8rem;
    padding: 3px 0;
    color: #334155;
}

.pos-receipt-item-name {
    flex: 1;
    font-weight: 600;
}

.pos-receipt-item-qty {
    width: 60px;
    text-align: center;
    color: #64748b;
}

.pos-receipt-item-total {
    width: 80px;
    text-align: left;
    font-weight: 700;
}

.pos-receipt-totals {
    text-align: right;
}

.pos-receipt-total-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.82rem;
    padding: 2px 0;
    color: #475569;
}

.pos-receipt-total-row.grand-total {
    font-size: 1rem;
    font-weight: 900;
    color: #1e293b;
    padding: 6px 0;
}

.pos-receipt-footer p {
    font-size: 0.85rem;
    font-weight: 700;
    color: #1e293b;
}

.pos-receipt-actions {
    display: flex;
    gap: 0;
    border-top: 1px solid #e2e8f0;
}

.pos-receipt-btn {
    flex: 1;
    padding: 14px;
    border: none;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.2s;
}

.pos-print-btn {
    background: #fff;
    color: var(--primary);
}

.pos-print-btn:hover { background: #eff6ff; }

.pos-thermal-btn {
    background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
    color: #fff;
}

.pos-thermal-btn:hover { background: linear-gradient(135deg, #c2410c 0%, #ea580c 100%); }

.pos-newsale-btn {
    background: var(--primary);
    color: #fff;
    border-right: 1px solid #e2e8f0;
}

.pos-newsale-btn:hover { background: var(--primary-dark); }

/* === شريط التبويب للهاتف (مخفي افتراضياً) === */
.pos-mobile-tabs {
    display: none;
}

/* === شريط الإجمالي العائم (مخفي افتراضياً) === */
.pos-mobile-float-bar {
    display: none;
}

/* === ريسبونسف — الأجهزة اللوحية === */
@media (max-width: 900px) {
    .pos-main {
        flex-direction: column;
    }
    .pos-cart-panel {
        width: 100% !important;
        min-width: unset;
        max-width: unset;
        max-height: 45vh;
        border-right: none;
        border-top: 2px solid var(--gray-200);
        box-shadow: none;
    }
    .pos-products-grid {
        grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
    }
}

/* === ريسبونسف — الهاتف === */
@media (max-width: 768px) {
    /* === إخفاء شريط التنقل السفلي للتطبيق في صفحة POS === */
    .mobile-bottom-nav {
        display: none !important;
    }

    /* === إلغاء padding-bottom الذي يضيفه mobile.css للشريط السفلي === */
    body {
        padding-bottom: 0 !important;
    }

    /* === شريط التبويب السفلي لـ POS === */
    .pos-mobile-tabs {
        display: flex;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: #fff;
        border-top: 1px solid var(--gray-200);
        box-shadow: 0 -2px 10px rgba(0,0,0,0.08);
        z-index: 1000;
        padding: 0 0 env(safe-area-inset-bottom, 0px);
        height: calc(56px + env(safe-area-inset-bottom, 0px));
    }

    .pos-dark .pos-mobile-tabs {
        background: var(--pos-surface);
        border-color: var(--pos-border);
    }

    .pos-mobile-tab {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        padding: 0 4px;
        border: none;
        background: none;
        color: var(--gray-400);
        font-size: 0.68rem;
        font-weight: 600;
        cursor: pointer;
        position: relative;
        transition: color 0.2s;
        -webkit-tap-highlight-color: transparent;
        min-height: 44px;
    }

    .pos-mobile-tab.active {
        color: var(--primary);
    }

    .pos-mobile-tab.active svg {
        stroke: var(--primary);
    }

    .pos-mobile-cart-badge {
        position: absolute;
        top: 2px;
        left: calc(50% + 8px);
        background: #ef4444;
        color: #fff;
        font-size: 0.6rem;
        font-weight: 800;
        min-width: 16px;
        height: 16px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
    }

    /* === التخطيط الرئيسي على الهاتف === */
    .pos-layout {
        height: calc(100dvh - 56px);
        padding-bottom: calc(56px + env(safe-area-inset-bottom, 0px));
    }

    @supports not (height: 100dvh) {
        .pos-layout {
            height: calc(100vh - 56px);
        }
    }

    .pos-main {
        flex-direction: column;
        overflow: hidden;
    }

    /* إخفاء/إظهار الأقسام حسب التبويب النشط */
    .pos-layout.pos-show-cart .pos-catalog {
        display: none;
    }

    .pos-layout.pos-show-cart .pos-cart-panel {
        display: flex;
        flex: 1;
        max-height: none;
        width: 100%;
        border-top: none;
    }

    .pos-layout:not(.pos-show-cart) .pos-cart-panel {
        display: none;
    }

    /* === الشريط العلوي العائم — الهاتف === */
    .pos-topbar-float-wrapper {
        padding: 6px 8px 4px;
    }

    .pos-topbar {
        flex-wrap: wrap;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 14px;
    }

    .pos-topbar-right {
        flex-shrink: 0;
    }

    .pos-title {
        font-size: 0.9rem;
        padding: 3px 10px 3px 3px;
        border-radius: 8px;
    }

    .pos-title svg {
        width: 16px;
        height: 16px;
    }

    .pos-topbar-center {
        order: 3;
        max-width: 100%;
        flex: 1 1 100%;
    }

    .pos-topbar-left {
        gap: 4px;
    }

    .pos-clock {
        display: none;
    }

    .pos-icon-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        padding: 6px;
    }

    .pos-search-input {
        padding: 7px 80px 7px 38px;
        font-size: 0.85rem;
        border-radius: 10px;
    }

    .pos-scan-btn {
        padding: 4px 8px;
        border-radius: 8px;
    }

    /* === التصنيفات === */
    .pos-categories {
        padding: 6px 4px;
        gap: 4px;
    }

    .pos-cat-btn {
        padding: 5px 10px;
        font-size: 0.72rem;
    }

    /* === شبكة المنتجات === */
    .pos-products-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 8px;
        padding: 4px 0;
    }

    .pos-product-card {
        min-height: 160px;
        border-radius: 10px;
    }

    .pos-product-card .pos-pimg-wrap {
        height: 76px;
    }

    .pos-product-card-body {
        padding: 8px 10px 4px;
    }

    .pos-product-name {
        font-size: 0.78rem;
        line-height: 1.3;
        -webkit-line-clamp: 2;
    }

    .pos-product-code {
        font-size: 0.62rem;
    }

    .pos-product-bottom {
        padding: 6px 10px;
    }

    .pos-product-price {
        font-size: 0.85rem;
    }

    .pos-product-stock {
        font-size: 0.62rem;
        padding: 2px 5px;
    }

    /* === شريط الإجمالي العائم === */
    .pos-mobile-float-bar {
        display: flex;
        position: fixed;
        bottom: calc(56px + env(safe-area-inset-bottom, 0px));
        left: 0;
        right: 0;
        background: #fff;
        border-top: 1px solid var(--gray-200);
        box-shadow: 0 -2px 12px rgba(0,0,0,0.1);
        padding: 8px 12px;
        z-index: 999;
        gap: 10px;
        align-items: center;
    }

    .pos-dark .pos-mobile-float-bar {
        background: var(--pos-surface);
        border-color: var(--pos-border);
    }

    .pos-mobile-float-total {
        flex: 1;
        display: flex;
        align-items: baseline;
        gap: 6px;
    }

    .pos-mobile-float-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--gray-500);
    }

    .pos-dark .pos-mobile-float-label { color: var(--pos-text-secondary); }

    .pos-mobile-float-amount {
        font-size: 1.15rem;
        font-weight: 900;
        color: var(--gray-800);
    }

    .pos-dark .pos-mobile-float-amount { color: var(--pos-text); }

    .pos-mobile-float-pay {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 10px 22px;
        border: none;
        border-radius: 10px;
        background: linear-gradient(135deg, #059669, #10b981);
        color: #fff;
        font-size: 0.92rem;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(5,150,105,0.3);
    }

    .pos-mobile-float-pay:hover:not(:disabled) {
        background: linear-gradient(135deg, #047857, #059669);
        box-shadow: 0 4px 12px rgba(5,150,105,0.4);
    }

    .pos-mobile-float-pay:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* === لوحة السلة تتمرر كوحدة واحدة === */
    .pos-layout.pos-show-cart .pos-cart-panel {
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: calc(52px + env(safe-area-inset-bottom, 0px));
    }

    .pos-layout.pos-show-cart .pos-cart-panel::-webkit-scrollbar { width: 3px; }
    .pos-layout.pos-show-cart .pos-cart-panel::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 3px; }

    .pos-cart-items {
        flex: none !important;
        overflow-y: visible !important;
        padding-bottom: 0;
    }

    .pos-cart-summary {
        flex-shrink: 0;
        border-top: 2px solid var(--gray-200);
    }

    /* === إخفاء زر الدفع العادي (يستبدله الشريط العائم) === */
    .pos-layout.pos-show-cart .pos-pay-btn {
        display: none;
    }

    /* === عناصر السلة — تصميم مكدس للهاتف === */
    .pos-cart-item {
        flex-wrap: wrap;
        border-radius: 10px;
        margin-bottom: 8px;
    }

    .pos-cart-item-info {
        flex: 1 1 0;
        min-width: 0;
        padding: 10px 10px 4px;
        order: 1;
    }

    .pos-cart-item-name {
        font-size: 0.85rem;
        line-height: 1.3;
    }

    .pos-cart-item-detail {
        margin-top: 3px;
    }

    .pos-cart-item-price {
        font-size: 0.72rem;
    }

    .pos-cart-item-total-section {
        order: 2;
        flex: 0 0 auto;
        flex-direction: row;
        align-items: center;
        gap: 6px;
        padding: 10px 10px 4px;
        min-width: auto;
    }

    .pos-cart-item-total {
        font-size: 0.92rem;
        font-weight: 900;
    }

    .pos-cart-item-delete {
        margin-top: 0;
        padding: 4px;
    }

    .pos-cart-item-delete svg {
        width: 14px;
        height: 14px;
    }

    .pos-cart-item-actions {
        order: 3;
        width: 100%;
        flex-direction: row;
        justify-content: center;
        gap: 8px;
        padding: 6px 10px 8px;
        border-right: none;
        border-top: 1px solid var(--gray-100);
        background: rgba(255,255,255,0.3);
        min-width: auto;
    }

    .pos-dark .pos-cart-item-actions {
        border-color: var(--pos-border);
        background: rgba(255,255,255,0.02);
    }

    .pos-cart-item-qty-btn {
        width: 34px;
        height: 30px;
        font-size: 1rem;
        border-radius: 8px;
    }

    .pos-cart-item-qty-input {
        font-size: 1rem;
        min-width: 42px;
        max-width: 60px;
        padding: 3px 4px;
    }

    /* === ملخص السلة — مضغوط === */
    .pos-cart-summary {
        padding: 8px 12px;
    }

    .pos-summary-row {
        padding: 2px 0;
        font-size: 0.82rem;
    }

    .pos-summary-row.pos-total-row {
        font-size: 1.05rem;
        padding: 5px 0 3px;
    }

    /* === أزرار التحكم === */
    .pos-cart-actions {
        gap: 6px;
        margin: 6px 0 2px;
    }

    .pos-action-btn {
        padding: 8px 6px;
        font-size: 0.76rem;
        border-radius: 8px;
    }

    /* === قسم الدفع — مضغوط === */
    .pos-payment-input {
        padding: 8px;
        font-size: 1.1rem;
    }

    .pos-quick-amounts {
        gap: 4px;
    }

    .pos-quick-amounts button {
        padding: 6px 4px;
        font-size: 0.68rem;
        min-width: 0;
        flex: 1 1 calc(33% - 4px);
    }

    /* === ترقيم الصفحات === */
    .pos-pagination {
        padding: 6px 0;
    }

    .pos-pagination button {
        padding: 5px 10px;
        font-size: 0.75rem;
    }

    /* === الإيصال === */
    .pos-receipt-overlay {
        padding: 12px;
    }

    .pos-receipt-modal {
        max-width: calc(100vw - 24px);
        margin: 0 auto;
    }

    .pos-receipt-actions {
        flex-direction: column;
    }

    .pos-receipt-btn {
        padding: 12px;
    }

    .pos-newsale-btn {
        border-right: none;
        border-top: 1px solid #e2e8f0;
    }
}

/* === ريسبونسف — هواتف صغيرة === */
@media (max-width: 480px) {
    .pos-layout {
        height: calc(100dvh - 52px);
        padding-bottom: calc(56px + env(safe-area-inset-bottom, 0px));
    }

    @supports not (height: 100dvh) {
        .pos-layout {
            height: calc(100vh - 52px);
        }
    }

    .pos-topbar {
        padding: 4px 8px;
        gap: 4px;
    }

    .pos-title {
        font-size: 0.88rem;
    }

    .pos-title svg {
        width: 16px;
        height: 16px;
    }

    .pos-search-input {
        padding: 7px 75px 7px 36px;
        font-size: 0.85rem;
    }

    .pos-products-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 8px;
    }

    .pos-product-card {
        min-height: 155px;
        border-radius: 10px;
    }

    .pos-product-card .pos-pimg-wrap {
        height: 72px;
    }

    .pos-product-card-body {
        padding: 8px 10px 4px;
        gap: 2px;
    }

    .pos-product-name {
        font-size: 0.76rem;
        min-height: 2em;
        -webkit-line-clamp: 2;
    }

    .pos-product-code {
        font-size: 0.6rem;
    }

    .pos-product-bottom {
        padding: 6px 10px;
        gap: 4px;
    }

    .pos-product-price {
        font-size: 0.82rem;
    }

    .pos-product-stock {
        font-size: 0.6rem;
        padding: 2px 5px;
    }

    .pos-cat-btn {
        padding: 4px 8px;
        font-size: 0.68rem;
    }

    .pos-cart-item-qty-btn {
        width: 30px;
        height: 28px;
        font-size: 0.9rem;
    }

    .pos-cart-item-name {
        font-size: 0.78rem;
    }

    .pos-cart-item-total {
        font-size: 0.85rem;
    }

    .pos-cart-item-info {
        padding: 8px 8px 3px;
    }

    .pos-cart-item-total-section {
        padding: 8px 8px 3px;
    }

    .pos-cart-item-actions {
        padding: 5px 8px 6px;
        gap: 6px;
    }

    .pos-quick-amounts button {
        flex: 1 1 calc(33% - 4px);
        padding: 6px 4px;
        font-size: 0.68rem;
    }

    .pos-discount-input input.form-control {
        padding: 7px 10px;
        font-size: 0.82rem;
        min-height: 34px;
    }

    .pos-discount-apply-btn {
        padding: 7px 12px !important;
        font-size: 0.76rem !important;
        min-height: 34px !important;
    }

    .pos-mobile-tab {
        font-size: 0.62rem;
    }

    .pos-mobile-float-amount {
        font-size: 1.05rem;
    }

    .pos-mobile-float-pay {
        padding: 8px 16px;
        font-size: 0.85rem;
    }
}

/* === طباعة الإيصال === */
@media print {
    body * { visibility: hidden; }
    .pos-receipt, .pos-receipt * { visibility: visible; }
    .pos-receipt {
        position: absolute;
        left: 0;
        top: 0;
        width: 80mm;
        font-size: 12px;
        max-height: none;
        overflow: visible;
        padding: 10px;
    }
    .pos-receipt-actions { display: none !important; }
    #receiptNo { display: block !important; font-weight: 700 !important; }
}

/* === حركة إضافة للسلة === */
@keyframes posAddToCart {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.pos-cart-item.just-added {
    animation: posAddToCart 0.3s ease;
}

/* === حركة نجاح الدفع === */
@keyframes posPaySuccess {
    0% { transform: scale(1); }
    25% { transform: scale(1.03); }
    50% { transform: scale(0.98); }
    100% { transform: scale(1); }
}

.pos-pay-btn.success {
    animation: posPaySuccess 0.4s ease;
    background: linear-gradient(135deg, #047857 0%, #059669 100%) !important;
}

/* === زر الطلبات المعلقة في الشريط العلوي === */
.pos-held-btn {
    position: relative;
}

.pos-held-badge {
    position: absolute;
    top: -4px;
    left: -4px;
    background: #ef4444;
    color: #fff;
    font-size: 0.62rem;
    font-weight: 800;
    min-width: 16px;
    height: 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    box-shadow: 0 1px 4px rgba(239, 68, 68, 0.4);
    animation: posHeldPulse 2s infinite;
    z-index: 1;
}

@keyframes posHeldPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.pos-dark .pos-held-badge {
    background: #f87171;
    box-shadow: 0 1px 4px rgba(248, 113, 113, 0.4);
}

/* === نافذة الطلبات المعلقة === */
.pos-held-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: posHeldFadeIn 0.2s ease;
}

@keyframes posHeldFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.pos-held-modal {
    background: #fff;
    border-radius: 20px;
    width: 100%;
    max-width: 520px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18), 0 8px 24px rgba(0, 0, 0, 0.08);
    animation: posHeldSlideUp 0.25s ease;
    overflow: hidden;
}

@keyframes posHeldSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.pos-dark .pos-held-modal {
    background: #1e293b;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4), 0 8px 24px rgba(0, 0, 0, 0.2);
}

.pos-held-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px;
    border-bottom: 2px solid #f1f5f9;
    background: linear-gradient(180deg, #fff 0%, #fafbfc 100%);
    flex-shrink: 0;
}

.pos-dark .pos-held-modal-header {
    border-color: #334155;
    background: #1e293b;
}

.pos-held-modal-header h3 {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.05rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0;
}

.pos-dark .pos-held-modal-header h3 {
    color: #f1f5f9;
}

.pos-held-count {
    background: #ef4444;
    color: #fff;
    font-size: 0.68rem;
    padding: 1px 7px;
    border-radius: 10px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
}

.pos-held-close-btn {
    background: none;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 6px;
    cursor: pointer;
    color: #94a3b8;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.pos-held-close-btn:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #dc2626;
}

.pos-dark .pos-held-close-btn {
    border-color: #334155;
    color: #64748b;
}

.pos-dark .pos-held-close-btn:hover {
    background: rgba(220, 38, 38, 0.15);
    border-color: rgba(248, 113, 113, 0.3);
    color: #f87171;
}

.pos-held-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    min-height: 120px;
}

.pos-held-modal-body::-webkit-scrollbar { width: 5px; }
.pos-held-modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }

.pos-dark .pos-held-modal-body::-webkit-scrollbar-thumb { background: #475569; }

.pos-held-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 50px 20px;
    color: #cbd5e1;
    text-align: center;
}

.pos-held-empty svg {
    opacity: 0.4;
}

.pos-held-empty p {
    font-size: 0.95rem;
    font-weight: 600;
    color: #94a3b8;
    margin: 0;
}

.pos-held-empty span {
    font-size: 0.78rem;
    color: #cbd5e1;
}

/* === بطاقة الطلب المعلق === */
.pos-held-card {
    background: #fff;
    border: 1.5px solid #e8ecf1;
    border-radius: 14px;
    margin-bottom: 12px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    transition: all 0.2s;
}

.pos-held-card:hover {
    border-color: #6366f1;
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.1);
}

.pos-dark .pos-held-card {
    background: #0f172a;
    border-color: #334155;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.pos-dark .pos-held-card:hover {
    border-color: #818cf8;
    box-shadow: 0 4px 16px rgba(129, 140, 248, 0.1);
}

.pos-held-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e8ecf1;
}

.pos-dark .pos-held-card-header {
    background: rgba(255, 255, 255, 0.03);
    border-color: #334155;
}

.pos-held-card-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.pos-held-card-num {
    font-weight: 800;
    font-size: 0.92rem;
    color: #6366f1;
    background: rgba(99, 102, 241, 0.08);
    padding: 2px 10px;
    border-radius: 8px;
    font-family: 'SF Mono', 'Cascadia Code', 'Courier New', monospace;
}

.pos-dark .pos-held-card-num {
    color: #818cf8;
    background: rgba(129, 140, 248, 0.1);
}

.pos-held-card-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.75rem;
    color: #94a3b8;
}

.pos-held-card-meta span {
    display: flex;
    align-items: center;
    gap: 3px;
}

.pos-held-card-total {
    font-weight: 800;
    font-size: 0.95rem;
    color: #1e293b;
    direction: ltr;
}

.pos-dark .pos-held-card-total {
    color: #f1f5f9;
}

/* === قائمة المنتجات في البطاقة === */
.pos-held-card-items {
    padding: 8px 16px;
    max-height: 150px;
    overflow-y: auto;
}

.pos-held-card-items::-webkit-scrollbar { width: 3px; }
.pos-held-card-items::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 3px; }

.pos-held-item-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 5px 0;
    font-size: 0.8rem;
    border-bottom: 1px solid #f1f5f9;
}

.pos-held-item-row:last-child {
    border-bottom: none;
}

.pos-dark .pos-held-item-row {
    border-color: rgba(255, 255, 255, 0.06);
}

.pos-held-item-name {
    flex: 1;
    color: #475569;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pos-dark .pos-held-item-name {
    color: #cbd5e1;
}

.pos-held-item-qty {
    color: #94a3b8;
    margin: 0 12px;
    font-weight: 600;
    white-space: nowrap;
}

.pos-held-item-price {
    font-weight: 700;
    color: #6366f1;
    white-space: nowrap;
    direction: ltr;
}

.pos-dark .pos-held-item-price {
    color: #818cf8;
}

/* === إجراءات البطاقة === */
.pos-held-card-actions {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid #f1f5f9;
    background: linear-gradient(180deg, #fafbfc 0%, #f8fafc 100%);
}

.pos-dark .pos-held-card-actions {
    border-color: #334155;
    background: rgba(255, 255, 255, 0.02);
}

.pos-held-restore-btn,
.pos-held-delete-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 10px;
    border: none;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}

.pos-held-restore-btn {
    background: #3b82f6;
    color: #fff;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.25);
}

.pos-held-restore-btn:hover {
    background: #2563eb;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
    transform: translateY(-1px);
}

.pos-held-delete-btn {
    background: #fee2e2;
    color: #dc2626;
    border: 1.5px solid #fca5a5;
}

.pos-held-delete-btn:hover {
    background: #fecaca;
    border-color: #f87171;
    transform: translateY(-1px);
}

.pos-dark .pos-held-restore-btn {
    background: #2563eb;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
}

.pos-dark .pos-held-delete-btn {
    background: rgba(220, 38, 38, 0.15);
    color: #f87171;
    border-color: rgba(248, 113, 113, 0.3);
}

.pos-dark .pos-held-delete-btn:hover {
    background: rgba(220, 38, 38, 0.25);
}

/* === تذييل النافذة === */
.pos-held-modal-footer {
    display: flex;
    justify-content: center;
    padding: 14px 22px;
    border-top: 1px solid #f1f5f9;
    flex-shrink: 0;
}

.pos-dark .pos-held-modal-footer {
    border-color: #334155;
}

.pos-held-delete-all-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 24px;
    border-radius: 12px;
    border: 1.5px solid #fca5a5;
    background: #fff;
    color: #dc2626;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}

.pos-held-delete-all-btn:hover {
    background: #fee2e2;
    border-color: #f87171;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}

.pos-dark .pos-held-delete-all-btn {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(248, 113, 113, 0.25);
    color: #f87171;
}

.pos-dark .pos-held-delete-all-btn:hover {
    background: rgba(220, 38, 38, 0.18);
}

/* === خصم الطلب المعلق === */
.pos-held-card-discount {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 16px;
    background: #fef3c7;
    font-size: 0.78rem;
    font-weight: 600;
    color: #d97706;
}

.pos-dark .pos-held-card-discount {
    background: rgba(217, 119, 6, 0.12);
    color: #fcd34d;
}

/* === نافذة التأكيد === */
.pos-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: posHeldFadeIn 0.2s ease;
}

.pos-confirm-dialog {
    background: #fff;
    border-radius: 18px;
    padding: 28px;
    width: 100%;
    max-width: 380px;
    text-align: center;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18);
    animation: posHeldSlideUp 0.25s ease;
}

.pos-dark .pos-confirm-dialog {
    background: #1e293b;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4);
}

.pos-confirm-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.pos-confirm-icon.warning {
    background: #fef3c7;
    color: #d97706;
}

.pos-confirm-icon.danger {
    background: #fee2e2;
    color: #dc2626;
}

.pos-dark .pos-confirm-icon.warning {
    background: rgba(217, 119, 6, 0.15);
    color: #fcd34d;
}

.pos-dark .pos-confirm-icon.danger {
    background: rgba(220, 38, 68, 0.15);
    color: #f87171;
}

.pos-confirm-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 8px;
}

.pos-dark .pos-confirm-title {
    color: #f1f5f9;
}

.pos-confirm-msg {
    font-size: 0.85rem;
    color: #64748b;
    margin: 0 0 20px;
    line-height: 1.6;
}

.pos-dark .pos-confirm-msg {
    color: #94a3b8;
}

.pos-confirm-actions {
    display: flex;
    gap: 10px;
}

.pos-confirm-cancel,
.pos-confirm-ok {
    flex: 1;
    padding: 10px 16px;
    border-radius: 12px;
    border: none;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}

.pos-confirm-cancel {
    background: #f1f5f9;
    color: #475569;
}

.pos-confirm-cancel:hover {
    background: #e2e8f0;
}

.pos-dark .pos-confirm-cancel {
    background: #334155;
    color: #cbd5e1;
}

.pos-dark .pos-confirm-cancel:hover {
    background: #475569;
}

.pos-confirm-ok.warning {
    background: #f59e0b;
    color: #fff;
}

.pos-confirm-ok.warning:hover {
    background: #d97706;
}

.pos-confirm-ok.danger {
    background: #ef4444;
    color: #fff;
}

.pos-confirm-ok.danger:hover {
    background: #dc2626;
}

/* === استجابة الهاتف === */
@media (max-width: 600px) {
    .pos-held-modal {
        max-height: 90vh;
        border-radius: 16px;
    }

    .pos-held-card-actions {
        flex-direction: column;
    }

    .pos-held-restore-btn,
    .pos-held-delete-btn {
        padding: 10px;
    }
}

/* v6.1.6 (W15): مؤشر Offline */
.pos-offline-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 100000;
    background: #b45309;
    color: #fff;
    text-align: center;
    padding: 6px 16px;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    animation: posOfflinePulse 2s ease-in-out infinite;
}
@keyframes posOfflinePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.85; }
}

/* v6.1.6 (W16): نافذة المرتجعات */
.pos-return-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 99990;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.pos-return-modal {
    background: var(--pos-surface, #fff);
    border-radius: 16px;
    width: 100%;
    max-width: 560px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    animation: posHeldSlideUp 0.3s ease-out;
}
.pos-return-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--pos-border, #e5e7eb);
}
.pos-return-modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pos-return-modal-body {
    padding: 20px;
}
.pos-return-item-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--pos-border, #e5e7eb);
}
.pos-return-item-row:last-child { border-bottom: none; }
.pos-return-item-check {
    width: 18px;
    height: 18px;
    accent-color: #dc2626;
}
.pos-return-item-info { flex: 1; }
.pos-return-item-name { font-weight: 600; font-size: 0.9rem; }
.pos-return-item-meta { font-size: 0.8rem; color: var(--pos-text-muted, #6b7280); }
.pos-return-qty-input {
    width: 70px;
    padding: 4px 8px;
    border: 1px solid var(--pos-border, #e5e7eb);
    border-radius: 8px;
    text-align: center;
    font-size: 0.9rem;
    background: var(--pos-input-bg, #fff);
    color: var(--pos-text, #1f2937);
}
.pos-return-total-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-top: 2px dashed var(--pos-border, #e5e7eb);
    margin-top: 8px;
    font-weight: 700;
    font-size: 1rem;
    color: #dc2626;
}
.pos-dark .pos-return-modal {
    background: var(--pos-surface, #1f2937);
}
.pos-dark .pos-return-modal-header {
    border-color: rgba(255,255,255,0.1);
}
</style>

<script src="<?php echo $base_url ?? ''; ?>/assets/js/thermal-printer.js"></script>
<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// ===================================
//  منطق نقطة البيع (POS Logic)
// ===================================

const POS_CURRENCY = '<?php echo htmlspecialchars($currency); ?>';
const POS_TAX_ENABLED = <?php echo $tax_enabled ? 'true' : 'false'; ?>;
const POS_TAX_RATE = <?php echo intval($tax_rate); ?>;
const POS_AUTO_COMPLETE = <?php echo $pos_auto_complete ? 'true' : 'false'; ?>;
const POS_SOUND_ENABLED = <?php echo $pos_sound_enabled ? 'true' : 'false'; ?>;
let POS_CSRF = '<?php echo $csrf_token; ?>';
const POS_BASE_URL = '<?php echo $base_url ?? ""; ?>';
// v6.1.4 (W6 إصلاح): طريقة الدفع الحالية — cash (افتراضي) / card / split
let POS_PAYMENT_METHOD = 'cash';

// === إعدادات الطابعة الحرارية ===
const THERMAL_ENABLED = <?php echo $thermal_printer_enabled ? 'true' : 'false'; ?>;
const THERMAL_TYPE = '<?php echo $thermal_printer_enabled ? $thermal_printer_type : ''; ?>';
// v6.1.6 (W18 إصلاح): IP والمنفذ لا يظهران في source إلا إذا كانت الطابعة مُفعَّلة
const THERMAL_IP = '<?php echo $thermal_printer_enabled ? htmlspecialchars($thermal_printer_ip) : ''; ?>';
const THERMAL_PORT = '<?php echo $thermal_printer_enabled ? htmlspecialchars($thermal_printer_port) : ''; ?>';
const THERMAL_WIDTH = '<?php echo $thermal_printer_enabled ? $thermal_printer_width : ''; ?>';
const THERMAL_AUTO_PRINT = <?php echo $thermal_auto_print ? 'true' : 'false'; ?>;
const THERMAL_AUTO_CUT = <?php echo $thermal_auto_cut ? 'true' : 'false'; ?>;
const THERMAL_CASH_DRAWER = <?php echo $thermal_cash_drawer ? 'true' : 'false'; ?>;
const THERMAL_FOOTER = '<?php echo $thermal_printer_enabled ? htmlspecialchars($thermal_receipt_footer) : ''; ?>';

// تهيئة الطابعة الحرارية
ThermalPrinter.configure({
    paperWidth: THERMAL_WIDTH,
    charsPerLine: THERMAL_WIDTH === '58mm' ? 32 : 48,
    printerIp: THERMAL_IP,
    printerPort: parseInt(THERMAL_PORT) || 9100,
    autoCut: THERMAL_AUTO_CUT,
    cashDrawer: THERMAL_CASH_DRAWER,
});

// بيانات آخر إيصال (للطباعة الحرارية)
let lastReceiptData = null;

// === التبديل بين تبويبات الهاتف ===
let currentMobileTab = 'catalog';

function switchMobileTab(tab) {
    currentMobileTab = tab;
    const layout = document.getElementById('posLayout');
    const tabs = document.querySelectorAll('.pos-mobile-tab');
    const floatBar = document.getElementById('mobileFloatBar');

    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tab));

    if (tab === 'cart') {
        layout.classList.add('pos-show-cart');
        // إظهار الشريط العائم فقط إذا كانت السلة غير فارغة
        if (floatBar && cart.length > 0) floatBar.style.display = 'flex';
    } else {
        layout.classList.remove('pos-show-cart');
        if (floatBar) floatBar.style.display = 'none';
    }
}

function updateMobileCartBadge() {
    const badge = document.getElementById('mobileCartBadge');
    if (!badge) return;
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// === حالة السلة ===
let cart = [];
let currentCategory = 0;
let currentPage = 1;
let searchTimeout = null;
let searchHighlightIndex = -1;
let searchResults = [];
let discount = 0;  // cart-level absolute discount amount (computed from type+value)
let discountType = 'amount';  // v6.1.6 (W11): 'amount' | 'percent' — type of cart-level discount
let discountValue = 0;  // v6.1.6 (W11): raw value entered by user (amount or percent)
let editingItemDiscountId = null;  // v6.1.6 (W12): cart item id being edited in the modal
let itemDiscountType = 'amount';  // v6.1.6 (W12): type selected in the modal
let heldCarts = [];
let heldCartCounter = 0;

// === تهيئة ===
document.addEventListener('DOMContentLoaded', function() {
    loadHeldCartsFromStorage();
    loadProducts();
    startClock();
    setupSearch();
    setupKeyboardShortcuts();
    // v6.1.6 (W15): تسجيل Service Worker + مؤشر حالة الاتصال
    registerPosServiceWorker();
    setupOfflineDetection();
});

// === ساعة POS ===
function startClock() {
    function updateClock() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('posClock').textContent = h + ':' + m + ':' + s;
    }
    updateClock();
    setInterval(updateClock, 1000);
}

// === تحميل المنتجات ===
async function loadProducts(page, category) {
    page = page || currentPage;
    category = (category !== undefined) ? category : currentCategory;

    const grid = document.getElementById('posProductsGrid');
    grid.innerHTML = '<div class="pos-loading"><div class="spinner" style="width:32px;height:32px;"></div><p>جاري تحميل المنتجات...</p></div>';

    try {
        let url = POS_BASE_URL + '/api/search_products.php?per_page=40&page=' + page;
        if (category > 0) url += '&category=' + category;

        const res = await fetch(url);

        // معالجة خاصة للردود غير JSON (مثل صفحات خطأ HTML أو تحذيرات PHP)
        let data;
        try {
            const responseText = await res.text();
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            // الخادم أرجع HTML أو شيئاً غير JSON — غالباً خطأ PHP أو إعادة توجيه
            const statusHint = res.status === 401 ? 'انتهت صلاحية الجلسة' :
                               res.status === 403 ? 'ليس لديك صلاحية' :
                               'خطأ في الخادم (HTTP ' + res.status + ')';
            grid.innerHTML = '<div class="pos-loading"><p>' + statusHint + '</p></div>';
            return;
        }

        // استخراج المصفوفة بأمان — نتأكد أنها مصفوفة حقيقية
        let products = data.products || data.data || [];
        if (!Array.isArray(products)) {
            // قد يكون data.data كائناً يحيط بمصفوفة (مثل ApiResponse)
            if (products && typeof products === 'object' && Array.isArray(products.data)) {
                products = products.data;
            } else {
                products = [];
            }
        }
        const totalPages = (data.pagination && data.pagination.total_pages) ? data.pagination.total_pages : 1;

        if (data.success === false && data.message && products.length === 0) {
            // الخادم أرجع خطأ مع رسالة
            grid.innerHTML = '<div class="pos-loading"><p>' + App.escapeHtml(data.message) + '</p></div>';
            return;
        }

        if (products.length === 0) {
            grid.innerHTML = '<div class="pos-loading"><p>لا توجد منتجات</p></div>';
            return;
        }

        // === جلب صور المنتجات — كل منتج يأخذ صورة أو /uploads/products.png ===
        await fetchProductImages(products);

        grid.innerHTML = '';
        products.forEach(p => {
            const card = createProductCard(p);
            grid.appendChild(card);
        });

        renderPagination(page, totalPages);

    } catch (err) {
        grid.innerHTML = '<div class="pos-loading"><p>خطأ في تحميل المنتجات</p></div>';
    }
}

// === الصورة الافتراضية (SVG placeholder) ===
var POS_PLACEHOLDER_IMG = 'data:image/svg+xml;base64,' + btoa('<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" rx="8" fill="#f3f4f6"/><rect x="20" y="16" width="40" height="36" rx="4" fill="#d1d5db"/><circle cx="34" cy="30" r="5" fill="#9ca3af"/><path d="M22 48 L34 38 L44 46 L50 40 L58 48 L58 52 L22 52 Z" fill="#9ca3af"/></svg>');

// === جلب صور المنتجات من pos-product-images.php ===
// API يُرجع مسار مثل "/uploads/products/photo.jpg" أو "/uploads/products/5/products.png"
async function fetchProductImages(products) {
    if (!products || products.length === 0) return;

    try {
        const ids = products.map(p => p.id).join(',');
        const res = await fetch(POS_BASE_URL + '/api/pos-product-images.php?ids=' + ids, {
            credentials: 'same-origin'
        });
        if (!res.ok) {
            products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
            return;
        }

        const responseText = await res.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
            return;
        }

        if (!data || !data.images) {
            products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
            return;
        }

        // كل منتج يأخذ صورته
        // API يُرجع: { "images": { "1": "/uploads/photo.jpg", "2": "/uploads/products/2/products.png" } }
        products.forEach(function(p) {
            var imgPath = data.images[p.id];
            if (imgPath) {
                p.image = (imgPath.startsWith('http') || imgPath.startsWith('data:')) ? imgPath : (POS_BASE_URL + imgPath);
            } else {
                p.image = POS_PLACEHOLDER_IMG;
            }
        });
    } catch (e) {
        products.forEach(function(p) { p.image = POS_PLACEHOLDER_IMG; });
    }
}

// === بطاقة منتج ===
function createProductCard(product) {
    var card = document.createElement('div');
    card.className = 'pos-product-card' + (parseFloat(product.quantity) <= 0 ? ' out-of-stock' : '');
    card.onclick = function() { addToCart(product); };

    var qty = parseFloat(product.quantity || 0);
    var minQty = parseFloat(product.min_quantity || 5);
    var stockClass = 'in-stock';
    var stockText = qty + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'no-stock'; stockText = 'نفد'; }
    else if (qty <= minQty) { stockClass = 'low-stock'; stockText = qty + ' ' + (product.unit || ''); }

    var catColor = product.category_color || '#6366f1';

    // === صورة المنتج — ضمان: دائماً صورة (حقيقية أو placeholder) ===
    var imgUrl = product.image || POS_PLACEHOLDER_IMG;
    var imgHtml = '<div class="pos-pimg-wrap"><img src="' + imgUrl + '" alt="' + App.escapeHtml(product.name || '') + '" loading="lazy"></div>';

    card.innerHTML =
        '<div class="pos-pcat-bar" style="background:' + catColor + '"></div>' +
        imgHtml +
        '<div class="pos-product-card-body">' +
            '<div class="pos-product-name">' + App.escapeHtml(product.name || '') + '</div>' +
            '<div class="pos-product-code">' + App.escapeHtml(product.code || '') + '</div>' +
        '</div>' +
        '<div class="pos-product-bottom">' +
            '<span class="pos-product-stock ' + stockClass + '">' + stockText + '</span>' +
            '<span class="pos-product-price">' + parseFloat(product.selling_price || 0).toFixed(2) + '</span>' +
        '</div>';

    return card;
}

// === ترقيم الصفحات ===
function renderPagination(current, total) {
    const container = document.getElementById('posPagination');
    if (total <= 1) { container.innerHTML = ''; return; }

    let html = '';
    html += `<button ${current <= 1 ? 'disabled' : ''} onclick="loadProducts(${current - 1})">&#8594;</button>`;

    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);

    for (let i = start; i <= end; i++) {
        html += `<button class="${i === current ? 'active' : ''}" onclick="loadProducts(${i})">${i}</button>`;
    }

    html += `<button ${current >= total ? 'disabled' : ''} onclick="loadProducts(${current + 1})">&#8592;</button>`;
    container.innerHTML = html;
}

// === فلتر التصنيفات ===
function filterCategory(catId) {
    currentCategory = catId;
    currentPage = 1;

    document.querySelectorAll('.pos-cat-btn').forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.category) === catId);
    });

    loadProducts(1, catId);
}

// === بحث المنتجات ===
function setupSearch() {
    const input = document.getElementById('posSearchInput');
    const dropdown = document.getElementById('posSearchDropdown');

    input.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const q = this.value.trim();

        if (q.length < 1) {
            dropdown.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(() => searchProducts(q), 200);
    });

    input.addEventListener('keydown', function(e) {
        if (!dropdown.style.display || dropdown.style.display === 'none') return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            searchHighlightIndex = Math.min(searchHighlightIndex + 1, searchResults.length - 1);
            updateSearchHighlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            searchHighlightIndex = Math.max(searchHighlightIndex - 1, -1);
            updateSearchHighlight();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // إذا كان هناك نتيجة مُحددة في القائمة — أضفها
            if (searchHighlightIndex >= 0 && searchResults[searchHighlightIndex]) {
                addToCart(searchResults[searchHighlightIndex]);
                input.value = '';
                dropdown.style.display = 'none';
            }
            // إذا كانت القائمة مخفية لكن هناك نتيجة واحدة (باركود) — أضفها مباشرة
            else if ((dropdown.style.display === 'none' || !dropdown.style.display) && searchResults.length === 1) {
                addToCart(searchResults[0]);
                input.value = '';
                input.focus();
            }
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('posSearchWrapper').contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
}

async function searchProducts(query) {
    const dropdown = document.getElementById('posSearchDropdown');
    const resultsDiv = document.getElementById('posSearchResults');

    try {
        const url = POS_BASE_URL + '/api/search_products.php?q=' + encodeURIComponent(query) + '&per_page=15';
        const res = await fetch(url);

        // معالجة خاصة للردود غير JSON (مثل صفحات خطأ HTML أو تحذيرات PHP)
        let data;
        try {
            const responseText = await res.text();
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            // الخادم أرجع HTML أو شيئاً غير JSON — غالباً خطأ PHP أو إعادة توجيه
            const statusHint = res.status === 401 ? 'انتهت صلاحية الجلسة' :
                               res.status === 403 ? 'ليس لديك صلاحية' :
                               'خطأ في البحث (HTTP ' + res.status + ')';
            resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#dc2626;">' + statusHint + '</div>';
            dropdown.style.display = 'flex';
            return;
        }

        // استخراج المصفوفة بأمان — نتأكد أنها مصفوفة حقيقية
        let products = data.products || data.data || [];
        if (!Array.isArray(products)) {
            if (products && typeof products === 'object' && Array.isArray(products.data)) {
                products = products.data;
            } else {
                products = [];
            }
        }
        searchResults = products;
        searchHighlightIndex = -1;

        // جلب صور نتائج البحث
        await fetchProductImages(products);

        // إذا أرجع الخادم رسالة خطأ مع عدم وجود نتائج
        if (data.success === false && data.message && products.length === 0) {
            resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#dc2626;">' + App.escapeHtml(data.message) + '</div>';
            dropdown.style.display = 'flex';
            return;
        }

        if (products.length === 0) {
            resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#94a3b8;">لا توجد نتائج</div>';
            dropdown.style.display = 'flex';
            return;
        }

        let html = '';
        products.forEach((p, i) => {
            const qty = parseFloat(p.quantity || 0);
            const stockColor = qty > 0 ? (qty <= 5 ? '#d97706' : '#059669') : '#dc2626';
            const pImgUrl = p.image || POS_PLACEHOLDER_IMG;
            let thumbHtml = '';
            if (pImgUrl) {
                thumbHtml = `<div class="pos-search-result-thumb"><img src="${App.escapeHtml(pImgUrl)}" alt="" loading="lazy"></div>`;
            } else {
                thumbHtml = `<div class="pos-search-result-thumb"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20" style="color:var(--gray-300);"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>`;
            }
            html += `
                <div class="pos-search-result-item" data-index="${i}" onclick="selectSearchResult(${i})" style="gap:10px;">
                    ${thumbHtml}
                    <div style="flex:1; min-width:0;">
                        <div class="pos-search-result-name">${App.escapeHtml(p.name)}</div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span class="pos-search-result-code">${App.escapeHtml(p.code || '')}</span>
                            <span class="pos-search-result-stock" style="color:${stockColor}; font-size:0.75rem;">مخزون: ${qty}</span>
                        </div>
                    </div>
                    <span class="pos-search-result-price">${parseFloat(p.selling_price || 0).toFixed(2)} ${POS_CURRENCY}</span>
                </div>
            `;
        });

        resultsDiv.innerHTML = html;
        dropdown.style.display = 'flex';

    } catch (err) {
        resultsDiv.innerHTML = '<div style="padding:16px; text-align:center; color:#dc2626;">خطأ في البحث</div>';
        dropdown.style.display = 'flex';
    }
}

function selectSearchResult(index) {
    if (searchResults[index]) {
        addToCart(searchResults[index]);
        document.getElementById('posSearchInput').value = '';
        document.getElementById('posSearchDropdown').style.display = 'none';
    }
}

function updateSearchHighlight() {
    document.querySelectorAll('.pos-search-result-item').forEach((item, i) => {
        item.classList.toggle('highlighted', i === searchHighlightIndex);
    });
}

// === إدارة السلة ===
function addToCart(product) {
    const qty = parseFloat(product.quantity || 0);
    if (qty <= 0) {
        App.toast('المنتج غير متوفر في المخزون', 'error');
        return;
    }

    const existing = cart.find(item => item.id === product.id);
    if (existing) {
        if (existing.quantity + 1 > qty) {
            App.toast('الكمية المطلوبة تتجاوز المخزون المتوفر', 'warning');
            return;
        }
        existing.quantity += 1;
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            code: product.code || '',
            unit: product.unit || '',
            price: parseFloat(product.selling_price || 0),
            quantity: 1,
            maxStock: qty,
            image: product.image || POS_PLACEHOLDER_IMG,
            itemDiscount: { type: 'amount', value: 0 },  // v6.1.6 (W12): خصم فردي على هذا الصنف
        });
    }

    playSound('beep');
    renderCart();

    if (POS_AUTO_COMPLETE) {
        document.getElementById('paidAmount').focus();
    } else {
        document.getElementById('posSearchInput').focus();
    }
}

function removeFromCart(productId) {
    cart = cart.filter(item => item.id !== productId);
    renderCart();
}

// v6.1.6 (W12 إصلاح): فتح نافذة خصم الصنف الفردي
function openItemDiscountModal(productId) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    editingItemDiscountId = productId;
    document.getElementById('itemDiscountItemName').textContent = item.name + ' (' + item.price.toFixed(2) + ' × ' + item.quantity + ')';
    // تهيئة النوع من قيمة الصنف الحالية
    itemDiscountType = (item.itemDiscount && item.itemDiscount.type) ? item.itemDiscount.type : 'amount';
    setItemDiscountType(itemDiscountType);
    // ملء الحقل بالقيمة الحالية (إن وجدت)
    document.getElementById('itemDiscountValue').value = (item.itemDiscount && item.itemDiscount.value > 0) ? item.itemDiscount.value : '';
    document.getElementById('itemDiscountModal').classList.add('open');
    setTimeout(() => document.getElementById('itemDiscountValue').focus(), 50);
}

function closeItemDiscountModal() {
    document.getElementById('itemDiscountModal').classList.remove('open');
    editingItemDiscountId = null;
}

function setItemDiscountType(type) {
    if (type !== 'amount' && type !== 'percent') return;
    itemDiscountType = type;
    document.getElementById('itemDiscountTypeAmount').classList.toggle('active', type === 'amount');
    document.getElementById('itemDiscountTypePercent').classList.toggle('active', type === 'percent');
    const input = document.getElementById('itemDiscountValue');
    input.placeholder = type === 'percent'
        ? '<?php echo t('pos_item_discount_modal_ph_percent'); ?>'
        : '<?php echo t('pos_item_discount_modal_ph_amount'); ?>';
}

// v6.1.6 (W12 إصلاح): حفظ خصم الصنف
function saveItemDiscount() {
    if (editingItemDiscountId === null) return;
    const item = cart.find(i => i.id === editingItemDiscountId);
    if (!item) { closeItemDiscountModal(); return; }

    const val = parseFloat(document.getElementById('itemDiscountValue').value || 0);
    if (isNaN(val) || val < 0) {
        App.toast('<?php echo addslashes(t('pos_discount_invalid')); ?>', 'error');
        return;
    }
    if (itemDiscountType === 'percent' && val > 100) {
        App.toast('<?php echo addslashes(t('pos_discount_invalid')); ?>', 'error');
        return;
    }
    if (itemDiscountType === 'amount' && val > (item.price * item.quantity)) {
        App.toast('<?php echo addslashes(t('pos_discount_exceeds_total')); ?>', 'error');
        return;
    }
    // إذا كانت القيمة 0، أزل الخصم
    if (val === 0) {
        item.itemDiscount = { type: 'amount', value: 0 };
    } else {
        item.itemDiscount = { type: itemDiscountType, value: val };
    }
    closeItemDiscountModal();
    renderCart();
    App.toast('<?php echo addslashes(t('pos_discount_applied')); ?>', 'success');
}

// v6.1.6 (W12 إصلاح): إزالة خصم الصنف
function removeItemDiscount(productId) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    item.itemDiscount = { type: 'amount', value: 0 };
    renderCart();
}

// v6.1.6 (W12 إصلاح): إغلاق النافذة عند النقر خارجها
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('itemDiscountModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeItemDiscountModal();
        });
    }
    // إغلاق بمفتاح Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('open')) {
            closeItemDiscountModal();
        }
    });
});

function updateQuantity(productId, delta) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;

    const newQty = item.quantity + delta;
    if (newQty <= 0) {
        removeFromCart(productId);
        return;
    }
    if (newQty > item.maxStock) {
        App.toast('الكمية تتجاوز المخزون المتوفر', 'warning');
        return;
    }

    item.quantity = newQty;
    renderCart();
}

function setQuantity(productId, qty) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;

    if (qty <= 0) {
        removeFromCart(productId);
        return;
    }
    if (qty > item.maxStock) {
        App.toast('الكمية تتجاوز المخزون المتوفر', 'warning');
        return;
    }

    item.quantity = qty;
    renderCart();
}

function handleQtyInput(productId, value) {
    let qty = parseFloat(value);
    if (isNaN(qty) || qty <= 0) {
        // إعادة القيمة السابقة
        const item = cart.find(i => i.id === productId);
        if (item) {
            const input = document.querySelector(`.pos-cart-item-qty-input`);
            // نعيد الرندر لاستعادة القيمة الصحيحة
            renderCart();
        }
        return;
    }
    // تقريب إلى رقمين عشريين
    qty = Math.round(qty * 100) / 100;
    setQuantity(productId, qty);
}

function clearCart() {
    if (cart.length === 0) return;
    cart = [];
    discount = 0;
    discountType = 'amount';  // v6.1.6 (W11): إعادة تعيين نوع خصم السلة
    discountValue = 0;  // v6.1.6 (W11): إعادة تعيين قيمة خصم السلة
    setDiscountType('amount');  // v6.1.6 (W11): إعادة واجهة المُبدّل للحالة الافتراضية
    document.getElementById('discountValue').value = '';
    document.getElementById('discountInput').style.display = 'none';
    document.getElementById('discountRow').style.display = 'none';
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    const summary = document.getElementById('cartSummary');
    const payBtn = document.getElementById('payBtn');
    const floatBar = document.getElementById('mobileFloatBar');
    const floatTotal = document.getElementById('mobileFloatTotal');
    const floatPayBtn = document.getElementById('mobileFloatPayBtn');

    // تحديث عداد السلة دائماً (حتى عند الإفراغ)
    document.getElementById('cartCount').textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
    updateMobileCartBadge();

    if (cart.length === 0) {
        container.innerHTML = `
            <div class="pos-cart-empty" id="cartEmpty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="64" height="64">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
                </svg>
                <p><?php echo t('pos_cart_empty'); ?></p>
                <span><?php echo t('pos_cart_empty_hint'); ?></span>
            </div>
        `;
        summary.style.display = 'none';
        payBtn.disabled = true;
        if (floatBar) floatBar.style.display = 'none';
        if (floatPayBtn) floatPayBtn.disabled = true;
        return;
    }

    summary.style.display = 'block';
    payBtn.disabled = false;
    if (floatPayBtn) floatPayBtn.disabled = false;

    let html = '';
    let subtotal = 0;

    cart.forEach(item => {
        // v6.1.6 (W12 إصلاح): lineTotal يحسب بعد خصم الصنف الفردي
        const lineTotal = computeLineTotal(item);
        const lineTotalRaw = item.price * item.quantity;
        const itemDiscAmt = computeItemDiscountAmount(item);
        subtotal += lineTotal;

        // === صورة مصغرة للمنتج في السلة ===
        let cartThumbHtml = '';
        if (item.image) {
            cartThumbHtml = `<div class="pos-cart-item-thumb"><img src="${App.escapeHtml(item.image)}" alt="" loading="lazy"></div>`;
        } else {
            cartThumbHtml = `<div class="pos-cart-item-thumb"><div class="pos-cart-thumb-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div></div>`;
        }

        // v6.1.6 (W12 إصلاح): شارة خصم الصنف + أزرار تعديل/إزالة
        let itemDiscountHtml = '';
        if (itemDiscAmt > 0) {
            const discLabel = item.itemDiscount.type === 'percent'
                ? '-' + item.itemDiscount.value + '%'
                : '-' + itemDiscAmt.toFixed(2) + ' ' + POS_CURRENCY;
            itemDiscountHtml = `
                <div class="pos-cart-item-discount-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    ${discLabel}
                </div>
                <div class="pos-cart-item-discount-actions">
                    <button class="pos-cart-item-discount-btn" onclick="openItemDiscountModal(${item.id})" title="<?php echo t('pos_item_discount_edit'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <?php echo t('pos_item_discount_edit'); ?>
                    </button>
                    <button class="pos-cart-item-discount-btn" onclick="removeItemDiscount(${item.id})" title="<?php echo t('pos_item_discount_remove'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php echo t('pos_item_discount_remove'); ?>
                    </button>
                </div>
            `;
        }

        // v6.1.6 (W12 إصلاح): زر "إضافة خصم" إن لم يكن هناك خصم
        let addItemDiscountBtnHtml = '';
        if (itemDiscAmt === 0) {
            addItemDiscountBtnHtml = `
                <button class="pos-cart-item-discount-btn" onclick="openItemDiscountModal(${item.id})" title="<?php echo t('pos_item_discount_add'); ?>" style="font-size:0.7rem; color:#92400e; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-top:2px;">
                    + <?php echo t('pos_item_discount_add'); ?>
                </button>
            `;
        }

        html += `
            <div class="pos-cart-item">
                ${cartThumbHtml}
                <div class="pos-cart-item-info">
                    <div class="pos-cart-item-name">${App.escapeHtml(item.name)}</div>
                    <div class="pos-cart-item-detail">
                        <span class="pos-cart-item-price">${item.price.toFixed(2)} × ${Number.isInteger(item.quantity) ? item.quantity : item.quantity.toFixed(2)}</span>
                        ${item.unit ? '<span class="pos-cart-item-unit">' + App.escapeHtml(item.unit) + '</span>' : ''}
                    </div>
                    ${itemDiscountHtml}
                    ${addItemDiscountBtnHtml}
                </div>
                <div class="pos-cart-item-total-section">
                    <span class="pos-cart-item-total">${lineTotal.toFixed(2)}</span>
                    ${itemDiscAmt > 0 ? '<span style="text-decoration:line-through; color:#9ca3af; font-size:0.75rem;">' + lineTotalRaw.toFixed(2) + '</span>' : ''}
                    <button class="pos-cart-item-delete" onclick="removeFromCart(${item.id})" title="حذف">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                        </svg>
                    </button>
                </div>
                <div class="pos-cart-item-actions">
                    <button class="pos-cart-item-qty-btn pos-qty-remove" onclick="updateQuantity(${item.id}, -1)">−</button>
                    <input type="number" class="pos-cart-item-qty-input" value="${item.quantity}" min="0.01" step="0.01" onchange="handleQtyInput(${item.id}, this.value)" onblur="handleQtyInput(${item.id}, this.value)">
                    <button class="pos-cart-item-qty-btn" onclick="updateQuantity(${item.id}, 1)">+</button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;

    // حساب الإجمالي
    let tax = 0;
    let total = subtotal;

    if (POS_TAX_ENABLED) {
        tax = subtotal * (POS_TAX_RATE / 100);
        total = subtotal + tax;
    }

    const finalTotal = total - discount;

    document.getElementById('subtotalAmount').textContent = subtotal.toFixed(2) + ' ' + POS_CURRENCY;
    document.getElementById('totalAmount').textContent = finalTotal.toFixed(2) + ' ' + POS_CURRENCY;
    document.getElementById('payBtnTotal').textContent = finalTotal.toFixed(2);

    // تحديث الشريط العائم
    if (floatTotal) floatTotal.textContent = finalTotal.toFixed(2) + ' ' + POS_CURRENCY;
    if (floatBar) floatBar.style.display = 'flex';

    if (POS_TAX_ENABLED) {
        document.getElementById('taxAmount').textContent = tax.toFixed(2) + ' ' + POS_CURRENCY;
    }

    if (discount > 0) {
        document.getElementById('discountAmount').textContent = '-' + discount.toFixed(2) + ' ' + POS_CURRENCY;
        document.getElementById('discountRow').style.display = 'flex';
    }

    calculateChange();
}

// === الخصم ===
function toggleDiscount() {
    const input = document.getElementById('discountInput');
    input.style.display = input.style.display === 'none' ? 'block' : 'none';
    if (input.style.display === 'block') {
        document.getElementById('discountValue').focus();
    }
}

// v6.1.6 (W11 إصلاح): تبديل نوع خصم السلة (مبلغ/نسبة)
function setDiscountType(type) {
    if (type !== 'amount' && type !== 'percent') return;
    discountType = type;
    // تحديث الأزرار النشطة
    document.getElementById('discountTypeAmount').classList.toggle('active', type === 'amount');
    document.getElementById('discountTypePercent').classList.toggle('active', type === 'percent');
    // تحديث placeholder الحقل
    const input = document.getElementById('discountValue');
    input.placeholder = type === 'percent'
        ? '<?php echo t('pos_discount_value_ph_percent'); ?>'
        : '<?php echo t('pos_discount_value_ph_amount'); ?>';
    input.focus();
}

// v6.1.6 (W11 إصلاح): تطبيق الخصم على السلة — يحسب المبلغ المطلق من النوع والقيمة
function applyDiscount() {
    const val = parseFloat(document.getElementById('discountValue').value || 0);
    if (isNaN(val) || val < 0) {
        App.toast('<?php echo addslashes(t('pos_discount_invalid')); ?>', 'error');
        return;
    }
    // حساب المجموع الفرعي الحالي (بعد خصم الأصناف الفردية إن وجدت)
    let subtotal = cart.reduce((sum, item) => sum + computeLineTotal(item), 0);
    let computed;
    if (discountType === 'percent') {
        if (val > 100) {
            App.toast('<?php echo addslashes(t('pos_discount_invalid')); ?>', 'error');
            return;
        }
        computed = subtotal * (val / 100);
    } else {
        if (val > subtotal) {
            App.toast('<?php echo addslashes(t('pos_discount_exceeds_total')); ?>', 'error');
            return;
        }
        computed = val;
    }
    discountValue = val;
    discount = Math.round(computed * 100) / 100;  // تقريب لسنتين
    renderCart();
    App.toast('<?php echo addslashes(t('pos_discount_applied')); ?>', 'success');
}

// v6.1.6 (W12 إصلاح): حساب إجمالي الصنف بعد خصمه الفردي (إن وجد)
function computeLineTotal(item) {
    const raw = item.price * item.quantity;
    if (!item.itemDiscount || !item.itemDiscount.value || item.itemDiscount.value <= 0) {
        return raw;
    }
    let disc;
    if (item.itemDiscount.type === 'percent') {
        disc = raw * (item.itemDiscount.value / 100);
    } else {
        disc = Math.min(item.itemDiscount.value, raw);  // لا يمكن أن يتجاوز إجمالي الصنف
    }
    return Math.max(0, raw - disc);
}

// v6.1.6 (W12 إصلاح): حساب مبلغ خصم الصنف المطلق (للعرض في الشارة)
function computeItemDiscountAmount(item) {
    if (!item.itemDiscount || !item.itemDiscount.value || item.itemDiscount.value <= 0) return 0;
    const raw = item.price * item.quantity;
    if (item.itemDiscount.type === 'percent') {
        return Math.round(raw * (item.itemDiscount.value / 100) * 100) / 100;
    }
    return Math.min(item.itemDiscount.value, raw);
}

// === حساب الباقي ===
function calculateChange() {
    const paid = parseFloat(document.getElementById('paidAmount').value || 0);
    const cardPaid = parseFloat(document.getElementById('cardAmount')?.value || 0);
    // v6.1.6 (W12 إصلاح): subtotal بعد خصم الأصناف الفردية
    let subtotal = cart.reduce((sum, item) => sum + computeLineTotal(item), 0);
    let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    let total = subtotal + tax - discount;

    const changeDisplay = document.getElementById('changeDisplay');
    const changeAmount = document.getElementById('changeAmount');

    // v6.1.4 (W6 إصلاح): حساب الباقي حسب طريقة الدفع
    let totalPaid = paid;
    if (POS_PAYMENT_METHOD === 'split') {
        totalPaid = paid + cardPaid;
    } else if (POS_PAYMENT_METHOD === 'card') {
        // بطاقة: لا يوجد باقٍ (المدفوع = الإجمالي تلقائياً)
        changeDisplay.style.display = 'none';
        return;
    }

    if (totalPaid > 0 && totalPaid >= total) {
        const change = totalPaid - total;
        changeDisplay.style.display = 'flex';
        changeAmount.textContent = change.toFixed(2) + ' ' + POS_CURRENCY;
    } else {
        changeDisplay.style.display = 'none';
    }
}

// === v6.1.4 (W6 إصلاح): اختيار طريقة الدفع ===
function selectPaymentMethod(method) {
    if (!['cash', 'card', 'split'].includes(method)) return;
    POS_PAYMENT_METHOD = method;

    // تحديث الأزرار البصرية
    document.querySelectorAll('.pos-pm-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.pm === method);
    });

    const cashField = document.getElementById('cashField');
    const cardField = document.getElementById('cardField');
    const paidInput = document.getElementById('paidAmount');
    const cardInput = document.getElementById('cardAmount');
    const quickAmounts = document.getElementById('quickAmounts');

    if (method === 'cash') {
        cashField.style.display = '';
        cardField.style.display = 'none';
        quickAmounts.style.display = '';
        paidInput.value = '';
        if (cardInput) cardInput.value = '';
    } else if (method === 'card') {
        // بطاقة: المبلغ = الإجمالي تلقائياً، لا حاجة لإدخال يدوي
        let subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
        let total = subtotal + tax - discount;
        paidInput.value = total.toFixed(2);
        cashField.style.display = '';
        cardField.style.display = 'none';
        quickAmounts.style.display = 'none';
    } else if (method === 'split') {
        cashField.style.display = '';
        cardField.style.display = '';
        quickAmounts.style.display = '';
        paidInput.value = '';
        if (cardInput) cardInput.value = '';
    }

    calculateChange();
}

// === مبالغ سريعة ===
function setQuickAmount(type) {
    let subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    let total = subtotal + tax - discount;

    const paidInput = document.getElementById('paidAmount');

    if (type === 'exact') {
        paidInput.value = total.toFixed(2);
    } else if (type === 'round') {
        paidInput.value = Math.ceil(total).toFixed(2);
    } else {
        paidInput.value = parseFloat(type).toFixed(2);
    }

    calculateChange();
}

// === تعليق الطلب ===
function holdCart() {
    if (cart.length === 0) return;

    heldCartCounter++;
    const now = new Date();
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    const total = subtotal + tax - discount;

    heldCarts.push({
        id: heldCartCounter,
        items: JSON.parse(JSON.stringify(cart)),
        discount: discount,
        discountType: discountType,  // v6.1.6 (W11): حفظ نوع خصم السلة
        discountValue: discountValue,  // v6.1.6 (W11): حفظ قيمة خصم السلة الخام
        subtotal: subtotal,
        tax: tax,
        total: total,
        time: now.toLocaleTimeString('ar-MA', { hour: '2-digit', minute: '2-digit' }),
        date: now.toLocaleDateString('ar-MA', { day: '2-digit', month: '2-digit', year: 'numeric' }),
        timestamp: now.getTime(),
        itemCount: cart.reduce((sum, item) => sum + item.quantity, 0),
    });

    saveHeldCartsToStorage();
    clearCart();
    updateHeldCartsBadge();
    App.toast('تم تعليق الطلب رقم #' + heldCartCounter, 'info');
}

// === حفظ/تحميل الطلبات المعلقة من localStorage ===
function saveHeldCartsToStorage() {
    try {
        localStorage.setItem('pos_heldCarts', JSON.stringify(heldCarts));
        localStorage.setItem('pos_heldCartCounter', heldCartCounter.toString());
    } catch (e) {}
}

function loadHeldCartsFromStorage() {
    try {
        const saved = localStorage.getItem('pos_heldCarts');
        const savedCounter = localStorage.getItem('pos_heldCartCounter');
        if (saved) {
            heldCarts = JSON.parse(saved);
        }
        if (savedCounter) {
            heldCartCounter = parseInt(savedCounter, 10) || 0;
        }
    } catch (e) {
        heldCarts = [];
        heldCartCounter = 0;
    }
    updateHeldCartsBadge();
}

// === تحديث عداد الطلبات المعلقة ===
function updateHeldCartsBadge() {
    const badge = document.getElementById('heldBadge');
    if (!badge) return;
    const count = heldCarts.length;
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// === عرض الطلبات المعلقة ===
function showHeldCarts() {
    const overlay = document.getElementById('heldOverlay');
    const body = document.getElementById('heldModalBody');
    const footer = document.getElementById('heldModalFooter');
    const countEl = document.getElementById('heldCount');

    countEl.textContent = heldCarts.length;

    if (heldCarts.length === 0) {
        body.innerHTML = '<div class="pos-held-empty">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="56" height="56">' +
                '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>' +
            '</svg>' +
            '<p>لا توجد طلبات معلقة</p>' +
            '<span>يمكنك تعليق طلب من زر "تعليق" في السلة</span>' +
        '</div>';
        footer.style.display = 'none';
    } else {
        let html = '';
        heldCarts.forEach(function(held, index) {
            const itemsHtml = held.items.map(function(item) {
                return '<div class="pos-held-item-row">' +
                    '<span class="pos-held-item-name">' + App.escapeHtml(item.name) + '</span>' +
                    '<span class="pos-held-item-qty">x' + item.quantity + '</span>' +
                    '<span class="pos-held-item-price">' + (item.price * item.quantity).toFixed(2) + ' ' + POS_CURRENCY + '</span>' +
                '</div>';
            }).join('');

            const discountHtml = held.discount > 0
                ? '<div class="pos-held-card-discount">' +
                      '<span>الخصم</span>' +
                      '<span>-' + held.discount.toFixed(2) + ' ' + POS_CURRENCY + '</span>' +
                  '</div>'
                : '';

            html += '<div class="pos-held-card">' +
                '<div class="pos-held-card-header">' +
                    '<div class="pos-held-card-info">' +
                        '<span class="pos-held-card-num">#' + held.id + '</span>' +
                        '<div class="pos-held-card-meta">' +
                            '<span>' + held.time + '</span>' +
                            '<span>' + held.itemCount + ' منتج</span>' +
                        '</div>' +
                    '</div>' +
                    '<span class="pos-held-card-total">' + held.total.toFixed(2) + ' ' + POS_CURRENCY + '</span>' +
                '</div>' +
                discountHtml +
                '<div class="pos-held-card-items">' + itemsHtml + '</div>' +
                '<div class="pos-held-card-actions">' +
                    '<button type="button" class="pos-held-restore-btn" onclick="restoreHeldCart(' + index + ')">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">' +
                            '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>' +
                        '</svg>' +
                        'استعادة' +
                    '</button>' +
                    '<button type="button" class="pos-held-delete-btn" onclick="deleteHeldCart(' + index + ')">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">' +
                            '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>' +
                        '</svg>' +
                        'حذف' +
                    '</button>' +
                '</div>' +
            '</div>';
        });
        body.innerHTML = html;
        footer.style.display = 'flex';
    }

    overlay.style.display = 'flex';
}

// === إغلاق نافذة الطلبات المعلقة ===
function closeHeldCarts(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('heldOverlay').style.display = 'none';
}

// === استعادة طلب معلق ===
function restoreHeldCart(index) {
    if (index < 0 || index >= heldCarts.length) return;

    const held = heldCarts[index];

    // إذا كانت السلة غير فارغة، اطلب تأكيد الاستبدال
    if (cart.length > 0) {
        showConfirmDialog(
            'warning',
            'السلة غير فارغة',
            'السلة الحالية تحتوي على ' + cart.reduce((s, i) => s + i.quantity, 0) + ' منتج. هل تريد استبدالها بالطلب المعلق #' + held.id + '؟',
            'استبدال',
            function() {
                doRestoreHeldCart(index);
            }
        );
    } else {
        doRestoreHeldCart(index);
    }
}

function doRestoreHeldCart(index) {
    const held = heldCarts[index];

    // استعادة السلة
    cart = JSON.parse(JSON.stringify(held.items));
    discount = held.discount || 0;
    // v6.1.6 (W11 إصلاح): استعادة نوع وقيمة خصم السلة
    discountType = held.discountType || 'amount';
    discountValue = held.discountValue || 0;

    // حذف الطلب المعلق
    heldCarts.splice(index, 1);
    saveHeldCartsToStorage();
    updateHeldCartsBadge();

    // إغلاق النافذة
    document.getElementById('heldOverlay').style.display = 'none';

    // تحديث واجهة السلة
    if (discount > 0) {
        document.getElementById('discountValue').value = discountValue;
        setDiscountType(discountType);  // v6.1.6 (W11): تحديث المُبدّل والأزرار
        document.getElementById('discountRow').style.display = 'flex';
        document.getElementById('discountAmount').textContent = '-' + discount.toFixed(2);
    }

    renderCart();

    // في الهاتف، انتقل تلقائياً لتبويب السلة
    if (window.innerWidth <= 768) {
        switchMobileTab('cart');
    }

    App.toast('تم استعادة الطلب المعلق #' + held.id, 'success');
}

// === حذف طلب معلق ===
function deleteHeldCart(index) {
    if (index < 0 || index >= heldCarts.length) return;

    const held = heldCarts[index];
    showConfirmDialog(
        'danger',
        'حذف الطلب المعلق',
        'هل أنت متأكد من حذف الطلب المعلق #' + held.id + '؟ لا يمكن التراجع عن هذا الإجراء.',
        'حذف',
        function() {
            heldCarts.splice(index, 1);
            saveHeldCartsToStorage();
            updateHeldCartsBadge();
            showHeldCarts(); // تحديث العرض
            App.toast('تم حذف الطلب المعلق', 'info');
        }
    );
}

// === حذف كل الطلبات المعلقة ===
function deleteAllHeldCarts() {
    if (heldCarts.length === 0) return;

    showConfirmDialog(
        'danger',
        'حذف جميع الطلبات المعلقة',
        'هل أنت متأكد من حذف جميع الطلبات المعلقة (' + heldCarts.length + ' طلب)؟ لا يمكن التراجع عن هذا الإجراء.',
        'حذف الكل',
        function() {
            heldCarts = [];
            saveHeldCartsToStorage();
            updateHeldCartsBadge();
            showHeldCarts(); // تحديث العرض
            App.toast('تم حذف جميع الطلبات المعلقة', 'info');
        }
    );
}

// === نافذة تأكيد مخصصة ===
function showConfirmDialog(type, title, message, okText, onConfirm) {
    // إزالة أي نافذة تأكيد سابقة
    const existing = document.getElementById('posConfirmOverlay');
    if (existing) existing.remove();

    const iconSvg = type === 'warning'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';

    const overlay = document.createElement('div');
    overlay.id = 'posConfirmOverlay';
    overlay.className = 'pos-confirm-overlay';
    overlay.innerHTML =
        '<div class="pos-confirm-dialog">' +
            '<div class="pos-confirm-icon ' + type + '">' + iconSvg + '</div>' +
            '<h4 class="pos-confirm-title">' + App.escapeHtml(title) + '</h4>' +
            '<p class="pos-confirm-msg">' + App.escapeHtml(message) + '</p>' +
            '<div class="pos-confirm-actions">' +
                '<button type="button" class="pos-confirm-cancel" id="posConfirmCancel">إلغاء</button>' +
                '<button type="button" class="pos-confirm-ok ' + type + '" id="posConfirmOk">' + App.escapeHtml(okText) + '</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    document.getElementById('posConfirmCancel').addEventListener('click', function() {
        overlay.remove();
    });

    document.getElementById('posConfirmOk').addEventListener('click', function() {
        overlay.remove();
        if (typeof onConfirm === 'function') onConfirm();
    });

    // إغلاق بالنقر خارج النافذة
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.remove();
    });
}

// === معالجة الدفع ===
async function processPayment() {
    if (cart.length === 0) return;

    const paid = parseFloat(document.getElementById('paidAmount').value || 0);
    const cardPaid = parseFloat(document.getElementById('cardAmount')?.value || 0);
    // v6.1.6 (W12 إصلاح): subtotal بعد خصم الأصناف الفردية
    let subtotal = cart.reduce((sum, item) => sum + computeLineTotal(item), 0);
    let tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
    let total = subtotal + tax - discount;

    // v6.1.4 (W6 إصلاح): التحقق من صحة الدفع حسب الطريقة المختارة
    let totalPaid = paid;
    if (POS_PAYMENT_METHOD === 'split') {
        totalPaid = paid + cardPaid;
        if (paid <= 0 || cardPaid <= 0) {
            App.toast('<?php echo addslashes(t('pos_split_required')); ?>', 'error');
            return;
        }
    } else if (POS_PAYMENT_METHOD === 'card') {
        totalPaid = paid;  // paidAmount = total تلقائياً
    }

    if (totalPaid < total) {
        App.toast('<?php echo addslashes(t('pos_paid_less_than_total')); ?>', 'error');
        return;
    }

    const payBtn = document.getElementById('payBtn');
    payBtn.disabled = true;
    payBtn.innerHTML = '<div class="spinner" style="width:20px;height:20px;border-top-color:#fff;"></div> <?php echo addslashes(t('pos_processing')); ?>';

    // v6.1.6 (W12 إصلاح): إرسال خصم الصنف الفردي + نوعه في الـ payload
    const items = cart.map(item => ({
        product_id: item.id,
        quantity: item.quantity,
        selling_price: item.price,
        item_discount: computeItemDiscountAmount(item),  // المبلغ المطلق المحسوب في JS (للتحقق جانب الخادم)
        item_discount_type: (item.itemDiscount && item.itemDiscount.value > 0) ? item.itemDiscount.type : 'amount',
        item_discount_value: (item.itemDiscount && item.itemDiscount.value > 0) ? item.itemDiscount.value : 0,
    }));

    try {
        const res = await fetch(POS_BASE_URL + '/api/pos-process-sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': POS_CSRF,
            },
            body: JSON.stringify({
                items: items,
                sale_date: new Date().toISOString().split('T')[0],
                notes: '',
                discount: discount,  // v6.1.6 (W11): المبلغ المطلق للخصم على مستوى السلة (محسوب من type+value)
                discount_type: discountType,  // v6.1.6 (W11): 'amount' | 'percent' — للتحقق جانب الخادم
                discount_value: discountValue,  // v6.1.6 (W11): القيمة الخام التي أدخلها المستخدم
                tax_amount: tax,  // W14 إصلاح: إرسال الضريبة للخادم للمقارنة والتحقق
                paid_amount: totalPaid,  // v6.1.4 (W6): المبلغ الكلي المدفوع
                payment_method: POS_PAYMENT_METHOD,  // v6.1.4 (W6): طريقة الدفع
                cash_amount: POS_PAYMENT_METHOD === 'split' ? paid : totalPaid,  // للدفع المقسّم: النقد فقط
                card_amount: POS_PAYMENT_METHOD === 'split' ? cardPaid : 0,  // للدفع المقسّم: البطاقة فقط
                csrf_token: POS_CSRF,
            }),
        });

        // معالجة خاصة للردود غير JSON (مثل صفحات خطأ HTML)
        let data;
        try {
            const responseText = await res.text();
            data = JSON.parse(responseText);
        } catch (jsonErr) {
            // الخادم أرجع HTML أو شيئاً غير JSON — غالباً خطأ PHP أو إعادة توجيه
            if (res.status === 401) {
                App.toast('<?php echo addslashes(t('pos_session_expired')); ?>', 'error');
            } else if (res.status === 403) {
                App.toast('<?php echo addslashes(t('pos_no_permission')); ?>', 'error');
            } else {
                App.toast('<?php echo addslashes(t('pos_server_error')); ?>'.replace('{status}', res.status), 'error');
            }
            payBtn.disabled = false;
            payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <?php echo t('pos_pay_btn'); ?> (<span id="payBtnTotal">${total.toFixed(2)}</span> ${POS_CURRENCY})`;
            return;
        }

        if (data.success) {
            playSound('success');

            // تحديث CSRF
            if (data.data && data.data.csrf_token) {
                POS_CSRF = data.data.csrf_token;
            }

            // عرض الإيصال
            showReceipt(data.data, paid);

            // نجاح
            payBtn.classList.add('success');
            setTimeout(() => {
                payBtn.classList.remove('success');
                payBtn.disabled = false;
                payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <?php echo t('pos_pay_btn'); ?> (<span id="payBtnTotal">0.00</span> ${POS_CURRENCY})`;
            }, 1000);
        } else {
            // رسالة خطأ مفصلة من الخادم
            // دعم تنسيقين: posJsonError ترسل {message} و ErrorHandler يرسل {error:{message}}
            let errorMsg = data.message || (data.error && data.error.message) || '<?php echo addslashes(t('pos_sale_failed')); ?>';
            // إذا كانت الرسالة تحتوي على أسطر جديدة، اعرض السطر الأول فقط مع تفاصيل
            if (errorMsg.includes('\n')) {
                errorMsg = errorMsg.split('\n')[0];
            }
            App.toast(errorMsg, 'error');
            payBtn.disabled = false;
            payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <?php echo t('pos_pay_btn'); ?> (<span id="payBtnTotal">${total.toFixed(2)}</span> ${POS_CURRENCY})`;
        }
    } catch (err) {
        App.toast('<?php echo addslashes(t('pos_connection_error')); ?>', 'error');
        payBtn.disabled = false;
        payBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> <?php echo t('pos_pay_btn'); ?> (<span id="payBtnTotal">${total.toFixed(2)}</span> ${POS_CURRENCY})`;
    }
}

// === الإيصال ===
function showReceipt(data, paid) {
    // v6.1.6 (W12 إصلاح): عرض خصم الصنف الفردي في الإيصال لو وجد
    const itemsHtml = data.items.map(item => {
        // إن وُجد خصم على الصنف، اعرض السعر قبل/بعد + سطر خصم
        const hasItemDisc = (typeof item.item_discount === 'number') && item.item_discount > 0;
        const lineGross = (typeof item.line_gross === 'number') ? item.line_gross : (item.price * item.quantity);
        const discType = item.item_discount_type || 'amount';
        const discValue = item.item_discount_value || 0;
        const discLabel = hasItemDisc
            ? (discType === 'percent' ? '-' + discValue + '%' : '-' + item.item_discount.toFixed(2) + ' ' + POS_CURRENCY)
            : '';
        return `
            <div class="pos-receipt-item">
                <span class="pos-receipt-item-name">${App.escapeHtml(item.name)}</span>
                <span class="pos-receipt-item-qty">${item.quantity} ${App.escapeHtml(item.unit || '')}</span>
                <span class="pos-receipt-item-total">${item.total.toFixed(2)}</span>
                ${hasItemDisc ? `
                    <span class="pos-receipt-item-discount" style="grid-column:1/-1; font-size:0.75em; color:#92400e; padding-top:2px; border-top:1px dashed #fcd34d; margin-top:2px;">
                        <?php echo t('pos_item_discount'); ?>: ${discLabel} (${lineGross.toFixed(2)} → ${item.total.toFixed(2)})
                    </span>
                ` : ''}
            </div>
        `;
    }).join('');
    document.getElementById('receiptItems').innerHTML = itemsHtml;

    let totalsHtml = `
        <div class="pos-receipt-total-row">
            <span><?php echo t('pos_subtotal_label'); ?></span>
            <span>${data.subtotal.toFixed(2)} ${POS_CURRENCY}</span>
        </div>
    `;

    if (POS_TAX_ENABLED) {
        // W14 إصلاح: استخدم مبلغ الضريبة المحسوبة في الخادم (data.tax_amount) بدل إعادة حسابها في JS
        const taxAmt = (typeof data.tax_amount === 'number') ? data.tax_amount : (data.subtotal * POS_TAX_RATE / 100);
        if (taxAmt > 0) {
            totalsHtml += `
                <div class="pos-receipt-total-row">
                    <span><?php echo t('pos_tax_label'); ?></span>
                    <span>${taxAmt.toFixed(2)} ${POS_CURRENCY}</span>
                </div>
            `;
        }
    }

    if (data.discount > 0) {
        totalsHtml += `
            <div class="pos-receipt-total-row">
                <span><?php echo t('pos_discount_label'); ?></span>
                <span>-${data.discount.toFixed(2)} ${POS_CURRENCY}</span>
            </div>
        `;
    }

    totalsHtml += `
        <div class="pos-receipt-total-row grand-total">
            <span><?php echo t('pos_total_label'); ?></span>
            <span>${data.total.toFixed(2)} ${POS_CURRENCY}</span>
        </div>
        <div class="pos-receipt-total-row">
            <span><?php echo t('pos_paid_label'); ?></span>
            <span>${data.paid.toFixed(2)} ${POS_CURRENCY}</span>
        </div>
    `;

    // v6.1.4 (W6 إصلاح): عرض طريقة الدفع ومكوناتها في الإيصال
    if (data.payment_method) {
        const pmLabel = data.payment_method === 'cash' ? '<?php echo addslashes(t('pos_pm_cash')); ?>'
                      : data.payment_method === 'card' ? '<?php echo addslashes(t('pos_pm_card')); ?>'
                      : '<?php echo addslashes(t('pos_pm_split')); ?>';
        totalsHtml += `
            <div class="pos-receipt-total-row" style="font-size:0.85em; color:#6b7280;">
                <span><?php echo t('pos_payment_method_label'); ?></span>
                <span>${pmLabel}</span>
            </div>
        `;
        if (data.payment_method === 'split' && data.cash_amount !== undefined && data.card_amount !== undefined) {
            totalsHtml += `
                <div class="pos-receipt-total-row" style="font-size:0.8em; color:#6b7280;">
                    <span><?php echo t('pos_pm_cash'); ?></span>
                    <span>${data.cash_amount.toFixed(2)} ${POS_CURRENCY}</span>
                </div>
                <div class="pos-receipt-total-row" style="font-size:0.8em; color:#6b7280;">
                    <span><?php echo t('pos_pm_card'); ?></span>
                    <span>${data.card_amount.toFixed(2)} ${POS_CURRENCY}</span>
                </div>
            `;
        }
    }

    if (data.change > 0) {
        totalsHtml += `
            <div class="pos-receipt-total-row" style="font-weight:700; color:#059669;">
                <span><?php echo t('pos_change_label'); ?></span>
                <span>${data.change.toFixed(2)} ${POS_CURRENCY}</span>
            </div>
        `;
    }

    document.getElementById('receiptTotals').innerHTML = totalsHtml;
    document.getElementById('receiptDateTime').textContent = formatDateStr(data.date) + ' ' + data.time;

    // v6.1.6: عرض رقم الإيصال
    const receiptNoEl = document.getElementById('receiptNo');
    const rNo = data.receipt_no || (data.sale_ids && data.sale_ids.length > 0 ? '#' + data.sale_ids[0] : '');
    if (receiptNoEl && rNo) {
        receiptNoEl.textContent = '<?php echo addslashes(t('pos_receipt_number')); ?>' + ': ' + rNo;
        receiptNoEl.style.display = 'block';
    } else if (receiptNoEl) {
        receiptNoEl.style.display = 'none';
    }

    document.getElementById('receiptOverlay').style.display = 'flex';

    // حفظ بيانات الإيصال للطباعة الحرارية
    lastReceiptData = data;

    // طباعة تلقائية إذا مفعّلة
    if (THERMAL_ENABLED && THERMAL_AUTO_PRINT) {
        setTimeout(function() { printReceiptThermal(); }, 500);
    }
}

function formatDateStr(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    var fmt = window.DATE_FORMAT || 'Y-m-d';
    var yyyy = d.getFullYear();
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var dd = String(d.getDate()).padStart(2, '0');
    switch (fmt) {
        case 'd/m/Y': return dd + '/' + mm + '/' + yyyy;
        case 'm/d/Y': return mm + '/' + dd + '/' + yyyy;
        default: return yyyy + '-' + mm + '-' + dd;
    }
}

function printReceipt() {
    window.print();
}

// === طباعة حرارية (ESC/POS) ===
async function printReceiptThermal() {
    if (!lastReceiptData) {
        App.toast('<?php echo addslashes(t('pos_no_receipt_data')); ?>', 'error');
        return;
    }

    const data = lastReceiptData;

    // بناء بيانات الإيصال للطابعة الحرارية
    const receiptData = {
        storeName: document.getElementById('receiptStoreName') ? document.getElementById('receiptStoreName').textContent : '<?php echo htmlspecialchars(addslashes($store_name ?: getSetting('app_name', t('pos_app_name_default')))); ?>',
        storeAddress: '<?php echo htmlspecialchars(addslashes($store_address)); ?>',
        storePhone: '<?php echo htmlspecialchars(addslashes($store_phone)); ?>',
        dateTime: document.getElementById('receiptDateTime') ? document.getElementById('receiptDateTime').textContent : (data.date + ' ' + data.time),
        cashier: '<?php echo htmlspecialchars(addslashes($_SESSION['user_name'] ?? '')); ?>',
        receiptNo: data.receipt_no || (data.sale_ids && data.sale_ids.length > 0 ? '#' + data.sale_ids[0] : ''),  // v6.1.6: رقم الإيصال (بديل: sale_id إذا لم يوجد)
        items: data.items || [],
        subtotal: data.subtotal || 0,
        // W14 إصلاح: استخدم مبلغ الضريبة من الخادم
        tax: (typeof data.tax_amount === 'number') ? data.tax_amount : (POS_TAX_ENABLED ? (data.subtotal || 0) * (POS_TAX_RATE / 100) : 0),
        discount: data.discount || 0,
        total: data.total || 0,
        paid: data.paid || 0,
        change: data.change || 0,
        payment_method: data.payment_method || 'cash',  // v6.1.4 (W6): طريقة الدفع في الإيصال الحراري
        cash_amount: data.cash_amount || 0,  // v6.1.4 (W6): للدفع المقسّم
        card_amount: data.card_amount || 0,  // v6.1.4 (W6): للدفع المقسّم
        footer: THERMAL_FOOTER || '<?php echo addslashes(t('pos_receipt_thanks')); ?>',
        appName: '<?php echo htmlspecialchars(addslashes(getSetting('app_name', t('pos_app_name_default')))); ?>',
    };

    try {
        // تحديد وضع الطباعة
        let mode = 'browser';
        if (THERMAL_TYPE === 'serial') {
            mode = 'thermal-serial';
        } else if (THERMAL_TYPE === 'network') {
            mode = 'thermal-network';
        } else {
            mode = 'html'; // طباعة HTML حرارية عبر window.print()
        }

        await ThermalPrinter.print(receiptData, mode);
        App.toast('<?php echo addslashes(t('pos_thermal_sent')); ?>', 'success');
    } catch (err) {
        console.error('Thermal print error:', err);
        let errorMsg = '<?php echo addslashes(t('pos_thermal_failed')); ?>';
        if (err.message) {
            if (err.message.includes('Web Serial')) {
                errorMsg = '<?php echo addslashes(t('pos_thermal_no_usb')); ?>';
            } else if (err.message.includes('الاتصال')) {
                errorMsg = '<?php echo addslashes(t('pos_thermal_connection_failed')); ?>';
            } else {
                errorMsg = '<?php echo addslashes(t('pos_thermal_error')); ?>'.replace('{msg}', err.message);
            }
        }
        App.toast(errorMsg, 'error');

        // كبديل، افتح طباعة المتصفح
        if (confirm('<?php echo addslashes(t('pos_thermal_fallback_confirm')); ?>')) {
            window.print();
        }
    }
}

function newSale() {
    document.getElementById('receiptOverlay').style.display = 'none';
    cart = [];
    discount = 0;
    discountType = 'amount';  // v6.1.6 (W11): إعادة تعيين نوع خصم السلة
    discountValue = 0;  // v6.1.6 (W11): إعادة تعيين قيمة خصم السلة
    setDiscountType('amount');  // v6.1.6 (W11): إعادة واجهة المُبدّل
    document.getElementById('paidAmount').value = '';
    // v6.1.4 (W6 إصلاح): إعادة تعيين طريقة الدفع إلى نقداً + إخفاء حقل البطاقة
    if (document.getElementById('cardAmount')) {
        document.getElementById('cardAmount').value = '';
    }
    selectPaymentMethod('cash');
    document.getElementById('discountValue').value = '';
    document.getElementById('discountInput').style.display = 'none';
    renderCart();
    document.getElementById('posSearchInput').focus();

    // العودة لتبويب المنتجات على الهاتف
    switchMobileTab('catalog');

    // إعادة تحميل المنتجات لتحديث المخزون
    loadProducts();
}

// === تبديل السمة ===
document.getElementById('posThemeToggle').addEventListener('click', function() {
    const layout = document.getElementById('posLayout');
    layout.classList.toggle('pos-dark');

    const isDark = layout.classList.contains('pos-dark');
    const icon = document.getElementById('themeIcon');

    if (isDark) {
        icon.innerHTML = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
    } else {
        icon.innerHTML = '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>';
    }

    // حفظ التفضيل محلياً
    localStorage.setItem('pos_theme', isDark ? 'dark' : 'light');
});

// تحميل السمة المحفوظة
(function() {
    const saved = localStorage.getItem('pos_theme');
    if (saved === 'dark' && '<?php echo $pos_theme; ?>' === 'light') {
        document.getElementById('posLayout').classList.add('pos-dark');
    }
})();

// === أصوات ===
// v6.1.6 (W17 إصلاح): AudioContext واحد يُعاد استخدامه — المتصفحات تحدّ من عدد Contexts
let _audioCtx = null;
function playSound(type) {
    if (!POS_SOUND_ENABLED) return;
    try {
        if (!_audioCtx) {
            _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (_audioCtx.state === 'suspended') {
            _audioCtx.resume();
        }
        const oscillator = _audioCtx.createOscillator();
        const gainNode = _audioCtx.createGain();

        oscillator.connect(gainNode);
        gainNode.connect(_audioCtx.destination);

        if (type === 'beep') {
            oscillator.frequency.value = 800;
            gainNode.gain.value = 0.1;
            oscillator.start();
            oscillator.stop(_audioCtx.currentTime + 0.1);
        } else if (type === 'success') {
            oscillator.frequency.value = 1000;
            gainNode.gain.value = 0.1;
            oscillator.start();
            setTimeout(() => { oscillator.frequency.value = 1200; }, 100);
            oscillator.stop(_audioCtx.currentTime + 0.25);
        }
    } catch (e) {}
}

// === اختصارات لوحة المفاتيح ===
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // F2: تركيز البحث
        if (e.key === 'F2') {
            e.preventDefault();
            document.getElementById('posSearchInput').focus();
        }
        // F4: دفع
        if (e.key === 'F4' && cart.length > 0) {
            e.preventDefault();
            processPayment();
        }
        // F8: عملية جديدة
        if (e.key === 'F8') {
            e.preventDefault();
            const overlay = document.getElementById('receiptOverlay');
            if (overlay.style.display !== 'none') {
                newSale();
            }
        }
        // Escape: إغلاق الإيصال أو الطلبات المعلقة
        if (e.key === 'Escape') {
            const confirmOverlay = document.getElementById('posConfirmOverlay');
            if (confirmOverlay) {
                confirmOverlay.remove();
                return;
            }
            const heldOverlay = document.getElementById('heldOverlay');
            if (heldOverlay && heldOverlay.style.display !== 'none') {
                heldOverlay.style.display = 'none';
                return;
            }
            const receiptOverlay = document.getElementById('receiptOverlay');
            if (receiptOverlay.style.display !== 'none') {
                newSale();
            }
        }
    });
}

// === مسح الباركود بالكاميرا ===
document.getElementById('posScanBtn').addEventListener('click', function() {
    if (typeof openBarcodeScanner === 'function') {
        openBarcodeScanner();
    } else {
        App.toast('ماسح الباركود غير متاح', 'warning');
    }
});

// === ردّ مسح الباركود: عند مسح باركود بالكاميرا، ابحث عن المنتج وأضفه ===
window.onBarcodeScanned = function(scannedCode, target) {
    var searchInput = document.getElementById('posSearchInput');
    searchInput.value = scannedCode;
    searchProducts(scannedCode).then(function() {
        // إذا وُجد منتج واحد فقط، أضفه مباشرة
        if (searchResults.length === 1) {
            addToCart(searchResults[0]);
            searchInput.value = '';
            document.getElementById('posSearchDropdown').style.display = 'none';
        } else if (searchResults.length > 1) {
            // عدة نتائج: اعرض القائمة المنسدلة
            App.toast('تم العثور على ' + searchResults.length + ' منتجات — اختر من القائمة', 'info');
            searchInput.focus();
        } else {
            // لا نتائج
            App.toast('لم يتم العثور على منتج بالباركود: ' + scannedCode, 'warning');
            searchInput.value = '';
        }
    });
};

// === مستمع ماسح الباركود بالأجهزة (Hardware Scanner) ===
// ماسحات USB/Bluetooth تُحاكي إدخال لوحة مفاتيح سريع + Enter
(function() {
    var hwBuffer = '';
    var hwTimeout = null;
    var HW_MIN_LENGTH = 3;     // أقل طول للباركود
    var HW_MAX_GAP = 80;       // أقصى فترة بين ضغطتين (ms) — ماسح الأجهزة سريع جداً

    document.addEventListener('keypress', function(e) {
        // تجاهل إذا كان التركيز في حقل إدخال (عدا البحث)
        var activeEl = document.activeElement;
        var isSearchInput = activeEl && activeEl.id === 'posSearchInput';
        var isOtherInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT') && !isSearchInput;

        // إذا كان التركيز في حقل آخر (مثل المبلغ المدفوع)، لا نعترض
        if (isOtherInput) return;

        // تجاهل المفاتيح الوظيفية والاختصارات
        if (e.ctrlKey || e.altKey || e.metaKey) return;
        if (e.key === 'F2' || e.key === 'F4' || e.key === 'F8' || e.key === 'Escape') return;

        // إذا كان حقل البحث نشطاً وكان فارغاً، ابدأ تجميع الباركود
        if (e.key === 'Enter') {
            if (hwBuffer.length >= HW_MIN_LENGTH) {
                e.preventDefault();
                e.stopPropagation();
                var code = hwBuffer.trim();
                hwBuffer = '';
                clearTimeout(hwTimeout);

                // بحث وإضافة المنتج
                searchProducts(code).then(function() {
                    if (searchResults.length === 1) {
                        addToCart(searchResults[0]);
                        searchInput.value = '';
                        document.getElementById('posSearchDropdown').style.display = 'none';
                    } else if (searchResults.length > 1) {
                        searchInput.value = code;
                        searchInput.focus();
                    } else {
                        App.toast('لم يتم العثور على منتج بالباركود: ' + code, 'warning');
                    }
                });
                return;
            }
            // إذا البافر قصير، لا نعترض (Enter عادي)
            hwBuffer = '';
            clearTimeout(hwTimeout);
            return;
        }

        // تجميع الأحرف
        hwBuffer += e.key;
        clearTimeout(hwTimeout);
        hwTimeout = setTimeout(function() {
            // إذا انقضت الفترة بدون إكمال، أفرغ البافر
            hwBuffer = '';
        }, HW_MAX_GAP);
    }, true); // capture phase لاعتراض الحدث مبكراً
})();

// ==============================================================
// v6.1.6 (W15): PWA — Service Worker + Offline Detection
// ==============================================================
function registerPosServiceWorker() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./pos-sw.js', { scope: './' })
            .then(function(reg) {
                console.log('[POS] Service Worker مسجل:', reg.scope);
            })
            .catch(function(err) {
                console.warn('[POS] فشل تسجيل Service Worker:', err);
            });
    }
}

function setupOfflineDetection() {
    const banner = document.getElementById('posOfflineBanner');
    function update() {
        if (banner) banner.style.display = navigator.onLine ? 'none' : 'flex';
    }
    window.addEventListener('online', function() {
        update();
        // إعادة تحميل المنتجات عند عودة الاتصال
        loadProducts();
        App.toast('<?php echo addslashes(t('pos_back_online')); ?>', 'success');
    });
    window.addEventListener('offline', update);
    update(); // فحص أولي
}

// ==============================================================
// v6.1.6 (W16): المرتجعات من POS
// ==============================================================
let returnOriginalData = null; // بيانات العملية الأصلية

function openReturnModal() {
    document.getElementById('returnOverlay').style.display = 'flex';
    document.getElementById('returnStep1').style.display = 'block';
    document.getElementById('returnStep2').style.display = 'none';
    document.getElementById('returnStep3').style.display = 'none';
    document.getElementById('returnSaleIds').value = '';
    document.getElementById('returnSearchResult').innerHTML = '';
    returnOriginalData = null;
    setTimeout(function() { document.getElementById('returnSaleIds').focus(); }, 100);
}

function closeReturnModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('returnOverlay').style.display = 'none';
}

function backToReturnStep1() {
    document.getElementById('returnStep1').style.display = 'block';
    document.getElementById('returnStep2').style.display = 'none';
    returnOriginalData = null;
}

function searchOriginalSale() {
    var query = document.getElementById('returnSaleIds').value.trim();
    if (!query) { App.toast('<?php echo addslashes(t('pos_return_enter_value')); ?>', 'warning'); return; }

    var resultDiv = document.getElementById('returnSearchResult');
    resultDiv.innerHTML = '<div style="text-align:center;padding:20px;color:#6b7280;"><?php echo addslashes(t('pos_return_searching')); ?>...</div>';

    // تحديد نوع البحث: رقم إيصال (YYYYMMDD-NNNN) أو معرّفات مبيعات
    var url;
    if (/^\d{8}-\d{4}$/.test(query)) {
        // رقم إيصال (صيغة YYYYMMDD-NNNN)
        url = POS_BASE_URL + '/api/pos-search-sale.php?receipt_no=' + encodeURIComponent(query);
    } else {
        // معرّفات مبيعات (مفصولة بفاصلة)
        url = POS_BASE_URL + '/api/pos-search-sale.php?ids=' + encodeURIComponent(query);
    }

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                resultDiv.innerHTML = '<div style="color:#dc2626;padding:10px;">' + App.escapeHtml(data.message) + '</div>';
                return;
            }
            returnOriginalData = data.data;
            showReturnStep2(data.data);
        })
        .catch(function(err) {
            resultDiv.innerHTML = '<div style="color:#dc2626;padding:10px;"><?php echo addslashes(t('pos_return_connection_error')); ?></div>';
        });
}

function showReturnStep2(saleData) {
    document.getElementById('returnStep1').style.display = 'none';
    document.getElementById('returnStep2').style.display = 'block';

    var html = '<div style="margin-bottom:12px; font-size:0.85rem; color:#6b7280;">' +
        '<?php echo addslashes(t('pos_return_original_info')); ?>' +
        ' ' + saleData.sale_date + ' | ' + saleData.item_count + ' ' + '<?php echo addslashes(t('pos_return_items')); ?>' +
        ' | <?php echo addslashes(t('pos_total_label')); ?>: ' + saleData.total.toFixed(2) + ' ' + POS_CURRENCY;
    if (saleData.receipt_no) {
        html += ' | <?php echo addslashes(t('pos_receipt_number')); ?>: ' + saleData.receipt_no;
    }
    html += '</div>';

    saleData.items.forEach(function(item) {
        html += '<div class="pos-return-item-row" data-sale-id="' + item.id + '" data-product-id="' + item.product_id + '">' +
            '<input type="checkbox" class="pos-return-item-check" checked>' +
            '<div class="pos-return-item-info">' +
                '<div class="pos-return-item-name">' + App.escapeHtml(item.product_name) + '</div>' +
                '<div class="pos-return-item-meta">' +
                    item.quantity + ' ' + (item.unit || '') + ' &times; ' + parseFloat(item.selling_price).toFixed(2) + ' ' + POS_CURRENCY +
                    ' = ' + parseFloat(item.total).toFixed(2) + ' ' + POS_CURRENCY +
                '</div>' +
            '</div>' +
            '<input type="number" class="pos-return-qty-input" value="' + item.quantity + '" min="0.01" max="' + item.quantity + '" step="0.01">' +
        '</div>';
    });

    html += '<div class="pos-return-total-row">' +
        '<span><?php echo addslashes(t('pos_return_total_label')); ?></span>' +
        '<span id="returnTotalPreview">0.00 ' + POS_CURRENCY + '</span>' +
    '</div>';

    document.getElementById('returnOriginalItems').innerHTML = html;

    // حساب الإجمالي عند التغيير
    document.querySelectorAll('.pos-return-item-row').forEach(function(row) {
        row.querySelector('.pos-return-item-check').addEventListener('change', updateReturnTotal);
        row.querySelector('.pos-return-qty-input').addEventListener('input', updateReturnTotal);
    });
    updateReturnTotal();
}

function updateReturnTotal() {
    var total = 0;
    document.querySelectorAll('.pos-return-item-row').forEach(function(row) {
        var checked = row.querySelector('.pos-return-item-check').checked;
        if (!checked) return;
        var price = parseFloat(returnOriginalData.items.find(function(it) { return it.id == row.dataset.saleId; }).selling_price) || 0;
        var qty = parseFloat(row.querySelector('.pos-return-qty-input').value) || 0;
        total += price * qty;
    });
    var preview = document.getElementById('returnTotalPreview');
    if (preview) preview.textContent = total.toFixed(2) + ' ' + POS_CURRENCY;
}

function processReturn() {
    if (!returnOriginalData) return;

    var items = [];
    var errors = [];

    document.querySelectorAll('.pos-return-item-row').forEach(function(row) {
        var checked = row.querySelector('.pos-return-item-check').checked;
        if (!checked) return;

        var saleId = parseInt(row.dataset.saleId);
        var productId = parseInt(row.dataset.productId);
        var qty = parseFloat(row.querySelector('.pos-return-qty-input').value) || 0;
        var maxQty = parseFloat(returnOriginalData.items.find(function(it) { return it.id == saleId; }).quantity) || 0;

        if (qty <= 0) return;
        if (qty > maxQty) {
            errors.push('<?php echo addslashes(t('pos_return_qty_exceed')); ?>');
            return;
        }

        items.push({
            original_sale_id: saleId,
            product_id: productId,
            return_quantity: qty
        });
    });

    if (errors.length > 0) {
        App.toast(errors.join('\n'), 'error');
        return;
    }
    if (items.length === 0) {
        App.toast('<?php echo addslashes(t('pos_return_select_one')); ?>', 'warning');
        return;
    }

    var reason = document.getElementById('returnReason').value;

    // تأكيد
    showConfirmDialog('danger',
        '<?php echo addslashes(t('pos_return_confirm_title')); ?>',
        '<?php echo addslashes(t('pos_return_confirm_msg')); ?> ' + items.length + ' <?php echo addslashes(t('pos_return_confirm_products')); ?>',
        '<?php echo addslashes(t('pos_return_confirm_btn')); ?>',
        function() { doProcessReturn(items, reason); }
    );
}

function doProcessReturn(items, reason) {
    fetch(POS_BASE_URL + '/api/pos-process-return.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
            csrf_token: csrfToken,
            sale_ids: returnOriginalData.sale_ids,
            items: items,
            reason: reason
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            App.toast(data.message || '<?php echo addslashes(t('pos_return_failed')); ?>', 'error');
            return;
        }
        // تحديث CSRF
        if (data.data && data.data.csrf_token) csrfToken = data.data.csrf_token;

        // عرض النتيجة
        document.getElementById('returnStep2').style.display = 'none';
        document.getElementById('returnStep3').style.display = 'block';

        var html = '<svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" width="48" height="48">' +
            '<polyline points="20 6 9 17 4 12"/>' +
            '</svg>' +
            '<h3 style="color:#16a34a; margin:12px 0 8px;"><?php echo addslashes(t('pos_return_success')); ?></h3>' +
            '<div style="font-size:0.9rem; color:#6b7280;">';
        data.data.items.forEach(function(item) {
            html += item.product_name + ' &times; ' + item.return_quantity + ' = ' + item.refund_total.toFixed(2) + '<br>';
        });
        html += '</div>' +
            '<div style="font-size:1.1rem; font-weight:700; margin-top:12px; color:#dc2626;">' +
            '<?php echo addslashes(t('pos_return_total_label')); ?>: ' + data.data.total_refund.toFixed(2) + ' ' + POS_CURRENCY +
            '</div>';

        document.getElementById('returnResult').innerHTML = html;

        // إعادة تحميل المنتجات (المخزون تغيّر)
        loadProducts();
        playSound('success');
    })
    .catch(function(err) {
        App.toast('خطأ في الاتصال', 'error');
    });
}
</script>

<?php require_once 'includes/barcode-scanner.php'; ?>
<?php require_once 'includes/footer.php'; ?>
