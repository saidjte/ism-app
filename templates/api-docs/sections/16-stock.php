<?php
/**
 * قسم توثيق API: المخزون (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 16. المخزون ===== -->
    <div class="section" id="stock">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            المخزون
        </div>

        <div class="endpoint" id="ep-stock-transfer">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/stock/transfer</span>
                <span class="endpoint-desc">تحويل مخزون بين منتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">from_product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج المصدر</td></tr>
                            <tr><td class="param-name">to_product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج الهدف</td></tr>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية المحولة</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-update-qty">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/stock/update-qty</span>
                <span class="endpoint-desc">تحديث كمية منتج مباشرة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">new_qty</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية الجديدة (0 على الأقل)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-inventory-count">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/stock/inventory-count</span>
                <span class="endpoint-desc">تسجيل جرد المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">count_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الجرد</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">items</td><td class="param-type">array</td><td class="param-required">مطلوب</td><td>قائمة عناصر الجرد</td></tr>
                            <tr><td class="param-name">items[].product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">items[].actual_qty</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية الفعلية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-inventory-data">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/stock/inventory-data</span>
                <span class="endpoint-desc">بيانات الجرد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب التصنيف</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-last-count">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/stock/last-count</span>
                <span class="endpoint-desc">آخر جرد مخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>
