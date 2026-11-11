<?php
require_once '../includes/bootstrap.php';
$page_title = t('purchases_register_new');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('purchases.add');

// Handle redirect messages
$delete_msg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'purchase_deleted') {
    $delete_msg = t('purchases_deleted_success');
}
$edit_msg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'purchase_edited') {
    $edit_msg = t('purchases_updated_success');
}

// جلب إعدادات الضرائب الافتراضية
$purchase_tax_enabled = getSetting('purchase_tax_enabled', '0');
$purchase_tax_default_name = getSetting('purchase_tax_default_name', 'حقوق الثَمْبر');
$purchase_tax_default_rate = getSetting('purchase_tax_default_rate', '0.50');
$purchase_tax_default_type = getSetting('purchase_tax_default_type', 'percentage');

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    // === البيانات المشتركة ===
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    // === بيانات المنتجات (مصفوفات) ===
    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $purchase_prices = $_POST['purchase_prices'] ?? [];
    $purchase_unit_factors = $_POST['purchase_unit_factors'] ?? [];

    // === بيانات التخفيضات ===
    $remise_types = $_POST['remise_type'] ?? [];
    $remise_product_ids = $_POST['remise_product_id'] ?? [];
    $remise_quantities = $_POST['remise_quantity'] ?? [];
    $remise_amounts = $_POST['remise_amount'] ?? [];
    $remise_descriptions = $_POST['remise_description'] ?? [];

    // === بيانات الضرائب ===
    $tax_enabled = isset($_POST['tax_enabled']) ? true : false;
    $tax_name = trim($_POST['tax_name'] ?? '');
    $tax_type = $_POST['tax_type'] ?? 'percentage';
    $tax_value = floatval($_POST['tax_value'] ?? 0);

    // التحقق من بيانات الضرائب
    if ($tax_enabled && $tax_value <= 0) {
        $errors[] = 'يرجى إدخال قيمة الضريبة أو تعطيلها';
    }
    if ($tax_enabled && empty($tax_name)) {
        $errors[] = 'يرجى إدخال اسم الضريبة';
    }
    if (!in_array($tax_type, ['percentage', 'fixed'])) {
        $tax_type = 'percentage';
    }

    // === بيانات تخفيضات المنتجات (لكل منتج) ===
    $prod_remise_types = $_POST['prod_remise_type'] ?? [];
    $prod_remise_product_ids = $_POST['prod_remise_product_id'] ?? [];
    $prod_remise_quantities = $_POST['prod_remise_quantity'] ?? [];
    $prod_remise_amounts = $_POST['prod_remise_amount'] ?? [];
    $prod_remise_descriptions = $_POST['prod_remise_description'] ?? [];

    // === التحقق من وجود منتجات ===
    $valid_count = 0;
    foreach ($product_ids as $idx => $pid) {
        $pid = intval($pid);
        $qty = floatval($quantities[$idx] ?? 0);
        $price = floatval($purchase_prices[$idx] ?? 0);
        if ($pid > 0 && $qty > 0 && $price > 0) {
            $valid_count++;
        }
    }

    if ($valid_count === 0) {
        $errors[] = 'يرجى إضافة منتج واحد على الأقل مع بيانات صحيحة';
    }

    if (empty($purchase_date)) {
        $errors[] = t('purchases_date_required');
    }

    // === التحقق من كل منتج ===
    $products_data = [];
    if (empty($errors)) {
        foreach ($product_ids as $idx => $pid) {
            $pid = intval($pid);
            $qty = floatval($quantities[$idx] ?? 0);
            $price = floatval($purchase_prices[$idx] ?? 0);
            $factor = floatval($purchase_unit_factors[$idx] ?? 1);

            if ($pid <= 0 || $qty <= 0 || $price <= 0) continue;

            // التحقق من وجود المنتج
            $prod = fetchOne("SELECT id, name, code, unit, has_expiry FROM products WHERE id = ?", [$pid]);
            if (!$prod) {
                $errors[] = 'المنتج ذو المعرف ' . $pid . ' غير موجود';
                continue;
            }

            // حساب الكمية الأساسية
            $base_qty = $qty;
            if ($factor > 0 && $factor != 1) {
                $base_qty = $qty * $factor;
            }

            $has_expiry = intval($prod['has_expiry']);
            $expiry_entries = [];

            // التحقق من تواريخ الانتهاء
            if ($has_expiry) {
                $exp_dates = $_POST['expiry_dates'][$idx] ?? [];
                $exp_quantities = $_POST['expiry_quantities'][$idx] ?? [];

                // === تصحيح: إذا لم يتم العثور بالـ $idx، جرب البحث بالسلسلة أو بالأرقام الصحيحة ===
                if (empty($exp_dates) && isset($_POST['expiry_dates'])) {
                    // أحياناً يختلف النوع (string vs int) بين مفتاح product_ids و expiry_dates
                    foreach ($_POST['expiry_dates'] as $ek => $ev) {
                        if ((string)$ek === (string)$idx) {
                            $exp_dates = $ev;
                            $exp_quantities = $_POST['expiry_quantities'][$ek] ?? [];
                            break;
                        }
                    }
                }

                for ($ei = 0; $ei < count($exp_dates); $ei++) {
                    $ed = trim($exp_dates[$ei] ?? '');
                    $eq = floatval($exp_quantities[$ei] ?? 0);
                    if (!empty($ed) && $eq > 0) {
                        $expiry_entries[] = ['date' => $ed, 'quantity' => $eq];
                    }
                }
                if (empty($expiry_entries)) {
                    $debug_keys = isset($_POST['expiry_dates']) ? implode(', ', array_map('strval', array_keys($_POST['expiry_dates']))) : 'لا يوجد';
                    $debug_dates = isset($_POST['expiry_dates'][$idx]) ? json_encode($_POST['expiry_dates'][$idx]) : 'غير موجود بالمفتاح ' . $idx;
                    $errors[] = 'يجب إضافة تاريخ انتهاء صلاحية واحد على الأقل للمنتج: ' . $prod['name'] . ' [مفاتيح: ' . $debug_keys . ' | تواريخ ' . $idx . ': ' . $debug_dates . ']';
                } else {
                    $total_exp_qty = array_sum(array_column($expiry_entries, 'quantity'));
                    if (abs($total_exp_qty - $base_qty) > 0.001) {
                        $errors[] = 'المنتج ' . $prod['name'] . ': مجموع كميات تواريخ الانتهاء (' . number_format($total_exp_qty, 2) . ') يجب أن يساوي الكمية (' . number_format($base_qty, 2) . ')';
                    }
                }
            }

            $products_data[] = [
                'idx' => $idx,
                'id' => $pid,
                'name' => $prod['name'],
                'code' => $prod['code'],
                'unit' => $prod['unit'],
                'display_qty' => $qty,
                'base_qty' => $base_qty,
                'price' => $price,
                'factor' => $factor,
                'has_expiry' => $has_expiry,
                'expiry_entries' => $expiry_entries,
            ];
        }
    }

    // فحص هل جدول purchase_remises موجود
    $remises_table_exists = false;
    try {
        $pdo_check = AppDatabase::getInstance()->getConnection();
        $check_result = $pdo_check->query("SHOW TABLES LIKE 'purchase_remises'");
        $remises_table_exists = ($check_result && $check_result->rowCount() > 0);
    } catch (\Throwable $e) {
        $remises_table_exists = false;
    }

    // === معالجة البيانات في معاملة واحدة ===
    if (empty($errors) && !empty($products_data)) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            // === المرحلة 1: حساب الإجماليات قبل إنشاء أي سجل ===
            $gross_total = 0;
            $total_prod_financial_remise = 0;
            foreach ($products_data as $pd) {
                $gross_total += $pd['display_qty'] * $pd['price'];
                $pr_types_calc = $prod_remise_types[$pd['idx']] ?? [];
                foreach ($pr_types_calc as $pri => $pr_type) {
                    if ($pr_type === 'financial') {
                        $total_prod_financial_remise += floatval($prod_remise_amounts[$pd['idx']][$pri] ?? 0);
                    }
                }
            }
            $total_financial_remise = 0;
            foreach ($remise_types as $ri => $type) {
                if ($type === 'financial') {
                    $total_financial_remise += floatval($remise_amounts[$ri] ?? 0);
                }
            }
            $all_financial_remise = $total_financial_remise + $total_prod_financial_remise;
            $net_total = max(0, $gross_total - $all_financial_remise);
            $order_tax_amount = 0;
            if ($tax_enabled && $tax_value > 0) {
                if ($tax_type === 'percentage') {
                    $order_tax_amount = round($net_total * ($tax_value / 100), 2);
                } else {
                    $order_tax_amount = $tax_value;
                }
            }
            $order_grand_total = round($net_total + $order_tax_amount, 2);

            // === المرحلة 2: إنشاء فاتورة الشراء ===
            $order_id = null;
            try {
                $pdo->prepare("INSERT INTO purchase_orders (total, financial_discount, net_total, tax_rate, tax_amount, tax_name, grand_total, purchase_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$gross_total, $all_financial_remise, $net_total, $tax_enabled ? $tax_value : 0, $order_tax_amount, $tax_enabled ? $tax_name : null, $order_grand_total, $purchase_date, $notes]);
                $order_id = $pdo->lastInsertId();
            } catch (\Throwable $e) {
                $order_id = null;
            }

            // === المرحلة 3: إنشاء سطور المنتجات ===
            $first_purchase_id = null;
            $purchase_details = [];

            foreach ($products_data as $pd) {
                // حساب سعر الوحدة الأساسية: إذا كانت الوحدة المختارة ليست الأساسية، نحوّل السعر
                $base_unit_price = $pd['price'];
                if ($pd['factor'] > 0 && $pd['factor'] != 1) {
                    $base_unit_price = round($pd['price'] / $pd['factor'], 6);
                }

                // الإجمالي = الكمية بالوحدة المختارة × السعر بالوحدة المختارة
                // أو ما يعادلها: الكمية الأساسية × سعر الوحدة الأساسية
                $line_total = $pd['display_qty'] * $pd['price'];

                // حساب تخفيض المنتج المالي
                $prod_fin_remise = 0;
                $pr_types = $prod_remise_types[$pd['idx']] ?? [];
                foreach ($pr_types as $pri => $pr_type) {
                    if ($pr_type === 'financial') {
                        $prod_fin_remise += floatval($prod_remise_amounts[$pd['idx']][$pri] ?? 0);
                    }
                }

                // المبلغ الصافي لكل منتج (توزيع الخصم المالي بالتناسب)
                $net_line_total = $line_total;
                if ($all_financial_remise > 0 && $gross_total > 0) {
                    $net_line_total = $line_total - ($line_total / max($gross_total, 0.01)) * $all_financial_remise;
                    $net_line_total = max(0, $net_line_total);
                }

                // جلب الأسعار الحالية
                $oldPricesStmt = $pdo->prepare("SELECT purchase_price, selling_price FROM products WHERE id = ?");
                $oldPricesStmt->execute([$pd['id']]);
                $old_prices = $oldPricesStmt->fetch(PDO::FETCH_ASSOC);

                // حساب حصة الضريبة لهذا المنتج (بالتناسب مع الإجمالي)
                $tax_rate_for_product = 0;
                $tax_amount_for_product = 0;
                $tax_name_for_product = null;
                if ($tax_enabled && $tax_value > 0) {
                    if ($tax_type === 'percentage') {
                        $tax_rate_for_product = $tax_value;
                        $tax_amount_for_product = $net_line_total * ($tax_value / 100);
                    } else {
                        $tax_rate_for_product = $gross_total > 0 ? ($tax_value / $gross_total) * 100 : 0;
                        $tax_amount_for_product = $gross_total > 0 ? ($line_total / $gross_total) * $tax_value : 0;
                    }
                    $tax_name_for_product = $tax_name;
                }

                // حساب الإجمالي النهائي (صافي + ضريبة)
                $grand_total_for_product = $net_line_total + $tax_amount_for_product;

                // حساب السعر الفعلي بالوحدة (بعد الخصم والضريبة)
                $actual_unit_price = $base_unit_price;
                if ($pd['base_qty'] > 0 && ($all_financial_remise > 0 || $tax_amount_for_product > 0)) {
                    $actual_unit_price = round(($net_line_total + $tax_amount_for_product) / $pd['base_qty'], 6);
                }

                // إدراج سجل الشراء مع order_id
                $stmt = $pdo->prepare("INSERT INTO purchases (order_id, product_id, quantity, purchase_price, total, tax_rate, tax_amount, tax_name, grand_total, purchase_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $pd['id'], $pd['base_qty'], $base_unit_price, $net_line_total, $tax_rate_for_product, $tax_amount_for_product, $tax_name_for_product, $grand_total_for_product, $purchase_date, $notes]);
                $purchase_id = $pdo->lastInsertId();

                if ($first_purchase_id === null) {
                    $first_purchase_id = $purchase_id;
                }

                // إنشاء دفعات الشراء (السعر بالوحدة الأساسية + السعر الفعلي)
                try {
                    $batchStmt = $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, actual_purchase_price, purchase_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($pd['has_expiry'] && !empty($pd['expiry_entries'])) {
                        foreach ($pd['expiry_entries'] as $ed) {
                            $batchStmt->execute([$pd['id'], $purchase_id, $ed['quantity'], $ed['quantity'], $base_unit_price, $actual_unit_price, $purchase_date, $ed['date']]);
                        }
                    } else {
                        $batchStmt->execute([$pd['id'], $purchase_id, $pd['base_qty'], $pd['base_qty'], $base_unit_price, $actual_unit_price, $purchase_date, null]);
                    }
                } catch (Exception $e) {}

                // تحديث كمية المنتج وسعر الشراء (السعر بالوحدة الأساسية)
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ?, purchase_price = ? WHERE id = ?");
                $stmt->execute([$pd['base_qty'], $base_unit_price, $pd['id']]);

                // تسجيل تغيير السعر
                try {
                    $old_purchase = floatval($old_prices['purchase_price']);
                    $old_selling = floatval($old_prices['selling_price']);
                    if ($base_unit_price != $old_purchase) {
                        $historyStmt = $pdo->prepare("INSERT INTO price_history (product_id, old_purchase_price, new_purchase_price, old_selling_price, new_selling_price, change_reason) VALUES (?, ?, ?, ?, ?, ?)");
                        $historyStmt->execute([$pd['id'], $old_purchase, $base_unit_price, $old_selling, $old_selling, 'تسجيل عملية شراء جديدة - سعر الشراء: ' . number_format($base_unit_price, 2) . ' بالوحدة الأساسية']);
                    }
                } catch (Exception $e) {}

                $purchase_details[] = [
                    'id' => $purchase_id,
                    'name' => $pd['name'],
                    'display_qty' => $pd['display_qty'],
                    'unit' => $pd['unit'],
                    'total' => $net_line_total,
                ];

                // === معالجة تخفيضات المنتج (لكل منتج) — فقط إذا جدول purchase_remises موجود ===
                if ($remises_table_exists) {
                    foreach ($pr_types as $pri => $pr_type) {
                        if ($pr_type === 'material') {
                            $pr_prod_id = intval($prod_remise_product_ids[$pd['idx']][$pri] ?? 0);
                            $pr_qty = floatval($prod_remise_quantities[$pd['idx']][$pri] ?? 0);
                            $pr_desc = trim($prod_remise_descriptions[$pd['idx']][$pri] ?? '');
                            if ($pr_prod_id > 0 && $pr_qty > 0) {
                                try {
                                    $pdo->prepare("INSERT INTO purchase_remises (purchase_id, type, product_id, quantity, unit_price, description) VALUES (?, ?, ?, ?, ?, ?)")
                                        ->execute([$purchase_id, $order_id, 'material', $pr_prod_id, $pr_qty, 0, $pr_desc]);
                                } catch (\Throwable $e) {}
                                $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                                    ->execute([$pr_qty, $pr_prod_id]);

                                // إنشاء دفعة شراء بسعر 0 للمنتج المجاني
                                try {
                                    $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, actual_purchase_price, purchase_date, expiry_date) VALUES (?, ?, ?, ?, 0, 0, ?, NULL)")
                                        ->execute([$pr_prod_id, $purchase_id, $pr_qty, $pr_qty, $purchase_date]);
                                } catch (\Throwable $e) {}
                            }
                        } elseif ($pr_type === 'financial') {
                            $pr_amount = floatval($prod_remise_amounts[$pd['idx']][$pri] ?? 0);
                            $pr_desc = trim($prod_remise_descriptions[$pd['idx']][$pri] ?? '');
                            if ($pr_amount > 0) {
                                try {
                                    $pdo->prepare("INSERT INTO purchase_remises (purchase_id, type, amount, description) VALUES (?, ?, ?, ?)")
                                        ->execute([$purchase_id, 'financial', $pr_amount, $pr_desc]);
                                } catch (\Throwable $e) {}
                            }
                        }
                    }
                }
            }

            // === معالجة التخفيضات العامة على الفاتورة ===
            if ($remises_table_exists) {
                foreach ($remise_types as $ri => $type) {
                    if ($type === 'material') {
                        $remise_prod_id = intval($remise_product_ids[$ri] ?? 0);
                        $remise_qty = floatval($remise_quantities[$ri] ?? 0);
                        $remise_desc = trim($remise_descriptions[$ri] ?? '');

                        if ($remise_prod_id > 0 && $remise_qty > 0) {
                            try {
                                $pdo->prepare("INSERT INTO purchase_remises (purchase_id, order_id, type, product_id, quantity, unit_price, description) VALUES (?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$first_purchase_id, $order_id, 'material', $remise_prod_id, $remise_qty, 0, $remise_desc]);
                            } catch (\Throwable $e) {}

                            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
                                ->execute([$remise_qty, $remise_prod_id]);

                            // إنشاء دفعة شراء بسعر 0 للمنتج المجاني
                            try {
                                $pdo->prepare("INSERT INTO purchase_batches (product_id, purchase_id, original_quantity, remaining_quantity, purchase_price, actual_purchase_price, purchase_date, expiry_date) VALUES (?, ?, ?, ?, 0, 0, ?, NULL)")
                                    ->execute([$remise_prod_id, $first_purchase_id, $remise_qty, $remise_qty, $purchase_date]);
                            } catch (\Throwable $e) {}
                        
                        }

                    } elseif ($type === 'financial') {
                        $remise_amount = floatval($remise_amounts[$ri] ?? 0);
                        $remise_desc = trim($remise_descriptions[$ri] ?? '');

                        if ($remise_amount > 0) {
                            try {
                                $pdo->prepare("INSERT INTO purchase_remises (purchase_id, order_id, type, amount, description) VALUES (?, ?, ?, ?, ?)")
                                    ->execute([$first_purchase_id, $order_id, 'financial', $remise_amount, $remise_desc]);
                            } catch (\Throwable $e) {}
                        }
                    }
                }
            }

            $pdo->commit();
            $success = true;

            try {
                $product_count = count($purchase_details);
                $totalFormatted = getCurrencySymbol() . number_format($order_grand_total, 2);
                $names = [];
                foreach ($purchase_details as $pd) {
                    $names[] = $pd['name'];
                }
                $namesStr = implode('، ', array_slice($names, 0, 5));
                if ($product_count > 5) $namesStr .= ' و ' . ($product_count - 5) . ' منتجات أخرى';
                $summaryMsg = "شراء {$product_count} منتج ({$namesStr}) بإجمالي {$totalFormatted}";
                AppNotification::add(NOTIF_PURCHASE, t('notif_title_new_purchase'), t('notif_msg_new_purchase', $product_count, $totalFormatted), ['url' => '../purchases/add-purchase.php', 'purchase_id' => $first_purchase_id], null, 'notif_title_new_purchase', 'notif_msg_new_purchase', ['count' => $product_count, 'total' => $totalFormatted]);
            } catch (Exception $e) {}

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Purchase Add Error: ' . $e->getMessage());
            $errors[] = t('purchases_error_saving');
        }
    }
}

