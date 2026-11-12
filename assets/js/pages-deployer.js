/* ============================================================================
 * pages-deployer.js — منطق الناشر الذري (مستخرج من deployer.php في المرحلة 3 — M3)
 * ============================================================================ */

//  نظام i18n — ترجمة رسائل الواجهة (عربي / إنجليزي / فرنسي)
// ====================================================================
const I18N = {
    ar: {
        // عام
        loading: 'جاري التنفيذ...',
        confirm_action: 'هل تريد المتابعة؟',
        yes: 'نعم',
        no: 'لا',
        // تسجيل الدخول
        enter_secret: 'أدخل الرمز السري',
        login: '🔓 دخول',
        forgot_secret: '🔑 نسيت الرمز السري؟ إعادة تعيين',
        // إعادة تعيين
        new_secret_min8: 'الرمز السري الجديد (8 أحرف على الأقل)',
        confirm_new_secret: 'تأكيد الرمز السري الجديد',
        reset_secret_btn: '🔑 إعادة تعيين الرمز السري',
        reset_confirm: 'سيتم تعيين رمز سري جديد للناشر.\n\nتأكد من حفظ الرمز الجديد في مكان آمن!\n\nهل تريد المتابعة؟',
        // نشر
        deploy_atomic: '🚀 نشر ذري',
        deploy_confirm: 'سيتم نشر ملفات التحديث بشكل ذري (Write-Then-Rename).\n\n• يتم تفعيل وضع الصيانة تلقائياً\n• يتم نسخ الملفات الحالية احتياطياً قبل الاستبدال\n• كل ملف يُستبدل ذرياً بعملية rename (POSIX guarantee)\n• الملفات الحرجة (.htaccess + config/database.php) لا تُستبدل\n• في حالة الفشل يتم التراجع تلقائياً\n\nسيتم حجب الطلبات مؤقتاً أثناء النشر.\n\nهل تريد المتابعة؟',
        // تراجع
        rollback_btn: '⏪ تراجع عن نشر الملفات',
        rollback_confirm: '⚠️ سيتم استعادة الملفات القديمة وحذف ملفات التحديث المنشورة.\n\nهل أنت متأكد؟',
        // ترقية طارئة
        emergency_btn: '🚑 تشغيل ترقية قاعدة البيانات الآن',
        emergency_confirm: 'سيتم تشغيل ترقية قاعدة البيانات لجميع الإصدارات المعلقة.\n\n• يتم إنشاء نسخة احتياطية تلقائياً قبل البدء\n• الدوال المفقودة تُتخطى بدلاً من إيقاف الترقية\n• في حالة الفشل يتم التراجع تلقائياً\n\nهل تريد المتابعة؟',
        // تغيير الرمز
        current_secret: 'الرمز السري الحالي',
        change_secret_btn: '🔑 تغيير الرمز السري',
        change_confirm: 'سيتم تغيير الرمز السري للناشر.\n\nتأكد من حفظ الرمز الجديد في مكان آمن!\n\nهل تريد المتابعة؟',
        // أخطاء التحقق
        err_required: 'هذا الحقل مطلوب',
        err_minlength: 'يجب أن يكون 8 أحرف على الأقل',
        err_match: 'الرمزان غير متطابقين',
        err_zip_required: 'يرجى اختيار ملف ZIP',
        err_zip_invalid: 'يجب اختيار ملف بصيغة .zip فقط',
        // تحميل
        loading_deploy: 'جاري نشر الملفات ذرياً...',
        loading_rollback: 'جاري التراجع عن النشر...',
        loading_upgrade: 'جاري تشغيل ترقية قاعدة البيانات...',
        loading_change: 'جاري تغيير الرمز السري...',
        loading_reset: 'جاري إعادة تعيين الرمز السري...',
        loading_login: 'جاري تسجيل الدخول...'
    },
    en: {
        loading: 'Processing...',
        confirm_action: 'Do you want to continue?',
        enter_secret: 'Enter secret key',
        login: '🔓 Login',
        forgot_secret: '🔑 Forgot secret? Reset',
        new_secret_min8: 'New secret (at least 8 characters)',
        confirm_new_secret: 'Confirm new secret',
        reset_secret_btn: '🔑 Reset Secret',
        reset_confirm: 'A new deployer secret will be set.\n\nMake sure to save the new secret in a safe place!\n\nDo you want to continue?',
        deploy_atomic: '🚀 Atomic Deploy',
        deploy_confirm: 'Files will be deployed atomically (Write-Then-Rename).\n\n• Maintenance mode activated automatically\n• Current files backed up before replacement\n• Each file replaced atomically via rename (POSIX guarantee)\n• Critical files (.htaccess + config/database.php) are not replaced\n• Automatic rollback on failure\n\nRequests will be temporarily blocked during deployment.\n\nDo you want to continue?',
        rollback_btn: '⏪ Rollback Deployment',
        rollback_confirm: '⚠️ Current files will be restored and deployed updates will be removed.\n\nAre you sure?',
        emergency_btn: '🚑 Run Emergency DB Upgrade',
        emergency_confirm: 'Database upgrade will run for all pending versions.\n\n• Automatic backup before starting\n• Missing functions are skipped instead of stopping\n• Automatic rollback on failure\n\nDo you want to continue?',
        current_secret: 'Current secret',
        change_secret_btn: '🔑 Change Secret',
        change_confirm: 'The deployer secret will be changed.\n\nMake sure to save the new secret in a safe place!\n\nDo you want to continue?',
        err_required: 'This field is required',
        err_minlength: 'Must be at least 8 characters',
        err_match: 'The two secrets do not match',
        err_zip_required: 'Please select a ZIP file',
        err_zip_invalid: 'Only .zip files are allowed',
        loading_deploy: 'Deploying files atomically...',
        loading_rollback: 'Rolling back deployment...',
        loading_upgrade: 'Running database upgrade...',
        loading_change: 'Changing secret...',
        loading_reset: 'Resetting secret...',
        loading_login: 'Logging in...'
    },
    fr: {
        loading: 'Traitement en cours...',
        confirm_action: 'Voulez-vous continuer ?',
        enter_secret: 'Entrez la clé secrète',
        login: '🔓 Connexion',
        forgot_secret: '🔑 Clé oubliée ? Réinitialiser',
        new_secret_min8: 'Nouvelle clé (au moins 8 caractères)',
        confirm_new_secret: 'Confirmer la nouvelle clé',
        reset_secret_btn: '🔑 Réinitialiser la clé',
        reset_confirm: 'Une nouvelle clé sera définie.\n\nAssurez-vous de sauvegarder la nouvelle clé !\n\nVoulez-vous continuer ?',
        deploy_atomic: '🚀 Déploiement atomique',
        deploy_confirm: 'Les fichiers seront déployés atomiquement (Write-Then-Rename).\n\n• Mode maintenance activé automatiquement\n• Sauvegarde des fichiers avant remplacement\n• Chaque fichier remplacé atomiquement (POSIX guarantee)\n• Les fichiers critiques ne sont pas remplacés\n• Retour automatique en cas d\'échec\n\nVoulez-vous continuer ?',
        rollback_btn: '⏪ Annuler le déploiement',
        rollback_confirm: '⚠️ Les fichiers actuels seront restaurés et les mises à jour supprimées.\n\nÊtes-vous sûr ?',
        emergency_btn: '🚑 Mise à jour d\'urgence de la BDD',
        emergency_confirm: 'La mise à jour de la base de données sera exécutée.\n\n• Sauvegarde automatique avant de commencer\n• Les fonctions manquantes sont ignorées\n• Retour automatique en cas d\'échec\n\nVoulez-vous continuer ?',
        current_secret: 'Clé secrète actuelle',
        change_secret_btn: '🔑 Changer la clé',
        change_confirm: 'La clé sera modifiée.\n\nAssurez-vous de sauvegarder la nouvelle clé !\n\nVoulez-vous continuer ?',
        err_required: 'Ce champ est obligatoire',
        err_minlength: 'Doit contenir au moins 8 caractères',
        err_match: 'Les deux clés ne correspondent pas',
        err_zip_required: 'Veuillez sélectionner un fichier ZIP',
        err_zip_invalid: 'Seuls les fichiers .zip sont autorisés',
        loading_deploy: 'Déploiement atomique en cours...',
        loading_rollback: 'Annulation du déploiement...',
        loading_upgrade: 'Mise à jour de la BDD en cours...',
        loading_change: 'Changement de clé en cours...',
        loading_reset: 'Réinitialisation de la clé...',
        loading_login: 'Connexion en cours...'
    }
};

