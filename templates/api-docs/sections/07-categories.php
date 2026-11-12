<?php
/**
 * قسم توثيق API: التصنيفات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 7. التصنيفات ===== -->
    <div class="section" id="categories">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            التصنيفات
        </div>

        <div class="endpoint open" id="ep-categories-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/categories</span>
                <span class="endpoint-desc">قائمة التصنيفات مع عدد المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": [
    { "id": 1, "name": "ألبان", "description": "منتجات الألبان", "color": "#3b82f6", "product_count": 25 }
  ]
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-categories-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/categories</span>
                <span class="endpoint-desc">إضافة تصنيف جديد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم التصنيف</td></tr>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>الوصف</td></tr>
                            <tr><td class="param-name">color</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>اللون (مثل: #3b82f6)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-categories-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/categories/{id}</span>
                <span class="endpoint-desc">تحديث تصنيف — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">أرسل الحقول المراد تحديثها: name, description, color</p>
            </div>
        </div>

        <div class="endpoint" id="ep-categories-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/categories/{id}</span>
                <span class="endpoint-desc">حذف تصنيف — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--red);">لا يمكن حذف تصنيف مرتبط بمنتجات. يجب نقل المنتجات أولاً.</p>
            </div>
        </div>
    </div>
