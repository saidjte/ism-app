<?php
/**
 * قسم توثيق API: بطاقات المنتجات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 17. بطاقات المنتجات ===== -->
    <div class="section" id="labels">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            بطاقات المنتجات
        </div>

        <div class="endpoint" id="ep-labels-data">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/labels/data</span>
                <span class="endpoint-desc">بيانات بطاقات المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_ids</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>معرفات المنتجات (مفصولة بفاصلة)</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بحث</td></tr>
                        </tbody>
                    </table>
                </div>
                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">يتضمن: products, categories, layouts, settings</p>
            </div>
        </div>

        <div class="endpoint" id="ep-labels-generate">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/labels/generate</span>
                <span class="endpoint-desc">إنشاء بطاقات المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_ids</td><td class="param-type">array</td><td class="param-required">مطلوب</td><td>قائمة معرفات المنتجات</td></tr>
                            <tr><td class="param-name">quantities</td><td class="param-type">object</td><td class="param-optional">اختياري</td><td>عدد النسخ لكل منتج</td></tr>
                            <tr><td class="param-name">label_layout</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>extra_large|wide|large|medium|small|tiny</td></tr>
                            <tr><td class="param-name">show_purchase</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض سعر الشراء</td></tr>
                            <tr><td class="param-name">show_code</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض الكود</td></tr>
                            <tr><td class="param-name">show_barcode</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض الباركود</td></tr>
                            <tr><td class="param-name">show_category</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض التصنيف</td></tr>
                            <tr><td class="param-name">show_date</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض التاريخ</td></tr>
                            <tr><td class="param-name">store_name</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض اسم المتجر</td></tr>
                            <tr><td class="param-name">code_type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>barcode|qrcode|none</td></tr>
                            <tr><td class="param-name">barcode_format</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>صيغة الباركود</td></tr>
                            <tr><td class="param-name">price_display</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>طريقة عرض السعر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
