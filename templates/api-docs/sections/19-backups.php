<?php
/**
 * قسم توثيق API: النسخ الاحتياطية (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 19. النسخ الاحتياطية ===== -->
    <div class="section" id="backups">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            النسخ الاحتياطية
        </div>

        <div class="endpoint" id="ep-backups-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/backups</span>
                <span class="endpoint-desc">قائمة النسخ الاحتياطية مع الحجم والتاريخ — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-backups-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/backups/create</span>
                <span class="endpoint-desc">إنشاء نسخة احتياطية — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>وصف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/backups/delete</span>
                <span class="endpoint-desc">حذف نسخة احتياطية — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">filename</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم ملف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-restore">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/backups/restore</span>
                <span class="endpoint-desc">استعادة نسخة احتياطية — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">filename</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم ملف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-download">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/backups/download</span>
                <span class="endpoint-desc">تحميل نسخة احتياطية (محمي من path traversal) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">filename</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم ملف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/backups/status</span>
                <span class="endpoint-desc">حالة النسخ الاحتياطي — مصادقة + مدير</span>
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
    "method": "mysqldump",
    "max_files": 10,
    "auto_backup_enabled": true
  }
}
                    </div>
                </div>
            </div>
        </div>
    </div>
