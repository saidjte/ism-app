<?php
/**
 * ===== مشغّل الاختبارات =====
 * يكتشف ويشغل كل ملفات الاختبار تلقائياً
 *
 * الاستخدام:
 *   php tests/run.php           (سطر الأوامر)
 *   http://localhost/is-app/tests/run.php  (المتصفح)
 */

// === الكشف عن بيئة التشغيل ===
$isWeb = (PHP_SAPI !== 'cli' && !isset($_SERVER['argv']));

// مسار المجلد الأساسي
$baseDir = dirname(__DIR__);

// بدء الجلسة مبكراً قبل أي إخراج
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// === تحميل ملفات النظام (قبل أي إخراج) ===
require_once $baseDir . '/includes/AppException.php';
require_once $baseDir . '/includes/ErrorHandler.php';
ErrorHandler::init();
require_once $baseDir . '/includes/ApiResponse.php';
require_once $baseDir . '/includes/BaseController.php';
require_once $baseDir . '/includes/Pagination.php';
require_once $baseDir . '/includes/Cache.php';
require_once $baseDir . '/includes/i18n.php';
require_once $baseDir . '/includes/audit_log.php';
require_once $baseDir . '/includes/ApiRouter.php';
require_once $baseDir . '/includes/CaisseHelper.php';
require_once $baseDir . '/includes/Database.php';
require_once $baseDir . '/includes/EnvEncryptor.php';
require_once $baseDir . '/includes/csrf.php';

// === ثوابت قاعدة البيانات (مطلوبة من AppDatabase) ===
if (!defined('DB_HOST'))   define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))   define('DB_NAME', 'test_db');
if (!defined('DB_USER'))   define('DB_USER', 'root');
if (!defined('DB_PASS'))   define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// === دوال مساعدة عامة (مطلوبة من الاختبارات) ===
// هذه الدوال تفوّض العمل إلى AppDatabase::getInstance()
// ويتم حقن اتصال SQLite في كل اختبار عبر Reflection

if (!function_exists('fetchOne')) {
    function fetchOne($sql, $params = []) {
        return AppDatabase::getInstance()->fetchOne($sql, $params);
    }
}
if (!function_exists('fetchAll')) {
    function fetchAll($sql, $params = []) {
        return AppDatabase::getInstance()->fetchAll($sql, $params);
    }
}
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        return AppDatabase::getInstance()->getConnection();
    }
}
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') {
        return AppDatabase::getInstance()->getSetting($key, $default);
    }
}
if (!function_exists('saveSetting')) {
    function saveSetting($key, $value, $description = null) {
        return AppDatabase::getInstance()->saveSetting($key, $value, $description);
    }
}
if (!function_exists('executeQuery')) {
    function executeQuery($sql, $params = []) {
        return AppDatabase::getInstance()->query($sql, $params);
    }
}
if (!function_exists('insertData')) {
    function insertData($sql, $params = []) {
        return AppDatabase::getInstance()->insert($sql, $params);
    }
}
if (!function_exists('formatMoney')) {
    function formatMoney(?float $amount, int $decimals = 2): string {
        return number_format($amount ?? 0, $decimals) . ' د.م.';
    }
}
if (!function_exists('getCurrencySymbol')) {
    function getCurrencySymbol(): string {
        return 'د.م.';
    }
}

