<?php
/**
 * قسم توثيق API: واتساب (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 22. واتساب ===== -->
    <div class="section" id="whatsapp">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
            واتساب
        </div>

        <div class="endpoint" id="ep-whatsapp-send-alert">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/whatsapp/send-alert</span>
                <span class="endpoint-desc">إرسال تنبيه واتساب عبر UltraMsg — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">message</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>رسالة مخصصة (افتراضي: التقرير اليومي)</td></tr>
                            <tr><td class="param-name">force</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>إرسال حتى لو مُعطل</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-whatsapp-test">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/whatsapp/test</span>
                <span class="endpoint-desc">اختبار اتصال واتساب — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">instance_id</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>معرف مثيل UltraMsg</td></tr>
                            <tr><td class="param-name">api_token</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>رمز API</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-whatsapp-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/whatsapp/status</span>
                <span class="endpoint-desc">حالة واتساب — مصادقة + مدير</span>
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
    "enabled": true,
    "configured": true,
    "phone": "+213XXXXXXXXX"
  }
}
                    </div>
                </div>
            </div>
        </div>
    </div>