// سجل المشتريات الأخيرة — مجمّع حسب فاتورة الشراء
$recent_purchases = [];
try {
    $recent_purchases = fetchAll(
        "SELECT p.*, pr.name as product_name, pr.code, pr.unit,
                po.id as order_id, po.grand_total as order_grand_total,
                po.financial_discount as order_discount, po.tax_amount as order_tax,
                po.tax_name as order_tax_name
         FROM purchases p 
         JOIN products pr ON p.product_id = pr.id 
         LEFT JOIN purchase_orders po ON p.order_id = po.id
         ORDER BY p.created_at DESC LIMIT 30"
    );
} catch (Exception $e) {}

// جلب التخفيضات لكل فاتورة
$order_remises = [];
$purchase_remises = [];
if (!empty($recent_purchases)) {
    $pids = array_column($recent_purchases, 'id');
    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    try {
        $remise_rows = fetchAll(
            "SELECT pr.*, p2.name as remise_product_name, p2.code as remise_product_code
             FROM purchase_remises pr 
             LEFT JOIN products p2 ON pr.product_id = p2.id
             WHERE pr.purchase_id IN ($placeholders) 
             ORDER BY pr.id", $pids
        );
        foreach ($remise_rows as $r) {
            $purchase_remises[$r['purchase_id']][] = $r;
            // Group by order_id
            $oid = $r['order_id'] ?? $r['purchase_id'];
            $order_remises[$oid][] = $r;
        }
    } catch (Exception $e) {}
}

