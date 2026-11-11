/**
 * ===================================
 *  نظام التحقق من النماذج (Form Validation)
 *  طبقة JavaScript مكملة لـ HTML5 validation
 *  رسائل خطأ فورية مترجمة عبر نظام i18n
 * ===================================
 */

const FormValidation = {
    /** رسائل التحقق المترجمة (تُحدَّث من PHP) */
    messages: {},

    /** اللغة الحالية */
    lang: 'ar',

    /**
     * تهيئة نظام التحقق
     * @param {Object} config - إعدادات اختيارية
     */
    init(config = {}) {
        this.messages = config.messages || {};
        this.lang = config.lang || 'ar';

        // تطبيق التحقق على جميع النماذج
        document.querySelectorAll('form[data-validate], form[method="POST"], form[method="post"]').forEach(form => {
            this.setupForm(form);
        });

        // مراقبة النماذج المضافة ديناميكياً
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        const forms = node.tagName === 'FORM' ? [node] : node.querySelectorAll('form');
                        forms.forEach(form => {
                            if (form.method && form.method.toLowerCase() === 'post') {
                                this.setupForm(form);
                            }
                        });
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    },

    /**
     * إعداد نموذج للتحقق
     * @param {HTMLFormElement} form
     */
    setupForm(form) {
        // منع الإعداد المتكرر
        if (form.dataset.validationSetup === 'true') return;
        form.dataset.validationSetup = 'true';

        // تعطيل رسائل HTML5 الافتراضية (نستخدم رسائلنا المخصصة)
        form.setAttribute('novalidate', '');

        // التحقق عند الإرسال
        form.addEventListener('submit', (e) => {
            const isValid = this.validateForm(form);
            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                // التمرير إلى أول حقل خاطئ
                const firstError = form.querySelector('.field-error:not(:empty)');
                if (firstError) {
                    const field = firstError.closest('.form-group')?.querySelector('input, select, textarea');
                    if (field) {
                        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        field.focus();
                    }
                }
            }
        });

        // التحقق الفوري عند مغادرة الحقل (blur)
        form.querySelectorAll('input, select, textarea').forEach(field => {
            // التحقق عند blur
            field.addEventListener('blur', () => {
                this.validateField(field);
            });

            // إزالة الخطأ عند البدء بالكتابة
            field.addEventListener('input', () => {
                if (field.classList.contains('field-invalid')) {
                    this.clearFieldError(field);
                    // إعادة التحقق بعد كتابة حرف (للحقول الرقمية)
                    if (field.type === 'number') {
                        this.validateField(field);
                    }
                }
            });

            // التحقق عند تغيير القائمة المنسدلة
            field.addEventListener('change', () => {
                this.validateField(field);
            });
        });
    },

    /**
     * التحقق من نموذج كامل
     * @param {HTMLFormElement} form
     * @returns {boolean}
     */
    validateForm(form) {
        let isValid = true;
        const fields = form.querySelectorAll('input, select, textarea');

        fields.forEach(field => {
            if (!this.validateField(field)) {
                isValid = false;
            }
        });

        // التحقق المخصص: تأكيد كلمة المرور
        const password = form.querySelector('input[name="password"], input[name="new_password"]');
        const passwordConfirm = form.querySelector('input[name="password_confirm"], input[name="confirm_password"]');
        if (password && passwordConfirm && password.value && passwordConfirm.value) {
            if (password.value !== passwordConfirm.value) {
                this.showFieldError(passwordConfirm, this.getMessage('validation_password_mismatch'));
                isValid = false;
            }
        }

        return isValid;
    },

    /**
     * التحقق من حقل واحد
     * @param {HTMLElement} field
     * @returns {boolean}
     */
    validateField(field) {
        // تجاهل الحقول المخفية والمعطلة والأزرار
        if (!field.offsetParent && field.type !== 'hidden') return true;
        if (field.disabled) return true;
        if (['submit', 'button', 'reset', 'image', 'hidden'].includes(field.type)) return true;
        if (field.readOnly && field.type !== 'hidden') return true;

        // مسح الخطأ السابق
        this.clearFieldError(field);

        const value = field.value.trim();
        let errorMsg = '';

        // 1. التحقق من الحقول المطلوبة
        if (field.hasAttribute('required') && !value) {
            // حقل select
            if (field.tagName === 'SELECT') {
                errorMsg = this.getMessage('validation_select_required');
            } else if (field.type === 'email') {
                errorMsg = this.getMessage('validation_email');
            } else {
                errorMsg = this.getMessage('validation_required');
            }
        }

        // 2. التحقق من نوع البريد الإلكتروني
        if (!errorMsg && value && field.type === 'email') {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(value)) {
                errorMsg = this.getMessage('validation_email');
            }
        }

        // 3. التحقق من الطول الأدنى
        if (!errorMsg && value && field.hasAttribute('minlength')) {
            const minLen = parseInt(field.getAttribute('minlength'), 10);
            if (value.length < minLen) {
                errorMsg = this.getMessage('validation_minlength', { min: minLen });
            }
        }

        // 4. التحقق من الطول الأقصى
        if (!errorMsg && value && field.hasAttribute('maxlength')) {
            const maxLen = parseInt(field.getAttribute('maxlength'), 10);
            if (value.length > maxLen) {
                errorMsg = this.getMessage('validation_maxlength', { max: maxLen });
            }
        }

        // 5. التحقق من النمط (pattern)
        if (!errorMsg && value && field.hasAttribute('pattern')) {
            const pattern = new RegExp('^(?:' + field.getAttribute('pattern') + ')$');
            if (!pattern.test(value)) {
                errorMsg = field.getAttribute('data-pattern-error') || this.getMessage('validation_pattern');
            }
        }

        // 6. التحقق من القيم الرقمية
        if (!errorMsg && value && field.type === 'number') {
            const numVal = parseFloat(value);
            if (isNaN(numVal)) {
                errorMsg = this.getMessage('validation_number');
            } else {
                // التحقق من الحد الأدنى
                if (field.hasAttribute('min') && numVal < parseFloat(field.getAttribute('min'))) {
                    errorMsg = this.getMessage('validation_min', { min: field.getAttribute('min') });
                }
                // التحقق من الحد الأقصى
                if (!errorMsg && field.hasAttribute('max') && numVal > parseFloat(field.getAttribute('max'))) {
                    errorMsg = this.getMessage('validation_max', { max: field.getAttribute('max') });
                }
                // التحقق من الخطوة (step)
                if (!errorMsg && field.hasAttribute('step') && field.getAttribute('step') !== 'any') {
                    const step = parseFloat(field.getAttribute('step'));
                    const min = field.hasAttribute('min') ? parseFloat(field.getAttribute('min')) : 0;
                    const remainder = Math.abs((numVal - min) / step);
                    if (Math.abs(remainder - Math.round(remainder)) > 0.0001) {
                        errorMsg = this.getMessage('validation_step');
                    }
                }
                // التحقق من القيمة الموجبة للأسعار والمبالغ
                if (!errorMsg && field.hasAttribute('data-positive') && numVal <= 0) {
                    const errorKey = field.getAttribute('data-positive-error') || 'validation_amount_positive';
                    errorMsg = this.getMessage(errorKey);
                }
            }
        }

        // 7. التحقق من التاريخ
        if (!errorMsg && value && field.type === 'date') {
            const dateVal = new Date(value);
            if (isNaN(dateVal.getTime())) {
                errorMsg = this.getMessage('validation_date');
            }
        }

        // 8. التحقق من URL
        if (!errorMsg && value && field.type === 'url') {
            try {
                new URL(value);
            } catch {
                errorMsg = this.getMessage('validation_url');
            }
        }

        // 9. تحقق مخصص عبر data-validate
        if (!errorMsg && field.hasAttribute('data-validate')) {
            errorMsg = this.customValidation(field);
        }

        // عرض النتيجة
        if (errorMsg) {
            this.showFieldError(field, errorMsg);
            return false;
        }

        // إظهار علامة النجاح (اختياري)
        this.showFieldSuccess(field);
        return true;
    },

    /**
     * تحقق مخصص حسب data-validate
     * @param {HTMLElement} field
     * @returns {string} رسالة خطأ أو فارغ
     */
    customValidation(field) {
        const type = field.getAttribute('data-validate');
        const value = field.value.trim();

        switch (type) {
            case 'password-match': {
                const targetId = field.getAttribute('data-match-target');
                const target = document.getElementById(targetId);
                if (target && value !== target.value) {
                    return this.getMessage('validation_password_mismatch');
                }
                break;
            }
            case 'positive-number': {
                const num = parseFloat(value);
                if (!isNaN(num) && num <= 0) {
                    return this.getMessage('validation_amount_positive');
                }
                break;
            }
            default:
                break;
        }
        return '';
    },

    /**
     * عرض خطأ على حقل
     * @param {HTMLElement} field
     * @param {string} message
     */
    showFieldError(field, message) {
        field.classList.add('field-invalid');
        field.classList.remove('field-valid');

        // إنشاء أو تحديث حاوية الخطأ
        const formGroup = field.closest('.form-group') || field.closest('.input-wrapper')?.parentElement;
        if (!formGroup) return;

        let errorEl = formGroup.querySelector('.field-error');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'field-error';
            // إدراج بعد الحقل مباشرة أو بعد input-wrapper
            const wrapper = field.closest('.input-wrapper');
            if (wrapper) {
                wrapper.parentElement.insertBefore(errorEl, wrapper.nextSibling);
            } else {
                formGroup.insertBefore(errorEl, field.nextSibling.nextSibling);
            }
        }
        errorEl.textContent = message;
        errorEl.setAttribute('role', 'alert');

        // تحديث aria
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', errorEl.id || `error-${field.name || Math.random().toString(36).substr(2, 5)}`);
        errorEl.id = field.getAttribute('aria-describedby');
    },

    /**
     * إظهار علامة نجاح على حقل
     * @param {HTMLElement} field
     */
    showFieldSuccess(field) {
        // فقط للحقول المطلوبة التي تم ملؤها بنجاح
        if (field.hasAttribute('required') || field.value.trim()) {
            field.classList.add('field-valid');
            field.classList.remove('field-invalid');
            field.removeAttribute('aria-invalid');
        }
    },

    /**
     * مسح خطأ من حقل
     * @param {HTMLElement} field
     */
    clearFieldError(field) {
        field.classList.remove('field-invalid', 'field-valid');
        field.removeAttribute('aria-invalid');

        const formGroup = field.closest('.form-group') || field.closest('.input-wrapper')?.parentElement;
        if (formGroup) {
            const errorEl = formGroup.querySelector('.field-error');
            if (errorEl) {
                errorEl.textContent = '';
            }
        }
    },

    /**
     * الحصول على رسالة مترجمة
     * @param {string} key مفتاح الرسالة
     * @param {Object} params معاملات الاستبدال
     * @returns {string}
     */
    getMessage(key, params = {}) {
        let msg = this.messages[key] || key;
        // استبدال المعاملات مثل {min}, {max}
        Object.keys(params).forEach(param => {
            msg = msg.replace(new RegExp('\\{' + param + '\\}', 'g'), params[param]);
        });
        return msg;
    },

    /**
     * تحديث الرسائل المترجمة (يُستخدم عند تغيير اللغة)
     * @param {Object} newMessages
     */
    updateMessages(newMessages) {
        this.messages = { ...this.messages, ...newMessages };
    },

    /**
     * إضافة تحقق مخصص يدوي لنموذج
     * @param {HTMLFormElement} form
     * @param {Function} customFn دالة التحقق المخصصة تُرجع true/false
     */
    addCustomValidator(form, customFn) {
        form.addEventListener('submit', (e) => {
            if (!customFn(form)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }
};

// تصدير عام
window.FormValidation = FormValidation;
