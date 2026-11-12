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
// v6.3.5 (M3): جسر القيم المولدة من PHP — المنطق في pages-add-purchase.js
const CURRENCY_SYMBOL = <?php echo json_encode(getCurrencySymbol(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?php echo $base_url ?? ''; ?>/assets/js/pages-add-purchase.js?<?php echo $css_version ?? 'v=6.3.5'; ?>"></script>

<?php require_once '../includes/barcode-scanner.php'; ?>

<link rel="stylesheet" href="<?php echo $base_url ?? ''; ?>/assets/css/pages-add-purchase.css?<?php echo $css_version ?? 'v=6.3.5'; ?>">

<?php require_once '../includes/footer.php'; ?>
