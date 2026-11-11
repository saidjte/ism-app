<?php
/**
 * ========================================================================
 *  صفحة التحقق من الأسعار — PWA مستقلة
 *  تعمل بدون اتصال بالخادم مع دعم مسح الباركود
 *  قابلة للتثبيت على الهاتف كتطبيق مستقل
 * ========================================================================
 *  v4.15.12 — تحسينات PWA والعمل بدون اتصال:
 *  1. إخفاء رسالة التثبيت تلقائياً عند تثبيت التطبيق
 *  2. كشف وضع standalone (التطبيق مثبت) عبر matchMedia
 *  3. Service Worker مُحسّن مع تخزين مسبق للأصول
 *  4. العمل بدون إنترنت مباشرة بعد أول تحميل ناجح
 *  5. رسالة ترحيب عند فتح التطبيق المثبت
 *
 *  يرث إصلاحات v4.15.11:
 *  - عنوان API مطلق (يعمل عند تثبيت PWA على الهاتف)
 *  - إضافة الباركود في البحث (searchProducts)
 *  - فهرس باركود في IndexedDB (DB_VERSION=3)
 *  - عرض رسائل خطأ واضحة عند فشل جلب البيانات
 */

// تحميل الإعدادات — بدون جلسة أو مصادقة (صفحة عامة)
require_once __DIR__ . '/config/database.php';

$currency = getSetting('currency_symbol', 'د.م.');
$appName = getSetting('app_name', 'IS-APP');
$baseURL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($baseURL === '/' || $baseURL === '\\') $baseURL = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$fullBaseURL = $scheme . '://' . $host . $baseURL;

