<?php
/**
 * ===== صفحة اختبار API التفاعلية =====
 * تتيح للمدير اختبار جميع نقاط نهاية API الهاتف مباشرة من المتصفح
 *
 * الميزات:
 *   - تسجيل الدخول والحصول على رمز Bearer
 *   - اختبار جميع نقاط النهاية (المنتجات، المبيعات، المشتريات، التقارير)
 *   - عرض الطلب والاستجابة بشكل تفصيلي
 *   - حفظ الرمز تلقائياً في المتصفح
 *   - استجابات مُنسّقة JSON مع تلوين
 *
 * v4.39.1: إصلاح حرج — ALTER TABLE بـ NULL أولاً لتجنب فشل NOT NULL مع صفوف موجودة
 */

$page_title = 'اختبار API';
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
$app_version = defined('APP_VERSION') ? APP_VERSION : '4.40.0';

// جلب المستخدمين لاختيارهم في تسجيل الدخول
$users = [];
try {
    $users = fetchAll("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY role DESC, full_name ASC");
    if (!is_array($users)) $users = [];
} catch (\Throwable $e) {
    $users = [];
}

// جلب التصنيفات لاختيارها في فلاتر المنتجات
$categories = [];
try {
    $categories = fetchAll("SELECT id, name FROM categories ORDER BY name ASC");
    if (!is_array($categories)) $categories = [];
} catch (\Throwable $e) {
    $categories = [];
}

// جلب بعض المنتجات لاختيارها
$sampleProducts = [];
try {
    $sampleProducts = fetchAll("SELECT id, name, code FROM products ORDER BY name ASC LIMIT 20");
    if (!is_array($sampleProducts)) $sampleProducts = [];
} catch (\Throwable $e) {
    $sampleProducts = [];
}

// فحص حالة جدول api_tokens
$tableExists = false;
$tableHealthy = true;
$tokenCount = 0;
$missingColumns = [];
// v6.3.6: أضيفت token_hash/token_name/scopes — مطلوبة لتسجيل دخول الهاتف
$requiredColumns = ['id', 'user_id', 'token', 'token_hash', 'token_name', 'scopes', 'device_name', 'is_revoked', 'expires_at', 'last_used_at', 'created_at'];

try {
    $db = AppDatabase::getInstance();
    $pdo = $db->getConnection();
    $stmt = $pdo->query("SHOW TABLES LIKE 'api_tokens'");
    $tableExists = !empty($stmt->fetch());
    if ($tableExists) {
        try {
            $countRow = $pdo->query("SELECT COUNT(*) as c FROM api_tokens")->fetch(PDO::FETCH_ASSOC);
            $tokenCount = intval($countRow['c'] ?? 0);
        } catch (\Throwable $e) {
            // الجدول موجود لكن به أعمدة مفقودة
        }
        // كشف الأعمدة المفقودة
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM api_tokens");
            $existingCols = [];
            while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $existingCols[] = $row['Field'];
            }
            $missingColumns = array_diff($requiredColumns, $existingCols);
            if (!empty($missingColumns)) {
                $tableHealthy = false;
            }
        } catch (\Throwable $e) {
            $tableHealthy = false;
        }
    }
} catch (\Throwable $e) {}

$page_title = htmlspecialchars(getSetting('app_name', 'إدارة المخزون')) . ' - اختبار API';
?>
<?php require_once 'includes/header.php'; ?>

<style>
:root {
    --t-bg: #0f172a;
    --t-card: #1e293b;
    --t-code: #0d1117;
    --t-text: #e2e8f0;
    --t-muted: #94a3b8;
    --t-primary: #3b82f6;
    --t-green: #22c55e;
    --t-yellow: #eab308;
    --t-red: #ef4444;
    --t-purple: #a855f7;
    --t-cyan: #06b6d4;
    --t-border: #334155;
    --t-radius: 10px;
}
.api-tester { font-size: 0.9rem; }
.api-tester .hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 50%, #1a1040 100%);
    border-radius: 14px; padding: 28px 30px; margin-bottom: 24px; color: #fff;
    position: relative; overflow: hidden;
}
.api-tester .hero h1 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
.api-tester .hero .subtitle { color: var(--t-muted); font-size: 0.9rem; }
.api-tester .hero .badges { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.api-tester .hero .badge-item {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.08); border: 1px solid var(--t-border);
    padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
}

/* Status Bar */
.status-bar {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    background: var(--t-card); border: 1px solid var(--t-border);
    border-radius: var(--t-radius); padding: 14px 20px; margin-bottom: 20px;
}
.status-bar .status-item { display: flex; align-items: center; gap: 6px; font-size: 0.84rem; }
.status-bar .status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.status-bar .status-dot.ok { background: var(--t-green); box-shadow: 0 0 6px rgba(34,197,94,0.5); }
.status-bar .status-dot.warn { background: var(--t-yellow); box-shadow: 0 0 6px rgba(234,179,8,0.5); }
.status-bar .status-dot.err { background: var(--t-red); box-shadow: 0 0 6px rgba(239,68,68,0.5); }

/* Cards */
.t-card {
    background: var(--t-card); border: 1px solid var(--t-border);
    border-radius: var(--t-radius); overflow: hidden; margin-bottom: 16px;
}
.t-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; cursor: pointer;
    border-bottom: 1px solid var(--t-border);
    transition: background 0.15s;
}
.t-card-header:hover { background: rgba(255,255,255,0.03); }
.t-card-header h3 { font-size: 0.95rem; font-weight: 700; color: var(--t-text); margin: 0; flex: 1; }
.t-card-body { padding: 18px; }

