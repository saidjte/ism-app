<?php
/**
 * قسم توثيق API: الإشعارات الفورية (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 23. الإشعارات الفورية ===== -->
    <div class="section" id="push">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/><path d="M2 8l2-2m16 2l2-2"/></svg>
            الإشعارات الفورية
        </div>

        <div class="endpoint" id="ep-push-subscribe">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/push/subscribe</span>
                <span class="endpoint-desc">اشتراك في الإشعارات الفورية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">endpoint</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>عنوان الاشتراك</td></tr>
                            <tr><td class="param-name">p256dh</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>مفتاح التشفير العام</td></tr>
                            <tr><td class="param-name">auth</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>مفتاح المصادقة</td></tr>
                            <tr><td class="param-name">device_type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>android|ios|web</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-push-unsubscribe">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/push/unsubscribe</span>
                <span class="endpoint-desc">إلغاء الاشتراك في الإشعارات الفورية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">endpoint</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>عنوان الاشتراك</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-push-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/push/status</span>
                <span class="endpoint-desc">حالة الإشعارات الفورية — مصادقة</span>
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
    "subscriptions_count": 3,
    "devices": { "android": 1, "ios": 0, "web": 2 }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-push-permission">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/push/permission</span>
                <span class="endpoint-desc">تسجيل حالة إذن الإشعارات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">permission</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>granted|denied|default</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
