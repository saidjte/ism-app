<?php
/**
 * ===== خدمة المبيعات (SaleService) =====
 * تجمع كل المنطق التجاري المتعلق بالمبيعات
 * يشمل: تسجيل البيع، حساب الأرباح، FIFO/FEFO للدفعات
 *
 * المسؤوليات:
 * - تسجيل عمليات البيع مع خصم من الدفعات (FIFO/FEFO)
 * - حذف عمليات البيع مع إعادة الكميات والدفعات
 * - حساب الأرباح لفترة معينة
 * - التحقق من صلاحية المنتجات المنتهية
 *
 * v5.13.0: إعادة تفعيل FEFO + استعادة الدفعات عند الحذف
 * v4.16.9: ترمي AppException بدلاً من إرجاع مصفوفات خطأ + أنواع إرجاع
 */

class SaleService
{
    /**
     * تسجيل الخطأ — يستخدم ErrorHandler إن كان متوفراً، وإلا error_log
     *
     * @param \Throwable $e الاستثناء
     * @param string $context سياق إضافي
     */
    private static function logError(\Throwable $e, string $context = ''): void
    {
        if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
            ErrorHandler::logException($e, 'SaleService::' . $context);
        } else {
            $file = basename($e->getFile());
            error_log("[ISM] SaleService::{$context}: {$e->getMessage()} في {$file}:{$e->getLine()}");
        }
    }

    /**
     * تسجيل عملية بيع جديدة
     *
     * @param array $data بيانات البيع
     * @return array بيانات البيع المسجلة
     * @throws AppException عند فشل التحقق أو خطأ في قاعدة البيانات
     */
    public static function create(array $data): array
    {
        // التحقق من صحة البيانات
        $validation = self::validateSaleData($data);
        if ($validation !== true) {
            throw AppException::validation(implode('، ', $validation));
        }

        $items = $data['items'];
        $sale_date = $data['sale_date'] ?? date('Y-m-d');
        $notes = trim($data['notes'] ?? '');

        $valid_rows = [];
        $seen_product_ids = [];

        foreach ($items as $index => $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = floatval($item['quantity'] ?? 0);
            $price = floatval($item['selling_price'] ?? 0);

            if ($pid <= 0) continue;
            if ($qty <= 0) {
                throw AppException::validation('الكمية يجب أن تكون أكبر من صفر (المنتج رقم ' . ($index + 1) . ')');
            }
            if ($price <= 0) {
                throw AppException::validation('سعر البيع يجب أن يكون أكبر من صفر (المنتج رقم ' . ($index + 1) . ')');
            }

            // التحقق من تكرار المنتج
            if (in_array($pid, $seen_product_ids)) {
                throw AppException::validation('المنتج مكرر في القائمة (المنتج رقم ' . ($index + 1) . ')');
            }
            $seen_product_ids[] = $pid;

            // التحقق من وجود المنتج وكميته
            $product = fetchOne("SELECT quantity, purchase_price, name, unit, has_expiry FROM products WHERE id = ?", [$pid]);
            if (!$product) {
                throw AppException::notFound('المنتج غير موجود (المنتج رقم ' . ($index + 1) . ')');
            }
            if ($qty > floatval($product['quantity'])) {
                throw AppException::validation(
                    'الكمية المطلوبة (' . number_format($qty, 2) . ') أكبر من المتوفر في المخزون (' . number_format(floatval($product['quantity']), 2) . ') للمنتج "' . $product['name'] . '"'
                );
            }

            $valid_rows[] = [
                'index' => $index,
                'product_id' => $pid,
                'quantity' => $qty,
                'selling_price' => $price,
                'product' => $product,
                'has_expiry' => intval($product['has_expiry'] ?? 0),
            ];
        }

        if (empty($valid_rows)) {
            throw AppException::validation('يرجى إضافة منتج واحد على الأقل');
        }

        // معالجة البيانات في معاملة واحدة
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $grand_total = 0;
            $grand_cost = 0;
            $grand_profit = 0;
            $sale_count = count($valid_rows);
            $sale_details = [];
            $all_sale_ids = [];

            $saleStmt = $pdo->prepare(
                "INSERT INTO sales (product_id, quantity, selling_price, purchase_price_at_sale, total, cost_total, profit, sale_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");

            foreach ($valid_rows as $row) {
                $pid = $row['product_id'];
                $qty = $row['quantity'];
                $price = $row['selling_price'];
                $product = $row['product'];
                $has_expiry = intval($product['has_expiry'] ?? 0);

                // تحديد طريقة الخصم: FEFO للمنتجات ذات الانتهاء، FIFO للبقية
                $method = $has_expiry ? 'fefo' : 'fifo';
                $batch_allocations = self::deductFromBatches($pid, $qty, $method);

                if (!empty($batch_allocations)) {
                    // Calculate cost from batch allocations
                    $cost_total = 0;
                    $total_qty_from_batches = 0;
                    foreach ($batch_allocations as $alloc) {
                        $cost_total += $alloc['quantity'] * $alloc['cost_price'];
                        $total_qty_from_batches += $alloc['quantity'];
                    }
                    $weighted_purchase_price = $total_qty_from_batches > 0 ? $cost_total / $total_qty_from_batches : floatval($product['purchase_price']);
                } else {
                    // Fallback to flat purchase price
                    $cost_total = $qty * floatval($product['purchase_price']);
                    $weighted_purchase_price = floatval($product['purchase_price']);
                }

                $total = $qty * $price;
                $profit = $total - $cost_total;

                // إدراج سجل البيع
                $saleStmt->execute([
                    $pid, $qty, $price, $weighted_purchase_price,
                    $total, $cost_total, $profit, $sale_date, $notes
                ]);
                $sale_id = $pdo->lastInsertId();
                $all_sale_ids[] = $sale_id;

                // خصم الكمية من المخزون
                $updateStmt->execute([$qty, $pid]);

                // If batches were used, update batch remaining quantities and create sale_batch_items
                if (!empty($batch_allocations)) {
                    $batchUpdateStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity - ? WHERE id = ?");
                    $batchItemStmt = $pdo->prepare("INSERT INTO sale_batch_items (sale_id, batch_id, quantity_taken, purchase_price, cost_total) VALUES (?, ?, ?, ?, ?)");
                    foreach ($batch_allocations as $alloc) {
                        $batchUpdateStmt->execute([$alloc['quantity'], $alloc['batch_id']]);
                        $batchItemStmt->execute([$sale_id, $alloc['batch_id'], $alloc['quantity'], $alloc['cost_price'], $alloc['quantity'] * $alloc['cost_price']]);
                    }
                }

                $grand_total += $total;
                $grand_cost += $cost_total;
                $grand_profit += $profit;

                $sale_details[] = $qty . ' ' . $product['unit'] . ' من ' . $product['name'];
            }

            $pdo->commit();

            // تسجيل النشاط
            try {
                addAuditLog('sale_add', "تسجيل {$sale_count} عمليات بيع - الإجمالي: " . number_format($grand_total, 2), '', 'sale', intval($all_sale_ids[0] ?? 0));
            } catch (\Exception $e) {
                self::logError($e, 'create(auditLog)');
            }

            return [
                'success' => true,
                'sale_id' => intval($all_sale_ids[0] ?? 0),
                'sale_ids' => $all_sale_ids,
                'message' => 'تم تسجيل ' . $sale_count . ' عمليات بيع بنجاح - الإجمالي: ' . formatMoney($grand_total) . ' - الربح: ' . formatMoney($grand_profit),
                'summary' => [
                    'total' => $grand_total,
                    'cost' => $grand_cost,
                    'profit' => $grand_profit,
                    'count' => $sale_count
                ]
            ];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ae; // إعادة رمي AppException كما هي
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::logError($e, 'create');
            throw AppException::database('حدث خطأ أثناء تسجيل البيع. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * حذف عملية بيع مع إعادة الكميات والدفعات
     *
     * @param int $id معرف عملية البيع
     * @return array نتيجة الحذف
     * @throws AppException عند عدم وجود البيع أو خطأ في قاعدة البيانات
     */
    public static function delete(int $id): array
    {
        // التحقق من وجود البيع
        $sale = fetchOne("SELECT * FROM sales WHERE id = ?", [$id]);
        if (!$sale) {
            throw AppException::notFound('عملية البيع غير موجودة');
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // إعادة كمية المنتج
            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([
                floatval($sale['quantity']),
                intval($sale['product_id'])
            ]);

            // Restore batch quantities from sale_batch_items
            $batchItems = $pdo->prepare("SELECT batch_id, quantity_taken FROM sale_batch_items WHERE sale_id = ?");
            $batchItems->execute([$id]);
            $batchRestoreStmt = $pdo->prepare("UPDATE purchase_batches SET remaining_quantity = remaining_quantity + ? WHERE id = ?");
            while ($bi = $batchItems->fetch(PDO::FETCH_ASSOC)) {
                $batchRestoreStmt->execute([floatval($bi['quantity_taken']), intval($bi['batch_id'])]);
            }
            // Delete sale_batch_items
            $pdo->prepare("DELETE FROM sale_batch_items WHERE sale_id = ?")->execute([$id]);

            // حذف سجل البيع
            $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$id]);

            $pdo->commit();

            // تسجيل النشاط
            try {
                addAuditLog('sale_delete', "حذف عملية بيع #{$id}", '', 'sale', $id);
            } catch (\Exception $e) {
                self::logError($e, 'delete(auditLog)');
            }

            return ['success' => true, 'message' => 'تم حذف عملية البيع بنجاح وتم استرجاع المخزون والدفعات'];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::logError($e, 'delete');
            throw AppException::database('حدث خطأ أثناء حذف البيع. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * حساب الأرباح لفترة معينة (مُحسّن — استعلام مدمج + فهارس + LIMIT)
     *
     * التحسينات (v4.25.0):
     *   - دمج 3 استعلامات SUM المنفصلة على جدول sales في استعلام واحد
     *   - دمج استعلامي الضرائب والمصاريف في استعلام واحد عبر UNION ALL
     *   - إضافة LIMIT لاستعلام أرباح المنتجات لمنع البطء مع البيانات الكبيرة
     *   - دعم الاستعلام عبر VIEW (mv_daily_profits) — بيانات دقيقة تلقائياً (v5.23.0)
     *   - استخدام الفهارس المركبة المضافة في ترقية v4.25.0
     *
     * @param string $startDate تاريخ البداية (Y-m-d)
     * @param string $endDate تاريخ النهاية (Y-m-d)
     * @param int $productLimit الحد الأقصى لعدد المنتجات في التفصيل (افتراضي: 100)
     * @param bool $useMaterializedView استخدام العرض المُجسَّد إن توفر (افتراضي: true)
     * @return array ملخص الأرباح
     */
    public static function calculateProfits(string $startDate, string $endDate, int $productLimit = 100, bool $useMaterializedView = true): array
    {
        // التحقق من صحة التواريخ
        if (empty($startDate) || empty($endDate)) {
            return [
                'total_sales' => 0,
                'total_cost' => 0,
                'gross_profit' => 0,
                'total_expenses' => 0,
                'total_taxes' => 0,
                'net_profit' => 0,
                'message' => 'التواريخ مطلوبة'
            ];
        }

        // الحد الأدنى لـ LIMIT هو 10 والحد الأقصى 5000
        // 0 = عرض الكل (يُحوَّل إلى رقم كبير جداً)
        if ($productLimit === 0) {
            $productLimit = 999999;
        } else {
            $productLimit = max(10, min(5000, $productLimit));
        }

        // === محاولة استخدام Materialized View أولاً ===
        if ($useMaterializedView) {
            $mvResult = self::calculateProfitsFromMV($startDate, $endDate, $productLimit);
            if ($mvResult !== null) {
                return $mvResult;
            }
        }

        // === الاستعلام المدمج 1: مبيعات + تكلفة + ربح في استعلام واحد ===
        // بدلاً من 3 استعلامات منفصلة، ندمجها في استعلام واحد يقرأ جدول sales مرة واحدة فقط
        $salesAggregates = [
            'total_sales' => 0,
            'total_cost' => 0,
            'gross_profit' => 0,
        ];
        try {
            $salesRow = fetchOne(
                "SELECT COALESCE(SUM(total), 0) as total_sales,
                        COALESCE(SUM(cost_total), 0) as total_cost,
                        COALESCE(SUM(profit), 0) as gross_profit
                 FROM sales
                 WHERE sale_date BETWEEN ? AND ?",
                [$startDate, $endDate]
            );
            if ($salesRow) {
                $salesAggregates['total_sales'] = floatval($salesRow['total_sales']);
                $salesAggregates['total_cost'] = floatval($salesRow['total_cost']);
                $salesAggregates['gross_profit'] = floatval($salesRow['gross_profit']);
            }
        } catch (\Exception $e) {
            self::logError($e, 'calculateProfits(salesAggregates)');
        }

        $total_sales = $salesAggregates['total_sales'];
        $total_cost = $salesAggregates['total_cost'];
        $gross_profit = $salesAggregates['gross_profit'];

        // === الاستعلام المدمج 2: ضرائب + مصاريف في استعلام واحد عبر UNION ALL ===
        $total_taxes = 0;
        $total_expenses = 0;
        try {
            $summaryRows = fetchAll(
                "SELECT 'taxes' as type, COALESCE(SUM(tax_amount), 0) as total
                 FROM purchases WHERE purchase_date BETWEEN ? AND ?
                 UNION ALL
                 SELECT 'expenses' as type, COALESCE(SUM(amount), 0) as total
                 FROM expenses WHERE expense_date BETWEEN ? AND ?",
                [$startDate, $endDate, $startDate, $endDate]
            );
            foreach ($summaryRows as $row) {
                if ($row['type'] === 'taxes') {
                    $total_taxes = floatval($row['total']);
                } elseif ($row['type'] === 'expenses') {
                    $total_expenses = floatval($row['total']);
                }
            }
        } catch (\Exception $e) {
            self::logError($e, 'calculateProfits(taxesExpenses)');
            // تراجع: استعلامات منفصلة إذا فشل UNION ALL
            try {
                $total_taxes = floatval(fetchOne(
                    "SELECT COALESCE(SUM(tax_amount), 0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?",
                    [$startDate, $endDate]
                )['total'] ?? 0);
            } catch (\Exception $e2) {
                self::logError($e2, 'calculateProfits(taxesFallback)');
            }
            try {
                $total_expenses = floatval(fetchOne(
                    "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?",
                    [$startDate, $endDate]
                )['total'] ?? 0);
            } catch (\Exception $e2) {
                self::logError($e2, 'calculateProfits(expensesFallback)');
            }
        }

        // صافي الربح = الربح الإجمالي - المصاريف - الضرائب
        // (مُوحَّد مع profit-report.php لحساب صافي الربح الحقيقي)
        $net_profit = $gross_profit - $total_expenses - $total_taxes;

        // هوامش الربح
        $gross_margin = $total_sales > 0 ? ($gross_profit / $total_sales) * 100 : 0;
        $net_margin = $total_sales > 0 ? ($net_profit / $total_sales) * 100 : 0;

        // تفصيل الأرباح حسب المنتج مع LIMIT
        $product_profits = [];
        try {
            $product_profits = fetchAll(
                "SELECT p.id, p.code, p.name, p.unit, p.quantity as current_stock,
                        p.purchase_price as current_purchase_price, p.selling_price as current_selling_price,
                        COALESCE(SUM(s.quantity), 0) as total_sold,
                        COALESCE(SUM(s.total), 0) as total_sales_amount,
                        COALESCE(SUM(s.cost_total), 0) as total_cost_amount,
                        COALESCE(SUM(s.profit), 0) as total_profit
                 FROM products p
                 LEFT JOIN sales s ON p.id = s.product_id AND s.sale_date BETWEEN ? AND ?
                 GROUP BY p.id
                 HAVING total_sold > 0
                 ORDER BY total_profit DESC
                 LIMIT ?",
                [$startDate, $endDate, $productLimit]
            );
        } catch (\Exception $e) {
            self::logError($e, 'calculateProfits(productProfits)');
        }

        // قيمة المخزون الحالي
        $stock_value = 0;
        $stock_value_retail = 0;
        try {
            $stockRow = fetchOne(
                "SELECT
                    COALESCE(SUM(quantity * purchase_price), 0) as cost_total,
                    COALESCE(SUM(quantity * selling_price), 0) as retail_total
                 FROM products"
            );
            $stock_value = floatval($stockRow['cost_total'] ?? 0);
            $stock_value_retail = floatval($stockRow['retail_total'] ?? 0);
        } catch (\Exception $e) {
            self::logError($e, 'calculateProfits(stockValue)');
            $stock_value = 0;
            $stock_value_retail = 0;
        }

        return [
            'total_sales' => $total_sales,
            'total_cost' => $total_cost,
            'gross_profit' => $gross_profit,
            'total_expenses' => $total_expenses,
            'total_taxes' => $total_taxes,
            'net_profit' => $net_profit,
            'gross_margin' => round($gross_margin, 1),
            'net_margin' => round($net_margin, 1),
            'stock_value' => $stock_value,
            'stock_value_retail' => $stock_value_retail,
            'product_profits' => $product_profits,
            'product_limit' => $productLimit,
        ];
    }

    /**
     * حساب الأرباح من VIEW الأرباح اليومية (mv_daily_profits)
     * v5.23.0: أصبح VIEW حقيقي — البيانات دقيقة تلقائياً دائماً
     * يُرجع null إذا كان العرض غير متوفر
     *
     * @param string $startDate تاريخ البداية
     * @param string $endDate تاريخ النهاية
     * @param int $productLimit حد المنتجات
     * @return array|null النتائج أو null
     */
    private static function calculateProfitsFromMV(string $startDate, string $endDate, int $productLimit): ?array
    {
        try {
            $pdo = AppDatabase::getInstance()->getConnection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            // SQLite لا يدعم هذا العرض — نستخدمه فقط مع MySQL
            if ($driver === 'sqlite') {
                return null;
            }

            // التحقق من وجود mv_daily_profits (VIEW أو جدول)
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'mv_daily_profits'");
            if (!$tableCheck || $tableCheck->rowCount() === 0) {
                return null;
            }

            // v5.23.0: mv_daily_profits أصبح VIEW حقيقي — البيانات دائماً محدّثة تلقائياً
            // لا حاجة للتحقق من صلاحية البيانات أو التحديث اليدوي

            // تجميع من العرض المُجسَّد
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(total_sales), 0) as total_sales,
                        COALESCE(SUM(total_cost), 0) as total_cost,
                        COALESCE(SUM(gross_profit), 0) as gross_profit,
                        COALESCE(SUM(total_expenses), 0) as total_expenses,
                        COALESCE(SUM(total_taxes), 0) as total_taxes,
                        COALESCE(SUM(net_profit), 0) as net_profit,
                        COALESCE(SUM(sales_count), 0) as sales_count
                 FROM mv_daily_profits
                 WHERE profit_date BETWEEN ? AND ?"
            );
            $stmt->execute([$startDate, $endDate]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            $total_sales = floatval($row['total_sales']);
            $total_cost = floatval($row['total_cost']);
            $gross_profit = floatval($row['gross_profit']);
            $total_expenses = floatval($row['total_expenses']);
            $total_taxes = floatval($row['total_taxes']);
            $net_profit = floatval($row['net_profit']);

            // === تحقق إضافي: إذا كان الملخص أصفاراً رغم وجود مبيعات ===
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE sale_date BETWEEN ? AND ?");
            $checkStmt->execute([$startDate, $endDate]);
            $checkSales = intval($checkStmt->fetchColumn());
            if ($total_sales == 0 && $checkSales > 0) {
                return null;
            }

            $gross_margin = $total_sales > 0 ? ($gross_profit / $total_sales) * 100 : 0;
            $net_margin = $total_sales > 0 ? ($net_profit / $total_sales) * 100 : 0;

            // تفصيل المنتجات مع LIMIT
            $product_profits = fetchAll(
                "SELECT p.id, p.code, p.name, p.unit, p.quantity as current_stock,
                        p.purchase_price as current_purchase_price, p.selling_price as current_selling_price,
                        COALESCE(SUM(s.quantity), 0) as total_sold,
                        COALESCE(SUM(s.total), 0) as total_sales_amount,
                        COALESCE(SUM(s.cost_total), 0) as total_cost_amount,
                        COALESCE(SUM(s.profit), 0) as total_profit
                 FROM products p
                 LEFT JOIN sales s ON p.id = s.product_id AND s.sale_date BETWEEN ? AND ?
                 GROUP BY p.id
                 HAVING total_sold > 0
                 ORDER BY total_profit DESC
                 LIMIT ?",
                [$startDate, $endDate, $productLimit]
            );

            // === تحقق تطابق: مقارنة إجمالي الملخص مع مجموع المنتجات ===
            $pp_total_sales = array_sum(array_column($product_profits, 'total_sales_amount'));
            if ($total_sales > 0 && $pp_total_sales > 0 && abs($total_sales - $pp_total_sales) > 0.01) {
                // عدم تطابق كبير — نعود للاستعلام المباشر
                return null;
            }

            // قيمة المخزون
            $stock_value = 0;
            $stock_value_retail = 0;
            try {
                $stockRow = fetchOne(
                    "SELECT
                        COALESCE(SUM(quantity * purchase_price), 0) as cost_total,
                        COALESCE(SUM(quantity * selling_price), 0) as retail_total
                     FROM products"
                );
                $stock_value = floatval($stockRow['cost_total'] ?? 0);
                $stock_value_retail = floatval($stockRow['retail_total'] ?? 0);
            } catch (\Exception $e) {
                self::logError($e, 'calculateProfitsFromMV(stockValue)');
            }

            return [
                'total_sales' => $total_sales,
                'total_cost' => $total_cost,
                'gross_profit' => $gross_profit,
                'total_expenses' => $total_expenses,
                'total_taxes' => $total_taxes,
                'net_profit' => $net_profit,
                'gross_margin' => round($gross_margin, 1),
                'net_margin' => round($net_margin, 1),
                'stock_value' => $stock_value,
                'stock_value_retail' => $stock_value_retail,
                'product_profits' => $product_profits,
                'product_limit' => $productLimit,
                'from_materialized_view' => true,
            ];
        } catch (\Exception $e) {
            self::logError($e, 'calculateProfitsFromMV');
            return null;
        }
    }

    /**
     * تحديث العرض المجسد للأرباح اليومية (مهمل منذ v5.23.0)
     * v5.23.0: mv_daily_profits أصبح VIEW حقيقي — لا حاجة للتحديث
     * الدالة تُبقى فارغة للتوافق مع الأكواد القديمة
     *
     * @param PDO $pdo اتصال قاعدة البيانات
     * @param string|null $date تاريخ محدد (null = تحديث كل الأيام)
     * @return int عدد الصفوف المُحدَّثة
     */
    public static function refreshMaterializedView(PDO $pdo, ?string $date = null): int
    {
        // v5.23.0: mv_daily_profits أصبح VIEW حقيقي — البيانات دائماً محدّثة تلقائياً
        // لم يعد هناك حاجة لتحديث يدوي. الدالة تُبقى فارغة للتوافق مع الأكواد القديمة.
        return 0;
    }

    /**
     * خصم الكمية من دفعات الشراء (FIFO/FEFO)
     * لا يُنفذ التعديلات بل يُرجع الخطة فقط (لتستخدم في المعاملة)
     *
     * @param int $productId معرف المنتج
     * @param float $quantity الكمية المطلوبة
     * @param string $method طريقة الخصم: 'fifo' أو 'fefo'
     * @return array قائمة الدفعات المخصومة
     */
    public static function deductFromBatches(int $productId, float $quantity, string $method = 'fifo'): array
    {
        if ($quantity <= 0 || $productId <= 0) {
            return [];
        }

        // ترتيب الدفعات حسب الطريقة المختارة
        if ($method === 'fefo') {
            $orderClause = "CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE '9999-12-31' END ASC, purchase_date ASC, id ASC";
        } else {
            $orderClause = "purchase_date ASC, CASE WHEN expiry_date IS NOT NULL THEN expiry_date ELSE '9999-12-31' END ASC, id ASC";
        }

        $batches = fetchAll(
            "SELECT id, remaining_quantity, purchase_price, expiry_date
             FROM purchase_batches
             WHERE product_id = ? AND remaining_quantity > 0
             ORDER BY {$orderClause}",
            [$productId]
        );

        if (empty($batches)) {
            return [];
        }

        $result = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $available = floatval($batch['remaining_quantity']);
            $take = min($remaining, $available);

            $result[] = [
                'batch_id' => intval($batch['id']),
                'quantity' => $take,
                'cost_price' => floatval($batch['purchase_price'])
            ];

            $remaining -= $take;
        }

        // التحقق من كفاية الدفعات
        if ($remaining > 0.001) {
            return [];
        }

        return $result;
    }

    /**
     * التحقق من صحة بيانات البيع
     *
     * @param array $data بيانات البيع
     * @return array|true مصفوفة الأخطاء أو true
     */
    private static function validateSaleData(array $data): array|bool
    {
        $errors = [];

        // التحقق من وجود عناصر البيع
        $items = $data['items'] ?? [];
        if (empty($items) || count($items) == 0) {
            $errors[] = 'يرجى إضافة منتج واحد على الأقل';
        }

        // التحقق من تاريخ البيع
        $sale_date = $data['sale_date'] ?? '';
        if (empty($sale_date)) {
            $errors[] = 'تاريخ البيع مطلوب';
        }

        // التحقق من كل عنصر
        $valid_count = 0;
        foreach ($items as $index => $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = floatval($item['quantity'] ?? 0);
            $price = floatval($item['selling_price'] ?? 0);

            if ($pid <= 0) continue;
            $valid_count++;

            if ($qty <= 0) {
                $errors[] = 'الكمية يجب أن تكون أكبر من صفر (المنتج رقم ' . ($index + 1) . ')';
            }
            if ($price <= 0) {
                $errors[] = 'سعر البيع يجب أن يكون أكبر من صفر (المنتج رقم ' . ($index + 1) . ')';
            }
        }

        if ($valid_count === 0 && empty($errors)) {
            $errors[] = 'يرجى إضافة منتج واحد على الأقل مع بيانات صحيحة';
        }

        return empty($errors) ? true : $errors;
    }
}
