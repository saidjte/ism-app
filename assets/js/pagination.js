/**
 * ===================================
 *  مكون ترقيم الصفحات (AJAX Pagination)
 *  يدعم ترقيم الصفحات بلا إعادة تحميل كاملة
 * ===================================
 */

class AjaxPagination {
    /**
     * @param {Object} options خيارات التهيئة
     * @param {string} options.containerSelector محدد حاوية أزرار الترقيم
     * @param {string} options.contentSelector محدد حاوية المحتوى
     * @param {string} options.infoSelector محدد معلومات الترقيم
     * @param {string} options.url رابط الـ API
     * @param {string} options.method طريقة الطلب
     * @param {number} options.perPage عدد العناصر في كل صفحة
     * @param {Function} options.onPageChange دالة عند تغيير الصفحة
     * @param {Function} options.beforeLoad دالة قبل التحميل
     */
    constructor(options = {}) {
        this.options = {
            containerSelector: '#pagination-container',
            contentSelector: '#paginated-content',
            infoSelector: '#pagination-info',
            url: '',
            method: 'GET',
            pageParam: 'page',
            perPage: 20,
            onPageChange: null,
            beforeLoad: null,
            ...options,
        };

        this.container = document.querySelector(this.options.containerSelector);
        this.contentContainer = document.querySelector(this.options.contentSelector);
        this.infoContainer = document.querySelector(this.options.infoSelector);
        this.currentPage = 1;
        this.totalPages = 1;
        this.total = 0;
        this.loading = false;

        if (this.container) {
            this.container.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-page]');
                if (btn && !btn.disabled) {
                    e.preventDefault();
                    this.goToPage(parseInt(btn.dataset.page));
                }
            });
        }
    }

    /**
     * الانتقال لصفحة محددة
     * @param {number} page رقم الصفحة
     */
    async goToPage(page) {
        if (this.loading || page < 1 || page > this.totalPages) return;
        this.loading = true;

        try {
            if (typeof this.options.beforeLoad === 'function') this.options.beforeLoad();

            const url = new URL(this.options.url, window.location.origin);
            url.searchParams.set(this.options.pageParam, page);
            url.searchParams.set('per_page', this.options.perPage);

            const data = await App.get(url.pathname + url.search);
            this.currentPage = page;

            if (data.pagination) {
                this.totalPages = data.pagination.total_pages;
                this.total = data.pagination.total;
            }

            if (this.contentContainer && data.data !== undefined) {
                if (typeof this.options.onPageChange === 'function') {
                    this.options.onPageChange(page, data);
                }
            }

            this.render();
            this.updateUrl(page);
        } catch (error) {
            console.error('Pagination error:', error);
            App.toast('خطأ في تحميل البيانات', 'error');
        } finally {
            this.loading = false;
        }
    }

    /** عرض أزرار ترقيم الصفحات */
    render() {
        if (!this.container || this.totalPages <= 1) {
            if (this.container) this.container.innerHTML = '';
            return;
        }

        const pages = this.getVisiblePages();
        let html = '';

        html += `<button data-page="${this.currentPage - 1}" ${this.currentPage <= 1 ? 'disabled' : ''} class="pagination-btn pagination-prev">السابق</button>`;

        pages.forEach((page) => {
            if (page === '...') {
                html += '<span class="pagination-dots">...</span>';
            } else {
                const active = page === this.currentPage ? ' active' : '';
                html += `<button data-page="${page}" class="pagination-btn pagination-number${active}">${page}</button>`;
            }
        });

        html += `<button data-page="${this.currentPage + 1}" ${this.currentPage >= this.totalPages ? 'disabled' : ''} class="pagination-btn pagination-next">التالي</button>`;

        this.container.innerHTML = html;

        if (this.infoContainer) {
            if (this.total === 0) {
                this.infoContainer.textContent = 'لا توجد نتائج';
            } else {
                const start = (this.currentPage - 1) * this.options.perPage + 1;
                const end = Math.min(this.currentPage * this.options.perPage, this.total);
                this.infoContainer.textContent = `النتائج ${start} - ${end} من ${this.total}`;
            }
        }
    }

    /** @private أرقام الصفحات المرئية */
    getVisiblePages() {
        const maxVisible = 5;
        if (this.totalPages <= maxVisible + 2) {
            return Array.from({ length: this.totalPages }, (_, i) => i + 1);
        }

        const pages = [];
        const half = Math.floor(maxVisible / 2);
        const start = Math.max(2, this.currentPage - half);
        const end = Math.min(this.totalPages - 1, this.currentPage + half);

        pages.push(1);
        if (start > 2) pages.push('...');
        for (let i = start; i <= end; i++) pages.push(i);
        if (end < this.totalPages - 1) pages.push('...');
        pages.push(this.totalPages);
        return pages;
    }

    /** @private تحديث URL */
    updateUrl(page) {
        const url = new URL(window.location);
        if (page > 1) url.searchParams.set(this.options.pageParam, page);
        else url.searchParams.delete(this.options.pageParam);
        window.history.replaceState({}, '', url);
    }

    /**
     * تهيئة من بيانات أولية
     * @param {Object} data بيانات الصفحة
     */
    initFromData(data) {
        if (data.pagination) {
            this.totalPages = data.pagination.total_pages;
            this.total = data.pagination.total;
            this.currentPage = data.pagination.current_page;
            this.render();
        }
    }
}

window.AjaxPagination = AjaxPagination;