/* Method Badge */
.method-badge {
    display: inline-block; padding: 3px 10px; border-radius: 6px;
    font-size: 0.72rem; font-weight: 800; letter-spacing: 0.5px;
    min-width: 56px; text-align: center; flex-shrink: 0;
}
.m-get { background: rgba(34,197,94,0.12); color: var(--t-green); border: 1px solid rgba(34,197,94,0.3); }
.m-post { background: rgba(234,179,8,0.12); color: var(--t-yellow); border: 1px solid rgba(234,179,8,0.3); }
.m-put { background: rgba(168,85,247,0.12); color: var(--t-purple); border: 1px solid rgba(168,85,247,0.3); }
.m-delete { background: rgba(239,68,68,0.12); color: var(--t-red); border: 1px solid rgba(239,68,68,0.3); }

/* Form Elements */
.t-label { display: block; font-weight: 700; font-size: 0.82rem; color: var(--t-muted); margin-bottom: 4px; }
.t-input, .t-select, .t-textarea {
    width: 100%; padding: 9px 12px; font-size: 0.85rem;
    background: var(--t-code); border: 1px solid var(--t-border);
    border-radius: 6px; color: var(--t-text); font-family: inherit;
    direction: ltr; text-align: left; transition: border-color 0.2s;
}
.t-input:focus, .t-select:focus, .t-textarea:focus { outline: none; border-color: var(--t-primary); }
.t-textarea { min-height: 80px; resize: vertical; font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.82rem; }
.t-select { cursor: pointer; }
.t-select option { background: var(--t-card); color: var(--t-text); }

