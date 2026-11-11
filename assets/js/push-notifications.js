/**
 * ===================================
 *  إشعارات المتصفح — Browser Push Notifications
 *  PushNotificationSystem — Frontend module
 * ===================================
 *
 *  المعمارية:
 *  1. تسجيل Service Worker (sw.js)
 *  2. طلب إذن الإشعارات من المستخدم
 *  3. حفظ بيانات الاشتراك في قاعدة البيانات
 *  4. عند وصول إشعار جديد عبر polling → عرضه كـ Browser Notification
 *  5. دعم كامل لـ Web Push Protocol (VAPID)
 */

const PushNotificationSystem = {
    /** مفتاح VAPID العام — يُحمّل من إعدادات الخادم */
    vapidPublicKey: null,

    /** حالة التسجيل */
    isRegistered: false,
    swRegistration: null,
    pushSubscription: null,

    /** حالة الإذن */
    permissionState: 'default',

    /** إعدادات */
    enabled: false,

    /** حالة تحميل VAPID */
    vapidLoaded: false,

    /** نوع الجهاز */
    isMobile: false,
    isAndroid: false,
    isIOS: false,

    /** هل التطبيق يعمل كـ PWA؟ */
    isStandalone: false,

    // ─────────────────────────────────
    //  التهيئة
    // ─────────────────────────────────

    /**
     * تهيئة نظام إشعارات المتصفح
     */
    async init() {
        // اكتشاف نوع الجهاز
        this.detectDevice();

        // فحص دعم المتصفح
        if (!this.isSupported()) {
            this.handleUnsupported();
            return;
        }

        // فحص HTTPS — تحذير فقط (لا نمنع التهيئة — قد يعمل على شبكة محلية)
        const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        if (!isSecure) {
            console.warn('PushNotif: Push API يتطلب HTTPS عادةً — البروتوكول الحالي:', location.protocol);
        }

        // فحص تفعيل الإعداد
        this.enabled = await this.isPushEnabled();
        if (!this.enabled) {
            console.info('PushNotif: إشعارات المتصفح غير مفعّلة في الإعدادات');
            return;
        }

        // تحميل مفتاح VAPID من الخادم
        await this.loadVapidKey();

        // تسجيل Service Worker
        try {
            await this.registerServiceWorker();
        } catch (err) {
            console.error('PushNotif: فشل تسجيل Service Worker:', err.message);
            this.updatePermissionUI();
            return;
        }

        // فحص حالة الإذن
        this.permissionState = Notification.permission;

        // إذا كان الإذن ممنوحاً، محاولة إنشاء اشتراك Push
        if (this.permissionState === 'granted') {
            await this.subscribePush();
        }

        // تحديث واجهة المستخدم
        this.updatePermissionUI();

        // ربط مع نظام الإشعارات الداخلي
        this.connectToNotifSystem();

        console.log('PushNotif: تم التهيئة بنجاح — الإذن:', this.permissionState, '— VAPID:', this.vapidLoaded ? 'متوفر' : 'غير متوفر', '— جهاز:', this.isMobile ? (this.isAndroid ? 'Android' : 'iOS') : 'سطح مكتب');
    },

    // ─────────────────────────────────
    //  اكتشاف الجهاز
    // ─────────────────────────────────

    /**
     * اكتشاف نوع الجهاز والمتصفح
     */
    detectDevice() {
        const ua = navigator.userAgent;
        this.isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(ua);
        this.isAndroid = /Android/i.test(ua);
        this.isIOS = /iPhone|iPad|iPod/i.test(ua);
        this.isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true
            || document.referrer.includes('android-app://');
    },

    // ─────────────────────────────────
    //  تحميل مفتاح VAPID
    // ─────────────────────────────────

    /**
     * تحميل مفتاح VAPID العام من إعدادات الخادم
     */
    async loadVapidKey() {
        try {
            const url = this.buildUrl('/api/push-subscribe.php');
            const data = await App.get(url, { action: 'vapid_key' });
            if (data.success && data.vapid_public_key) {
                this.vapidPublicKey = data.vapid_public_key;
                this.vapidLoaded = true;
                console.log('PushNotif: تم تحميل مفتاح VAPID بنجاح');
            } else {
                console.info('PushNotif: مفتاح VAPID غير مُعد — إشعارات Push ستكون محدودة');
            }
        } catch (e) {
            console.info('PushNotif: فشل تحميل مفتاح VAPID —', e.message);
        }
    },

    // ─────────────────────────────────
    //  فحص الدعم
    // ─────────────────────────────────

    /**
     * هل المتصفح يدعم إشعارات Push؟
     */
    isSupported() {
        return ('serviceWorker' in navigator) &&
               ('PushManager' in window) &&
               ('Notification' in window);
    },

    /**
     * معالجة المتصفحات غير المدعومة
     */
    handleUnsupported() {
        const reasons = [];

        if (!('serviceWorker' in navigator)) {
            reasons.push('Service Worker غير مدعوم');
        }
        if (!('PushManager' in window)) {
            reasons.push('Push API غير مدعوم');
        }
        if (!('Notification' in window)) {
            reasons.push('Notification API غير مدعوم');
        }

        // iOS Safari — لا يدعم Push إلا في وضع PWA
        if (this.isIOS && !this.isStandalone) {
            console.info('PushNotif: iOS Safari لا يدعم Push إلا كـ PWA (إضافة للشاشة الرئيسية)');
            this.updatePermissionUIWithMessage(
                'لتفعيل الإشعارات على iPhone، أضف التطبيق للشاشة الرئيسية ثم افتحه من هناك',
                'ios-pwa'
            );
            return;
        }

        console.info('PushNotif: المتصفح لا يدعم إشعارات Push —', reasons.join('، '));
        this.updatePermissionUI();
    },

    /**
     * هل الإعداد مفعّل في النظام؟
     */
    async isPushEnabled() {
        try {
            const url = this.buildUrl('/api/push-subscribe.php');
            const data = await App.get(url, { action: 'status' });
            return data.success && data.push_enabled;
        } catch (e) {
            return false;
        }
    },

    // ─────────────────────────────────
    //  تسجيل Service Worker
    // ─────────────────────────────────

    /**
     * تسجيل Service Worker
     */
    async registerServiceWorker() {
        const swUrl = this.buildUrl('/sw.js');

        try {
            this.swRegistration = await navigator.serviceWorker.register(swUrl, {
                scope: '/'
            });
        } catch (regErr) {
            // محاولة ثانية بدون scope
            try {
                this.swRegistration = await navigator.serviceWorker.register(swUrl);
            } catch (regErr2) {
                throw regErr2;
            }
        }

        // التحقق من التحديثات
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            console.log('PushNotif: تم تحديث Service Worker');
        });

        // الاستماع للرسائل من Service Worker
        navigator.serviceWorker.addEventListener('message', (event) => {
            this.handleSWMessage(event.data);
        });

        // انتظار تفعيل Service Worker
        if (this.swRegistration.installing) {
            await new Promise((resolve) => {
                this.swRegistration.installing.addEventListener('statechange', (e) => {
                    if (e.target.state === 'activated') resolve();
                });
            });
        } else if (this.swRegistration.waiting) {
            // هناك SW ينتظر التفعيل — حاول تنشيطه
            if (this.swRegistration.waiting.state === 'installed') {
                this.swRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
        }

        this.isRegistered = true;
        console.log('PushNotif: تم تسجيل Service Worker بنجاح');
    },

    // ─────────────────────────────────
    //  طلب الإذن
    // ─────────────────────────────────

    /**
     * طلب إذن الإشعارات من المستخدم
     */
    async requestPermission() {
        if (!this.isSupported()) {
            // رسالة خاصة بـ iOS
            if (this.isIOS && !this.isStandalone) {
                App.toast('لتفعيل الإشعارات على iPhone، أضف التطبيق للشاشة الرئيسية من زر المشاركة ثم افتحه من هناك', 'warning', 8000);
                return false;
            }
            App.toast('المتصفح لا يدعم إشعارات Push', 'error');
            return false;
        }

        // فحص HTTPS — تحذير مع محاولة
        const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        if (!isSecure) {
            console.warn('PushNotif: المحاولة على HTTP — قد يفشل الاشتراك في بعض المتصفحات');
            // لا نمنع — بعض الشبكات المحلية تسمح به
        }

        // فحص VAPID
        if (!this.vapidPublicKey) {
            App.toast('مفاتيح VAPID غير مُعدة — يرجى إعدادها من إعدادات النظام أولاً', 'warning', 6000);
            return false;
        }

        // تسجيل Service Worker إذا لم يتم بعد
        if (!this.swRegistration) {
            try {
                await this.registerServiceWorker();
            } catch (err) {
                App.toast('فشل تسجيل Service Worker: ' + err.message, 'error', 6000);
                return false;
            }
        }

        if (Notification.permission === 'granted') {
            this.permissionState = 'granted';
            await this.subscribePush();
            this.updatePermissionUI();
            return true;
        }

        if (Notification.permission === 'denied') {
            App.toast('تم رفض إذن الإشعارات سابقاً — يجب تفعيله من إعدادات المتصفح', 'warning', 6000);
            this.permissionState = 'denied';
            this.updatePermissionUI();
            return false;
        }

        // طلب الإذن
        let result;
        try {
            result = await Notification.requestPermission();
        } catch (permErr) {
            console.error('PushNotif: فشل طلب الإذن:', permErr);
            App.toast('فشل طلب إذن الإشعارات', 'error');
            return false;
        }

        this.permissionState = result;

        if (result === 'granted') {
            await this.subscribePush();
            this.updatePermissionUI();
            App.toast('تم تفعيل إشعارات المتصفح بنجاح ✅', 'success');
            return true;
        } else if (result === 'denied') {
            App.toast('تم رفض إذن الإشعارات', 'error');
            this.updatePermissionUI();
            return false;
        } else {
            App.toast('تم إغلاق طلب الإذن بدون اختيار', 'info');
            return false;
        }
    },

    // ─────────────────────────────────
    //  اشتراك Push
    // ─────────────────────────────────

    /**
     * إنشاء اشتراك Push وحفظه في قاعدة البيانات
     */
    async subscribePush() {
        if (!this.swRegistration) return;

        try {
            // فحص الاشتراك الحالي
            let subscription = await this.swRegistration.pushManager.getSubscription();

            if (!subscription) {
                // إنشاء اشتراك جديد
                if (!this.vapidPublicKey) {
                    console.warn('PushNotif: لا يوجد مفتاح VAPID — لا يمكن إنشاء اشتراك');
                    App.toast('إشعارات Push تتطلب إعداد مفاتيح VAPID من المسؤول', 'warning', 6000);
                    return;
                }

                const options = {
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
                };

                try {
                    subscription = await this.swRegistration.pushManager.subscribe(options);
                } catch (subscribeErr) {
                    console.error('PushNotif: فشل الاشتراك:', subscribeErr.name, subscribeErr.message);

                    // تشخيص السبب
                    let userMsg = 'فشل الاشتراك في إشعارات Push';
                    const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';

                    if (subscribeErr.name === 'NotAllowedError') {
                        userMsg = 'تم رفض إذن الإشعارات — فعّله من إعدادات المتصفح';
                    } else if (!isSecure) {
                        // السبب الأكثر احتمالاً: لا HTTPS
                        userMsg = 'إشعارات Push تتطلب HTTPS — افتح الموقع بـ https:// أو فعّل SSL في الاستضافة';
                    } else if (subscribeErr.message.includes('applicationServerKey') || subscribeErr.message.includes('VAPID')) {
                        userMsg = 'مفتاح VAPID غير صالح — تواصل مع المسؤول';
                    } else if (subscribeErr.message.includes('registration') || subscribeErr.message.includes('subscribe') || subscribeErr.message.includes('Permission')) {
                        userMsg = 'فشل التسجيل — تأكد من اتصال الإنترنت وجرّب تحديث الصفحة';
                    }

                    App.toast(userMsg, 'error', 6000);
                    return;
                }
            }

            this.pushSubscription = subscription;

            // حفظ الاشتراك في قاعدة البيانات
            const saved = await this.saveSubscription(subscription);

            if (saved) {
                console.log('PushNotif: تم الاشتراك وحفظه بنجاح');
            }
        } catch (err) {
            console.error('PushNotif: فشل الاشتراك:', err.message);
        }
    },

    /**
     * إلغاء اشتراك Push
     */
    async unsubscribePush() {
        if (!this.pushSubscription) return;

        try {
            await this.pushSubscription.unsubscribe();

            // حذف من قاعدة البيانات
            const url = this.buildUrl('/api/push-subscribe.php');
            await App.post(url, {
                action: 'unsubscribe',
                endpoint: this.pushSubscription.endpoint,
                csrf_token: this.getCsrfToken()
            });

            this.pushSubscription = null;
            this.updatePermissionUI();
            App.toast('تم إلغاء إشعارات المتصفح', 'info');
        } catch (err) {
            console.error('PushNotif: فشل إلغاء الاشتراك:', err.message);
        }
    },

    /**
     * حفظ بيانات الاشتراك في قاعدة البيانات
     */
    async saveSubscription(subscription) {
        try {
            const subscriptionJson = subscription.toJSON();
            const url = this.buildUrl('/api/push-subscribe.php');

            const result = await App.post(url, {
                action: 'subscribe',
                endpoint: subscriptionJson.endpoint,
                p256dh: subscriptionJson.keys?.p256dh || '',
                auth: subscriptionJson.keys?.auth || '',
                browser: this.getBrowserName(),
                csrf_token: this.getCsrfToken()
            });

            return result && result.success;
        } catch (err) {
            console.error('PushNotif: فشل حفظ الاشتراك:', err.message);
            return false;
        }
    },

    // ─────────────────────────────────
    //  عرض الإشعارات
    // ─────────────────────────────────

    /**
     * عرض إشعار في المتصفح
     * @param {Object} notif بيانات الإشعار { title, body, icon, tag, url, notificationId }
     */
    async showBrowserNotification(notif) {
        // التحقق من الإذن
        if (Notification.permission !== 'granted') return;

        // إذا كان Service Worker مسجلاً، أرسل الإشعار عبره
        if (this.swRegistration && this.swRegistration.active) {
            this.swRegistration.active.postMessage({
                type: 'SHOW_NOTIFICATION',
                data: {
                    title: notif.title || (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.new_notification ? NOTIF_I18N.new_notification : 'New notification'),
                    body: notif.message || notif.body || '',
                    icon: this.buildUrl('/assets/img/price-check-icon-192.png'),
                    tag: 'notif-' + (notif.id || Date.now()),
                    url: notif.url || this.buildUrl('/notifications/'),
                    notificationId: notif.id || null
                }
            });
        } else {
            // Fallback: استخدام Notification API مباشرة
            try {
                const notification = new Notification(notif.title || (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.new_notification ? NOTIF_I18N.new_notification : 'New notification'), {
                    body: notif.message || notif.body || '',
                    icon: this.buildUrl('/assets/img/price-check-icon-192.png'),
                    tag: 'notif-' + (notif.id || Date.now()),
                    data: {
                        url: notif.url || this.buildUrl('/notifications/')
                    }
                });

                notification.onclick = function() {
                    window.focus();
                    const url = this.data?.url;
                    if (url) window.location.href = url;
                    this.close();
                };
            } catch (e) {
                console.debug('PushNotif: فشل عرض الإشعار:', e.message);
            }
        }
    },

    // ─────────────────────────────────
    //  الربط مع نظام الإشعارات الداخلي
    // ─────────────────────────────────

    /**
     * ربط مع NotifSystem — عند وصول إشعارات جديدة، عرضها في المتصفح
     */
    connectToNotifSystem() {
        // تخزين المرجع الأصلي لـ showNewNotification
        const originalShowNewNotification = NotifSystem.showNewNotification.bind(NotifSystem);

        // استبدال الدالة بنسخة محسّنة
        NotifSystem.showNewNotification = function(notif) {
            // استدعاء الدالة الأصلية (toast داخلي)
            originalShowNewNotification(notif);

            // عرض إشعار المتصفح
            if (PushNotificationSystem.enabled && PushNotificationSystem.permissionState === 'granted') {
                // خريطة الأيقونات حسب النوع
                const iconMap = {
                    sale: '\uD83D\uDCB0',       // 💰
                    purchase: '\uD83D\uDED2',   // 🛒
                    product: '\uD83D\uDCE6',    // 📦
                    stock: '\u26A0\uFE0F',      // ⚠️
                    expiry: '\uD83D\uDCC5',     // 📅
                    expense: '\uD83D\uDCB8',    // 💸
                    user: '\uD83D\uDC64',       // 👤
                    system: '\u2699\uFE0F',     // ⚙️
                    backup: '\uD83D\uDCBE'      // 💾
                };

                const icon = iconMap[notif.type] || '\uD83D\uDD14'; // 🔔

                PushNotificationSystem.showBrowserNotification({
                    id: notif.id,
                    title: icon + ' ' + (notif.title || (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.new_notification ? NOTIF_I18N.new_notification : 'New notification')),
                    message: notif.message || '',
                    type: notif.type,
                    url: notif.url || null
                });
            }
        };

        console.log('PushNotif: تم الربط مع NotifSystem');
    },

    // ─────────────────────────────────
    //  واجهة المستخدم
    // ─────────────────────────────────

    /**
     * تحديث عناصر واجهة المستخدم حسب حالة الإذن
     */
    updatePermissionUI() {
        const enableBtn = document.getElementById('push-enable-btn');
        const statusEl = document.getElementById('push-permission-status');

        if (statusEl) {
            const statusMap = {
                'granted': { text: (NOTIF_I18N ? NOTIF_I18N.push_enabled : 'Enabled'), class: 'push-status-granted', color: '#059669' },
                'denied': { text: (NOTIF_I18N ? NOTIF_I18N.push_denied : 'Denied'), class: 'push-status-denied', color: '#dc2626' },
                'default': { text: (NOTIF_I18N ? NOTIF_I18N.push_disabled : 'Disabled'), class: 'push-status-default', color: '#d97706' }
            };
            const status = statusMap[this.permissionState] || statusMap['default'];
            statusEl.textContent = status.text;
            statusEl.style.color = status.color;
            statusEl.className = 'push-permission-status ' + status.class;
        }

        if (enableBtn) {
            if (this.permissionState === 'granted') {
                enableBtn.textContent = (NOTIF_I18N ? NOTIF_I18N.push_enabled : 'Enabled');
                enableBtn.disabled = true;
                enableBtn.style.opacity = '0.6';
            } else if (this.permissionState === 'denied') {
                enableBtn.textContent = (NOTIF_I18N ? NOTIF_I18N.push_denied : 'Denied');
                enableBtn.disabled = true;
                enableBtn.style.opacity = '0.6';
            } else {
                enableBtn.textContent = (NOTIF_I18N ? NOTIF_I18N.push_disabled : 'Enable browser notifications');
                enableBtn.disabled = false;
                enableBtn.style.opacity = '1';
            }
        }

        // تحديث زر جرس الإشعارات
        const bellBtn = document.getElementById('notif-bell-btn');
        if (bellBtn && this.permissionState === 'granted') {
            bellBtn.title = (bellBtn.title || '') + ' (' + (NOTIF_I18N ? NOTIF_I18N.push_enabled : 'Enabled') + ')';
        }
    },

    /**
     * تحديث واجهة المستخدم مع رسالة مخصصة
     */
    updatePermissionUIWithMessage(message, type) {
        const enableBtn = document.getElementById('push-enable-btn');
        if (enableBtn) {
            enableBtn.textContent = message;
            enableBtn.disabled = true;
            enableBtn.style.opacity = '0.6';
            enableBtn.style.fontSize = '0.7rem';
            enableBtn.style.whiteSpace = 'normal';
            enableBtn.style.lineHeight = '1.3';
        }
    },

    // ─────────────────────────────────
    //  رسائل Service Worker
    // ─────────────────────────────────

    /**
     * معالجة رسائل من Service Worker
     */
    handleSWMessage(data) {
        if (!data) return;
        // يمكن إضافة معالجة إضافية هنا
    },

    // ─────────────────────────────────
    //  أدوات مساعدة
    // ─────────────────────────────────

    /**
     * بناء رابط كامل
     */
    buildUrl(path) {
        const baseUrl = typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '';
        return baseUrl + path;
    },

    /**
     * الحصول على رمز CSRF
     */
    getCsrfToken() {
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    },

    /**
     * اكتشاف اسم المتصفح
     */
    getBrowserName() {
        const ua = navigator.userAgent;
        if (ua.includes('Firefox')) return 'Firefox';
        if (ua.includes('Edg')) return 'Edge';
        if (ua.includes('Chrome')) return 'Chrome';
        if (ua.includes('Safari')) return 'Safari';
        if (ua.includes('Opera') || ua.includes('OPR')) return 'Opera';
        return 'Unknown';
    },

    /**
     * تحويل مفتاح VAPID من Base64 إلى Uint8Array
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }
};

// ─────────────────────────────────
//  بدء التشغيل التلقائي
// ─────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    PushNotificationSystem.init();
});

// تصدير عام
window.PushNotificationSystem = PushNotificationSystem;
