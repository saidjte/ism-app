<?php
/**
 * قسم توثيق API: وحدات القياس (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 25. وحدات القياس ===== -->
    <div class="section" id="units">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
            وحدات القياس
        </div>

        <div class="endpoint" id="ep-units-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/units</span>
                <span class="endpoint-desc">قائمة وحدات القياس — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بحث</td></tr>
                        </tbody>
                    </table>
                </div>
                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">يرجع 10 وحدات افتراضية إذا لم تكن جدول الوحدات موجوداً</p>
            </div>
        </div>

        <div class="endpoint" id="ep-units-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/units/{id}</span>
                <span class="endpoint-desc">تفاصيل وحدة قياس — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-units-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/units</span>
                <span class="endpoint-desc">إضافة وحدة قياس جديدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم الوحدة (50 حرف على الأكثر)</td></tr>
                            <tr><td class="param-name">symbol</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>رمز الوحدة</td></tr>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>الوصف</td></tr>
                            <tr><td class="param-name">is_base_unit</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>هل هي وحدة أساسية؟</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-units-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/units/{id}</span>
                <span class="endpoint-desc">تحديث وحدة قياس (يتدفق إلى المنتجات) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">معاملات: name, symbol, description, is_base_unit. التغييرات تتدفق تلقائياً للمنتجات المرتبطة.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-units-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/units/{id}</span>
                <span class="endpoint-desc">حذف وحدة قياس — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--red);">لا يمكن حذف وحدة مستخدمة من قبل منتجات.</p>
            </div>
        </div>
    </div>
