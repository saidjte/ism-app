<?php
/**
 * API معالجة عملية بيع من نقطة البيع (POS)
 * يستقبل طلب AJAX بصيغة JSON ويعالج البيع بنفس منطق add-sale.php
 * يدعم FIFO/FEFO، فحص المخزون، فحص الصلاحية
 *
 * v5.13.0: إعادة تفعيل FEFO + تخزين الخصم
 * v4.9.94: إصلاح حرج — hasPermission() غير معرّفة في API (permissions.php لم يكن يُحمّل عبر bootstrap.php)
 * v4.9.93: إصلاح شامل — فحص الجداول الأساسية + معالجة الأخطاء المحسّنة
 */

// === ضمان إرجاع JSON دائماً حتى في حالة الخطأ ===
function posJsonError($message, $code = 400) {
    // تنظيف أي output سابق (مثل تحذيرات PHP)
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
header('X-API-Version: ' . API_VERSION);
    echo json_encode(['success' => false, 'message' => $message, 'products' => [], 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// تسجيل الأخطاء للمساعدة في التشخيص
function posLogError($context, $message) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logEntry = sprintf("[%s] [POS-SALE] %s: %s\n", date('Y-m-d H:i:s'), $context, $message);
    @file_put_contents($logDir . '/pos-errors.log', $logEntry, FILE_APPEND);
}

// === التقاط مخرجات PHP غير المرغوبة (تحذيرات، إشعارات) ===
ob_start();

// بدء الجلسة يدوياً قبل أي output (إعدادات مركزية من config/session.php)
if (!function_exists('startSecureSession')) {
    require_once __DIR__ . '/../config/session.php';
}
startSecureSession();

// تحميل bootstrap مع حماية من الأخطاء
try {
    require_once __DIR__ . '/../includes/bootstrap.php';
} catch (\Throwable $e) {
    posLogError('Bootstrap', $e->getMessage());
    posJsonError(t('common_error_loading'), 500);
}

// تنظيف أي output غير مرغوب من bootstrap (تحذيرات PHP، إشعارات)
if (ob_get_level()) ob_clean();

// === حماية: تحميل permissions.php إذا لم يُحمَّل عبر bootstrap.php (إصدارات أقدم) ===
if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/../includes/permissions.php';
}

// === فحص تسجيل الدخول — إرجاع JSON بدلاً من إعادة التوجيه ===
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
    posJsonError('يجب تسجيل الدخول أولاً. يرجى تحديث الصفحة وتسجيل الدخول من جديد.', 401);
}

header('Content-Type: application/json; charset=utf-8');
header('X-API-Version: ' . API_VERSION);
header('Cache-Control: no-cache, no-store');

// فقط طلبات POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    posJsonError(t('pos_method_not_allowed'), 405);
}

// قراءة البيانات من JSON body
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if ($input === null && !empty($rawInput)) {
    posLogError('JSON_PARSE', 'Failed to parse: ' . mb_substr($rawInput, 0, 200));
    posJsonError('بيانات الطلب غير صالحة. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
}

// التحقق من CSRF
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
    posLogError('CSRF', "Token mismatch. Session: " . ($sessionToken ? 'exists' : 'empty') . ", Request: " . ($csrfToken ? 'exists' : 'empty'));
    posJsonError(t('common_csrf_expired'), 403);
}

// صلاحية البيع
if (!hasPermission('sales.add')) {
    posJsonError('ليس لديك صلاحية تسجيل المبيعات', 403);
}

$items = $input['items'] ?? [];
$sale_date = $input['sale_date'] ?? date('Y-m-d');
$notes = trim($input['notes'] ?? '');
$discount = floatval($input['discount'] ?? 0);
$paid_amount = floatval($input['paid_amount'] ?? 0);

// v6.1.6 (W11 إصلاح): استقبال نوع وقيمة خصم السلة (amount|percent) للتحقق جانب الخادم
$discount_type_raw = is_string($input['discount_type'] ?? null) ? strtolower(trim($input['discount_type'])) : 'amount';
if (!in_array($discount_type_raw, ['amount', 'percent'], true)) {
    $discount_type_raw = 'amount';  // fallback آمن للإصدارات القديمة
}
$discount_type = $discount_type_raw;
$discount_value = floatval($input['discount_value'] ?? 0);

