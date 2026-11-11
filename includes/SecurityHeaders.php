<?php
/**
 * ===== نظام رؤوس الأمان الديناميكي (SecurityHeaders) =====
 * يولّد ويرسل رؤوس أمان HTTP ديناميكياً لكل طلب
 * بما في ذلك CSP مع nonce فريد لكل طلب
 *
 * الميزات:
 *   - CSP ديناميكي مع nonce فريد لكل طلب
 *   - وضعين: تنفيذ (Enforce) أو تقرير فقط (Report-Only)
 *   - HSTS مع includeSubDomains و preload
 *   - رؤوس أمان إضافية (X-Content-Type-Options, X-Frame-Options, etc.)
 *   - وضع تطوير يسمح بالتشخيص بدون تشفير صارم
 *   - توافق كامل مع الإعدادات الموجودة في .htaccess
 *
 * أوضاع CSP:
 *   - CSP_ENFORCE=true  → Content-Security-Policy (يحظر المخالفات)
 *   - CSP_ENFORCE=false → Content-Security-Policy-Report-Only (يراقب فقط)
 *   يُنصح بالبدء بوضع التقرير حتى تُضاف nonce لكل السكربتات المضمّنة
 */

class SecurityHeaders
{
    /** @var string Nonce فريد لكل طلب */
    private static string $nonce = '';

    /** @var bool هل تم إرسال الرؤوس بالفعل */
    private static bool $sent = false;

    /** @var array سياسة CSP المبنية ديناميكياً */
    private static array $cspDirectives = [];

    /** @var bool هل CSP في وضع التنفيذ القسري */
    private static bool $enforceMode = false;

    /**
     * تهيئة نظام رؤوس الأمان
     * يُستدعى في بداية bootstrap.php بعد session_start
     */
    public static function init(): void
    {
        if (self::$sent) return;

        // تحديد وضع CSP من متغيرات البيئة
        // القيمة الافتراضية: Report-Only (آمن للانتقال التدريجي)
        $enforce = getenv('CSP_ENFORCE');
        self::$enforceMode = ($enforce === 'true' || $enforce === '1' || $enforce === 'yes');

        // توليد nonce فريد لكل طلب (16 بايت = 32 حرف سداسي عشري)
        self::$nonce = bin2hex(random_bytes(16));

        // بناء توجيهات CSP الديناميكية
        self::buildCSP();

        // إرسال الرؤوس
        self::sendHeaders();

        self::$sent = true;
    }