// اللغة الحالية — من localStorage أو الافتراضية
let currentLang = localStorage.getItem('deployer_lang') || 'ar';

// تطبيق الترجمة على كل العناصر
function applyI18N(lang) {
    if (!I18N[lang]) lang = 'ar';
    currentLang = lang;
    localStorage.setItem('deployer_lang', lang);
    document.documentElement.lang = lang;
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';

    // تحديث الأزرار النشطة
    document.querySelectorAll('.lang-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.lang === lang);
    });

    // ترجمة العناصر ذات data-i18n
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (I18N[lang][key] !== undefined) el.textContent = I18N[lang][key];
    });

    // ترجمة placeholder
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.dataset.i18nPlaceholder;
        if (I18N[lang][key] !== undefined) el.placeholder = I18N[lang][key];
    });

    // تحديث اتجاه حقول كلمة المرور
    document.querySelectorAll('input[type="password"]').forEach(el => {
        el.style.direction = 'ltr';
        el.style.textAlign = 'center';
    });
}

// استخراج نص مؤكد حسب الإجراء
function getConfirmMsg(action) {
    const msgs = {
        deploy_zip: I18N[currentLang]?.deploy_confirm || I18N.ar.deploy_confirm,
        rollback_files: I18N[currentLang]?.rollback_confirm || I18N.ar.rollback_confirm,
        emergency_upgrade: I18N[currentLang]?.emergency_confirm || I18N.ar.emergency_confirm,
        change_secret: I18N[currentLang]?.change_confirm || I18N.ar.change_confirm,
        reset_secret: I18N[currentLang]?.reset_confirm || I18N.ar.reset_confirm
    };
    return msgs[action] || I18N[currentLang]?.confirm_action || I18N.ar.confirm_action;
}

