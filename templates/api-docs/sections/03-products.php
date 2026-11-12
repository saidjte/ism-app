<?php
/**
 * قسم توثيق API: المنتجات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 3. المنتجات ===== -->
    <div class="section" id="products">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            المنتجات
        </div>

        <div class="endpoint open" id="ep-products-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products</span>
                <span class="endpoint-desc">قائمة المنتجات مع بحث وترقيم صفحات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نص البحث (اسم، كود، باركود)</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف للتصفية</td></tr>
                            <tr><td class="param-name">low_stock</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض المنتجات منخفضة المخزون فقط</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة (افتراضي: 1)</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 20، أقصى: 100)</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">cURL</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
curl -X GET "<?php echo htmlspecialchars($base_url); ?>/api/mobile/products?search=حليب&page=1&per_page=20" \
  -H "Authorization: Bearer ism_YOUR_TOKEN_HERE"
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": [
    { "id": 1, "code": "PRD-001", "name": "حليب طازج", "quantity": 150, "purchase_price": 8.00, "selling_price": 12.50, "unit": "لتر", "category_id": 2, "min_quantity": 20 }
  ],
  "pagination": {
    "total": 245,
    "per_page": 20,
    "current_page": 1,
    "total_pages": 13,
    "has_next": true,
    "has_prev": false
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-products-search">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/search</span>
                <span class="endpoint-desc">بحث سريع في المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">q</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>نص البحث</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب التصنيف</td></tr>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد النتائج (5–100، افتراضي: 20)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-products-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/{id}</span>
                <span class="endpoint-desc">تفاصيل منتج واحد مع وحداته ودفعاته — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات المسار</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-products-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/products</span>
                <span class="endpoint-desc">إضافة منتج جديد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم المنتج</td></tr>
                            <tr><td class="param-name">code</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>كود المنتج (تلقائي إن لم يُحدد)</td></tr>
                            <tr><td class="param-name">purchase_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر الشراء</td></tr>
                            <tr><td class="param-name">selling_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر البيع</td></tr>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية</td></tr>
                            <tr><td class="param-name">unit</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>وحدة القياس</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                            <tr><td class="param-name">min_quantity</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الحد الأدنى للمخزون</td></tr>
                            <tr><td class="param-name">has_expiry</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>هل المنتج له تاريخ انتهاء؟</td></tr>
                            <tr><td class="param-name">additional_units</td><td class="param-type">array</td><td class="param-optional">اختياري</td><td>وحدات إضافية للمنتج</td></tr>
                            <tr><td class="param-name">expiry_dates</td><td class="param-type">array</td><td class="param-optional">اختياري</td><td>تواريخ انتهاء الصلاحية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-products-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/products/{id}</span>
                <span class="endpoint-desc">تحديث منتج (جزئي) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">أرسل فقط الحقول المراد تحديثها. الحقول غير المرسلة تبقى بدون تغيير. نفس معاملات POST لكن جميعها اختيارية.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-products-patch">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-patch">PATCH</span>
                <span class="endpoint-path">/api/mobile/products/{id}</span>
                <span class="endpoint-desc">تحديث جزئي لمنتج — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">يعمل مثل PUT — تحديث جزئي للمنتج.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-products-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/products/{id}</span>
                <span class="endpoint-desc">حذف منتج — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--red);">لا يمكن حذف منتج مرتبط بمبيعات أو مشتريات. يجب حذف العمليات المرتبطة أولاً.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-products-units">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/{id}/units</span>
                <span class="endpoint-desc">وحدات المنتج مع أسعار البيع — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-products-purchase-units">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/{id}/purchase-units</span>
                <span class="endpoint-desc">معلومات وحدة الشراء للمنتج — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>
