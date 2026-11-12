<?php
/**
 * قسم توثيق API: المستخدمين (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 14. المستخدمين ===== -->
    <div class="section" id="users">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            المستخدمين
        </div>

        <div class="endpoint" id="ep-users-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/users</span>
                <span class="endpoint-desc">قائمة المستخدمين — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                            <tr><td class="param-name">role</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>admin|manager|user</td></tr>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بحث</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-users-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/users</span>
                <span class="endpoint-desc">إضافة مستخدم جديد — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">username</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم المستخدم (3 أحرف على الأقل)</td></tr>
                            <tr><td class="param-name">full_name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>الاسم الكامل</td></tr>
                            <tr><td class="param-name">password</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>كلمة المرور (8 أحرف على الأقل)</td></tr>
                            <tr><td class="param-name">role</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>admin|manager|user</td></tr>
                            <tr><td class="param-name">email</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>البريد الإلكتروني</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-users-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/users/{id}</span>
                <span class="endpoint-desc">تحديث بيانات مستخدم — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">معاملات: full_name, email, role (جميعها اختيارية)</p>
            </div>
        </div>

        <div class="endpoint" id="ep-users-toggle">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/users/{id}/toggle</span>
                <span class="endpoint-desc">تبديل حالة المستخدم (تفعيل/تعطيل) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-users-reset-password">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/users/{id}/reset-password</span>
                <span class="endpoint-desc">إعادة تعيين كلمة مرور مستخدم (يُبطِل جميع الرموز) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">new_password</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>كلمة المرور الجديدة (8 أحرف على الأقل)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
