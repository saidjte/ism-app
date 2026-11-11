<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$page_title = t('nav_notifications');
requireLogin();
require_once __DIR__ . '/../includes/header.php';

$userId = intval($_SESSION['user_id']);
$baseApiUrl = $base_url . '/api/notifications.php';

// Current filters
$currentType = $_GET['type'] ?? '';
$currentFilter = $_GET['filter'] ?? 'all'; // all, unread, read

// Load initial notifications
$notifications = AppNotification::getForUser($userId, 50, 0, $currentType ?: null, $currentFilter === 'unread', $currentFilter === 'read');
$unreadCount = AppNotification::getUnreadCount($userId);
$lastId = AppNotification::getLastId($userId);

// Type metadata
$colorMap = AppNotification::getColorMap();
$iconMap = AppNotification::getIconMap();
$typeLabels = AppNotification::getTypeLabels();
$notifTypes = ['sale', 'purchase', 'product', 'stock', 'expiry', 'expense', 'user', 'system', 'backup'];
?>

<div class="container" style="max-width:960px; margin:0 auto; padding:1.5rem 1rem;">
    <!-- رأس الصفحة -->
    <div class="notif-page-header">
        <h2>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28" style="color:#2563eb;">
                <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>
            </svg>
            <?php echo t('nav_notifications'); ?>
            <span class="notif-badge-count" id="headerUnreadCount" style="background:#2563eb;color:#fff;font-size:0.8rem;font-weight:700;padding:2px 10px;border-radius:12px;min-width:28px;text-align:center;"><?php echo $unreadCount; ?></span>
        </h2>
        <div class="notif-page-header-actions">
            <button class="notif-mark-all-btn" onclick="cleanupOld()" id="cleanupBtn" title="تنظيف الإشعارات القديمة (المقروءة الأقدم من 30 يوم)" style="background:#f59e0b;color:#fff;border-color:#f59e0b;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M4 6h16v2H4z" stroke-linejoin="round"/></svg>
                تنظيف القديمة
            </button>
            <?php if ($unreadCount > 0): ?>
            <button class="notif-mark-all-btn" onclick="markAllRead()" id="markAllBtn" title="<?php echo t('nav_mark_all_read'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo t('nav_mark_all_read'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- فلتر حالة القراءة -->
    <div class="notif-filters">
        <button class="notif-filter-btn <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" onclick="setFilter('all')" data-filter="all">
            الكل
        </button>
        <button class="notif-filter-btn <?php echo $currentFilter === 'unread' ? 'active' : ''; ?>" onclick="setFilter('unread')" data-filter="unread">
            🔴 <?php echo t('notifications_unread'); ?>
            <?php if ($unreadCount > 0): ?>
            <strong>(<?php echo $unreadCount; ?>)</strong>
            <?php endif; ?>
        </button>
        <button class="notif-filter-btn <?php echo $currentFilter === 'read' ? 'active' : ''; ?>" onclick="setFilter('read')" data-filter="read">
            ✓ <?php echo t('notifications_read'); ?>
        </button>
    </div>

    <!-- فلتر حسب النوع -->
    <div class="notif-filters" style="margin-bottom:1.5rem;">
        <?php foreach ($notifTypes as $t): ?>
        <button class="notif-filter-btn <?php echo $currentType === $t ? 'active' : ''; ?>"
                onclick="setTypeFilter('<?php echo $t; ?>')"
                data-type="<?php echo $t; ?>"
                <?php if ($currentType === $t): ?>style="background:<?php echo $colorMap[$t]; ?>;color:#fff;border-color:<?php echo $colorMap[$t]; ?>;"<?php endif; ?>>
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo $currentType === $t ? '#fff' : $colorMap[$t]; ?>;margin-left:4px;"></span>
            <?php echo $iconMap[$t] . ' ' . $typeLabels[$t]; ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- قائمة الإشعارات -->
    <div class="notif-center-list" id="notifList">
        <?php if (empty($notifications)): ?>
        <div class="notif-empty" style="padding:4rem 2rem;">
            <div class="notif-empty-icon">🔔</div>
            <p style="font-size:1rem;font-weight:600;color:#64748b;margin-bottom:4px;">لا توجد إشعارات</p>
            <p style="font-size:0.85rem;color:#94a3b8;">
                <?php if ($currentFilter !== 'all' || $currentType): ?>
                جرّب تغيير الفلاتر لعرض المزيد من الإشعارات
                <?php else: ?>
                ستظهر هنا جميع إشعارات التطبيق عند وقوع الأحداث
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <?php foreach ($notifications as $n): ?>
        <div class="notif-card <?php echo $n['is_read'] ? '' : 'unread'; ?>" id="notif-<?php echo $n['id']; ?>" data-id="<?php echo $n['id']; ?>">
            <div class="notif-card-icon type-<?php echo $n['type']; ?>">
                <?php echo $n['icon']; ?>
            </div>
            <div class="notif-card-body">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span class="notif-card-title"><?php echo htmlspecialchars($n['title']); ?></span>
                    <span class="notif-type-badge type-<?php echo $n['type']; ?>"><?php echo $n['type_label']; ?></span>
                </div>
                <div class="notif-card-message" title="<?php echo htmlspecialchars($n['message']); ?>">
                    <?php echo htmlspecialchars($n['message']); ?>
                </div>
                <div class="notif-card-meta">
                    <span class="notif-card-time">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="vertical-align:middle;margin-left:2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php echo $n['time_ago']; ?>
                    </span>
                </div>
            </div>
            <div class="notif-card-actions">
                <?php if (!$n['is_read']): ?>
                <button class="notif-read-btn" onclick="markRead(<?php echo $n['id']; ?>)" title="<?php echo t('notifications_mark_read'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <?php endif; ?>
                <button class="notif-delete-btn" onclick="deleteNotif(<?php echo $n['id']; ?>)" title="<?php echo t('delete'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- تحميل المزيد -->
    <?php if (count($notifications) >= 50): ?>
    <div class="notif-load-more" id="loadMoreSection">
        <button onclick="loadMore()" id="loadMoreBtn"><?php echo t('notifications_load_more'); ?></button>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
