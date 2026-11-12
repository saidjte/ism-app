<?php
/**
 * قسم توثيق API: المشتريات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 5. المشتريات ===== -->
    <div class="section" id="purchases">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            المشتريات
        </div>

        <div class="endpoint open" id="ep-purchases-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/purchases</span>
                <span class="endpoint-desc">قائمة المشتريات مع تصفية وترقيم صفحات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية (Y-m-d)</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية (Y-m-d)</td></tr>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة (افتراضي: 1)</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 20)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-purchases-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/purchases/{id}</span>
                <span class="endpoint-desc">تفاصيل عملية شراء مع عناصر الدفعات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-purchases-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/purchases</span>
                <span class="endpoint-desc">تسجيل عملية شراء جديدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية المشتراة</td></tr>
                            <tr><td class="param-name">cost_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر التكلفة للوحدة</td></tr>
                            <tr><td class="param-name">purchase_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الشراء (افتراضي: اليوم)</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">sale_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر البيع المقترح</td></tr>
                            <tr><td class="param-name">tax_enabled</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>تفعيل الضريبة</td></tr>
                            <tr><td class="param-name">tax_type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نوع الضريبة</td></tr>
                            <tr><td class="param-name">tax_value</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>قيمة الضريبة</td></tr>
                            <tr><td class="param-name">tax_name</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>اسم الضريبة</td></tr>
                            <tr><td class="param-name">batch_items</td><td class="param-type">array</td><td class="param-optional">اختياري</td><td>عناصر الدفعة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-purchases-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/purchases/{id}</span>
                <span class="endpoint-desc">تحديث عملية شراء — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الكمية الجديدة</td></tr>
                            <tr><td class="param-name">purchase_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر الشراء الجديد</td></tr>
                            <tr><td class="param-name">purchase_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الشراء</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-purchases-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/purchases/{id}</span>
                <span class="endpoint-desc">حذف عملية شراء مع تعديل المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--yellow);">عند حذف عملية شراء يتم خصم الكميات تلقائياً من المخزون.</p>
            </div>
        </div>
    </div>
