/**
 * ===================================
 *  تنسيق واستدعاءات (App Utilities)
 *  الملف الأساسي: إشعارات، تأكيدات، طلبات AJAX، تنسيق
 *  يُحمَّل أولاً قبل جميع الملفات الأخرى
 * ===================================
 */

const App = {
    /** رمز العملة (يُحدد من PHP عبر window.CURRENCY) */
    currency: typeof CURRENCY !== 'undefined' ? CURRENCY : 'د.م.',

    /** المسار الأساسي (يُحدد من PHP عبر window.BASE_URL) */
    baseUrl: typeof BASE_URL !== 'undefined' ? BASE_URL : '',

    // ─────────────────────────────────
    //  الإشعارات (Toast Notifications)
    // ─────────────────────────────────

    /**
     * عرض إشعار مؤقت
     * @param {string} message نص الرسالة
     * @param {string} type نوع الرسالة: success | error | warning | info
     * @param {number} duration مدة العرض بالمللي ثانية (افتراضي 3000)
     */
    toast(message, type = 'info', duration = 3000) {
        const container = document.getElementById('toast-container') || this.createToastContainer();
        const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
        const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };

        const toast = document.createElement('div');
        toast.className = 'app-toast app-toast-' + type;
        toast.style.cssText = `
            display: flex; align-items: center; gap: 10px; padding: 12px 20px;
            background: #fff; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.12);
            border-right: 4px solid ${colors[type]}; color: #1e293b; font-size: 0.9rem;
            transform: translateX(120%); transition: transform 0.3s ease; max-width: 380px;
            font-family: 'Segoe UI', Tahoma, sans-serif; direction: rtl;
        `;
        const safeMessage = App.escapeHtml(message);
        toast.innerHTML = `
            <span style="font-size:1.2rem; font-weight:700; color:${colors[type]}">${icons[type]}</span>
            <span style="flex:1">${safeMessage}</span>
            <button onclick="this.parentElement.remove()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:1.2rem;padding:0 4px;">&times;</button>
        `;

        container.appendChild(toast);
        requestAnimationFrame(() => { toast.style.transform = 'translateX(0)'; });

        setTimeout(() => {
            toast.style.transform = 'translateX(120%)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    /**
     * إنشاء حاوية الإشعارات
     * @private
     */
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = `
            position: fixed; top: 20px; left: 20px; z-index: 10000;
            display: flex; flex-direction: column; gap: 10px;
        `;
        document.body.appendChild(container);
        return container;
    },

    // ─────────────────────────────────
    //  تأكيد الإجراءات (Confirm Dialog)
    // ─────────────────────────────────

    /**
     * عرض نافذة تأكيد مخصصة (بدلاً من confirm() الأصلي)
     * @param {string} message رسالة التأكيد
     * @returns {Promise<boolean>}
     */
    async confirm(message) {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5); z-index: 9999;
                display: flex; align-items: center; justify-content: center; padding: 20px;
            `;

            const dialog = document.createElement('div');
            dialog.style.cssText = `
                background: #fff; border-radius: 14px; padding: 28px;
                max-width: 400px; width: 100%; box-shadow: 0 8px 32px rgba(0,0,0,0.2);
                text-align: center; direction: rtl; font-family: 'Segoe UI', Tahoma, sans-serif;
            `;
            const safeMessage = App.escapeHtml(message);
            dialog.innerHTML = `
                <div style="font-size: 2.5rem; margin-bottom: 12px;">❓</div>
                <p style="color: #1e293b; font-size: 1rem; line-height: 1.6; margin-bottom: 24px;">${safeMessage}</p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button id="confirm-yes" style="
                        padding: 10px 28px; border-radius: 8px; border: none;
                        background: #ef4444; color: #fff; font-weight: 600; cursor: pointer;
                        font-size: 0.95rem; transition: opacity 0.2s;
                    ">نعم، تأكيد</button>
                    <button id="confirm-no" style="
                        padding: 10px 28px; border-radius: 8px; border: 1px solid #e2e8f0;
                        background: #fff; color: #475569; font-weight: 600; cursor: pointer;
                        font-size: 0.95rem; transition: background 0.2s;
                    ">إلغاء</button>
                </div>
            `;

            overlay.appendChild(dialog);
            document.body.appendChild(overlay);

            dialog.querySelector('#confirm-yes').onclick = () => { overlay.remove(); resolve(true); };
            dialog.querySelector('#confirm-no').onclick = () => { overlay.remove(); resolve(false); };
            overlay.onclick = (e) => { if (e.target === overlay) { overlay.remove(); resolve(false); } };
        });
    },

    // ─────────────────────────────────
    //  طلبات AJAX (HTTP Client)
    // ─────────────────────────────────

    /**
     * طلب GET
     * @param {string} url الرابط
     * @param {Object} params معاملات الاستعلام
     * @returns {Promise<Object>}
     */
    async get(url, params = {}) {
        const queryString = new URLSearchParams(params).toString();
        const fullUrl = queryString ? `${url}?${queryString}` : url;
        const response = await fetch(fullUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        return this.parseResponse(response);
    },

    /**
     * طلب POST (JSON)
     * @param {string} url الرابط
     * @param {Object} data البيانات
     * @returns {Promise<Object>}
     */
    async post(url, data = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data),
        });
        return this.parseResponse(response);
    },

    /**
     * طلب POST بنموذج (FormData)
     * @param {string} url الرابط
     * @param {FormData} formData بيانات النموذج
     * @returns {Promise<Object>}
     */
    async postForm(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
        });
        return this.parseResponse(response);
    },

    /**
     * طلب PUT (JSON)
     * @param {string} url الرابط
     * @param {Object} data البيانات
     * @returns {Promise<Object>}
     */
    async put(url, data = {}) {
        const response = await fetch(url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data),
        });
        return this.parseResponse(response);
    },

    /**
     * طلب DELETE
     * @param {string} url الرابط
     * @returns {Promise<Object>}
     */
    async delete(url) {
        const response = await fetch(url, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        return this.parseResponse(response);
    },

    /**
     * تحليل استجابة الخادم
     * @private
     * @param {Response} response
     * @returns {Promise<Object>}
     */
    async parseResponse(response) {
        const text = await response.text();
        try {
            const data = JSON.parse(text);
            if (!response.ok && !data.success) {
                throw new Error(data.error?.message || 'حدث خطأ غير متوقع');
            }
            return data;
        } catch (e) {
            if (e.message && !e.message.includes('JSON')) throw e;
            throw new Error('خطأ في الاتصال بالخادم');
        }
    },

    // ─────────────────────────────────
    //  تنسيق البيانات (Formatters)
    // ─────────────────────────────────

    /**
     * تنسيق مبلغ مالي
     * @param {number} amount المبلغ
     * @returns {string}
     */
    formatMoney(amount) {
        return new Intl.NumberFormat('ar-MA', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount) + ' ' + this.currency;
    },

    /**
     * تنسيق تاريخ حسب إعداد date_format في النظام
     * @param {string} dateStr تاريخ بصيغة YYYY-MM-DD
     * @returns {string}
     */
    formatDate(dateStr) {
        if (!dateStr || dateStr === '0000-00-00') return '-';
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
    },

    /**
     * تنسيق تاريخ ووقت حسب إعداد date_format في النظام
     * @param {string} dateStr تاريخ ووقت
     * @returns {string}
     */
    formatDateTime(dateStr) {
        if (!dateStr) return '-';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        var time = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        return this.formatDate(dateStr.split(' ')[0] || dateStr) + ' ' + time;
    },

    // ─────────────────────────────────
    //  أدوات متنوعة (Utilities)
    // ─────────────────────────────────

    /**
     * Debounce — تأخير تنفيذ الدالة
     * @param {Function} fn الدالة
     * @param {number} delay التأخير بالمللي ثانية (افتراضي 300)
     * @returns {Function}
     */
    debounce(fn, delay = 300) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    },

    /**
     * هروب HTML لمنع XSS
     * @param {string} str النص
     * @returns {string}
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    /**
     * نسخ نص للحافظة
     * @param {string} text النص
     */
    async copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.toast('تم النسخ', 'success');
        } catch {
            const input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            input.remove();
            this.toast('تم النسخ', 'success');
        }
    },
};

// تصدير عام
window.App = App;
