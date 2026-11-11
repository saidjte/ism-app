<?php
/**
 * ===== نظام ترقيم الصفحات الموحد =====
 * يوفر ترقيم صفحات متسق لكل القوائم
 */

class Pagination
{
    private int $total;
    private int $perPage;
    private int $currentPage;
    private int $totalPages;
    private string $baseUrl;
    private string $pageParam;
    private int $maxVisible;

    public function __construct(int $total, int $perPage = 20, int $currentPage = 1, string $baseUrl = '', string $pageParam = 'page', int $maxVisible = 5)
    {
        $this->total = max(0, $total);
        $this->perPage = max(1, min(100, $perPage));
        $this->currentPage = max(1, $currentPage);
        $this->totalPages = max(1, (int) ceil($this->total / $this->perPage));
        $this->currentPage = min($this->currentPage, $this->totalPages);
        $this->baseUrl = $baseUrl;
        $this->pageParam = $pageParam;
        $this->maxVisible = $maxVisible;
    }

    /**
     * حساب قيمة OFFSET لاستعلام SQL
     */
    public function getOffset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    /**
     * جلب قيمة LIMIT لاستعلام SQL
     */
    public function getLimit(): int
    {
        return $this->perPage;
    }

    /**
     * هل هناك صفحة تالية؟
     */
    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * هل هناك صفحة سابقة؟
     */
    public function hasPrev(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * رقم الصفحة التالية
     */
    public function getNextPage(): ?int
    {
        return $this->hasNext() ? $this->currentPage + 1 : null;
    }

    /**
     * رقم الصفحة السابقة
     */
    public function getPrevPage(): ?int
    {
        return $this->hasPrev() ? $this->currentPage - 1 : null;
    }

    /**
     * رقم الصفحة الحالية
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * عدد الصفحات الكلي
     */
    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /**
     * عدد العناصر الكلي
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * عدد العناصر في كل صفحة
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * أرقام الصفحات المرئية (مع ... للفجوات)
     * مثال: [1, 2, '...', 7, 8, 9, '...', 15]
     */
    public function getVisiblePages(): array
    {
        if ($this->totalPages <= $this->maxVisible + 2) {
            return range(1, $this->totalPages);
        }

        $pages = [];
        $half = (int) floor($this->maxVisible / 2);
        $start = max(2, $this->currentPage - $half);
        $end = min($this->totalPages - 1, $this->currentPage + $half);

        // تعديل النطاق إذا كان قصيراً
        if ($start > 2 && $end < $this->totalPages - 1) {
            if ($this->currentPage - $start < $half) {
                $start = max(2, $start - ($half - ($this->currentPage - $start)));
            }
            if ($end - $this->currentPage < $half) {
                $end = min($this->totalPages - 1, $end + ($half - ($end - $this->currentPage)));
            }
        }

        $pages[] = 1;

        if ($start > 2) {
            $pages[] = '...';
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $this->totalPages - 1) {
            $pages[] = '...';
        }

        $pages[] = $this->totalPages;

        return $pages;
    }

    /**
     * معلومات الترقيم كـ array (للاستجابات JSON)
     */
    public function toArray(): array
    {
        return [
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'total_pages'  => $this->totalPages,
            'has_next'     => $this->hasNext(),
            'has_prev'     => $this->hasPrev(),
            'offset'       => $this->getOffset(),
        ];
    }

    /**
     * توليد HTML لترقيم الصفحات
     */
    public function renderHtml(string $containerId = 'pagination', string $containerClass = 'pagination-container'): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }

        $pages = $this->getVisiblePages();
        $html = '<div id="' . htmlspecialchars($containerId) . '" class="' . htmlspecialchars($containerClass) . '">';

        // زر السابق
        $prevDisabled = $this->hasPrev() ? '' : ' disabled';
        $prevUrl = $this->hasPrev() ? $this->getPageUrl($this->currentPage - 1) : '#';
        $html .= '<a href="' . htmlspecialchars($prevUrl) . '" class="pagination-btn pagination-prev"' . $prevDisabled . ' data-page="' . ($this->currentPage - 1) . '">' . t('previous') . '</a>';

        // أرقام الصفحات
        foreach ($pages as $page) {
            if ($page === '...') {
                $html .= '<span class="pagination-dots">...</span>';
            } else {
                $active = ($page === $this->currentPage) ? ' active' : '';
                $url = $this->getPageUrl($page);
                $html .= '<a href="' . htmlspecialchars($url) . '" class="pagination-btn pagination-number' . $active . '" data-page="' . $page . '">' . $page . '</a>';
            }
        }

        // زر التالي
        $nextDisabled = $this->hasNext() ? '' : ' disabled';
        $nextUrl = $this->hasNext() ? $this->getPageUrl($this->currentPage + 1) : '#';
        $html .= '<a href="' . htmlspecialchars($nextUrl) . '" class="pagination-btn pagination-next"' . $nextDisabled . ' data-page="' . ($this->currentPage + 1) . '">' . t('next') . '</a>';

        $html .= '</div>';
        return $html;
    }

    /**
     * توليد HTML لمعرفة الصفحة الحالية (معلومات)
     */
    public function renderInfo(string $label = ''): string
    {
        if ($label === '') {
            $label = t('results');
        }

        if ($this->total === 0) {
            return '<div class="pagination-info">' . t('pagination_no_items', $label) . '</div>';
        }

        $start = ($this->currentPage - 1) * $this->perPage + 1;
        $end = min($this->currentPage * $this->perPage, $this->total);

        return '<div class="pagination-info">' . t('pagination_showing', $label, $start, $end, $this->total) . '</div>';
    }

    /**
     * توليد رابط مع رقم الصفحة
     */
    private function getPageUrl(int $page): string
    {
        if (empty($this->baseUrl)) {
            $query = $_GET;
            $query[$this->pageParam] = $page;
            return '?' . http_build_query($query);
        }

        $separator = str_contains($this->baseUrl, '?') ? '&' : '?';
        return $this->baseUrl . $separator . $this->pageParam . '=' . $page;
    }

    /**
     * إنشاء Pagination من GET parameters
     * طريقة مريحة للاستخدام المباشر
     */
    public static function fromRequest(int $total, int $defaultPerPage = 20, string $pageParam = 'page'): self
    {
        $page = max(1, intval($_GET[$pageParam] ?? 1));
        $perPage = max(1, min(100, intval($_GET['per_page'] ?? $defaultPerPage)));

        return new self($total, $perPage, $page, '', $pageParam);
    }

    /**
     * CSS لترقيم الصفحات (يمكن إضافته للصفحة)
     */
    public static function getStyles(): string
    {
        return <<<'CSS'
.pagination-container {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 20px 0;
    flex-wrap: wrap;
}
.pagination-info {
    text-align: center;
    color: #64748b;
    font-size: 0.875rem;
    margin-bottom: 8px;
}
.pagination-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    color: #475569;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s ease;
}
.pagination-btn:hover:not(:disabled):not(.active) {
    background: #f1f5f9;
    border-color: #cbd5e1;
}
.pagination-btn.active {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb;
}
.pagination-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pagination-dots {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    color: #94a3b8;
    font-size: 0.875rem;
}
@media (max-width: 480px) {
    .pagination-container { gap: 4px; }
    .pagination-btn { min-width: 32px; height: 32px; font-size: 0.8rem; padding: 0 8px; }
}
CSS;
    }
}
