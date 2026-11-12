<?php
/**
 * قسم توثيق API: الصندوق (جزء من api-docs.php — مفصول في المرحلة 3 — M3)
 * يُضمَّن داخل نطاق api-docs.php — \$base_url وغيرها متاحة.
 */
?>
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
