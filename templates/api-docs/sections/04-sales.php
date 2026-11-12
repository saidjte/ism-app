<?php
/**
 * قسم توثيق API: المبيعات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 4. المبيعات ===== -->
    <div class="section" id="sales">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            المبيعات
        </div>

        <div class="endpoint open" id="ep-sales-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/sales</span>
                <span class="endpoint-desc">قائمة المبيعات مع تصفية وترقيم صفحات — مصادقة</span>
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

        <div class="endpoint" id="ep-sales-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/sales/{id}</span>
                <span class="endpoint-desc">تفاصيل عملية بيع مع عناصر الدفعات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-sales-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/sales</span>
                <span class="endpoint-desc">تسجيل عملية بيع جديدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">items</td><td class="param-type">array</td><td class="param-required">مطلوب</td><td>قائمة عناصر البيع</td></tr>
                            <tr><td class="param-name">items[].product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">items[].quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية المباعة</td></tr>
                            <tr><td class="param-name">items[].selling_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر البيع للوحدة</td></tr>
                            <tr><td class="param-name">sale_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البيع (افتراضي: اليوم)</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">discount</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الخصم</td></tr>
                            <tr><td class="param-name">paid_amount</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>المبلغ المدفوع</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
{
  "items": [
    {"product_id": 1, "quantity": 5, "selling_price": 12.50},
    {"product_id": 3, "quantity": 2, "selling_price": 45.00}
  ],
  "sale_date": "2025-05-20",
  "notes": "بيع من الهاتف",
  "discount": 5.00,
  "paid_amount": 100.00
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-sales-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/sales/{id}</span>
                <span class="endpoint-desc">تحديث عملية بيع — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الكمية الجديدة</td></tr>
                            <tr><td class="param-name">selling_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر البيع الجديد</td></tr>
                            <tr><td class="param-name">sale_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البيع</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-sales-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/sales/{id}</span>
                <span class="endpoint-desc">حذف عملية بيع مع استرجاع المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--yellow);">عند حذف عملية بيع يتم استرجاع الكميات تلقائياً إلى المخزون.</p>
            </div>
        </div>
    </div>
