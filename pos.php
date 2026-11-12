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

                <!-- محدد نوع البحث -->
                <div class="return-search-tabs" style="display:flex; gap:0; margin-bottom:12px; border:1.5px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                    <button type="button" class="return-search-tab active" id="tabReceipt" onclick="switchReturnSearchTab('receipt')" style="flex:1; padding:8px 12px; border:none; cursor:pointer; font-weight:600; font-size:0.82rem; background:#2563eb; color:#fff; transition:all 0.15s;">
                        🧾 <?php echo t('pos_return_by_receipt') ?: 'رقم الإيصال'; ?>
                    </button>
                    <button type="button" class="return-search-tab" id="tabIds" onclick="switchReturnSearchTab('ids')" style="flex:1; padding:8px 12px; border:none; cursor:pointer; font-weight:600; font-size:0.82rem; background:#f1f5f9; color:#64748b; transition:all 0.15s;">
                        🔢 <?php echo t('pos_return_by_ids') ?: 'أرقام الأصناف'; ?>
                    </button>
                </div>

                <!-- بحث برقم الإيصال: تاريخ + رقم -->
                <div id="returnSearchReceipt" style="display:flex; gap:8px; margin-bottom:12px; align-items:center;">
                    <input type="date" id="returnReceiptDate" class="form-control" style="flex:1; max-width:160px;" title="<?php echo t('pos_return_receipt_date') ?: 'تاريخ الإيصال'; ?>">
                    <span style="font-size:1.2rem; color:#94a3b8; font-weight:700;">—</span>
                    <input type="text" id="returnReceiptNum" class="form-control" placeholder="0001" maxlength="4" style="width:80px; text-align:center; font-weight:600; letter-spacing:1px;" title="<?php echo t('pos_return_receipt_num') ?: 'رقم الإيصال'; ?>">
                    <button type="button" class="pos-discount-apply-btn" onclick="searchOriginalSale()"><?php echo t('pos_return_search_btn'); ?></button>
                </div>

                <!-- بحث بأرقام الأصناف -->
                <div id="returnSearchIds" style="display:none; gap:8px; margin-bottom:12px;">
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="returnSaleIds" class="form-control" placeholder="<?php echo t('pos_return_search_placeholder'); ?>" style="flex:1;">
                        <button type="button" class="pos-discount-apply-btn" onclick="searchOriginalSale()"><?php echo t('pos_return_search_btn'); ?></button>
                    </div>
                    <small style="color:#94a3b8; font-size:0.72rem;"><?php echo t('pos_return_ids_hint') ?: 'أدخل معرّفات الأصناف مفصولة بفاصلة (مثال: 101,102,103)'; ?></small>
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

<link rel="stylesheet" href="<?php echo $base_url ?? ''; ?>/assets/css/pages-pos.css?<?php echo $css_version ?? 'v=6.3.5'; ?>">

<script src="<?php echo $base_url ?? ''; ?>/assets/js/thermal-printer.js"></script>
<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// ============================================================================
// v6.3.5 (M3): جسر إعدادات POS — القيم المولدة من PHP فقط
// المنطق الكامل انتقل إلى assets/js/pages-pos.js (ثوابت POS_* / THERMAL_* + كائن PTXT للنصوص)
// ============================================================================
const POS_CURRENCY = <?php echo json_encode(htmlspecialchars($currency), JSON_UNESCAPED_UNICODE); ?>;
const POS_TAX_ENABLED = <?php echo $tax_enabled ? 'true' : 'false'; ?>;
const POS_TAX_RATE = <?php echo intval($tax_rate); ?>;
const POS_AUTO_COMPLETE = <?php echo $pos_auto_complete ? 'true' : 'false'; ?>;
const POS_SOUND_ENABLED = <?php echo $pos_sound_enabled ? 'true' : 'false'; ?>;
let POS_CSRF = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
const POS_BASE_URL = <?php echo json_encode($base_url ?? "", JSON_UNESCAPED_UNICODE); ?>;
const THERMAL_ENABLED = <?php echo $thermal_printer_enabled ? 'true' : 'false'; ?>;
const THERMAL_TYPE = <?php echo json_encode($thermal_printer_enabled ? $thermal_printer_type : '', JSON_UNESCAPED_UNICODE); ?>;
const THERMAL_IP = <?php echo json_encode($thermal_printer_enabled ? htmlspecialchars($thermal_printer_ip) : '', JSON_UNESCAPED_UNICODE); ?>;
const THERMAL_PORT = <?php echo json_encode($thermal_printer_enabled ? htmlspecialchars($thermal_printer_port) : '', JSON_UNESCAPED_UNICODE); ?>;
const THERMAL_WIDTH = <?php echo json_encode($thermal_printer_enabled ? $thermal_printer_width : '', JSON_UNESCAPED_UNICODE); ?>;
const THERMAL_AUTO_PRINT = <?php echo $thermal_auto_print ? 'true' : 'false'; ?>;
const THERMAL_AUTO_CUT = <?php echo $thermal_auto_cut ? 'true' : 'false'; ?>;
const THERMAL_CASH_DRAWER = <?php echo $thermal_cash_drawer ? 'true' : 'false'; ?>;
const THERMAL_FOOTER = <?php echo json_encode($thermal_printer_enabled ? htmlspecialchars($thermal_receipt_footer) : '', JSON_UNESCAPED_UNICODE); ?>;

