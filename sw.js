/**
 * ========================================================================
 *  Service Worker — إشعارات المتصفح + مزامنة عدم الاتصال
 *  Web Push Notifications + Offline Sync
 * ========================================================================
 */

const SW_VERSION = '6.2.0';

// ─────────────────────────────────
//  IndexedDB — تخزين حالة الإشعارات offline
// ─────────────────────────────────
const IDB_NAME = 'ism-notifications';
const IDB_VERSION = 1;
const STORE_NAME = 'meta';

function openDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(IDB_NAME, IDB_VERSION);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME);
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function getMeta(key) {
    try {
        const db = await openDB();
        return new Promise((resolve) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const req = store.get(key);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
        });
    } catch { return null; }
}

async function setMeta(key, value) {
    try {
        const db = await openDB();
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(value, key);
    } catch { /* صامت */ }
}

// ─────────────────────────────────
//  تثبيت Service Worker
// ─────────────────────────────────
self.addEventListener('install', (event) => {
    console.log('[SW] تثبيت Service Worker v' + SW_VERSION);
    self.skipWaiting();
});

// ─────────────────────────────────
//  تفعيل Service Worker
// ─────────────────────────────────
self.addEventListener('activate', (event) => {
    console.log('[SW] تفعيل Service Worker v' + SW_VERSION);
    event.waitUntil(
        self.clients.claim().then(() => {
            console.log('[SW] تم السيطرة على جميع العملاء');
        })
    );
});

// ─────────────────────────────────
//  استقبال رسائل push من الخادم
// ─────────────────────────────────
self.addEventListener('push', (event) => {
    console.log('[SW] استقبال رسالة Push');

    let data = {
        title: 'إشعار جديد',
        body: 'لديك إشعار جديد في النظام',
        icon: '/assets/img/price-check-icon-192.png',
        badge: '/assets/img/price-check-icon-72.png',
        tag: 'notification',
        url: '/'
    };

    if (event.data) {
        try {
            data = { ...data, ...event.data.json() };
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        tag: data.tag || 'notification',
        data: {
            url: data.url || '/',
            notificationId: data.notificationId || null,
            timestamp: Date.now()
        },
        vibrate: [100, 50, 100],
        actions: [
            { action: 'open', title: 'فتح' },
            { action: 'dismiss', title: 'إغلاق' }
        ],
        requireInteraction: false,
        silent: false
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// ─────────────────────────────────
//  النقر على الإشعار — تتبع Analytics
// ─────────────────────────────────
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] نقر على الإشعار:', event.action);

    event.notification.close();

    // تتبع: نقر على الإشعار
    const notifId = event.notification.data?.notificationId;
    if (notifId) {
        trackEvent(notifId, event.action === 'dismiss' ? 'dismissed' : 'clicked');
    }

    if (event.action === 'dismiss') {
        return;
    }

    const urlToOpen = event.notification.data?.url || '/';

    event.waitUntil(
        self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(urlToOpen);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(urlToOpen);
            }
        })
    );
});

// ─────────────────────────────────
//  إغلاق الإشعار — تتبع Analytics
// ─────────────────────────────────
self.addEventListener('notificationclose', (event) => {
    console.log('[SW] إغلاق الإشعار:', event.notification.tag);
    const notifId = event.notification.data?.notificationId;
    if (notifId) {
        trackEvent(notifId, 'closed');
    }
});

// ─────────────────────────────────
//  رسائل من الصفحة الرئيسية
// ─────────────────────────────────
self.addEventListener('message', (event) => {
    const { type, data } = event.data || {};

    switch (type) {
        case 'SHOW_NOTIFICATION':
            if (data) {
                const options = {
                    body: data.body || '',
                    icon: data.icon || '/assets/img/price-check-icon-192.png',
                    tag: data.tag || 'in-app-notification',
                    data: {
                        url: data.url || '/',
                        notificationId: data.notificationId || null,
                        timestamp: Date.now()
                    },
                    vibrate: [100, 50, 100],
                    requireInteraction: false,
                    silent: false
                };
                self.registration.showNotification(data.title || 'إشعار', options);
            }
            break;

        case 'GET_VERSION':
            event.ports[0].postMessage({ version: SW_VERSION });
            break;

        case 'SAVE_LAST_ID':
            // حفظ آخر معرف إشعار في IndexedDB للمزامنة offline
            if (data?.lastId) {
                setMeta('last_notif_id', data.lastId);
            }
            break;

        case 'NOTIF_ACTION':
            // تتبع تفاعل المستخدم (قراءة/حذف/نقر)
            if (data?.id && data?.action) {
                trackEvent(data.id, data.action);
            }
            break;
    }
});

