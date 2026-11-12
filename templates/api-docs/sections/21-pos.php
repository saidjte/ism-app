<?php
/**
 * قسم توثيق API: نقطة البيع (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 21. نقطة البيع ===== -->
    <div class="section" id="pos">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20"/><path d="M6 14h.01"/><path d="M10 14h4"/></svg>
            نقطة البيع
        </div>

        <div class="endpoint" id="ep-pos-products">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/pos/products</span>
                <span class="endpoint-desc">بحث منتجات نقطة البيع — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">q</td><td class="param-type">string</td><td class="param-required">مطلوب*</td><td>نص البحث (مطلوب ما لم يُرسل category_id)</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد النتائج (5–50)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-pos-product-images">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/pos/product-images</span>
                <span class="endpoint-desc">صور منتجات متعددة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">ids</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>معرفات المنتجات (مفصولة بفاصلة)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint open" id="ep-pos-checkout">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/pos/checkout</span>
                <span class="endpoint-desc">إتمام عملية البيع مع إرجاع بيانات الإيصال — مصادقة</span>
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
                            <tr><td class="param-name">items[].quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية</td></tr>
                            <tr><td class="param-name">items[].selling_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر البيع</td></tr>
                            <tr><td class="param-name">sale_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البيع</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">discount</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الخصم</td></tr>
                            <tr><td class="param-name">paid_amount</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>المبلغ المدفوع</td></tr>
                            <tr><td class="param-name">source</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>المصدر (افتراضي: pos_mobile)</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "sale_id": 42,
    "receipt": {
      "items": [...],
      "total": 125.00,
      "discount": 5.00,
      "paid": 120.00,
      "change": 0.00
    }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-pos-settings">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/pos/settings</span>
                <span class="endpoint-desc">إعدادات نقطة البيع، المتجر، الضريبة، الطابعة، العملة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-pos-categories">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/pos/categories</span>
                <span class="endpoint-desc">تصنيفات نقطة البيع (تشمل "الكل" في الموضع 0) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>