// تجميع المشتريات حسب الفاتورة
$grouped_purchases = [];
foreach ($recent_purchases as $p) {
    $key = $p['order_id'] ? 'order_' . $p['order_id'] : 'single_' . $p['id'];
    $grouped_purchases[$key]['order_id'] = $p['order_id'];
    $grouped_purchases[$key]['order_grand_total'] = $p['order_grand_total'];
    $grouped_purchases[$key]['order_discount'] = $p['order_discount'];
    $grouped_purchases[$key]['order_tax'] = $p['order_tax'];
    $grouped_purchases[$key]['order_tax_name'] = $p['order_tax_name'];
    $grouped_purchases[$key]['purchase_date'] = $p['purchase_date'];
    $grouped_purchases[$key]['notes'] = $p['notes'];
    $grouped_purchases[$key]['items'][] = $p;
}
$grouped_purchases = array_slice($grouped_purchases, 0, 15, true);

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once '../includes/header.php';
?>
<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
            <?php echo t('purchases_register_new'); ?>
        </h1>
        <a href="list-purchases.php" class="btn btn-outline">سجل المشتريات</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo t('purchases_submitted'); ?></div>
    <?php endif; ?>

    <?php if (!empty($delete_msg)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($delete_msg); ?></div>
    <?php endif; ?>

    <?php if (!empty($edit_msg)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($edit_msg); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>تسجيل عملية شراء جديدة (عدة منتجات)</h2></div>
        <div class="card-body">
            <form method="POST" action="" id="purchaseForm" onsubmit="return validateForm()">
                <div class="alert alert-info">
                    <strong>ملاحظة:</strong> يمكنك إضافة عدة منتجات في عملية شراء واحدة. يتم تحديث المخزون تلقائياً وإنشاء دفعات (Batches) لكل منتج.
                </div>

                <!-- أزرار المسح بالباركود والإضافة اليدوية -->
                <div class="purchase-buttons-row">
                    <button type="button" onclick="openBarcodeScanner()" class="btn btn-lg purchase-barcode-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                            <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                            <line x1="7" y1="12" x2="17" y2="12"/>
                        </svg>
                        <?php echo t('barcode_scanning'); ?>
                    </button>
                    <button type="button" onclick="addProductRow()" class="btn btn-lg purchase-add-manual-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        إضافة منتج يدوياً
                    </button>
                </div>

                <!-- === القسم المشترك: التاريخ والملاحظات === -->
                <div class="form-grid" style="margin-bottom: 1rem;">
                    <div class="form-group">
                        <label><?php echo t('purchases_date'); ?> <span class="required">*</span></label>
                        <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo t('notes'); ?></label>
                        <input type="text" name="notes" class="form-control" placeholder="ملاحظات إضافية (اختياري)">
                    </div>
                </div>

                <!-- === حاوية صفوف المنتجات === -->
                <div id="productRows">
                    <!-- يتم إضافة صفوف المنتجات هنا ديناميكياً -->
                </div>

                <!-- زر إضافة صف منتج (أصغر) -->
                <div style="margin: 1rem 0;">
                    <button type="button" onclick="addProductRow()" class="btn purchase-add-row-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        + إضافة صف منتج آخر
                    </button>
                </div>

                <!-- === ملخص === -->
                <div id="purchaseSummary" class="purchase-summary-box" style="border-color: #2563eb; background: linear-gradient(135deg, #f8fafc, #f1f5f9);">
                    <div class="purchase-summary-grid">
                        <div class="purchase-summary-item">
                            <div class="purchase-summary-label">عدد المنتجات</div>
                            <div id="summaryProductCount" class="purchase-summary-value" style="color: var(--primary, #2563eb);">0</div>
                        </div>
                        <div class="purchase-summary-item">
                            <div class="purchase-summary-label">الإجمالي قبل الخصم</div>
                            <div id="summaryGrossTotal" class="purchase-summary-value purchase-summary-value--total">0.00</div>
                        </div>
                        <div class="purchase-summary-item" id="summaryRemiseDiv" style="display:none;">
                            <div class="purchase-summary-label">التخفيض المالي</div>
                            <div id="summaryFinancialRemise" class="purchase-summary-value" style="color: #d97706;">0.00</div>
                        </div>
                        <div class="purchase-summary-item">
                            <div class="purchase-summary-label">الصافي</div>
                            <div id="summaryNetTotal" class="purchase-summary-value purchase-summary-value--profit">0.00</div>
                        </div>
                        <div class="purchase-summary-item" id="summaryTaxDiv" style="display:none;">
                            <div class="purchase-summary-label"><span id="summaryTaxName">الضريبة</span></div>
                            <div id="summaryTaxAmount" class="purchase-summary-value" style="color: #4f46e5;">0.00</div>
                        </div>
                        <div class="purchase-summary-item" id="summaryGrandTotalDiv" style="display:none;">
                            <div class="purchase-summary-label">الإجمالي مع الضريبة</div>
                            <div id="summaryGrandTotal" class="purchase-summary-value" style="color: #7c3aed; font-size: 1.4rem;">0.00</div>
                        </div>
                    </div>
                </div>

                <!-- ===== قسم الضرائب (حقوق الثَمْبر) ===== -->
                <div id="taxSection" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px dashed #c7d2fe;">
                    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                        <h3 style="margin: 0; font-size: 1.1rem; color: var(--gray-700);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                            </svg>
                            الضرائب — <span style="font-size: 0.8rem; color: var(--gray-400);">اختياري</span>
                        </h3>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 6px 14px; border: 2px solid <?php echo $purchase_tax_enabled == '1' ? '#6366f1' : '#e2e8f0'; ?>; border-radius: 8px; font-weight: 700; font-size: 0.85rem; background: <?php echo $purchase_tax_enabled == '1' ? '#eef2ff' : '#fff'; ?>; transition: all 0.15s;" id="taxToggleLabel">
                            <input type="checkbox" name="tax_enabled" id="tax_enabled_checkbox" <?php echo $purchase_tax_enabled == '1' ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color:#6366f1;" onchange="toggleTaxFields()">
                            تفعيل الضريبة
                        </label>
                    </div>

                    <div id="taxFieldsContainer" style="display: <?php echo $purchase_tax_enabled == '1' ? 'block' : 'none'; ?>;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; align-items: end;">
                            <div>
                                <label style="font-size: 0.78rem; font-weight: 600; color: var(--gray-500); display: block; margin-bottom: 3px;">اسم الضريبة</label>
                                <input type="text" name="tax_name" id="tax_name_input" class="form-control" value="<?php echo htmlspecialchars($purchase_tax_default_name); ?>" placeholder="حقوق الثَمْبر" style="font-weight: 600;">
                            </div>
                            <div>
                                <label style="font-size: 0.78rem; font-weight: 600; color: var(--gray-500); display: block; margin-bottom: 3px;">نوع الضريبة</label>
                                <select name="tax_type" id="tax_type_select" class="form-control" onchange="recalculateTotals()" style="font-weight: 600;">
                                    <option value="percentage" <?php echo $purchase_tax_default_type == 'percentage' ? 'selected' : ''; ?>>نسبة مئوية (%)</option>
                                    <option value="fixed" <?php echo $purchase_tax_default_type == 'fixed' ? 'selected' : ''; ?>>مبلغ ثابت</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.78rem; font-weight: 600; color: var(--gray-500); display: block; margin-bottom: 3px;" id="tax_value_label"><?php echo $purchase_tax_default_type == 'percentage' ? 'النسبة (%)' : 'المبلغ'; ?></label>
                                <input type="number" name="tax_value" id="tax_value_input" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($purchase_tax_default_rate); ?>" placeholder="0.00" oninput="recalculateTotals()" style="font-weight: 600;">
                                <small style="color: var(--gray-400); font-size: 0.72rem;" id="tax_value_hint"><?php echo $purchase_tax_default_type == 'percentage' ? 'مثال: 0.50 يعني 0.50%' : 'المبلغ الإجمالي للضريبة'; ?></small>
                            </div>
                        </div>

                        <div id="taxCalculated" style="display:none; margin-top: 0.75rem; padding: 0.6rem 1rem; background: linear-gradient(135deg, #eef2ff, #e0e7ff); border: 1.5px solid #c7d2fe; border-radius: 10px; text-align: center;">
                            <div style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-size: 0.85rem; color: #4338ca; font-weight: 600;" id="taxCalculatedLabel">حقوق الثَمْبر:</span>
                                <span style="font-size: 1.3rem; font-weight: 900; color: #4f46e5;" id="taxCalculatedAmount">0.00</span>
                                <span style="font-size: 0.85rem; color: #6366f1;"><?php echo getCurrencySymbol(); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== قسم التخفيضات (Remise) ===== -->
                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px dashed #e5e7eb;">
                    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                        <h3 style="margin: 0; font-size: 1.1rem; color: var(--gray-700);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="20" height="20" style="vertical-align: middle; margin-left: 4px;">
                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                            </svg>
                            التخفيضات (Remises) — <span style="font-size: 0.8rem; color: var(--gray-400);">اختياري</span>
                        </h3>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button type="button" onclick="addRemiseRow('material')" 
                                    style="background: #f0fdf4; color: #059669; border: 1px solid #86efac; padding: 6px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 0.85rem;">
                                + تخفيض مادي (سلع)
                            </button>
                            <button type="button" onclick="addRemiseRow('financial')" 
                                    style="background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; padding: 6px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 0.85rem;">
                                + تخفيض مالي
                            </button>
                        </div>
                    </div>

                    <div id="remisesContainer"></div>

                    <div id="remiseSummary" style="display: none; margin-top: 0.75rem;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem;">
                            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 0.6rem 0.75rem; text-align: center;">
                                <div style="font-size: 0.75rem; color: var(--gray-500);">🎁 إجمالي التخفيضات المادية</div>
                                <div id="totalMaterialRemise" style="font-weight: 800; color: #059669;">0</div>
                            </div>
                            <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 0.6rem 0.75rem; text-align: center;">
                                <div style="font-size: 0.75rem; color: var(--gray-500);">💰 إجمالي التخفيض المالي</div>
                                <div id="totalFinancialRemise" style="font-weight: 800; color: #d97706;">0.00</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- === زر التسجيل === -->
                <div style="margin-top: 1rem;">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-secondary btn-lg purchase-submit-btn" style="background: linear-gradient(135deg, #2563eb, #3b82f6); color: white; border: none; box-shadow: 0 2px 8px rgba(37,99,235,0.3);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                        <?php echo t('purchases_register'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- سجل المشتريات -->
    <div class="card">
        <div class="card-header"><h2><?php echo t('purchases_recent'); ?></h2></div>
        <div class="card-body">
            <?php if (empty($recent_purchases)): ?>
                <p class="text-muted" style="text-align:center; padding:2rem;"><?php echo t('purchases_no_results'); ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>الضريبة</th>
                            <th>التخفيضات</th>
                            <th>التاريخ</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($recent_purchases as $p): 
                            $p_remises = $purchase_remises[$p['id']] ?? [];
                            $has_material = false;
                            $has_financial = false;
                            $fin_total = 0;
                            $mat_items = [];
                            foreach ($p_remises as $r) {
                                if ($r['type'] === 'material') { $has_material = true; $mat_items[] = $r; }
                                else { $has_financial = true; $fin_total += floatval($r['amount']); }
                            }
                        ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($p['code']); ?></td>
                            <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                            <td><?php echo number_format($p['quantity'], 2); ?></td>
                            <td><?php echo htmlspecialchars($p['unit']); ?></td>
                            <td><?php echo formatMoney($p['purchase_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($p['total']); ?></td>
                            <td style="font-size: 0.8rem;">
                                <?php 
                                $p_tax_amount = isset($p['tax_amount']) ? floatval($p['tax_amount']) : 0;
                                $p_tax_name = isset($p['tax_name']) ? $p['tax_name'] : ''; 
                                if ($p_tax_amount > 0): ?>
                                <div style="background: #eef2ff; border-radius: 4px; padding: 2px 6px;">
                                    <span style="font-size:0.7rem; color:#6366f1;"><?php echo htmlspecialchars($p_tax_name ?: 'ضريبة'); ?></span>
                                    <strong style="color:#4f46e5;">+<?php echo formatMoney($p_tax_amount); ?></strong>
                                </div>
                                <?php else: ?>
                                <span style="color: var(--gray-300);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8rem;">
                                <?php if (empty($p_remises)): ?>
                                    <span style="color: var(--gray-300);">—</span>
                                <?php else: ?>
                                    <?php foreach ($mat_items as $mi): ?>
                                    <div style="background: #f0fdf4; border-radius: 4px; padding: 2px 6px; margin-bottom: 2px;">
                                        🎁 <strong><?php echo number_format($mi['quantity'], 2); ?></strong> 
                                        <?php echo htmlspecialchars($mi['remise_product_name'] ?? ''); ?>
                                        <?php if ($mi['description']): ?>
                                            <span style="color: var(--gray-400); font-size: 0.7rem;">(<?php echo htmlspecialchars($mi['description']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if ($has_financial): ?>
                                    <div style="background: #fef3c7; border-radius: 4px; padding: 2px 6px;">
                                        💰 <strong>-<?php echo formatMoney($fin_total); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $p['purchase_date']; ?></td>
                            <td class="purchase-actions-cell">
                                <a href="edit-purchase.php?id=<?php echo $p['id']; ?>" class="purchase-action-btn--edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    تعديل
                                </a>
                                <a href="delete-purchase.php?id=<?php echo $p['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف عملية الشراء هذه؟
سيتم تقليل المخزون وحذف الدفعات المرتبطة.')" class="purchase-action-btn--delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    حذف
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
const CURRENCY_SYMBOL = '<?php echo getCurrencySymbol(); ?>';
let productRowCounter = 0;
let remiseCounter = 0;

// بيانات صف المنتجات
let productRowsData = {};

// حالة البحث لكل صف
let rowSearchTimeouts = {};
let rowSearchResults = {};
let rowHighlightedIndex = {};
let rowDropdownOpen = {};

// حالة التخفيض

// === كشف المنتج المكرر ===
function isDuplicateProduct(productId, currentIdx) {
    for (const [idx, state] of Object.entries(productRowsData)) {
        if (parseInt(idx) === currentIdx) continue;
        if (state.id === productId) return parseInt(idx);
    }
    return null;
}

function getRowPosition(idx) {
    const container = document.getElementById('productRows');
    const rows = container.querySelectorAll('.product-row');
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].dataset.idx === String(idx)) return i;
    }
    return -1;
}
let remiseSearchTimeouts = {};
let remiseHighlightedIndex = {};
let remiseDropdownOpen = {};

// حالة تخفيضات المنتجات (لكل منتج)
let prodRemiseCounters = {};
let prmSearchTimeouts = {};
let prmHighlightedIndex = {};
let prmDropdownOpen = {};

// ============================================================
// === إضافة صف منتج جديد ===
// ============================================================
function addProductRow() {
    productRowCounter++;
    const idx = productRowCounter;
    productRowsData[idx] = { id: null, name: '', code: '', unit: '', basePrice: 0, hasExpiry: 0, factor: 1, unitName: '' };

    const container = document.getElementById('productRows');
    const row = document.createElement('div');
    row.id = 'productRow_' + idx;
    row.className = 'product-row';

    row.innerHTML = `
        <div class="product-row-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="product-row-number" id="rowNum_${idx}">منتج #1</span>
                <button type="button" onclick="toggleProdRemise(${idx})" 
                        style="background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; padding: 3px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.75rem; display: flex; align-items: center; gap: 4px;" title="تخفيضات هذا المنتج">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                    </svg>
                    تخفيضات
                </button>
            </div>
            <button type="button" class="product-row-remove" onclick="removeProductRow(${idx})" title="حذف المنتج">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                حذف
            </button>
        </div>

        <!-- حقل البحث عن المنتج -->
        <div class="product-row-grid">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>المنتج <span class="required">*</span></label>
                <div class="product-search-wrapper" id="prodSearchWrapper_${idx}">
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="prod_search_${idx}" class="form-control search-input" 
                               placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openRowSearch(${idx})" oninput="searchRowProduct(${idx})" onkeydown="handleRowKeydown(event, ${idx})">
                        <button type="button" class="search-clear-btn" id="prod_clear_${idx}" onclick="clearRowProduct(${idx})" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                        <button type="button" class="search-arrow-btn" id="prod_arrow_${idx}" onclick="toggleRowSearch(${idx})" style="display:none;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <button type="button" class="search-scan-btn" data-scan-target="${idx}" onclick="openBarcodeScanner(this)" title="مسح الباركود بالكاميرا">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                <line x1="7" y1="12" x2="17" y2="12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="selected-product" id="prod_selected_${idx}" style="display:none;">
                        <div class="selected-product-info">
                            <span class="selected-product-code" id="prod_sel_code_${idx}"></span>
                            <span class="selected-product-name" id="prod_sel_name_${idx}"></span>
                            <span class="selected-product-stock" id="prod_sel_stock_${idx}"></span>
                        </div>
                        <button type="button" class="selected-product-remove" onclick="clearRowProduct(${idx})" title="إزالة المنتج">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="search-dropdown" id="prod_dropdown_${idx}" style="display:none;">
                        <div class="search-results" id="prod_results_${idx}">
                            <div class="search-loading" id="prod_loading_${idx}" style="display:none;"><div class="spinner"></div>جاري البحث...</div>
                            <div class="search-no-results" id="prod_noresults_${idx}" style="display:none;">لا توجد نتائج مطابقة</div>
                            <div class="search-hint" id="prod_hint_${idx}">اكتب للبحث عن منتج (كود أو اسم)</div>
                        </div>
                    </div>
                    <input type="hidden" name="product_ids[${idx}]" id="prod_id_${idx}" value="">
                    <input type="hidden" id="prod_base_price_${idx}" value="">
                    <input type="hidden" id="prod_unit_name_${idx}" value="">
                </div>
            </div>
        </div>

        <!-- حقول الكمية والسعر والوحدة -->
        <div class="product-row-fields" id="prod_fields_${idx}" style="display:none;">
            <div class="form-group">
                <label>الكمية <span class="required">*</span></label>
                <input type="number" name="quantities[${idx}]" id="prod_qty_${idx}" class="form-control" step="0.01" min="0.01" value="" placeholder="الكمية" oninput="calculateLineTotal(${idx})" style="font-weight: 600;">
                <div class="form-text" id="prod_unit_label_${idx}"></div>
            </div>
            <div class="form-group" id="prod_unit_group_${idx}" style="display:none;">
                <label>وحدة الشراء</label>
                <select id="prod_unit_select_${idx}" class="form-control" onchange="onRowUnitChange(${idx})">
                    <option value="base">الوحدة الأساسية</option>
                </select>
                <div class="form-text" id="prod_unit_info_${idx}"></div>
            </div>
            <div class="form-group">
                <label>سعر الشراء للوحدة <span class="required">*</span></label>
                <input type="number" name="purchase_prices[${idx}]" id="prod_price_${idx}" class="form-control" step="0.01" min="0.01" value="" placeholder="0.00" oninput="calculateLineTotal(${idx})" style="font-weight: 600;">
            </div>
        </div>

        <!-- حقول خفية -->
        <input type="hidden" name="purchase_unit_factors[${idx}]" id="prod_factor_${idx}" value="1">

        <!-- إجمالي الصف -->
        <div class="product-row-summary" id="prod_summary_${idx}" style="display:none;">
            <div class="form-group">
                <label>إجمالي الصف</label>
                <input type="text" id="prod_line_total_${idx}" class="form-control purchase-line-total-input" value="0.00 <?php echo getCurrencySymbol(); ?>" readonly>
            </div>
            <div class="form-group purchase-align-end"></div>
        </div>

        <!-- قسم تواريخ انتهاء الصلاحية (لكل منتج) -->
        <div id="prod_expiry_section_${idx}" style="display:none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 2px dashed #93c5fd;">
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.95rem; color: #1e40af;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" width="16" height="16" style="vertical-align: middle; margin-left: 3px;">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/>
                    </svg>
                    تواريخ انتهاء الصلاحية
                </h4>
                <button type="button" onclick="addExpiryRow(${idx})"
                        style="background: #eff6ff; color: #2563eb; border: 1px solid #93c5fd; padding: 5px 12px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.8rem;">
                    + إضافة تاريخ
                </button>
            </div>
            <div class="alert alert-info" style="font-size:0.78rem; padding:6px 10px;">
                <strong>ملاحظة:</strong> مجموع كميات جميع التواريخ يجب أن يساوي الكمية بعد تحويل وحدة الشراء
            </div>
            <div id="prod_expiry_container_${idx}"></div>
            <div id="prod_expiry_summary_${idx}" style="display:none; margin-top:0.5rem;">
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:6px; padding:5px 10px; text-align:center;">
                        <div style="font-size:0.7rem; color:var(--gray-500);">مجموع كميات التواريخ</div>
                        <div id="prod_expiry_total_${idx}" style="font-weight:800; color:#2563eb;">0</div>
                    </div>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:5px 10px; text-align:center;">
                        <div style="font-size:0.7rem; color:var(--gray-500);">الكمية المطلوبة</div>
                        <div id="prod_expiry_required_${idx}" style="font-weight:800; color:#1e40af;">0</div>
                    </div>
                    <div id="prod_expiry_diff_box_${idx}" style="border-radius:6px; padding:5px 10px; text-align:center; display:none;">
                        <div style="font-size:0.7rem; color:var(--gray-500);">الفارق</div>
                        <div id="prod_expiry_diff_${idx}" style="font-weight:800; font-size:1rem;">0</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- قسم تخفيضات المنتج (لكل منتج) -->
        <div id="prod_remise_section_${idx}" style="display:none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 2px dashed #fcd34d;">
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.9rem; color: #92400e;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="15" height="15" style="vertical-align: middle; margin-left: 3px;">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                    </svg>
                    تخفيضات هذا المنتج
                </h4>
                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                    <button type="button" onclick="addProdRemiseRow(${idx}, 'material')" 
                            style="background: #f0fdf4; color: #059669; border: 1px solid #86efac; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.75rem;">
                        + تخفيض مادي
                    </button>
                    <button type="button" onclick="addProdRemiseRow(${idx}, 'financial')" 
                            style="background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.75rem;">
                        + تخفيض مالي
                    </button>
                </div>
            </div>
            <div id="prod_remise_container_${idx}"></div>
        </div>
    `;

    container.appendChild(row);
    recalculateTotals();
}

// ============================================================
// === حذف صف منتج ===
// ============================================================
function removeProductRow(idx) {
    const row = document.getElementById('productRow_' + idx);
    if (row) row.remove();
    delete productRowsData[idx];
    recalculateTotals();
    updateRowNumbers();
}

// === تحديث أرقام الصفوف ===
function updateRowNumbers() {
    const rows = document.querySelectorAll('.product-row');
    rows.forEach((row, i) => {
        const numEl = row.querySelector('.product-row-number');
        if (numEl) numEl.textContent = 'منتج #' + (i + 1);
    });
}

// ============================================================
// === البحث عن المنتجات لكل صف ===
// ============================================================
function openRowSearch(idx) {
    const pidInput = document.getElementById('prod_id_' + idx);
    if (pidInput && pidInput.value) return;
    const dropdown = document.getElementById('prod_dropdown_' + idx);
    if (dropdown) { dropdown.style.display = 'flex'; rowDropdownOpen[idx] = true; }
    const input = document.getElementById('prod_search_' + idx);
    if (input && !input.value.trim()) searchRowProduct(idx);
}

function closeRowSearch(idx) {
    const dropdown = document.getElementById('prod_dropdown_' + idx);
    if (dropdown) dropdown.style.display = 'none';
    rowDropdownOpen[idx] = false;
    rowHighlightedIndex[idx] = -1;
}

function searchRowProduct(idx) {
    const input = document.getElementById('prod_search_' + idx);
    if (!input) return;
    const query = input.value.trim();
    const dropdown = document.getElementById('prod_dropdown_' + idx);
    const resultsDiv = document.getElementById('prod_results_' + idx);
    const clearBtn = document.getElementById('prod_clear_' + idx);
    const arrowBtn = document.getElementById('prod_arrow_' + idx);
    if (clearBtn) clearBtn.style.display = query ? 'flex' : 'none';
    if (arrowBtn) arrowBtn.style.display = 'none';
    if (rowSearchTimeouts[idx]) clearTimeout(rowSearchTimeouts[idx]);
    if (dropdown) { dropdown.style.display = 'flex'; rowDropdownOpen[idx] = true; }
    rowHighlightedIndex[idx] = -1;
    rowSearchTimeouts[idx] = setTimeout(function() {
        if (!resultsDiv) return;
        const loading = document.getElementById('prod_loading_' + idx);
        const hint = document.getElementById('prod_hint_' + idx);
        const noResults = document.getElementById('prod_noresults_' + idx);
        if (loading) loading.style.display = 'flex';
        if (hint) hint.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        // إزالة العناصر القديمة
        resultsDiv.querySelectorAll('.row-product-item, .search-dropdown-footer').forEach(el => el.remove());
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (loading) loading.style.display = 'none';
                resultsDiv.querySelectorAll('.row-product-item, .search-dropdown-footer').forEach(el => el.remove());
                var _products = data.products || data.data || [];
                if (_products.length > 0) {
                    rowSearchResults[idx] = _products;
                    _products.forEach(function(product, i) {
                        const item = createRowProductItem(product, query, idx);
                        item.dataset.index = i;
                        item.addEventListener('mouseenter', function() {
                            clearRowHighlight(idx);
                            item.classList.add('keyboard-nav');
                            rowHighlightedIndex[idx] = i;
                        });
                        item.addEventListener('mouseleave', function() {
                            item.classList.remove('keyboard-nav');
                        });
                        resultsDiv.appendChild(item);
                    });
                    // إضافة عداد النتائج
                    if (data.total) {
                        const footer = document.createElement('div');
                        footer.className = 'search-dropdown-footer';
                        footer.textContent = data.total + ' نتيجة';
                        resultsDiv.appendChild(footer);
                    }
                } else {
                    var errorMsg = '';
                    if (data.success === false) errorMsg = data.message || 'خطأ في البحث';
                    if (noResults) { noResults.style.display = 'block'; noResults.textContent = errorMsg || 'لا توجد نتائج مطابقة'; }
                    else if (hint) { hint.style.display = 'block'; hint.textContent = errorMsg || 'لا توجد نتائج مطابقة'; }
                    rowSearchResults[idx] = [];
                }
            }).catch(function(err) {
                if (loading) loading.style.display = 'none';
                if (noResults) { noResults.style.display = 'block'; noResults.textContent = 'خطأ في الاتصال: ' + (err.message || ''); }
            });
    }, 300);
}

function createRowProductItem(product, query, idx) {
    const item = document.createElement('div');
    item.className = 'search-product-item row-product-item';
    item.onclick = function() { selectRowProduct(idx, product); };

    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock', stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    else if (qty <= parseFloat(product.min_quantity || 0)) { stockClass = 'low-stock'; stockText = 'منخفض: ' + qty.toFixed(2) + ' ' + product.unit; }

    let nameHtml = escapeHtml(product.name), codeHtml = escapeHtml(product.code);
    if (query) {
        const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi');
        nameHtml = nameHtml.replace(regex, '<mark>$1</mark>');
        codeHtml = codeHtml.replace(regex, '<mark>$1</mark>');
    }

    // كشف المنتج المكرر
    const dupIdx = isDuplicateProduct(product.id, idx);
    if (dupIdx !== null) {
        item.style.opacity = '0.5';
        item.style.pointerEvents = 'none';
        stockText += ' (مضاف مسبقاً)';
        stockClass = 'out-of-stock';
    }

    item.innerHTML = `
        <span class="item-code">${codeHtml}</span>
        <div class="item-details">
            <div class="item-name">${nameHtml}</div>
            <div class="item-meta">
                <span>شراء: ${parseFloat(product.purchase_price).toFixed(2) + ' ' + CURRENCY_SYMBOL}</span>
                <span>الوحدة: ${escapeHtml(product.unit || '')}</span>
            </div>
        </div>
        <span class="item-stock ${stockClass}">${stockText}</span>
    `;
    return item;
}

function selectRowProduct(idx, product) {
    // كشف المنتج المكرر
    const dupIdx = isDuplicateProduct(product.id, idx);
    if (dupIdx !== null) {
        alert('هذا المنتج مضاف بالفعل في صف آخر (#' + (getRowPosition(dupIdx) + 1) + ')');
        return;
    }

    document.getElementById('prod_id_' + idx).value = product.id;
    document.getElementById('prod_base_price_' + idx).value = product.purchase_price;
    document.getElementById('prod_unit_name_' + idx).value = product.unit;

    const input = document.getElementById('prod_search_' + idx);
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('prod_clear_' + idx);
    if (clearBtn) clearBtn.style.display = 'none';
    const arrowBtn = document.getElementById('prod_arrow_' + idx);
    if (arrowBtn) arrowBtn.style.display = 'none';

    const selectedDiv = document.getElementById('prod_selected_' + idx);
    selectedDiv.style.display = 'flex';
    document.getElementById('prod_sel_code_' + idx).textContent = product.code;
    document.getElementById('prod_sel_name_' + idx).textContent = product.name;

    const qty = parseFloat(product.quantity);
    const stockSpan = document.getElementById('prod_sel_stock_' + idx);
    if (qty <= 0) { stockSpan.style.background = '#fee2e2'; stockSpan.style.color = '#dc2626'; stockSpan.textContent = 'غير متوفر'; }
    else { stockSpan.style.background = '#fef3c7'; stockSpan.style.color = '#92400e'; stockSpan.textContent = 'متوفر: ' + qty.toFixed(2) + ' ' + product.unit; }

    closeRowSearch(idx);

    // إظهار حقول الكمية والسعر
    document.getElementById('prod_fields_' + idx).style.display = 'grid';
    document.getElementById('prod_summary_' + idx).style.display = 'grid';
    document.getElementById('productRow_' + idx).classList.add('has-product');

    // تعبئة سعر الشراء
    document.getElementById('prod_price_' + idx).value = product.purchase_price;
    document.getElementById('prod_unit_label_' + idx).textContent = 'الوحدة: ' + product.unit;
    document.getElementById('prod_factor_' + idx).value = '1';

    // تخزين بيانات المنتج
    productRowsData[idx] = { id: product.id, name: product.name, code: product.code, unit: product.unit, basePrice: parseFloat(product.purchase_price), hasExpiry: parseInt(product.has_expiry) || 0, factor: 1, unitName: product.unit };

    // تحميل وحدات الشراء
    loadRowPurchaseUnits(idx, product.id);

    // إظهار/إخفاء قسم انتهاء الصلاحية
    if (productRowsData[idx].hasExpiry) {
        showRowExpirySection(idx, true);
    } else {
        showRowExpirySection(idx, false);
    }

    calculateLineTotal(idx);
}

function clearRowProduct(idx) {
    document.getElementById('prod_id_' + idx).value = '';
    document.getElementById('prod_base_price_' + idx).value = '';
    document.getElementById('prod_unit_name_' + idx).value = '';
    document.getElementById('prod_factor_' + idx).value = '1';

    const input = document.getElementById('prod_search_' + idx);
    if (input) { input.style.display = 'block'; input.value = ''; }
    const clearBtn = document.getElementById('prod_clear_' + idx);
    if (clearBtn) clearBtn.style.display = 'none';
    const arrowBtn = document.getElementById('prod_arrow_' + idx);
    if (arrowBtn) arrowBtn.style.display = 'flex';
    const selectedDiv = document.getElementById('prod_selected_' + idx);
    if (selectedDiv) selectedDiv.style.display = 'none';

    // إخفاء حقول الكمية والسعر
    document.getElementById('prod_fields_' + idx).style.display = 'none';
    document.getElementById('prod_summary_' + idx).style.display = 'none';
    document.getElementById('productRow_' + idx).classList.remove('has-product');

    document.getElementById('prod_price_' + idx).value = '';
    document.getElementById('prod_qty_' + idx).value = '';
    document.getElementById('prod_unit_label_' + idx).textContent = '';
    document.getElementById('prod_line_total_' + idx).value = '0.00 ' + CURRENCY_SYMBOL;
    document.getElementById('prod_unit_group_' + idx).style.display = 'none';

    productRowsData[idx] = { id: null, name: '', code: '', unit: '', basePrice: 0, hasExpiry: 0, factor: 1, unitName: '' };
    showRowExpirySection(idx, false);

    if (input) input.focus();
    searchRowProduct(idx);
    recalculateTotals();
}

// ============================================================
// === وحدات الشراء لكل صف ===
// ============================================================
function loadRowPurchaseUnits(idx, productId) {
    const group = document.getElementById('prod_unit_group_' + idx);
    const select = document.getElementById('prod_unit_select_' + idx);
    if (!group || !select) return;

    fetch('../api/get_purchase_units.php?product_id=' + productId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            select.innerHTML = '';

            const hasBase = data.purchase_unit && data.purchase_unit !== data.selling_unit;
            const hasAdditional = data.units && data.units.length > 0;

            if (!hasBase && !hasAdditional) {
                group.style.display = 'none';
                document.getElementById('prod_factor_' + idx).value = '1';
                document.getElementById('prod_unit_info_' + idx).textContent = '';
                return;
            }

            group.style.display = '';

            // وحدة البيع الأساسية
            const optSelling = document.createElement('option');
            optSelling.value = 'base';
            optSelling.dataset.factor = 1;
            optSelling.textContent = data.selling_unit + ' (الوحدة الأساسية)';
            select.appendChild(optSelling);

            // وحدة الشراء الأساسية
            if (hasBase) {
                const optBase = document.createElement('option');
                optBase.value = 'purchase_base';
                optBase.dataset.factor = data.purchase_unit_factor;
                optBase.textContent = data.purchase_unit + ' — شراء (1 ' + data.purchase_unit + ' = ' + data.purchase_unit_factor + ' ' + data.selling_unit + ')';
                select.appendChild(optBase);
            }

            // وحدات شراء إضافية
            if (hasAdditional) {
                data.units.forEach(function(u) {
                    const opt = document.createElement('option');
                    const totalFactor = parseFloat(u.conversion_factor) * (data.purchase_unit_factor || 1);
                    opt.value = 'additional_' + u.id;
                    opt.dataset.factor = totalFactor;
                    opt.textContent = u.unit_name + ' — شراء (1 ' + u.unit_name + ' = ' + totalFactor + ' ' + data.selling_unit + ')';
                    select.appendChild(opt);
                });
            }

            onRowUnitChange(idx);
        })
        .catch(function() {
            group.style.display = 'none';
            document.getElementById('prod_factor_' + idx).value = '1';
        });
}

function onRowUnitChange(idx) {
    const select = document.getElementById('prod_unit_select_' + idx);
    if (!select) return;

    const selectedOpt = select.options[select.selectedIndex];
    const newFactor = parseFloat(selectedOpt.dataset.factor) || 1;

    // تحويل سعر الشراء حسب الوحدة الجديدة
    const priceInput = document.getElementById('prod_price_' + idx);
    const currentPrice = parseFloat(priceInput.value) || 0;
    if (currentPrice > 0 && productRowsData[idx]) {
        const oldFactor = productRowsData[idx].factor || 1;
        // السعر الجديد = السعر الحالي × (معامل الوحدة الجديدة / معامل الوحدة القديمة)
        const newPrice = currentPrice * (newFactor / oldFactor);
        priceInput.value = newPrice.toFixed(2);
    }

    document.getElementById('prod_factor_' + idx).value = newFactor;

    const infoDiv = document.getElementById('prod_unit_info_' + idx);
    if (infoDiv) {
        const unitName = selectedOpt.textContent.split(' — ')[0] || selectedOpt.textContent.split(' (')[0];
        infoDiv.textContent = 'المعامل: 1 ' + unitName + ' = ' + newFactor + ' وحدة بيع أساسية';
    }

    // تحديث اسم الوحدة
    const unitLabel = document.getElementById('prod_unit_label_' + idx);
    if (unitLabel) {
        const unitName2 = selectedOpt.textContent.split(' — ')[0] || selectedOpt.textContent.split(' (')[0];
        unitLabel.textContent = 'الوحدة: ' + unitName2;
    }

    if (productRowsData[idx]) {
        productRowsData[idx].factor = newFactor;
        productRowsData[idx].unitName = selectedOpt.textContent.split(' — ')[0] || selectedOpt.textContent.split(' (')[0];
    }

    calculateLineTotal(idx);
}

// ============================================================
// === حساب الإجمالي ===
// ============================================================
function calculateLineTotal(idx) {
    const qty = parseFloat(document.getElementById('prod_qty_' + idx).value) || 0;
    const price = parseFloat(document.getElementById('prod_price_' + idx).value) || 0;
    const factor = parseFloat(document.getElementById('prod_factor_' + idx).value) || 1;
    const total = qty * price;

    // حساب تخفيض المنتج المالي
    let prodFinRemise = 0;
    const prContainer = document.getElementById('prod_remise_container_' + idx);
    if (prContainer) {
        prContainer.querySelectorAll('input[name="prod_remise_type[' + idx + '][]"]').forEach(function(t) {
            if (t.value === 'financial') {
                const r = t.closest('div[id^="prod_remise_row_' + idx + '_"]');
                if (r) {
                    const a = r.querySelector('input[name="prod_remise_amount[' + idx + '][]"]');
                    if (a) prodFinRemise += parseFloat(a.value) || 0;
                }
            }
        });
    }
    const netTotal = Math.max(0, total - prodFinRemise);

    const totalEl = document.getElementById('prod_line_total_' + idx);
    if (totalEl) {
        if (prodFinRemise > 0) {
            totalEl.value = netTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL + '  (قبل الخصم: ' + total.toFixed(2) + ')';
        } else {
            totalEl.value = total.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        }
    }

    // تحديث ملخص انتهاء الصلاحية إن وجد
    if (productRowsData[idx] && productRowsData[idx].hasExpiry) {
        updateExpirySummary(idx);
    }

    recalculateTotals();
}

function recalculateTotals() {
    let grandTotal = 0;
    let productCount = 0;

    document.querySelectorAll('.product-row').forEach(function(row) {
        const id = row.id.replace('productRow_', '');
        const pid = document.getElementById('prod_id_' + id);
        if (!pid || !pid.value) return;

        const qty = parseFloat(document.getElementById('prod_qty_' + id).value) || 0;
        const price = parseFloat(document.getElementById('prod_price_' + id).value) || 0;
        if (qty > 0 && price > 0) {
            grandTotal += qty * price;
            productCount++;
        }
    });

    // حساب إجمالي التخفيض المالي (عام)
    let totalFinRemise = 0;
    const remiseContainer = document.getElementById('remisesContainer');
    if (remiseContainer) {
        remiseContainer.querySelectorAll('input[name="remise_type[]"]').forEach(function(t) {
            if (t.value === 'financial') {
                const r = t.closest('div[id^="remise_row_"]');
                if (r) {
                    const a = r.querySelector('input[name="remise_amount[]"]');
                    if (a) totalFinRemise += parseFloat(a.value) || 0;
                }
            }
        });
    }

    // حساب إجمالي تخفيضات المنتجات المالية
    document.querySelectorAll('.product-row').forEach(function(row) {
        const id = row.id.replace('productRow_', '');
        const prContainer = document.getElementById('prod_remise_container_' + id);
        if (prContainer) {
            prContainer.querySelectorAll('input[name="prod_remise_type[' + id + '][]"]').forEach(function(t) {
                if (t.value === 'financial') {
                    const r = t.closest('div[id^="prod_remise_row_' + id + '_"]');
                    if (r) {
                        const a = r.querySelector('input[name="prod_remise_amount[' + id + '][]"]');
                        if (a) totalFinRemise += parseFloat(a.value) || 0;
                    }
                }
            });
        }
    });

    const netTotal = Math.max(0, grandTotal - totalFinRemise);

    // حساب الضريبة
    let taxAmount = 0;
    let taxEnabled = document.getElementById('tax_enabled_checkbox') && document.getElementById('tax_enabled_checkbox').checked;
    let taxName = document.getElementById('tax_name_input') ? document.getElementById('tax_name_input').value.trim() : '';
    let taxType = document.getElementById('tax_type_select') ? document.getElementById('tax_type_select').value : 'percentage';
    let taxValue = document.getElementById('tax_value_input') ? (parseFloat(document.getElementById('tax_value_input').value) || 0) : 0;

    if (taxEnabled && taxValue > 0 && netTotal > 0) {
        if (taxType === 'percentage') {
            taxAmount = netTotal * (taxValue / 100);
        } else {
            taxAmount = taxValue;
        }
    }

    const grandTotalWithTax = netTotal + taxAmount;

    const summary = document.getElementById('purchaseSummary');
    if (productCount > 0) {
        summary.style.display = 'block';
        summary.classList.add('visible');
        document.getElementById('summaryProductCount').textContent = productCount;
        document.getElementById('summaryGrossTotal').textContent = grandTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        if (totalFinRemise > 0) {
            document.getElementById('summaryRemiseDiv').style.display = '';
            document.getElementById('summaryFinancialRemise').textContent = '-' + totalFinRemise.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        } else {
            document.getElementById('summaryRemiseDiv').style.display = 'none';
        }
        document.getElementById('summaryNetTotal').textContent = netTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;

        // عرض الضريبة
        if (taxEnabled && taxAmount > 0) {
            document.getElementById('summaryTaxDiv').style.display = '';
            document.getElementById('summaryTaxName').textContent = taxName || 'الضريبة';
            document.getElementById('summaryTaxAmount').textContent = '+' + taxAmount.toFixed(2) + ' ' + CURRENCY_SYMBOL;
            document.getElementById('summaryGrandTotalDiv').style.display = '';
            document.getElementById('summaryGrandTotal').textContent = grandTotalWithTax.toFixed(2) + ' ' + CURRENCY_SYMBOL;
        } else {
            document.getElementById('summaryTaxDiv').style.display = 'none';
            document.getElementById('summaryGrandTotalDiv').style.display = 'none';
        }
    } else {
        summary.style.display = 'none';
        summary.classList.remove('visible');
    }

    // تحديث عرض الضريبة المحسوبة
    const taxCalc = document.getElementById('taxCalculated');
    if (taxEnabled && taxAmount > 0) {
        taxCalc.style.display = '';
        document.getElementById('taxCalculatedLabel').textContent = (taxName || 'الضريبة') + ':';
        document.getElementById('taxCalculatedAmount').textContent = '+' + taxAmount.toFixed(2);
    } else {
        taxCalc.style.display = 'none';
    }
}

// ============================================================
// === تواريخ انتهاء الصلاحية لكل منتج ===
// ============================================================
let expiryCounters = {};

function showRowExpirySection(idx, show) {
    const section = document.getElementById('prod_expiry_section_' + idx);
    if (show) {
        section.style.display = '';
        if (!expiryCounters[idx] || expiryCounters[idx] === 0) {
            addExpiryRow(idx);
        }
    } else {
        section.style.display = 'none';
        // مسح صفوف الانتهاء
        const container = document.getElementById('prod_expiry_container_' + idx);
        if (container) container.innerHTML = '';
        expiryCounters[idx] = 0;
    }
}

function addExpiryRow(prodIdx) {
    if (!expiryCounters[prodIdx]) expiryCounters[prodIdx] = 0;
    expiryCounters[prodIdx]++;

    const eIdx = expiryCounters[prodIdx];
    const container = document.getElementById('prod_expiry_container_' + prodIdx);
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'expiry-row';
    row.id = 'expiry_row_' + prodIdx + '_' + eIdx;
    row.style.cssText = 'display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:8px 12px; background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #93c5fd; border-radius:8px; margin-bottom:6px;';
    row.innerHTML = `
        <div style="flex:1; min-width:150px;">
            <label style="font-size:0.75rem; font-weight:600; color:#1e40af; display:block; margin-bottom:2px;">تاريخ الانتهاء</label>
            <input type="date" name="expiry_dates[${prodIdx}][]" class="form-control" style="border-color:#93c5fd; font-weight:600; font-size:0.85rem; padding:6px 8px;" onchange="updateExpirySummary(${prodIdx})">
        </div>
        <div style="min-width:120px;">
            <label style="font-size:0.75rem; font-weight:600; color:#1e40af; display:block; margin-bottom:2px;">الكمية (بالوحدة الأساسية)</label>
            <input type="number" name="expiry_quantities[${prodIdx}][]" class="form-control" step="0.01" min="0.01" value="0" style="border-color:#93c5fd; font-weight:700; font-size:0.85rem; padding:6px 8px;" oninput="updateExpirySummary(${prodIdx})">
        </div>
        <button type="button" onclick="removeExpiryRowById(${prodIdx}, ${eIdx})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem; padding:4px;" title="حذف">✕</button>
    `;
    container.appendChild(row);
    updateExpirySummary(prodIdx);
}

function removeExpiryRowById(prodIdx, eIdx) {
    const row = document.getElementById('expiry_row_' + prodIdx + '_' + eIdx);
    if (row) row.remove();
    updateExpirySummary(prodIdx);
}

function getRowBaseQuantity(idx) {
    const qty = parseFloat(document.getElementById('prod_qty_' + idx).value) || 0;
    const factor = parseFloat(document.getElementById('prod_factor_' + idx).value) || 1;
    if (factor > 0 && factor != 1) return qty * factor;
    return qty;
}

function updateExpirySummary(prodIdx) {
    const container = document.getElementById('prod_expiry_container_' + prodIdx);
    if (!container) return;

    let totalQty = 0;
    container.querySelectorAll('input[name="expiry_quantities[' + prodIdx + '][]"]').forEach(function(input) {
        totalQty += parseFloat(input.value) || 0;
    });

    const totalEl = document.getElementById('prod_expiry_total_' + prodIdx);
    const requiredEl = document.getElementById('prod_expiry_required_' + prodIdx);
    const diffBox = document.getElementById('prod_expiry_diff_box_' + prodIdx);
    const diffEl = document.getElementById('prod_expiry_diff_' + prodIdx);
    const summaryBox = document.getElementById('prod_expiry_summary_' + prodIdx);

    if (!totalEl) return;

    const requiredQty = getRowBaseQuantity(prodIdx);

    totalEl.textContent = totalQty.toFixed(2);
    requiredEl.textContent = requiredQty.toFixed(2);

    if (totalQty > 0 || requiredQty > 0) {
        summaryBox.style.display = '';
        diffBox.style.display = '';
        const diff = totalQty - requiredQty;
        diffEl.textContent = (diff >= 0 ? '+' : '') + diff.toFixed(2);
        if (Math.abs(diff) < 0.01) {
            diffBox.style.background = '#f0fdf4';
            diffBox.style.border = '1px solid #86efac';
            diffBox.style.color = '#059669';
        } else {
            diffBox.style.background = '#fef2f2';
            diffBox.style.border = '1px solid #fca5a5';
            diffBox.style.color = '#dc2626';
        }
    } else {
        summaryBox.style.display = 'none';
    }
}

// ============================================================
// === التخفيضات (Remises) ===
// ============================================================
function addRemiseRow(type) {
    remiseCounter++;
    const container = document.getElementById('remisesContainer');
    const row = document.createElement('div');
    row.id = 'remise_row_' + remiseCounter;
    row.style.cssText = 'background: ' + (type === 'material' ? '#f0fdf4' : '#fef3c7') + '; border: 1px solid ' + (type === 'material' ? '#86efac' : '#fcd34d') + '; border-radius: 10px; padding: 0.75rem; margin-bottom: 0.5rem; position: relative;';

    if (type === 'material') {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 700; font-size: 0.9rem; color: #059669;">🎁 تخفيض مادي — إعطاء سلع مجانية <span style="font-size:0.75rem; color:var(--gray-400);">(سعر الدفعة = 0.00)</span></span>
                <button type="button" onclick="removeRemiseRow(${remiseCounter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr; gap: 0.5rem; align-items: end;">
                <div style="position:relative; min-width:0;" class="remise-search-wrapper">
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">المنتج المعطى *</label>
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" class="form-control remise-search-input" placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openRemiseSearch(${remiseCounter})" oninput="searchRemiseProduct(${remiseCounter})"
                               style="font-size:0.85rem; padding:8px 32px 8px 40px; width:100%;">
                        <button type="button" class="remise-clear-btn" id="remise_clear_${remiseCounter}" onclick="clearRemiseSelection(${remiseCounter})" style="display:none; position:absolute; left:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#9ca3af; padding:2px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="remise-search-dropdown" id="remise_dropdown_${remiseCounter}" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); max-height:220px; overflow-y:auto; z-index:50; margin-top:2px;">
                        <div class="remise-search-results" id="remise_results_${remiseCounter}"></div>
                    </div>
                    <div class="remise-selected-product" id="remise_selected_${remiseCounter}" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:6px 10px; margin-top:4px; font-size:0.82rem; align-items:center; gap:6px;">
                        <span style="font-family:monospace; background:#dcfce7; padding:1px 6px; border-radius:4px; font-weight:700; color:#166534;" id="remise_sel_code_${remiseCounter}"></span>
                        <span style="font-weight:600; color:#0f172a;" id="remise_sel_name_${remiseCounter}"></span>
                    </div>
                    <input type="hidden" name="remise_product_id[]" id="remise_pid_${remiseCounter}" value="">
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">الكمية *</label>
                    <input type="number" name="remise_quantity[]" class="form-control" step="0.01" min="0.01" required placeholder="0" oninput="updateRemiseSummary()" style="font-size:0.9rem; padding:8px;">
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="remise_description[]" class="form-control" placeholder="اختياري" style="font-size:0.85rem; padding:8px;">
                </div>
            </div>
            <input type="hidden" name="remise_type[]" value="material">
        `;
    } else {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 700; font-size: 0.9rem; color: #d97706;">💰 تخفيض مالي — خصم من المبلغ</span>
                <button type="button" onclick="removeRemiseRow(${remiseCounter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem;">
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">مبلغ الخصم *</label>
                    <input type="number" name="remise_amount[]" class="form-control" step="0.01" min="0.01" required placeholder="0.00" oninput="updateRemiseSummary()" style="font-size:0.9rem; padding:8px; font-weight:800; text-align:center; border-color:#f59e0b;">
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="remise_description[]" class="form-control" placeholder="اختياري" style="font-size:0.85rem; padding:8px;">
                </div>
            </div>
            <input type="hidden" name="remise_type[]" value="financial">
        `;
    }

    container.appendChild(row);
    updateRemiseSummary();
    recalculateTotals();
}

function removeRemiseRow(id) {
    const row = document.getElementById('remise_row_' + id);
    if (row) row.remove();
    updateRemiseSummary();
    recalculateTotals();
}

function updateRemiseSummary() {
    const container = document.getElementById('remisesContainer');
    const summary = document.getElementById('remiseSummary');
    const rows = container.querySelectorAll('div[id^="remise_row_"]');

    if (rows.length === 0) {
        summary.style.display = 'none';
        recalculateTotals();
        return;
    }
    summary.style.display = 'block';

    let matCount = 0;
    let finTotal = 0;

    rows.forEach(function(row) {
        const type = row.querySelector('input[name="remise_type[]"]');
        if (!type) return;
        if (type.value === 'material') {
            const qty = parseFloat(row.querySelector('input[name="remise_quantity[]"]').value) || 0;
            if (qty > 0) matCount++;
        } else {
            const amount = parseFloat(row.querySelector('input[name="remise_amount[]"]').value) || 0;
            finTotal += amount;
        }
    });

    document.getElementById('totalMaterialRemise').textContent = matCount > 0 ? matCount + ' منتج(ات)' : '0';
    document.getElementById('totalFinancialRemise').textContent = finTotal.toFixed(2) + ' ' + CURRENCY_SYMBOL;

    recalculateTotals();
}

// === بحث المنتجات في التخفيض المادي ===
function openRemiseSearch(counter) {
    const pidInput = document.getElementById('remise_pid_' + counter);
    if (pidInput && pidInput.value) return;
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; remiseDropdownOpen[counter] = true; }
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input && !input.value.trim()) searchRemiseProduct(counter);
}

