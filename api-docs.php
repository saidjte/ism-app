<?php
/**
 * ===== توثيق API للمطورين =====
 * صفحة توثيق تفاعلية لواجهة برمجة التطبيقات (API)
 * متاحة للمديرين فقط
 *
 * v4.35.0: إنشاء صفحة التوثيق
 * v5.12.0: إضافة توثيق نقاط النهاية المفقودة
 * v5.15.0: توثيق شامل لجميع نقاط النهاية (~110 نقطة، 29 قسم)
 */

$page_title = 'توثيق API للمطورين';
require_once 'includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
requireAdmin();

$base_url = rtrim(getSetting('base_url', ''), '/');
if (empty($base_url)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
}
$base_url = rtrim($base_url, '/');

$app_name = htmlspecialchars(getSetting('app_name', 'إدارة المخزون'));
$app_version = defined('APP_VERSION') ? APP_VERSION : '5.16.0';
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $app_name; ?> — توثيق API</title>
    <style>
        :root {
            --bg: #0f172a;
            --bg-card: #1e293b;
            --bg-code: #0d1117;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-light: #60a5fa;
            --green: #22c55e;
            --green-bg: rgba(34,197,94,0.12);
            --yellow: #eab308;
            --yellow-bg: rgba(234,179,8,0.12);
            --red: #ef4444;
            --red-bg: rgba(239,68,68,0.12);
            --purple: #a855f7;
            --purple-bg: rgba(168,85,247,0.12);
            --cyan: #06b6d4;
            --cyan-bg: rgba(6,182,212,0.12);
            --orange: #f97316;
            --orange-bg: rgba(249,115,22,0.12);
            --border: #334155;
            --radius: 10px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            direction: rtl;
        }
        a { color: var(--primary-light); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Header */
        .api-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 50%, #1a1040 100%);
            border-bottom: 2px solid var(--primary);
            padding: 40px 30px;
            text-align: center;
        }
        .api-header h1 { font-size: 2rem; font-weight: 800; margin-bottom: 8px; }
        .api-header h1 svg { vertical-align: middle; margin-left: 8px; }
        .api-header .subtitle { color: var(--text-muted); font-size: 1rem; margin-bottom: 16px; }
        .api-header .badges { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
        .api-header .badge-item {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.08); border: 1px solid var(--border);
            padding: 6px 14px; border-radius: 20px; font-size: 0.82rem; font-weight: 600;
        }

        /* Nav */
        .api-nav {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 0 20px;
            position: sticky; top: 0; z-index: 100;
            overflow-x: auto;
        }
        .api-nav ul { list-style: none; display: flex; gap: 0; white-space: nowrap; }
        .api-nav li a {
            display: block; padding: 14px 18px;
            color: var(--text-muted); font-size: 0.88rem; font-weight: 600;
            border-bottom: 3px solid transparent; transition: all 0.2s;
        }
        .api-nav li a:hover, .api-nav li a.active {
            color: var(--primary-light); border-bottom-color: var(--primary);
            text-decoration: none; background: rgba(59,130,246,0.06);
        }

        /* Main Content */
        .api-content { max-width: 1100px; margin: 0 auto; padding: 30px 20px; }

        .section { margin-bottom: 40px; }
        .section-title {
            font-size: 1.35rem; font-weight: 800; margin-bottom: 16px;
            padding-bottom: 10px; border-bottom: 2px solid var(--border);
            display: flex; align-items: center; gap: 10px;
        }
        .section-title svg { color: var(--primary); }

        /* Endpoint Card */
        .endpoint {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 16px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .endpoint:hover { border-color: var(--primary); }
        .endpoint-header {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; cursor: pointer;
            transition: background 0.15s;
        }
        .endpoint-header:hover { background: rgba(255,255,255,0.03); }
        .method-badge {
            display: inline-block; padding: 4px 12px; border-radius: 6px;
            font-size: 0.75rem; font-weight: 800; letter-spacing: 0.5px;
            min-width: 64px; text-align: center; flex-shrink: 0;
        }
        .method-get { background: var(--green-bg); color: var(--green); border: 1px solid rgba(34,197,94,0.3); }
        .method-post { background: var(--yellow-bg); color: var(--yellow); border: 1px solid rgba(234,179,8,0.3); }
        .method-put { background: var(--purple-bg); color: var(--purple); border: 1px solid rgba(168,85,247,0.3); }
        .method-delete { background: var(--red-bg); color: var(--red); border: 1px solid rgba(239,68,68,0.3); }
        .method-patch { background: var(--orange-bg); color: var(--orange); border: 1px solid rgba(249,115,22,0.3); }

        .endpoint-path {
            font-family: 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
            font-size: 0.9rem; color: var(--text); direction: ltr; text-align: left;
            flex: 1;
        }
        .endpoint-desc { font-size: 0.82rem; color: var(--text-muted); }
        .endpoint-toggle {
            color: var(--text-muted); transition: transform 0.2s;
            flex-shrink: 0;
        }
        .endpoint.open .endpoint-toggle { transform: rotate(180deg); }
        .endpoint-body {
            display: none; padding: 0 18px 18px;
            border-top: 1px solid var(--border);
        }
        .endpoint.open .endpoint-body { display: block; }

        .endpoint-section { margin-top: 14px; }
        .endpoint-section h4 {
            font-size: 0.85rem; font-weight: 700; color: var(--primary-light);
            margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;
        }

        /* Code Block */
        .code-block {
            background: var(--bg-code);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 14px 16px;
            font-family: 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
            font-size: 0.82rem;
            direction: ltr; text-align: left;
            overflow-x: auto;
            position: relative;
            line-height: 1.6;
        }
        .code-block .lang-badge {
            position: absolute; top: 8px; left: 8px;
            background: rgba(255,255,255,0.1); padding: 2px 8px;
            border-radius: 4px; font-size: 0.7rem; color: var(--text-muted);
        }
        .code-block .copy-btn {
            position: absolute; top: 8px; right: 8px;
            background: rgba(255,255,255,0.1); border: 1px solid var(--border);
            color: var(--text-muted); padding: 3px 8px; border-radius: 4px;
            font-size: 0.7rem; cursor: pointer; transition: all 0.2s;
        }
        .code-block .copy-btn:hover { background: rgba(255,255,255,0.2); color: var(--text); }

        /* Params Table */
        .params-table {
            width: 100%; border-collapse: collapse; font-size: 0.84rem;
        }
        .params-table th {
            text-align: right; padding: 8px 12px;
            background: rgba(255,255,255,0.04); color: var(--text-muted);
            border-bottom: 2px solid var(--border); font-weight: 700;
            font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .params-table td {
            padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .params-table .param-name {
            font-family: monospace; color: var(--cyan); font-weight: 600;
            direction: ltr; text-align: left;
        }
        .params-table .param-type { color: var(--purple); font-size: 0.78rem; }
        .params-table .param-required { color: var(--red); font-size: 0.72rem; font-weight: 700; }
        .params-table .param-optional { color: var(--text-muted); font-size: 0.72rem; }

        /* Auth Box */
        .auth-box {
            background: linear-gradient(135deg, rgba(34,197,94,0.08), rgba(59,130,246,0.08));
            border: 1px solid rgba(34,197,94,0.25);
            border-radius: var(--radius);
            padding: 20px;
        }
        .auth-box h3 { color: var(--green); margin-bottom: 12px; font-size: 1.05rem; }

        /* Try It */
        .try-section {
            background: rgba(59,130,246,0.06);
            border: 1px solid rgba(59,130,246,0.2);
            border-radius: 8px;
            padding: 14px;
            margin-top: 14px;
        }
        .try-section h4 { color: var(--primary-light); margin-bottom: 10px; }
        .try-input {
            width: 100%; padding: 10px 12px;
            background: var(--bg-code); border: 1px solid var(--border);
            border-radius: 6px; color: var(--text);
            font-family: monospace; font-size: 0.84rem;
            direction: ltr; text-align: left;
            margin-bottom: 8px;
        }
        .try-input:focus { outline: none; border-color: var(--primary); }
        .try-btn {
            padding: 8px 20px;
            background: var(--primary); color: white;
            border: none; border-radius: 6px;
            font-weight: 700; font-size: 0.85rem;
            cursor: pointer; transition: background 0.2s;
        }
        .try-btn:hover { background: var(--primary-light); }
        .try-result {
            margin-top: 10px; display: none;
        }
        .try-result pre {
            background: var(--bg-code);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            font-size: 0.8rem;
            direction: ltr; text-align: left;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        /* Info cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }
        .info-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
        }
        .info-card h3 { font-size: 1rem; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .info-card ul { padding-right: 20px; list-style: disc; }
        .info-card li { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px; }

        /* Footer */
        .api-footer {
            text-align: center; padding: 30px;
            color: var(--text-muted); font-size: 0.82rem;
            border-top: 1px solid var(--border);
            margin-top: 40px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .api-header { padding: 24px 16px; }
            .api-header h1 { font-size: 1.4rem; }
            .api-content { padding: 20px 12px; }
            .endpoint-header { flex-wrap: wrap; gap: 8px; }
            .endpoint-path { font-size: 0.82rem; word-break: break-all; }
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="api-header">
    <h1>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32" style="color:var(--primary-light);">
            <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/>
            <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>
        </svg>
        توثيق API للمطورين
    </h1>
    <div class="subtitle"><?php echo $app_name; ?> — واجهة برمجة تطبيقات الهاتف المحمول</div>
    <div class="badges">
        <span class="badge-item" style="color:var(--green);border-color:rgba(34,197,94,0.3);">REST API</span>
        <span class="badge-item" style="color:var(--primary-light);border-color:rgba(59,130,246,0.3);">Bearer Token Auth</span>
        <span class="badge-item" style="color:var(--purple);border-color:rgba(168,85,247,0.3);">JSON</span>
        <span class="badge-item" style="color:var(--cyan);border-color:rgba(6,182,212,0.3);">v1</span>
        <span class="badge-item" style="color:var(--yellow);border-color:rgba(234,179,8,0.3);"><?php echo $app_version; ?></span>
    </div>
</div>

<!-- Nav -->
<nav class="api-nav">
    <ul>
        <li><a href="#intro" class="active">مقدمة</a></li>
        <li><a href="#auth">المصادقة</a></li>
        <li><a href="#products">المنتجات</a></li>
        <li><a href="#sales">المبيعات</a></li>
        <li><a href="#purchases">المشتريات</a></li>
        <li><a href="#reports">التقارير</a></li>
        <li><a href="#categories">التصنيفات</a></li>
        <li><a href="#dashboard">لوحة التحكم</a></li>
        <li><a href="#expenses">المصروفات</a></li>
        <li><a href="#settings">الإعدادات</a></li>
        <li><a href="#caisse">الصندوق</a></li>
        <li><a href="#notifications">الإشعارات</a></li>
        <li><a href="#product-images">صور المنتجات</a></li>
        <li><a href="#users">المستخدمين</a></li>
        <li><a href="#prices">الأسعار</a></li>
        <li><a href="#stock">المخزون</a></li>
        <li><a href="#labels">بطاقات المنتجات</a></li>
        <li><a href="#export">التصدير</a></li>
        <li><a href="#backups">النسخ الاحتياطية</a></li>
        <li><a href="#audit">التدقيق</a></li>
        <li><a href="#pos">نقطة البيع</a></li>
        <li><a href="#whatsapp">واتساب</a></li>
        <li><a href="#push">الإشعارات الفورية</a></li>
        <li><a href="#tokens">رموز API</a></li>
        <li><a href="#units">وحدات القياس</a></li>
        <li><a href="#expiry">صلاحية المنتجات</a></li>
        <li><a href="#system">إدارة النظام</a></li>
        <li><a href="#batches">الدفعات</a></li>
        <li><a href="#errors">الأخطاء</a></li>
    </ul>
</nav>

<!-- Content -->
<div class="api-content">

    <!-- ===== 1. مقدمة ===== -->
    <div class="section" id="intro">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            مقدمة
        </div>
        <div class="info-grid">
            <div class="info-card">
                <h3 style="color:var(--green);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    العنوان الأساسي
                </h3>
                <div class="code-block" style="margin-top:8px;">
                    <span class="lang-badge">URL</span>
                    <?php echo htmlspecialchars($base_url); ?>/api/mobile/
                </div>
                <p style="margin-top:8px;font-size:0.84rem;color:var(--text-muted);">جميع نقاط النهاية تبدأ بهذا العنوان. استخدم المسارات النسبية المذكورة أدناه.</p>
            </div>
            <div class="info-card">
                <h3 style="color:var(--primary-light);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    صيغة البيانات
                </h3>
                <ul>
                    <li>الطلبات: <code style="color:var(--cyan);">Content-Type: application/json</code></li>
                    <li>الاستجابات: <code style="color:var(--cyan);">application/json; charset=utf-8</code></li>
                    <li>التشفير: UTF-8</li>
                    <li>لا حاجة لـ CSRF — الرموز بديل آمن</li>
                </ul>
            </div>
            <div class="info-card">
                <h3 style="color:var(--purple);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    الأمان
                </h3>
                <ul>
                    <li>الرموز مُخزّنة مشفّرة (SHA-256)</li>
                    <li>الرمز الأصلي يظهر مرة واحدة فقط</li>
                    <li>انتهاء صلاحية تلقائي (90 يوم افتراضياً)</li>
                    <li>إبطال فوري عند الحاجة</li>
                </ul>
            </div>
        </div>
        <div class="info-grid" style="margin-top:16px;">
            <div class="info-card">
                <h3 style="color:var(--cyan);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    صيغة الاستجابة الناجحة
                </h3>
                <div class="code-block" style="margin-top:8px;">
                    <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": { ... },
  "message": "رسالة توضيحية",
  "pagination": { "total": 100, "per_page": 20, "current_page": 1, "total_pages": 5, "has_next": true, "has_prev": false }
}
                </div>
            </div>
            <div class="info-card">
                <h3 style="color:var(--red);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    صيغة الاستجابة الخاطئة
                </h3>
                <div class="code-block" style="margin-top:8px;">
                    <span class="lang-badge">JSON</span>
{
  "success": false,
  "error": "رسالة الخطأ",
  "error_type": "validation_error",
  "status_code": 422
}
                </div>
            </div>
        </div>
    </div>

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

    <!-- ===== 4. المبيعات ===== -->
    <div class="section" id="sales">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            المبيعات
        </div>

        <div class="endpoint open" id="ep-sales-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/sales</span>
                <span class="endpoint-desc">قائمة المبيعات مع تصفية وترقيم صفحات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية (Y-m-d)</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية (Y-m-d)</td></tr>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة (افتراضي: 1)</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 20)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-sales-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/sales/{id}</span>
                <span class="endpoint-desc">تفاصيل عملية بيع مع عناصر الدفعات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-sales-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/sales</span>
                <span class="endpoint-desc">تسجيل عملية بيع جديدة — مصادقة</span>
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
                            <tr><td class="param-name">items[].quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية المباعة</td></tr>
                            <tr><td class="param-name">items[].selling_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر البيع للوحدة</td></tr>
                            <tr><td class="param-name">sale_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البيع (افتراضي: اليوم)</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">discount</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الخصم</td></tr>
                            <tr><td class="param-name">paid_amount</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>المبلغ المدفوع</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>مثال الطلب</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
                        <button class="copy-btn" onclick="copyCode(this)">نسخ</button>
{
  "items": [
    {"product_id": 1, "quantity": 5, "selling_price": 12.50},
    {"product_id": 3, "quantity": 2, "selling_price": 45.00}
  ],
  "sale_date": "2025-05-20",
  "notes": "بيع من الهاتف",
  "discount": 5.00,
  "paid_amount": 100.00
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-sales-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/sales/{id}</span>
                <span class="endpoint-desc">تحديث عملية بيع — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الكمية الجديدة</td></tr>
                            <tr><td class="param-name">selling_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر البيع الجديد</td></tr>
                            <tr><td class="param-name">sale_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البيع</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-sales-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/sales/{id}</span>
                <span class="endpoint-desc">حذف عملية بيع مع استرجاع المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--yellow);">عند حذف عملية بيع يتم استرجاع الكميات تلقائياً إلى المخزون.</p>
            </div>
        </div>
    </div>

    <!-- ===== 5. المشتريات ===== -->
    <div class="section" id="purchases">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            المشتريات
        </div>

        <div class="endpoint open" id="ep-purchases-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/purchases</span>
                <span class="endpoint-desc">قائمة المشتريات مع تصفية وترقيم صفحات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية (Y-m-d)</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية (Y-m-d)</td></tr>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة (افتراضي: 1)</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 20)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-purchases-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/purchases/{id}</span>
                <span class="endpoint-desc">تفاصيل عملية شراء مع عناصر الدفعات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-purchases-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/purchases</span>
                <span class="endpoint-desc">تسجيل عملية شراء جديدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية المشتراة</td></tr>
                            <tr><td class="param-name">cost_price</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>سعر التكلفة للوحدة</td></tr>
                            <tr><td class="param-name">purchase_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الشراء (افتراضي: اليوم)</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">sale_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر البيع المقترح</td></tr>
                            <tr><td class="param-name">tax_enabled</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>تفعيل الضريبة</td></tr>
                            <tr><td class="param-name">tax_type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نوع الضريبة</td></tr>
                            <tr><td class="param-name">tax_value</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>قيمة الضريبة</td></tr>
                            <tr><td class="param-name">tax_name</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>اسم الضريبة</td></tr>
                            <tr><td class="param-name">batch_items</td><td class="param-type">array</td><td class="param-optional">اختياري</td><td>عناصر الدفعة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-purchases-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/purchases/{id}</span>
                <span class="endpoint-desc">تحديث عملية شراء — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>الكمية الجديدة</td></tr>
                            <tr><td class="param-name">purchase_price</td><td class="param-type">float</td><td class="param-optional">اختياري</td><td>سعر الشراء الجديد</td></tr>
                            <tr><td class="param-name">purchase_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الشراء</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-purchases-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/purchases/{id}</span>
                <span class="endpoint-desc">حذف عملية شراء مع تعديل المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--yellow);">عند حذف عملية شراء يتم خصم الكميات تلقائياً من المخزون.</p>
            </div>
        </div>
    </div>

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

    <!-- ===== 7. التصنيفات ===== -->
    <div class="section" id="categories">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            التصنيفات
        </div>

        <div class="endpoint open" id="ep-categories-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/categories</span>
                <span class="endpoint-desc">قائمة التصنيفات مع عدد المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>مثال الاستجابة (200)</h4>
                    <div class="code-block">
                        <span class="lang-badge">JSON</span>
{
  "success": true,
  "data": [
    { "id": 1, "name": "ألبان", "description": "منتجات الألبان", "color": "#3b82f6", "product_count": 25 }
  ]
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-categories-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/categories</span>
                <span class="endpoint-desc">إضافة تصنيف جديد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم التصنيف</td></tr>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>الوصف</td></tr>
                            <tr><td class="param-name">color</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>اللون (مثل: #3b82f6)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-categories-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/categories/{id}</span>
                <span class="endpoint-desc">تحديث تصنيف — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">أرسل الحقول المراد تحديثها: name, description, color</p>
            </div>
        </div>

        <div class="endpoint" id="ep-categories-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/categories/{id}</span>
                <span class="endpoint-desc">حذف تصنيف — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--red);">لا يمكن حذف تصنيف مرتبط بمنتجات. يجب نقل المنتجات أولاً.</p>
            </div>
        </div>
    </div>

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

    <!-- ===== 9. المصروفات ===== -->
    <div class="section" id="expenses">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            المصروفات
        </div>

        <div class="endpoint" id="ep-expenses-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expenses</span>
                <span class="endpoint-desc">قائمة المصروفات مع إحصائيات — مصادقة</span>
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
                            <tr><td class="param-name">category</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تصفية حسب الفئة</td></tr>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-expenses-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/expenses</span>
                <span class="endpoint-desc">إضافة مصروف جديد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">category</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>فئة المصروف</td></tr>
                            <tr><td class="param-name">amount</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>المبلغ</td></tr>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>الوصف</td></tr>
                            <tr><td class="param-name">expense_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ المصروف (افتراضي: اليوم)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-expenses-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/expenses/{id}</span>
                <span class="endpoint-desc">تحديث مصروف — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">معاملات: category, amount, description, expense_date (جميعها اختيارية)</p>
            </div>
        </div>

        <div class="endpoint" id="ep-expenses-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/expenses/{id}</span>
                <span class="endpoint-desc">حذف مصروف — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-expenses-categories">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/expenses/categories</span>
                <span class="endpoint-desc">فئات المصروفات (الافتراضية + المستخدمة) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>

    <!-- ===== 10. الإعدادات ===== -->
    <div class="section" id="settings">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            الإعدادات
        </div>

        <div class="endpoint open" id="ep-settings-get">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/settings</span>
                <span class="endpoint-desc">جميع الإعدادات (57 إعداد مجموعة) — مصادقة</span>
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
    "general": { "app_name": "...", "currency": "...", ... },
    "pos": { "tax_enabled": "...", "printer_type": "...", ... },
    "notifications": { "whatsapp_enabled": "...", ... },
    ...
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-settings-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/settings</span>
                <span class="endpoint-desc">تحديث الإعدادات (مدير فقط) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">أرسل فقط الإعدادات المراد تحديثها. الاستجابة: <code style="color:var(--cyan);">207 Multi-Status</code> لأن بعض الإعدادات قد تفشل.</p>
            </div>
        </div>
    </div>

    <!-- ===== 11. الصندوق ===== -->
    <div class="section" id="caisse">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20"/><path d="M6 14h.01"/><path d="M10 14h.01"/></svg>
            الصندوق
        </div>

        <div class="endpoint" id="ep-caisse-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/caisse/status</span>
                <span class="endpoint-desc">حالة الصندوق اليوم مع المبلغ المتوقع والفعلي — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-caisse-history">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/caisse/history</span>
                <span class="endpoint-desc">سجل الصندوق — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">days</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد الأيام (1–90)</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-caisse-transactions-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/caisse/transactions</span>
                <span class="endpoint-desc">قائمة المعاملات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>deposit|withdrawal</td></tr>
                            <tr><td class="param-name">date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ محدد</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-caisse-transactions-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/caisse/transactions</span>
                <span class="endpoint-desc">إضافة معاملة (إيداع/سحب) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>deposit أو withdrawal</td></tr>
                            <tr><td class="param-name">amount</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>المبلغ</td></tr>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>الوصف</td></tr>
                            <tr><td class="param-name">transaction_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ المعاملة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-caisse-transactions-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/caisse/transactions/{id}</span>
                <span class="endpoint-desc">حذف معاملة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-caisse-project-amount">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/caisse/project-amount</span>
                <span class="endpoint-desc">تحديد المبلغ المتوقع في الصندوق — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">amount</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>المبلغ المتوقع</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-caisse-actual-count">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/caisse/actual-count</span>
                <span class="endpoint-desc">تسجيل العد الفعلي للصندوق — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">actual_amount</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>المبلغ الفعلي</td></tr>
                            <tr><td class="param-name">note</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 12. الإشعارات ===== -->
    <div class="section" id="notifications">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            الإشعارات
        </div>

        <div class="endpoint" id="ep-notifications-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/notifications</span>
                <span class="endpoint-desc">قائمة الإشعارات — مصادقة</span>
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
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تصفية حسب النوع</td></tr>
                            <tr><td class="param-name">unread</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>غير المقروءة فقط</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-notifications-unread-count">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/notifications/unread-count</span>
                <span class="endpoint-desc">عدد الإشعارات غير المقروءة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-notifications-poll">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/notifications/poll</span>
                <span class="endpoint-desc">استطلاع الإشعارات الجديدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">last_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>آخر معرف إشعار تم استلامه</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-notifications-mark-read">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/notifications/mark-read</span>
                <span class="endpoint-desc">تحديد إشعار كمقروء — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف الإشعار</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-notifications-mark-all-read">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/notifications/mark-all-read</span>
                <span class="endpoint-desc">تحديد جميع الإشعارات كمقروءة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-notifications-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/notifications/{id}</span>
                <span class="endpoint-desc">حذف إشعار — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>

    <!-- ===== 13. صور المنتجات ===== -->
    <div class="section" id="product-images">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            صور المنتجات
        </div>

        <div class="endpoint" id="ep-images-upload">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/products/{id}/images</span>
                <span class="endpoint-desc">رفع صورة منتج (multipart/form-data) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات النموذج (Multipart)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">image</td><td class="param-type">file</td><td class="param-required">مطلوب</td><td>ملف الصورة (أقصى 5MB)</td></tr>
                            <tr><td class="param-name">remove_bg</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>إزالة الخلفية</td></tr>
                            <tr><td class="param-name">bg_tolerance</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تفاوت الخلفية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-images-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/products/{id}/images</span>
                <span class="endpoint-desc">قائمة صور المنتج — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-images-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/images/{id}</span>
                <span class="endpoint-desc">حذف صورة (حذف ناعم) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-images-set-primary">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/images/{id}/set-primary</span>
                <span class="endpoint-desc">تعيين صورة كرئيسية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>

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

    <!-- ===== 15. الأسعار ===== -->
    <div class="section" id="prices">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            الأسعار
        </div>

        <div class="endpoint" id="ep-prices-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/prices/list</span>
                <span class="endpoint-desc">قائمة أسعار المنتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>نص البحث</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-prices-history">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/prices/history</span>
                <span class="endpoint-desc">سجل تغييرات الأسعار — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بحث</td></tr>
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>all|purchase|selling</td></tr>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 50)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-prices-history-product">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/prices/history/{id}</span>
                <span class="endpoint-desc">سجل أسعار منتج محدد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>

    <!-- ===== 16. المخزون ===== -->
    <div class="section" id="stock">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            المخزون
        </div>

        <div class="endpoint" id="ep-stock-transfer">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/stock/transfer</span>
                <span class="endpoint-desc">تحويل مخزون بين منتجات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">from_product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج المصدر</td></tr>
                            <tr><td class="param-name">to_product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج الهدف</td></tr>
                            <tr><td class="param-name">quantity</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية المحولة</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-update-qty">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/stock/update-qty</span>
                <span class="endpoint-desc">تحديث كمية منتج مباشرة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">new_qty</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية الجديدة (0 على الأقل)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-inventory-count">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/stock/inventory-count</span>
                <span class="endpoint-desc">تسجيل جرد المخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">count_date</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ الجرد</td></tr>
                            <tr><td class="param-name">notes</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملاحظات</td></tr>
                            <tr><td class="param-name">items</td><td class="param-type">array</td><td class="param-required">مطلوب</td><td>قائمة عناصر الجرد</td></tr>
                            <tr><td class="param-name">items[].product_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المنتج</td></tr>
                            <tr><td class="param-name">items[].actual_qty</td><td class="param-type">float</td><td class="param-required">مطلوب</td><td>الكمية الفعلية</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-inventory-data">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/stock/inventory-data</span>
                <span class="endpoint-desc">بيانات الجرد — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب التصنيف</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-stock-last-count">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/stock/last-count</span>
                <span class="endpoint-desc">آخر جرد مخزون — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>

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

    <!-- ===== 18. التصدير ===== -->
    <div class="section" id="export">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            التصدير
        </div>

        <div class="endpoint" id="ep-export-csv">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/export/csv/{type}</span>
                <span class="endpoint-desc">تصدير CSV مع UTF-8 BOM — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات المسار</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">type</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>products|sales|purchases|expenses|profits|profit_report</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                            <tr><td class="param-name">category_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>معرف التصنيف</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-export-pdf">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/export/pdf/{type}</span>
                <span class="endpoint-desc">تصدير صفحة طباعة HTML — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">نفس معاملات CSV. يُرجع صفحة HTML جاهزة للطباعة.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-export-data">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/export/data/{type}</span>
                <span class="endpoint-desc">تصدير بيانات JSON — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">نفس معاملات CSV. يُرجع بيانات JSON خام.</p>
            </div>
        </div>
    </div>

    <!-- ===== 19. النسخ الاحتياطية ===== -->
    <div class="section" id="backups">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            النسخ الاحتياطية
        </div>

        <div class="endpoint" id="ep-backups-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/backups</span>
                <span class="endpoint-desc">قائمة النسخ الاحتياطية مع الحجم والتاريخ — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-backups-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/backups/create</span>
                <span class="endpoint-desc">إنشاء نسخة احتياطية — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>وصف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/backups/delete</span>
                <span class="endpoint-desc">حذف نسخة احتياطية — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">filename</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم ملف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-restore">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/backups/restore</span>
                <span class="endpoint-desc">استعادة نسخة احتياطية — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">filename</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم ملف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-download">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/backups/download</span>
                <span class="endpoint-desc">تحميل نسخة احتياطية (محمي من path traversal) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">filename</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم ملف النسخة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-backups-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/backups/status</span>
                <span class="endpoint-desc">حالة النسخ الاحتياطي — مصادقة + مدير</span>
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
    "method": "mysqldump",
    "max_files": 10,
    "auto_backup_enabled": true
  }
}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 20. التدقيق ===== -->
    <div class="section" id="audit">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            التدقيق
        </div>

        <div class="endpoint" id="ep-audit-logs">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/audit/logs</span>
                <span class="endpoint-desc">سجلات التدقيق — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">user_id</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>تصفية حسب المستخدم</td></tr>
                            <tr><td class="param-name">action</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تصفية حسب الإجراء</td></tr>
                            <tr><td class="param-name">date_from</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ البداية</td></tr>
                            <tr><td class="param-name">date_to</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>تاريخ النهاية</td></tr>
                            <tr><td class="param-name">limit</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد السجلات (10–1000)</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-audit-error-logs">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/audit/error-logs</span>
                <span class="endpoint-desc">سجلات الأخطاء — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">file</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>ملف السجل</td></tr>
                            <tr><td class="param-name">level</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>مستوى الخطأ</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر (افتراضي: 100)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-audit-stats">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/audit/stats</span>
                <span class="endpoint-desc">إحصائيات التدقيق — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">معاملات: date_from, date_to. يتضمن: by_action, by_user, recent</p>
            </div>
        </div>
    </div>

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

    <!-- ===== 22. واتساب ===== -->
    <div class="section" id="whatsapp">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
            واتساب
        </div>

        <div class="endpoint" id="ep-whatsapp-send-alert">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/whatsapp/send-alert</span>
                <span class="endpoint-desc">إرسال تنبيه واتساب عبر UltraMsg — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">message</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>رسالة مخصصة (افتراضي: التقرير اليومي)</td></tr>
                            <tr><td class="param-name">force</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>إرسال حتى لو مُعطل</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-whatsapp-test">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/whatsapp/test</span>
                <span class="endpoint-desc">اختبار اتصال واتساب — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">instance_id</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>معرف مثيل UltraMsg</td></tr>
                            <tr><td class="param-name">api_token</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>رمز API</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-whatsapp-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/whatsapp/status</span>
                <span class="endpoint-desc">حالة واتساب — مصادقة + مدير</span>
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
    "configured": true,
    "phone": "+213XXXXXXXXX"
  }
}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 23. الإشعارات الفورية ===== -->
    <div class="section" id="push">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/><path d="M2 8l2-2m16 2l2-2"/></svg>
            الإشعارات الفورية
        </div>

        <div class="endpoint" id="ep-push-subscribe">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/push/subscribe</span>
                <span class="endpoint-desc">اشتراك في الإشعارات الفورية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">endpoint</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>عنوان الاشتراك</td></tr>
                            <tr><td class="param-name">p256dh</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>مفتاح التشفير العام</td></tr>
                            <tr><td class="param-name">auth</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>مفتاح المصادقة</td></tr>
                            <tr><td class="param-name">device_type</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>android|ios|web</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-push-unsubscribe">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/push/unsubscribe</span>
                <span class="endpoint-desc">إلغاء الاشتراك في الإشعارات الفورية — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">endpoint</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>عنوان الاشتراك</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-push-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/push/status</span>
                <span class="endpoint-desc">حالة الإشعارات الفورية — مصادقة</span>
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
    "subscriptions_count": 3,
    "devices": { "android": 1, "ios": 0, "web": 2 }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-push-permission">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/push/permission</span>
                <span class="endpoint-desc">تسجيل حالة إذن الإشعارات — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">permission</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>granted|denied|default</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 24. رموز API ===== -->
    <div class="section" id="tokens">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            رموز API
        </div>

        <div class="endpoint" id="ep-tokens-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/tokens</span>
                <span class="endpoint-desc">قائمة الرموز — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الاستعلام (Query)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">status</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>active|revoked|expired|all</td></tr>
                            <tr><td class="param-name">page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>رقم الصفحة</td></tr>
                            <tr><td class="param-name">per_page</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>عدد العناصر</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-tokens-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/tokens/create</span>
                <span class="endpoint-desc">إنشاء رمز API جديد — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">user_id</td><td class="param-type">int</td><td class="param-required">مطلوب</td><td>معرف المستخدم</td></tr>
                            <tr><td class="param-name">device_name</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>اسم الجهاز</td></tr>
                            <tr><td class="param-name">expiry_days</td><td class="param-type">int</td><td class="param-optional">اختياري</td><td>مدة الصلاحية: 30|60|90|180|365 يوم</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-tokens-revoke">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/tokens/revoke/{id}</span>
                <span class="endpoint-desc">إبطال رمز — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-tokens-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/tokens/{id}</span>
                <span class="endpoint-desc">حذف رمز نهائياً — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>
    </div>

    <!-- ===== 25. وحدات القياس ===== -->
    <div class="section" id="units">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
            وحدات القياس
        </div>

        <div class="endpoint" id="ep-units-list">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/units</span>
                <span class="endpoint-desc">قائمة وحدات القياس — مصادقة</span>
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
                            <tr><td class="param-name">search</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>بحث</td></tr>
                        </tbody>
                    </table>
                </div>
                <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">يرجع 10 وحدات افتراضية إذا لم تكن جدول الوحدات موجوداً</p>
            </div>
        </div>

        <div class="endpoint" id="ep-units-show">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/units/{id}</span>
                <span class="endpoint-desc">تفاصيل وحدة قياس — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-units-create">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/units</span>
                <span class="endpoint-desc">إضافة وحدة قياس جديدة — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم الوحدة (50 حرف على الأكثر)</td></tr>
                            <tr><td class="param-name">symbol</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>رمز الوحدة</td></tr>
                            <tr><td class="param-name">description</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>الوصف</td></tr>
                            <tr><td class="param-name">is_base_unit</td><td class="param-type">bool</td><td class="param-optional">اختياري</td><td>هل هي وحدة أساسية؟</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-units-update">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/units/{id}</span>
                <span class="endpoint-desc">تحديث وحدة قياس (يتدفق إلى المنتجات) — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">معاملات: name, symbol, description, is_base_unit. التغييرات تتدفق تلقائياً للمنتجات المرتبطة.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-units-delete">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-delete">DELETE</span>
                <span class="endpoint-path">/api/mobile/units/{id}</span>
                <span class="endpoint-desc">حذف وحدة قياس — مصادقة</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--red);">لا يمكن حذف وحدة مستخدمة من قبل منتجات.</p>
            </div>
        </div>
    </div>

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

    <!-- ===== 27. إدارة النظام ===== -->
    <div class="section" id="system">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            إدارة النظام
        </div>

        <div class="endpoint open" id="ep-system-info">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/info</span>
                <span class="endpoint-desc">معلومات التطبيق وقاعدة البيانات والخادم — مصادقة + مدير</span>
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
    "app": { "name": "إدارة المخزون", "version": "5.15.0" },
    "database": { "type": "MySQL", "size": "12.5 MB" },
    "stats": { "products": 120, "sales": 1540, "purchases": 980 },
    "server": { "php_version": "8.2.0", "max_upload": "50M" }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-db-status">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/db-status</span>
                <span class="endpoint-desc">حالة قاعدة البيانات والجداول — مصادقة + مدير</span>
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
    "status": "connected",
    "tables": 18,
    "missing_tables": [],
    "schema_ok": true
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-test-db">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/system/test-db</span>
                <span class="endpoint-desc">اختبار اتصال قاعدة بيانات جديدة — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <div class="endpoint-section">
                    <h4>معاملات الجسم (JSON)</h4>
                    <table class="params-table">
                        <thead><tr><th>المعامل</th><th>النوع</th><th>مطلوب</th><th>الوصف</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">host</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>عنوان الخادم</td></tr>
                            <tr><td class="param-name">name</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم قاعدة البيانات</td></tr>
                            <tr><td class="param-name">user</td><td class="param-type">string</td><td class="param-required">مطلوب</td><td>اسم المستخدم</td></tr>
                            <tr><td class="param-name">pass</td><td class="param-type">string</td><td class="param-optional">اختياري</td><td>كلمة المرور</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-db-settings">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-put">PUT</span>
                <span class="endpoint-path">/api/mobile/system/db-settings</span>
                <span class="endpoint-desc">تحديث إعدادات قاعدة البيانات (يكتب .env) — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">نفس معاملات test-db. يقوم بتحديث ملف .env مباشرة.</p>
            </div>
        </div>

        <div class="endpoint" id="ep-system-changelog">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/changelog</span>
                <span class="endpoint-desc">سجل تغييرات الإصدارات — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body"></div>
        </div>

        <div class="endpoint" id="ep-system-permissions">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/permissions</span>
                <span class="endpoint-desc">صلاحيات المستخدم والأدوار — مصادقة + مدير</span>
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
    "user": { "id": 1, "role": "admin" },
    "role": "admin",
    "permissions": ["products.view", "products.add", ...],
    "permissions_grouped": { "products": ["view", "add", "edit", "delete"], ... }
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-version">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-get">GET</span>
                <span class="endpoint-path">/api/mobile/system/version</span>
                <span class="endpoint-desc">معلومات الإصدار — مصادقة</span>
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
    "app_version": "5.15.0",
    "api_version": "1",
    "php_version": "8.2.0"
  }
}
                    </div>
                </div>
            </div>
        </div>

        <div class="endpoint" id="ep-system-clear-cache">
            <div class="endpoint-header" onclick="toggleEndpoint(this)">
                <span class="method-badge method-post">POST</span>
                <span class="endpoint-path">/api/mobile/system/clear-cache</span>
                <span class="endpoint-desc">مسح ذاكرة التخزين المؤقت والسجلات القديمة — مصادقة + مدير</span>
                <span class="endpoint-toggle">▼</span>
            </div>
            <div class="endpoint-body">
                <p style="font-size:0.85rem;color:var(--text-muted);">يمسح: ذاكرة الإعدادات المؤقتة، ملفات الكاش، السجلات القديمة.</p>
            </div>
        </div>
    </div>

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