.t-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border: none; border-radius: 8px;
    font-weight: 700; font-size: 0.85rem; cursor: pointer;
    transition: all 0.2s; font-family: inherit;
}
.t-btn-primary { background: var(--t-primary); color: #fff; }
.t-btn-primary:hover { background: #2563eb; }
.t-btn-success { background: var(--t-green); color: #fff; }
.t-btn-success:hover { background: #16a34a; }
.t-btn-danger { background: var(--t-red); color: #fff; }
.t-btn-danger:hover { background: #dc2626; }
.t-btn-outline { background: transparent; color: var(--t-muted); border: 1px solid var(--t-border); }
.t-btn-outline:hover { background: rgba(255,255,255,0.05); color: var(--t-text); }
.t-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Grid */
.t-grid { display: grid; gap: 10px; }
.t-grid-2 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.t-grid-3 { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
.t-grid-4 { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }

/* Code Block */
.t-code {
    background: var(--t-code); border: 1px solid var(--t-border);
    border-radius: 8px; padding: 14px 16px; overflow-x: auto;
    font-family: 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
    font-size: 0.8rem; direction: ltr; text-align: left;
    line-height: 1.6; position: relative; max-height: 500px;
    overflow-y: auto; white-space: pre-wrap; word-break: break-word;
}
.t-code .key { color: #7dd3fc; }
.t-code .string { color: #86efac; }
.t-code .number { color: #fbbf24; }
.t-code .bool { color: #c084fc; }
.t-code .null { color: #94a3b8; }

/* Result Panel */
.result-panel {
    border-radius: 8px; overflow: hidden; margin-top: 12px;
    border: 1px solid var(--t-border);
}
.result-header {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; font-size: 0.82rem; font-weight: 700;
}
.result-header.success { background: rgba(34,197,94,0.12); color: var(--t-green); border-bottom: 1px solid rgba(34,197,94,0.2); }
.result-header.error { background: rgba(239,68,68,0.12); color: var(--t-red); border-bottom: 1px solid rgba(239,68,68,0.2); }
.result-header.pending { background: rgba(59,130,246,0.12); color: var(--t-primary); border-bottom: 1px solid rgba(59,130,246,0.2); }

/* Token Input */
.token-input-group {
    display: flex; gap: 8px; align-items: center;
    background: var(--t-code); border: 1px solid var(--t-border);
    border-radius: 8px; padding: 4px; margin-bottom: 12px;
}
.token-input-group input {
    flex: 1; background: transparent; border: none; color: var(--t-text);
    font-family: 'Cascadia Code', monospace; font-size: 0.82rem;
    padding: 8px 12px; outline: none; direction: ltr; text-align: left;
}

/* Endpoint Group */
.ep-group { margin-bottom: 8px; }
.ep-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,0.04);
    cursor: pointer; transition: background 0.15s;
}
.ep-row:hover { background: rgba(255,255,255,0.04); }
.ep-row:last-child { border-bottom: none; }
.ep-path { font-family: 'Cascadia Code', monospace; font-size: 0.84rem; color: var(--t-text); direction: ltr; text-align: left; flex: 1; }
.ep-desc { font-size: 0.78rem; color: var(--t-muted); }
.ep-play {
    background: rgba(59,130,246,0.15); color: var(--t-primary);
    border: 1px solid rgba(59,130,246,0.3); border-radius: 6px;
    padding: 4px 10px; font-size: 0.72rem; font-weight: 700;
    cursor: pointer; transition: all 0.2s; white-space: nowrap;
}
.ep-play:hover { background: var(--t-primary); color: #fff; }

/* Tabs */
.t-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--t-border); margin-bottom: 16px; overflow-x: auto; }
.t-tab {
    padding: 10px 18px; font-size: 0.85rem; font-weight: 600;
    color: var(--t-muted); cursor: pointer; border-bottom: 3px solid transparent;
    transition: all 0.2s; white-space: nowrap;
}
.t-tab:hover { color: var(--t-text); background: rgba(255,255,255,0.03); }
.t-tab.active { color: var(--t-primary); border-bottom-color: var(--t-primary); }
.t-tab-content { display: none; }
.t-tab-content.active { display: block; }

/* Loading spinner */
.t-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.2); border-top-color: #fff; border-radius: 50%; animation: t-spin 0.6s linear infinite; }
@keyframes t-spin { to { transform: rotate(360deg); } }

/* Toast */
.t-toast {
    position: fixed; bottom: 20px; left: 20px; z-index: 9999;
    padding: 12px 20px; border-radius: 10px; font-size: 0.85rem; font-weight: 600;
    color: #fff; transform: translateY(100px); opacity: 0;
    transition: all 0.3s; max-width: 400px;
}
.t-toast.show { transform: translateY(0); opacity: 1; }
.t-toast.success { background: #16a34a; }
.t-toast.error { background: #dc2626; }
.t-toast.info { background: #2563eb; }

@media (max-width: 768px) {
    .api-tester .hero { padding: 20px 16px; }
    .api-tester .hero h1 { font-size: 1.2rem; }
    .t-grid-2, .t-grid-3, .t-grid-4 { grid-template-columns: 1fr; }
    .ep-row { flex-wrap: wrap; gap: 6px; }
}
</style>

<div class="api-tester">
    <!-- Hero -->
    <div class="hero">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            اختبار API التفاعلي
        </h1>
        <div class="subtitle">اختبر جميع نقاط نهاية Mobile API مباشرة من المتصفح</div>
        <div class="badges">
            <span class="badge-item" style="color:var(--t-green);border-color:rgba(34,197,94,0.3);">REST API</span>
            <span class="badge-item" style="color:var(--t-primary);border-color:rgba(59,130,246,0.3);">Bearer Token</span>
            <span class="badge-item" style="color:var(--t-purple);border-color:rgba(168,85,247,0.3);"><?php echo $app_version; ?></span>
        </div>
    </div>

    <!-- Status Bar -->
    <div class="status-bar">
        <div class="status-item">
            <span class="status-dot <?php echo $tableExists && $tableHealthy ? 'ok' : ($tableExists ? 'warn' : 'err'); ?>"></span>
            <span style="color:<?php echo $tableExists && $tableHealthy ? 'var(--t-green)' : ($tableExists ? 'var(--t-yellow)' : 'var(--t-red)'); ?>">
                جدول api_tokens: <?php echo !$tableExists ? 'غير موجود' : ($tableHealthy ? 'سليم' : 'يحتاج إصلاح'); ?>
            </span>
        </div>
        <?php if ($tableExists && $tableHealthy): ?>
        <div class="status-item">
            <span class="status-dot <?php echo $tokenCount > 0 ? 'ok' : 'warn'; ?>"></span>
            <span style="color:<?php echo $tokenCount > 0 ? 'var(--t-green)' : 'var(--t-yellow)'; ?>">
                الرموز: <?php echo $tokenCount; ?>
            </span>
        </div>
        <?php endif; ?>
        <div class="status-item">
            <span class="status-dot" id="connectionDot" style="background:var(--t-muted);"></span>
            <span id="connectionStatus" style="color:var(--t-muted);">لم يتم الاتصال بعد</span>
        </div>
        <div style="margin-right:auto; display:flex; gap:8px;">
            <a href="settings/api-tokens.php" class="t-btn t-btn-outline" style="font-size:0.78rem; padding:6px 12px;">إدارة الرموز</a>
            <a href="create-api-table.php" class="t-btn t-btn-outline" style="font-size:0.78rem; padding:6px 12px;">إعداد API</a>
        </div>
    </div>

    <?php if (!$tableExists): ?>
    <div style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:10px; padding:16px; margin-bottom:20px; color:var(--t-red);">
        <strong>جدول api_tokens غير موجود!</strong> يجب إنشاؤه أولاً.
        <a href="create-api-table.php" style="color:var(--t-primary); font-weight:600; text-decoration:underline; margin-right:8px;">إنشاء الجدول</a>
        <button onclick="fixTable()" class="t-btn t-btn-danger" style="font-size:0.78rem; padding:6px 14px; margin-right:8px;">إصلاح تلقائي</button>
    </div>
    <?php elseif (!$tableHealthy): ?>
    <div style="background:rgba(234,179,8,0.1); border:1px solid rgba(234,179,8,0.3); border-radius:10px; padding:16px; margin-bottom:20px; color:var(--t-yellow);">
        <strong>جدول api_tokens يحتاج إصلاح!</strong> أعمدة مفقودة: <?php echo implode(', ', $missingColumns); ?>
        <a href="create-api-table.php" style="color:var(--t-primary); font-weight:600; text-decoration:underline; margin-right:8px;">إصلاح الجدول</a>
        <button onclick="fixTable()" class="t-btn t-btn-primary" style="font-size:0.78rem; padding:6px 14px; margin-right:8px;">إصلاح تلقائي فوري</button>
    </div>
    <?php endif; ?>
    <div id="fix-table-result" style="margin-bottom:16px;"></div>

    <!-- Token Section -->
    <div class="t-card" style="border-color: var(--t-primary);">
        <div class="t-card-header" style="background: linear-gradient(135deg, rgba(59,130,246,0.08), rgba(168,85,247,0.05));">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:var(--t-primary);"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <h3>المصادقة — الرمز الحالي</h3>
        </div>
        <div class="t-card-body">
            <div class="token-input-group">
                <span style="padding:0 10px; color:var(--t-muted); font-size:0.78rem; white-space:nowrap;">Bearer</span>
                <input type="text" id="tokenInput" placeholder="ism_xxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
                <button class="t-btn t-btn-success" onclick="saveToken()" style="padding:6px 14px; font-size:0.78rem;">حفظ</button>
                <button class="t-btn t-btn-outline" onclick="clearToken()" style="padding:6px 14px; font-size:0.78rem;">مسح</button>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                <button class="t-btn t-btn-primary" onclick="testConnection()" id="btnTestConn" style="font-size:0.82rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    اختبار الاتصال
                </button>
                <span id="tokenStatus" style="font-size:0.82rem; color:var(--t-muted); display:flex; align-items:center; gap:6px;"></span>
                <div id="result-conn-test" style="margin-top:8px;"></div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="t-tabs">
        <div class="t-tab active" onclick="switchTab('login', this)">تسجيل الدخول</div>
        <div class="t-tab" onclick="switchTab('auth', this)">المصادقة</div>
        <div class="t-tab" onclick="switchTab('products', this)">المنتجات</div>
        <div class="t-tab" onclick="switchTab('sales', this)">المبيعات</div>
        <div class="t-tab" onclick="switchTab('purchases', this)">المشتريات</div>
        <div class="t-tab" onclick="switchTab('reports', this)">التقارير</div>
    </div>

    <!-- ===== Tab: Login ===== -->
    <div class="t-tab-content active" id="tab-login">
        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-post">POST</span>
                <h3>/api/mobile/auth/login — تسجيل الدخول والحصول على رمز</h3>
            </div>
            <div class="t-card-body">
                <div class="t-grid t-grid-2" style="margin-bottom:12px;">
                    <div>
                        <label class="t-label">اسم المستخدم <span style="color:var(--t-red);">*</span></label>
                        <select class="t-select" id="loginUsername" onchange="document.getElementById('loginUsernameManual').value=this.value">
                            <option value="">— اختر أو أدخل يدوياً —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['username']); ?>">
                                <?php echo htmlspecialchars($u['full_name']); ?> (<?php echo htmlspecialchars($u['username']); ?> — <?php echo $u['role'] === 'admin' ? 'مدير' : 'مستخدم'; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="t-input" id="loginUsernameManual" placeholder="أو أدخل اسم المستخدم يدوياً" style="margin-top:6px;">
                    </div>
                    <div>
                        <label class="t-label">كلمة المرور <span style="color:var(--t-red);">*</span></label>
                        <input type="password" class="t-input" id="loginPassword" placeholder="كلمة المرور" autocomplete="current-password">
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="t-label">اسم الجهاز (اختياري)</label>
                    <input type="text" class="t-input" id="loginDevice" placeholder="مثال: اختبار المتصفح" value="اختبار المتصفح" style="max-width:400px;">
                </div>
                <div style="display:flex; gap:8px;">
                    <button class="t-btn t-btn-success" onclick="doLogin()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        تسجيل الدخول
                    </button>
                </div>
                <div id="result-login"></div>
            </div>
        </div>
    </div>

    <!-- ===== Tab: Auth ===== -->
    <div class="t-tab-content" id="tab-auth">
        <div class="ep-group">
            <div class="t-card">
                <div class="t-card-header">
                    <span class="method-badge m-post">POST</span>
                    <h3>/api/mobile/auth/logout — تسجيل الخروج</h3>
                </div>
                <div class="t-card-body">
                    <button class="t-btn t-btn-danger" onclick="apiCall('POST','api/mobile/auth/logout',null,'logout')">
                        تسجيل الخروج وإبطال الرمز
                    </button>
                    <div id="result-logout"></div>
                </div>
            </div>
            <div class="t-card">
                <div class="t-card-header">
                    <span class="method-badge m-get">GET</span>
                    <h3>/api/mobile/auth/me — بيانات المستخدم الحالي</h3>
                </div>
                <div class="t-card-body">
                    <button class="t-btn t-btn-primary" onclick="apiCall('GET','api/mobile/auth/me',null,'me')">
                        عرض بياناتي
                    </button>
                    <div id="result-me"></div>
                </div>
            </div>
            <div class="t-card">
                <div class="t-card-header">
                    <span class="method-badge m-post">POST</span>
                    <h3>/api/mobile/auth/refresh — تجديد الرمز</h3>
                </div>
                <div class="t-card-body">
                    <button class="t-btn t-btn-primary" onclick="apiCall('POST','api/mobile/auth/refresh',null,'refresh')">
                        تجديد الرمز
                    </button>
                    <div id="result-refresh"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Tab: Products ===== -->
    <div class="t-tab-content" id="tab-products">
        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/products — قائمة المنتجات</h3>
            </div>
            <div class="t-card-body">
                <div class="t-grid t-grid-3" style="margin-bottom:12px;">
                    <div>
                        <label class="t-label">بحث</label>
                        <input type="text" class="t-input" id="prodSearch" placeholder="اسم، كود، باركود...">
                    </div>
                    <div>
                        <label class="t-label">التصنيف</label>
                        <select class="t-select" id="prodCategory">
                            <option value="">الكل</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?php echo intval($c['id']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="t-label">الصفحة / عدد</label>
                        <div style="display:flex; gap:6px;">
                            <input type="number" class="t-input" id="prodPage" value="1" min="1" style="width:70px;">
                            <input type="number" class="t-input" id="prodPerPage" value="10" min="1" max="100" style="width:70px;">
                        </div>
                    </div>
                </div>
                <div style="display:flex; gap:8px; margin-bottom:8px;">
                    <label style="display:flex; align-items:center; gap:6px; font-size:0.82rem; color:var(--t-muted); cursor:pointer;">
                        <input type="checkbox" id="prodLowStock"> منخفض المخزون فقط
                    </label>
                </div>
                <button class="t-btn t-btn-primary" onclick="searchProducts()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    بحث
                </button>
                <div id="result-products-list"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/products/{id} — تفاصيل منتج</h3>
            </div>
            <div class="t-card-body">
                <div class="t-grid t-grid-3" style="margin-bottom:12px;">
                    <div>
                        <label class="t-label">معرف المنتج</label>
                        <select class="t-select" id="prodIdSelect" onchange="document.getElementById('prodIdInput').value=this.value">
                            <option value="">— اختر —</option>
                            <?php foreach ($sampleProducts as $p): ?>
                            <option value="<?php echo intval($p['id']); ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo intval($p['id']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" class="t-input" id="prodIdInput" placeholder="أو أدخل ID" min="1" style="margin-top:6px;">
                    </div>
                </div>
                <button class="t-btn t-btn-primary" onclick="getProduct()">عرض المنتج</button>
                <div id="result-products-show"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-post">POST</span>
                <h3>/api/mobile/products — إضافة منتج جديد</h3>
            </div>
            <div class="t-card-body">
                <div class="t-grid t-grid-2" style="margin-bottom:12px;">
                    <div>
                        <label class="t-label">اسم المنتج <span style="color:var(--t-red);">*</span></label>
                        <input type="text" class="t-input" id="newProdName" placeholder="اسم المنتج">
                    </div>
                    <div>
                        <label class="t-label">الكود</label>
                        <input type="text" class="t-input" id="newProdCode" placeholder="تلقائي إن لم يُحدد">
                    </div>
                    <div>
                        <label class="t-label">سعر الشراء <span style="color:var(--t-red);">*</span></label>
                        <input type="number" class="t-input" id="newProdBuyPrice" placeholder="0.00" step="0.01">
                    </div>
                    <div>
                        <label class="t-label">سعر البيع <span style="color:var(--t-red);">*</span></label>
                        <input type="number" class="t-input" id="newProdSellPrice" placeholder="0.00" step="0.01">
                    </div>
                    <div>
                        <label class="t-label">الكمية <span style="color:var(--t-red);">*</span></label>
                        <input type="number" class="t-input" id="newProdQty" placeholder="0" step="0.01">
                    </div>
                    <div>
                        <label class="t-label">الوحدة <span style="color:var(--t-red);">*</span></label>
                        <input type="text" class="t-input" id="newProdUnit" placeholder="piece" value="piece">
                    </div>
                </div>
                <button class="t-btn t-btn-success" onclick="createProduct()">إضافة المنتج</button>
                <div id="result-products-create"></div>
            </div>
        </div>
    </div>

    <!-- ===== Tab: Sales ===== -->
    <div class="t-tab-content" id="tab-sales">
        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/sales — قائمة المبيعات</h3>
            </div>
            <div class="t-card-body">
                <div class="t-grid t-grid-3" style="margin-bottom:12px;">
                    <div>
                        <label class="t-label">من تاريخ</label>
                        <input type="date" class="t-input" id="salesDateFrom">
                    </div>
                    <div>
                        <label class="t-label">إلى تاريخ</label>
                        <input type="date" class="t-input" id="salesDateTo">
                    </div>
                    <div>
                        <label class="t-label">الصفحة / عدد</label>
                        <div style="display:flex; gap:6px;">
                            <input type="number" class="t-input" id="salesPage" value="1" min="1" style="width:70px;">
                            <input type="number" class="t-input" id="salesPerPage" value="10" min="1" max="100" style="width:70px;">
                        </div>
                    </div>
                </div>
                <button class="t-btn t-btn-primary" onclick="listSales()">عرض المبيعات</button>
                <div id="result-sales-list"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-post">POST</span>
                <h3>/api/mobile/sales — تسجيل عملية بيع</h3>
            </div>
            <div class="t-card-body">
                <label class="t-label">عناصر البيع (JSON)</label>
                <textarea class="t-textarea" id="saleItems" rows="8">{
  "items": [
    {"product_id": 1, "quantity": 2, "selling_price": 25.00}
  ],
  "notes": "بيع تجريبي من صفحة الاختبار"
}</textarea>
                <div style="margin-top:8px;">
                    <button class="t-btn t-btn-success" onclick="createSale()">تسجيل البيع</button>
                </div>
                <div id="result-sales-create"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/sales/{id} — تفاصيل بيع</h3>
            </div>
            <div class="t-card-body">
                <div style="display:flex; gap:8px; align-items:end;">
                    <div>
                        <label class="t-label">معرف البيع</label>
                        <input type="number" class="t-input" id="saleIdInput" placeholder="ID" min="1" style="width:120px;">
                    </div>
                    <button class="t-btn t-btn-primary" onclick="getSale()">عرض</button>
                </div>
                <div id="result-sales-show"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-delete">DELETE</span>
                <h3>/api/mobile/sales/{id} — حذف بيع</h3>
            </div>
            <div class="t-card-body">
                <div style="display:flex; gap:8px; align-items:end;">
                    <div>
                        <label class="t-label">معرف البيع</label>
                        <input type="number" class="t-input" id="saleDeleteId" placeholder="ID" min="1" style="width:120px;">
                    </div>
                    <button class="t-btn t-btn-danger" onclick="deleteSale()">حذف</button>
                </div>
                <div id="result-sales-delete"></div>
            </div>
        </div>
    </div>

    <!-- ===== Tab: Purchases ===== -->
    <div class="t-tab-content" id="tab-purchases">
        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/purchases — قائمة المشتريات</h3>
            </div>
            <div class="t-card-body">
                <div class="t-grid t-grid-3" style="margin-bottom:12px;">
                    <div>
                        <label class="t-label">من تاريخ</label>
                        <input type="date" class="t-input" id="purchDateFrom">
                    </div>
                    <div>
                        <label class="t-label">إلى تاريخ</label>
                        <input type="date" class="t-input" id="purchDateTo">
                    </div>
                    <div>
                        <label class="t-label">الصفحة / عدد</label>
                        <div style="display:flex; gap:6px;">
                            <input type="number" class="t-input" id="purchPage" value="1" min="1" style="width:70px;">
                            <input type="number" class="t-input" id="purchPerPage" value="10" min="1" max="100" style="width:70px;">
                        </div>
                    </div>
                </div>
                <button class="t-btn t-btn-primary" onclick="listPurchases()">عرض المشتريات</button>
                <div id="result-purchases-list"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-post">POST</span>
                <h3>/api/mobile/purchases — تسجيل عملية شراء</h3>
            </div>
            <div class="t-card-body">
                <label class="t-label">بيانات الشراء (JSON)</label>
                <textarea class="t-textarea" id="purchaseData" rows="8">{
  "product_id": 1,
  "quantity": 10,
  "cost_price": 15.00,
  "notes": "شراء تجريبي من صفحة الاختبار"
}</textarea>
                <div style="margin-top:8px;">
                    <button class="t-btn t-btn-success" onclick="createPurchase()">تسجيل الشراء</button>
                </div>
                <div id="result-purchases-create"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/purchases/{id} — تفاصيل شراء</h3>
            </div>
            <div class="t-card-body">
                <div style="display:flex; gap:8px; align-items:end;">
                    <div>
                        <label class="t-label">معرف الشراء</label>
                        <input type="number" class="t-input" id="purchIdInput" placeholder="ID" min="1" style="width:120px;">
                    </div>
                    <button class="t-btn t-btn-primary" onclick="getPurchase()">عرض</button>
                </div>
                <div id="result-purchases-show"></div>
            </div>
        </div>
    </div>

    <!-- ===== Tab: Reports ===== -->
    <div class="t-tab-content" id="tab-reports">
        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/reports/dashboard — لوحة التحكم</h3>
            </div>
            <div class="t-card-body">
                <button class="t-btn t-btn-primary" onclick="apiCall('GET','api/mobile/reports/dashboard',null,'dashboard')">
                    عرض لوحة التحكم
                </button>
                <div id="result-dashboard"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/reports/profits — تقرير الأرباح</h3>
            </div>
            <div class="t-card-body">
                <div style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                    <div>
                        <label class="t-label">من تاريخ</label>
                        <input type="date" class="t-input" id="profitDateFrom" value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div>
                        <label class="t-label">إلى تاريخ</label>
                        <input type="date" class="t-input" id="profitDateTo" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button class="t-btn t-btn-primary" onclick="getProfits()">عرض الأرباح</button>
                </div>
                <div id="result-profits"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/reports/categories — التصنيفات</h3>
            </div>
            <div class="t-card-body">
                <button class="t-btn t-btn-primary" onclick="apiCall('GET','api/mobile/reports/categories',null,'categories')">
                    عرض التصنيفات
                </button>
                <div id="result-categories"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/reports/stock — تقرير المخزون</h3>
            </div>
            <div class="t-card-body">
                <button class="t-btn t-btn-primary" onclick="apiCall('GET','api/mobile/reports/stock',null,'stock')">
                    عرض المخزون
                </button>
                <div id="result-stock"></div>
            </div>
        </div>

        <div class="t-card">
            <div class="t-card-header">
                <span class="method-badge m-get">GET</span>
                <h3>/api/mobile/reports/expiry — تقرير الصلاحية</h3>
            </div>
            <div class="t-card-body">
                <div style="display:flex; gap:8px; align-items:end;">
                    <div>
                        <label class="t-label">أيام قادمة</label>
                        <input type="number" class="t-input" id="expiryDays" value="30" min="1" style="width:100px;">
                    </div>
                    <button class="t-btn t-btn-primary" onclick="getExpiry()">عرض الصلاحية</button>
                </div>
                <div id="result-expiry"></div>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="t-toast" id="toast"></div>

<script>
// ===== Configuration =====
const BASE_URL = '<?php echo htmlspecialchars($base_url, ENT_QUOTES); ?>';

// ===== Token Management =====
function getToken() {
    return localStorage.getItem('ism_api_token') || '';
}

function saveToken() {
    const token = document.getElementById('tokenInput').value.trim();
    if (!token) { showToast('يرجى إدخال رمز', 'error'); return; }
    localStorage.setItem('ism_api_token', token);
    showToast('تم حفظ الرمز بنجاح', 'success');
    updateTokenStatus();
}

function clearToken() {
    localStorage.removeItem('ism_api_token');
    document.getElementById('tokenInput').value = '';
    showToast('تم مسح الرمز', 'info');
    updateTokenStatus();
}

function setToken(token) {
    localStorage.setItem('ism_api_token', token);
    document.getElementById('tokenInput').value = token;
    updateTokenStatus();
}

function updateTokenStatus() {
    const token = getToken();
    const el = document.getElementById('tokenStatus');
    if (token) {
        el.innerHTML = '<span style="color:var(--t-green);">✓ رمز محفوظ</span> <span style="color:var(--t-muted); font-size:0.75rem;">(' + token.substring(0, 12) + '...)</span>';
    } else {
        el.innerHTML = '<span style="color:var(--t-yellow);">لا يوجد رمز — سجّل الدخول أولاً</span>';
    }
}

// ===== Tab Switching =====
function switchTab(tabName, el) {
    document.querySelectorAll('.t-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.t-tab-content').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('tab-' + tabName).classList.add('active');
}

// ===== API Call Engine =====
async function apiCall(method, endpoint, body, resultId) {
    if (!resultId) resultId = endpoint.replace(/[\/{}]/g, '-').replace(/^-/, '');
    const resultEl = document.getElementById('result-' + resultId);
    if (!resultEl) { console.warn('No result element for:', resultId); return; }

    const token = getToken();
    const url = BASE_URL + '/' + endpoint;

    // Show loading
    resultEl.innerHTML = renderPending(url, method);

    const startTime = performance.now();

    try {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            }
        };

        if (token && !endpoint.includes('auth/login')) {
            options.headers['Authorization'] = 'Bearer ' + token;
        }

        if (body && method !== 'GET') {
            options.body = typeof body === 'string' ? body : JSON.stringify(body);
        }

        const response = await fetch(url, options);
        const endTime = performance.now();
        const duration = Math.round(endTime - startTime);

        let data;
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            data = await response.json();
        } else {
            const text = await response.text();
            try { data = JSON.parse(text); } catch(e) { data = text; }
        }

        resultEl.innerHTML = renderResult(response.status, data, duration, url, method);

        // Auto-save token from login
        if (endpoint.includes('auth/login') && data && data.success && data.data && data.data.token) {
            setToken(data.data.token);
            showToast('تم تسجيل الدخول بنجاح! الرمز محفوظ تلقائياً', 'success');
            updateConnectionStatus(true);
        }

        // Auto-save token from refresh
        if (endpoint.includes('auth/refresh') && data && data.success && data.data && data.data.token) {
            setToken(data.data.token);
            showToast('تم تجديد الرمز! الرمز الجديد محفوظ', 'success');
        }

        // Update connection status on auth/me success
        if (endpoint.includes('auth/me')) {
            updateConnectionStatus(response.ok);
        }

    } catch (error) {
        const endTime = performance.now();
        const duration = Math.round(endTime - startTime);
        resultEl.innerHTML = renderResult(0, { error: error.message }, duration, url, method);
        if (endpoint.includes('auth/me')) updateConnectionStatus(false);
    }
}

// ===== Specific API Actions =====

function doLogin() {
    const username = document.getElementById('loginUsernameManual').value || document.getElementById('loginUsername').value;
    const password = document.getElementById('loginPassword').value;
    const deviceName = document.getElementById('loginDevice').value || 'اختبار المتصفح';

    if (!username || !password) {
        showToast('يرجى إدخال اسم المستخدم وكلمة المرور', 'error');
        return;
    }

    apiCall('POST', 'api/mobile/auth/login', {
        username: username,
        password: password,
        device_name: deviceName
    }, 'login');
}

function testConnection() {
    const token = getToken();
    if (!token) {
        showToast('لا يوجد رمز! سجّل الدخول أولاً', 'error');
        return;
    }
    apiCall('GET', 'api/mobile/auth/me', null, 'conn-test');
}

function searchProducts() {
    const search = document.getElementById('prodSearch').value;
    const catId = document.getElementById('prodCategory').value;
    const page = document.getElementById('prodPage').value || 1;
    const perPage = document.getElementById('prodPerPage').value || 10;
    const lowStock = document.getElementById('prodLowStock').checked;

    let params = new URLSearchParams();
    if (search) params.set('search', search);
    if (catId) params.set('category_id', catId);
    params.set('page', page);
    params.set('per_page', perPage);
    if (lowStock) params.set('low_stock', '1');

    apiCall('GET', 'api/mobile/products?' + params.toString(), null, 'products-list');
}

function getProduct() {
    const id = document.getElementById('prodIdInput').value || document.getElementById('prodIdSelect').value;
    if (!id) { showToast('أدخل معرف المنتج', 'error'); return; }
    apiCall('GET', 'api/mobile/products/' + id, null, 'products-show');
}

function createProduct() {
    const body = {
        name: document.getElementById('newProdName').value,
        code: document.getElementById('newProdCode').value || undefined,
        purchase_price: parseFloat(document.getElementById('newProdBuyPrice').value) || 0,
        selling_price: parseFloat(document.getElementById('newProdSellPrice').value) || 0,
        quantity: parseFloat(document.getElementById('newProdQty').value) || 0,
        unit: document.getElementById('newProdUnit').value || 'piece',
    };
    if (!body.name) { showToast('اسم المنتج مطلوب', 'error'); return; }
    apiCall('POST', 'api/mobile/products', body, 'products-create');
}

function listSales() {
    const dateFrom = document.getElementById('salesDateFrom').value;
    const dateTo = document.getElementById('salesDateTo').value;
    const page = document.getElementById('salesPage').value || 1;
    const perPage = document.getElementById('salesPerPage').value || 10;

    let params = new URLSearchParams();
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    params.set('page', page);
    params.set('per_page', perPage);

    apiCall('GET', 'api/mobile/sales?' + params.toString(), null, 'sales-list');
}

function createSale() {
    try {
        const body = JSON.parse(document.getElementById('saleItems').value);
        apiCall('POST', 'api/mobile/sales', body, 'sales-create');
    } catch(e) {
        showToast('JSON غير صالح: ' + e.message, 'error');
    }
}

function getSale() {
    const id = document.getElementById('saleIdInput').value;
    if (!id) { showToast('أدخل معرف البيع', 'error'); return; }
    apiCall('GET', 'api/mobile/sales/' + id, null, 'sales-show');
}

function deleteSale() {
    const id = document.getElementById('saleDeleteId').value;
    if (!id) { showToast('أدخل معرف البيع', 'error'); return; }
    if (!confirm('هل أنت متأكد من حذف عملية البيع #' + id + '؟ سيتم استرجاع المخزون.')) return;
    apiCall('DELETE', 'api/mobile/sales/' + id, null, 'sales-delete');
}

function listPurchases() {
    const dateFrom = document.getElementById('purchDateFrom').value;
    const dateTo = document.getElementById('purchDateTo').value;
    const page = document.getElementById('purchPage').value || 1;
    const perPage = document.getElementById('purchPerPage').value || 10;

    let params = new URLSearchParams();
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    params.set('page', page);
    params.set('per_page', perPage);

    apiCall('GET', 'api/mobile/purchases?' + params.toString(), null, 'purchases-list');
}

function createPurchase() {
    try {
        const body = JSON.parse(document.getElementById('purchaseData').value);
        apiCall('POST', 'api/mobile/purchases', body, 'purchases-create');
    } catch(e) {
        showToast('JSON غير صالح: ' + e.message, 'error');
    }
}

function getPurchase() {
    const id = document.getElementById('purchIdInput').value;
    if (!id) { showToast('أدخل معرف الشراء', 'error'); return; }
    apiCall('GET', 'api/mobile/purchases/' + id, null, 'purchases-show');
}

function getProfits() {
    const dateFrom = document.getElementById('profitDateFrom').value;
    const dateTo = document.getElementById('profitDateTo').value;
    let params = new URLSearchParams();
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    apiCall('GET', 'api/mobile/reports/profits?' + params.toString(), null, 'profits');
}

function getExpiry() {
    const days = document.getElementById('expiryDays').value || 30;
    apiCall('GET', 'api/mobile/reports/expiry?days_ahead=' + days, null, 'expiry');
}

// ===== Rendering Helpers =====

function renderPending(url, method) {
    return '<div class="result-panel"><div class="result-header pending"><span class="t-spinner"></span> جارٍ الإرسال... <code style="direction:ltr;margin-right:8px;font-size:0.75rem;">' + method + ' ' + url + '</code></div></div>';
}

function renderResult(status, data, duration, url, method) {
    const isOk = status >= 200 && status < 300;
    const headerClass = isOk ? 'success' : 'error';
    const statusColor = isOk ? 'var(--t-green)' : (status === 0 ? 'var(--t-red)' : 'var(--t-red)');

    let statusText = status === 0 ? 'Network Error' : status;
    let jsonStr = '';
    try {
        jsonStr = syntaxHighlight(JSON.stringify(data, null, 2));
    } catch(e) {
        jsonStr = escapeHtml(String(data));
    }

    return '<div class="result-panel">' +
        '<div class="result-header ' + headerClass + '">' +
        '<span style="font-size:0.9rem;">' + (isOk ? '✓' : '✗') + '</span>' +
        '<code style="direction:ltr; font-size:0.75rem;">' + method + ' ' + url + '</code>' +
        '<span style="margin-right:auto;"></span>' +
        '<span style="color:' + statusColor + '; font-weight:800;">HTTP ' + statusText + '</span>' +
        '<span style="color:var(--t-muted); font-size:0.75rem;">' + duration + 'ms</span>' +
        '</div>' +
        '<div class="t-code" style="margin:0; border:none; border-radius:0;">' + jsonStr + '</div>' +
        '</div>';
}

function syntaxHighlight(json) {
    json = escapeHtml(json);
    return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
        let cls = 'number';
        if (/^"/.test(match)) {
            if (/:$/.test(match)) {
                cls = 'key';
            } else {
                cls = 'string';
            }
        } else if (/true|false/.test(match)) {
            cls = 'bool';
        } else if (/null/.test(match)) {
            cls = 'null';
        }
        return '<span class="' + cls + '">' + match + '</span>';
    });
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function updateConnectionStatus(ok) {
    const dot = document.getElementById('connectionDot');
    const status = document.getElementById('connectionStatus');
    if (ok) {
        dot.className = 'status-dot ok';
        status.style.color = 'var(--t-green)';
        status.textContent = 'متصل';
    } else {
        dot.className = 'status-dot err';
        status.style.color = 'var(--t-red)';
        status.textContent = 'فشل الاتصال';
    }
}

// ===== Toast =====
function showToast(msg, type) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 't-toast ' + type;
    setTimeout(() => el.classList.add('show'), 10);
    setTimeout(() => el.classList.remove('show'), 3000);
}

// ===== Fix Table — Trigger auto-migration via API call =====
function fixTable() {
    const resultEl = document.getElementById('fix-table-result');
    resultEl.innerHTML = '<div class="result-panel"><div class="result-header pending"><span class="t-spinner"></span> جارٍ إصلاح الجدول...</div></div>';
    
    // The auto-migration runs on EVERY API request to api/mobile/*
    // So we just need to make any API request to trigger it
    // We use the login endpoint with dummy data — the auto-migration runs BEFORE the login logic
    fetch(BASE_URL + '/api/mobile/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: '__fix_table_trigger__', password: '__dummy__' })
    })
    .then(r => r.json())
    .then(data => {
        // Even though login fails, the auto-migration should have run
        // Now check the table status by reloading the page
        resultEl.innerHTML = '<div class="result-panel"><div class="result-header success">✓ تم تشغيل الإصلاح التلقائي — جارٍ إعادة تحميل الصفحة...</div></div>';
        setTimeout(() => window.location.reload(), 2000);
    })
    .catch(err => {
        resultEl.innerHTML = '<div class="result-panel"><div class="result-header error">✗ فشل الاتصال: ' + escapeHtml(err.message) + '</div></div>';
    });
}

// ===== Init =====
document.addEventListener('DOMContentLoaded', function() {
    const savedToken = getToken();
    if (savedToken) {
        document.getElementById('tokenInput').value = savedToken;
    }
    updateTokenStatus();
});
</script>

<?php require_once 'includes/footer.php'; ?>