function closeRemiseSearch(counter) {
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    if (dropdown) dropdown.style.display = 'none';
    remiseDropdownOpen[counter] = false;
    remiseHighlightedIndex[counter] = -1;
}

function searchRemiseProduct(counter) {
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (!input) return;
    const query = input.value.trim();
    const resultsDiv = document.getElementById('remise_results_' + counter);
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';
    if (remiseSearchTimeouts[counter]) clearTimeout(remiseSearchTimeouts[counter]);
    const dropdown = document.getElementById('remise_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; remiseDropdownOpen[counter] = true; }
    remiseHighlightedIndex[counter] = -1;
    remiseSearchTimeouts[counter] = setTimeout(function() {
        if (!resultsDiv) return;
        resultsDiv.innerHTML = '<div style="padding:8px; text-align:center; color:#94a3b8; font-size:0.8rem;"><div class="spinner" style="margin:0 auto 4px; width:14px; height:14px; border-width:2px;"></div>جاري البحث...</div>';
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (!resultsDiv) return;
                resultsDiv.querySelectorAll('.remise-product-item').forEach(el => el.remove());
                var _remiseProducts = data.products || data.data || [];
                if (_remiseProducts.length > 0) {
                    _remiseProducts.forEach(function(product, index) {
                        const item = createRemiseProductItem(product, query, counter);
                        item.dataset.index = index;
                        item.dataset.counter = counter;
                        resultsDiv.appendChild(item);
                    });
                } else {
                    resultsDiv.innerHTML = '<div style="padding:10px; text-align:center; color:#94a3b8; font-size:0.8rem;">لا توجد نتائج مطابقة</div>';
                }
            }).catch(function() {
                if (resultsDiv) resultsDiv.innerHTML = '<div style="padding:10px; text-align:center; color:#ef4444; font-size:0.8rem;">خطأ في البحث</div>';
            });
    }, 300);
}