// استخراج نص التحميل حسب الإجراء
function getLoadingMsg(action) {
    const msgs = {
        deploy_zip: 'loading_deploy',
        rollback_files: 'loading_rollback',
        emergency_upgrade: 'loading_upgrade',
        change_secret: 'loading_change',
        reset_secret: 'loading_reset'
    };
    const key = msgs[action] || 'loading';
    return I18N[currentLang]?.[key] || I18N.ar[key] || I18N.ar.loading;
}

// ====================================================================
//  مكون التحميل الموحد
// ====================================================================
function showLoading(action) {
    const overlay = document.getElementById('deployer-loading');
    const textEl = overlay?.querySelector('.deployer-loading-text');
    if (overlay) {
        if (textEl) textEl.textContent = getLoadingMsg(action);
        overlay.style.display = 'flex';
    }
}
function hideLoading() {
    const overlay = document.getElementById('deployer-loading');
    if (overlay) overlay.style.display = 'none';
}

// ====================================================================
//  نظام التحقق من جانب العميل
// ====================================================================
function validateField(input) {
    const rules = (input.dataset.validate || '').split('|');
    const minLen = parseInt(input.dataset.minlength) || 0;
    const matchName = input.dataset.match || null;
    let errorMsg = '';

    for (const rule of rules) {
        if (rule === 'required' && !input.value.trim()) {
            errorMsg = I18N[currentLang]?.err_required || I18N.ar.err_required;
            break;
        }
        if (rule === 'minlength' && input.value.trim().length < minLen) {
            errorMsg = I18N[currentLang]?.err_minlength || I18N.ar.err_minlength;
            break;
        }
        if (rule === 'match' && matchName) {
            const matchInput = input.form?.querySelector('[name="' + matchName + '"]');
            if (matchInput && input.value !== matchInput.value) {
                errorMsg = I18N[currentLang]?.err_match || I18N.ar.err_match;
                break;
            }
        }
        if (rule === 'zipfile') {
            const file = input.files?.[0];
            if (file && !file.name.toLowerCase().endsWith('.zip')) {
                errorMsg = I18N[currentLang]?.err_zip_invalid || I18N.ar.err_zip_invalid;
                break;
            }
        }
    }

    // عرض/إخفاء رسالة الخطأ
    const errorEl = input.parentElement?.querySelector('.field-error[data-for="' + input.name + '"]');
    if (errorMsg) {
        input.classList.add('invalid');
        input.classList.remove('valid');
        if (errorEl) {
            errorEl.textContent = errorMsg;
            errorEl.classList.add('visible');
        }
    } else {
        input.classList.remove('invalid');
        if (input.value.trim()) input.classList.add('valid');
        else input.classList.remove('valid');
        if (errorEl) errorEl.classList.remove('visible');
    }
    return !errorMsg;
}