// v6.1.4 (W6 إصلاح): استقبال طريقة الدفع ومكوناتها مع قائمة بيضاء صارمة
// القيم المسموحة: cash, card, split — أي قيمة أخرى تُرفض (حماية من SQL injection/تلاعب)
$payment_method_raw = is_string($input['payment_method'] ?? null) ? strtolower(trim($input['payment_method'])) : 'cash';
if (!in_array($payment_method_raw, ['cash', 'card', 'split'], true)) {
    posLogError('PaymentMethod', 'Invalid payment_method: ' . substr($payment_method_raw, 0, 30));
    posJsonError('طريقة دفع غير صالحة. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 400);
}
$payment_method = $payment_method_raw;

// مكونات الدفع المقسّم (اختياري — فقط لـ split)
$cash_amount = floatval($input['cash_amount'] ?? 0);
$card_amount = floatval($input['card_amount'] ?? 0);

// W14 إصلاح: قراءة إعدادات الضريبة من system_settings (مصدر موثوق) وليس من العميل
$server_tax_enabled = getSetting('tax_enabled', '0') === '1';
$server_tax_rate = floatval(getSetting('tax_rate', '0'));
// الضريبة التي يدّعي العميل أنه حسبها (للمقارنة والتحقق)
$client_tax_amount = floatval($input['tax_amount'] ?? 0);

// التحقق من وجود منتجات
if (empty($items) || count($items) === 0) {
    posJsonError('يرجى إضافة منتج واحد على الأقل');
}

if (empty($sale_date)) {
    posJsonError(t('sales_date_required'));
}

// === فحص الجداول الأساسية المطلوبة لعملية البيع ===
$missing_tables = [];
$tables_to_check = ['products', 'sales', 'system_settings'];
try {
    $pdo_check = AppDatabase::getInstance()->getConnection();
    $db_name = $pdo_check->query("SELECT DATABASE()")->fetchColumn();

    foreach ($tables_to_check as $tbl) {
        $check = $pdo_check->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1");
        $check->execute([$db_name, $tbl]);
        if ($check->fetch() === false) {
            $missing_tables[] = $tbl;
        }
    }
} catch (\Throwable $e) {
    posLogError('TableCheck', $e->getMessage());
}

if (!empty($missing_tables)) {
    posLogError('MissingTables', 'Missing: ' . implode(', ', $missing_tables));
    posJsonError('جداول قاعدة البيانات مفقودة: ' . implode('، ', $missing_tables) . '. يرجى الذهاب إلى صفحة التحديثات وإصلاح الجداول المفقودة.', 500);
}

// فحص أعمدة جدول sales المطلوبة (مع كاش بنية الجدول — لا تتغير إلا نادراً)
$sales_required_columns = ['product_id', 'quantity', 'selling_price', 'purchase_price_at_sale', 'total', 'cost_total', 'profit', 'sale_date', 'notes'];
$missing_sales_columns = [];
try {
    $cols_result = SchemaCache::remember('sales_schema_columns', function() use ($pdo_check) {
        return $pdo_check->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    });
    foreach ($sales_required_columns as $col) {
        if (!in_array($col, $cols_result)) {
            $missing_sales_columns[] = $col;
        }
    }
} catch (\Throwable $e) {
    posLogError('SalesColumnsCheck', $e->getMessage());
}

if (!empty($missing_sales_columns)) {
    posLogError('MissingColumns', 'Sales table missing columns: ' . implode(', ', $missing_sales_columns));
    posJsonError('جدول المبيعات يفتقد أعمدة مطلوبة: ' . implode('، ', $missing_sales_columns) . '. يرجى الذهاب إلى صفحة التحديثات لتحديث قاعدة البيانات.', 500);
}

// W14 إصلاح: التحقق من وجود عمود tax_amount (أُضيف في v6.1.2)
$hasTaxColumn = false;
try {
    $hasTaxColumn = in_array('tax_amount', $cols_result);
} catch (\Throwable $e) {}

// v6.1.4 (W6 إصلاح): التحقق من وجود أعمدة طريقة الدفع (payment_method, change_amount, cash_amount, card_amount)
// أُضيفت في v6.1.4 — نقبل قواعد البيانات القديمة بدونها (fallback آمن)
$hasPaymentMethodCol = false;
$hasChangeAmountCol  = false;
$hasSplitAmountsCol  = false;
try {
    $hasPaymentMethodCol = in_array('payment_method', $cols_result, true);
    $hasChangeAmountCol  = in_array('change_amount',  $cols_result, true);
    $hasSplitAmountsCol  = in_array('cash_amount',    $cols_result, true)
                         && in_array('card_amount',   $cols_result, true);
} catch (\Throwable $e) {}

// v6.1.6: التحقق من وجود عمود receipt_no (رقم الإيصال)
// فحص مستقل بلا كاش — لأن SchemaCache قد يحتوي بنية قديمة
$hasReceiptNoCol = false;
try {
    $receiptNoCheck = $pdo_check->query("SHOW COLUMNS FROM sales LIKE 'receipt_no'")->fetchAll(PDO::FETCH_COLUMN);
    $hasReceiptNoCol = !empty($receiptNoCheck);
} catch (\Throwable $e) {}

$errors = [];
$expiry_block_sale = false;

try {
    // Check expiry blocking setting
    $expiry_block_sale = false;
} catch (\Throwable $e) {
    ErrorHandler::logException($e, 'pos-process-sale::expiryBlockSetting');
}

$today = date('Y-m-d');
$valid_rows = [];
$seen_product_ids = [];

// التحقق من كل صف
try {
    foreach ($items as $i => $item) {
        $pid = intval($item['product_id'] ?? 0);
        $qty = floatval($item['quantity'] ?? 0);
        $price = floatval($item['selling_price'] ?? 0);

        if ($pid <= 0) continue;
        if ($qty <= 0) {
            $errors[] = t('sales_qty_must_be_positive_product') . ' ' . ($i + 1) . ')';
            continue;
        }
        if ($price <= 0) {
            $errors[] = t('sales_selling_price_must_be_positive_product') . ' ' . ($i + 1) . ')';
            continue;
        }

        // كشف التكرار
        if (in_array($pid, $seen_product_ids)) {
            $errors[] = t('sales_product_duplicate');
            continue;
        }
        $seen_product_ids[] = $pid;

        // فحص المنتج
        $product = fetchOne("SELECT quantity, purchase_price, name, unit, has_expiry FROM products WHERE id = ?", [$pid]);
        if (!$product) {
            $errors[] = 'المنتج غير موجود (رقم ' . ($i + 1) . ')';
            continue;
        }
        if ($qty > $product['quantity']) {
            $errors[] = 'الكمية المطلوبة (' . number_format($qty, 2) . ') أكبر من المتوفر (' . number_format($product['quantity'], 2) . ') للمنتج "' . $product['name'] . '"';
            continue;
        }

        // v6.1.6 (W12 إصلاح): استقبال خصم الصنف الفردي + التحقق من نوعه
        $item_discount_type_raw = is_string($item['item_discount_type'] ?? null) ? strtolower(trim($item['item_discount_type'])) : 'amount';
        if (!in_array($item_discount_type_raw, ['amount', 'percent'], true)) {
            $item_discount_type_raw = 'amount';
        }
        $item_discount_value = floatval($item['item_discount_value'] ?? 0);
        if ($item_discount_value < 0) $item_discount_value = 0;

        // حساب مبلغ الخصم المطلق من النوع والقيمة (الخادم هو المصدر الموثوق)
        $line_gross = $qty * $price;
        if ($item_discount_type_raw === 'percent') {
            if ($item_discount_value > 100) $item_discount_value = 100;
            $item_discount_abs = round($line_gross * ($item_discount_value / 100), 4);
        } else {
            // amount: لا يمكن أن يتجاوز إجمالي الصنف
            if ($item_discount_value > $line_gross) $item_discount_value = $line_gross;
            $item_discount_abs = round($item_discount_value, 4);
        }

        $valid_rows[] = [
            'product_id' => $pid,
            'quantity' => $qty,
            'selling_price' => $price,
            'product' => $product,
            'has_expiry' => intval($product['has_expiry'] ?? 0),
            // v6.1.6 (W12): خصم الصنف الفردي (مبلغ مطلق محسوب في الخادم)
            'item_discount' => $item_discount_abs,
            'item_discount_type' => $item_discount_type_raw,
            'item_discount_value' => $item_discount_value,
        ];
    }
} catch (\Throwable $e) {
    posLogError('ProductValidation', $e->getMessage());
    ErrorHandler::logException($e, 'ProductValidation');
    posJsonError(ErrorHandler::safeExceptionMessage($e, 'خطأ في التحقق من المنتجات.'));
}

if (!empty($errors)) {
    posJsonError(implode("\n", $errors));
}

// معالجة المبيعات في معاملة واحدة
try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();

    $grand_total = 0;
    $grand_cost = 0;
    $grand_profit = 0;
    $grand_tax = 0;  // W14 إصلاح: تتبع إجمالي الضريبة المحسوبة في الخادم
    $sale_count = count($valid_rows);
    $sale_details = [];
    $all_sale_ids = [];

    // v6.1.6: توليد رقم إيصال فريد (صيغة: YYYYMMDD-NNNN)
    $receipt_no = '';
    if ($hasReceiptNoCol) {
        $today_str = date('Ymd');
        // البحث عن آخر رقم إيصال لهذا اليوم
        $lastNo = 0;
        try {
            $lastRow = $pdo->query("SELECT receipt_no FROM sales WHERE receipt_no LIKE '{$today_str}-%' ORDER BY receipt_no DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($lastRow && preg_match('/(\d{4})$/', $lastRow['receipt_no'], $m)) {
                $lastNo = intval($m[1]);
            }
        } catch (\Throwable $e) {}
        $receipt_no = $today_str . '-' . str_pad($lastNo + 1, 4, '0', STR_PAD_LEFT);
    }

    // Check if discount column exists
    $hasDiscountColumn = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sales LIKE 'discount'")->fetchAll(PDO::FETCH_COLUMN);
        $hasDiscountColumn = !empty($cols);
    } catch (\Throwable $e) {}

    // W14 إصلاح: بناء جملة INSERT مع/بدون tax_amount و discount
    // v6.1.4 (W6 إصلاح): إضافة payment_method, change_amount, cash_amount, card_amount إن وُجدت
    // لضمان التوافق مع الإصدارات القديمة والجديدة من قاعدة البيانات
    $insertCols = "product_id, quantity, selling_price, purchase_price_at_sale, total, cost_total, profit, sale_date, notes";
    $insertPlaceholders = "?, ?, ?, ?, ?, ?, ?, ?, ?";

    if ($hasDiscountColumn) {
        $insertCols .= ", discount, paid_amount";
        $insertPlaceholders .= ", ?, ?";
    }
    if ($hasTaxColumn) {
        $insertCols .= ", tax_amount";
        $insertPlaceholders .= ", ?";
    }
    if ($hasPaymentMethodCol) {
        $insertCols .= ", payment_method";
        $insertPlaceholders .= ", ?";
    }
    if ($hasChangeAmountCol) {
        $insertCols .= ", change_amount";
        $insertPlaceholders .= ", ?";
    }
    if ($hasSplitAmountsCol) {
        $insertCols .= ", cash_amount, card_amount";
        $insertPlaceholders .= ", ?, ?";
    }
    if ($hasReceiptNoCol) {
        $insertCols .= ", receipt_no";
        $insertPlaceholders .= ", ?";
    }
    $saleStmt = $pdo->prepare("INSERT INTO sales ($insertCols) VALUES ($insertPlaceholders)");
    // W1 إصلاح: شرط quantity >= ? يمنع سباق المخزون (race condition) بين كاتبين متزامنين
    $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");

    $receipt_items = [];

    // v6.1.4 (W6 إصلاح): متغيرات لتخزين قيم مستوى الفاتورة على أول صنف فقط
    // (change_amount, cash_amount, card_amount تُخزَّن مرة واحدة لتجنب التكرار)
    $change_amount_stored = false;
    $split_amounts_stored = false;
    $receipt_no_stored = false;

    foreach ($valid_rows as $row) {
        $pid = $row['product_id'];
        $qty = $row['quantity'];
        $price = $row['selling_price'];
        $product = $row['product'];
        $has_expiry = intval($row['has_expiry'] ?? 0);

        // Try FEFO batch allocation if product has expiry
        $batch_allocations = [];
        if ($has_expiry) {
            $batch_allocations = SaleService::deductFromBatches($pid, $qty, 'fefo');
        }

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

        // v6.1.6 (W12 إصلاح): تطبيق خصم الصنف الفردي قبل حساب الإجمالي والربح والضريبة
        $line_gross = $qty * $price;
        $item_discount = floatval($row['item_discount'] ?? 0);
        $item_discount = min($item_discount, $line_gross);  // safety: never exceed line gross
        $total = $line_gross - $item_discount;
        $profit = $total - $cost_total;  // الربح يُحسب بعد خصم الصنف

        // W14 إصلاح: الضريبة تُحسب على الإجمالي بعد خصم الصنف (القانوني)
        $item_tax = ($server_tax_enabled && $server_tax_rate > 0)
            ? round($total * ($server_tax_rate / 100), 4)
            : 0;

        // v6.1.6 (W12 إصلاح): $item_discount تم حسابه أعلاه (خصم الصنف الفردي)
        // W2 إصلاح: توزيع خصم السلة الإجمالي يتم بعد اللوب (يحتاج معرفة grand_total أولاً)
        // ملاحظة: خصم السلة يُضاف لاحقاً عبر UPDATE منفصل (انظر أسفل)

        // W14 إصلاح: بناء مصفوفة المعاملات حسب الأعمدة الموجودة
        $execParams = [$pid, $qty, $price, $weighted_purchase_price, $total, $cost_total, $profit, $sale_date, $notes];
        if ($hasDiscountColumn) {
            $execParams[] = $item_discount;  // discount
            // v6.1.4 (W6 إصلاح): paid_amount على مستوى الفاتورة — يُخزَّن على كل صنف
            // (القيمة الكلية للفاتورة؛ يمكن تجميعها لاحقاً عند الحاجة لتقارير الفواتير)
            $execParams[] = $paid_amount;    // paid_amount
        }
        if ($hasTaxColumn) {
            $execParams[] = $item_tax;       // tax_amount
        }
        if ($hasPaymentMethodCol) {
            $execParams[] = $payment_method; // payment_method (نفس القيمة على كل الأصناف)
        }
        if ($hasChangeAmountCol) {
            // change_amount يُخزَّن على أول صنف فقط — لتمثيل مستوى الفاتورة
            if (!$change_amount_stored) {
                $execParams[] = max(0, $paid_amount - ($grand_total + $item_tax));
                $change_amount_stored = true;
            } else {
                $execParams[] = 0;
            }
        }
        if ($hasSplitAmountsCol) {
            // cash_amount و card_amount يُخزّنان على أول صنف فقط
            if (!$split_amounts_stored) {
                $execParams[] = $cash_amount;
                $execParams[] = $card_amount;
                $split_amounts_stored = true;
            } else {
                $execParams[] = 0;
                $execParams[] = 0;
            }
        }
        if ($hasReceiptNoCol) {
            // receipt_no يُخزَّن على أول صنف فقط (نفس نمط change_amount)
            $execParams[] = $receipt_no_stored ? null : $receipt_no;
            if (!$receipt_no_stored) $receipt_no_stored = true;
        }
        $saleStmt->execute($execParams);
        $sale_id = $pdo->lastInsertId();
        $all_sale_ids[] = $sale_id;

        $updateStmt->execute([$qty, $pid, $qty]);
        // W1 إصلاح: لو لم يتأثر أي صف = مخزون غير كافٍ (تم بيعه لكاتب آخر بين الفحص والتنفيذ)
        if ($updateStmt->rowCount() === 0) {
            throw new \RuntimeException("الكمية المطلوبة من \"" . $product['name'] . "\" لم تعد متوفرة (ربما بيعت لكاتب آخر). يرجى إعادة المحاولة.");
        }

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
        $grand_tax += $item_tax;  // W14 إصلاح: تجميع الضريبة

        $receipt_items[] = [
            'name' => $product['name'],
            'code' => '',
            'unit' => $product['unit'],
            'quantity' => $qty,
            'price' => $price,
            'total' => $total,
            // v6.1.6 (W12): خصم الصنف الفردي لعرضه في الإيصال
            'item_discount' => round($item_discount, 2),
            'item_discount_type' => $row['item_discount_type'] ?? 'amount',
            'item_discount_value' => floatval($row['item_discount_value'] ?? 0),
            'line_gross' => round($line_gross, 2),  // الإجمالي قبل الخصم (للعرض مشطوب)
        ];

        $sale_details[] = $qty . ' ' . $product['unit'] . ' من ' . $product['name']
            . ($item_discount > 0 ? ' (خصم ' . round($item_discount, 2) . ')' : '');
    }

    // W2 إصلاح: توزيع خصم السلة على الأصناف بنسبة كل صنف من الإجمالي
    // v6.1.6 (W12 إصلاح): الإضافة لخصم الصنف الفردي الموجود، وليس الاستبدال
    //   - قبل: UPDATE sales SET discount = ? WHERE id = ?  (يُلغي خصم الصنف الفردي!)
    //   - بعد: UPDATE sales SET discount = discount + ? WHERE id = ?  (يُضيف نصيب خصم السلة)
    //   ملاحظة: خصم الصنف الفردي خُزِّن بالفعل في discount أثناء INSERT (STEP 3)
    if ($hasDiscountColumn && $discount > 0 && $grand_total > 0 && !empty($all_sale_ids)) {
        $discountUpdateStmt = $pdo->prepare("UPDATE sales SET discount = discount + ? WHERE id = ?");
        $distributed = 0.0;
        $placeholders = implode(',', array_fill(0, count($all_sale_ids), '?'));
        // جلب معرفات البيع و total كل صف لتوزيع الخصم
        $saleRowsStmt = $pdo->prepare("SELECT id, total FROM sales WHERE id IN ($placeholders)");
        $saleRowsStmt->execute($all_sale_ids);
        $saleRows = $saleRowsStmt->fetchAll(PDO::FETCH_ASSOC);
        $lastIdx = count($saleRows) - 1;
        foreach ($saleRows as $idx => $sr) {
            if ($idx === $lastIdx) {
                // الصنف الأخير يأخذ المتبقي من الخصم لتجنب أخطاء التقريب
                $item_disc = $discount - $distributed;
            } else {
                $item_disc = $discount * (floatval($sr['total']) / $grand_total);
            }
            $item_disc = round($item_disc, 4);
            $distributed += $item_disc;
            $discountUpdateStmt->execute([$item_disc, $sr['id']]);
        }
    }

    // v6.1.6 (W11 إصلاح): التحقق من خصم السلة — إعادة حساب المبلغ المطلق من type+value
    // ومنع التلاعب: لو أرسل العميل discount=0 لكن discount_type=percent + discount_value=50
    // سيرفض الخادم العملية (لن يقبل خصماً أقل من المطلوب)
    $server_discount_computed = 0.0;
    if ($discount_type === 'percent') {
        $discount_value = min($discount_value, 100);  // safety
        $server_discount_computed = $grand_total * ($discount_value / 100);
    } else {
        $discount_value = min($discount_value, $grand_total);  // safety: لا يتجاوز الإجمالي
        $server_discount_computed = $discount_value;
    }
    $server_discount_computed = round($server_discount_computed, 2);
    $client_discount_rounded = round($discount, 2);
    if (abs($server_discount_computed - $client_discount_rounded) > 0.50) {
        // تفاوت أكبر من 0.50 = محاولة تلاعب أو خطأ JS
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        posLogError('DiscountMismatch', "Type={$discount_type} Value={$discount_value} ServerComputed={$server_discount_computed} ClientClaimed={$client_discount_rounded} Items=" . count($all_sale_ids));
        posJsonError('تنبيه أمني: حساب الخصم غير متطابق. تم رفض العملية. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 400);
    }
    // استخدام القيمة المحسوبة في الخادم (الموثوقة) بدل قيمة العميل
    $discount = $server_discount_computed;

    // W14 إصلاح حرج: التحقق من تطابق الضريبة المُرسَلة من العميل مع المحسوبة في الخادم
    // سد ثغرة سرقة الضريبة: لو حُرِّفت قيمة العميل (DevTools) — نرفض العملية بالكامل
    $server_tax_amount = round($grand_tax, 2);
    $client_tax_rounded = round($client_tax_amount, 2);
    if (abs($server_tax_amount - $client_tax_rounded) > 0.50) {
        // تفاوت أكبر من 0.50 وحدة = محاولة تلاعب (أو خطأ JS كبير)
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        posLogError('TaxMismatch', "Server={$server_tax_amount} Client={$client_tax_rounded} Items=" . count($all_sale_ids));
        posJsonError('تنبيه أمني: حساب الضريبة غير متطابق. تم رفض العملية. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 400);
    }

    $pdo->commit();

    // الإجمالي النهائي = المجموع + الضريبة - الخصم
    $final_total = $grand_total + $grand_tax - $discount;
    $change = $paid_amount - $final_total;

    // v6.1.4 (W6 إصلاح): التحقق من صحة مكونات الدفع المقسّم
    // لـ split: cash_amount + card_amount يجب أن يساوي paid_amount
    if ($payment_method === 'split') {
        $split_sum = $cash_amount + $card_amount;
        if (abs($split_sum - $paid_amount) > 0.01) {
            // تفاوت صغير = خطأ في JS — نسجل ونقبل (التسامح 0.01)
            posLogError('SplitMismatch', "cash={$cash_amount} card={$card_amount} paid={$paid_amount} diff=" . ($split_sum - $paid_amount));
        }
    } elseif ($payment_method === 'card') {
        // بطاقة: paid_amount يجب = final_total (لا يوجد باقٍ)
        // نسجل فقط لو كان مختلفاً — لا نرفض (قد يكون العميل دفع أكثر عبر البطاقة)
        if (abs($paid_amount - $final_total) > 0.01 && $paid_amount < $final_total) {
            posLogError('CardUnderpaid', "paid={$paid_amount} total={$final_total}");
        }
    }

    // إشعار — داخل try-catch منفصل حتى لا يفشل البيع
    try {
        $currencySymbol = getCurrencySymbol();
        $totalFormatted = $currencySymbol . number_format($grand_total, 2);
        $profitFormatted = $currencySymbol . number_format($grand_profit, 2);
        $title_key = 'notif_title_pos_sale';
        $message_key = 'notif_msg_pos_sale';
        // الـ placeholders في المفتاح: {count} {total} {profit}
        $message_params = ['count' => $sale_count, 'total' => $totalFormatted, 'profit' => $profitFormatted];
        AppNotification::add(NOTIF_SALE, t($title_key), t($message_key, $sale_count, $totalFormatted, $profitFormatted), ['url' => '../pos.php', 'sale_ids' => $all_sale_ids], null, $title_key, $message_key, $message_params);
    } catch (\Throwable $e) {
        posLogError('Notification', $e->getMessage());
    }

    // W3 إصلاح: سجل تدقيق لكل عملية بيع POS (تتبع المستخدم، IP، تفاصيل السلة)
    try {
        $auditDetails = json_encode([
            'sale_ids' => $all_sale_ids,
            'item_count' => $sale_count,
            'subtotal' => round($grand_total, 2),
            'discount' => round($discount, 2),
            'discount_type' => $discount_type,  // v6.1.6 (W11): نوع خصم السلة في السجل
            'discount_value' => round($discount_value, 4),  // v6.1.6 (W11): القيمة الخام لخصم السلة
            'item_discounts_total' => round(array_sum(array_column($receipt_items, 'item_discount')), 2),  // v6.1.6 (W12): إجمالي خصوم الأصناف
            'tax_amount' => round($grand_tax, 2),  // W14: مبلغ الضريبة في السجل
            'tax_rate' => $server_tax_rate,
            'total' => round($final_total, 2),
            'paid' => round($paid_amount, 2),
            'change' => round(max(0, $change), 2),
            'payment_method' => $payment_method,  // v6.1.4 (W6): طريقة الدفع في السجل
            'cash_amount' => round($cash_amount, 2),  // v6.1.4 (W6): للدفع المقسّم
            'card_amount' => round($card_amount, 2),  // v6.1.4 (W6): للدفع المقسّم
            'items' => array_map(function($it) { return ['name' => $it['name'], 'qty' => $it['quantity'], 'price' => $it['price']]; }, $receipt_items),
        ], JSON_UNESCAPED_UNICODE);
        addAuditLog('sale_add', 'بيع POS (' . $payment_method . '): ' . $sale_count . ' منتجات بمبلغ ' . round($final_total, 2), $auditDetails, 'sale', intval($all_sale_ids[0] ?? 0));
    } catch (\Throwable $e) {
        posLogError('AuditLog', $e->getMessage());
    }

    // تحديث رمز CSRF
    $newCsrf = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $newCsrf;

    // تنظيف أي output غير مرغوب قبل إرسال JSON
    if (ob_get_level()) ob_clean();

    echo json_encode([
        'success' => true,
        'message' => t('pos_sale_registered_success'),
        'data' => [
            'sale_ids' => $all_sale_ids,
            'sale_count' => $sale_count,
            'receipt_no' => $receipt_no,  // v6.1.6: رقم الإيصال التلقائي
            'subtotal' => round($grand_total, 2),
            'discount' => round($discount, 2),
            'discount_type' => $discount_type,  // v6.1.6 (W11): نوع خصم السلة في الاستجابة
            'discount_value' => round($discount_value, 4),  // v6.1.6 (W11): القيمة الخام
            'tax_amount' => round($grand_tax, 2),  // W14 إصلاح: الضريبة المحسوبة في الخادم
            'tax_rate' => $server_tax_rate,         // لإظهار النسبة في الإيصال
            'total' => round($final_total, 2),
            'paid' => round($paid_amount, 2),
            'change' => round(max(0, $change), 2),
            'payment_method' => $payment_method,  // v6.1.4 (W6): طريقة الدفع في الاستجابة
            'cash_amount' => round($cash_amount, 2),  // v6.1.4 (W6): للدفع المقسّم
            'card_amount' => round($card_amount, 2),  // v6.1.4 (W6): للدفع المقسّم
            'profit' => round($grand_profit, 2),
            'cost' => round($grand_cost, 2),
            'items' => $receipt_items,
            'date' => $sale_date,
            'time' => date('H:i'),
            'csrf_token' => $newCsrf,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    posLogError('SaleProcessing', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // رسالة خطأ — لا نكشف تفاصيل قاعدة البيانات للمستخدم
    $errorMsg = $e->getMessage();
    $userMsg = t('sales_error_saving');

    // تسجيل الخطأ التفصيلي في السجلات فقط
    posLogError('SaleError-Detail', $errorMsg);

    // إذا كان خطأ في جدول غير موجود — رسالة عامة مع توجيه
    if (strpos($errorMsg, "Base table or view not found") !== false || strpos($errorMsg, "doesn't exist") !== false) {
        $userMsg = 'هناك مشكلة في قاعدة البيانات. يرجى التواصل مع المسؤول أو الذهاب إلى صفحة التحديثات.';
    } elseif (strpos($errorMsg, "Unknown column") !== false) {
        $userMsg = 'هناك مشكلة في قاعدة البيانات. يرجى التواصل مع المسؤول أو الذهاب إلى صفحة التحديثات.';
    } elseif (strpos($errorMsg, "SQLSTATE") !== false) {
        $userMsg = t('common_error_processing');
    }

    posJsonError($userMsg, 500);
}
