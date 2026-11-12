<?php
/**
 * قسم توثيق API: الأخطاء (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 29. الأخطاء ===== -->
    <div class="section" id="errors">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            الأخطاء
        </div>

        <div class="info-grid">
            <div class="info-card">
                <h3 style="color:var(--red);">رموز حالة HTTP</h3>
                <table class="params-table" style="margin-top:8px;">
                    <thead><tr><th>الرمز</th><th>المعنى</th><th>الوصف</th></tr></thead>
                    <tbody>
                        <tr><td style="color:var(--green);font-weight:700;">200</td><td>نجاح</td><td>الطلب تم بنجاح</td></tr>
                        <tr><td style="color:var(--green);font-weight:700;">201</td><td>تم الإنشاء</td><td>تم إنشاء المورد بنجاح</td></tr>
                        <tr><td style="color:var(--yellow);font-weight:700;">207</td><td>متعدد الحالات</td><td>بعض العمليات نجحت وبعضها فشل (مثل تحديث الإعدادات)</td></tr>
                        <tr><td style="color:var(--yellow);font-weight:700;">400</td><td>طلب غير صالح</td><td>معاملات مفقودة أو غير صحيحة</td></tr>
                        <tr><td style="color:var(--orange);font-weight:700;">401</td><td>غير مصرح</td><td>رمز المصادقة مفقود أو غير صالح أو منتهي</td></tr>
                        <tr><td style="color:var(--orange);font-weight:700;">403</td><td>محظور</td><td>ليس لديك صلاحية كافية</td></tr>
                        <tr><td style="color:var(--red);font-weight:700;">404</td><td>غير موجود</td><td>المورد المطلوب غير موجود</td></tr>
                        <tr><td style="color:var(--red);font-weight:700;">405</td><td>طريقة غير مسموحة</td><td>طريقة HTTP غير مدعومة لهذا المسار</td></tr>
                        <tr><td style="color:var(--red);font-weight:700;">422</td><td>خطأ تحقق</td><td>البيانات المرسلة لا تجتاز التحقق</td></tr>
                        <tr><td style="color:var(--red);font-weight:700;">429</td><td>طلبات كثيرة</td><td>تم تجاوز حد الطلبات المسموح</td></tr>
                        <tr><td style="color:var(--red);font-weight:700;">500</td><td>خطأ الخادم</td><td>خطأ داخلي في الخادم</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="info-card">
                <h3 style="color:var(--orange);">أنواع الأخطاء</h3>
                <table class="params-table" style="margin-top:8px;">
                    <thead><tr><th>النوع</th><th>الوصف</th></tr></thead>
                    <tbody>
                        <tr><td style="color:var(--cyan);font-weight:600;">authentication_error</td><td>فشل المصادقة</td></tr>
                        <tr><td style="color:var(--cyan);font-weight:600;">authorization_error</td><td>صلاحيات غير كافية</td></tr>
                        <tr><td style="color:var(--cyan);font-weight:600;">validation_error</td><td>بيانات غير صالحة</td></tr>
                        <tr><td style="color:var(--cyan);font-weight:600;">not_found_error</td><td>المورد غير موجود</td></tr>
                        <tr><td style="color:var(--cyan);font-weight:600;">conflict_error</td><td>تعارض (مثل: منتج مرتبط)</td></tr>
                        <tr><td style="color:var(--cyan);font-weight:600;">rate_limit_error</td><td>تجاوز حد الطلبات</td></tr>
                        <tr><td style="color:var(--cyan);font-weight:600;">server_error</td><td>خطأ داخلي</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top:20px;">
            <div class="endpoint">
                <div class="endpoint-header" onclick="toggleEndpoint(this)">
                    <span class="method-badge method-post" style="background:var(--red-bg);color:var(--red);border-color:rgba(239,68,68,0.3);">خطأ</span>
                    <span class="endpoint-path">مثال: فشل المصادقة (401)</span>
                    <span class="endpoint-toggle">▼</span>
                </div>
                <div class="endpoint-body">
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": false,
  "error": "رمز المصادقة غير صالح أو منتهي الصلاحية",
  "error_type": "authentication_error",
  "status_code": 401
}
                    </div>
                </div>
            </div>

            <div class="endpoint">
                <div class="endpoint-header" onclick="toggleEndpoint(this)">
                    <span class="method-badge method-post" style="background:var(--orange-bg);color:var(--orange);border-color:rgba(249,115,22,0.3);">خطأ</span>
                    <span class="endpoint-path">مثال: خطأ التحقق (422)</span>
                    <span class="endpoint-toggle">▼</span>
                </div>
                <div class="endpoint-body">
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": false,
  "error": "حقل الاسم مطلوب",
  "error_type": "validation_error",
  "status_code": 422,
  "errors": {
    "name": ["حقل الاسم مطلوب"]
  }
}
                    </div>
                </div>
            </div>

            <div class="endpoint">
                <div class="endpoint-header" onclick="toggleEndpoint(this)">
                    <span class="method-badge method-post" style="background:var(--yellow-bg);color:var(--yellow);border-color:rgba(234,179,8,0.3);">خطأ</span>
                    <span class="endpoint-path">مثال: تعارض (409)</span>
                    <span class="endpoint-toggle">▼</span>
                </div>
                <div class="endpoint-body">
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": false,
  "error": "لا يمكن حذف المنتج لوجود عمليات مبيعات مرتبطة",
  "error_type": "conflict_error",
  "status_code": 409
}
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /api-content -->
