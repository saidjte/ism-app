/**
 * ===== رسوم لوحة التحكم البيانية (Dashboard Charts) =====
 * يفصل كود Chart.js عن HTML للعرض
 *
 * تم تفكيكه من index.php (inline <script>) إلى ملف مستقل
 *
 * @since v4.17.0 — تفكيك God File index.php
 *
 * الاعتماديات:
 *   - Chart.js (chart.umd.min.js)
 *   - متغيرات JavaScript عامة: window.CURRENCY
 *   - بيانات من PHP تُمرر عبر window.dashboardChartData
 */

(function () {
    'use strict';

    // === التحقق من توفر البيانات والرسوم البيانية ===
    if (typeof Chart === 'undefined') {
        console.warn('[Dashboard] Chart.js غير محمّل — تم تخطي الرسوم البيانية');
        return;
    }

    const data = window.dashboardChartData;
    if (!data) {
        console.warn('[Dashboard] بيانات الرسم البياني غير متوفرة (window.dashboardChartData)');
        return;
    }

    // === إعدادات عامة ===
    Chart.defaults.font.family = 'Tajawal, Segoe UI, sans-serif';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    const C = window.CURRENCY || '';

    // === رسم بياني: المبيعات والمشتريات والأرباح الشهرية ===
    const monthlyEl = document.getElementById('monthlyOverviewChart');
    if (monthlyEl && data.monthly) {
        new Chart(monthlyEl, {
            type: 'bar',
            data: {
                labels: data.monthly.labels,
                datasets: [
                    {
                        label: 'المبيعات',
                        data: data.monthly.sales,
                        backgroundColor: 'rgba(37,99,235,0.8)',
                        borderRadius: 6,
                        barPercentage: 0.7
                    },
                    {
                        label: 'المشتريات',
                        data: data.monthly.purchases,
                        backgroundColor: 'rgba(220,38,38,0.7)',
                        borderRadius: 6,
                        barPercentage: 0.7
                    },
                    {
                        label: 'الأرباح',
                        data: data.monthly.profit,
                        backgroundColor: 'rgba(5,150,105,0.8)',
                        borderRadius: 6,
                        barPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + parseFloat(ctx.parsed.y).toLocaleString('ar-MA') + ' ' + C;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (v) { return v >= 1000 ? (v / 1000).toFixed(0) + 'K' : v; }
                        }
                    }
                }
            }
        });
    }

    // === رسم بياني: أفضل المنتجات مبيعاً ===
    const topProductsEl = document.getElementById('topProductsDashChart');
    if (topProductsEl && data.topProducts) {
        new Chart(topProductsEl, {
            type: 'doughnut',
            data: {
                labels: data.topProducts.labels,
                datasets: [{
                    data: data.topProducts.values,
                    backgroundColor: data.topProducts.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        rtl: true,
                        labels: { font: { size: 11 }, padding: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                return ctx.label + ': ' + parseFloat(ctx.parsed).toLocaleString('ar-MA') + ' ' + C + ' (' + ((ctx.parsed / total) * 100).toFixed(1) + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
})();
