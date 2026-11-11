<?php
/**
 * ===== PWA Manifest — ديناميكي =====
 * يُولّد manifest للـ PWA بمسارات نسبية
 * المسارات النسبية تُحلّل تلقائياً حسب موقع الملف
 * مثلاً: ./index.php → /ism-app/index.php (لو الملف في /ism-app/)
 */

// اسم التطبيق
$app_name = 'إدارة المخزون';
if (function_exists('getSetting')) {
    try { $app_name = getSetting('app_name', $app_name); } catch (\Throwable $e) {}
}

// اللغة
$lang = 'ar';
$dir = 'rtl';
if (function_exists('lang')) { $lang = lang(); }
if (function_exists('langDir')) { $dir = langDir(); }

$manifest = [
    'name'             => $app_name,
    'short_name'       => 'ISM',
    'description'      => 'نظام إدارة المخزون والمبيعات',
    'start_url'        => './index.php',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#ffffff',
    'theme_color'      => '#2563eb',
    'dir'              => $dir,
    'lang'             => $lang,
    'categories'       => ['business', 'productivity'],
    'icons'            => [
        ['src' => './assets/img/price-check-icon-72.png',   'sizes' => '72x72',    'type' => 'image/png'],
        ['src' => './assets/img/price-check-icon-96.png',   'sizes' => '96x96',    'type' => 'image/png'],
        ['src' => './assets/img/price-check-icon-128.png',  'sizes' => '128x128',  'type' => 'image/png'],
        ['src' => './assets/img/price-check-icon-144.png',  'sizes' => '144x144',  'type' => 'image/png'],
        ['src' => './assets/img/price-check-icon-152.png',  'sizes' => '152x152',  'type' => 'image/png'],
        ['src' => './assets/img/price-check-icon-192.png',  'sizes' => '192x192',  'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => './assets/img/price-check-icon-384.png',  'sizes' => '384x384',  'type' => 'image/png'],
        ['src' => './assets/img/price-check-icon-512.png',  'sizes' => '512x512',  'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
