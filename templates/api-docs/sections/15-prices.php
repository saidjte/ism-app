<?php
/**
 * قسم توثيق API: الأسعار (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 15. الأسعار ===== -->
    <div class="section" id="prices">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            الأسعار
        </div>

        <div class="endpoint" id="ep-prices-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/prices/list</span>
                <span class="endpoint-desc">قائمة أسعار المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نص البحث</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-prices-history">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/prices/history</span>
                <span class="endpoint-desc">سجل تغييرات الأسعار — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بحث</td></tr>
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>all|purchase|selling</td></tr>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 50)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-prices-history-product">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/prices/history/{id}</span>
                <span class="endpoint-desc">سجل أسعار منتج محدد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>
