/**
 * ========================================================================
 *  POS Service Worker — v6.1.6 (W15)
 *  تخزين مؤقت للأصول الثابتة + بيانات المنتجات
 *  يسمح بتصفح المنتجات وعرضها بدون إنترنت
 *  عمليات البيع تُرسل فور اتصال الشبكة (لا تُخزّن مؤقتاً)
 * ========================================================================
 */

const POS_SW_VERSION = '6.1.6-w15';
const POS_CACHE_NAME = 'pos-static-v' + POS_SW_VERSION;
const POS_DATA_CACHE = 'pos-data-v1';

// === الأصول الثابتة المراد تخزينها مؤقتاً عند التثبيت ===
const PRECACHE_URLS = [
    './',
    './pos.php',
    './pos-manifest.json',
    './assets/css/design-system.css',
    './assets/css/base.css',
    './assets/css/layout.css',
    './assets/css/forms.css',
    './assets/css/buttons.css',
    './assets/css/components.css',
    './assets/css/tables.css',
    './assets/css/modal.css',
    './assets/css/alerts.css',
    './assets/css/navbar.css',
    './assets/css/mobile.css',
    './assets/css/auth.css',
    './assets/css/pages-caisse.css',
    './assets/css/pages-dashboard.css',
    './assets/css/validation-loading.css',
    './assets/js/utils.js',
    './assets/js/app.js',
    './assets/js/thermal-printer.js',
    './assets/fonts/cairo.css',
    './assets/img/price-check-icon-192.png',
];

// === تثبيت: تخزين الأصول الثابتة ===
self.addEventListener('install', (event) => {
    console.log('[POS-SW] تثبيت v' + POS_SW_VERSION);
    event.waitUntil(
        caches.open(POS_CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_URLS).catch((err) => {
                console.warn('[POS-SW] فشل تخزين بعض الأصول:', err);
            });
        })
    );
    self.skipWaiting();
});

// === تفعيل: حذف الكاش القديم ===
self.addEventListener('activate', (event) => {
    console.log('[POS-SW] تفعيل v' + POS_SW_VERSION);
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key.startsWith('pos-static-') && key !== POS_CACHE_NAME)
                    .map((key) => {
                        console.log('[POS-SW] حذف كاش قديم:', key);
                        return caches.delete(key);
                    })
            );
        }).then(() => self.clients.claim())
    );
});

// === طلبات الشبكة ===
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    const method = event.request.method;

    // === عمليات الكتابة (POST/PUT/DELETE): دائماً عبر الشبكة ===
    if (method !== 'GET') {
        // إذا لا يوجد اتصال، نبلغ الصفحة
        if (!navigator.onLine) {
            event.respondWith(
                new Response(JSON.stringify({
                    success: false,
                    offline: true,
                    message: 'لا يوجد اتصال بالإنترنت — العملية في الانتظار'
                }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' }
                })
            );
            return;
        }
        return; // letting browser handle normally
    }

    // === بيانات المنتجات: Network First → Cache Fallback ===
    if (url.pathname.includes('/api/search_products') ||
        url.pathname.includes('/api/pos-product-images')) {
        event.respondWith(networkFirstThenCache(event.request, POS_DATA_CACHE, 300)); // 5 دقائق
        return;
    }

    // === الأصول الثابتة: Cache First → Network Fallback ===
    if (url.pathname.startsWith('/assets/') ||
        url.pathname.startsWith('/fonts/') ||
        url.pathname.endsWith('.css') ||
        url.pathname.endsWith('.js') ||
        url.pathname.endsWith('.woff2') ||
        url.pathname.endsWith('.woff') ||
        url.pathname.endsWith('.ttf') ||
        url.pathname.endsWith('.png') ||
        url.pathname.endsWith('.jpg') ||
        url.pathname.endsWith('.svg') ||
        url.pathname.endsWith('.webp') ||
        url.pathname.endsWith('.ico')) {
        event.respondWith(cacheFirstThenNetwork(event.request, POS_CACHE_NAME));
        return;
    }

    // === صفحة POS نفسها: Network First → Cache Fallback ===
    if (url.pathname.endsWith('/pos.php') || url.pathname === '/') {
        event.respondWith(networkFirstThenCache(event.request, POS_CACHE_NAME, 3600));
        return;
    }

    // === باقي الطلبات: عبر الشبكة مباشرة (بدون cache) ===
    return;
});

// === استراتيجية: Network First ثم Cache ===
async function networkFirstThenCache(request, cacheName, maxAgeSeconds) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            // استنساخ لأن الـ response يمكن قراءته مرة واحدة فقط
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        // الشبكة فاشلة → نبحث في الكاش
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }
        // لا كاش → نحاول إرجاع قائمة المنتجات المخزنة
        if (request.url.includes('/api/search_products')) {
            const allCached = await caches.open(POS_DATA_CACHE);
            const keys = await allCached.keys();
            // نأخذ أي كاش منتجات متوفر
            for (const key of keys) {
                if (key.url.includes('/api/search_products')) {
                    const cachedResponse = await allCached.match(key);
                    if (cachedResponse) {
                        return new Response(cachedResponse.body, {
                            status: 200,
                            statusText: 'OK (Offline Cache)',
                            headers: cachedResponse.headers
                        });
                    }
                }
            }
        }
        return new Response(JSON.stringify({
            success: false,
            offline: true,
            message: 'لا يوجد اتصال ولا بيانات مخزنة'
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

// === استراتيجية: Cache First ثم Network ===
async function cacheFirstThenNetwork(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        return new Response('Offline', { status: 503 });
    }
}

// === إرسال حالة الاتصال للصفحة ===
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'GET_STATUS') {
        event.ports[0].postMessage({
            version: POS_SW_VERSION,
            online: navigator.onLine
        });
    }
});
