<?php
/**
 * ===== خدمة المشتريات (PurchaseService) =====
 * تجمع كل المنطق التجاري المتعلق بالمشتريات
 *
 * المسؤوليات:
 * - تسجيل عمليات الشراء مع إنشاء الدفعات وتحديث المخزون
 * - تحديث عمليات الشراء (الكمية، السعر، التاريخ)
 * - حذف عمليات الشراء مع إعادة تعديل المخزون والدفعات
 * - تسجيل تغييرات الأسعار في سجل الأسعار
 * - التعامل مع التخفيضات والضرائب
 *
 * v4.16.9: ترمي AppException بدلاً من إرجاع مصفوفات خطأ + أنواع إرجاع
 */

class PurchaseService
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
            ErrorHandler::logException($e, 'PurchaseService::' . $context);
        } else {
            $file = basename($e->getFile());
            error_log("[ISM] PurchaseService::{$context}: {$e->getMessage()} في {$file}:{$e->getLine()}");
        }
    }

    /**
     * تسجيل عملية شراء جديدة
     *
     * @param array $data بيانات الشراء
     * @return array بيانات الشراء المسجلة
     * @throws AppException عند فشل التحقق أو خطأ في قاعدة البيانات
     */
    public static function create(array $data): array
    {
        // التحقق من صحة البيانات
        $validation = self::validatePurchaseData($data);
        if ($validation !== true) {
            throw AppException::validation(implode('، ', $validation));
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $product_id = intval($data['product_id']);
            $quantity = floatval($data['quantity']);
            $cost_price = floatval($data['cost_price']);
            $purchase_date = $data['purchase_date'] ?? date('Y-m-d');
            $notes = trim($data['notes'] ?? '');
            $sale_price = !empty($data['sale_price']) ? floatval($data['sale_price']) : null;

            // جلب بيانات المنتج
            $product = fetchOne("SELECT * FROM products WHERE id = ?", [$product_id]);
            if (!$product) {
                $pdo->rollBack();
                throw AppException::notFound('المنتج غير موجود');
            }

            // حساب الإجمالي الخام
            $gross_total = $quantity * $cost_price;

            // === التحسين 1: خصم مالي (توحيد مع add-purchase.php) ===
            $financial_remise = 0;
            $remises = $data['remises'] ?? [];
            foreach ($remises as $remise) {
                if (($remise['type'] ?? '') === 'financial') {
                    $financial_remise += floatval($remise['amount'] ?? 0);
                }
            }
            $total = max(0, $gross_total - $financial_remise);

            // حساب الضريبة على الصافي (بعد الخصم)
            $tax_rate = 0;
            $tax_amount = 0;
            $tax_name = null;
            $tax_enabled = !empty($data['tax_enabled']);
            if ($tax_enabled) {
                $tax_type = $data['tax_type'] ?? 'percentage';
                $tax_value = floatval($data['tax_value'] ?? 0);
                $tax_name = trim($data['tax_name'] ?? '');

                if ($tax_type === 'percentage' && $tax_value > 0) {
                    $tax_rate = $tax_value;
                    $tax_amount = $total * ($tax_value / 100);
                } elseif ($tax_type === 'fixed' && $tax_value > 0) {
                    $tax_rate = $total > 0 ? ($tax_value / $total) * 100 : 0;
                    $tax_amount = $tax_value;
                }
            }

            // === التحسين 2: الإجمالي النهائي = صافي + ضريبة ===
            $grand_total = $total + $tax_amount;

            // === التحسين 3: السعر الفعلي بالوحدة (بعد الخصم والضريبة) ===
            $actual_price = $cost_price;
            if ($quantity > 0 && ($financial_remise > 0 || $tax_amount > 0)) {
                $actual_price = round($grand_total / $quantity, 6);
            }

            // إنشاء فاتورة الشراء (منتج واحد من الهاتف)
            $order_id = null;
            try {
                $pdo->prepare(
                    "INSERT INTO purchase_orders (total, financial_discount, net_total, tax_rate, tax_amount, tax_name, grand_total, purchase_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$gross_total, $financial_remise, $total, $tax_rate, $tax_amount, $tax_name, $grand_total, $purchase_date, $notes]);
                $order_id = $pdo->lastInsertId();
            } catch (\Throwable $e) {
                $order_id = null;
            }

            // إدراج سجل الشراء مع order_id
            $stmt = $pdo->prepare(
                "INSERT INTO purchases (order_id, product_id, quantity, purchase_price, total, tax_rate, tax_amount, tax_name, grand_total, purchase_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $order_id, $product_id, $quantity, $cost_price, $total,
                $tax_rate, $tax_amount, $tax_name, $grand_total, $purchase_date, $notes
            ]);
            $purchase_id = $pdo->lastInsertId();

            // v5.13.0: إنشاء سجلات الدفعات عند إضافة مشتريات
            $batch_items = $data['batch_items'] ?? [];

            // إنشاء سجلات الدفعات تلقائياً
            if (!empty($batch_items)) {
                // الدفعات محددة يدوياً (مع السعر الفعلي)
                $batchStmt = $pdo->prepare(
                    "INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, actual_purchase_price, purchase_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($batch_items as $bi) {
                    $bi_qty = floatval($bi['quantity'] ?? 0);
                    $bi_expiry = !empty($bi['expiry_date']) ? trim($bi['expiry_date']) : null;
                    if ($bi_qty > 0) {
                        $batchStmt->execute([$product_id, $purchase_id, $bi_qty, $bi_qty, $cost_price, $actual_price, $purchase_date, $bi_expiry]);
                    }
                }
            } else {
                // دفعة واحدة تلقائية (مع السعر الفعلي)
                $pdo->prepare(
                    "INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, actual_purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$product_id, $purchase_id, $quantity, $quantity, $cost_price, $actual_price, $purchase_date]);
            }

            // تحديث كمية وسعر المنتج
            $pdo->prepare("UPDATE products SET quantity = quantity + ?, purchase_price = ? WHERE id = ?")
                ->execute([$quantity, $cost_price, $product_id]);

            // تحديث سعر البيع إذا تم تحديده
            if ($sale_price !== null && $sale_price > 0) {
                $pdo->prepare("UPDATE products SET selling_price = ? WHERE id = ?")
                    ->execute([$sale_price, $product_id]);
            }

            // تسجيل تغيير السعر
            try {
                $old_purchase = floatval($product['purchase_price']);
                $old_selling = floatval($product['selling_price']);
                $new_selling = $sale_price ?? $old_selling;

                if (abs($cost_price - $old_purchase) > 0.001 || ($sale_price !== null && abs($sale_price - $old_selling) > 0.001)) {
                    $reason = 'تسجيل عملية شراء جديدة - سعر الشراء: ' . number_format($cost_price, 2);
                    executeQuery(
                        "INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$product_id, $old_purchase, $cost_price, $old_selling, $new_selling, $reason]
                    );
                }
            } catch (\Exception $e) {
                self::logError($e, 'create(priceHistory)');
            }

            // معالجة التخفيضات
            $remises = $data['remises'] ?? [];
            if (!empty($remises)) {
                foreach ($remises as $remise) {
                    $remise_type = $remise['type'] ?? '';
                    if ($remise_type === 'material') {
                        $remise_prod_id = intval($remise['product_id'] ?? 0);
                        $remise_qty = floatval($remise['quantity'] ?? 0);
                        $remise_desc = trim($remise['description'] ?? '');

                        if ($remise_prod_id > 0 && $remise_qty > 0) {
                            try {
                                $pdo->prepare(
                                    "INSERT INTO purchase_remises (purchase_id, order_id, type, product_id, quantity, unit_price, description) VALUES (?, ?, ?, ?, ?, ?, ?)"
                                )->execute([$purchase_id, $order_id, 'material', $remise_prod_id, $remise_qty, 0, $remise_desc]);
                            } catch (\Throwable $e) {
                                self::logError($e, 'create(remiseMaterial)');
                            }

                            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                                ->execute([$remise_qty, $remise_prod_id]);

                            // إنشاء دفعة شراء بسعر 0 للمنتج المجاني
                            try {
                                $pdo->prepare(
                                    "INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, actual_purchase_price, purchase_date, expiry_date) VALUES (?, ?, ?, ?, 0, 0, ?, NULL)"
                                )->execute([$remise_prod_id, $purchase_id, $remise_qty, $remise_qty, $purchase_date]);
                            } catch (\Throwable $e) {
                                self::logError($e, 'create(remiseMaterialBatch)');
                            }
                        }
                    } elseif ($remise_type === 'financial') {
                        $remise_amount = floatval($remise['amount'] ?? 0);
                        $remise_desc = trim($remise['description'] ?? '');

                        if ($remise_amount > 0) {
                            try {
                                $pdo->prepare(
                                    "INSERT INTO purchase_remises (purchase_id, type, amount, description) VALUES (?, ?, ?, ?)"
                                )->execute([$purchase_id, 'financial', $remise_amount, $remise_desc]);
                            } catch (\Throwable $e) {
                                self::logError($e, 'create(remiseFinancial)');
                            }
                        }
                    }
                }
            }

            $pdo->commit();

            // تسجيل النشاط
            try {
                addAuditLog('purchase_add', "تسجيل شراء: {$product['name']} - الكمية: {$quantity}", '', 'purchase', intval($purchase_id));
            } catch (\Exception $e) {
                self::logError($e, 'create(auditLog)');
            }

            return [
                'success' => true,
                'purchase_id' => intval($purchase_id),
                'message' => 'تم تسجيل عملية الشراء بنجاح وتم تحديث المخزون!'
            ];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::logError($e, 'create');
            throw AppException::database('حدث خطأ أثناء حفظ المشتريات. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * تحديث عملية شراء
     *
     * @param int $id معرف عملية الشراء
     * @param array $data البيانات الجديدة
     * @return array نتيجة التحديث
     * @throws AppException عند عدم وجود الشراء أو خطأ في قاعدة البيانات
     */
    public static function update(int $id, array $data): array
    {
        // التحقق من وجود عملية الشراء
        $purchase = fetchOne("SELECT * FROM purchases WHERE id = ?", [$id]);
        if (!$purchase) {
            throw AppException::notFound('عملية الشراء غير موجودة');
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // إعادة جلب بيانات الشراء داخل المعاملة
            $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
            $stmt->execute([$id]);
            $old_purchase = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$old_purchase) {
                throw AppException::notFound('عملية الشراء غير موجودة');
            }

            $old_quantity = floatval($old_purchase['quantity']);
            $old_price = floatval($old_purchase['purchase_price']);
            $old_date = $old_purchase['purchase_date'];
            $product_id = intval($old_purchase['product_id']);

            $new_quantity = floatval($data['quantity'] ?? $old_quantity);
            $new_price = floatval($data['cost_price'] ?? $old_price);
            $new_date = trim($data['purchase_date'] ?? $old_date);
            $new_notes = trim($data['notes'] ?? $old_purchase['notes']);
            $new_total = $new_quantity * $new_price;

            $quantity_changed = abs($new_quantity - $old_quantity) > 0.001;
            $price_changed = abs($new_price - $old_price) > 0.001;
            $date_changed = $new_date !== $old_date;

            // ─────────────────────────────────────
            // 1. تحديث الدفعات المرتبطة بهذه العملية
            // ─────────────────────────────────────
            $batches = $pdo->prepare("SELECT * FROM purchase_batches WHERE purchase_id = ? ORDER BY id");
            $batches->execute([$id]);
            $purchaseBatches = $batches->fetchAll(\PDO::FETCH_ASSOC);

            if ($quantity_changed && !empty($purchaseBatches)) {
                // التحقق من وجود استهلاك في أي دفعة
                foreach ($purchaseBatches as $b) {
                    if (floatval($b['remaining_quantity']) < floatval($b['original_quantity']) - 0.001) {
                        $pdo->rollBack();
                        throw AppException::validation('لا يمكن تعديل كمية عملية الشراء لوجود مبيعات سابقة استهلكت من الدفعات المرتبطة');
                    }
                }

                // تحديث كمية المنتج
                $quantity_diff = $new_quantity - $old_quantity;
                $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                    ->execute([$quantity_diff, $product_id]);

                // إعادة توزيع الكمية على الدفعات بنفس النسب
                if (count($purchaseBatches) > 1) {
                    $total_old_batch = array_sum(array_map(fn($b) => floatval($b['original_quantity']), $purchaseBatches));
                    foreach ($purchaseBatches as $b) {
                        $ratio = floatval($b['original_quantity']) / $total_old_batch;
                        $new_batch_qty = $new_quantity * $ratio;
                        $pdo->prepare("UPDATE purchase_batches SET original_quantity = ?, remaining_quantity = ? WHERE id = ?")
                            ->execute([$new_batch_qty, $new_batch_qty, intval($b['id'])]);
                    }
                } else {
                    // دفعة واحدة
                    $pdo->prepare("UPDATE purchase_batches SET original_quantity = ?, remaining_quantity = ? WHERE id = ?")
                        ->execute([$new_quantity, $new_quantity, intval($purchaseBatches[0]['id'])]);
                }
            } elseif (!$quantity_changed) {
                // لا تغيير في الكمية - لا حاجة لتعديل المنتج
            }

            // ─────────────────────────────────────
            // 2. تغيير السعر
            // ─────────────────────────────────────
            if ($price_changed) {
                // تحديث سعر شراء المنتج
                $pdo->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")
                    ->execute([$new_price, $product_id]);

                // تسجيل تغيير السعر في السجل
                try {
                    $currentProduct = $pdo->prepare("SELECT purchase_price, selling_price FROM products WHERE id = ?");
                    $currentProduct->execute([$product_id]);
                    $prodPrices = $currentProduct->fetch(\PDO::FETCH_ASSOC);

                    $old_selling = floatval($prodPrices['selling_price']);

                    $pdo->prepare(
                        "INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $product_id,
                        $old_price,
                        $new_price,
                        $old_selling,
                        $old_selling,
                        'تعديل عملية شراء #' . $id . ' — سعر الشراء: ' . number_format($new_price, 2)
                    ]);
                } catch (\Exception $e) {
                    self::logError($e, 'update(priceHistory)');
                }

                // تحديث أسعار جميع الدفعات المرتبطة
                if (!empty($purchaseBatches)) {
                    $pdo->prepare("UPDATE purchase_batches SET purchase_price = ? WHERE purchase_id = ?")
                        ->execute([$new_price, $id]);
                }
            }

            // ─────────────────────────────────────
            // 3. تغيير التاريخ
            // ─────────────────────────────────────
            if ($date_changed && !empty($purchaseBatches)) {
                $pdo->prepare("UPDATE purchase_batches SET purchase_date = ? WHERE purchase_id = ?")
                    ->execute([$new_date, $id]);
            }

            // ─────────────────────────────────────
            // 4. تحديث سجل الشراء الرئيسي
            // ─────────────────────────────────────
            $pdo->prepare(
                "UPDATE purchases SET quantity = ?, purchase_price = ?, total = ?, purchase_date = ?, notes = ? WHERE id = ?"
            )->execute([$new_quantity, $new_price, $new_total, $new_date, $new_notes, $id]);

            $pdo->commit();

            // تسجيل النشاط
            try {
                addAuditLog('purchase_add', "تعديل عملية شراء #{$id}", '', 'purchase', $id);
            } catch (\Exception $e) {
                self::logError($e, 'update(auditLog)');
            }

            return ['success' => true, 'message' => 'تم تعديل عملية الشراء بنجاح وتم تحديث المخزون والأسعار.'];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::logError($e, 'update');
            throw AppException::database('حدث خطأ أثناء تحديث المشتريات. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * حذف عملية شراء مع إعادة تعديل المخزون والدفعات
     *
     * @param int $id معرف عملية الشراء
     * @return array نتيجة الحذف
     * @throws AppException عند عدم وجود الشراء أو خطأ في قاعدة البيانات
     */
    public static function delete(int $id): array
    {
        // التحقق من وجود عملية الشراء
        $purchase = fetchOne("SELECT * FROM purchases WHERE id = ?", [$id]);
        if (!$purchase) {
            throw AppException::notFound('عملية الشراء غير موجودة');
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // جلب الدفعات المرتبطة بهذه العملية
            $batchStmt = $pdo->prepare("SELECT id, original_quantity, remaining_quantity FROM purchase_batches WHERE purchase_id = ?");
            $batchStmt->execute([$id]);
            $purchaseBatches = $batchStmt->fetchAll(\PDO::FETCH_ASSOC);

            // حساب إجمالي الكمية المتبقية في الدفعات
            $total_remaining = 0;
            foreach ($purchaseBatches as $b) {
                $total_remaining += floatval($b['remaining_quantity']);
            }

            // خصم الكمية المتبقية (وليس الأصلية) من المنتج
            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")
                ->execute([$total_remaining, intval($purchase['product_id'])]);

            // حذف sale_batch_items المرتبطة بالدفعات ثم حذف الدفعات
            $batchItemDeleteStmt = $pdo->prepare("DELETE FROM sale_batch_items WHERE batch_id = ?");
            foreach ($purchaseBatches as $b) {
                $batchItemDeleteStmt->execute([intval($b['id'])]);
            }
            $pdo->prepare("DELETE FROM purchase_batches WHERE purchase_id = ?")->execute([$id]);

            // حذف التخفيضات المرتبطة
            try {
                $pdo->prepare("DELETE FROM purchase_remises WHERE purchase_id = ?")->execute([$id]);
            } catch (\Exception $e) {
                self::logError($e, 'delete(remises)');
            }

            // حذف سجل الشراء
            $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$id]);

            $pdo->commit();

            // تسجيل النشاط
            try {
                $productName = fetchOne("SELECT name FROM products WHERE id = ?", [intval($purchase['product_id'])]);
                $productName = $productName ? $productName['name'] : '';
                addAuditLog('purchase_delete', "حذف عملية شراء: {$productName}", '', 'purchase', $id);
            } catch (\Exception $e) {
                self::logError($e, 'delete(auditLog)');
            }

            return ['success' => true, 'message' => 'تم حذف عملية الشراء بنجاح وتم تحديث المخزون.'];

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
            throw AppException::database('حدث خطأ أثناء حذف المشتريات. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * التحقق من صحة بيانات الشراء
     *
     * @param array $data بيانات الشراء
     * @return array|true مصفوفة الأخطاء أو true
     */
    private static function validatePurchaseData(array $data): array|bool
    {
        $errors = [];

        // معرف المنتج
        $product_id = intval($data['product_id'] ?? 0);
        if ($product_id <= 0) {
            $errors[] = 'يرجى اختيار منتج';
        } else {
            $product = fetchOne("SELECT id FROM products WHERE id = ?", [$product_id]);
            if (!$product) {
                $errors[] = 'المنتج المحدد غير موجود';
            }
        }

        // الكمية
        if (floatval($data['quantity'] ?? 0) <= 0) {
            $errors[] = 'الكمية يجب أن تكون أكبر من صفر';
        }

        // سعر الشراء
        if (floatval($data['cost_price'] ?? 0) <= 0) {
            $errors[] = 'سعر الشراء يجب أن يكون أكبر من صفر';
        }

        // تاريخ الشراء
        $purchase_date = $data['purchase_date'] ?? '';
        if (empty($purchase_date)) {
            $errors[] = 'تاريخ الشراء مطلوب';
        }

        // التحقق من تواريخ الانتهاء
        $batch_items = $data['batch_items'] ?? [];
        if (!empty($batch_items)) {
            $total_batch_qty = 0;
            $quantity = floatval($data['quantity'] ?? 0);
            foreach ($batch_items as $bi) {
                $bi_qty = floatval($bi['quantity'] ?? 0);
                $bi_date = trim($bi['expiry_date'] ?? '');
                if ($bi_qty > 0) {
                    $total_batch_qty += $bi_qty;
                    if (!empty($bi_date) && strtotime($bi_date) === false) {
                        $errors[] = 'تاريخ الانتهاء غير صالح: ' . $bi_date;
                    }
                }
            }
            if ($total_batch_qty > 0 && abs($total_batch_qty - $quantity) > 0.001) {
                $errors[] = 'مجموع كميات الدفعات (' . number_format($total_batch_qty, 2) . ') يجب أن يساوي الكمية الإجمالية (' . number_format($quantity, 2) . ')';
            }
        }

        // التحقق من بيانات الضريبة
        if (!empty($data['tax_enabled'])) {
            $tax_value = floatval($data['tax_value'] ?? 0);
            $tax_name = trim($data['tax_name'] ?? '');
            if ($tax_value <= 0) {
                $errors[] = 'يرجى إدخال قيمة الضريبة أو تعطيلها';
            }
            if (empty($tax_name)) {
                $errors[] = 'يرجى إدخال اسم الضريبة';
            }
            $tax_type = $data['tax_type'] ?? 'percentage';
            if (!in_array($tax_type, ['percentage', 'fixed'])) {
                $errors[] = 'نوع الضريبة غير صالح';
            }
        }

        return empty($errors) ? true : $errors;
    }
}
