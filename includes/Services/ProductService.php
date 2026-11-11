<?php
/**
 * ===== خدمة المنتجات (ProductService) =====
 * تجمع كل المنطق التجاري المتعلق بالمنتجات في مكان واحد
 *
 * المسؤوليات:
 * - إضافة / تعديل / حذف المنتجات
 * - البحث والتصفية مع ترقيم الصفحات
 * - تحويل المخزون بين المنتجات والوحدات
 * - جرد المخزون ومقارنة الكميات
 * - تسجيل تغييرات الأسعار في السجل
 * - تقرير الصلاحية وانتهاء المنتجات
 * - تقرير سجل الأسعار
 * - تسجيل النشاطات في سجل المراجعة
 *
 * v4.16.9: ترمي AppException بدلاً من إرجاع مصفوفات خطأ + أنواع إرجاع
 * v5.26.0: إضافة طرق جلب البيانات لفصل MVC (getActiveCategories, detectColumns,
 *           updateQuantity, transferBetweenProducts, getInventoryProducts, getLastInventorySummary,
 *           getExpiryStats, getExpiryBatches, getPriceHistory, getProductViewData)
 */

class ProductService
{
    /**
     * تسجيل الخطأ — يستخدم ErrorHandler إن كان متوفراً، وإلا error_log
     */
    private static function logError(\Throwable $e, string $context = ''): void
    {
        if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
            ErrorHandler::logException($e, 'ProductService::' . $context);
        } else {
            $file = basename($e->getFile());
            error_log("[ISM] ProductService::{$context}: {$e->getMessage()} في {$file}:{$e->getLine()}");
        }
    }

    // ====================================================================
    //  طرق CRUD الأساسية (موجودة مسبقاً)
    // ====================================================================

    /**
     * إضافة منتج جديد مع التحقق من البيانات
     */
    public static function create(array $data): array
    {
        $validation = self::validateProductData($data);
        if ($validation !== true) {
            $code = trim($data['code'] ?? '');
            $isDuplicateCodeError = (!empty($code) && count($validation) === 1 && in_array('كود المنتج موجود مسبقاً', $validation));
            if (!$isDuplicateCodeError) {
                throw AppException::validation(implode('، ', $validation));
            }
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $code = trim($data['code'] ?? '');
            if (empty($code)) {
                $code = self::generateProductCode();
            }

            $existing = fetchOne("SELECT id FROM products WHERE code = ?", [$code]);
            if ($existing) {
                if (preg_match('/PRD-(\d+)/', $code, $matches)) {
                    $num = intval($matches[1]);
                    do {
                        $num++;
                        $code = 'PRD-' . str_pad($num, 3, '0', STR_PAD_LEFT);
                    } while (fetchOne("SELECT id FROM products WHERE code = ?", [$code]));
                } else {
                    $pdo->rollBack();
                    throw AppException::validation('كود المنتج موجود مسبقاً');
                }
            }

            $name = trim($data['name']);
            $purchase_price = floatval($data['purchase_price']);
            $selling_price = floatval($data['selling_price']);
            $unit = trim($data['unit']);
            $purchase_unit = !empty($data['purchase_unit']) ? trim($data['purchase_unit']) : null;
            $purchase_unit_factor = floatval($data['purchase_unit_factor'] ?? 1);
            $quantity = floatval($data['quantity'] ?? 0);
            $min_quantity = floatval($data['min_quantity'] ?? 0);
            $category_id = !empty($data['category_id']) ? intval($data['category_id']) : null;
            $has_expiry = !empty($data['has_expiry']) ? 1 : 0;

            $stmt = $pdo->prepare(
                "INSERT INTO products (code, name, purchase_price, selling_price, unit, purchase_unit, purchase_unit_factor, category_id, quantity, min_quantity, has_expiry)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $code, $name, $purchase_price, $selling_price, $unit,
                $purchase_unit, $purchase_unit_factor, $category_id,
                $quantity, $min_quantity, $has_expiry
            ]);
            $product_id = $pdo->lastInsertId();

            $additional_units = $data['additional_units'] ?? [];
            if (!empty($additional_units)) {
                try {
                    $auStmt = $pdo->prepare(
                        "INSERT INTO additional_units (product_id, unit_name, conversion_factor, selling_price) VALUES (?, ?, ?, ?)"
                    );
                    foreach ($additional_units as $au) {
                        if (!empty($au['name']) && floatval($au['factor'] ?? 0) > 0) {
                            $auStmt->execute([$product_id, $au['name'], $au['factor'], floatval($au['price'] ?? 0)]);
                        }
                    }
                } catch (\Exception $e) {
                    self::logError($e, 'create(additionalUnits)');
                }
            }

            $purchase_additional_units = $data['purchase_additional_units'] ?? [];
            if (!empty($purchase_additional_units)) {
                try {
                    $pauStmt = $pdo->prepare(
                        "INSERT INTO purchase_additional_units (product_id, unit_name, conversion_factor) VALUES (?, ?, ?)"
                    );
                    foreach ($purchase_additional_units as $pau) {
                        if (!empty($pau['name']) && floatval($pau['factor'] ?? 0) > 0) {
                            $pauStmt->execute([$product_id, $pau['name'], $pau['factor']]);
                        }
                    }
                } catch (\Exception $e) {
                    self::logError($e, 'create(purchaseAdditionalUnits)');
                }
            }

            if ($quantity > 0) {
                $expiry_dates = $data['expiry_dates'] ?? [];
                if ($has_expiry && !empty($expiry_dates)) {
                    $batchStmt = $pdo->prepare(
                        "INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    foreach ($expiry_dates as $ed) {
                        $ed_qty = floatval($ed['quantity'] ?? 0);
                        $ed_date = !empty($ed['date']) ? trim($ed['date']) : null;
                        if ($ed_qty > 0) {
                            $batchStmt->execute([$product_id, $ed_qty, $ed_qty, $purchase_price, date('Y-m-d'), $ed_date]);
                        }
                    }
                } else {
                    $pdo->prepare(
                        "INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?)"
                    )->execute([$product_id, $quantity, $quantity, $purchase_price, date('Y-m-d')]);
                }
            }

            $pdo->commit();

            try {
                addAuditLog('product_add', "إضافة منتج جديد: {$name} ({$code})", '', 'product', intval($product_id));
            } catch (\Exception $e) {
                self::logError($e, 'create(auditLog)');
            }

            return [
                'success' => true,
                'id' => intval($product_id),
                'message' => 'تم إضافة المنتج بنجاح! الكود: ' . $code
            ];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            self::logError($e, 'create');
            throw AppException::database('حدث خطأ أثناء إضافة المنتج. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * تحديث منتج موجود
     */
    public static function update(int $id, array $data): array
    {
        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
        if (!$product) {
            throw AppException::notFound('المنتج غير موجود');
        }

        $validation = self::validateProductData($data, $id);
        if ($validation !== true) {
            throw AppException::validation(implode('، ', $validation));
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $code = trim($data['code'] ?? $product['code']);
            $name = trim($data['name'] ?? $product['name']);
            $purchase_price = floatval($data['purchase_price'] ?? $product['purchase_price']);
            $selling_price = floatval($data['selling_price'] ?? $product['selling_price']);
            $unit = trim($data['unit'] ?? $product['unit']);
            $purchase_unit = !empty($data['purchase_unit']) ? trim($data['purchase_unit']) : null;
            $purchase_unit_factor = floatval($data['purchase_unit_factor'] ?? $product['purchase_unit_factor'] ?? 1);
            $quantity = floatval($data['quantity'] ?? $product['quantity']);
            $min_quantity = floatval($data['min_quantity'] ?? $product['min_quantity']);
            $category_id = !empty($data['category_id']) ? intval($data['category_id']) : null;
            $has_expiry = isset($data['has_expiry']) ? intval($data['has_expiry']) : intval($product['has_expiry']);

            $existing = fetchOne("SELECT id FROM products WHERE code = ? AND id != ?", [$code, $id]);
            if ($existing) {
                $pdo->rollBack();
                throw AppException::validation('كود المنتج موجود مسبقاً');
            }

            $old_purchase = floatval($product['purchase_price']);
            $old_selling = floatval($product['selling_price']);

            if (abs($purchase_price - $old_purchase) > 0.001 || abs($selling_price - $old_selling) > 0.001) {
                self::logPriceChange($id, $old_purchase, $old_selling, $purchase_price, $selling_price, $data['change_reason'] ?? '');
            }

            $pdo->prepare(
                "UPDATE products SET code=?, name=?, purchase_price=?, selling_price=?, unit=?, purchase_unit=?, purchase_unit_factor=?, category_id=?, quantity=?, min_quantity=?, has_expiry=? WHERE id=?"
            )->execute([
                $code, $name, $purchase_price, $selling_price, $unit,
                $purchase_unit, $purchase_unit_factor, $category_id,
                $quantity, $min_quantity, $has_expiry, $id
            ]);

            $additional_units = $data['additional_units'] ?? [];
            try {
                $pdo->prepare("DELETE FROM additional_units WHERE product_id = ?")->execute([$id]);
                if (!empty($additional_units)) {
                    $auStmt = $pdo->prepare(
                        "INSERT INTO additional_units (product_id, unit_name, conversion_factor, selling_price) VALUES (?, ?, ?, ?)"
                    );
                    foreach ($additional_units as $au) {
                        if (!empty($au['name']) && floatval($au['factor'] ?? 0) > 0) {
                            $auStmt->execute([$id, $au['name'], $au['factor'], floatval($au['price'] ?? 0)]);
                        }
                    }
                }
            } catch (\Exception $e) {
                self::logError($e, 'update(additionalUnits)');
            }

            $purchase_additional_units = $data['purchase_additional_units'] ?? [];
            try {
                $pdo->prepare("DELETE FROM purchase_additional_units WHERE product_id = ?")->execute([$id]);
                if (!empty($purchase_additional_units)) {
                    $pauStmt = $pdo->prepare(
                        "INSERT INTO purchase_additional_units (product_id, unit_name, conversion_factor) VALUES (?, ?, ?)"
                    );
                    foreach ($purchase_additional_units as $pau) {
                        if (!empty($pau['name']) && floatval($pau['factor'] ?? 0) > 0) {
                            $pauStmt->execute([$id, $pau['name'], $pau['factor']]);
                        }
                    }
                }
            } catch (\Exception $e) {
                self::logError($e, 'update(purchaseAdditionalUnits)');
            }

            $old_quantity = floatval($product['quantity']);
            if ($quantity > $old_quantity) {
                $qty_diff = $quantity - $old_quantity;
                $pdo->prepare(
                    "INSERT INTO purchase_batches (product_id, original_quantity, remaining_quantity, purchase_price, purchase_date) VALUES (?, ?, ?, ?, ?)"
                )->execute([$id, $qty_diff, $qty_diff, $purchase_price, date('Y-m-d')]);
            }

            $pdo->commit();

            try {
                addAuditLog('product_edit', "تعديل المنتج: {$name} ({$code})", '', 'product', $id);
            } catch (\Exception $e) {
                self::logError($e, 'update(auditLog)');
            }

            return ['success' => true, 'message' => 'تم تحديث المنتج بنجاح!'];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            self::logError($e, 'update');
            throw AppException::database('حدث خطأ أثناء تحديث المنتج. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * حذف منتج (حذف صلب)
     */
    public static function delete(int $id): array
    {
        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
        if (!$product) {
            throw AppException::notFound('المنتج غير موجود');
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $saleCount = fetchOne("SELECT COUNT(*) as cnt FROM sales WHERE product_id = ?", [$id]);
            if ($saleCount && intval($saleCount['cnt']) > 0) {
                $pdo->rollBack();
                throw AppException::validation('لا يمكن حذف المنتج لوجود عمليات بيع مرتبطة به (' . $saleCount['cnt'] . ' عملية)');
            }

            $purchaseCount = fetchOne("SELECT COUNT(*) as cnt FROM purchases WHERE product_id = ?", [$id]);
            if ($purchaseCount && intval($purchaseCount['cnt']) > 0) {
                $pdo->rollBack();
                throw AppException::validation('لا يمكن حذف المنتج لوجود عمليات شراء مرتبطة به (' . $purchaseCount['cnt'] . ' عملية)');
            }

            $pdo->prepare("DELETE FROM additional_units WHERE product_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM purchase_additional_units WHERE product_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM purchase_batches WHERE product_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM price_history WHERE product_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM inventory_counts WHERE product_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);

            $pdo->commit();

            try {
                addAuditLog('product_delete', "حذف المنتج: {$product['name']} ({$product['code']})", '', 'product', $id);
            } catch (\Exception $e) {
                self::logError($e, 'delete(auditLog)');
            }

            return ['success' => true, 'message' => 'تم حذف المنتج بنجاح'];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            self::logError($e, 'delete');
            throw AppException::database('حدث خطأ أثناء حذف المنتج. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    /**
     * جلب منتج بالمعرف مع كل البيانات المرتبطة
     */
    public static function getById(int $id): ?array
    {
        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$id]);
        if (!$product) return null;

        $product['additional_units'] = [];
        try {
            $product['additional_units'] = fetchAll("SELECT * FROM additional_units WHERE product_id = ?", [$id]);
        } catch (\Exception $e) { self::logError($e, 'getById(additionalUnits)'); }

        $product['purchase_additional_units'] = [];
        try {
            $product['purchase_additional_units'] = fetchAll("SELECT * FROM purchase_additional_units WHERE product_id = ?", [$id]);
        } catch (\Exception $e) { self::logError($e, 'getById(purchaseAdditionalUnits)'); }

        $product['batches'] = [];
        try {
            $product['batches'] = fetchAll("SELECT * FROM purchase_batches WHERE product_id = ? ORDER BY id", [$id]);
        } catch (\Exception $e) { self::logError($e, 'getById(batches)'); }

        $product['price_history'] = [];
        try {
            $product['price_history'] = fetchAll("SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT 10", [$id]);
        } catch (\Exception $e) { self::logError($e, 'getById(priceHistory)'); }

        if (!empty($product['category_id'])) {
            $category = fetchOne("SELECT name FROM categories WHERE id = ?", [$product['category_id']]);
            $product['category_name'] = $category ? $category['name'] : '';
        } else {
            $product['category_name'] = '';
        }

        return $product;
    }

    /**
     * البحث في المنتجات مع ترقيم الصفحات والتصفية
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return SearchService::searchProducts($filters, $page, $perPage, [
            'select_columns' => SearchService::buildCategorySelect(),
        ]);
    }

    /**
     * تحويل المخزون بين وحدات القياس لمنتج واحد
     */
    public static function transferStock(int $productId, int $fromUnitId, int $toUnitId, float $quantity): array
    {
        $product = fetchOne("SELECT * FROM products WHERE id = ?", [$productId]);
        if (!$product) throw AppException::notFound('المنتج غير موجود');
        if ($quantity <= 0) throw AppException::validation('الكمية يجب أن تكون أكبر من صفر');

        $fromFactor = 1.0;
        $toFactor = 1.0;

        if ($fromUnitId > 0) {
            $fromUnit = fetchOne("SELECT conversion_factor FROM additional_units WHERE id = ? AND product_id = ?", [$fromUnitId, $productId]);
            if (!$fromUnit) throw AppException::validation('وحدة القياس المصدر غير صالحة');
            $fromFactor = floatval($fromUnit['conversion_factor']);
        }

        if ($toUnitId > 0) {
            $toUnit = fetchOne("SELECT conversion_factor FROM additional_units WHERE id = ? AND product_id = ?", [$toUnitId, $productId]);
            if (!$toUnit) throw AppException::validation('وحدة القياس الوجهة غير صالحة');
            $toFactor = floatval($toUnit['conversion_factor']);
        }

        $baseQuantity = $quantity * $fromFactor;
        $result = $toFactor > 0 ? $baseQuantity / $toFactor : 0;

        if ($baseQuantity > floatval($product['quantity'])) {
            throw AppException::validation('الكمية المطلوبة أكبر من المتوفر في المخزون');
        }

        return [
            'success' => true,
            'result' => round($result, 4),
            'base_quantity' => $baseQuantity,
            'message' => "تم تحويل {$quantity} وحدة بنجاح = " . round($result, 2) . " وحدة وجهة"
        ];
    }

    /**
     * جرد المخزون - مقارنة الكمية النظرية بالفعلية وتحديث المخزون
     */
    public static function inventoryCount(array $items, string $countDate = '', string $notes = ''): array
    {
        if (empty($items)) throw AppException::validation('قائمة الجرد فارغة');
        if (empty($countDate)) $countDate = date('Y-m-d');

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $updated_count = 0;
            $results = [];

            foreach ($items as $item) {
                $product_id = intval($item['product_id'] ?? 0);
                $actual_quantity = floatval($item['actual_quantity'] ?? 0);
                if ($product_id <= 0) continue;

                $product = fetchOne("SELECT quantity FROM products WHERE id = ?", [$product_id]);
                if (!$product) continue;

                $system_quantity = floatval($product['quantity']);
                $difference = $actual_quantity - $system_quantity;

                try {
                    executeQuery(
                        "INSERT INTO inventory_counts (product_id, actual_quantity, system_quantity, difference, count_date, notes) VALUES (?, ?, ?, ?, ?, ?)",
                        [$product_id, $actual_quantity, $system_quantity, $difference, $countDate, $notes]
                    );
                } catch (\Exception $e) { self::logError($e, 'inventoryCount(insertRecord)'); }

                if (abs($difference) > 0.001) {
                    $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?")->execute([$actual_quantity, $product_id]);
                    $updated_count++;
                }

                $results[] = [
                    'product_id' => $product_id,
                    'system_quantity' => $system_quantity,
                    'actual_quantity' => $actual_quantity,
                    'difference' => $difference
                ];
            }

            $pdo->commit();

            try {
                addAuditLog('product_quantity_update', "جرد المخزون: تم تحديث {$updated_count} منتج", '', 'inventory', 0);
            } catch (\Exception $e) { self::logError($e, 'inventoryCount(auditLog)'); }

            return [
                'success' => true,
                'updated_count' => $updated_count,
                'items' => $results,
                'message' => "تم حفظ الجرد بنجاح! تم تحديث {$updated_count} منتج."
            ];

        } catch (AppException $ae) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ae;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            self::logError($e, 'inventoryCount');
            throw AppException::database('حدث خطأ أثناء جرد المخزون. يرجى المحاولة مرة أخرى.', $e);
        }
    }

    // ====================================================================
    //  طرق جديدة v5.26.0 — لفصل MVC (جلب البيانات للصفحات)
    // ====================================================================

    /**
     * جلب التصنيفات النشطة مرتبة
     *
     * @return array قائمة التصنيفات
     */
    public static function getActiveCategories(): array
    {
        try {
            return fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        } catch (\Exception $e) {
            self::logError($e, 'getActiveCategories');
            return [];
        }
    }

    /**
     * جلب كل التصنيفات (بما فيها غير النشطة) — للتقارير
     *
     * @return array
     */
    public static function getAllCategories(): array
    {
        try {
            return fetchAll("SELECT id, name FROM categories ORDER BY name ASC");
        } catch (\Exception $e) {
            self::logError($e, 'getAllCategories');
            return [];
        }
    }

    /**
     * كشف الأعمدة الموجودة في جدول products تلقائياً
     *
     * @return array ['hasBarcode' => bool, 'hasDeletedAt' => bool]
     */
    public static function detectColumns(): array
    {
        $result = ['hasBarcode' => false, 'hasDeletedAt' => false];
        try {
            $pdo = getDBConnection();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $columns = fetchAll("PRAGMA table_info(products)");
                $colNames = array_map(fn($c) => strtolower($c['name'] ?? $c['Field'] ?? ''), $columns);
            } else {
                $colNames = SchemaCache::remember('products_columns', function() {
                    $columns = fetchAll("SHOW COLUMNS FROM products");
                    return array_map(fn($c) => strtolower($c['Field'] ?? ''), $columns);
                });
            }
            $result['hasBarcode'] = in_array('barcode', $colNames);
            $result['hasDeletedAt'] = in_array('deleted_at', $colNames);
        } catch (\Exception $e) {
            self::logError($e, 'detectColumns');
        }
        return $result;
    }

    /**
     * تحديث كمية منتج (لتحرير مباشر من قائمة المنتجات / الجرد)
     *
     * @param int $id معرف المنتج
     * @param float $newQty الكمية الجديدة
     * @throws \Exception عند الفشل
     */
    public static function updateQuantity(int $id, float $newQty): void
    {
        executeQuery("UPDATE products SET quantity = ? WHERE id = ?", [$newQty, $id]);
    }

    /**
     * جلب قائمة المنتجات الأساسية (id, code, name, quantity, unit)
     * يُستخدم في تحويل المخزون والجرد
     *
     * @return array
     */
    public static function getAllBasic(): array
    {
        try {
            return fetchAll("SELECT id, code, name, quantity, unit FROM products ORDER BY name ASC");
        } catch (\Exception $e) {
            self::logError($e, 'getAllBasic');
            return [];
        }
    }

    /**
     * جلب منتجات للجرد (id, code, name, unit, quantity, min_quantity, purchase_price)
     *
     * @return array
     */
    public static function getInventoryProducts(): array
    {
        try {
            return fetchAll("SELECT id, code, name, unit, quantity, min_quantity, purchase_price FROM products ORDER BY code ASC");
        } catch (\Exception $e) {
            self::logError($e, 'getInventoryProducts');
            return [];
        }
    }

    /**
     * جلب ملخص آخر جرد
     *
     * @return array|null
     */
    public static function getLastInventorySummary(): ?array
    {
        try {
            return fetchOne(
                "SELECT count_date, COUNT(*) as total_items, SUM(ABS(difference)) as total_diff
                 FROM inventory_counts
                 GROUP BY count_date
                 ORDER BY count_date DESC
                 LIMIT 1"
            );
        } catch (\Exception $e) {
            self::logError($e, 'getLastInventorySummary');
            return null;
        }
    }

    /**
     * تحويل كمية بين منتجين مختلفين (NOT between units of same product)
     *
     * @param int $fromId معرف المنتج المصدر
     * @param int $toId معرف المنتج الوجهة
     * @param float $quantity الكمية
     * @param string $notes ملاحظات
     * @return array ['success' => bool, 'transfer_info' => array]
     * @throws AppException
     */
    public static function transferBetweenProducts(int $fromId, int $toId, float $quantity, string $notes = ''): array
    {
        if ($fromId <= 0) throw AppException::validation('يرجى اختيار المنتج المصدر');
        if ($toId <= 0) throw AppException::validation('يرجى اختيار المنتج الوجهة');
        if ($fromId === $toId) throw AppException::validation('لا يمكن التحويل لنفس المنتج');
        if ($quantity <= 0) throw AppException::validation(t('common_quantity_must_be_positive'));

        $from_product = fetchOne("SELECT * FROM products WHERE id = ?", [$fromId]);
        if (!$from_product) throw AppException::validation(t('transfer_source_not_found'));

        $to_product = fetchOne("SELECT * FROM products WHERE id = ?", [$toId]);
        if (!$to_product) throw AppException::validation(t('transfer_dest_not_found'));

        $available = floatval($from_product['quantity']);
        if ($quantity > $available) {
            throw AppException::validation(t('transfer_qty_exceeds_available') . number_format($available, 2) . ')');
        }

        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$quantity, $fromId]);
            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([$quantity, $toId]);

            $pdo->commit();

            try {
                addAuditLog('stock_transfer',
                    "تحويل مخزون: {$quantity} {$from_product['unit']} من {$from_product['name']} إلى {$to_product['name']}",
                    $notes, 'product', $fromId
                );
            } catch (\Exception $e) { self::logError($e, 'transferBetweenProducts(auditLog)'); }

            return [
                'success' => true,
                'transfer_info' => [
                    'from_name' => $from_product['name'],
                    'from_code' => $from_product['code'],
                    'to_name' => $to_product['name'],
                    'to_code' => $to_product['code'],
                    'quantity' => $quantity,
                    'unit' => $from_product['unit'],
                    'notes' => $notes
                ]
            ];
        } catch (AppException $ae) {
            throw $ae;
        } catch (\Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            self::logError($e, 'transferBetweenProducts');
            throw AppException::database(t('transfer_error'));
        }
    }

    /**
     * جلب إحصائيات تقرير الصلاحية
     *
     * @param string $today تاريخ اليوم (Y-m-d)
     * @param string $expiryLimit حد الصلاحية (Y-m-d)
     * @return array ['expired' => [...], 'expiring' => [...], 'valid' => [...]]
     */
    public static function getExpiryStats(string $today, string $expiryLimit): array
    {
        $stats = [
            'expired'  => ['count' => 0, 'qty' => 0, 'value' => 0],
            'expiring' => ['count' => 0, 'qty' => 0, 'value' => 0],
            'valid'    => ['count' => 0, 'qty' => 0, 'value' => 0],
        ];

        try {
            // منتهية
            $r = fetchOne(
                "SELECT COUNT(DISTINCT p.id) as product_count,
                        COALESCE(SUM(pb.remaining_quantity), 0) as total_qty,
                        COALESCE(SUM(pb.remaining_quantity * pb.purchase_price), 0) as total_value
                 FROM purchase_batches pb JOIN products p ON pb.product_id = p.id
                 WHERE pb.expiry_date < ? AND pb.remaining_quantity > 0 AND p.has_expiry = 1", [$today]
            );
            if ($r) $stats['expired'] = ['count' => $r['product_count'] ?? 0, 'qty' => $r['total_qty'] ?? 0, 'value' => $r['total_value'] ?? 0];

            // قاربت الانتهاء
            $r = fetchOne(
                "SELECT COUNT(DISTINCT p.id) as product_count,
                        COALESCE(SUM(pb.remaining_quantity), 0) as total_qty,
                        COALESCE(SUM(pb.remaining_quantity * pb.purchase_price), 0) as total_value
                 FROM purchase_batches pb JOIN products p ON pb.product_id = p.id
                 WHERE pb.expiry_date >= ? AND pb.expiry_date <= ? AND pb.remaining_quantity > 0 AND p.has_expiry = 1",
                [$today, $expiryLimit]
            );
            if ($r) $stats['expiring'] = ['count' => $r['product_count'] ?? 0, 'qty' => $r['total_qty'] ?? 0, 'value' => $r['total_value'] ?? 0];

            // صالحة
            $r = fetchOne(
                "SELECT COUNT(DISTINCT p.id) as product_count,
                        COALESCE(SUM(pb.remaining_quantity), 0) as total_qty,
                        COALESCE(SUM(pb.remaining_quantity * pb.purchase_price), 0) as total_value
                 FROM purchase_batches pb JOIN products p ON pb.product_id = p.id
                 WHERE pb.expiry_date > ? AND pb.remaining_quantity > 0 AND p.has_expiry = 1",
                [$expiryLimit]
            );
            if ($r) $stats['valid'] = ['count' => $r['product_count'] ?? 0, 'qty' => $r['total_qty'] ?? 0, 'value' => $r['total_value'] ?? 0];
        } catch (\Exception $e) {
            self::logError($e, 'getExpiryStats');
        }

        return $stats;
    }

    /**
     * جلب دفعات الصلاحية مع فلاتر
     *
     * @param array $filters ['status' => string, 'days' => int, 'category' => int, 'q' => string]
     * @return array
     */
    public static function getExpiryBatches(array $filters): array
    {
        $today = date('Y-m-d');
        $filter_days = max(1, intval($filters['days'] ?? 30));
        $expiry_limit = date('Y-m-d', strtotime('+' . $filter_days . ' days'));
        $filter_status = $filters['status'] ?? 'all';
        $filter_category = intval($filters['category'] ?? 0);
        $search_query = trim($filters['q'] ?? '');

        $where_conditions = ["pb.expiry_date IS NOT NULL", "pb.remaining_quantity > 0", "p.has_expiry = 1"];
        $params = [];

        if ($filter_status === 'expired') {
            $where_conditions[] = "pb.expiry_date < ?";
            $params[] = $today;
        } elseif ($filter_status === 'expiring') {
            $where_conditions[] = "pb.expiry_date >= ?";
            $where_conditions[] = "pb.expiry_date <= ?";
            $params[] = $today;
            $params[] = $expiry_limit;
        } elseif ($filter_status === 'valid') {
            $where_conditions[] = "pb.expiry_date > ?";
            $params[] = $expiry_limit;
        } else {
            $where_conditions[] = "pb.expiry_date <= ?";
            $params[] = $expiry_limit;
        }

        if ($filter_category > 0) {
            $where_conditions[] = "p.category_id = ?";
            $params[] = $filter_category;
        }

        if (!empty($search_query)) {
            $searchWhere = SearchService::buildSearchWhere($search_query, 'p', false);
            $where_conditions[] = $searchWhere['where'];
            $params = array_merge($params, $searchWhere['params']);
        }

        $where_clause = implode(' AND ', $where_conditions);

        try {
            $batches = fetchAll(
                "SELECT pb.id, pb.product_id, pb.remaining_quantity, pb.purchase_price, pb.expiry_date, pb.purchase_date,
                        pb.original_quantity,
                        p.code, p.name, p.unit, p.category_id,
                        c.name as category_name,
                        (pb.remaining_quantity * pb.purchase_price) as batch_value,
                        DATEDIFF(pb.expiry_date, CURDATE()) as days_left,
                        CASE
                            WHEN pb.expiry_date < CURDATE() THEN 'expired'
                            WHEN pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'critical'
                            WHEN pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 'warning'
                            WHEN pb.expiry_date <= ? THEN 'expiring'
                            ELSE 'valid'
                        END as status
                 FROM purchase_batches pb
                 JOIN products p ON pb.product_id = p.id
                 LEFT JOIN categories c ON p.category_id = c.id
                 WHERE $where_clause
                 ORDER BY
                    CASE
                        WHEN pb.expiry_date < CURDATE() THEN 0
                        WHEN pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1
                        WHEN pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 2
                        ELSE 3
                    END,
                    pb.expiry_date ASC,
                    pb.remaining_quantity DESC",
                array_merge($params, [$expiry_limit])
            );
        } catch (\Exception $e) {
            self::logError($e, 'getExpiryBatches');
            $batches = [];
        }

        return $batches;
    }

    /**
     * جلب سجل تغييرات الأسعار مع فلاتر وترقيم صفحات
     *
     * @param array $filters ['search' => string, 'type' => string, 'date_from' => string, 'date_to' => string]
     * @param int $page رقم الصفحة
     * @param int $perPage عدد العناصر لكل صفحة
     * @return array ['logs' => array, 'total' => int, 'total_pages' => int, 'total_changes' => int, 'purchase_changes' => int, 'selling_changes' => int, 'last_change' => string|null, 'offset' => int]
     */
    public static function getPriceHistory(array $filters, int $page = 1, int $perPage = 50): array
    {
        $search_product = trim($filters['search'] ?? '');
        $filter_type = $filters['type'] ?? 'all';
        $filter_date_from = $filters['date_from'] ?? '';
        $filter_date_to = $filters['date_to'] ?? '';

        $where_clauses = ["1=1"];
        $params = [];

        if (!empty($search_product)) {
            $searchWhere = SearchService::buildSearchWhere($search_product, 'p', false);
            $where_clauses[] = $searchWhere['where'];
            $params = array_merge($params, $searchWhere['params']);
        }

        if ($filter_type === 'purchase') {
            $where_clauses[] = "(ph.old_purchase_price != ph.new_purchase_price)";
        } elseif ($filter_type === 'selling') {
            $where_clauses[] = "(ph.old_selling_price != ph.new_selling_price)";
        }

        if (!empty($filter_date_from)) {
            $where_clauses[] = "DATE(ph.changed_at) >= ?";
            $params[] = $filter_date_from;
        }

        if (!empty($filter_date_to)) {
            $where_clauses[] = "DATE(ph.changed_at) <= ?";
            $params[] = $filter_date_to;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $price_logs = [];
        $total_records = 0;
        $total_pages = 0;
        $offset = ($page - 1) * $perPage;
        $total_changes = 0;
        $purchase_price_changes = 0;
        $selling_price_changes = 0;
        $last_change = null;

        try {
            $countResult = fetchOne("SELECT COUNT(*) as total FROM price_history ph JOIN products p ON ph.product_id = p.id WHERE $where_sql", $params);
            $total_records = $countResult ? intval($countResult['total']) : 0;
            $total_pages = max(1, ceil($total_records / $perPage));

            $statsResult = fetchOne(
                "SELECT COUNT(*) as total_changes,
                        SUM(CASE WHEN ph.old_purchase_price != ph.new_purchase_price THEN 1 ELSE 0 END) as purchase_changes,
                        SUM(CASE WHEN ph.old_selling_price != ph.new_selling_price THEN 1 ELSE 0 END) as selling_changes,
                        MAX(ph.changed_at) as last_change
                 FROM price_history ph JOIN products p ON ph.product_id = p.id WHERE $where_sql",
                $params
            );
            if ($statsResult) {
                $total_changes = intval($statsResult['total_changes'] ?? 0);
                $purchase_price_changes = intval($statsResult['purchase_changes'] ?? 0);
                $selling_price_changes = intval($statsResult['selling_changes'] ?? 0);
                $last_change = $statsResult['last_change'] ?? null;
            }

            $price_logs = fetchAll(
                "SELECT ph.*, p.name as product_name, p.code
                 FROM price_history ph JOIN products p ON ph.product_id = p.id
                 WHERE $where_sql
                 ORDER BY ph.changed_at DESC
                 LIMIT $perPage OFFSET $offset",
                $params
            );
        } catch (\Exception $e) {
            self::logError($e, 'getPriceHistory');
        }

        return [
            'logs' => $price_logs,
            'total' => $total_records,
            'total_pages' => $total_pages,
            'total_changes' => $total_changes,
            'purchase_changes' => $purchase_price_changes,
            'selling_changes' => $selling_price_changes,
            'last_change' => $last_change,
            'offset' => $offset,
        ];
    }

    /**
     * جلب بيانات عرض تفاصيل المنتج الكاملة
     *
     * @param int $id معرف المنتج
     * @return array|null
     */
    public static function getProductViewData(int $id): ?array
    {
        $product = self::getById($id);
        if (!$product) return null;

        // صور المنتج
        $product['images'] = [];
        $product['primary_image'] = null;
        try {
            if (file_exists(__DIR__ . '/../ProductImageService.php')) {
                require_once __DIR__ . '/../ProductImageService.php';
                $product['images'] = ProductImageService::getActiveImages($id);
                $product['primary_image'] = ProductImageService::getPrimaryImage($id);
            }
        } catch (\Exception $e) { self::logError($e, 'getProductViewData(images)'); }

        // مشتريات ومبيعات حديثة
        try {
            $product['recent_purchases'] = fetchAll("SELECT * FROM purchases WHERE product_id = ? ORDER BY purchase_date DESC LIMIT 10", [$id]);
        } catch (\Exception $e) { self::logError($e, 'getProductViewData(purchases)'); $product['recent_purchases'] = []; }

        try {
            $product['recent_sales'] = fetchAll("SELECT * FROM sales WHERE product_id = ? ORDER BY sale_date DESC LIMIT 10", [$id]);
        } catch (\Exception $e) { self::logError($e, 'getProductViewData(sales)'); $product['recent_sales'] = []; }

        // دفعات الشراء المتوفرة
        try {
            $product['available_batches'] = fetchAll(
                "SELECT * FROM purchase_batches WHERE product_id = ? AND remaining_quantity > 0 ORDER BY expiry_date ASC, id ASC",
                [$id]
            );
        } catch (\Exception $e) { self::logError($e, 'getProductViewData(batches)'); $product['available_batches'] = []; }

        // إجمالي المباع
        try {
            $salesSum = fetchOne("SELECT COALESCE(SUM(quantity), 0) as total_sold FROM sales WHERE product_id = ?", [$id]);
            $product['total_sold'] = $salesSum ? floatval($salesSum['total_sold']) : 0;
        } catch (\Exception $e) { $product['total_sold'] = 0; }

        // إجمالي المشتري
        try {
            $purchSum = fetchOne("SELECT COALESCE(SUM(quantity), 0) as total_purchased FROM purchases WHERE product_id = ?", [$id]);
            $product['total_purchased'] = $purchSum ? floatval($purchSum['total_purchased']) : 0;
        } catch (\Exception $e) { $product['total_purchased'] = 0; }

        return $product;
    }

    // ====================================================================
    //  طرق خاصة (private helpers)
    // ====================================================================

    private static function validateProductData(array $data, ?int $excludeId = null): array|bool
    {
        $errors = [];

        if (empty(trim($data['name'] ?? ''))) $errors[] = 'اسم المنتج مطلوب';
        if (floatval($data['purchase_price'] ?? 0) <= 0) $errors[] = 'سعر الشراء يجب أن يكون أكبر من صفر';
        if (floatval($data['selling_price'] ?? 0) <= 0) $errors[] = 'سعر البيع يجب أن يكون أكبر من صفر';

        $purchasePrice = floatval($data['purchase_price'] ?? 0);
        $sellingPrice = floatval($data['selling_price'] ?? 0);
        if ($purchasePrice > 0 && $sellingPrice > 0 && $sellingPrice < $purchasePrice) {
            $errors[] = 'سعر البيع (' . number_format($sellingPrice, 2) . ') لا يمكن أن يكون أقل من سعر الشراء (' . number_format($purchasePrice, 2) . ')';
        }

        if (empty(trim($data['unit'] ?? ''))) $errors[] = 'وحدة القياس مطلوبة';
        if (isset($data['quantity']) && floatval($data['quantity']) < 0) $errors[] = 'الكمية لا يمكن أن تكون سالبة';

        $code = trim($data['code'] ?? '');
        if (!empty($code)) {
            if ($excludeId) {
                $existing = fetchOne("SELECT id FROM products WHERE code = ? AND id != ?", [$code, $excludeId]);
            } else {
                $existing = fetchOne("SELECT id FROM products WHERE code = ?", [$code]);
            }
            if ($existing) $errors[] = 'كود المنتج موجود مسبقاً';
        }

        if (!empty($data['has_expiry']) && !empty($data['quantity']) && floatval($data['quantity']) > 0) {
            $expiry_dates = $data['expiry_dates'] ?? [];
            if (!empty($expiry_dates)) {
                $total_expiry_qty = 0;
                foreach ($expiry_dates as $ed) {
                    $ed_qty = floatval($ed['quantity'] ?? 0);
                    if (!empty(trim($ed['date'] ?? '')) && $ed_qty > 0) $total_expiry_qty += $ed_qty;
                }
                $quantity = floatval($data['quantity']);
                if ($total_expiry_qty > 0 && abs($total_expiry_qty - $quantity) > 0.001) {
                    $errors[] = 'مجموع كميات تواريخ الانتهاء (' . number_format($total_expiry_qty, 2) . ') يجب أن يساوي الكمية الإجمالية (' . number_format($quantity, 2) . ')';
                }
            }
        }

        if (!empty($data['custom_unit_name']) && floatval($data['custom_unit_conversion'] ?? 0) <= 0) {
            $errors[] = 'معامل التحويل يجب أن يكون أكبر من صفر';
        }

        return empty($errors) ? true : $errors;
    }

    private static function logPriceChange(int $productId, float $oldPurchasePrice, float $oldSellingPrice, float $newPurchasePrice, float $newSellingPrice, string $changeReason = ''): void
    {
        try {
            executeQuery(
                "INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$productId, $oldPurchasePrice, $newPurchasePrice, $oldSellingPrice, $newSellingPrice, $changeReason ?: null]
            );
        } catch (\Exception $e) { self::logError($e, 'logPriceChange'); }
    }

    private static function generateProductCode(): string
    {
        $code = 'PRD-001';
        try {
            $max_num = fetchOne("SELECT MAX(CAST(SUBSTRING(code, 5) AS INTEGER)) as max_num FROM products WHERE code LIKE 'PRD-%'");
            if ($max_num && $max_num['max_num'] !== null) {
                $next_num = intval($max_num['max_num']) + 1;
                $code = 'PRD-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
            }
            while (fetchOne("SELECT id FROM products WHERE code = ?", [$code])) {
                $next_num = intval(substr($code, 4)) + 1;
                $code = 'PRD-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
            }
        } catch (\Exception $e) { self::logError($e, 'generateProductCode'); }
        return $code;
    }
}