// التحقق من نموذج كامل
function validateForm(form) {
    const inputs = form.querySelectorAll('[data-validate]');
    let allValid = true;
    inputs.forEach(input => {
        if (!validateField(input)) allValid = false;
    });
    return allValid;
}

// ====================================================================
//  تهيئة الأحداث عند تحميل الصفحة
// ====================================================================
document.addEventListener('DOMContentLoaded', function() {
    // تطبيق اللغة المحفوظة
    applyI18N(currentLang);

    // أزرار تبديل اللغة
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            applyI18N(this.dataset.lang);
        });
    });

    // التحقق الفوري عند الكتابة
    document.querySelectorAll('[data-validate]').forEach(input => {
        input.addEventListener('input', function() { validateField(this); });
        input.addEventListener('change', function() { validateField(this); });
        // عند فقدان التركيز
        input.addEventListener('blur', function() {
            if (this.value.trim()) validateField(this);
        });
    });

    // اعتراض إرسال جميع النماذج
    document.querySelectorAll('form[method="POST"]').forEach(form => {
        form.addEventListener('submit', function(e) {
            // تحقق من جانب العميل
            const hasValidation = this.querySelectorAll('[data-validate]').length > 0;
            if (hasValidation && !validateForm(this)) {
                e.preventDefault();
                // التركيز على أول حقل خاطئ
                const firstInvalid = this.querySelector('.invalid');
                if (firstInvalid) firstInvalid.focus();
                return;
            }

            // تحديد الإجراء
            const actionInput = this.querySelector('[name="action"]');
            const action = actionInput ? actionInput.value : 'login';

            // تأكيد قبل الإجراءات الخطرة
            const needsConfirm = ['deploy_zip', 'rollback_files', 'emergency_upgrade', 'change_secret', 'reset_secret'];
            if (needsConfirm.includes(action)) {
                if (!confirm(getConfirmMsg(action))) {
                    e.preventDefault();
                    return;
                }
            }

            // عرض مؤشر التحميل
            showLoading(action);

            // للنماذج العادية (non-AJAX) — إخفاء التحميل بعد 60 ثانية كحد أقصى
            setTimeout(hideLoading, 60000);
        });
    });

    // تحسين حقل رفع الملفات — عرض اسم الملف
    const zipInput = document.querySelector('input[name="zip_file"]');
    if (zipInput) {
        zipInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const name = this.files[0].name;
                const size = (this.files[0].size / (1024 * 1024)).toFixed(1);
                // عرض معلومات الملف
                let info = this.parentElement.querySelector('.zip-info');
                if (!info) {
                    info = document.createElement('div');
                    info.className = 'zip-info';
                    info.style.cssText = 'font-size:.78rem;color:#059669;margin-top:4px';
                    this.parentElement.insertBefore(info, this.nextSibling.nextSibling || null);
                }
                info.textContent = '📎 ' + name + ' (' + size + ' MB)';
            }
        });
    }
});
