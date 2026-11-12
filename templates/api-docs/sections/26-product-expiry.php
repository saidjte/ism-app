<?php
/**
 * قسم توثيق API: صلاحية المنتجات (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 26. صلاحية المنتجات ===== -->
    <div class="section" id="expiry">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            صلاحية المنتجات
        </div>

        <div class="endpoint open" id="ep-expiry-report">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expiry/report</span>
                <span class="endpoint-desc">تقرير صلاحية المنتجات مع إحصائيات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">status</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>expired|expiring|all</td></tr>
                            <tr><td class="param-name">days</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأيام القادمة (افتراضي: 30)</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-expiry-alerts">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expiry/alerts</span>
                <span class="endpoint-desc">تنبيهات الصلاحية — مصادقة</span>
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
    "enabled": true,
    "expired": [{ "id": 5, "name": "زبادي", "expiry_date": "2025-05-15" }],
    "expiring": [{ "id": 8, "name": "جبن", "expiry_date": "2025-06-05" }],
    "alert_days": 30
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-expiry-dismiss">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/expiry/dismiss</span>
                <span class="endpoint-desc">إخفاء تنبيه الصلاحية لمدة ساعتين — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">sig</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>توقيع التنبيه</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-expiry-dismissed">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expiry/dismissed</span>
                <span class="endpoint-desc">التحقق من حالة الإخفاء — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">sig</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>توقيع التنبيه</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-expiry-batch-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/expiry/batch-update</span>
                <span class="endpoint-desc">تحديث تواريخ الصلاحية دفعة واحدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">updates</td><td class="param-type">array</td><td class="param-required">مطلوب</td><td>قائمة التحديثات</td></tr>
                            <tr><td class="param-name">updates[].product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">updates[].expiry_date</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>تاريخ الانتهاء الجديد</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Expiry Tracking (v5.16.0) -->
        <div class="endpoint open" id="ep-expiry-tracking">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expiry/tracking</span>
                <span class="endpoint-desc" style="color:var(--primary-light);font-weight:600;">NEW v5.16 — نظام تتبع الصلاحية المتقدم (نظرة عامة + تجميع حسب الإلحاح/التصنيف + دفعات + توصيات) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">days_ahead</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>نطاق الأيام القادمة: 7|15|30|60|90|180|365 (افتراضي: 90)</td></tr>
                            <tr><td class="param-name">group_by</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تجميع حسب: urgency|category|none (افتراضي: urgency)</td></tr>
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
    "enabled": true,
    "overview": {
      "total_tracked": 45,
      "expired_count": 2,
      "urgent_count": 3,
      "warning_count": 5,
      "notice_count": 8,
      "safe_count": 27,
      "total_loss_value": 450.00,
      "at_risk_value": 1200.00,
      "alert_days": 30,
      "days_ahead": 90,
      "block_sale": true
    },
    "urgency_groups": {
      "expired": [{ "id": 12, "name": "زبادي", "days_expired": 37 }],
      "urgent": [{ "id": 15, "name": "جبنة", "days_remaining": 5 }],
      "warning": [{ "id": 20, "name": "زبدة", "days_remaining": 15 }],
      "notice": [{ "id": 25, "name": "قشطة", "days_remaining": 25 }]
    },
    "category_groups": [],
    "batches": [],
    "recommendations": [
      { "type": "critical", "action": "remove_expired", "message": "إزالة المنتجات المنتهية الصلاحية من الرفوف فوراً", "count": 2 },
      { "type": "urgent", "action": "discount_urgent", "message": "تخفيض المنتجات التي تنتهي صلاحيتها خلال أسبوع", "count": 3 }
    ],
    "group_by": "urgency"
  }
}
                    </div>
                </div>
            </div>
        </div>

        <!-- Expiry Timeline (v5.16.0) -->
        <div class="endpoint open" id="ep-expiry-timeline">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expiry/timeline</span>
                <span class="endpoint-desc" style="color:var(--primary-light);font-weight:600;">NEW v5.16 — جدول زمني للصلاحيات (توزيع شهري للمنتجات حسب تاريخ الانتهاء) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">months</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأشهر القادمة (3–24, افتراضي: 6)</td></tr>
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
    "timeline": [
      {
        "month": "2025-06",
        "month_label": "يونيو 2025",
        "total_count": 5,
        "total_value": 1200,
        "already_expired": 2,
        "upcoming": 3,
        "first_expiry": "2025-06-03",
        "last_expiry": "2025-06-28",
        "status": "current"
      }
    ],
    "months": 6,
    "totals": { "expired": 3, "upcoming": 15, "total_value": 8500 },
    "currency": "د.م."
  }
}
                    </div>
                </div>
            </div>
        </div>
    </div>
