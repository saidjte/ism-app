<?php
/**
 * قسم توثيق API: التدقيق (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 20. التدقيق ===== -->
    <div class="section" id="audit">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            التدقيق
        </div>

        <div class="endpoint" id="ep-audit-logs">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/audit/logs</span>
                <span class="endpoint-desc">سجلات التدقيق — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">user_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب المستخدم</td></tr>
                            <tr><td class="param-name">action</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تصفية حسب الإجراء</td></tr>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد السجلات (10–1000)</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-audit-error-logs">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/audit/error-logs</span>
                <span class="endpoint-desc">سجلات الأخطاء — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">file</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملف السجل</td></tr>
                            <tr><td class="param-name">level</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>مستوى الخطأ</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 100)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-audit-stats">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/audit/stats</span>
                <span class="endpoint-desc">إحصائيات التدقيق — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">معاملات: date_from, date_to. يتضمن: by_action, by_user, recent</p>
            </div>
        </div>
    </div>
