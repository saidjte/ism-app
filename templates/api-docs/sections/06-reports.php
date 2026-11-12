<?php
/**
 * قسم توثيق API: التقارير (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 6. التقارير ===== -->
    <div class="section" id="reports">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            التقارير
        </div>

        <div class="endpoint" id="ep-reports-dashboard">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/dashboard</span>
                <span class="endpoint-desc">إحصائيات لوحة التحكم الأساسية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint open" id="ep-reports-profits">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/profits</span>
                <span class="endpoint-desc">تقرير الأرباح (SaleService موحّد) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>تاريخ البداية (Y-m-d)</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>تاريخ النهاية (Y-m-d)</td></tr>
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
    "total_sales": 45230.00,
    "total_purchases": 28500.00,
    "total_expenses": 3200.00,
    "gross_profit": 16730.00,
    "net_profit": 13530.00,
    "profit_margin": 29.9
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-reports-stock">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/stock</span>
                <span class="endpoint-desc">تقرير المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب التصنيف</td></tr>
                            <tr><td class="param-name">low_stock_only</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>عرض المخزون المنخفض فقط</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-reports-expiry">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/expiry</span>
                <span class="endpoint-desc">تقرير المنتجات قريبة الانتهاء — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">days_ahead</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأيام القادمة (افتراضي: 30)</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-reports-categories">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/categories</span>
                <span class="endpoint-desc">إحصائيات التصنيفات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-reports-sales">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/sales</span>
                <span class="endpoint-desc">تقرير المبيعات التفصيلي — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نص البحث</td></tr>
                        </tbody>
                    </table>
                </div>
                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">يتضمن: summary, by_product, by_category, daily_trend, top_products</p>
            </div>
        </div>

        <div class="endpoint" id="ep-reports-purchases">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/purchases</span>
                <span class="endpoint-desc">تقرير المشتريات التفصيلي — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">نفس معاملات تقرير المبيعات. يتضمن: summary, by_product, by_category, daily_trend, price_changes</p>
            </div>
        </div>

        <div class="endpoint" id="ep-reports-comparison">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/reports/comparison</span>
                <span class="endpoint-desc">مقارنة الفترات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">mode</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نوع المقارنة: month|quarter|year|custom</td></tr>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية (للوضع custom)</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية (للوضع custom)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
