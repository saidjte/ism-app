/* ============================================================================
 * pages-system-settings.js — منطق صفحة الإعدادات (مستخرج من settings/system-settings.php في المرحلة 3 — M3)
 * يُحمَّل في نهاية الصفحة — كل العناصر الموجّهة موجودة فوق موضع التحميل.
 * ============================================================================ */

                    function generateVapidKeys() {
                        const statusEl = document.getElementById('vapid-status');
                        statusEl.style.display = 'block';
                        statusEl.style.background = '#fef9c3';
                        statusEl.style.color = '#92400e';
                        statusEl.textContent = '⏳ جاري توليد المفاتيح...';

                        const csrfInput = document.querySelector('input[name="csrf_token"]');
                        const csrfToken = csrfInput ? csrfInput.value : '';

                        fetch('../api/push-subscribe.php?action=generate_vapid', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ csrf_token: csrfToken })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                statusEl.style.background = '#ecfdf5';
                                statusEl.style.color = '#059669';
                                statusEl.textContent = '✅ ' + data.message;
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                statusEl.style.background = '#fef2f2';
                                statusEl.style.color = '#dc2626';
                                statusEl.textContent = '❌ ' + (data.message || 'فشل توليد المفاتيح');
                            }
                        })
                        .catch(err => {
                            statusEl.style.background = '#fef2f2';
                            statusEl.style.color = '#dc2626';
                            statusEl.textContent = '❌ خطأ في الاتصال';
                        });
                    }

                // تبديل عرض خيارات الطابعة الحرارية
                document.querySelector('input[name="thermal_printer_enabled"]').addEventListener('change', function() {
                    document.getElementById('thermalPrinterOptions').style.display = this.checked ? '' : 'none';
                });

                // تبديل حقول نوع الاتصال
                function toggleThermalFields() {
                    var type = document.querySelector('input[name="thermal_printer_type"]:checked').value;
                    document.getElementById('thermalNetworkFields').style.display = type === 'network' ? '' : 'none';
                    document.getElementById('thermalSerialFields').style.display = type === 'serial' ? '' : 'none';
                }

function updateAppPreview() {
    var nameInput = document.getElementById('app_name_input');
    var colorInput = document.getElementById('app_logo_color_input');
    var colorText = document.getElementById('app_logo_color_text');
    var previewName = document.getElementById('app_preview_name');
    var previewLogo = document.getElementById('app_preview_logo');

    if (nameInput && previewName) {
        previewName.textContent = nameInput.value || 'اسم التطبيق';
    }
    if (colorInput && previewLogo) {
        previewLogo.style.color = colorInput.value;
    }
    if (colorInput && colorText) {
        colorText.value = colorInput.value;
    }
}

function syncColorFromText() {
    var colorText = document.getElementById('app_logo_color_text');
    var colorInput = document.getElementById('app_logo_color_input');
    var previewLogo = document.getElementById('app_preview_logo');

    if (colorText && colorInput) {
        var val = colorText.value.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(val)) {
            colorInput.value = val;
            if (previewLogo) {
                previewLogo.style.color = val;
            }
        }
    }
}

function toggleTaxSection() {
    var checked = document.getElementById('taxToggle').checked;
    document.getElementById('taxSection').style.display = checked ? 'block' : 'none';
    var label = document.getElementById('taxToggleLabel');
    label.style.borderColor = checked ? '#2563eb' : '#e2e8f0';
    label.style.background = checked ? '#eff6ff' : '#fff';
}
function togglePurchaseTaxSection() {
    var checked = document.getElementById('purchaseTaxToggle').checked;
    document.getElementById('purchaseTaxSection').style.display = checked ? 'block' : 'none';
    var label = document.getElementById('purchaseTaxToggleLabel');
    label.style.borderColor = checked ? '#6366f1' : '#e2e8f0';
    label.style.background = checked ? '#eef2ff' : '#fff';
}

function toggleExpirySection() {
    var checked = document.getElementById('expiryAlertToggle').checked;
    document.getElementById('expirySection').style.display = checked ? 'block' : 'none';
    var label = document.getElementById('expiryAlertLabel');
    label.style.borderColor = checked ? '#2563eb' : '#e2e8f0';
    label.style.background = checked ? '#eff6ff' : '#fff';
}

function toggleNotifSection() {
    var checked = document.getElementById('notifToggle').checked;
    document.getElementById('notifSection').style.display = checked ? 'block' : 'none';
    var label = document.getElementById('notifToggleLabel');
    label.style.borderColor = checked ? '#2563eb' : '#e2e8f0';
    label.style.background = checked ? '#eff6ff' : '#fff';
}

function toggleBackupSection() {
    var checked = document.getElementById('backupToggle').checked;
    document.getElementById('backupSection').style.display = checked ? 'block' : 'none';
    var label = document.getElementById('backupToggleLabel');
    label.style.borderColor = checked ? '#059669' : '#e2e8f0';
    label.style.background = checked ? '#ecfdf5' : '#fff';
}

function regenerateCronKey() {
    var chars = '0123456789abcdef';
    var key = '';
    for (var i = 0; i < 32; i++) {
        key += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    var input = document.getElementById('cronKeyInput');
    input.value = key;
    // تحديث المفتاح في أوامر cron المعروضة
    var displays = document.querySelectorAll('#cronKeyDisplay');
    displays.forEach(function(el) { el.textContent = key; });
}