// نصوص مترجمة (بديل التداخل المباشر في JS الخارجي)
window.PTXT =
<?php
$pos_js_texts = [
    't_pos_item_discount_modal_ph_percent' => t('pos_item_discount_modal_ph_percent'),
    't_pos_item_discount_modal_ph_amount' => t('pos_item_discount_modal_ph_amount'),
    't_pos_discount_invalid' => t('pos_discount_invalid'),
    't_pos_discount_exceeds_total' => t('pos_discount_exceeds_total'),
    't_pos_discount_applied' => t('pos_discount_applied'),
    't_pos_cart_empty' => t('pos_cart_empty'),
    't_pos_cart_empty_hint' => t('pos_cart_empty_hint'),
    't_pos_item_discount_edit' => t('pos_item_discount_edit'),
    't_pos_item_discount_remove' => t('pos_item_discount_remove'),
    't_pos_item_discount_add' => t('pos_item_discount_add'),
    't_pos_discount_value_ph_percent' => t('pos_discount_value_ph_percent'),
    't_pos_discount_value_ph_amount' => t('pos_discount_value_ph_amount'),
    't_pos_split_required' => t('pos_split_required'),
    't_pos_paid_less_than_total' => t('pos_paid_less_than_total'),
    't_pos_processing' => t('pos_processing'),
    't_pos_session_expired' => t('pos_session_expired'),
    't_pos_no_permission' => t('pos_no_permission'),
    't_pos_server_error' => t('pos_server_error'),
    't_pos_pay_btn' => t('pos_pay_btn'),
    't_pos_sale_failed' => t('pos_sale_failed'),
    't_pos_connection_error' => t('pos_connection_error'),
    't_pos_item_discount' => t('pos_item_discount'),
    't_pos_subtotal_label' => t('pos_subtotal_label'),
    't_pos_tax_label' => t('pos_tax_label'),
    't_pos_discount_label' => t('pos_discount_label'),
    't_pos_total_label' => t('pos_total_label'),
    't_pos_paid_label' => t('pos_paid_label'),
    't_pos_pm_cash' => t('pos_pm_cash'),
    't_pos_pm_card' => t('pos_pm_card'),
    't_pos_pm_split' => t('pos_pm_split'),
    't_pos_payment_method_label' => t('pos_payment_method_label'),
    't_pos_change_label' => t('pos_change_label'),
    't_pos_receipt_number' => t('pos_receipt_number'),
    't_pos_no_receipt_data' => t('pos_no_receipt_data'),
    'x_htmlspecialchars_addslas' => htmlspecialchars(addslashes($store_name ?: getSetting('app_name', t('pos_app_name_default')))),
    'x_htmlspecialchars_addslas_2' => htmlspecialchars(addslashes($store_address)),
    'x_htmlspecialchars_addslas_3' => htmlspecialchars(addslashes($store_phone)),
    'x_htmlspecialchars_addslas_4' => htmlspecialchars(addslashes($_SESSION['user_name'] ?? '')),
    't_pos_receipt_thanks' => t('pos_receipt_thanks'),
    'x_htmlspecialchars_addslas_5' => htmlspecialchars(addslashes(getSetting('app_name', t('pos_app_name_default')))),
    't_pos_thermal_sent' => t('pos_thermal_sent'),
    't_pos_thermal_failed' => t('pos_thermal_failed'),
    't_pos_thermal_no_usb' => t('pos_thermal_no_usb'),
    't_pos_thermal_connection_failed' => t('pos_thermal_connection_failed'),
    't_pos_thermal_error' => t('pos_thermal_error'),
    't_pos_thermal_fallback_confirm' => t('pos_thermal_fallback_confirm'),
    'x_pos_theme' => $pos_theme,
    't_pos_back_online' => t('pos_back_online'),
    't_pos_return_searching' => t('pos_return_searching'),
    't_pos_return_select_date_fb' => t('pos_return_select_date') ?: 'اختر تاريخ الإيصال',
    't_pos_return_enter_num_fb' => t('pos_return_enter_num') ?: 'أدخل رقم الإيصال (1-4 أرقام)',
    't_pos_return_enter_value' => t('pos_return_enter_value'),
    't_pos_return_connection_error' => t('pos_return_connection_error'),
    't_pos_return_original_info' => t('pos_return_original_info'),
    't_pos_return_items' => t('pos_return_items'),
    't_pos_return_total_label' => t('pos_return_total_label'),
    't_pos_return_qty_exceed' => t('pos_return_qty_exceed'),
    't_pos_return_select_one' => t('pos_return_select_one'),
    't_pos_return_confirm_title' => t('pos_return_confirm_title'),
    't_pos_return_confirm_msg' => t('pos_return_confirm_msg'),
    't_pos_return_confirm_products' => t('pos_return_confirm_products'),
    't_pos_return_confirm_btn' => t('pos_return_confirm_btn'),
    't_pos_return_failed' => t('pos_return_failed'),
    't_pos_return_success' => t('pos_return_success'),
];
echo json_encode($pos_js_texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
</script>
<script src="<?php echo $base_url ?? ''; ?>/assets/js/pages-pos.js?<?php echo $css_version ?? 'v=6.3.5'; ?>"></script>

<?php require_once 'includes/barcode-scanner.php'; ?>
<?php require_once 'includes/footer.php'; ?>
