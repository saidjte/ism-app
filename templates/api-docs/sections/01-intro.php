<?php
/**
 * قسم توثيق API: مقدمة (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 1. مقدمة ===== -->
    <div class="section" id="intro">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            مقدمة
        </div>
        <div class="info-grid">
            <div class="info-card">
                <h3 style="color:var(--green);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    العنوان الأساسي
                </h3>
                <div class="code-block" style="margin-top:8px;">
                    <span class="lang-badge">URL</span>
                    <?php echo htmlspecialchars($base_url); ?>/api/mobile/
                </div>
                <p style="margin-top:8px;font-size:0.84rem;color:var(--text-muted);">جميع نقاط النهاية تبدأ بهذا العنوان. استخدم المسارات النسبية المذكورة أدناه.</p>
            </div>
            <div class="info-card">
                <h3 style="color:var(--primary-light);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    صيغة البيانات
                </h3>
                <ul>
                    <li>الطلبات: <code style="color:var(--cyan);">Content-Type: application/json</code></li>
                    <li>الاستجابات: <code style="color:var(--cyan);">application/json; charset=utf-8</code></li>
                    <li>التشفير: UTF-8</li>
                    <li>لا حاجة لـ CSRF — الرموز بديل آمن</li>
                </ul>
            </div>
            <div class="info-card">
                <h3 style="color:var(--purple);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    الأمان
                </h3>
                <ul>
                    <li>الرموز مُخزّنة مشفّرة (SHA-256)</li>
                    <li>الرمز الأصلي يظهر مرة واحدة فقط</li>
                    <li>انتهاء صلاحية تلقائي (90 يوم افتراضياً)</li>
                    <li>إبطال فوري عند الحاجة</li>
                </ul>
            </div>
        </div>
        <div class="info-grid" style="margin-top:16px;">
            <div class="info-card">
                <h3 style="color:var(--cyan);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    صيغة الاستجابة الناجحة
                </h3>
                <div class="code-block" style="margin-top:8px;">
                    <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": { ... },
  "message": "رسالة توضيحية",
  "pagination": { "total": 100, "per_page": 20, "current_page": 1, "total_pages": 5, "has_next": true, "has_prev": false }
}
                </div>
            </div>
            <div class="info-card">
                <h3 style="color:var(--red);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    صيغة الاستجابة الخاطئة
                </h3>
                <div class="code-block" style="margin-top:8px;">
                    <span class="lang-badge">JSON</span>
{
  "success": false,
  "error": "رسالة الخطأ",
  "error_type": "validation_error",
  "status_code": 422
}
                </div>
            </div>
        </div>
    </div>
