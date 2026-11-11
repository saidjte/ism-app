<?php
/**
 * ===== محرك القوالب البسيط =====
 * يفصل منطق PHP عن HTML بدون الاعتماد على مكتبات خارجية
 * 
 * الاستخدام:
 *   $template = new Template();
 *   $template->render('products/list', ['products' => $products, 'title' => 'المنتجات']);
 *   // يبحث عن الملف: templates/products/list.php
 */

class Template
{
    /** @var string مسار مجلد القوالب */
    private string $viewsDir;
    /** @var string مسار مجلد التخطيطات (layouts) */
    private string $layoutsDir;
    /** @var array المتغيرات العامة */
    private array $globalData = [];

    public function __construct(?string $viewsDir = null)
    {
        $baseDir = dirname(__DIR__);
        $this->viewsDir = $viewsDir ?? $baseDir . '/templates';
        $this->layoutsDir = $this->viewsDir . '/layouts';
    }

    /**
     * تعيين متغير عام متاح لكل القوالب
     */
    public function set(string $key, mixed $value): self
    {
        $this->globalData[$key] = $value;
        return $this;
    }

    /**
     * تعيين عدة متغيرات دفعة واحدة
     */
    public function setAll(array $data): self
    {
        $this->globalData = array_merge($this->globalData, $data);
        return $this;
    }

    /**
     * عرض قالب مع بيانات محددة
     * @param string $view اسم القالب (مثال: 'products/list')
     * @param array $data البيانات المتاحة في القالب
     */
    public function render(string $view, array $data = []): void
    {
        // دمج البيانات العامة مع المحلية
        $allData = array_merge($this->globalData, $data);

        // البحث عن ملف القالب
        $viewFile = $this->findView($view);
        if (!$viewFile) {
            throw new AppException("القالب '{$view}' غير موجود", 'not_found');
        }

        // استخراج المتغيرات للنطاق المحلي
        extract($allData, EXTR_SKIP);

        // بدء تجميع المخرجات
        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        echo $content;
    }

    /**
     * عرض قالب داخل تخطيط (layout)
     * @param string $layout اسم التخطيط (مثال: 'main')
     * @param string $view اسم القالب
     * @param array $data البيانات
     */
    public function renderWithLayout(string $layout, string $view, array $data = []): void
    {
        $allData = array_merge($this->globalData, $data);

        // عرض القالب أولاً في متغير
        $viewContent = $this->capture($view, $allData);
        $allData['content'] = $viewContent;

        // عرض التخطيط
        $layoutFile = $this->findView('layouts/' . $layout);
        if (!$layoutFile) {
            throw new AppException("التخطيط '{$layout}' غير موجود", 'not_found');
        }

        extract($allData, EXTR_SKIP);
        ob_start();
        include $layoutFile;
        echo ob_get_clean();
    }

    /**
     * تجميع مخرجات القالب في متغير (بدون echo)
     */
    public function capture(string $view, array $data = []): string
    {
        ob_start();
        $this->render($view, $data);
        return ob_get_clean();
    }

    /**
     * التحقق من وجود قالب
     */
    public function exists(string $view): bool
    {
        return $this->findView($view) !== null;
    }

    /**
     * البحث عن ملف القالب في المسارات المحتملة
     */
    private function findView(string $view): ?string
    {
        $file = str_replace('.', '/', $view);
        $extensions = ['.php', '.phtml', '.html'];

        foreach ($extensions as $ext) {
            $path = $this->viewsDir . '/' . $file . $ext;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    // === دوال مساعدة داخل القوالب ===

    /**
     * تنسيق HTML آمن
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * تنسيق تاريخ
     */
    public static function date(string $date, string $format = 'Y-m-d'): string
    {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '-';
        }
        return date($format, strtotime($date));
    }

    /**
     * تنسيق مبلغ مالي
     */
    public static function money(float $amount, string $currency = 'د.م.'): string
    {
        return number_format($amount, 2) . ' ' . $currency;
    }

    /**
     * عرض شارة الحالة
     */
    public static function badge(string $text, string $type = 'default'): string
    {
        $colors = [
            'success' => 'badge-success',
            'danger'  => 'badge-danger',
            'warning' => 'badge-warning',
            'info'    => 'badge-info',
            'default' => 'badge-info',
        ];
        $class = $colors[$type] ?? $colors['default'];
        return '<span class="' . $class . '">' . htmlspecialchars($text) . '</span>';
    }

    /**
     * عرض CSRF token في نموذج
     */
    public static function csrfField(): string
    {
        if (function_exists('generateCSRFToken')) {
            return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
        }
        return '';
    }

    /**
     * تحميل جزء (partial) من قالب
     */
    public static function partial(string $view, array $data = []): void
    {
        $template = new self();
        $template->render($view, $data);
    }
}
