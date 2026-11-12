<?php
/**
 * قسم توثيق API: الدفعات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 28. الدفعات ===== -->
    <div class="section" id="batches">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            الدفعات
        </div>

        <div class="endpoint open" id="ep-batches-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/{id}/batches</span>
                <span class="endpoint-desc">دفعات المنتج مع السعر المرجح — مصادقة</span>
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
    "product_id": 1,
    "weighted_price": 9.50,
    "batches": [
      { "id": 10, "batch_number": "B-2025-001", "quantity": 50, "remaining_quantity": 30, "unit_price": 8.00, "purchase_date": "2025-05-10", "expiry_date": "2025-08-10" }
    ]
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-batches-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/batches</span>
                <span class="endpoint-desc">إضافة دفعة جديدة (تحدّث كمية المنتج) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية</td></tr>
                            <tr><td class="param-name">unit_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر الوحدة</td></tr>
                            <tr><td class="param-name">purchase_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الشراء</td></tr>
                            <tr><td class="param-name">expiry_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الانتهاء</td></tr>
                            <tr><td class="param-name">batch_number</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>رقم الدفعة (تلقائي إن لم يُحدد)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-batches-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/batches/{id}</span>
                <span class="endpoint-desc">تحديث دفعة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">expiry_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الانتهاء</td></tr>
                            <tr><td class="param-name">batch_number</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>رقم الدفعة</td></tr>
                            <tr><td class="param-name">remaining_quantity</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الكمية المتبقية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-batches-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/batches/{id}</span>
                <span class="endpoint-desc">حذف دفعة (يُخصّم المتبقي من المخزون) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--yellow);">عند حذف دفعة يتم خصم الكمية المتبقية من مخزون المنتج تلقائياً.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-batches-expiring">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/batches/expiring</span>
                <span class="endpoint-desc">الدفعات قريبة الانتهاء — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">days</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأيام القادمة (افتراضي: 30)</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
