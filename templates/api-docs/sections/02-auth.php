<?php
/**
 * قسم توثيق API: المصادقة (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
    <!-- ===== 2. المصادقة ===== -->
    <div class="section" id="auth">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            المصادقة
        </div>

        <div class="auth-box" style="margin-bottom:20px;">
            <h3>كيفية المصادقة</h3>
            <p style="font-size:0.9rem;color:var(--text-muted);margin-bottom:12px;">
                جميع نقاط النهاية (ما عدا تسجيل الدخول) تتطلب رمز مصادقة Bearer Token.
                الرمز يبدأ بـ <code style="color:var(--cyan);">ism_</code> متبوعاً بـ 64 حرف سداسي عشري. أضفه في رأس الطلب:
            </p>
            <div class="code-block">
                <span class="lang-badge">HTTP Header</span>
                Authorization: Bearer ism_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
            </div>
        </div>

        <!-- Login -->
        <div class="endpoint open" id="ep-auth-login">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/auth/login</span>
                <span class="endpoint-desc">تسجيل الدخول والحصول على رمز — عام (لا يحتاج مصادقة)</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">username</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم المستخدم</td></tr>
                            <tr><td class="param-name">password</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>كلمة المرور</td></tr>
                            <tr><td class="param-name">device_name</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>اسم الجهاز (مثال: Samsung Galaxy S24)</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">cURL</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
curl -X POST <?php echo htmlspecialchars($base_url); ?>/api/mobile/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "your_password", "device_name": "My Phone"}'
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
{
  "success": true,
  "message": "تم تسجيل الدخول بنجاح",
  "data": {
    "token": "ism_a1b2c3d4e5f6...",
    "token_type": "Bearer",
    "expires_at": "2025-08-18 12:00:00",
    "user": {
      "id": 1,
      "username": "admin",
      "full_name": "المدير العام",
      "role": "admin"
    }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <!-- Logout -->
        <div class="endpoint" id="ep-auth-logout">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/auth/logout</span>
                <span class="endpoint-desc">تسجيل الخروج وإبطال الرمز الحالي — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section"><h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">cURL</span>
curl -X POST <?php echo htmlspecialchars($base_url); ?>/api/mobile/auth/logout \
  -H "Authorization: Bearer ism_YOUR_TOKEN_HERE"
                    </div>
                </div>
            </div>
        </div>

        <!-- Me -->
        <div class="endpoint" id="ep-auth-me">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/auth/me</span>
                <span class="endpoint-desc">بيانات المستخدم الحالي مع الصلاحيات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">cURL</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
curl -X GET <?php echo htmlspecialchars($base_url); ?>/api/mobile/auth/me \
  -H "Authorization: Bearer ism_YOUR_TOKEN_HERE"
                    </div>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": {
    "id": 1,
    "username": "admin",
    "full_name": "المدير العام",
    "role": "admin",
    "is_active": 1,
    "last_login": "2025-05-20 08:30:00",
    "permissions": ["products.view", "sales.add", ...]
  }
}
                    </div>
                </div>
            </div>
        </div>

        <!-- Refresh -->
        <div class="endpoint" id="ep-auth-refresh">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/auth/refresh</span>
                <span class="endpoint-desc">تجديد الرمز (يُبطِل القديم ويُنشئ جديد) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section"><h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "message": "تم تجديد الرمز بنجاح",
  "data": {
    "token": "ism_new_token_here...",
    "token_type": "Bearer",
    "expires_at": "2025-08-18 12:00:00"
  }
}
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="endpoint" id="ep-auth-change-password">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/auth/change-password</span>
                <span class="endpoint-desc">تغيير كلمة المرور — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">current_password</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>كلمة المرور الحالية</td></tr>
                            <tr><td class="param-name">new_password</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>كلمة المرور الجديدة (8 أحرف على الأقل)</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">cURL</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
curl -X PUT <?php echo htmlspecialchars($base_url); ?>/api/mobile/auth/change-password \
  -H "Authorization: Bearer ism_YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"current_password": "old_pass", "new_password": "new_secure_pass"}'
                    </div>
                </div>
            </div>
        </div>
    </div>
