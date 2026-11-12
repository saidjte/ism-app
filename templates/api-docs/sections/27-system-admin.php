<?php
/**
 * قسم توثيق API: إدارة النظام (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 27. إدارة النظام ===== -->
    <div class="section" id="system">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            إدارة النظام
        </div>

        <div class="endpoint open" id="ep-system-info">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/info</span>
                <span class="endpoint-desc">معلومات التطبيق وقاعدة البيانات والخادم — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "app": { "name": "إدارة المخزون", "version": "5.15.0" },
    "database": { "type": "MySQL", "size": "12.5 MB" },
    "stats": { "products": 120, "sales": 1540, "purchases": 980 },
    "server": { "php_version": "8.2.0", "max_upload": "50M" }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-db-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/db-status</span>
                <span class="endpoint-desc">حالة قاعدة البيانات والجداول — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "status": "connected",
    "tables": 18,
    "missing_tables": [],
    "schema_ok": true
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-test-db">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/system/test-db</span>
                <span class="endpoint-desc">اختبار اتصال قاعدة بيانات جديدة — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">host</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>عنوان الخادم</td></tr>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم قاعدة البيانات</td></tr>
                            <tr><td class="param-name">user</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم المستخدم</td></tr>
                            <tr><td class="param-name">pass</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>كلمة المرور</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-db-settings">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/system/db-settings</span>
                <span class="endpoint-desc">تحديث إعدادات قاعدة البيانات (يكتب .env) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">نفس معاملات test-db. يقوم بتحديث ملف .env مباشرة.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-system-changelog">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/changelog</span>
                <span class="endpoint-desc">سجل تغييرات الإصدارات — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-system-permissions">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/permissions</span>
                <span class="endpoint-desc">صلاحيات المستخدم والأدوار — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "user": { "id": 1, "role": "admin" },
    "role": "admin",
    "permissions": ["products.view", "products.add", ...],
    "permissions_grouped": { "products": ["view", "add", "edit", "delete"], ... }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-version">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/version</span>
                <span class="endpoint-desc">معلومات الإصدار — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "app_version": "5.15.0",
    "api_version": "1",
    "php_version": "8.2.0"
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-clear-cache">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/system/clear-cache</span>
                <span class="endpoint-desc">مسح ذاكرة التخزين المؤقت والسجلات القديمة — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">يمسح: ذاكرة الإعدادات المؤقتة، ملفات الكاش، السجلات القديمة.</p>
            </div>
        </div>
    </div>
