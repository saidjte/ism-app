/**
 * pos-held-carts-db.js
 * تجاوز (override) دوال الطلبات المعلقة — نقل من localStorage إلى قاعدة البيانات
 * يُحمَّل بعد السكربت الرئيسي في pos.php
 *
 * v5.30.0
 */

(function() {
    'use strict';

    var API_URL = POS_BASE_URL + '/api/pos-held-carts.php';

    // === تعليق الطلب (override) ===
    holdCart = function() {
        if (cart.length === 0) return;

        var now = new Date();
        var subtotal = cart.reduce(function(sum, item) { return sum + (item.price * item.quantity); }, 0);
        var tax = POS_TAX_ENABLED ? subtotal * (POS_TAX_RATE / 100) : 0;
        var total = subtotal + tax - discount;

        var cartPayload = {
            id: ++heldCartCounter,
            items: JSON.parse(JSON.stringify(cart)),
            discount: discount,
            subtotal: subtotal,
            tax: tax,
            total: total,
            time: now.toLocaleTimeString('ar-MA', { hour: '2-digit', minute: '2-digit' }),
            date: now.toLocaleDateString('ar-MA', { day: '2-digit', month: '2-digit', year: 'numeric' }),
            timestamp: now.getTime(),
            itemCount: cart.reduce(function(sum, item) { return sum + item.quantity; }, 0),
        };

        // إرسال إلى قاعدة البيانات
        fetch(API_URL + '?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: POS_CSRF,
                cart_data: cartPayload,
            }),
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                // تحديث المصفوفة المحلية
                cartPayload.db_id = data.db_id;
                cartPayload.cart_id = data.cart_id;
                heldCarts.unshift(cartPayload); // الأحدث أولاً
                clearCart();
                updateHeldCartsBadge();
                App.toast('تم تعليق الطلب رقم #' + cartPayload.id, 'info');
            } else {
                App.toast(data.message || 'فشل تعليق الطلب', 'error');
            }
        })
        .catch(function() {
            App.toast('خطأ في الاتصال بالخادم', 'error');
        });
    };

    // === تحميل الطلبات المعلقة من قاعدة البيانات (override) ===
    loadHeldCartsFromStorage = function() {
        fetch(API_URL + '?action=list')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    // ترتيب من الأقدم للأحدث (كما كان localStorage)
                    heldCarts = data.carts.reverse();
                    // تحديث العداد بأكبر id
                    var maxId = 0;
                    heldCarts.forEach(function(h) {
                        if (h.id > maxId) maxId = h.id;
                    });
                    heldCartCounter = maxId;
                } else {
                    heldCarts = [];
                    heldCartCounter = 0;
                }
                updateHeldCartsBadge();
            })
            .catch(function() {
                heldCarts = [];
                heldCartCounter = 0;
                updateHeldCartsBadge();
            });
    };

    // === حفظ — لم يعد مطلوباً (لا نستخدم localStorage) ===
    saveHeldCartsToStorage = function() {
        // لا شيء — البيانات في قاعدة البيانات الآن
    };

    // === استعادة طلب معلق (override) ===
    restoreHeldCart = function(index) {
        if (index < 0 || index >= heldCarts.length) return;

        var held = heldCarts[index];

        if (cart.length > 0) {
            showConfirmDialog(
                'warning',
                'السلة غير فارغة',
                'السلة الحالية تحتوي على ' + cart.reduce(function(s, i) { return s + i.quantity; }, 0) + ' منتج. هل تريد استبدالها بالطلب المعلق #' + held.id + '؟',
                'استبدال',
                function() { doRestoreHeldCart(index); }
            );
        } else {
            doRestoreHeldCart(index);
        }
    };

    // === تنفيذ الاستعادة (override) ===
    doRestoreHeldCart = function(index) {
        var held = heldCarts[index];

        // استعادة السلة
        cart = JSON.parse(JSON.stringify(held.items));
        discount = held.discount || 0;

        // حذف من قاعدة البيانات
        var dbId = held.db_id;
        fetch(API_URL + '?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: POS_CSRF, db_id: dbId }),
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            // إزالة من المصفوفة المحلية
            var idx = heldCarts.findIndex(function(h) { return h.db_id === dbId; });
            if (idx !== -1) heldCarts.splice(idx, 1);
            updateHeldCartsBadge();
        })
        .catch(function() {
            // حتى لو فشل الحذف من DB، نستمر بالاستعادة
            var idx = heldCarts.findIndex(function(h) { return h.db_id === dbId; });
            if (idx !== -1) heldCarts.splice(idx, 1);
            updateHeldCartsBadge();
        });

        // إغلاق النافذة وتحديث الواجهة (فوري — لا ننتظر AJAX)
        document.getElementById('heldOverlay').style.display = 'none';

        if (discount > 0) {
            document.getElementById('discountValue').value = discount;
            document.getElementById('discountRow').style.display = 'flex';
            document.getElementById('discountAmount').textContent = '-' + discount.toFixed(2);
        }

        renderCart();

        if (window.innerWidth <= 768) {
            switchMobileTab('cart');
        }

        App.toast('تم استعادة الطلب المعلق #' + held.id, 'success');
    };

    // === حذف طلب معلق واحد (override) ===
    deleteHeldCart = function(index) {
        if (index < 0 || index >= heldCarts.length) return;

        var held = heldCarts[index];
        showConfirmDialog(
            'danger',
            'حذف الطلب المعلق',
            'هل أنت متأكد من حذف الطلب المعلق #' + held.id + '؟ لا يمكن التراجع عن هذا الإجراء.',
            'حذف',
            function() {
                var dbId = held.db_id;
                fetch(API_URL + '?action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: POS_CSRF, db_id: dbId }),
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        var idx = heldCarts.findIndex(function(h) { return h.db_id === dbId; });
                        if (idx !== -1) heldCarts.splice(idx, 1);
                        updateHeldCartsBadge();
                        showHeldCarts();
                        App.toast('تم حذف الطلب المعلق', 'info');
                    } else {
                        App.toast(data.message || 'فشل الحذف', 'error');
                    }
                })
                .catch(function() {
                    App.toast('خطأ في الاتصال بالخادم', 'error');
                });
            }
        );
    };

    // === حذف كل الطلبات المعلقة (override) ===
    deleteAllHeldCarts = function() {
        if (heldCarts.length === 0) return;

        showConfirmDialog(
            'danger',
            'حذف جميع الطلبات المعلقة',
            'هل أنت متأكد من حذف جميع الطلبات المعلقة (' + heldCarts.length + ' طلب)؟ لا يمكن التراجع عن هذا الإجراء.',
            'حذف الكل',
            function() {
                fetch(API_URL + '?action=delete_all', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: POS_CSRF }),
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        heldCarts = [];
                        updateHeldCartsBadge();
                        showHeldCarts();
                        App.toast('تم حذف جميع الطلبات المعلقة', 'info');
                    } else {
                        App.toast(data.message || 'فشل الحذف', 'error');
                    }
                })
                .catch(function() {
                    App.toast('خطأ في الاتصال بالخادم', 'error');
                });
            }
        );
    };

    // === تنظيف localStorage القديم ===
    try {
        localStorage.removeItem('pos_heldCarts');
        localStorage.removeItem('pos_heldCartCounter');
    } catch (e) {}

})();