// === Polyfill لـ mbstring إذا لم تكن الإضافة مثبتة ===
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return preg_match_all('/./us', $string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        $total = count($chars);
        if ($start < 0) $start = max(0, $total + $start);
        if ($length === null) {
            return implode('', array_slice($chars, $start));
        }
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string {
        return strtolower($string);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string {
        return strtoupper($string);
    }
}
if (!function_exists('mb_substr_count')) {
    function mb_substr_count(string $haystack, string $needle, ?string $encoding = null): int {
        return substr_count($haystack, $needle);
    }
}

// === تهيئة نظام الترجمة (i18n) للاختبارات ===
// فرض تحميل الترجمات العربية (اللغة الافتراضية)
// هذا ضروري لأن بعض الأصناف (Pagination, CaisseHelper, audit_log) تستخدم t()
if (!$GLOBALS['i18n_initialized']) {
    $GLOBALS['i18n_lang'] = 'ar';
    $GLOBALS['i18n_initialized'] = true;
    $langFile = $baseDir . '/includes/languages/ar.php';
    if (file_exists($langFile)) {
        $GLOBALS['i18n_translations'] = require $langFile;
    }
}

// === تحميل ملفات الاختبار ===
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/AppExceptionTest.php';
require_once __DIR__ . '/PaginationTest.php';
require_once __DIR__ . '/BaseControllerTest.php';
require_once __DIR__ . '/CacheTest.php';
require_once __DIR__ . '/AuditLogTest.php';
require_once __DIR__ . '/CsrfTest.php';
require_once __DIR__ . '/ApiRouterTest.php';
require_once __DIR__ . '/ApiResponseTest.php';
require_once __DIR__ . '/CaisseHelperTest.php';
require_once __DIR__ . '/DatabaseTest.php';

// === تحميل خدمات التطبيق للاختبار ===
require_once $baseDir . '/includes/Services/ProductService.php';
require_once $baseDir . '/includes/Services/SaleService.php';
require_once $baseDir . '/includes/Services/PurchaseService.php';
if (file_exists($baseDir . '/includes/Services/SearchService.php')) {
    require_once $baseDir . '/includes/Services/SearchService.php';
}

// === تحميل اختبارات الخدمات ===
require_once __DIR__ . '/ProductServiceTest.php';
require_once __DIR__ . '/SaleServiceTest.php';
require_once __DIR__ . '/PurchaseServiceTest.php';

// تسجيل كل فئات الاختبار
$testClasses = [
    AppExceptionTest::class,
    PaginationTest::class,
    BaseControllerTest::class,
    CacheTest::class,
    AuditLogTest::class,
    CsrfTest::class,
    ApiRouterTest::class,
    ApiResponseTest::class,
    CaisseHelperTest::class,
    DatabaseTest::class,
    ProductServiceTest::class,
    SaleServiceTest::class,
    PurchaseServiceTest::class,
];

// وقت البدء
$startTime = microtime(true);

// === تشغيل الاختبارات ===
$suiteResults = [];
$totalPassed = 0;
$totalFailed = 0;
$totalErrors = 0;
$totalTests = 0;
$allFailures = [];
$allErrors = [];

foreach ($testClasses as $class) {
    if (!class_exists($class)) {
        $suiteResults[] = [
            'name' => $class,
            'passed' => 0, 'failed' => 0, 'errors' => 1,
            'failures' => [], 'error_details' => ["فئة الاختبار {$class} غير موجودة"],
        ];
        $totalErrors++;
        continue;
    }

    $shortName = (new ReflectionClass($class))->getShortName();

    try {
        $test = new $class($shortName);
        $result = $test->runAll();
    } catch (\Throwable $e) {
        $result = [
            'name' => $shortName,
            'passed' => 0, 'failed' => 0, 'errors' => 1,
            'failures' => [],
            'error_details' => ["خطأ في تشغيل فئة الاختبار: {$e->getMessage()}"],
        ];
    }

    $result['name'] = $shortName;
    $suiteResults[] = $result;

    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    $totalErrors  += $result['errors'];
    $totalTests   += $result['passed'] + $result['failed'] + $result['errors'];

    foreach ($result['failures'] as $f) {
        $allFailures[] = $f;
    }
    foreach ($result['error_details'] as $e) {
        $allErrors[] = $e;
    }
}

// وقت الانتهاء
$endTime = microtime(true);
$duration = round(($endTime - $startTime) * 1000);
$allOk = ($totalFailed === 0 && $totalErrors === 0);

// نسبة النجاح
$passRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 100;

// === عرض النتائج ===
if ($isWeb) {
    renderWeb($suiteResults, $totalTests, $totalPassed, $totalFailed, $totalErrors, $duration, $passRate, $allOk, $allFailures, $allErrors);
} else {
    renderCli($suiteResults, $totalTests, $totalPassed, $totalFailed, $totalErrors, $duration, $allOk, $allFailures, $allErrors);
}

// ==================================================================
// دوال العرض
// ==================================================================

function renderCli(array $suites, int $total, int $passed, int $failed, int $errors, int $duration, bool $ok, array $failures, array $errList): void
{
    echo "╔══════════════════════════════════════════════════════╗\n";
    echo "║     نظام اختبارات الحماية من الانحدار (Regression)   ║\n";
    echo "║          is-app - نظام إدارة المخزون                  ║\n";
    echo "╚══════════════════════════════════════════════════════╝\n\n";

    foreach ($suites as $s) {
        echo "▸ {$s['name']}\n";
        if ($s['passed'] > 0) echo "  ✓ {$s['passed']} ناجح\n";
        foreach ($s['failures'] as $f) echo "  {$f}\n";
        foreach ($s['error_details'] as $e) echo "  {$e}\n";
        echo "\n";
    }

    echo "───────────────────────────────────────────────\n";
    echo "📊 ملخص النتائج:\n";
    echo "  إجمالي الاختبارات: {$total}\n";
    echo "  ✓ ناجح:           {$passed}\n";
    echo "  ✗ فاشل:           {$failed}\n";
    echo "  ⚠ أخطاء:          {$errors}\n";
    echo "  ⏱  الوقت:         {$duration} ms\n";
    echo "───────────────────────────────────────────────\n";

    if ($ok) {
        echo "🎉 كل الاختبارات ناجحة! الكود محمي من الانحدار.\n";
    } else {
        echo "❌ هناك اختبارات فاشلة أو أخطاء تحتاج إصلاح!\n";
    }
    exit($ok ? 0 : 1);
}

function renderWeb(array $suites, int $total, int $passed, int $failed, int $errors, int $duration, float $passRate, bool $ok, array $failures, array $errList): void
{
    $barColor = $ok ? 'var(--secondary)' : ($passRate >= 70 ? 'var(--warning)' : 'var(--danger)');
    $statusIcon = $ok ? '✅' : '❌';
    $statusText = $ok ? 'كل الاختبارات ناجحة!' : 'هناك اختبارات فاشلة أو أخطاء تحتاج إصلاح';
    $statusBg  = $ok ? 'var(--secondary-light)' : 'var(--danger-light)';
    $statusClr = $ok ? 'var(--secondary-dark)' : 'var(--danger)';

    $now = date('Y-m-d H:i:s');

    // بناء صفوف جدول الأخطاء
    $errorsRows = '';
    foreach ($failures as $idx => $f) {
        $num = $idx + 1;
        $esc = htmlspecialchars($f, ENT_QUOTES, 'UTF-8');
        $errorsRows .= "<tr><td>{$num}</td><td><code>{$esc}</code></td></tr>\n";
    }
    foreach ($errList as $idx => $e) {
        $num = count($failures) + $idx + 1;
        $esc = htmlspecialchars($e, ENT_QUOTES, 'UTF-8');
        $errorsRows .= "<tr><td>{$num}</td><td><code>{$esc}</code></td></tr>\n";
    }

    $errorsSectionContent = $errorsRows
        ? '<table class="errors-table">'
            . '<thead><tr><th style="width:50px">#</th><th>التفاصيل</th></tr></thead>'
            . '<tbody>' . $errorsRows . '</tbody>'
            . '</table>'
        : '<div class="no-errors-msg">🎉 لا توجد أخطاء — كل الاختبارات تعمل بشكل صحيح!</div>';

    // بناء بطاقات مجموعات الاختبار
    $suiteCards = '';
    foreach ($suites as $s) {
        $suiteTotal = $s['passed'] + $s['failed'] + $s['errors'];
        $suiteOk = ($s['failed'] === 0 && $s['errors'] === 0);
        $badge = $suiteOk
            ? '<span class="suite-badge badge-ok">' . $s['passed'] . ' ناجح</span>'
            : '<span class="suite-badge badge-fail">' . $s['failed'] . ' فاشل · ' . $s['errors'] . ' خطأ</span>';

        $suiteBarColor = $suiteOk ? 'var(--secondary)' : 'var(--danger)';
        $suiteBarWidth = $suiteTotal > 0 ? round(($s['passed'] / $suiteTotal) * 100) : 0;
        $suiteBarBg = $suiteOk ? 'var(--secondary-light)' : 'var(--danger-light)';

        $detailsHtml = '';
        foreach ($s['failures'] as $f) {
            $detailsHtml .= '<div class="detail-row detail-fail">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        foreach ($s['error_details'] as $e) {
            $detailsHtml .= '<div class="detail-row detail-err">' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $failBadge = $s['failed'] > 0
            ? '<span class="cnt cnt-fail">' . $s['failed'] . ' فاشل</span>'
            : '';
        $errBadge = $s['errors'] > 0
            ? '<span class="cnt cnt-err">' . $s['errors'] . ' خطأ</span>'
            : '';

        $suiteCards .= <<<HTML
        <div class="suite-card">
            <div class="suite-header">
                <span class="suite-name">
                    <span class="suite-icon">{$statusIcon}</span>
                    {$s['name']}
                </span>
                {$badge}
            </div>
            <div class="suite-bar-wrap">
                <div class="suite-bar-bg" style="background:{$suiteBarBg}">
                    <div class="suite-bar" style="width:{$suiteBarWidth}%;background:{$suiteBarColor}"></div>
                </div>
                <span class="suite-pct">{$suiteBarWidth}%</span>
            </div>
            <div class="suite-counts">
                <span class="cnt cnt-ok">{$s['passed']} ناجح</span>
                {$failBadge}
                {$errBadge}
                <span class="cnt cnt-total">{$suiteTotal} اختبار</span>
            </div>
            {$detailsHtml}
        </div>
HTML;
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبارات الانحدار - is-app</title>
    <style>
        /* === المتغيرات === */
        :root {
            --primary: #2563eb; --primary-light: #dbeafe;
            --secondary: #059669; --secondary-dark: #047857; --secondary-light: #d1fae5;
            --danger: #dc2626; --danger-dark: #b91c1c; --danger-light: #fee2e2;
            --warning: #d97706; --warning-light: #fef3c7;
            --info: #0891b2; --info-light: #cffafe;
            --dark: #1e293b;
            --gray-50: #f8fafc; --gray-100: #f1f5f9; --gray-200: #e2e8f0;
            --gray-300: #cbd5e1; --gray-400: #94a3b8; --gray-500: #64748b;
            --gray-600: #475569; --gray-700: #334155; --gray-800: #1e293b;
            --white: #ffffff;
            --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.07), 0 2px 4px rgba(0,0,0,0.06);
            --radius: 8px; --radius-lg: 12px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gray-100); color: var(--gray-800);
            line-height: 1.6; direction: rtl; min-height: 100vh;
        }

        /* === الرأس === */
        .page-header {
            background: linear-gradient(135deg, var(--dark) 0%, #334155 100%);
            color: var(--white); padding: 2rem 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .header-inner {
            max-width: 1100px; margin: 0 auto; padding: 0 2rem;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
        }
        .header-title { display: flex; align-items: center; gap: 0.75rem; }
        .header-title .logo {
            width: 44px; height: 44px; background: var(--primary);
            border-radius: var(--radius); display: flex; align-items: center;
            justify-content: center; font-size: 1.4rem; flex-shrink: 0;
        }
        .header-title h1 { font-size: 1.5rem; font-weight: 700; }
        .header-title .subtitle { font-size: 0.85rem; opacity: 0.7; margin-top: 2px; }
        .header-meta { text-align: left; font-size: 0.8rem; opacity: 0.7; }

        /* === الحاوية === */
        .container { max-width: 1100px; margin: 0 auto; padding: 2rem; }

        /* === شريط الحالة === */
        .status-bar {
            background: {$statusBg}; border: 1px solid {$statusClr}22;
            border-radius: var(--radius-lg); padding: 1.25rem 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            margin-bottom: 1.5rem; animation: slideDown 0.4s ease;
        }
        .status-bar .status-icon { font-size: 1.6rem; }
        .status-bar .status-text { font-size: 1.1rem; font-weight: 700; color: {$statusClr}; }

        @keyframes slideDown {
            from { opacity:0; transform:translateY(-12px); }
            to { opacity:1; transform:translateY(0); }
        }

        /* === بطاقات الإحصائيات === */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 1rem; margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--white); border-radius: var(--radius-lg);
            padding: 1.25rem; box-shadow: var(--shadow);
            display: flex; align-items: center; gap: 1rem;
            border-right: 4px solid var(--primary);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-card.s-total { border-right-color: var(--info); }
        .stat-card.s-pass  { border-right-color: var(--secondary); }
        .stat-card.s-fail  { border-right-color: var(--danger); }
        .stat-card.s-err   { border-right-color: var(--warning); }
        .stat-icon {
            width: 48px; height: 48px; border-radius: var(--radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .s-total .stat-icon { background: var(--info-light); color: var(--info); }
        .s-pass  .stat-icon { background: var(--secondary-light); color: var(--secondary); }
        .s-fail  .stat-icon { background: var(--danger-light); color: var(--danger); }
        .s-err   .stat-icon { background: var(--warning-light); color: var(--warning); }
        .stat-info h3 { font-size: 0.8rem; color: var(--gray-500); font-weight: 500; margin-bottom: 2px; }
        .stat-value { font-size: 1.4rem; font-weight: 700; color: var(--gray-800); }

        /* === شريط التقدم الكلي === */
        .progress-section { margin-bottom: 2rem; }
        .progress-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 0.5rem;
        }
        .progress-label { font-size: 0.9rem; color: var(--gray-600); font-weight: 600; }
        .progress-pct { font-size: 0.95rem; font-weight: 700; color: {$barColor}; }
        .progress-track {
            height: 14px; background: var(--gray-200); border-radius: 99px;
            overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.08);
        }
        .progress-fill {
            height: 100%; border-radius: 99px; background: {$barColor};
            transition: width 0.8s cubic-bezier(0.4,0,0.2,1);
        }

        /* === قسم المجموعات === */
        .section-title {
            font-size: 1.15rem; font-weight: 700; color: var(--gray-700);
            margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;
        }
        .suites-grid {
            display: grid; grid-template-columns: repeat(2, 1fr);
            gap: 1rem; margin-bottom: 2rem;
        }

        /* === بطاقة المجموعة === */
        .suite-card {
            background: var(--white); border-radius: var(--radius-lg);
            box-shadow: var(--shadow); overflow: hidden;
            animation: fadeUp 0.3s ease both;
        }
        @keyframes fadeUp {
            from { opacity:0; transform:translateY(8px); }
            to { opacity:1; transform:translateY(0); }
        }
        .suite-header {
            padding: 1rem 1.25rem; display: flex;
            justify-content: space-between; align-items: center;
            border-bottom: 1px solid var(--gray-100);
        }
        .suite-name { font-weight: 700; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; }
        .suite-icon { font-size: 1rem; }
        .suite-badge {
            font-size: 0.75rem; font-weight: 600; padding: 0.2rem 0.65rem;
            border-radius: 99px;
        }
        .badge-ok { background: var(--secondary-light); color: var(--secondary-dark); }
        .badge-fail { background: var(--danger-light); color: var(--danger-dark); }

        .suite-bar-wrap {
            padding: 0.75rem 1.25rem; display: flex; align-items: center; gap: 0.75rem;
        }
        .suite-bar-bg { flex:1; height:8px; border-radius:99px; overflow:hidden; }
        .suite-bar { height:100%; border-radius:99px; transition: width 0.6s ease; }
        .suite-pct { font-size: 0.75rem; font-weight: 700; color: var(--gray-500); min-width: 36px; text-align: left; }

        .suite-counts {
            padding: 0 1.25rem 0.75rem; display: flex; gap: 0.75rem; flex-wrap: wrap;
        }
        .cnt { font-size: 0.75rem; font-weight: 500; padding: 0.15rem 0.5rem; border-radius: 4px; }
        .cnt-ok  { background: var(--secondary-light); color: var(--secondary-dark); }
        .cnt-fail { background: var(--danger-light); color: var(--danger-dark); }
        .cnt-err  { background: var(--warning-light); color: var(--warning); }
        .cnt-total { background: var(--gray-100); color: var(--gray-600); }

        .detail-row {
            padding: 0.5rem 1.25rem; font-size: 0.78rem; font-family: 'Consolas', 'Courier New', monospace;
            border-top: 1px solid var(--gray-100); line-height: 1.5;
        }
        .detail-fail { color: var(--danger-dark); background: var(--danger-light); }
        .detail-err  { color: var(--warning); background: var(--warning-light); }

        /* === جدول الأخطاء === */
        .errors-section { margin-bottom: 2rem; }
        .errors-table-wrap {
            background: var(--white); border-radius: var(--radius-lg);
            box-shadow: var(--shadow); overflow: hidden;
        }
        .errors-table {
            width: 100%; border-collapse: collapse; font-size: 0.82rem;
        }
        .errors-table thead { background: var(--gray-100); }
        .errors-table th {
            padding: 0.75rem 1rem; text-align: right; font-weight: 600;
            color: var(--gray-600); border-bottom: 2px solid var(--gray-200);
        }
        .errors-table td {
            padding: 0.6rem 1rem; border-bottom: 1px solid var(--gray-100);
            vertical-align: top;
        }
        .errors-table code {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 0.78rem; color: var(--danger-dark);
            background: var(--danger-light); padding: 0.15rem 0.4rem;
            border-radius: 4px; display: inline-block; max-width: 100%;
            word-break: break-all;
        }
        .errors-table tbody tr:hover { background: var(--gray-50); }
        .no-errors-msg {
            text-align: center; padding: 2rem; color: var(--secondary);
            font-weight: 600; font-size: 0.95rem;
        }

        /* === التذييل === */
        .footer {
            text-align: center; padding: 1.5rem 2rem; color: var(--gray-500);
            font-size: 0.8rem; border-top: 1px solid var(--gray-200);
            margin-top: 1rem;
        }
        .footer a { color: var(--primary); text-decoration: none; }

        /* === زر إعادة التشغيل === */
        .reload-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: var(--primary); color: var(--white);
            border: none; border-radius: var(--radius);
            padding: 0.6rem 1.25rem; font-size: 0.9rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none;
            font-family: inherit;
        }
        .reload-btn:hover { background: var(--dark); transform: translateY(-1px); }
        .reload-btn svg { width: 16px; height: 16px; }

        /* === التجاوب === */
        @media (max-width: 768px) {
            .header-inner { padding: 0 1rem; flex-direction: column; text-align: center; }
            .header-meta { text-align: center; }
            .container { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .stat-card { padding: 1rem; }
            .stat-icon { width: 40px; height: 40px; font-size: 1.1rem; }
            .stat-value { font-size: 1.15rem; }
            .suites-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .page-header { padding: 1.5rem 0; }
        }
    </style>
</head>
<body>

<!-- === الرأس === -->
<div class="page-header">
    <div class="header-inner">
        <div class="header-title">
            <div class="logo">🧪</div>
            <div>
                <h1>اختبارات الانحدار</h1>
                <div class="subtitle">نظام إدارة المخزون is-app</div>
            </div>
        </div>
        <div class="header-meta">
            <div>{$now}</div>
            <div>مدة التنفيذ: {$duration} ms</div>
        </div>
    </div>
</div>

<!-- === المحتوى === -->
<div class="container">

    <!-- شريط الحالة -->
    <div class="status-bar">
        <span class="status-icon">{$statusIcon}</span>
        <span class="status-text">{$statusText}</span>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card s-total">
            <div class="stat-icon">📋</div>
            <div class="stat-info">
                <h3>إجمالي الاختبارات</h3>
                <div class="stat-value">{$total}</div>
            </div>
        </div>
        <div class="stat-card s-pass">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
                <h3>ناجح</h3>
                <div class="stat-value">{$passed}</div>
            </div>
        </div>
        <div class="stat-card s-fail">
            <div class="stat-icon">❌</div>
            <div class="stat-info">
                <h3>فاشل</h3>
                <div class="stat-value">{$failed}</div>
            </div>
        </div>
        <div class="stat-card s-err">
            <div class="stat-icon">⚠️</div>
            <div class="stat-info">
                <h3>أخطاء</h3>
                <div class="stat-value">{$errors}</div>
            </div>
        </div>
    </div>

    <!-- شريط التقدم الكلي -->
    <div class="progress-section">
        <div class="progress-header">
            <span class="progress-label">نسبة النجاح</span>
            <span class="progress-pct">{$passRate}%</span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" style="width: {$passRate}%"></div>
        </div>
    </div>

    <!-- مجموعات الاختبارات -->
    <div class="section-title">📦 تفاصيل مجموعات الاختبار</div>
    <div class="suites-grid">
        {$suiteCards}
    </div>

    <!-- جدول الأخطاء -->
    <div class="errors-section">
        <div class="section-title">🔍 تفاصيل الأخطاء والفشل</div>
        <div class="errors-table-wrap">
            {$errorsSectionContent}
        </div>
    </div>

</div>

<!-- === التذييل === -->
<div class="footer">
    <div>
        <button class="reload-btn" onclick="location.reload()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            إعادة تشغيل الاختبارات
        </button>
    </div>
    <div style="margin-top: 0.75rem;">
        is-app — نظام اختبارات الحماية من الانحدار (Regression Tests)
    </div>
</div>

</body>
</html>
HTML;
}