// ─────────────────────────────────
//  مزامنة عند عودة الاتصال (Background Sync)
// ─────────────────────────────────
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-notifications') {
        console.log('[SW] مزامنة الإشعارات عند عودة الاتصال');
        event.waitUntil(syncMissedNotifications());
    }
});

// ─────────────────────────────────
//  فحص دوري (Periodic Background Sync)
// ─────────────────────────────────
self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'check-notifications') {
        console.log('[SW] فحص دوري للإشعارات');
        event.waitUntil(checkNewNotifications());
    }
});

// ─────────────────────────────────
//  دوال المزامنة
// ─────────────────────────────────

async function syncMissedNotifications() {
    try {
        const lastId = await getMeta('last_notif_id') || 0;
        const response = await fetch('/api/notifications.php?action=poll&last_id=' + lastId);
        if (!response.ok) return;

        const data = await response.json();
        if (data.success && data.notifications?.length > 0) {
            // إشعار المستخدم بالإشعارات الفائتة
            const latest = data.notifications[0];
            const count = data.notifications.length;

            self.registration.showNotification(
                count > 1 ? `لديك ${count} إشعارات جديدة` : (latest.title || 'إشعار جديد'),
                {
                    body: count > 1 ? `آخر: ${latest.message || ''}` : (latest.message || ''),
                    icon: '/assets/img/price-check-icon-192.png',
                    tag: 'synced-notifications',
                    data: { url: '/notifications/' }
                }
            );

            // تحديث آخر معرف
            const maxId = Math.max(...data.notifications.map(n => parseInt(n.id)));
            await setMeta('last_notif_id', maxId);
        }
    } catch (e) {
        console.debug('[SW] فشل مزامنة الإشعارات:', e.message);
    }
}

async function checkNewNotifications() {
    try {
        const response = await fetch('/api/notifications.php?action=unread_count');
        if (response.ok) {
            const data = await response.json();
            if (data.success && data.unread_count > 0) {
                const detailResponse = await fetch('/api/notifications.php?action=poll&last_id=0');
                if (detailResponse.ok) {
                    const detailData = await detailResponse.json();
                    if (detailData.success && detailData.notifications?.length > 0) {
                        const latest = detailData.notifications[0];
                        self.registration.showNotification(latest.title || 'إشعار جديد', {
                            body: latest.message || '',
                            tag: 'notif-' + latest.id,
                            data: { url: '/notifications/' }
                        });
                    }
                }
            }
        }
    } catch (e) {
        console.debug('[SW] فشل الفحص الدوري:', e.message);
    }
}

// ─────────────────────────────────
//  تتبع Analytics
// ─────────────────────────────────

async function trackEvent(notificationId, action) {
    try {
        // حفظ محلياً في IndexedDB
        const events = await getMeta('analytics_events') || [];
        events.push({
            notification_id: notificationId,
            action: action,
            timestamp: Date.now()
        });
        // حفظ آخر 100 حدث فقط
        if (events.length > 100) events.splice(0, events.length - 100);
        await setMeta('analytics_events', events);

        // محاولة إرسال للخادم
        sendAnalyticsBatch();
    } catch { /* صامت */ }
}

async function sendAnalyticsBatch() {
    try {
        const events = await getMeta('analytics_events') || [];
        if (events.length === 0) return;

        // إرسال فقط الأحداث غير المُرسلة
        const response = await fetch('/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=track_analytics&events=' + encodeURIComponent(JSON.stringify(events))
        });

        if (response.ok) {
            const data = await response.json();
            if (data.success) {
                // مسح الأحداث المُرسلة
                await setMeta('analytics_events', []);
            }
        }
    } catch { /* صامت — سيُعاد المحاولة لاحقاً */ }
}