function createRemiseProductItem(product, query, counter) {
    const item = document.createElement('div');
    item.className = 'search-product-item remise-product-item';
    item.style.cssText = 'padding:8px 12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; transition:background 0.15s; font-size:0.85rem;';
    item.onclick = function() { selectRemiseProduct(counter, product); };
    item.addEventListener('mouseenter', function() {
        document.querySelectorAll('.remise-product-item[data-counter="' + counter + '"]').forEach(function(el) { el.style.background = ''; });
        item.style.background = '#f0fdf4';
        remiseHighlightedIndex[counter] = parseInt(item.dataset.index);
    });
    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock', stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    let nameHtml = escapeHtml(product.name), codeHtml = escapeHtml(product.code);
    if (query) { const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi'); nameHtml = nameHtml.replace(regex, '<mark>$1</mark>'); codeHtml = codeHtml.replace(regex, '<mark>$1</mark>'); }
    item.innerHTML = '<div style="flex:1;"><span style="font-family:monospace; font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:1px 6px; border-radius:4px; margin-left:6px;">' + codeHtml + '</span><span style="font-weight:600; color:#0f172a;">' + nameHtml + '</span></div>' +
        '<div style="text-align:left; font-size:0.72rem; color:#64748b;"><div>سعر البيع: <strong>' + parseFloat(product.selling_price).toFixed(2) + ' ' + CURRENCY_SYMBOL + '</strong></div><div class="' + stockClass + '" style="font-weight:600; margin-top:1px;">' + stockText + '</div></div>';
    return item;
}

function selectRemiseProduct(counter, product) {
    document.getElementById('remise_pid_' + counter).value = product.id;
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'block';
    const selectedDiv = document.getElementById('remise_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'flex';
    document.getElementById('remise_sel_code_' + counter).textContent = product.code;
    document.getElementById('remise_sel_name_' + counter).textContent = product.name;
    closeRemiseSearch(counter);
}

function clearRemiseSelection(counter) {
    document.getElementById('remise_pid_' + counter).value = '';
    const input = document.querySelector('#remise_row_' + counter + ' .remise-search-input');
    if (input) { input.style.display = 'block'; input.value = ''; input.focus(); }
    const clearBtn = document.getElementById('remise_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'none';
    const selectedDiv = document.getElementById('remise_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'none';
    searchRemiseProduct(counter);
}

// ============================================================
// === تخفيضات المنتجات (لكل منتج) ===
// ============================================================
function toggleProdRemise(idx) {
    const section = document.getElementById('prod_remise_section_' + idx);
    if (!section) return;
    if (section.style.display === 'none') {
        section.style.display = '';
    } else {
        section.style.display = 'none';
    }
}

function addProdRemiseRow(idx, type) {
    if (!prodRemiseCounters[idx]) prodRemiseCounters[idx] = 0;
    prodRemiseCounters[idx]++;
    const counter = prodRemiseCounters[idx];
    const container = document.getElementById('prod_remise_container_' + idx);
    if (!container) return;

    const row = document.createElement('div');
    row.id = 'prod_remise_row_' + idx + '_' + counter;
    row.style.cssText = 'background: ' + (type === 'material' ? '#f0fdf4' : '#fef3c7') + '; border: 1px solid ' + (type === 'material' ? '#86efac' : '#fcd34d') + '; border-radius: 8px; padding: 0.6rem; margin-bottom: 0.4rem; position: relative; animation: fadeSlideDown 0.3s ease;';

    if (type === 'material') {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-weight: 700; font-size: 0.82rem; color: #059669;">🎁 تخفيض مادي — إعطاء سلع مجانية</span>
                <button type="button" onclick="removeProdRemiseRow(${idx}, ${counter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr; gap: 0.4rem;">
                <div style="position:relative;" class="prm-search-wrapper" id="pr_${idx}_search_wrapper_${counter}">
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">المنتج المعطى *</label>
                    <div class="search-input-container">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" class="form-control prm-search-input" id="pr_${idx}_search_${counter}" placeholder="ابحث بالكود أو اسم المنتج..." autocomplete="off"
                               onfocus="openPrmSearch(${idx}, ${counter})" oninput="searchPrmProduct(${idx}, ${counter})"
                               style="font-size:0.82rem; padding:6px 30px 6px 36px; width:100%;">
                        <button type="button" class="prm-clear-btn" id="pr_${idx}_clear_${counter}" onclick="clearPrmSelection(${idx}, ${counter})" style="display:none; position:absolute; left:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#9ca3af; padding:2px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="prm-search-dropdown" id="pr_${idx}_dropdown_${counter}" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); max-height:200px; overflow-y:auto; z-index:55; margin-top:2px;">
                        <div class="prm-search-results" id="pr_${idx}_results_${counter}"></div>
                    </div>
                    <div class="prm-selected-product" id="pr_${idx}_selected_${counter}" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:5px 8px; margin-top:3px; font-size:0.8rem; align-items:center; gap:5px;">
                        <span style="font-family:monospace; background:#dcfce7; padding:1px 5px; border-radius:4px; font-weight:700; color:#166534;" id="pr_${idx}_sel_code_${counter}"></span>
                        <span style="font-weight:600; color:#0f172a;" id="pr_${idx}_sel_name_${counter}"></span>
                    </div>
                    <input type="hidden" name="prod_remise_product_id[${idx}][]" id="pr_${idx}_pid_${counter}" value="">
                </div>
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">الكمية *</label>
                    <input type="number" name="prod_remise_quantity[${idx}][]" class="form-control" step="0.01" min="0.01" required placeholder="0" style="font-size:0.85rem; padding:6px;">
                </div>
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="prod_remise_description[${idx}][]" class="form-control" placeholder="اختياري" style="font-size:0.8rem; padding:6px;">
                </div>
            </div>
            <input type="hidden" name="prod_remise_type[${idx}][]" value="material">
        `;
    } else {
        row.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                <span style="font-weight: 700; font-size: 0.82rem; color: #d97706;">💰 تخفيض مالي — خصم من هذا المنتج</span>
                <button type="button" onclick="removeProdRemiseRow(${idx}, ${counter})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem; padding: 2px 6px;" title="حذف">✕</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem;">
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">مبلغ الخصم *</label>
                    <input type="number" name="prod_remise_amount[${idx}][]" class="form-control" step="0.01" min="0.01" required placeholder="0.00" oninput="calculateLineTotal(${idx})" style="font-size:0.85rem; padding:6px; font-weight:800; border-color:#f59e0b;">
                </div>
                <div>
                    <label style="font-size:0.72rem; font-weight:600; color:var(--gray-500);">وصف</label>
                    <input type="text" name="prod_remise_description[${idx}][]" class="form-control" placeholder="اختياري" style="font-size:0.8rem; padding:6px;">
                </div>
            </div>
            <input type="hidden" name="prod_remise_type[${idx}][]" value="financial">
        `;
    }

    container.appendChild(row);
    calculateLineTotal(idx);
}

function removeProdRemiseRow(idx, counter) {
    const row = document.getElementById('prod_remise_row_' + idx + '_' + counter);
    if (row) row.remove();
    calculateLineTotal(idx);
}

// === بحث المنتجات في تخفيض المنتج المادي ===
function openPrmSearch(idx, counter) {
    const prmKey = idx + '_' + counter;
    const pidInput = document.getElementById('pr_' + idx + '_pid_' + counter);
    if (pidInput && pidInput.value) return;
    const dropdown = document.getElementById('pr_' + idx + '_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; prmDropdownOpen[prmKey] = true; }
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (input && !input.value.trim()) searchPrmProduct(idx, counter);
}

function closePrmSearch(idx, counter) {
    const prmKey = idx + '_' + counter;
    const dropdown = document.getElementById('pr_' + idx + '_dropdown_' + counter);
    if (dropdown) dropdown.style.display = 'none';
    prmDropdownOpen[prmKey] = false;
    prmHighlightedIndex[prmKey] = -1;
}

function searchPrmProduct(idx, counter) {
    const prmKey = idx + '_' + counter;
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (!input) return;
    const query = input.value.trim();
    const resultsDiv = document.getElementById('pr_' + idx + '_results_' + counter);
    const clearBtn = document.getElementById('pr_' + idx + '_clear_' + counter);
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';
    if (prmSearchTimeouts[prmKey]) clearTimeout(prmSearchTimeouts[prmKey]);
    const dropdown = document.getElementById('pr_' + idx + '_dropdown_' + counter);
    if (dropdown) { dropdown.style.display = 'block'; prmDropdownOpen[prmKey] = true; }
    prmHighlightedIndex[prmKey] = -1;
    prmSearchTimeouts[prmKey] = setTimeout(function() {
        if (!resultsDiv) return;
        resultsDiv.innerHTML = '<div style="padding:8px; text-align:center; color:#94a3b8; font-size:0.8rem;"><div class="spinner" style="margin:0 auto 4px; width:14px; height:14px; border-width:2px;"></div>جاري البحث...</div>';
        fetch('../api/search_products.php?q=' + encodeURIComponent(query))
            .then(res => res.json()).then(data => {
                if (!resultsDiv) return;
                resultsDiv.querySelectorAll('.prm-product-item').forEach(el => el.remove());
                var _prmProducts = data.products || data.data || [];
                if (_prmProducts.length > 0) {
                    _prmProducts.forEach(function(product, index) {
                        const item = createPrmProductItem(product, query, idx, counter);
                        item.dataset.index = index;
                        item.dataset.prmkey = prmKey;
                        resultsDiv.appendChild(item);
                    });
                } else {
                    resultsDiv.innerHTML = '<div style="padding:10px; text-align:center; color:#94a3b8; font-size:0.8rem;">لا توجد نتائج مطابقة</div>';
                }
            }).catch(function() {
                if (resultsDiv) resultsDiv.innerHTML = '<div style="padding:10px; text-align:center; color:#ef4444; font-size:0.8rem;">خطأ في البحث</div>';
            });
    }, 300);
}

function createPrmProductItem(product, query, idx, counter) {
    const prmKey = idx + '_' + counter;
    const item = document.createElement('div');
    item.className = 'search-product-item prm-product-item';
    item.style.cssText = 'padding:8px 12px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; transition:background 0.15s; font-size:0.85rem;';
    item.onclick = function() { selectPrmProduct(idx, counter, product); };
    item.addEventListener('mouseenter', function() {
        document.querySelectorAll('.prm-product-item[data-prmkey="' + prmKey + '"]').forEach(function(el) { el.style.background = ''; });
        item.style.background = '#f0fdf4';
        prmHighlightedIndex[prmKey] = parseInt(item.dataset.index);
    });
    const qty = parseFloat(product.quantity);
    let stockClass = 'in-stock', stockText = 'متوفر: ' + qty.toFixed(2) + ' ' + (product.unit || '');
    if (qty <= 0) { stockClass = 'out-of-stock'; stockText = 'غير متوفر'; }
    let nameHtml = escapeHtml(product.name), codeHtml = escapeHtml(product.code);
    if (query) { const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi'); nameHtml = nameHtml.replace(regex, '<mark>$1</mark>'); codeHtml = codeHtml.replace(regex, '<mark>$1</mark>'); }
    item.innerHTML = '<div style="flex:1;"><span style="font-family:monospace; font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:1px 6px; border-radius:4px; margin-left:6px;">' + codeHtml + '</span><span style="font-weight:600; color:#0f172a;">' + nameHtml + '</span></div>' +
        '<div style="text-align:left; font-size:0.72rem; color:#64748b;"><div>سعر البيع: <strong>' + parseFloat(product.selling_price).toFixed(2) + ' ' + CURRENCY_SYMBOL + '</strong></div><div class="' + stockClass + '" style="font-weight:600; margin-top:1px;">' + stockText + '</div></div>';
    return item;
}

function selectPrmProduct(idx, counter, product) {
    document.getElementById('pr_' + idx + '_pid_' + counter).value = product.id;
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (input) input.style.display = 'none';
    const clearBtn = document.getElementById('pr_' + idx + '_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'block';
    const selectedDiv = document.getElementById('pr_' + idx + '_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'flex';
    document.getElementById('pr_' + idx + '_sel_code_' + counter).textContent = product.code;
    document.getElementById('pr_' + idx + '_sel_name_' + counter).textContent = product.name;
    closePrmSearch(idx, counter);
}

function clearPrmSelection(idx, counter) {
    document.getElementById('pr_' + idx + '_pid_' + counter).value = '';
    const input = document.getElementById('pr_' + idx + '_search_' + counter);
    if (input) { input.style.display = 'block'; input.value = ''; input.focus(); }
    const clearBtn = document.getElementById('pr_' + idx + '_clear_' + counter);
    if (clearBtn) clearBtn.style.display = 'none';
    const selectedDiv = document.getElementById('pr_' + idx + '_selected_' + counter);
    if (selectedDiv) selectedDiv.style.display = 'none';
    searchPrmProduct(idx, counter);
}

// ============================================================
// === أدوات مساعدة ===
// ============================================================
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function escapeRegex(string) { return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

// ============================================================
// === لوحة المفاتيح: قوائم البحث لكل صف منتج ===
// ============================================================
document.addEventListener('keydown', function(e) {
    // البحث في صفوف المنتجات
    for (var idx in rowDropdownOpen) {
        if (!rowDropdownOpen[idx]) continue;
        const items = document.querySelectorAll('.row-product-item[data-idx="' + idx + '"]');
        if (items.length === 0) continue;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            rowHighlightedIndex[idx] = Math.min((rowHighlightedIndex[idx] || -1) + 1, items.length - 1);
            items.forEach(function(item, i) { item.style.background = i === rowHighlightedIndex[idx] ? '#f0fdf4' : ''; });
            if (items[rowHighlightedIndex[idx]]) items[rowHighlightedIndex[idx]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            rowHighlightedIndex[idx] = Math.max((rowHighlightedIndex[idx] || 0) - 1, 0);
            items.forEach(function(item, i) { item.style.background = i === rowHighlightedIndex[idx] ? '#f0fdf4' : ''; });
            if (items[rowHighlightedIndex[idx]]) items[rowHighlightedIndex[idx]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && rowHighlightedIndex[idx] >= 0) {
            e.preventDefault();
            items[rowHighlightedIndex[idx]].click();
        } else if (e.key === 'Escape') {
            closeRowSearch(idx);
        }
        return;
    }

    // البحث في قوائم التخفيض
    for (var counter in remiseDropdownOpen) {
        if (!remiseDropdownOpen[counter]) continue;
        const items = document.querySelectorAll('.remise-product-item[data-counter="' + counter + '"]');
        if (items.length === 0) continue;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            remiseHighlightedIndex[counter] = Math.min((remiseHighlightedIndex[counter] || -1) + 1, items.length - 1);
            items.forEach(function(item, i) { item.style.background = i === remiseHighlightedIndex[counter] ? '#f0fdf4' : ''; });
            if (items[remiseHighlightedIndex[counter]]) items[remiseHighlightedIndex[counter]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            remiseHighlightedIndex[counter] = Math.max((remiseHighlightedIndex[counter] || 0) - 1, 0);
            items.forEach(function(item, i) { item.style.background = i === remiseHighlightedIndex[counter] ? '#f0fdf4' : ''; });
            if (items[remiseHighlightedIndex[counter]]) items[remiseHighlightedIndex[counter]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && remiseHighlightedIndex[counter] >= 0) {
            e.preventDefault();
            items[remiseHighlightedIndex[counter]].click();
        } else if (e.key === 'Escape') {
            closeRemiseSearch(counter);
        }
        return;
    }

    // البحث في قوائم تخفيضات المنتجات
    for (var prmKey in prmDropdownOpen) {
        if (!prmDropdownOpen[prmKey]) continue;
        const parts = prmKey.split('_');
        const pIdx = parts[0];
        const pCounter = parts[1];
        const items = document.querySelectorAll('.prm-product-item[data-prmkey="' + prmKey + '"]');
        if (items.length === 0) continue;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            prmHighlightedIndex[prmKey] = Math.min((prmHighlightedIndex[prmKey] || -1) + 1, items.length - 1);
            items.forEach(function(item, i) { item.style.background = i === prmHighlightedIndex[prmKey] ? '#f0fdf4' : ''; });
            if (items[prmHighlightedIndex[prmKey]]) items[prmHighlightedIndex[prmKey]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            prmHighlightedIndex[prmKey] = Math.max((prmHighlightedIndex[prmKey] || 0) - 1, 0);
            items.forEach(function(item, i) { item.style.background = i === prmHighlightedIndex[prmKey] ? '#f0fdf4' : ''; });
            if (items[prmHighlightedIndex[prmKey]]) items[prmHighlightedIndex[prmKey]].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && prmHighlightedIndex[prmKey] >= 0) {
            e.preventDefault();
            items[prmHighlightedIndex[prmKey]].click();
        } else if (e.key === 'Escape') {
            closePrmSearch(pIdx, pCounter);
        }
        return;
    }
});

// إغلاق القوائم عند النقر خارجها
document.addEventListener('click', function(e) {
    // إغلاق قوائم صفوف المنتجات
    document.querySelectorAll('[id^="prodSearchWrapper_"]').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const idx = wrapper.id.replace('prodSearchWrapper_', '');
            closeRowSearch(idx);
        }
    });
    // إغلاق قوائم التخفيض
    document.querySelectorAll('.remise-search-wrapper').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const row = wrapper.closest('div[id^="remise_row_"]');
            if (row) {
                const id = row.id.replace('remise_row_', '');
                closeRemiseSearch(id);
            }
        }
    });
    // إغلاق قوائم تخفيضات المنتجات
    document.querySelectorAll('.prm-search-wrapper').forEach(function(wrapper) {
        if (wrapper && !wrapper.contains(e.target)) {
            const row = wrapper.closest('div[id^="prod_remise_row_"]');
            if (row) {
                const idParts = row.id.replace('prod_remise_row_', '').split('_');
                closePrmSearch(idParts[0], idParts[1]);
            }
        }
    });
});

// ============================================================
// === باركود ===
// ============================================================

// target: قيمة data-scan-target من زر المسح (رقم الصف) أو null
window.onBarcodeScanned = function(code, target) {
    if (!code) return;
    var scanTarget = target !== undefined ? target : null;

    // إذا تم تحديد صف منتج عبر data-scan-target
    if (scanTarget !== null && scanTarget !== '' && productRowsData[scanTarget] && !productRowsData[scanTarget].id) {
        var rIdx = parseInt(scanTarget);
        fetch('../api/search_products.php?q=' + encodeURIComponent(code))
            .then(res => res.json()).then(data => {
                var _scanProducts2 = data.products || data.data || [];
                if (_scanProducts2.length > 0) {
                    selectRowProduct(rIdx, _scanProducts2.find(function(p) { return p.code === code; }) || _scanProducts2[0]);
                }
            });
        return;
    }
    // السلوك الافتراضي: إضافة صف جديد وإسناد الباركود
    addProductRow();
    var newIdx = productRowCounter;
    document.getElementById('prod_search_' + newIdx).value = code;
    fetch('../api/search_products.php?q=' + encodeURIComponent(code))
        .then(res => res.json()).then(data => {
            var _scanProducts3 = data.products || data.data || [];
            if (_scanProducts3.length > 0) {
                selectRowProduct(newIdx, _scanProducts3.find(function(p) { return p.code === code; }) || _scanProducts3[0]);
            }
        });
};

// ============================================================
// === التحقق قبل الإرسال ===
// ============================================================
function validateForm() {
    var rows = document.querySelectorAll('.product-row');
    var hasValidProduct = false;
    var errors = [];

    rows.forEach(function(row) {
        var idx = row.id.replace('productRow_', '');
        var pid = document.getElementById('prod_id_' + idx).value;
        if (!pid) return;

        var qty = parseFloat(document.getElementById('prod_qty_' + idx).value) || 0;
        var price = parseFloat(document.getElementById('prod_price_' + idx).value) || 0;

        if (qty <= 0) {
            errors.push('يرجى إدخال الكمية للمنتج: ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx));
        }
        if (price <= 0) {
            errors.push('يرجى إدخال سعر الشراء للمنتج: ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx));
        }

        // التحقق من تواريخ الانتهاء
        if (productRowsData[idx] && productRowsData[idx].hasExpiry) {
            var container = document.getElementById('prod_expiry_container_' + idx);
            if (container) {
                var totalExpQty = 0;
                container.querySelectorAll('input[name="expiry_quantities[' + idx + '][]"]').forEach(function(input) {
                    totalExpQty += parseFloat(input.value) || 0;
                });
                var hasAnyDate = false;
                container.querySelectorAll('input[name="expiry_dates[' + idx + '][]"]').forEach(function(input) {
                    if (input.value) hasAnyDate = true;
                });
                if (!hasAnyDate) {
                    errors.push('يرجى إضافة تواريخ انتهاء للمنتج: ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx));
                } else {
                    var baseQty = getRowBaseQuantity(idx);
                    if (Math.abs(totalExpQty - baseQty) > 0.01) {
                        errors.push('منتج ' + (document.getElementById('prod_sel_name_' + idx).textContent || idx) + ': مجموع كميات الانتهاء (' + totalExpQty.toFixed(2) + ') لا يساوي الكمية (' + baseQty.toFixed(2) + ')');
                    }
                }
            }
        }

        if (qty > 0 && price > 0) hasValidProduct = true;
    });

    if (!hasValidProduct) {
        errors.unshift('يرجى إضافة منتج واحد على الأقل مع الكمية وسعر الشراء');
    }

    if (errors.length > 0) {
        alert('يرجى تصحيح الأخطاء التالية:\n\n' + errors.join('\n'));
        return false;
    }

    return true;
}

// ============================================================
// === إضافة صف منتج أولي عند تحميل الصفحة ===
// ============================================================
function toggleTaxFields() {
    var checked = document.getElementById('tax_enabled_checkbox').checked;
    var container = document.getElementById('taxFieldsContainer');
    var label = document.getElementById('taxToggleLabel');
    if (checked) {
        container.style.display = 'block';
        label.style.borderColor = '#6366f1';
        label.style.background = '#eef2ff';
    } else {
        container.style.display = 'none';
        label.style.borderColor = '#e2e8f0';
        label.style.background = '#fff';
    }
    recalculateTotals();
}

// تحديث نوع الضريبة
function onTaxTypeChange() {
    var taxType = document.getElementById('tax_type_select').value;
    var valueLabel = document.getElementById('tax_value_label');
    var valueHint = document.getElementById('tax_value_hint');
    if (taxType === 'percentage') {
        valueLabel.textContent = 'النسبة (%)';
        valueHint.textContent = 'مثال: 0.50 يعني 0.50%';
    } else {
        valueLabel.textContent = 'المبلغ';
        valueHint.textContent = 'المبلغ الإجمالي للضريبة';
    }
    recalculateTotals();
}

// ربط حدث onchange لاختيار نوع الضريبة
document.getElementById('tax_type_select').addEventListener('change', onTaxTypeChange);

document.addEventListener('DOMContentLoaded', function() {
    addProductRow();
    recalculateTotals();

    // التركيز على حقل البحث في الصف الأول (إذا لم يكن أثناء مسح باركود)
    if (!window._barcodeProcessing) {
        var firstInput = document.getElementById('prod_search_' + Object.keys(productRowsData)[0]);
        if (firstInput) firstInput.focus();
    }
});
</script>

<?php require_once '../includes/barcode-scanner.php'; ?>

<style>
/* === تحسين الوافقية مع الهواتف لصفحة المشتريات === */
@keyframes fadeSlideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 768px) {
    .remise-search-wrapper input,
    .remise-search-wrapper select,
    .product-row input,
    .product-row select {
        font-size: 16px !important;
    }
    .product-row-fields,
    .product-row-summary,
    .product-row-grid {
        grid-template-columns: 1fr !important;
    }
    .product-row {
        padding: 12px;
    }
}
@media (max-width: 480px) {
    .alert {
        padding: 10px 12px !important;
        font-size: 0.82rem !important;
    }
    .alert svg {
        display: none !important;
    }
}
/* تأثير hover لزر حذف المنتج */
.product-row .product-row-remove:hover { background: #fee2e2 !important; }
</style>

<?php require_once '../includes/footer.php'; ?>
