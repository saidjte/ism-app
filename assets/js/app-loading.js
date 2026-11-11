/**
 * ===================================
 *  مكوّن Loading الموحد (Unified Loading)
 *  يُعرض تلقائياً أثناء عمليات AJAX
 *  ويُخفى عند الانتهاء
 *  يدعم الترجمة عبر نظام i18n
 * ===================================
 */

const AppLoading = {
    /** عدد الطلبات النشطة */
    _activeRequests: 0,

    /** عنصر مؤشر التحميل */
    _overlay: null,

    /** الرسالة الحالية */
    _currentMessage: '',

    /** الرسائل المترجمة */
    messages: {
        please_wait: 'يرجى الانتظار...',
        processing: 'جاري المعالجة...',
        saving: 'جاري الحفظ...',
        loading: 'جاري التحميل...'
    },

    /** هل تم إنشاء العنصر */
    _initialized: false,

    /** مهلة الإخفاء التلقائي (ms) - أمان لمنع التجميد */
    _autoHideTimeout: null,
    _autoHideDelay: 30000,

    /**
     * تهيئة مكوّن التحميل
     * @param {Object} config إعدادات اختيارية
     */
    init(config = {}) {
        this.messages = { ...this.messages, ...(config.messages || {}) };
        this._autoHideDelay = config.autoHideDelay || 30000;
        this._createOverlay();
        this._interceptFetch();
        this._initialized = true;
    },

    /**
     * إنشاء عنصر التحميل Overlay
     * @private
     */
    _createOverlay() {
        if (document.getElementById('app-loading-overlay')) {
            this._overlay = document.getElementById('app-loading-overlay');
            return;
        }

        const overlay = document.createElement('div');
        overlay.id = 'app-loading-overlay';
        overlay.className = 'app-loading-overlay';
        overlay.setAttribute('role', 'alert');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.setAttribute('aria-label', this.messages.loading);
        overlay.innerHTML = `
            <div class="app-loading-content">
                <div class="app-loading-spinner">
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                </div>
                <div class="app-loading-message" id="app-loading-message">${this.messages.please_wait}</div>
            </div>
        `;

        // إضافة للصفحة مخفياً
        overlay.style.display = 'none';
        document.body.appendChild(overlay);
        this._overlay = overlay;
    },

    /**
     * اعتراض fetch لعرض/إخفاء مؤشر التحميل تلقائياً
     * @private
     */
    _interceptFetch() {
        const originalFetch = window.fetch;
        const self = this;

        window.fetch = function(...args) {
            const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url || '');
            const options = args[1] || {};

            // تجاهل طلبات الإشعارات والـ polling
            const isSilent = url.includes('notifications.php') && !url.includes('action=mark') && !url.includes('action=delete');
            const isPolling = url.includes('unread_count') || url.includes('action=poll');
            const isBackground = options.headers?.['X-Silent'] || options.headers?.['X-Background'];

            if (isSilent || isPolling || isBackground) {
                return originalFetch.apply(this, args);
            }

            // تحديد نوع الرسالة
            const method = (options.method || 'GET').toUpperCase();
            let message = self.messages.loading;
            if (method === 'POST' || method === 'PUT') {
                message = self.messages.saving;
            }

            self.show(message);

            return originalFetch.apply(this, args)
                .then(response => {
                    self.hide();
                    return response;
                })
                .catch(error => {
                    self.hide();
                    throw error;
                });
        };
    },

    /**
     * عرض مؤشر التحميل
     * @param {string} message رسالة اختيارية
     */
    show(message) {
        this._activeRequests++;
        this._currentMessage = message || this.messages.please_wait;

        if (!this._overlay) {
            this._createOverlay();
        }

        // تحديث الرسالة
        const msgEl = this._overlay.querySelector('.app-loading-message');
        if (msgEl) {
            msgEl.textContent = this._currentMessage;
        }

        this._overlay.style.display = 'flex';
        this._overlay.setAttribute('aria-label', this._currentMessage);

        // أمان: إخفاء تلقائي بعد مهلة
        clearTimeout(this._autoHideTimeout);
        this._autoHideTimeout = setTimeout(() => {
            if (this._activeRequests > 0) {
                this._activeRequests = 0;
                this.hide();
            }
        }, this._autoHideDelay);
    },

    /**
     * إخفاء مؤشر التحميل
     */
    hide() {
        this._activeRequests = Math.max(0, this._activeRequests - 1);

        // لا تخفي حتى تنتهي جميع الطلبات
        if (this._activeRequests > 0) return;

        this._activeRequests = 0;
        clearTimeout(this._autoHideTimeout);

        if (this._overlay) {
            this._overlay.style.display = 'none';
        }
    },

    /**
     * إخفاء قسري (تجاهل العداد)
     */
    forceHide() {
        this._activeRequests = 0;
        clearTimeout(this._autoHideTimeout);
        if (this._overlay) {
            this._overlay.style.display = 'none';
        }
    },

    /**
     * عرض مؤشر تحميل داخل عنصر محدد
     * @param {HTMLElement} container العنصر الحاوي
     * @param {string} message رسالة اختيارية
     */
    showInContainer(container, message) {
        if (!container) return;
        const msg = message || this.messages.loading;

        // حفظ المحتوى الأصلي
        if (!container.dataset.originalContent) {
            container.dataset.originalContent = container.innerHTML;
            container.dataset.originalMinHeight = container.style.minHeight;
        }

        container.style.minHeight = '80px';
        container.innerHTML = `
            <div class="app-loading-inline">
                <div class="app-loading-spinner-sm">
                    <div class="spinner-ring"></div>
                    <div class="spinner-ring"></div>
                </div>
                <span class="app-loading-inline-message">${msg}</span>
            </div>
        `;
    },

    /**
     * إخفاء مؤشر التحميل من عنصر واستعادة المحتوى
     * @param {HTMLElement} container العنصر الحاوي
     * @param {string} newContent محتوى جديد اختياري
     */
    hideInContainer(container, newContent) {
        if (!container) return;

        if (newContent) {
            container.innerHTML = newContent;
        } else if (container.dataset.originalContent) {
            container.innerHTML = container.dataset.originalContent;
        }

        if (container.dataset.originalMinHeight) {
            container.style.minHeight = container.dataset.originalMinHeight;
        } else {
            container.style.minHeight = '';
        }

        delete container.dataset.originalContent;
        delete container.dataset.originalMinHeight;
    },

    /**
     * تحديث الرسائل المترجمة
     * @param {Object} newMessages
     */
    updateMessages(newMessages) {
        this.messages = { ...this.messages, ...newMessages };
    }
};

// تصدير عام
window.AppLoading = AppLoading;
