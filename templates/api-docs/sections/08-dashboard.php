<?php
/**
 * قسم توثيق API: لوحة التحكم (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 8. لوحة التحكم ===== -->
    <div class="section" id="dashboard">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            لوحة التحكم
        </div>

        <div class="endpoint" id="ep-dashboard-summary">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/summary</span>
                <span class="endpoint-desc">ملخص سريع (اليوم + الشهر) مع تنبيهات الصلاحية والمخزون المنخفض — مصادقة</span>
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
    "products": {
      "total": 245,
      "stock_quantity": 15230,
      "stock_value": 89500.00,
      "low_stock": 5,
      "low_stock_products": [
        { "id": 3, "name": "حليب طازج", "code": "PRD-003", "quantity": 2, "unit": "لتر", "min_quantity": 10, "category_name": "ألبان" }
      ]
    },
    "today": { "sales": 1250, "profit": 380, "sales_count": 8, "purchases": 800, "purchase_count": 3, "expenses": 120 },
    "month": { "sales": 28000, "profit": 8500, "sales_count": 150 },
    "expiry_alerts": {
      "enabled": true,
      "expired": [{ "id": 12, "name": "زبادي", "expiry_date": "2025-05-01", "days_expired": 37 }],
      "expiring": [{ "id": 15, "name": "جبنة", "expiry_date": "2025-06-15", "days_remaining": 8 }],
      "expired_count": 1,
      "expiring_count": 2,
      "expired_value": 150,
      "alert_days": 30,
      "signature": "abc123..."
    },
    "currency": "د.م."
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint open" id="ep-dashboard-stats">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/stats</span>
                <span class="endpoint-desc">إحصائيات غنية باستخدام DashboardData + تنبيهات الصلاحية والمخزون — مصادقة</span>
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
                        </tbody>
                    </table>
                </div>
                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">يتضمن: مقارنة الفترات، الأرباح (SaleService)، التغييرات، monthly_stats، العناصر الأخيرة، تنبيهات الصلاحية، تنبيهات المخزون المنخفض</p>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "period_comparison": { "current": {...}, "previous": {...}, "changes": {...} },
    "profits": { "total_sales": 45230, "total_purchases": 28500, "gross_profit": 16730, "net_profit": 13530 },
    "changes": { "sales_change": 12.5, "purchases_change": 8.3, "profit_change": 15.2 },
    "monthly_stats": [...],
    "recent_items": { "products": [...], "purchases": [...], "sales": [...], "expenses": [...] }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-dashboard-chart-data">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/chart-data</span>
                <span class="endpoint-desc">بيانات الرسوم البيانية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>sales_trend|top_products|stock_distribution|monthly_comparison</td></tr>
                            <tr><td class="param-name">days</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأيام (7–90)</td></tr>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                            <tr><td class="param-name">months</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأشهر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-dashboard-monthly">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/monthly</span>
                <span class="endpoint-desc">بيانات الرسم الشهري مع تحسين GROUP BY — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">months</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأشهر (3–12)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-dashboard-recent">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/recent</span>
                <span class="endpoint-desc">أحدث المنتجات، المشتريات، المبيعات، المصروفات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (1–20)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Dashboard Alerts -->
        <div class="endpoint" id="ep-dashboard-alerts">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/alerts</span>
                <span class="endpoint-desc">تنبيهات الصلاحية + المخزون المنخفض — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">expiry_days</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأيام لاعتبار المنتج قريب الصلاحية (افتراضي: من الإعدادات أو 30)</td></tr>
                            <tr><td class="param-name">expiry_limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>أقصى عدد منتجات في تنبيهات الصلاحية (1–50)</td></tr>
                            <tr><td class="param-name">low_stock_limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>أقصى عدد منتجات منخفضة المخزون (1–50)</td></tr>
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
    "summary": {
      "total_alerts": 8,
      "expiry_alerts_count": 3,
      "expired_count": 1,
      "expiring_count": 2,
      "expired_value": 450.00,
      "low_stock_count": 5,
      "batch_expiry_count": 2,
      "has_critical_alerts": true
    },
    "expiry": {
      "enabled": true,
      "expired": [
        { "id": 12, "name": "زبادي", "code": "PRD-012", "quantity": 30, "unit": "علبة", "expiry_date": "2025-05-01", "days_expired": 37, "purchase_price": 5, "selling_price": 8, "category_name": "ألبان" }
      ],
      "expiring": [
        { "id": 15, "name": "جبنة", "code": "PRD-015", "quantity": 20, "unit": "علبة", "expiry_date": "2025-06-15", "days_remaining": 8, "purchase_price": 12, "selling_price": 18, "category_name": "ألبان" }
      ],
      "expired_count": 1,
      "expiring_count": 2,
      "expired_value": 150.00,
      "alert_days": 30,
      "signature": "abc123..."
    },
    "low_stock": [
      { "id": 3, "name": "حليب طازج", "code": "PRD-003", "quantity": 2, "unit": "لتر", "min_quantity": 10, "purchase_price": 6, "selling_price": 9, "category_name": "ألبان", "stock_status": "below_minimum", "expected_minimum": 10 },
      { "id": 7, "name": "عصير برتقال", "code": "PRD-007", "quantity": 0, "unit": "علبة", "min_quantity": 5, "purchase_price": 4, "selling_price": 7, "category_name": "مشروبات", "stock_status": "out_of_stock", "expected_minimum": 5 }
    ],
    "batches": [
      { "id": 5, "product_id": 12, "batch_number": "B-20250401-0012", "remaining_quantity": 15, "unit_price": 5, "expiry_date": "2025-05-01", "product_name": "زبادي", "product_code": "PRD-012", "unit": "علبة", "days_to_expiry": -37, "expiry_status": "expired" }
    ]
  }
}
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>حالات المخزون (stock_status)</h4>
                    <table class="params-table">
                        <thead><tr><th>الحالة</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">out_of_stock</td><td style="color:var(--red);">الكمية = 0 — نفد المخزون</td></tr>
                            <tr><td class="param-name">below_minimum</td><td style="color:var(--yellow);">الكمية أقل من الحد الأدنى المحدد (min_quantity)</td></tr>
                            <tr><td class="param-name">below_threshold</td><td style="color:var(--yellow);">الكمية أقل من عتبة التنبيه (low_stock_threshold) — للمنتجات بدون min_quantity</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Dashboard Expiry Alerts (v5.16.1) -->
        <div class="endpoint open" id="ep-dashboard-expiry-alerts">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/expiry-alerts</span>
                <span class="endpoint-desc" style="color:var(--primary-light);font-weight:600;">NEW v5.16.1 — تنبيهات الصلاحية فقط (منفصلة) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>ملاحظة</h4>
                    <p>هذا المسار يرجع <b>جميع</b> تنبيهات الصلاحية بدون حد أقصى — التنبيهات يجب أن تكون كاملة حتى لا يفوتك تنبيه حرج. الإحصائيات تُحسب من استعلامات COUNT مستقلة لضمان الدقة.</p>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "stats": {
      "expired_count": 2,
      "expiring_count": 3,
      "total_alerts": 5,
      "expired_value": 450.00,
      "batch_count": 1,
      "has_critical": true,
      "severity": "critical",
      "enabled": true,
      "alert_days": 30
    },
    "expired": [
      { "id": 12, "name": "زبادي", "code": "PRD-012", "quantity": 30, "unit": "علبة", "expiry_date": "2025-05-01", "days_expired": 37, "purchase_price": 5, "selling_price": 8, "category_name": "ألبان" }
    ],
    "expiring": [
      { "id": 15, "name": "جبنة", "code": "PRD-015", "quantity": 20, "unit": "علبة", "expiry_date": "2025-06-15", "days_remaining": 8, "purchase_price": 12, "selling_price": 18, "category_name": "ألبان" }
    ],
    "batches": [
      { "id": 5, "product_id": 12, "batch_number": "B-001", "remaining_quantity": 15, "unit_price": 5, "expiry_date": "2025-05-01", "product_name": "زبادي", "days_to_expiry": -37, "expiry_status": "expired" }
    ],
    "signature": "abc123..."
  }
}
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>ملاحظة</h4>
                    <p>هذا المسار يرجع تنبيهات الصلاحية <b>فقط</b>. لتنبيهات المخزون المنخفض استخدم <code>/dashboard/low-stock-alerts</code>. للحصول على الكل معاً استخدم <code>/dashboard/alerts</code>.</p>
                </div>
            </div>
        </div>

        <!-- Dashboard Low Stock Alerts (v5.16.1) -->
        <div class="endpoint open" id="ep-dashboard-low-stock-alerts">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/low-stock-alerts</span>
                <span class="endpoint-desc" style="color:var(--primary-light);font-weight:600;">NEW v5.16.1 — تنبيهات المخزون المنخفض فقط (منفصلة) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>ملاحظة</h4>
                    <p>هذا المسار يرجع <b>جميع</b> تنبيهات المخزون المنخفض بدون حد أقصى — التنبيهات يجب أن تكون كاملة حتى لا يفوتك منتج نفد مخزونه. الإحصائيات تُحسب من استعلامات COUNT مستقلة لضمان الدقة.</p>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "stats": {
      "total_count": 5,
      "out_of_stock_count": 1,
      "below_minimum_count": 3,
      "below_threshold_count": 1,
      "total_value": 350.00,
      "has_critical": true,
      "severity": "critical",
      "enabled": true,
      "threshold": 5
    },
    "products": [
      { "id": 7, "name": "عصير برتقال", "code": "PRD-007", "quantity": 0, "unit": "علبة", "min_quantity": 5, "purchase_price": 4, "selling_price": 7, "category_name": "مشروبات", "stock_status": "out_of_stock", "expected_minimum": 5 },
      { "id": 3, "name": "حليب طازج", "code": "PRD-003", "quantity": 2, "unit": "لتر", "min_quantity": 10, "purchase_price": 6, "selling_price": 9, "category_name": "ألبان", "stock_status": "below_minimum", "expected_minimum": 10 }
    ]
  }
}
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>حالات المخزون (stock_status)</h4>
                    <table class="params-table">
                        <thead><tr><th>الحالة</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">out_of_stock</td><td style="color:var(--red);">الكمية = 0 — نفد المخزون</td></tr>
                            <tr><td class="param-name">below_minimum</td><td style="color:var(--yellow);">الكمية أقل من الحد الأدنى المحدد (min_quantity)</td></tr>
                            <tr><td class="param-name">below_threshold</td><td style="color:var(--yellow);">الكمية أقل من عتبة التنبيه (low_stock_threshold) — للمنتجات بدون min_quantity</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>ملاحظة</h4>
                    <p>هذا المسار يرجع تنبيهات المخزون المنخفض <b>فقط</b>. لتنبيهات الصلاحية استخدم <code>/dashboard/expiry-alerts</code>. للحصول على الكل معاً استخدم <code>/dashboard/alerts</code>.</p>
                </div>
            </div>
        </div>

        <!-- Dashboard Cards (v5.16.0) -->
        <div class="endpoint open" id="ep-dashboard-cards">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/cards</span>
                <span class="endpoint-desc" style="color:var(--primary-light);font-weight:600;">NEW v5.16 — بطاقات لوحة التحكم (مخزون منخفض + صلاحية منتهية + صلاحية قريبة + أفضل المنتجات مبيعاً) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">period</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>فترة أفضل المنتجات: today|week|month|all (افتراضي: month)</td></tr>
                            <tr><td class="param-name">card</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بطاقة محددة فقط: low_stock|expired|expiring|top_products (اختياري — يرجع الكل إن لم يُحدد)</td></tr>
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
    "low_stock": {
      "enabled": true,
      "count": 5,
      "items": [
        { "id": 3, "name": "حليب طازج", "code": "PRD-003", "quantity": 2, "unit": "لتر", "min_quantity": 10, "selling_price": 12, "category_name": "ألبان", "status": "below_minimum", "severity_level": "warning" }
      ],
      "total_value": 350,
      "threshold": 5,
      "severity": "warning"
    },
    "expired": {
      "enabled": true,
      "count": 2,
      "items": [
        { "id": 12, "name": "زبادي", "code": "PRD-012", "quantity": 30, "unit": "علبة", "expiry_date": "2025-05-01", "days_expired": 37, "urgency": "expired", "category_name": "ألبان" }
      ],
      "loss_value": 450,
      "alert_days": 30,
      "severity": "critical"
    },
    "expiring": {
      "enabled": true,
      "count": 3,
      "items": [
        { "id": 15, "name": "جبنة", "code": "PRD-015", "quantity": 20, "unit": "علبة", "expiry_date": "2025-06-15", "days_remaining": 8, "urgency": "urgent", "category_name": "ألبان" }
      ],
      "at_risk_value": 600,
      "alert_days": 30,
      "severity": "info"
    },
    "top_products": {
      "items": [
        { "id": 5, "name": "حليب طازج", "code": "PRD-005", "unit": "لتر", "category_name": "ألبان", "total_sold": 250, "total_sales": 3125, "total_profit": 875, "sales_count": 45, "market_share": 35.5 }
      ],
      "period": "month",
      "total_sold": 704
    },
    "alert_summary": {
      "total_alerts": 10,
      "has_critical": true,
      "critical_count": 2,
      "warning_count": 8
    },
    "settings": {
      "expiry_alert_days": 30,
      "low_stock_threshold": 5,
      "currency": "د.م."
    }
  }
}
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>مستويات الخطورة (severity)</h4>
                    <table class="params-table">
                        <thead><tr><th>المستوى</th><th>اللون</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">critical</td><td style="color:var(--red);">أحمر</td><td>يحتاج تدخل فوري (منتجات منتهية / نفد المخزون)</td></tr>
                            <tr><td class="param-name">warning</td><td style="color:var(--yellow);">أصفر</td><td>يحتاج انتباه قريب (مخزون منخفض / صلاحية قريبة)</td></tr>
                            <tr><td class="param-name">info</td><td style="color:var(--primary-light);">أزرق</td><td>معلومات عامة (عدد قليل من المنتجات قريبة الصلاحية)</td></tr>
                            <tr><td class="param-name">normal</td><td style="color:var(--green);">أخضر</td><td>لا توجد تنبيهات</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مستويات الإلحاح (urgency)</h4>
                    <table class="params-table">
                        <thead><tr><th>المستوى</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">long_expired</td><td>منتهي منذ أكثر من 90 يوم</td></tr>
                            <tr><td class="param-name">expired</td><td>منتهي منذ 30-90 يوم</td></tr>
                            <tr><td class="param-name">recently_expired</td><td>منتهي منذ أقل من 30 يوم</td></tr>
                            <tr><td class="param-name">urgent</td><td>ينتهي خلال 7 أيام</td></tr>
                            <tr><td class="param-name">warning</td><td>ينتهي خلال 8-15 يوم</td></tr>
                            <tr><td class="param-name">notice</td><td>ينتهي خلال 16-30 يوم</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Dashboard Top Products (v5.16.0) -->
        <div class="endpoint open" id="ep-dashboard-top-products">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/dashboard/top-products</span>
                <span class="endpoint-desc" style="color:var(--primary-light);font-weight:600;">NEW v5.16 — أفضل المنتجات مبيعاً مع حصص سوقية وهامش ربح — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">period</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>فترة الحساب: today|week|month|quarter|year|all (افتراضي: month)</td></tr>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد المنتجات (5–50, افتراضي: 10)</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب التصنيف</td></tr>
                            <tr><td class="param-name">sort_by</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ترتيب حسب: quantity|revenue|profit (افتراضي: quantity)</td></tr>
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
    "products": [
      {
        "rank": 1,
        "id": 5,
        "name": "حليب طازج",
        "code": "PRD-005",
        "unit": "لتر",
        "category_name": "ألبان",
        "current_stock": 150,
        "selling_price": 12.5,
        "total_sold": 250,
        "total_sales": 3125,
        "total_profit": 875,
        "profit_margin": 28.0,
        "sales_count": 45,
        "market_share": 35.5,
        "avg_selling_price": 12.5,
        "first_sale_date": "2025-05-01",
        "last_sale_date": "2025-05-30"
      }
    ],
    "period": "month",
    "sort_by": "quantity",
    "category_id": null,
    "totals": { "sold": 704, "revenue": 8800, "profit": 2450 },
    "currency": "د.م."
  }
}
                    </div>
                </div>
            </div>
        </div>
    </div>