// CSP nonce
$nonce = base64_encode(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#1a56db">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="أسعاري">
    <meta name="description" content="البحث عن أسعار المنتجات — يعمل بدون إنترنت">
    <title>أسعاري — البحث عن الأسعار</title>

    <!-- Manifest للتثبيت كـ PWA -->
    <link rel="manifest" href="<?= htmlspecialchars($baseURL) ?>/price-check-manifest.json">

    <!-- أيقونات PWA -->
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($baseURL) ?>/assets/img/price-check-icon.svg">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($baseURL) ?>/assets/img/price-check-icon-192.png">

    <!-- خط Cairo المحلي -->
    <link rel="stylesheet" href="<?= htmlspecialchars($baseURL) ?>/assets/fonts/cairo.css">

    <style nonce="<?= htmlspecialchars($nonce) ?>">
        :root {
            --primary: #1a56db;
            --primary-dark: #1442a8;
            --primary-light: #e8eefb;
            --success: #059669;
            --success-light: #d1fae5;
            --warning: #d97706;
            --warning-light: #fef3c7;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --radius: 12px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,.06);
            --shadow: 0 2px 8px rgba(0,0,0,.1);
            --shadow-lg: 0 8px 24px rgba(0,0,0,.15);
            --font: 'Cairo', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font);
            background: var(--gray-50);
            color: var(--gray-800);
            min-height: 100dvh;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* === الشريط العلوي === */
        .top-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            padding: 12px 16px;
            padding-top: max(12px, env(safe-area-inset-top));
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
        }

        .top-bar h1 {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-bar h1 svg { width: 24px; height: 24px; }

        .status-badges {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-online { background: rgba(255,255,255,.2); color: #fff; }
        .badge-offline { background: rgba(255,255,255,.15); color: #fecaca; }
        .badge-sync { background: rgba(5,150,105,.8); color: #fff; }
        .badge-updating { background: rgba(217,119,6,.8); color: #fff; }
        .badge-count { background: rgba(255,255,255,.2); color: #fff; }

        /* === منطقة البحث === */
        .search-section {
            padding: 16px;
            background: #fff;
            border-bottom: 1px solid var(--gray-200);
            position: sticky;
            top: 60px;
            z-index: 50;
            box-shadow: var(--shadow-sm);
        }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-input-wrap {
            flex: 1;
            position: relative;
        }

        .search-input-wrap svg {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: var(--gray-400);
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            padding: 12px 40px 12px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-family: var(--font);
            font-size: 15px;
            outline: none;
            transition: border-color .2s;
            background: var(--gray-50);
        }

        .search-input:focus {
            border-color: var(--primary);
            background: #fff;
        }

        .btn-scan {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            border: none;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background .2s, transform .1s;
        }

        .btn-scan:active { transform: scale(.95); }
        .btn-scan:hover { background: var(--primary-dark); }
        .btn-scan svg { width: 24px; height: 24px; }

        .search-hint {
            margin-top: 8px;
            font-size: 12px;
            color: var(--gray-400);
            text-align: center;
        }

        /* === أزرار الفلترة === */
        .filter-row {
            display: flex;
            gap: 6px;
            margin-top: 10px;
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .filter-row::-webkit-scrollbar { display: none; }

        .filter-chip {
            padding: 6px 14px;
            border-radius: 20px;
            border: 1.5px solid var(--gray-200);
            background: #fff;
            font-family: var(--font);
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
            white-space: nowrap;
            transition: all .2s;
        }

        .filter-chip.active {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* === قائمة النتائج === */
        .results-area {
            padding: 8px 16px 100px;
        }

        .result-count {
            font-size: 13px;
            color: var(--gray-500);
            padding: 8px 0;
        }

        .product-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 10px;
            overflow: hidden;
            transition: box-shadow .2s;
            border: 1px solid var(--gray-100);
        }

        .product-card:active {
            box-shadow: var(--shadow);
        }

        .product-main {
            display: flex;
            align-items: stretch;
        }

        /* === صورة المنتج === */
        .product-thumb {
            width: 64px;
            height: 64px;
            flex-shrink: 0;
            border-radius: 8px;
            overflow: hidden;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 10px 0 10px 10px;
        }

        .product-thumb img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .product-thumb-placeholder {
            width: 32px;
            height: 32px;
            color: var(--gray-300);
        }

        .product-color-bar {
            width: 5px;
            flex-shrink: 0;
        }

        .color-cat-0 { background: var(--gray-300); }
        .color-cat-1 { background: #3b82f6; }
        .color-cat-2 { background: #8b5cf6; }
        .color-cat-3 { background: #ec4899; }
        .color-cat-4 { background: #f59e0b; }
        .color-cat-5 { background: #10b981; }
        .color-cat-6 { background: #ef4444; }
        .color-cat-7 { background: #06b6d4; }

        .product-info {
            flex: 1;
            padding: 12px 14px;
            min-width: 0;
        }

        .product-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            font-size: 12px;
            color: var(--gray-500);
        }

        .product-meta span {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .product-price-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            background: var(--primary-light);
            min-width: 90px;
        }

        .price-label {
            font-size: 10px;
            color: var(--primary-dark);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .price-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .price-currency {
            font-size: 11px;
            color: var(--primary);
            font-weight: 600;
        }

        /* === وحدات إضافية === */
        .units-section {
            border-top: 1px dashed var(--gray-200);
            padding: 8px 14px 10px;
            background: var(--gray-50);
        }

        .units-title {
            font-size: 11px;
            color: var(--gray-400);
            font-weight: 600;
            margin-bottom: 6px;
        }

        .unit-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
            font-size: 13px;
        }

        .unit-name {
            color: var(--gray-600);
            font-weight: 500;
        }

        .unit-price {
            color: var(--primary-dark);
            font-weight: 700;
        }

        /* === حالة المخزون === */
        .stock-tag {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .stock-ok { background: var(--success-light); color: var(--success); }
        .stock-low { background: var(--warning-light); color: var(--warning); }
        .stock-out { background: var(--danger-light); color: var(--danger); }

        /* === حالات فارغة === */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-400);
        }

        .empty-state svg { width: 64px; height: 64px; margin-bottom: 16px; }
        .empty-state h3 { font-size: 18px; margin-bottom: 8px; color: var(--gray-500); }
        .empty-state p { font-size: 14px; }

        /* === زر التثبيت === */
        .install-banner {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--gray-800);
            color: #fff;
            padding: 14px 16px;
            padding-bottom: calc(14px + var(--safe-bottom));
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            z-index: 200;
            box-shadow: 0 -4px 12px rgba(0,0,0,.2);
        }

        .install-banner.show { display: flex; }

        .install-banner p {
            font-size: 13px;
            line-height: 1.5;
        }

        .install-btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background: var(--primary);
            color: #fff;
            font-family: var(--font);
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .install-close {
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            padding: 4px;
        }

        /* === مودال مسح الباركود === */
        .scanner-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.85);
            z-index: 300;
            display: none;
            flex-direction: column;
        }

        .scanner-overlay.show { display: flex; }

        .scanner-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            color: #fff;
        }

        .scanner-header h2 { font-size: 16px; }

        .scanner-close {
            background: rgba(255,255,255,.2);
            border: none;
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .scanner-body {
            flex: 1;
            position: relative;
            overflow: hidden;
        }

        #scanner-reader {
            width: 100%;
            height: 100%;
        }

        .scanner-guide {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 260px;
            height: 160px;
            border: 3px solid rgba(255,255,255,.5);
            border-radius: 12px;
            pointer-events: none;
        }

        .scanner-guide::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 2px;
            background: #ef4444;
            animation: scan-line 2s ease-in-out infinite;
        }

        @keyframes scan-line {
            0%, 100% { top: 15%; }
            50% { top: 85%; }
        }

        .scanner-hint {
            text-align: center;
            color: rgba(255,255,255,.7);
            padding: 16px;
            font-size: 14px;
        }

        /* === حالة التحميل === */
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,.95);
            z-index: 400;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-text {
            font-size: 15px;
            color: var(--gray-600);
            font-weight: 600;
        }

        /* === رسالة الخطأ === */
        .error-banner {
            background: var(--danger-light);
            border: 1.5px solid var(--danger);
            color: var(--danger);
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            margin: 8px 16px;
            display: none;
        }

        .error-banner.show { display: block; }

        /* === زر التحديث العائم === */
        .fab-sync {
            position: fixed;
            bottom: 24px;
            left: 24px;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: none;
            background: var(--primary);
            color: #fff;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 90;
            transition: transform .2s;
        }

        .fab-sync:active { transform: scale(.9); }
        .fab-sync.spinning svg { animation: spin 1s linear infinite; }
        .fab-sync svg { width: 24px; height: 24px; }

        /* === آخر تحديث === */
        .last-sync {
            text-align: center;
            font-size: 11px;
            color: var(--gray-400);
            padding: 8px;
        }

        /* === تحريكات === */
        .fade-in {
            animation: fadeIn .3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* === التوافق مع الشاشات الصغيرة === */
        @media (max-width: 380px) {
            .product-main { flex-direction: column; }
            .product-price-col {
                flex-direction: row;
                gap: 8px;
                padding: 8px 14px;
                min-width: auto;
            }
        }
    </style>
</head>
<body>

<!-- شاشة التحميل -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">جاري تحميل البيانات...</div>
</div>

<!-- الشريط العلوي -->
<header class="top-bar">
    <h1>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
            <line x1="3" y1="6" x2="21" y2="6"/>
            <path d="M16 10a4 4 0 01-8 0"/>
        </svg>
        أسعاري
    </h1>
    <div class="status-badges">
        <span class="badge badge-count" id="countBadge">0 منتج</span>
        <span class="badge badge-offline" id="statusBadge">غير متصل</span>
        <a href="<?= htmlspecialchars($baseURL) ?>/index.php" class="badge badge-back" title="العودة إلى التطبيق" style="cursor:pointer;text-decoration:none;color:#fff;background:rgba(255,255,255,.15);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            التطبيق
        </a>
    </div>
</header>

<!-- منطقة البحث -->
<section class="search-section">
    <div class="search-box">
        <div class="search-input-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="search" class="search-input" id="searchInput"
                   placeholder="ابحث بالاسم أو الكود أو الباركود..."
                   autocomplete="off" autocorrect="off" spellcheck="false">
        </div>
        <button class="btn-scan" id="btnScan" title="مسح الباركود">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2"/>
                <rect x="7" y="7" width="10" height="10" rx="1"/>
            </svg>
        </button>
    </div>
    <div class="filter-row" id="filterRow">
        <!-- الفلاتر تُبنى ديناميكياً -->
    </div>
    <div class="search-hint" id="searchHint">اكتب اسم المنتج أو امسح الباركود</div>
</section>

<!-- رسالة خطأ عند فشل جلب البيانات -->
<div class="error-banner" id="errorBanner"></div>

<!-- منطقة النتائج -->
<main class="results-area" id="resultsArea">
    <!-- المنتجات تُعرض هنا -->
</main>

<!-- زر التحديث العائم -->
<button class="fab-sync" id="fabSync" title="تحديث البيانات">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="23 4 23 10 17 10"/>
        <polyline points="1 20 1 14 7 14"/>
        <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
    </svg>
</button>

<!-- شريط تثبيت التطبيق -->
<div class="install-banner" id="installBanner">
    <p>ثبّت <strong>أسعاري</strong> على هاتفك للوصول السريع للأسعار بدون إنترنت</p>
    <button class="install-btn" id="installBtn">تثبيت</button>
    <button class="install-close" id="installClose">&times;</button>
</div>

<!-- مودال مسح الباركود -->
<div class="scanner-overlay" id="scannerOverlay">
    <div class="scanner-header">
        <h2>مسح الباركود</h2>
        <button class="scanner-close" id="scannerClose">&times;</button>
    </div>
    <div class="scanner-body">
        <div id="scanner-reader"></div>
        <div class="scanner-guide"></div>
    </div>
    <div class="scanner-hint">وجّه الكاميرا نحو الباركود — سيتم التعرف تلقائياً</div>
</div>

<!-- مكتبة مسح الباركود -->
<script src="<?= htmlspecialchars($baseURL) ?>/assets/vendor/html5-qrcode.min.js"></script>

<script nonce="<?= htmlspecialchars($nonce) ?>">
// ============================================================
//  تطبيق أسعاري — PWA للبحث عن الأسعار بدون اتصال
//  v4.15.12 — تحسينات PWA والعمل بدون اتصال
// ============================================================

(function() {
    'use strict';

    // === الثوابت ===
    const DB_NAME = 'PriceCheckDB';
    const DB_VERSION = 3;  // v4.15.11: رفع من 2 إلى 3 لإضافة فهرس الباركود
    const STORE_PRODUCTS = 'products';
    const STORE_META = 'meta';
    // [إصلاح] عنوان API مطلق — يعمل عند تثبيت PWA على الهاتف
    const API_URL = '<?= htmlspecialchars($fullBaseURL, ENT_QUOTES) ?>/api/price-check-data.php';

    // === عناصر DOM ===
    const $ = id => document.getElementById(id);
    const searchInput = $('searchInput');
    const resultsArea = $('resultsArea');
    const statusBadge = $('statusBadge');
    const countBadge = $('countBadge');
    const searchHint = $('searchHint');
    const filterRow = $('filterRow');
    const fabSync = $('fabSync');
    const loadingOverlay = $('loadingOverlay');
    const installBanner = $('installBanner');
    const installBtn = $('installBtn');
    const installClose = $('installClose');
    const scannerOverlay = $('scannerOverlay');
    const btnScan = $('btnScan');
    const scannerClose = $('scannerClose');
    const errorBanner = $('errorBanner');

    // === حالة التطبيق ===
    let allProducts = [];
    let categories = [];
    let activeCategory = null;
    let currency = 'د.م.';
    let lastSyncTime = null;
    let isOnline = navigator.onLine;
    let html5QrCode = null;
    let lastFetchError = null;

    // ============================================================
    //  IndexedDB — تخزين البيانات محلياً
    // ============================================================
    function openDB() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = e => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_PRODUCTS)) {
                    const store = db.createObjectStore(STORE_PRODUCTS, { keyPath: 'id' });
                    store.createIndex('code', 'code', { unique: false });
                    store.createIndex('name', 'name', { unique: false });
                    store.createIndex('category', 'category_name', { unique: false });
                    // [إصلاح v4.15.11] إضافة فهرس الباركود
                    store.createIndex('barcode', 'barcode', { unique: false });
                }
                // ترقية من DB_VERSION 2 → 3: إضافة فهرس الباركود
                if (e.oldVersion < 3 && db.objectStoreNames.contains(STORE_PRODUCTS)) {
                    const store = e.target.transaction.objectStore(STORE_PRODUCTS);
                    if (!store.indexNames.contains('barcode')) {
                        store.createIndex('barcode', 'barcode', { unique: false });
                    }
                }
                if (!db.objectStoreNames.contains(STORE_META)) {
                    db.createObjectStore(STORE_META, { keyPath: 'key' });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function saveProducts(products, meta = {}) {
        const db = await openDB();
        const tx = db.transaction([STORE_PRODUCTS, STORE_META], 'readwrite');
        // حذف المنتجات القديمة
        tx.objectStore(STORE_PRODUCTS).clear();
        // إضافة المنتجات الجديدة
        for (const p of products) {
            tx.objectStore(STORE_PRODUCTS).put(p);
        }
        // حفظ البيانات الوصفية
        tx.objectStore(STORE_META).put({ key: 'lastSync', value: meta.timestamp || new Date().toISOString() });
        tx.objectStore(STORE_META).put({ key: 'currency', value: meta.currency || currency });
        tx.objectStore(STORE_META).put({ key: 'count', value: products.length });
        if (meta.last_modified) {
            tx.objectStore(STORE_META).put({ key: 'lastModified', value: meta.last_modified });
        }
        return new Promise((resolve, reject) => {
            tx.oncomplete = () => { db.close(); resolve(); };
            tx.onerror = () => { db.close(); reject(tx.error); };
        });
    }

    async function loadProducts() {
        const db = await openDB();
        const tx = db.transaction(STORE_PRODUCTS, 'readonly');
        const store = tx.objectStore(STORE_PRODUCTS);
        const req = store.getAll();
        return new Promise((resolve, reject) => {
            req.onsuccess = () => { db.close(); resolve(req.result); };
            req.onerror = () => { db.close(); reject(req.error); };
        });
    }

    async function loadMeta() {
        const db = await openDB();
        const tx = db.transaction(STORE_META, 'readonly');
        const store = tx.objectStore(STORE_META);
        const req = store.getAll();
        return new Promise((resolve, reject) => {
            req.onsuccess = () => {
                db.close();
                const result = {};
                for (const item of req.result) {
                    result[item.key] = item.value;
                }
                resolve(result);
            };
            req.onerror = () => { db.close(); reject(req.error); };
        });
    }

    // ============================================================
    //  جلب البيانات من الخادم
    // ============================================================
    async function fetchFromServer() {
        try {
            const resp = await fetch(API_URL, { cache: 'no-cache' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || data.message || 'فشل في جلب البيانات');

            // حفظ في IndexedDB
            await saveProducts(data.products, {
                timestamp: data.timestamp,
                currency: data.currency,
                last_modified: data.last_modified,
            });

            currency = data.currency || 'د.م.';
            lastSyncTime = data.timestamp;
            allProducts = data.products;
            lastFetchError = null;
            hideError();

            return data;
        } catch (err) {
            console.warn('فشل جلب البيانات من الخادم:', err);
            lastFetchError = err.message || String(err);
            throw err;
        }
    }

    // ============================================================
    //  عرض/إخفاء رسائل الخطأ
    // ============================================================
    function showError(msg) {
        errorBanner.textContent = msg;
        errorBanner.classList.add('show');
    }

    function hideError() {
        errorBanner.classList.remove('show');
        errorBanner.textContent = '';
    }

    // ============================================================
    //  التهيئة
    // ============================================================
    async function init() {
        try {
            // محاولة تحميل البيانات المحلية أولاً
            const meta = await loadMeta();
            const localProducts = await loadProducts();

            if (localProducts.length > 0) {
                allProducts = localProducts;
                currency = meta.currency || 'د.م.';
                lastSyncTime = meta.lastSync;
            }

            // محاولة التحديث من الخادم إذا كان متصلاً
            if (navigator.onLine) {
                try {
                    await fetchFromServer();
                } catch (e) {
                    // البيانات المحلية كافية — لكن نعرض تحذير إذا لم تكن هناك بيانات محلية
                    if (allProducts.length === 0) {
                        showError('فشل جلب البيانات من الخادم: ' + (e.message || 'خطأ غير معروف') + '. اضغط زر التحديث للمحاولة مرة أخرى.');
                    }
                }
            } else if (allProducts.length === 0) {
                showError('لا يوجد اتصال بالإنترنت ولا بيانات محلية. اتصل بالإنترنت ثم اضغط زر التحديث.');
            }

            // بناء الفلاتر والعرض
            buildCategories();
            renderResults();
            updateStatus();

        } catch (err) {
            console.error('خطأ في التهيئة:', err);
            // حتى لو فشل كل شيء، نعرض حالة فارغة مع رسالة
            renderEmpty(true);
            showError('خطأ في تهيئة التطبيق: ' + (err.message || 'خطأ غير معروف'));
        } finally {
            loadingOverlay.style.display = 'none';
        }
    }

    // ============================================================
    //  التصنيفات والفلاتر
    // ============================================================
    function buildCategories() {
        const catSet = new Set();
        allProducts.forEach(p => {
            if (p.category_name) catSet.add(p.category_name);
        });
        categories = Array.from(catSet).sort();

        // بناء أزرار الفلاتر
        filterRow.innerHTML = '';
        const allChip = document.createElement('button');
        allChip.className = 'filter-chip active';
        allChip.textContent = 'الكل';
        allChip.onclick = () => setFilter(null, allChip);
        filterRow.appendChild(allChip);

        categories.forEach(cat => {
            const chip = document.createElement('button');
            chip.className = 'filter-chip';
            chip.textContent = cat;
            chip.onclick = () => setFilter(cat, chip);
            filterRow.appendChild(chip);
        });
    }

    function setFilter(cat, chip) {
        activeCategory = cat;
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        renderResults();
    }

    // ============================================================
    //  البحث — [إصلاح v4.15.11] إضافة الباركود
    // ============================================================
    function searchProducts(query) {
        if (!query) return activeCategory
            ? allProducts.filter(p => p.category_name === activeCategory)
            : allProducts;

        const q = query.toLowerCase().trim();
        const terms = q.split(/\s+/);

        return allProducts.filter(p => {
            // فلترة حسب التصنيف أولاً
            if (activeCategory && p.category_name !== activeCategory) return false;

            // [إصلاح] البحث في الاسم + الكود + الباركود + التصنيف
            const haystack = [
                p.name,
                p.code,
                p.barcode || '',      // v4.15.11: إضافة الباركود
                p.category_name || '',
            ].join(' ').toLowerCase();

            return terms.every(t => haystack.includes(t));
        });
    }

    // ============================================================
    //  عرض النتائج
    // ============================================================
    function renderResults() {
        const query = searchInput.value;
        const results = searchProducts(query);

        countBadge.textContent = results.length + ' منتج';

        if (results.length === 0) {
            renderEmpty(allProducts.length === 0);
            return;
        }

        searchHint.textContent = query
            ? `${results.length} نتيجة لـ"${query}"`
            : `${results.length} منتج`;

        let html = '';
        results.forEach((p, i) => {
            const catIdx = categories.indexOf(p.category_name);
            const colorClass = catIdx >= 0 ? `color-cat-${(catIdx % 8) + 1}` : 'color-cat-0';

            // حالة المخزون
            let stockTag = '';
            const qty = parseFloat(p.quantity) || 0;
            const minQty = parseFloat(p.min_quantity) || 0;
            if (qty <= 0) {
                stockTag = '<span class="stock-tag stock-out">نفذ</span>';
            } else if (minQty > 0 && qty <= minQty) {
                stockTag = '<span class="stock-tag stock-low">منخفض</span>';
            } else {
                stockTag = '<span class="stock-tag stock-ok">متوفر</span>';
            }

            // الوحدات الإضافية
            let unitsHTML = '';
            if (p.additional_units && p.additional_units.length > 0) {
                unitsHTML = '<div class="units-section"><div class="units-title">وحدات أخرى</div>';
                p.additional_units.forEach(u => {
                    unitsHTML += `<div class="unit-row">
                        <span class="unit-name">${escHtml(u.name)} (×${u.factor})</span>
                        <span class="unit-price">${formatPrice(u.price)}</span>
                    </div>`;
                });
                unitsHTML += '</div>';
            }

            // عرض سعر البيع — مع حماية من حقل مفقود
            const sellingPrice = p.selling_price != null ? formatPrice(p.selling_price) : '—';

            // صورة المنتج
            let thumbHtml = '';
            if (p.image) {
                const imgSrc = '<?= htmlspecialchars($baseURL) ?>/' + p.image;
                thumbHtml = `<div class="product-thumb"><img src="${imgSrc}" alt="" onerror="this.parentNode.innerHTML='<svg class=\'product-thumb-placeholder\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.5\'><rect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\'/><circle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'/><path d=\'m21 15-5-5L5 21\'/></svg>'"></div>`;
            } else {
                thumbHtml = `<div class="product-thumb"><svg class="product-thumb-placeholder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></div>`;
            }

            html += `
            <div class="product-card fade-in" style="animation-delay: ${Math.min(i * 30, 300)}ms">
                <div class="product-main">
                    <div class="product-color-bar ${colorClass}"></div>
                    ${thumbHtml}
                    <div class="product-info">
                        <div class="product-name">${escHtml(p.name)}</div>
                        <div class="product-meta">
                            ${p.code ? `<span>📦 ${escHtml(p.code)}</span>` : ''}
                            ${p.barcode ? `<span>🔖 ${escHtml(p.barcode)}</span>` : ''}
                            ${p.category_name ? `<span>📂 ${escHtml(p.category_name)}</span>` : ''}
                            ${p.unit ? `<span>📏 ${escHtml(p.unit)}</span>` : ''}
                            ${stockTag}
                        </div>
                    </div>
                    <div class="product-price-col">
                        <div class="price-label">سعر البيع</div>
                        <div class="price-value">${sellingPrice}</div>
                        <div class="price-currency">${escHtml(currency)}</div>
                    </div>
                </div>
                ${unitsHTML}
            </div>`;
        });

        resultsArea.innerHTML = html;
    }

    function renderEmpty(isNoData) {
        if (isNoData) {
            resultsArea.innerHTML = `
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <h3>لا توجد بيانات</h3>
                <p>لم يتم تحميل المنتجات بعد.<br>اتصل بالإنترنت ثم اضغط زر التحديث.</p>
            </div>`;
        } else {
            const query = searchInput.value;
            resultsArea.innerHTML = `
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <h3>لا نتائج</h3>
                <p>لم يتم العثور على منتجات تطابق "${escHtml(query)}"</p>
            </div>`;
        }
    }

    function formatPrice(val) {
        const n = parseFloat(val) || 0;
        return n.toLocaleString('ar-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ============================================================
    //  تحديث الحالة
    // ============================================================
    function updateStatus() {
        if (isOnline) {
            statusBadge.textContent = 'متصل';
            statusBadge.className = 'badge badge-online';
        } else {
            statusBadge.textContent = 'بدون إنترنت';
            statusBadge.className = 'badge badge-offline';
        }
        countBadge.textContent = allProducts.length + ' منتج';
    }

    // ============================================================
    //  مزامنة البيانات
    // ============================================================
    async function syncData() {
        if (!navigator.onLine) {
            showToast('لا يوجد اتصال بالإنترنت');
            return;
        }

        fabSync.classList.add('spinning');
        statusBadge.textContent = 'يُحدّث...';
        statusBadge.className = 'badge badge-updating';
        hideError();

        try {
            await fetchFromServer();
            buildCategories();
            renderResults();
            updateStatus();
            showToast('تم التحديث بنجاح — ' + allProducts.length + ' منتج');
        } catch (e) {
            showToast('فشل التحديث — ' + (e.message || 'خطأ غير معروف'));
            showError('فشل التحديث: ' + (e.message || 'خطأ غير معروف') + '. البيانات المحلية متاحة.');
            updateStatus();
        } finally {
            fabSync.classList.remove('spinning');
        }
    }

    // === Toast بسيط ===
    function showToast(msg) {
        let toast = document.querySelector('.toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'toast';
            toast.style.cssText = `
                position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%);
                background: var(--gray-800); color: #fff; padding: 10px 20px;
                border-radius: 8px; font-size: 13px; font-weight: 600; z-index: 500;
                font-family: var(--font); box-shadow: var(--shadow-lg);
                opacity: 0; transition: opacity .3s;
            `;
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.style.opacity = '1';
        setTimeout(() => { toast.style.opacity = '0'; }, 3000);
    }

    // ============================================================
    //  مسح الباركود
    // ============================================================
    function startScanner() {
        scannerOverlay.classList.add('show');

        if (html5QrCode) {
            html5QrCode.clear();
        }
        html5QrCode = new Html5Qrcode('scanner-reader');

        html5QrCode.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 260, height: 160 } },
            onScanSuccess,
            () => {} // تجاهل أخطاء الإطار
        ).catch(err => {
            console.error('فشل بدء الماسح:', err);
            showToast('لا يمكن الوصول إلى الكاميرا');
            closeScanner();
        });
    }

    function onScanSuccess(decodedText) {
        // إيقاف الماسح
        if (html5QrCode) {
            html5QrCode.stop().catch(() => {});
        }
        scannerOverlay.classList.remove('show');

        // تعبئة حقل البحث والبحث
        searchInput.value = decodedText;
        activeCategory = null;
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        const allChip = filterRow.querySelector('.filter-chip');
        if (allChip) allChip.classList.add('active');
        renderResults();

        // التحقق من وجود نتيجة
        const results = searchProducts(decodedText);
        if (results.length === 0) {
            showToast('لم يتم العثور على منتج بهذا الباركود');
        } else {
            showToast(`تم العثور على ${results.length} منتج`);
        }
    }

    function closeScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear();
                html5QrCode = null;
            }).catch(() => {
                html5QrCode = null;
            });
        }
        scannerOverlay.classList.remove('show');
    }

    // ============================================================
    //  تثبيت PWA — كشف التثبيت وإخفاء الرسالة
    // ============================================================
    let deferredPrompt = null;
    let isInstalled = false;

    // [v4.15.12] كشف هل التطبيق مثبت بالفعل؟
    // الطريقة 1: matchMedia — يعمل في Chrome/Edge/Firefox
    if (window.matchMedia('(display-mode: standalone)').matches) {
        isInstalled = true;
    }
    // الطريقة 2: navigator.standalone — يعمل في Safari (iOS)
    if (window.navigator.standalone === true) {
        isInstalled = true;
    }

    // إذا كان التطبيق مثبتاً، نخفي رسالة التثبيت نهائياً
    if (isInstalled) {
        installBanner.style.display = 'none';
        console.log('[أسعاري] التطبيق مثبت — إخفاء رسالة التثبيت');
    }

    window.addEventListener('beforeinstallprompt', e => {
        // لا نعرض الرسالة إذا كان التطبيق مثبتاً بالفعل
        if (isInstalled) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        deferredPrompt = e;
        // إظهار شريط التثبيت بعد 3 ثوان
        setTimeout(() => {
            if (!isInstalled) {
                installBanner.classList.add('show');
            }
        }, 3000);
    });

    // [v4.15.12] إخفاء الرسالة فور التثبيت
    window.addEventListener('appinstalled', () => {
        isInstalled = true;
        deferredPrompt = null;
        installBanner.classList.remove('show');
        installBanner.style.display = 'none';
        showToast('تم تثبيت أسعاري بنجاح!');
        console.log('[أسعاري] تم تثبيت التطبيق');
    });

    installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        if (outcome === 'accepted') {
            isInstalled = true;
            installBanner.style.display = 'none';
        }
        deferredPrompt = null;
        installBanner.classList.remove('show');
    });

    installClose.addEventListener('click', () => {
        installBanner.classList.remove('show');
    });

    // [v4.15.12] كشف التغيّر من متصفح إلى standalone بعد التثبيت
    window.matchMedia('(display-mode: standalone)').addEventListener('change', e => {
        if (e.matches) {
            isInstalled = true;
            installBanner.style.display = 'none';
            console.log('[أسعاري] تم التبديل إلى وضع standalone');
        }
    });

    // ============================================================
    //  أحداث
    // ============================================================
    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(renderResults, 200);
    });

    fabSync.addEventListener('click', syncData);
    btnScan.addEventListener('click', startScanner);
    scannerClose.addEventListener('click', closeScanner);

    window.addEventListener('online', () => {
        isOnline = true;
        updateStatus();
        // تحديث تلقائي عند استعادة الاتصال
        syncData();
    });

    window.addEventListener('offline', () => {
        isOnline = false;
        updateStatus();
        showToast('تم فقد الاتصال — يعمل بالبيانات المحلية');
    });

    // ============================================================
    //  تسجيل Service Worker
    // ============================================================
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?= htmlspecialchars($baseURL) ?>/price-check-sw.js')
            .then(reg => {
                console.log('Service Worker مسجّل:', reg.scope);
                // التحقق من التحديثات
                reg.addEventListener('updatefound', () => {
                    const newWorker = reg.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'activated') {
                            showToast('تم تحديث التطبيق');
                        }
                    });
                });
            })
            .catch(err => console.warn('فشل تسجيل Service Worker:', err));
    }

    // === بدء التطبيق ===
    init();

})();
</script>

</body>
</html>
