/**
 * ===================================
 *  نظام الإشعارات
 *  Notification System — SSE (real-time) + polling fallback, dropdown, updates
 *  v6.3.0: إضافة Server-Sent Events مع تراجع تلقائي لـ polling
 * ===================================
 */

const NotifSystem = {
    /** فترات الاستطلاع بالمللي ثانية — تكيفية حسب حالة التبويب */
    POLL_ACTIVE: 30000,    // تبويب نشط: 30 ثانية
    POLL_IDLE: 120000,     // تبويب مخفي: 120 ثانية
    POLL_BACKOFF_MAX: 300000, // أقصى تراجع: 5 دقائق

    /** فترة الاستطلاع الحالية (ديناميكية) */
    pollInterval: 30000,

    /** عدد دورات الاستطلاع المتتالية بدون إشعارات جديدة (للتراجع) */
    idlePollCount: 0,

    /** مؤقت الاستطلاع */
    pollTimer: null,

    /** آخر معرف إشعار تم استلامه */
    lastId: 0,

    /** حالة القائمة المنسدلة */
    isDropdownOpen: false,

    /** حالة التحميل */
    isLoading: false,

    /** مؤقت الصوت (منع التكرار) */
    lastSoundTime: 0,

    /** عدد تنبيهات الصلاحية (منتهية + قاربة) — يُمرَّر من PHP */
    expiryAlertsCount: 0,

    /** حالة رؤية التبويب */
    isTabVisible: true,

    // ─────────────────────────────────
    //  SSE (Server-Sent Events)
    // ─────────────────────────────────

    /** اتصال SSE الحالي */
    sseConnection: null,

    /** هل SSE متاح ويعمل؟ */
    sseActive: false,

    /** عدد محاولات إعادة الاتصال SSE */
    sseReconnectCount: 0,

    /** أقصى عدد محاولات إعادة الاتصال SSE قبل التراجع لـ polling */
    SSE_MAX_RECONNECTS: 3,

    /** هل SSE جُرِّب وفشل نهائياً؟ (لا تُعِد المحاولة) */
    ssePermanentlyFailed: false,

    // ─────────────────────────────────
    //  التهيئة
    // ─────────────────────────────────

    /**
     * تهيئة نظام الإشعارات
     */
    init() {
        this.bellBtn = document.getElementById('notif-bell-btn');
        this.dropdown = document.getElementById('notif-dropdown');
        this.badge = document.getElementById('notif-badge');
        this.dropdownList = document.getElementById('notif-dropdown-list');
        this.closeBtn = document.getElementById('notif-close-btn');
        if (!this.bellBtn || !this.dropdown) {
            console.warn('NotifSystem: عناصر الإشعارات غير موجودة في الصفحة');
            return;
        }

        // ربط الأحداث
        this.bellBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleDropdown();
        });

        // زر الإغلاق (X)
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeDropdown();
            });
        }

        // إغلاق عند النقر خارج القائمة المنسدلة
        document.addEventListener('click', (e) => {
            if (this.isDropdownOpen && !this.dropdown.contains(e.target) && !this.bellBtn.contains(e.target)) {
                this.closeDropdown();
            }
        });

        // إغلاق بمفتاح Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isDropdownOpen) {
                this.closeDropdown();
            }
        });

        // إعادة حساب موقع القائمة عند تغيير حجم النافذة أو التمرير
        window.addEventListener('resize', () => {
            if (this.isDropdownOpen) this.positionDropdown();
        });
        window.addEventListener('scroll', () => {
            if (this.isDropdownOpen) this.positionDropdown();
        }, true); // capture phase لرصد scroll على أي عنصر

        // قراءة عدد تنبيهات الصلاحية من PHP (data attribute)
        const bellBtn = this.bellBtn;
        if (bellBtn) {
            this.expiryAlertsCount = parseInt(bellBtn.dataset.expiryAlerts || '0', 10);
        }

        // مراقبة رؤية التبويب لتكييف فترة الاستطلاع
        document.addEventListener('visibilitychange', () => {
            this.isTabVisible = !document.hidden;
            this.adjustPollInterval();
            // عند العودة للتبويب: استطلاع فوري + مزامنة الإشعارات الفائتة
            if (this.isTabVisible) {
                this.poll();
                this.syncOfflineNotifications();
            }
        });

        // مراقبة عودة الاتصال — مزامنة الإشعارات الفائتة
        window.addEventListener('online', () => {
            console.log('NotifSystem: عودة الاتصال — مزامنة الإشعارات');
            this.syncOfflineNotifications();
        });

        // تحميل العدد أول مرة
        this.fetchUnreadCount();

        // محاولة الاتصال بـ SSE أولاً، مع تراجع لـ polling
        if (!this.ssePermanentlyFailed && typeof EventSource !== 'undefined') {
            this.initSSE();
        } else {
            this.startPolling();
        }
    },

    // ─────────────────────────────────
    //  SSE (Server-Sent Events)
    // ─────────────────────────────────

    /**
     * تهيئة اتصال SSE للإشعارات الفورية
     */
    initSSE() {
        if (this.sseConnection) return; // اتصال موجود بالفعل

        try {
            const url = this.buildUrl('/api/notifications-sse.php') + '?last_id=' + this.lastId;
            this.sseConnection = new EventSource(url);

            this.sseConnection.addEventListener('connected', (e) => {
                console.log('NotifSystem SSE: متصل بنجاح');
                this.sseActive = true;
                this.sseReconnectCount = 0;
                // إيقاف polling إذا كان يعمل
                this.stopPolling();
            });

            this.sseConnection.addEventListener('notification', (e) => {
                try {
                    const notif = JSON.parse(e.data);
                    if (notif && notif.id) {
                        this.lastId = Math.max(this.lastId, parseInt(notif.id));
                        this.showNewNotification(notif);
                        this.playNotificationSound();
                        this.idlePollCount = 0;
                    }
                } catch (err) {
                    console.debug('NotifSystem SSE: خطأ في تحليل بيانات الإشعار', err);
                }
            });

            this.sseConnection.addEventListener('unread_count', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    this.updateBadge(data.count || 0);
                } catch (err) {}
            });

            this.sseConnection.addEventListener('disabled', (e) => {
                // SSE معطّل من الإعدادات (notifications_sse_enabled = 0)
                console.info('NotifSystem SSE: معطّل من الإعدادات — التراجع لـ polling');
                this.ssePermanentlyFailed = true;
                this.closeSSE();
                this.startPolling();
            });

            this.sseConnection.addEventListener('error', (e) => {
                // خطأ من الخادم (ليس خطأ اتصال)
                try {
                    const data = JSON.parse(e.data);
                    if (data.code === 'unauthorized') {
                        console.warn('NotifSystem SSE: غير مصادق — إيقاف');
                        this.ssePermanentlyFailed = true;
                        this.closeSSE();
                        return;
                    }
                    if (data.code === 'sse_disabled') {
                        // SSE معطّل — نفس معالجة disabled
                        console.info('NotifSystem SSE: معطّل من الإعدادات — التراجع لـ polling');
                        this.ssePermanentlyFailed = true;
                        this.closeSSE();
                        this.startPolling();
                        return;
                    }
                } catch (err) {}
            });

            this.sseConnection.addEventListener('close', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (data.reconnect && data.last_id) {
                        this.lastId = Math.max(this.lastId, data.last_id);
                    }
                } catch (err) {}
            });

            this.sseConnection.onerror = () => {
                this.sseActive = false;
                this.sseReconnectCount++;

                if (this.sseReconnectCount >= this.SSE_MAX_RECONNECTS) {
                    console.warn('NotifSystem SSE: فشل متكرر — التراجع لـ polling');
                    this.ssePermanentlyFailed = true;
                    this.closeSSE();
                    this.startPolling();
                }
                // EventSource يُعيد الاتصال تلقائياً — لا نحتاج لفعل شيء
            };

        } catch (err) {
            console.warn('NotifSystem SSE: فشل الاتصال — التراجع لـ polling', err);
            this.ssePermanentlyFailed = true;
            this.closeSSE();
            this.startPolling();
        }
    },

    /**
     * إغلاق اتصال SSE
     */
    closeSSE() {
        if (this.sseConnection) {
            this.sseConnection.close();
            this.sseConnection = null;
        }
        this.sseActive = false;
    },

    // ─────────────────────────────────
    //  الاستطلاع (Polling) — fallback
    // ─────────────────────────────────

    /**
     * تكييف فترة الاستطلاع حسب حالة التبويب والنشاط
     */
    adjustPollInterval() {
        if (!this.isTabVisible) {
            // تبويب مخفي: استخدام فترة أطول
            this.pollInterval = this.POLL_IDLE;
        } else if (this.idlePollCount >= 5) {
            // 5+ دورات بدون جديد: تراجع تدريجي (60s, 90s, 120s... حتى 300s)
            const backoff = Math.min(
                this.POLL_ACTIVE + (this.idlePollCount - 4) * 30000,
                this.POLL_BACKOFF_MAX
            );
            this.pollInterval = backoff;
        } else {
            // نشط وع جديد: 30 ثانية
            this.pollInterval = this.POLL_ACTIVE;
        }
        // إعادة تشغيل المؤقت بالفترة الجديدة
        this.startPolling();
    },

    /**
     * بدء الاستطلاع الدوري
     */
    startPolling() {
        this.stopPolling();
        this.pollTimer = setInterval(() => this.poll(), this.pollInterval);
    },

    /**
     * إيقاف الاستطلاع
     */
    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    },

    /**
     * استطلاع إشعارات جديدة
     */
    async poll() {
        if (this.isLoading) return;

        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.get(url, { action: 'poll', last_id: this.lastId });

            if (data.success) {
                // تحديث الشارة دائماً (حتى بدون إشعارات جديدة — قد يتغير unread_count)
                this.updateBadge(data.unread_count || 0);

                const newNotifs = data.notifications || data.new_notifications;
                if (newNotifs && newNotifs.length > 0) {
                    // تحديث آخر معرف
                    this.lastId = Math.max(this.lastId, ...newNotifs.map(n => parseInt(n.id)));

                    // عرض إشعار لكل إشعار جديد
                    newNotifs.forEach(notif => {
                        this.showNewNotification(notif);
                    });

                    // تشغيل صوت (إذا كان مسموحًا)
                    this.playNotificationSound();

                    // إعادة تعيين التراجع — هناك نشاط
                    this.idlePollCount = 0;
                    this.adjustPollInterval();
                } else {
                    // لا إشعارات جديدة — زيادة عداد الخمول
                    this.idlePollCount++;
                    this.adjustPollInterval();
                }
            }
        } catch (err) {
            // صامت - لا نريد إزعاج المستخدم بأخطاء الاستطلاع
            console.debug('NotifSystem poll error:', err.message);
        }
    },

    // ─────────────────────────────────
    //  القائمة المنسدلة (Dropdown)
    // ─────────────────────────────────

    /**
     * تبديل حالة القائمة المنسدلة
     */
    toggleDropdown() {
        if (this.isDropdownOpen) {
            this.closeDropdown();
        } else {
            this.openDropdown();
        }
    },

    /**
     * فتح القائمة المنسدلة وتحميل الإشعارات
     */
    openDropdown() {
        this.isDropdownOpen = true;
        this.positionDropdown();
        this.dropdown.classList.add('open');
        this.bellBtn.setAttribute('aria-expanded', 'true');
        // منع التمرير على الهاتف
        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            document.body.style.overflow = 'hidden';
        }
        this.loadDropdown();
    },

    /**
     * إغلاق القائمة المنسدلة
     */
    closeDropdown() {
        this.isDropdownOpen = false;
        this.dropdown.classList.remove('open');
        this.bellBtn.setAttribute('aria-expanded', 'false');
        // استعادة التمرير
        if (document.body.style.overflow === 'hidden') {
            document.body.style.overflow = '';
        }
    },

    /**
     * حساب موقع القائمة المنسدلة بناءً على موقع الزر
     * ضروري لأن القائمة خارج navbar (position: fixed)
     */
    positionDropdown() {
        if (!this.bellBtn || !this.dropdown) return;

        const btnRect = this.bellBtn.getBoundingClientRect();
        const isMobile = window.innerWidth <= 768;

        if (isMobile) {
            // الهاتف: شاشة كاملة من الأسفل — لا حاجة لحساب الموقع
            this.dropdown.style.top = 'auto';
            this.dropdown.style.bottom = '0';
            this.dropdown.style.left = '0';
            this.dropdown.style.right = '0';
        } else {
            // سطح المكتب: أسفل الزر مباشرة
            const dropdownWidth = Math.min(360, window.innerWidth - 32);
            let left = btnRect.left;
            let top = btnRect.bottom + 8;

            // منع التجاوز على اليمين
            if (left + dropdownWidth > window.innerWidth - 16) {
                left = window.innerWidth - dropdownWidth - 16;
            }
            // منع التجاوز على اليسار
            if (left < 16) {
                left = 16;
            }
            // إذا لم تتسع الشاشة عمودياً، اعرض فوق الزر
            if (top + 450 > window.innerHeight) {
                top = btnRect.top - 458;
                if (top < 8) top = 8;
            }

            this.dropdown.style.top = top + 'px';
            this.dropdown.style.bottom = 'auto';
            this.dropdown.style.left = left + 'px';
            this.dropdown.style.right = 'auto';
        }
    },

    /**
     * تحميل محتوى القائمة المنسدلة
     */
    async loadDropdown() {
        if (this.isLoading || !this.dropdownList) return;
        this.isLoading = true;

        // عرض حالة التحميل
        this.dropdownList.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:center;padding:30px;color:var(--gray-400);">
                <span class="spinner" style="width:20px;height:20px;"></span>
            </div>
        `;

        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.get(url, { action: 'dropdown' });

            if (data.success) {
                this.renderDropdown(data.notifications || [], data.unread_count || 0);
            } else {
                this.dropdownList.innerHTML = `
                    <div class="notif-empty">
                        <span class="notif-empty-icon">&#9888;</span>
                        <p>${this.escapeHtml(data.error || 'حدث خطأ أثناء التحميل')}</p>
                    </div>
                `;
            }
        } catch (err) {
            this.dropdownList.innerHTML = `
                <div class="notif-empty">
                    <span class="notif-empty-icon">&#9888;</span>
                    <p>${typeof NOTIF_I18N !== 'undefined' ? NOTIF_I18N.no_notifications : 'لا توجد إشعارات'}</p>
                </div>
            `;
        } finally {
            this.isLoading = false;
        }
    },

    /**
     * عرض الإشعارات في القائمة المنسدلة
     * @param {Array} notifications قائمة الإشعارات
     * @param {number} unreadCount عدد غير المقروءة
     */
    renderDropdown(notifications, unreadCount) {
        // تحديث الشارة
        this.updateBadge(unreadCount);

        // تحديث رأس القائمة
        const countLabel = this.dropdown.querySelector('.notif-count-label');
        if (countLabel) {
            countLabel.textContent = unreadCount > 0 ? `(${unreadCount} جديد)` : '';
        }

        // تحديث أزرار تحديد الكل كمقروء
        const markAllBtn = this.dropdown.querySelector('.notif-mark-all-btn');
        if (markAllBtn) {
            markAllBtn.style.display = unreadCount > 0 ? '' : 'none';
            markAllBtn.onclick = () => this.markAllRead();
        }

        // لا توجد إشعارات
        if (notifications.length === 0) {
            this.dropdownList.innerHTML = `
                <div class="notif-empty">
                    <span class="notif-empty-icon">&#128276;</span>
                    <p>${typeof NOTIF_I18N !== 'undefined' ? NOTIF_I18N.no_notifications : 'لا توجد إشعارات'}</p>
                </div>
            `;
            return;
        }

        // إنشاء عناصر الإشعارات
        const html = notifications.map(notif => this.renderDropdownItem(notif)).join('');
        this.dropdownList.innerHTML = html;

        // ربط أحداث العناصر
        this.bindDropdownEvents();
    },

    /**
     * عرض عنصر إشعار واحد في القائمة المنسدلة
     * @param {Object} notif بيانات الإشعار
     * @returns {string} HTML
     */
    renderDropdownItem(notif) {
        const unreadClass = notif.is_read == 0 ? 'unread' : '';
        const typeClass = `type-${notif.type || 'system'}`;
        const priorityClass = notif.priority ? `priority-${notif.priority}` : '';
        const icon = this.getTypeIcon(notif.type);
        const time = this.formatTimeAgo(notif.created_at);
        const url = notif.url ? `data-url="${this.escapeHtml(notif.url)}"` : '';

        // شارة التجميع — عرض عدد الإشعارات المجمّعة
        const groupBadge = (notif.group_count && notif.group_count > 1)
            ? `<span class="notif-group-badge">${notif.group_count}</span>`
            : '';

        // شارة الأولوية — عرض للعاجل فقط
        const priorityBadge = notif.priority === 'urgent'
            ? '<span class="notif-priority-badge priority-urgent">' + (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.urgent ? NOTIF_I18N.urgent : 'عاجل') + '</span>'
            : '';

        return `
            <div class="notif-item ${unreadClass} ${priorityClass}" data-id="${notif.id}" ${url}>
                <div class="notif-item-icon ${typeClass}">
                    ${icon}
                </div>
                <div class="notif-item-content">
                    <div class="notif-item-title">${priorityBadge}${this.escapeHtml(notif.title)}</div>
                    <div class="notif-item-message">${this.escapeHtml(notif.message)}</div>
                    <div class="notif-item-time">${time}${groupBadge}</div>
                </div>
                <div class="notif-item-actions">
                    <button class="notif-delete-btn" data-delete="${notif.id}" title="حذف">&#128465;</button>
                </div>
            </div>
        `;
    },

    /**
     * ربط أحداث عناصر القائمة المنسدلة
     */
    bindDropdownEvents() {
        if (!this.dropdownList) return;

        this.dropdownList.querySelectorAll('.notif-item').forEach(item => {
            item.addEventListener('click', (e) => {
                // تجاهل النقر على زر الحذف
                if (e.target.closest('.notif-delete-btn')) return;

                const notifId = item.dataset.id;
                const notifUrl = item.dataset.url;

                // تحديد كمقروء
                if (item.classList.contains('unread')) {
                    this.markRead(notifId, item);
                }

                // التنقل إذا كان هناك رابط
                if (notifUrl) {
                    window.location.href = notifUrl;
                }
            });
        });

        // أزرار الحذف
        this.dropdownList.querySelectorAll('.notif-delete-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const notifId = btn.dataset.delete;
                this.deleteNotification(notifId);
            });
        });
    },

    // ─────────────────────────────────
    //  الشارة (Badge)
    // ─────────────────────────────────

    /**
     * تحديث شارة عدد الإشعارات
     * @param {number} count عدد الإشعارات غير المقروءة
     */
    updateBadge(count) {
        if (!this.badge) return;
        count = parseInt(count) || 0;
        // دمج عدد تنبيهات الصلاحية مع عدد الإشعارات غير المقروءة
        const total = count + this.expiryAlertsCount;

        if (total > 0) {
            this.badge.textContent = total > 99 ? '99+' : total;
            this.badge.style.display = 'flex';
            this.badge.classList.add('pulse');
            // إزالة نبض بعد 10 ثواني
            setTimeout(() => this.badge.classList.remove('pulse'), 10000);
        } else {
            this.badge.textContent = '';
            this.badge.style.display = 'none';
            this.badge.classList.remove('pulse');
        }
    },

    /**
     * جلب عدد الإشعارات غير المقروءة
     */
    async fetchUnreadCount() {
        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.get(url, { action: 'unread_count' });
            if (data.success) {
                this.updateBadge(data.unread_count || 0);
                this.lastId = data.last_id || 0;
            }
        } catch (err) {
            // الصمت — لا نريد إزعاج المستخدم
            console.debug('NotifSystem: فشل جلب عدد الإشعارات');
        }
    },

    // ─────────────────────────────────
    //  الإجراءات (Actions)
    // ─────────────────────────────────

    /**
     * تحديد إشعار كمقروء
     * @param {string|number} notificationId معرف الإشعار
     * @param {HTMLElement|null} element عنصر DOM المراد تحديثه
     */
    async markRead(notificationId, element) {
        const csrfToken = this.getCsrfToken();
        if (!csrfToken) return;

        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.post(url, {
                action: 'mark_read',
                id: notificationId,
                csrf_token: csrfToken
            });

            // التحقق من نجاح العملية قبل تحديث الواجهة
            if (!data.success) {
                if (data.csrf_error) {
                    App.toast('يرجى تحديث الصفحة والمحاولة مرة أخرى', 'warning');
                } else {
                    App.toast(data.message || 'فشل تحديد الإشعار كمقروء', 'error');
                }
                return;
            }

            // تحديث الواجهة
            if (element) {
                element.classList.remove('unread');
            }

            // تتبع: قراءة الإشعار
            this.trackAction(notificationId, 'read');

            // تحديث العداد من الخادم
            this.updateBadge(data.unread_count ?? 0);
        } catch (err) {
            if (err?.message?.includes('CSRF') || err?.message?.includes('أمني')) {
                App.toast('يرجى تحديث الصفحة والمحاولة مرة أخرى', 'warning');
            } else {
                App.toast('فشل تحديد الإشعار كمقروء', 'error');
            }
        }
    },

    /**
     * تحديد جميع الإشعارات كمقروءة
     */
    async markAllRead() {
        const csrfToken = this.getCsrfToken();
        if (!csrfToken) {
            App.toast('يرجى تحديث الصفحة والمحاولة مرة أخرى', 'warning');
            return;
        }

        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.post(url, {
                action: 'mark_all_read',
                csrf_token: csrfToken
            });

            if (data.success) {
                // تحديث الشارة بالعدد الفعلي من الخادم
                this.updateBadge(data.unread_count ?? 0);

                // إزالة حالة غير المقروء من جميع العناصر
                if (this.dropdownList) {
                    this.dropdownList.querySelectorAll('.notif-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                }

                // إخفاء زر تحديد الكل
                const markAllBtn = this.dropdown.querySelector('.notif-mark-all-btn');
                if (markAllBtn) {
                    markAllBtn.style.display = 'none';
                }

                // تحديث تسمية العدد
                const countLabel = this.dropdown.querySelector('.notif-count-label');
                if (countLabel) {
                    countLabel.textContent = '';
                }

                App.toast('تم تحديد جميع الإشعارات كمقروءة', 'success');
            } else {
                if (data.csrf_error) {
                    App.toast('يرجى تحديث الصفحة والمحاولة مرة أخرى', 'warning');
                } else {
                    App.toast(data.message || 'حدث خطأ أثناء التحديث', 'error');
                }
            }
        } catch (err) {
            App.toast('حدث خطأ أثناء التحديث', 'error');
        }
    },

    /**
     * حذف إشعار
     * @param {string|number} notificationId معرف الإشعار
     */
    async deleteNotification(notificationId) {
        // تأكيد الحذف
        const confirmed = await App.confirm('هل تريد حذف هذا الإشعار؟');
        if (!confirmed) return;

        const csrfToken = this.getCsrfToken();
        if (!csrfToken) {
            App.toast('يرجى تحديث الصفحة والمحاولة مرة أخرى', 'warning');
            return;
        }

        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.post(url, {
                action: 'delete',
                id: notificationId,
                csrf_token: csrfToken
            });

            // التحقق من نجاح العملية
            if (!data.success) {
                if (data.csrf_error) {
                    App.toast('يرجى تحديث الصفحة والمحاولة مرة أخرى', 'warning');
                } else {
                    App.toast(data.message || 'فشل حذف الإشعار', 'error');
                }
                return;
            }

            // إزالة من الواجهة
            const item = this.dropdownList?.querySelector(`[data-id="${notificationId}"]`);
            if (item) {
                item.style.transition = 'opacity 0.3s, max-height 0.3s';
                item.style.opacity = '0';
                item.style.maxHeight = '0';
                item.style.overflow = 'hidden';
                setTimeout(() => item.remove(), 300);
            }

            // تتبع: حذف الإشعار
            this.trackAction(notificationId, 'delete');

            // تحديث العداد من الخادم
            this.updateBadge(data.unread_count ?? 0);
        } catch (err) {
            App.toast('حدث خطأ أثناء الحذف', 'error');
        }
    },

    // ─────────────────────────────────
    //  إشعار الإشعارات الجديدة (Toast)
    // ─────────────────────────────────

    /**
     * عرض إشعار تنبيه لإشعار جديد
     * @param {Object} notif بيانات الإشعار
     */
    showNewNotification(notif) {
        const icon = this.getTypeIcon(notif.type);
        const title = this.escapeHtml(notif.title || (typeof NOTIF_I18N !== 'undefined' ? NOTIF_I18N.new_notification : 'إشعار جديد'));
        const message = this.escapeHtml(notif.message || '');

        // مدة الإشعار حسب الأولوية
        const duration = notif.priority === 'urgent' ? 8000 : (notif.priority === 'info' ? 3000 : 5000);
        const toastType = notif.priority === 'urgent' ? 'error' : 'info';

        // شارة التجميع
        const groupText = (notif.group_count && notif.group_count > 1) ? ` (${notif.group_count})` : '';

        App.toast(`${icon} ${title}${groupText}${message ? ': ' + message : ''}`, toastType, duration);
    },

    // ─────────────────────────────────
    //  الصوت (Sound)
    // ─────────────────────────────────

    /**
     * تشغيل صوت تنبيه بسيط
     */
    playNotificationSound() {
        // منع التكرار خلال 30 ثانية
        const now = Date.now();
        if (now - this.lastSoundTime < 30000) return;
        this.lastSoundTime = now;

        try {
            // إنشاء صوت بسيط باستخدام Web Audio API
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);

            // إشعارات عاجلة: صوت أعلى وأطول
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            gainNode.gain.value = 0.1;

            oscillator.start();
            gainNode.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);
            oscillator.stop(audioCtx.currentTime + 0.3);
        } catch (e) {
            // الصوت غير مدعوم — تجاهل بصمت
        }
    },

    // ─────────────────────────────────
    //  أدوات مساعدة (Helpers)
    // ─────────────────────────────────

    /**
     * الحصول على رمز CSRF من الصفحة
     * @returns {string}
     */
    getCsrfToken() {
        // 1. البحث عن حقل مخفي (الطريقة الأساسية)
        const input = document.querySelector('input[name="csrf_token"]');
        if (input && input.value) return input.value;
        // 2. البحث عن meta tag
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) return meta.content;
        // 3. إرجاع فارغ مع تحذير
        console.warn('NotifSystem: رمز CSRF غير موجود في الصفحة — يرجى تحديث الصفحة');
        return '';
    },

    /**
     * بناء رابط API كامل
     * @param {string} path مسار API
     * @returns {string}
     */
    buildUrl(path) {
        const baseUrl = typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '';
        return baseUrl + path;
    },

    /**
     * الحصول على أيقونة حسب نوع الإشعار
     * @param {string} type نوع الإشعار
     * @returns {string} رمز الأيقونة
     */
    getTypeIcon(type) {
        const icons = {
            sale: '&#128176;',       // 💰
            purchase: '&#128722;',   // 🛒
            product: '&#128230;',    // 📦
            stock: '&#9888;',        // ⚠
            expiry: '&#128197;',     // 📅
            system: '&#9881;',       // ⚙
        };
        return icons[type] || '&#128276;'; // 🔔
    },

    /**
     * تنسيق الوقت بشكل نسبي
     * @param {string} dateStr التاريخ
     * @returns {string}
     */
    formatTimeAgo(dateStr) {
        if (!dateStr) return '';

        const now = new Date();
        const date = new Date(dateStr);
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHour / 24);

        if (diffSec < 60) return typeof NOTIF_I18N !== 'undefined' ? NOTIF_I18N.just_now : 'الآن';
        if (diffMin < 60) {
            const tpl = (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.minutes_ago) ? NOTIF_I18N.minutes_ago : 'منذ {n} دقيقة';
            return tpl.replace('{n}', diffMin);
        }
        if (diffHour < 24) {
            const tpl = (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.hours_ago) ? NOTIF_I18N.hours_ago : 'منذ {n} ساعة';
            return tpl.replace('{n}', diffHour);
        }
        if (diffDay < 7) {
            const tpl = (typeof NOTIF_I18N !== 'undefined' && NOTIF_I18N.days_ago) ? NOTIF_I18N.days_ago : 'منذ {n} يوم';
            return tpl.replace('{n}', diffDay);
        }

        // أكثر من أسبوع — عرض التاريخ
        return App.formatDateTime(dateStr);
    },

    /**
     * هروب HTML لمنع XSS
     * @param {string} str النص
     * @returns {string}
     */
    escapeHtml(str) {
        if (!str) return '';
        if (typeof App !== 'undefined' && App.escapeHtml) {
            return App.escapeHtml(str);
        }
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    // ─────────────────────────────────
    //  مزامنة الإشعارات الفائتة (Offline Support)
    // ─────────────────────────────────

    /**
     * مزامنة الإشعارات الفائتة عند عودة الاتصال
     * يطلب من API الإشعارات التي فاتت منذ آخر استطلاع
     */
    async syncOfflineNotifications() {
        if (!navigator.onLine) return;

        try {
            const url = this.buildUrl('/api/notifications.php');
            const data = await App.get(url, { action: 'poll', last_id: this.lastId });

            if (data.success) {
                const newNotifs = data.notifications || data.new_notifications;
                if (newNotifs && newNotifs.length > 0) {
                    // تحديث آخر معرف
                    this.lastId = Math.max(this.lastId, ...newNotifs.map(n => parseInt(n.id)));

                    // حفظ آخر معرف في Service Worker للمزامنة المستقبلية
                    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                        navigator.serviceWorker.controller.postMessage({
                            type: 'SAVE_LAST_ID',
                            data: { lastId: this.lastId }
                        });
                    }

                    // عرض الإشعارات الفائتة
                    newNotifs.forEach(notif => {
                        this.showNewNotification(notif);
                    });

                    this.playNotificationSound();
                    this.updateBadge(data.unread_count || 0);

                    console.log('NotifSystem: تمت مزامنة', newNotifs.length, 'إشعار فائت');
                }
            }
        } catch (err) {
            console.debug('NotifSystem: فشلت مزامنة الإشعارات الفائتة:', err.message);
        }
    },

    // ─────────────────────────────────
    //  تتبع تفاعل المستخدم (Analytics)
    // ─────────────────────────────────

    /**
     * تسجيل حدث تفاعل مع إشعار
     * @param {string|number} notificationId معرف الإشعار
     * @param {string} action النوع: 'read' | 'click' | 'dismiss' | 'delete'
     */
    trackAction(notificationId, action) {
        try {
            const url = this.buildUrl('/api/notifications.php');
            // إرسال بدون انتظار — لا يُعطّل الواجهة
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=track&notification_id=' + encodeURIComponent(notificationId) + '&action_type=' + encodeURIComponent(action) + '&csrf_token=' + encodeURIComponent(this.getCsrfToken())
            }).catch(() => { /* صامت */ });

            // إرسال للـ Service Worker أيضاً
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'NOTIF_ACTION',
                    data: { id: notificationId, action: action }
                });
            }
        } catch { /* صامت */ }
    }
};

// ─────────────────────────────────
//  بدء التشغيل
// ─────────────────────────────────
(function initNotifSystem() {
    // التأكد من أن DOM جاهز قبل التهيئة
    // بما أن السكربت يُحمّل في نهاية الصفحة، قد يكون DOMContentLoaded قد انتهى بالفعل
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            NotifSystem.init();
        });
    } else {
        // DOM جاهز بالفعل — تهيئة مباشرة
        NotifSystem.init();
    }
})();

// تصدير عام
window.NotifSystem = NotifSystem;