<!-- Footer -->
<div class="api-footer">
    <p><?php echo $app_name; ?> — توثيق API v<?php echo $app_version; ?> — <?php echo date('Y'); ?></p>
    <p style="margin-top:4px;">جميع نقاط النهاية تتطلب مصادقة Bearer Token ما لم يُذكر خلاف ذلك</p>
</div>

<script>
function toggleEndpoint(header) {
    var endpoint = header.parentElement;
    endpoint.classList.toggle('open');
}

function copyCode(btn) {
    var codeBlock = btn.closest('.code-block');
    var text = codeBlock.textContent
        .replace(/^نسخ$/, '')
        .replace(/^\w+\n/, '')
        .trim();
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            btn.textContent = 'تم النسخ!';
            setTimeout(function() { btn.textContent = 'نسخ'; }, 2000);
        });
    } else {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            btn.textContent = 'تم النسخ!';
            setTimeout(function() { btn.textContent = 'نسخ'; }, 2000);
        } catch(e) {
            btn.textContent = 'خطأ!';
        }
        document.body.removeChild(textarea);
    }
}

// Active nav highlighting on scroll
var sections = document.querySelectorAll('.section');
var navLinks = document.querySelectorAll('.api-nav a');

function updateActiveNav() {
    var scrollPos = window.scrollY + 100;
    sections.forEach(function(section) {
        if (section.offsetTop <= scrollPos && (section.offsetTop + section.offsetHeight) > scrollPos) {
            var id = section.getAttribute('id');
            navLinks.forEach(function(link) {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + id) {
                    link.classList.add('active');
                }
            });
        }
    });
}

window.addEventListener('scroll', updateActiveNav);

// Smooth scroll for nav links
navLinks.forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var target = document.querySelector(this.getAttribute('href'));
        if (target) {
            var navHeight = document.querySelector('.api-nav').offsetHeight;
            var targetPos = target.offsetTop - navHeight - 10;
            window.scrollTo({ top: targetPos, behavior: 'smooth' });
        }
    });
});
</script>
</body>
</html>
