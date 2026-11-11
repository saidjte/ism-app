/**
 * ===================================
 *  مكون البحث عن المنتجات (Product Search)
 *  مكون مشترك يُستخدم في: المبيعات، المشتريات، قائمة المنتجات
 *  يمنع تكرار كود البحث في كل صفحة
 * ===================================
 */

class ProductSearch {
    /**
     * @param {Object} options خيارات التهيئة
     * @param {string} options.inputSelector محدد حقل البحث
     * @param {string} options.resultsSelector محدد حاوية النتائج
     * @param {Function} options.onSelect دالة عند اختيار منتج
     * @param {string} options.searchUrl رابط البحث
     * @param {string} options.placeholder نص التلميح
     * @param {number} options.minChars الحد الأدنى للأحرف
     */
    constructor(options = {}) {
        this.options = {
            inputSelector: '#product-search',
            resultsSelector: '#search-results',
            onSelect: null,
            searchUrl: '/api/search_products.php',
            placeholder: 'ابحث عن منتج (اسم، باركود، تصنيف)...',
            minChars: 1,
            highlightMatch: true,
            showImage: false,
            ...options,
        };

        this.input = document.querySelector(this.options.inputSelector);
        this.resultsContainer = document.querySelector(this.options.resultsSelector);
        this.selectedIndex = -1;
        this.products = [];
        this.isOpen = false;

        if (this.input && this.resultsContainer) {
            this.init();
        }
    }

    /** @private */
    init() {
        if (this.input && !this.input.placeholder) {
            this.input.placeholder = this.options.placeholder;
        }

        this.input.addEventListener('input', App.debounce((e) => {
            this.search(e.target.value);
        }, 200));

        this.input.addEventListener('keydown', (e) => this.handleKeydown(e));

        document.addEventListener('click', (e) => {
            if (!this.input.contains(e.target) && !this.resultsContainer.contains(e.target)) {
                this.close();
            }
        });

        this.input.addEventListener('focus', () => {
            if (this.input.value.length >= this.options.minChars && this.products.length > 0) {
                this.showResults();
            }
        });
    }

    /**
     * البحث عن المنتجات
     * @param {string} query نص البحث
     */
    async search(query) {
        if (query.length < this.options.minChars) {
            this.close();
            return;
        }

        try {
            const data = await App.get(this.options.searchUrl, { query: query });
            this.products = data.products || data.data || [];
            this.selectedIndex = -1;

            if (this.products.length > 0) {
                this.renderResults();
            } else {
                this.resultsContainer.innerHTML = `
                    <div style="padding: 16px; text-align: center; color: #94a3b8; font-size: 0.9rem;">
                        لم يتم العثور على نتائج
                    </div>
                `;
                this.showResults();
            }
        } catch (error) {
            console.error('ProductSearch error:', error);
        }
    }

    /** @private */
    renderResults() {
        let html = '';
        this.products.forEach((product, index) => {
            const name = this.options.highlightMatch
                ? this.highlightText(product.name, this.input.value)
                : App.escapeHtml(product.name);

            const priceStr = product.price ? App.formatMoney(parseFloat(product.price)) : '';
            const qtyStr = product.quantity !== undefined
                ? `<span style="color: ${product.quantity > 0 ? '#10b981' : '#ef4444'}; font-size: 0.8rem;">المخزون: ${App.escapeHtml(String(product.quantity))}</span>`
                : '';
            const barcodeStr = product.barcode
                ? `<span style="color: #94a3b8; font-size: 0.75rem; font-family: monospace;">${App.escapeHtml(product.barcode)}</span>`
                : '';

            html += `
                <div class="search-result-item" data-index="${index}" style="
                    display: flex; align-items: center; gap: 12px; padding: 10px 14px;
                    cursor: pointer; transition: background 0.1s; border-bottom: 1px solid #f1f5f9;
                " onmouseenter="window._productSearch.highlight(${index})" onmouseleave="window._productSearch.unhighlight()">
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:500; color:#1e293b; font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${name}</div>
                        <div style="display:flex; gap:8px; align-items:center; margin-top:2px;">
                            ${barcodeStr}
                            ${qtyStr}
                        </div>
                    </div>
                    ${priceStr ? `<div style="font-weight:600; color:#2563eb; font-size:0.9rem; white-space:nowrap;">${priceStr}</div>` : ''}
                </div>
            `;
        });

        this.resultsContainer.innerHTML = html;
        this.showResults();

        this.resultsContainer.querySelectorAll('.search-result-item').forEach((item) => {
            item.addEventListener('click', () => {
                const idx = parseInt(item.dataset.index);
                this.selectProduct(idx);
            });
        });
    }

    /**
     * تمييز نص البحث في النتائج
     * @private
     * @param {string} text النص الكامل
     * @param {string} query نص البحث
     * @returns {string}
     */
    highlightText(text, query) {
        if (!query) return App.escapeHtml(text);
        const escaped = App.escapeHtml(text);
        const queryEscaped = App.escapeHtml(query);
        const regex = new RegExp(`(${queryEscaped.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return escaped.replace(regex, '<mark style="background:#fef08a;padding:0 2px;border-radius:2px;">$1</mark>');
    }

    /** @private */
    handleKeydown(e) {
        if (!this.isOpen) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, this.products.length - 1);
                this.updateHighlight();
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateHighlight();
                break;
            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0) this.selectProduct(this.selectedIndex);
                break;
            case 'Escape':
                this.close();
                break;
        }
    }

    /** @param {number} index */
    highlight(index) { this.selectedIndex = index; this.updateHighlight(); }

    unhighlight() { this.selectedIndex = -1; this.updateHighlight(); }

    /** @private */
    updateHighlight() {
        const items = this.resultsContainer.querySelectorAll('.search-result-item');
        items.forEach((item, i) => { item.style.background = i === this.selectedIndex ? '#f0f9ff' : ''; });
        if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
            items[this.selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * اختيار منتج
     * @param {number} index فهرس المنتج
     */
    selectProduct(index) {
        const product = this.products[index];
        if (!product) return;
        this.input.value = product.name;
        this.close();
        if (typeof this.options.onSelect === 'function') this.options.onSelect(product);
    }

    /** @private */
    showResults() { this.resultsContainer.style.display = 'block'; this.isOpen = true; }

    /** إغلاق القائمة */
    close() { this.resultsContainer.style.display = 'none'; this.isOpen = false; this.selectedIndex = -1; }

    /** إعادة ضبط البحث */
    reset() { this.input.value = ''; this.products = []; this.selectedIndex = -1; this.close(); }

    /** تعيين قيمة حقل البحث */
    setValue(name) { this.input.value = name; this.close(); }
}

// تسجيل المثيل العام
window._productSearch = null;
window.ProductSearch = ProductSearch;
