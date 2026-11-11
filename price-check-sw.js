/**
 * ========================================================================
 *  Service Worker لتطبيق "أسعاري" — PWA للبحث عن الأسعار
 *  يخزّن الصفحة والبيانات محلياً للعمل بدون اتصال
 *  v4.15.12 — تحسينات العمل بدون اتصال:
 *  1. تخزين مسبق لكل الأصول (App Shell)
 *  2. استراتيجية Cache First للملفات الثابتة
 *  3. Network First مع تراجع للكاش لبيانات API
 *  4. تخزين صفحة price-check.php نفسها للعمل offline
 * ========================================================================
 */

const CACHE_NAME = 'price-check-v4.15.12';
const DATA_CACHE = 'price-check-data-v4.15.12';

// الملفات التي يجب تخزينها مسبقاً (App Shell)
// استخدام مسارات نسبية — تعمل على أي خادم
const PRECACHE_URLS = [
    './price-check.php',
    './api/price-check-data.php',
    './assets/vendor/html5-qrcode.min.js',
    './assets/fonts/cairo.css',
    './assets/fonts/cairo-400.ttf',
    './assets/fonts/cairo-600.ttf',
    './assets/fonts/cairo-700.ttf',
    './assets/fonts/cairo-800.ttf',
    './assets/fonts/cairo-900.ttf',
    './price-check-manifest.json',
    './assets/img/price-check-icon.svg',
    './assets/img/price-check-icon-192.png',
    './assets/img/price-check-icon-512.png',
];

// تثبيت — تخزين الملفات الأساسية
self.addEventListener('install', event => {
    console.log('[SW] تثبيت Service Worker v4.15.12...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[SW] تخزين الملفات الأساسية...');
                // تخزين الملفات واحداً تلو الآخر لتجنب فشل كامل
                return Promise.allSettled(
                    PRECACHE_URLS.map(url =>
                        cache.add(url).catch(err => {
                            console.warn('[SW] فشل تخزين:', url, err.message);
                        })
                    )
                );
            })
            .then(() => {
                console.log('[SW] تم التخزين المسبق — تفعيل فوري');
                return self.skipWaiting();
            })
    );
});

// تفعيل — تنظيف الكاش القديم والسيطرة على جميع العملاء
self.addEventListener('activate', event => {
    console.log('[SW] تفعيل Service Worker v4.15.12...');
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME && key !== DATA_CACHE)
                    .map(key => {
                        console.log('[SW] حذف كاش قديم:', key);
                        return caches.delete(key);
                    })
            );
        }).then(() => {
            console.log('[SW] السيطرة على جميع العملاء...');
            return self.clients.claim();
        })
    );
});

// استراتيجية الطلبات
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // تجاهل الطلبات غير GET
    if (event.request.method !== 'GET') return;

    // تجاهل طلبات المصادقة والجلسات
    if (url.pathname.includes('login') || url.pathname.includes('auth') || url.pathname.includes('csrf')) return;

    // API البيانات — Network First مع تراجع للكاش
    if (url.pathname.endsWith('price-check-data.php') || url.pathname.includes('api/price-check-data')) {
        event.respondWith(networkFirstThenCache(event.request, DATA_CACHE));
        return;
    }

    // صفحة price-check.php — Network First مع تراجع للكاش
    if (url.pathname.endsWith('price-check.php')) {
        event.respondWith(networkFirstThenCache(event.request, CACHE_NAME));
        return;
    }

    // الملفات الثابتة (CSS, JS, fonts, images) — Cache First مع تحديث خلفي
    if (isStaticAsset(url.pathname)) {
        event.respondWith(cacheFirstWithBackgroundUpdate(event.request, CACHE_NAME));
        return;
    }

    // الصفحة الرئيسية — إعادة توجيه لـ price-check.php
    if (url.pathname === '/' || url.pathname.endsWith('/')) {
        event.respondWith(
            caches.match('./price-check.php').then(cached => {
                if (cached) return cached;
                return fetch(event.request);
            })
        );
        return;
    }
});

// === Network First ثم Cache ===
// يحاول جلب البيانات من الشبكة أولاً؛ إذا فشل يرجع للكاش
async function networkFirstThenCache(request, cacheName) {
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (err) {
        // البحث في كل مخازن الكاش
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            console.log('[SW] استخدام النسخة المخزنة:', request.url);
            return cachedResponse;
        }

        // إذا كانت صفحة HTML، أعد النسخة المخزنة من price-check.php
        if (request.headers.get('Accept')?.includes('text/html')) {
            const fallback = await caches.match('./price-check.php');
            if (fallback) return fallback;
        }

        // إذا كان طلب API، أعد رد JSON بالبيانات الفارغة
        if (request.url.includes('price-check-data')) {
            return new Response(JSON.stringify({
                success: false,
                error: 'غير متصل بالإنترنت',
                products: [],
                offline: true
            }), {
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
                status: 200
            });
        }

        return new Response('غير متصل', { status: 503, statusText: 'Offline' });
    }
}

// === Cache First مع تحديث خلفي ===
// يقدم النسخة المخزنة فوراً ثم يحدّثها في الخلفية
async function cacheFirstWithBackgroundUpdate(request, cacheName) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
        // تحديث في الخلفية (Stale-While-Revalidate)
        fetch(request).then(networkResponse => {
            if (networkResponse.ok) {
                caches.open(cacheName).then(cache => cache.put(request, networkResponse));
            }
        }).catch(() => {});
        return cachedResponse;
    }

    // لا توجد نسخة مخزنة — جلب من الشبكة
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (err) {
        return new Response('', { status: 503, statusText: 'Offline' });
    }
}

// === هل الملف ثابت؟ ===
function isStaticAsset(pathname) {
    return /\.(css|js|ttf|woff2?|png|jpg|jpeg|svg|gif|ico|webp|json)$/i.test(pathname)
        && !pathname.includes('price-check-data');
}

// === رسائل من الصفحة ===
self.addEventListener('message', event => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data?.type === 'CLEAR_CACHE') {
        caches.keys().then(keys => {
            keys.forEach(key => caches.delete(key));
        });
    }

    // طلب تحديث كاش معين
    if (event.data?.type === 'CACHE_URLS' && event.data.urls) {
        caches.open(CACHE_NAME).then(cache => {
            event.data.urls.forEach(url => {
                cache.add(url).catch(() => {});
            });
        });
    }
});