    /**
     * الحصول على nonce الحالي
     * يُستخدم في header.php لإضافته إلى سكربتات HTML
     *
     * @return string قيمة nonce بصيغة base64
     */
    public static function getNonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = bin2hex(random_bytes(16));
        }
        return base64_encode(self::$nonce);
    }

    /**
     * الحصول على سمة nonce للحقن في HTML
     * مثال: <script <?php echo SecurityHeaders::nonceAttr(); ?>>
     *
     * @return string سمة nonce جاهزة للحقن في HTML
     */
    public static function nonceAttr(): string
    {
        return 'nonce="' . htmlspecialchars(self::getNonce(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * بناء توجيهات CSP ديناميكياً
     * في وضع Report-Only: نضيف 'unsafe-inline' كإجراء مؤقت
     * في وضع Enforce: نستخدم nonce فقط بدون 'unsafe-inline'
     */
    private static function buildCSP(): void
    {
        $nonce = self::getNonce();
        $isDebug = defined('APP_DEBUG') && APP_DEBUG === true;

        // في وضع التقرير فقط: نسمح بـ unsafe-inline مؤقتاً
        // لضمان عمل التطبيق أثناء الانتقال التدريجي
        if (self::$enforceMode) {
            // وضع التنفيذ: nonce فقط (أقصى أمان)
            $scriptSrc = "'self' 'nonce-{$nonce}'" . ($isDebug ? " 'unsafe-eval'" : "");
            $styleSrc  = "'self' 'nonce-{$nonce}' https://fonts.googleapis.com";
        } else {
            // وضع التقرير: nonce + unsafe-inline كمؤقت
            // ملاحظة: المتصفحات الحديثة (CSP3) تتجاهل 'unsafe-inline'
            // عند وجود nonce، لكن هذا يضمن التوافق مع المتصفحات القديمة
            // ويسمح لـ onclick handlers بالعمل أثناء الانتقال
            $scriptSrc = "'self' 'nonce-{$nonce}' 'unsafe-inline'" . ($isDebug ? " 'unsafe-eval'" : "");
            $styleSrc  = "'self' 'nonce-{$nonce}' 'unsafe-inline' https://fonts.googleapis.com";
        }

        self::$cspDirectives = [
            'default-src'     => "'self'",
            'script-src'      => $scriptSrc,
            'style-src'       => $styleSrc,
            'img-src'         => "'self' data: blob:",
            'font-src'        => "'self' https://fonts.gstatic.com https://fonts.googleapis.com",
            'connect-src'     => "'self' ws: wss:",
            'frame-ancestors' => "'self'",
            'form-action'     => "'self'",
            'base-uri'        => "'self'",
            'object-src'      => "'none'",
            'worker-src'      => "'self' blob:",
        ];
    }

    /**
     * إرسال جميع رؤوس الأمان
     */
    private static function sendHeaders(): void
    {
        // التحقق من أن الرؤوس لم تُرسل بعد
        if (headers_sent($file, $line)) {
            // لا يمكن إرسال الرؤوس إذا تم إرسال المحتوى بالفعل
            return;
        }

        // 1. Content-Security-Policy
        $cspHeader = self::buildCSPString();

        if (self::$enforceMode) {
            // وضع التنفيذ: يحظر المخالفات
            header("Content-Security-Policy: {$cspHeader}");
        } else {
            // وضع التقرير فقط: يراقب المخالفات بدون حظر
            // هذا يسمح للتطبيق بالعمل أثناء الانتقال التدريجي
            header("Content-Security-Policy-Report-Only: {$cspHeader}");
        }

        // 2. Strict-Transport-Security (HSTS)
        // مدة سنة مع includeSubDomains و preload
        // يُفعّل فقط عند استخدام HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // 3. رؤوس أمان إضافية (تُرسل أيضاً من .htaccess كاحتياط)
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');

        // 4. إزالة رؤوس كشف المعلومات
        header_remove('X-Powered-By');
        header_remove('Server');
    }

    /**
     * بناء سلسلة CSP من التوجيهات
     *
     * @return string سلسلة CSP جاهزة للإرسال
     */
    private static function buildCSPString(): string
    {
        $parts = [];
        foreach (self::$cspDirectives as $directive => $value) {
            $parts[] = "{$directive} {$value}";
        }
        return implode('; ', $parts);
    }

    /**
     * إضافة مصدر مسموح به إلى توجيه CSP
     * يُستخدم للسماح بمصادر خارجية محددة ديناميكياً
     *
     * @param string $directive اسم التوجيه (مثال: script-src, style-src)
     * @param string $source المصدر المسموح به (مثال: https://cdn.example.com)
     */
    public static function addCSPSource(string $directive, string $source): void
    {
        if (isset(self::$cspDirectives[$directive])) {
            self::$cspDirectives[$directive] .= " {$source}";
        }
    }

    /**
     * الحصول على توجيهات CSP الحالية (للتصحيح)
     *
     * @return array توجيهات CSP
     */
    public static function getCSPDirectives(): array
    {
        return self::$cspDirectives;
    }

    /**
     * التحقق من أن nonce مُعرّف
     *
     * @return bool هل تم توليد nonce
     */
    public static function hasNonce(): bool
    {
        return self::$nonce !== '';
    }

    /**
     * هل CSP في وضع التنفيذ القسري؟
     *
     * @return bool
     */
    public static function isEnforceMode(): bool
    {
        return self::$enforceMode;
    }

    /**
     * الحصول على وضع CSP الحالي (لعرضه في واجهة الإدارة)
     *
     * @return string وضع CSP: 'enforce' أو 'report-only'
     */
    public static function getMode(): string
    {
        return self::$enforceMode ? 'enforce' : 'report-only';
    }
}