(function() {
    const apiBase = '<?php echo $baseApiUrl; ?>';
    const csrfToken = '<?php echo AppCsrf::generateToken(); ?>';
    let currentFilter = '<?php echo $currentFilter; ?>';
    let currentType = '<?php echo $currentType; ?>';
    let currentOffset = <?php echo count($notifications); ?>;
    let lastId = <?php echo $lastId; ?>;
    let isLoading = false;
    let pollTimer = null;

    // ===== Polling =====
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollNew, 30000);
    }

    function pollNew() {
        fetch(apiBase + '?action=poll&last_id=' + lastId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.new_notifications && data.new_notifications.length > 0) {
                        data.new_notifications.reverse().forEach(n => prependNotification(n));
                        lastId = data.last_id;
                    }
                    updateUnreadBadge(data.unread_count);
                }
            })
            .catch(() => {});
    }

    // ===== Render =====
    function createNotifElement(n) {
        const div = document.createElement('div');
        div.className = 'notif-card' + (n.is_read ? '' : ' unread');
        div.id = 'notif-' + n.id;
        div.dataset.id = n.id;

        const bgColor = n.color || '#64748b';
        div.innerHTML = `
            <div class="notif-card-icon type-${n.type || 'system'}">
                ${n.icon || '🔔'}
            </div>
            <div class="notif-card-body">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span class="notif-card-title">${escapeHtml(n.title)}</span>
                    <span class="notif-type-badge type-${n.type || 'system'}">${n.type_label || n.type}</span>
                </div>
                <div class="notif-card-message" title="${escapeHtml(n.message)}">${escapeHtml(n.message)}</div>
                <div class="notif-card-meta">
                    <span class="notif-card-time">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="vertical-align:middle;margin-left:2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        ${n.time_ago || ''}
                    </span>
                </div>
            </div>
            <div class="notif-card-actions">
                ${!n.is_read ? `<button class="notif-read-btn" onclick="markRead(${n.id})" title="تحديد كمقروء">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
                </button>` : ''}
                <button class="notif-delete-btn" onclick="deleteNotif(${n.id})" title="حذف">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                </button>
            </div>
        `;
        return div;
    }

    function prependNotification(n) {
        const list = document.getElementById('notifList');
        const empty = list.querySelector('.notif-empty');
        if (empty) empty.remove();
        if (document.getElementById('notif-' + n.id)) return;
        list.prepend(createNotifElement(n));
    }

    // ===== Filters =====
    window.setFilter = function(filter) {
        currentFilter = filter;
        currentOffset = 0;
        document.querySelectorAll('.notif-filter-btn[data-filter]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filter === filter);
        });
        reloadList();
    };

    window.setTypeFilter = function(type) {
        currentType = currentType === type ? '' : type;
        currentOffset = 0;
        const colors = <?php echo json_encode($colorMap); ?>;
        document.querySelectorAll('.notif-filter-btn[data-type]').forEach(btn => {
            const isActive = btn.dataset.type === currentType;
            btn.classList.toggle('active', isActive);
            if (isActive) {
                btn.style.background = colors[btn.dataset.type] || '#2563eb';
                btn.style.color = '#fff';
                btn.style.borderColor = colors[btn.dataset.type] || '#2563eb';
            } else {
                btn.style.background = '';
                btn.style.color = '';
                btn.style.borderColor = '';
            }
        });
        reloadList();
    };

    function reloadList() {
        const list = document.getElementById('notifList');
        const loadMoreEl = document.getElementById('loadMoreSection');
        list.innerHTML = '<div style="text-align:center;padding:3rem;"><svg style="animation:spin 1s linear infinite;width:28px;height:28px;color:#94a3b8;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg><style>@keyframes spin{to{transform:rotate(360deg)}}</style></div>';

        let url = apiBase + '?action=list&limit=50&offset=0';
        if (currentFilter === 'unread') url += '&unread=1';
        if (currentFilter === 'read') url += '&read=1';
        if (currentType) url += '&type=' + encodeURIComponent(currentType);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                list.innerHTML = '';
                lastId = data.last_id;
                currentOffset = data.notifications.length;
                updateUnreadBadge(data.unread_count);

                if (data.notifications.length === 0) {
                    list.innerHTML = `
                        <div class="notif-empty" style="padding:4rem 2rem;">
                            <div class="notif-empty-icon">🔔</div>
                            <p style="font-size:1rem;font-weight:600;color:#64748b;margin-bottom:4px;">لا توجد إشعارات</p>
                            <p style="font-size:0.85rem;color:#94a3b8;">جرّب تغيير الفلاتر لعرض المزيد من الإشعارات</p>
                        </div>
                    `;
                    if (loadMoreEl) loadMoreEl.style.display = 'none';
                    return;
                }

                data.notifications.forEach(n => list.appendChild(createNotifElement(n)));
                if (loadMoreEl) {
                    loadMoreEl.style.display = data.notifications.length >= 50 ? 'flex' : 'none';
                }
            })
            .catch(() => {
                list.innerHTML = '<div class="notif-empty" style="padding:4rem 2rem;"><div class="notif-empty-icon">⚠️</div><p style="font-size:1rem;color:#64748b;">خطأ في تحميل الإشعارات</p></div>';
            });
    }

    // ===== Mark read =====
    window.markRead = function(id) {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('notification_id', id);
        formData.append('csrf_token', csrfToken);

        fetch(apiBase, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const el = document.getElementById('notif-' + id);
                if (el) {
                    el.classList.remove('unread');
                    const readBtn = el.querySelector('.notif-read-btn');
                    if (readBtn) readBtn.remove();
                }
                updateUnreadBadge(data.unread_count);
            })
            .catch(() => {});
    };

    // ===== Cleanup old read notifications =====
    window.cleanupOld = function() {
        if (!confirm('حذف الإشعارات المقروءة الأقدم من 30 يوم؟\nالإشعارات غير المقروءة لن تتأثر.')) return;

        const btn = document.getElementById('cleanupBtn');
        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

        const formData = new FormData();
        formData.append('action', 'cleanup');
        formData.append('days', '30');
        formData.append('csrf_token', csrfToken);

        fetch(apiBase, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    if (typeof App !== 'undefined' && App.toast) App.toast(data.message || 'فشل التنظيف', 'error');
                    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                    return;
                }
                if (typeof App !== 'undefined' && App.toast) {
                    App.toast(data.message || 'تم التنظيف بنجاح', 'success');
                }
                updateUnreadBadge(data.unread_count);
                reloadList();
            })
            .catch(() => {
                if (typeof App !== 'undefined' && App.toast) App.toast('خطأ في الاتصال', 'error');
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            });
    };

    // ===== Mark all read =====
    window.markAllRead = function() {
        const btn = document.getElementById('markAllBtn');
        if (btn) btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        formData.append('csrf_token', csrfToken);

        fetch(apiBase, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    if (btn) btn.disabled = false;
                    return;
                }
                document.querySelectorAll('.notif-card.unread').forEach(el => {
                    el.classList.remove('unread');
                    const readBtn = el.querySelector('.notif-read-btn');
                    if (readBtn) readBtn.remove();
                });
                updateUnreadBadge(0);
                if (btn) btn.style.display = 'none';
                if (typeof App !== 'undefined' && App.toast) {
                    App.toast('تم تحديد جميع الإشعارات كمقروءة', 'success');
                }
            })
            .catch(() => {
                if (btn) btn.disabled = false;
            });
    };

    // ===== Delete =====
    window.deleteNotif = function(id) {
        if (!confirm('هل أنت متأكد من حذف هذا الإشعار؟')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('notification_id', id);
        formData.append('csrf_token', csrfToken);

        fetch(apiBase, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const el = document.getElementById('notif-' + id);
                if (el) {
                    el.style.transition = 'all 0.3s';
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(100%)';
                    setTimeout(() => el.remove(), 300);
                }
                updateUnreadBadge(data.unread_count);
            })
            .catch(() => {});
    };

    // ===== Load more =====
    window.loadMore = function() {
        if (isLoading) return;
        isLoading = true;
        const btn = document.getElementById('loadMoreBtn');
        btn.disabled = true;
        btn.textContent = 'جاري التحميل...';

        let url = apiBase + '?action=list&limit=50&offset=' + currentOffset;
        if (currentFilter === 'unread') url += '&unread=1';
        if (currentFilter === 'read') url += '&read=1';
        if (currentType) url += '&type=' + encodeURIComponent(currentType);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const list = document.getElementById('notifList');
                data.notifications.forEach(n => list.appendChild(createNotifElement(n)));
                currentOffset += data.notifications.length;
                lastId = data.last_id;
                updateUnreadBadge(data.unread_count);
                btn.disabled = false;
                btn.textContent = 'تحميل المزيد';
                if (data.notifications.length < 50) {
                    const moreEl = document.getElementById('loadMoreSection');
                    if (moreEl) moreEl.style.display = 'none';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'تحميل المزيد';
            })
            .finally(() => { isLoading = false; });
    };

    // ===== Update badge =====
    function updateUnreadBadge(count) {
        const badge = document.getElementById('headerUnreadCount');
        if (badge) badge.textContent = count;
        const markAllBtn = document.getElementById('markAllBtn');
        if (markAllBtn) markAllBtn.style.display = count > 0 ? '' : 'none';

        // Update NotifSystem badge too (dropdown in navbar)
        if (typeof NotifSystem !== 'undefined') {
            NotifSystem.updateBadge(count);
        }
    }

    // ===== Utility =====
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Start polling
    startPolling();
})();
</script>
