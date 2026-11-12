<?php
/**
 * قسم توثيق API: صور المنتجات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 13. صور المنتجات ===== -->
    <div class="section" id="product-images">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            صور المنتجات
        </div>

        <div class="endpoint" id="ep-images-upload">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/products/{id}/images</span>
                <span class="endpoint-desc">رفع صورة منتج (multipart/form-data) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات النموذج (Multipart)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">image</td><td class="param-type">file</td><td class="param-required">مطلوب</td><td>ملف الصورة (أقصى 5MB)</td></tr>
                            <tr><td class="param-name">remove_bg</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>إزالة الخلفية</td></tr>
                            <tr><td class="param-name">bg_tolerance</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تفاوت الخلفية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-images-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/{id}/images</span>
                <span class="endpoint-desc">قائمة صور المنتج — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-images-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/images/{id}</span>
                <span class="endpoint-desc">حذف صورة (حذف ناعم) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-images-set-primary">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/images/{id}/set-primary</span>
                <span class="endpoint-desc">تعيين صورة كرئيسية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>
