/**
 * ===================================
 *  الملف الرئيسي للتطبيق (App Core)
 *  تهيئة المكونات المشتركة والتنقل
 *  يُحمَّل بعد utils.js مباشرة
 * ===================================
 */

document.addEventListener('DOMContentLoaded', () => {
    // === تهيئة متغيرات عامة من PHP ===
    window.CURRENCY = window.CURRENCY || 'د.م.';
    window.BASE_URL = window.BASE_URL || '';
    App.baseUrl = window.BASE_URL;

    // === تهيئة التنقل ===
    initNavigation();

    // === إغلاق القوائم المنسدلة عند النقر خارجها ===
    initClickOutside();
});

// ─────────────────────────────────
//  التنقل (Navigation)
// ─────────────────────────────────

/**
 * تهيئة التنقل وقائمة الهاتف
 * @private
 */
function initNavigation() {
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (window.innerWidth > 768) {
                closeMobileMenu();
            }
        }, 150);
    });
}

/**
 * إغلاق القوائم عند النقر خارجها
 * @private
 */
function initClickOutside() {
    document.addEventListener('click', (e) => {
        // إغلاق القوائم المنسدلة في شريط التنقل
        document.querySelectorAll('.nav-dropdown.open').forEach((dropdown) => {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });

        // إغلاق قائمة المستخدم
        document.querySelectorAll('.user-info.open').forEach((info) => {
            if (!info.contains(e.target)) {
                info.classList.remove('open');
            }
        });
    });
}

/**
 * تبديل القائمة الجانبية للهاتف
 */
function toggleMobileMenu() {
    const nav = document.getElementById('mainNav');
    const overlay = document.getElementById('mobileOverlay');
    const btn = document.getElementById('mobileMenuBtn');
    if (!nav) return;

    const isOpen = nav.classList.contains('mobile-open');
    if (isOpen) {
        closeMobileMenu();
    } else {
        nav.classList.add('mobile-open');
        if (overlay) overlay.classList.add('active');
        if (btn) btn.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

/**
 * إغلاق القائمة الجانبية
 */
function closeMobileMenu() {
    const nav = document.getElementById('mainNav');
    const overlay = document.getElementById('mobileOverlay');
    const btn = document.getElementById('mobileMenuBtn');
    if (nav) nav.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    if (btn) btn.classList.remove('active');
    document.body.style.overflow = '';
}

/**
 * تبديل قائمة المستخدم في الهاتف
 */
function toggleMobileUserMenu() {
    const actions = document.getElementById('mobileUserDropdown');
    if (actions) {
        actions.classList.toggle('open');
    }
}

// ─────────────────────────────────
//  أدوات DOM المساعدة
// ─────────────────────────────────

/**
 * تحديث عنصر بـ HTML جديد
 * @param {string|HTMLElement} container محدد CSS أو عنصر
 * @param {string} html المحتوى الجديد
 */
function updateContent(container, html) {
    const el = typeof container === 'string' ? document.querySelector(container) : container;
    if (el) el.innerHTML = html;
}

/**
 * عرض حالة التحميل في عنصر
 * @param {string|HTMLElement} container محدد CSS أو عنصر
 * @param {string} message رسالة التحميل
 */
function showLoading(container, message = 'جاري التحميل...') {
    const el = typeof container === 'string' ? document.querySelector(container) : container;
    if (el) {
        const safeMessage = App.escapeHtml(message);
        el.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:center; padding:40px; color:#94a3b8;">
                <svg style="animation: spin 1s linear infinite; width:24px; height:24px; margin-left:10px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                </svg>
                ${safeMessage}
            </div>
            <style>@keyframes spin { to { transform: rotate(360deg) } }</style>
        `;
    }
}

/**
 * تأكيد الحذف مع طلب AJAX
 * @param {string} message رسالة التأكيد
 * @param {string} url رابط الحذف
 * @param {Function} onSuccess دالة بعد النجاح
 */
async function confirmAndDelete(message, url, onSuccess = null) {
    const confirmed = await App.confirm(message);
    if (!confirmed) return;

    try {
        const data = await App.post(url, { _method: 'DELETE' });
        App.toast('تم الحذف بنجاح', 'success');
        if (typeof onSuccess === 'function') onSuccess(data);
        else if (typeof reloadTable === 'function') reloadTable();
    } catch (error) {
        App.toast(error.message || 'حدث خطأ أثناء الحذف', 'error');
    }
}
