<?php
$page_title = 'إدارة وحدات القياس';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();

// حساب base_url مبكراً لاستخدامه في التحويلات قبل header.php
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_url = in_array(basename($script_dir), ['products', 'purchases', 'sales', 'api', 'users', 'settings', 'expenses', 'units', 'categories']) 
    ? rtrim(dirname($script_dir), '/') 
    : rtrim($script_dir, '/');
if (empty($base_url)) $base_url = '';

$errors = [];
$success = false;
$editing = null;

// أنواع الوحدات
$unit_types = [
    'quantity' => 'كمية (عدد)',
    'weight'   => 'وزن',
    'volume'   => 'حجم / سائل',
    'length'   => 'طول',
    'area'     => 'مساحة',
    'other'    => 'أخرى',
];

// ألوان لأنواع الوحدات
$unit_type_colors = [
    'quantity' => '#2563eb',
    'weight'   => '#059669',
    'volume'   => '#7c3aed',
    'length'   => '#d97706',
    'area'     => '#0891b2',
    'other'    => '#64748b',
];

// الوحدات الأساسية حسب النوع
$base_units_by_type = [
    'quantity' => ['name' => 'قطعة', 'abbreviation' => 'قط', 'hint' => '1 وحدة = 1 قطعة'],
    'weight'   => ['name' => 'كيلوغرام', 'abbreviation' => 'كلغ', 'hint' => '1 وحدة = ? كيلوغرام'],
    'volume'   => ['name' => 'لتر', 'abbreviation' => 'لتر', 'hint' => '1 وحدة = ? لتر'],
    'length'   => ['name' => 'متر', 'abbreviation' => 'م', 'hint' => '1 وحدة = ? متر'],
    'area'     => ['name' => 'متر مربع', 'abbreviation' => 'م²', 'hint' => '1 وحدة = ? م²'],
];

// حذف وحدة (POST فقط) - قبل إرسال أي محتوى HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_unit') {
    requireCSRF();
    $delete_id = intval($_POST['unit_id'] ?? 0);
    try {
        // التحقق من استخدام الوحدة في المنتجات
        $count_products = fetchOne("SELECT COUNT(*) as total FROM products WHERE unit = (SELECT abbreviation FROM measurement_units WHERE id = ?)", [$delete_id])['total'];
        // التحقق من استخدام الوحدة في الوحدات الإضافية
        $count_additional = 0;
        try {
            $count_additional = fetchOne("SELECT COUNT(*) as total FROM additional_units WHERE unit_name = (SELECT abbreviation FROM measurement_units WHERE id = ?)", [$delete_id])['total'];
        } catch (Exception $e) {
            // جدول additional_units قد لا يكون موجوداً بعد
        }
        $total_usage = $count_products + $count_additional;

        if ($total_usage > 0) {
            $error_msg = 'لا يمكن حذف هذه الوحدة لأنها مستخدمة في ' . $total_usage . ' منتج(ات) أو وحدة إضافية. يمكنك تعطيلها بدلاً من ذلك.';
        } else {
            executeQuery("DELETE FROM measurement_units WHERE id = ?", [$delete_id]);
            header("Location: " . $base_url . "/units/list-units.php?msg=deleted");
            exit;
        }
    } catch (Exception $e) {
        $error_msg = 'حدث خطأ أثناء حذف الوحدة';
    }
}

// تفعيل/تعطيل وحدة (POST فقط) - قبل إرسال أي محتوى HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_unit') {
    requireCSRF();
    $toggle_id = intval($_POST['unit_id'] ?? 0);
    try {
        $unit = fetchOne("SELECT is_active FROM measurement_units WHERE id = ?", [$toggle_id]);
        if ($unit) {
            $new_status = $unit['is_active'] ? 0 : 1;
            executeQuery("UPDATE measurement_units SET is_active = ? WHERE id = ?", [$new_status, $toggle_id]);
            header("Location: " . $base_url . "/units/list-units.php?msg=toggled");
            exit;
        }
    } catch (Exception $e) {
        $error_msg = 'حدث خطأ';
    }
}

require_once '../includes/header.php';

// تعديل وحدة
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editing = fetchOne("SELECT * FROM measurement_units WHERE id = ?", [intval($_GET['edit'])]);
}

// إضافة أو تعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $edit_id = intval($_POST['edit_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $abbreviation = trim($_POST['abbreviation'] ?? '');
    $unit_type = $_POST['unit_type'] ?? 'quantity';
    $description = trim($_POST['description'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_base_unit = isset($_POST['is_base_unit']) ? 1 : 0;
    $conversion_to_base = floatval($_POST['conversion_to_base'] ?? 1);

    // إذا كانت وحدة أساسية، اجعل معامل التحويل = 1 تلقائياً
    if ($is_base_unit) {
        $conversion_to_base = 1;
    }

    if (empty($name)) $errors[] = 'اسم الوحدة مطلوب';
    if (empty($abbreviation)) $errors[] = 'الرمز المختصر مطلوب';
    if (!isset($unit_types[$unit_type])) $errors[] = 'نوع الوحدة غير صالح';
    if ($conversion_to_base <= 0) $errors[] = 'معامل التحويل يجب أن يكون أكبر من صفر';

    if (empty($errors)) {
        if ($edit_id > 0) {
            $existing = fetchOne("SELECT id FROM measurement_units WHERE name = ? AND id != ?", [$name, $edit_id]);
        } else {
            $existing = fetchOne("SELECT id FROM measurement_units WHERE name = ?", [$name]);
        }
        if ($existing) $errors[] = 'اسم الوحدة موجود مسبقاً';

        $abb_existing = fetchOne("SELECT id FROM measurement_units WHERE abbreviation = ? AND id != ?", [$abbreviation, $edit_id]);
        if ($abb_existing) $errors[] = 'الرمز المختصر موجود مسبقاً';
    }

    // التحقق من وجود وحدة أساسية أخرى لنفس النوع (عدا الوحدة الحالية)
    if ($is_base_unit && empty($errors)) {
        $other_base = fetchOne("SELECT id, name FROM measurement_units WHERE unit_type = ? AND is_base_unit = 1 AND id != ?", [$unit_type, $edit_id]);
        if ($other_base) {
            $errors[] = 'يوجد وحدة أساسية أخرى لنوع "' . $unit_types[$unit_type] . '": ' . $other_base['name'] . '. يجب إلغاء تفعيلها أولاً.';
        }
    }

    if (empty($errors)) {
        try {
            if ($edit_id > 0) {
                executeQuery("UPDATE measurement_units SET name=?, abbreviation=?, unit_type=?, description=?, sort_order=?, is_active=?, is_base_unit=?, conversion_to_base=? WHERE id=?",
                    [$name, $abbreviation, $unit_type, $description ?: null, $sort_order, $is_active, $is_base_unit, $conversion_to_base, $edit_id]);
                $success_msg = 'تم تحديث الوحدة بنجاح';
            } else {
                insertData("INSERT INTO measurement_units (name, abbreviation, unit_type, description, sort_order, is_active, is_base_unit, conversion_to_base) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $abbreviation, $unit_type, $description ?: null, $sort_order, $is_active, $is_base_unit, $conversion_to_base]);
                $success_msg = 'تم إضافة الوحدة بنجاح';
            }
            $success = true;
            $editing = null;
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'save_unit');
            $errors[] = 'حدث خطأ: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ الوحدة');
        }
    }
}

// رسائل
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $success_msg = 'تم حذف الوحدة بنجاح';
    if ($_GET['msg'] == 'toggled') $success_msg = 'تم تغيير حالة الوحدة بنجاح';
}

// جلب الوحدات مع إحصائيات الاستخدام
$units = [];
try {
    $units = fetchAll("SELECT mu.*,
        (SELECT COUNT(*) FROM products WHERE unit = mu.abbreviation) as products_count,
        (SELECT COUNT(*) FROM additional_units WHERE unit_name = mu.abbreviation) as additional_count
        FROM measurement_units mu
        ORDER BY mu.sort_order ASC, mu.name ASC");
} catch (Exception $e) {
    // جدول additional_units قد لا يكون موجوداً بعد - استعلام بدونها
    try {
        $units = fetchAll("SELECT mu.*,
            (SELECT COUNT(*) FROM products WHERE unit = mu.abbreviation) as products_count,
            0 as additional_count
            FROM measurement_units mu
            ORDER BY mu.sort_order ASC, mu.name ASC");
    } catch (Exception $e2) {
        $units = [];
    }
}
?>

<style>
/* === لوحة الوحدات الأساسية === */
.base-units-panel {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}
.base-units-panel-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: 0.85rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.base-units-panel-title svg {
    width: 20px;
    height: 20px;
    stroke: #2563eb;
}
.base-units-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.base-unit-card {
    display: flex;
    align-items: center;
    gap: 8px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.85rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.base-unit-card .bu-icon {
    font-size: 1.1rem;
}
.base-unit-card .bu-type {
    color: #94a3b8;
    font-size: 0.75rem;
    display: block;
}
.base-unit-card .bu-name {
    font-weight: 700;
    color: #1e293b;
}
.base-unit-card .bu-abb {
    color: #64748b;
    font-size: 0.8rem;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 4px;
}

/* === صندوق معلومة الوحدة الأساسية في النموذج === */
.base-unit-info-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 10px 16px;
    font-size: 0.9rem;
    color: #1e40af;
    margin-bottom: 0.5rem;
    transition: all 0.3s ease;
}
.base-unit-info-box .buinfo-icon {
    font-size: 1.15rem;
    flex-shrink: 0;
}
.base-unit-info-box .buinfo-text {
    font-weight: 600;
}
.base-unit-info-box .buinfo-hint {
    color: #3b82f6;
    font-size: 0.82rem;
    font-weight: 400;
    margin-right: 6px;
}

/* === حقول الوحدة الأساسية ومعامل التحويل === */
.base-conversion-row {
    display: flex;
    gap: 16px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 0.5rem;
}
.base-unit-checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: #fefce8;
    border: 1px solid #fde68a;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.base-unit-checkbox-group:hover {
    background: #fef9c3;
}
.base-unit-checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #d97706;
    cursor: pointer;
}
.base-unit-checkbox-group label {
    font-weight: 600;
    color: #92400e;
    cursor: pointer;
    font-size: 0.9rem;
    user-select: none;
}
.conversion-field-group {
    flex: 1;
    min-width: 200px;
}
.conversion-field-group label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 4px;
    display: block;
}
.conversion-input-wrap {
    position: relative;
}
.conversion-input-wrap input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    text-align: center;
    font-weight: 600;
    transition: all 0.2s;
}
.conversion-input-wrap input:disabled {
    background: #f1f5f9;
    color: #94a3b8;
    cursor: not-allowed;
}
.conversion-hint-text {
    font-size: 0.78rem;
    color: #64748b;
    margin-top: 4px;
}
.conversion-hint-text strong {
    color: #334155;
}

/* === نجمة الوحدة الأساسية في الجدول === */
.base-unit-star {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-radius: 50%;
    font-size: 0.8rem;
    margin-inline-end: 4px;
    box-shadow: 0 1px 3px rgba(217,119,6,0.2);
}
.conversion-factor-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.78rem;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 4px;
    margin-top: 2px;
}
.conversion-factor-badge strong {
    color: #334155;
    font-weight: 700;
}
</style>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
            </svg>
            إدارة وحدات القياس
        </h1>
    </div>

    <!-- إحصائيات -->
    <div class="stats-grid">
        <?php
        $total_units = count($units);
        $active_units = count(array_filter($units, fn($u) => $u['is_active']));
        $used_units = count(array_filter($units, fn($u) => ($u['products_count'] + $u['additional_count']) > 0));
        // إحصائيات حسب النوع
        $type_counts = [];
        foreach ($units as $u) {
            $t = $u['unit_type'];
            if (!isset($type_counts[$t])) $type_counts[$t] = 0;
            $type_counts[$t]++;
        }
        ?>
        <div class="stat-card blue">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
            </div>
            <div class="stat-info"><h3>إجمالي الوحدات</h3><div class="stat-value"><?php echo $total_units; ?></div></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M5 13l4 4L19 7"/></svg>
            </div>
            <div class="stat-info"><h3>الوحدات النشطة</h3><div class="stat-value"><?php echo $active_units; ?></div></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div class="stat-info"><h3>وحدات مستخدمة</h3><div class="stat-value"><?php echo $used_units; ?></div></div>
        </div>
    </div>

    <!-- إحصائيات حسب النوع -->
    <div class="unit-type-stats">
        <?php foreach ($unit_types as $type_key => $type_name): 
            $count = $type_counts[$type_key] ?? 0;
            if ($count > 0):
        ?>
        <div class="unit-type-badge" style="border-color: <?php echo $unit_type_colors[$type_key]; ?>;">
            <span class="unit-type-dot" style="background: <?php echo $unit_type_colors[$type_key]; ?>;"></span>
            <span class="unit-type-name"><?php echo $type_name; ?></span>
            <span class="unit-type-count"><?php echo $count; ?></span>
        </div>
        <?php endif; endforeach; ?>
    </div>

    <?php if (isset($success_msg) && $success): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <!-- لوحة الوحدات الأساسية -->
    <div class="base-units-panel">
        <div class="base-units-panel-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            الوحدات الأساسية حسب النوع
        </div>
        <div class="base-units-grid">
            <?php foreach ($base_units_by_type as $type_key => $bu): 
                $color = $unit_type_colors[$type_key] ?? '#64748b';
            ?>
            <div class="base-unit-card" style="border-color: <?php echo $color; ?>30;">
                <span class="bu-icon" style="color: <?php echo $color; ?>;">⭐</span>
                <div>
                    <span class="bu-type" style="color: <?php echo $color; ?>;"><?php echo $unit_types[$type_key] ?? $type_key; ?></span>
                    <span class="bu-name"><?php echo htmlspecialchars($bu['name']); ?></span>
                </div>
                <span class="bu-abb" style="background: <?php echo $color; ?>15; color: <?php echo $color; ?>;"><?php echo htmlspecialchars($bu['abbreviation']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- نموذج الإضافة/التعديل -->
    <div class="card">
        <div class="card-header">
            <h2><?php echo $editing ? 'تعديل الوحدة' : 'إضافة وحدة قياس جديدة'; ?></h2>
            <?php if ($editing): ?>
                <a href="<?php echo $base_url; ?>/units/list-units.php" class="btn btn-sm btn-outline">إلغاء التعديل</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul class="category-errors"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="unit-form">
                <input type="hidden" name="edit_id" value="<?php echo $editing ? $editing['id'] : 0; ?>">

                <div class="form-group">
                    <label>اسم الوحدة <span class="required">*</span></label>
                    <input type="text" name="name" id="unitNameInput" class="form-control" placeholder="مثال: قطعة، كيلوغرام، كرتونة" value="<?php echo htmlspecialchars($editing['name'] ?? ($_POST['name'] ?? '')); ?>" required>
                </div>

                <div class="form-group grow-0">
                    <label>الرمز المختصر <span class="required">*</span></label>
                    <input type="text" name="abbreviation" id="unitAbbrInput" class="form-control" placeholder="مثال: قط، كلغ، كرت" value="<?php echo htmlspecialchars($editing['abbreviation'] ?? ($_POST['abbreviation'] ?? '')); ?>" style="max-width:150px;" required>
                </div>

                <div class="form-group grow-0">
                    <label>نوع الوحدة</label>
                    <select name="unit_type" id="unitTypeSelect" class="form-control" style="min-width:170px;">
                        <?php foreach ($unit_types as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo (($editing['unit_type'] ?? ($_POST['unit_type'] ?? 'quantity')) == $key) ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- صندوق معلومة الوحدة الأساسية -->
                <div class="base-unit-info-box" id="baseUnitInfoBox">
                    <span class="buinfo-icon">⭐</span>
                    <span>الوحدة الأساسية لهذا النوع:</span>
                    <span class="buinfo-text" id="baseUnitInfoName"><?php 
                        $current_type = $editing['unit_type'] ?? ($_POST['unit_type'] ?? 'quantity');
                        echo htmlspecialchars($base_units_by_type[$current_type]['name'] ?? 'غير محدد');
                    ?></span>
                    <span class="buinfo-hint">(<?php 
                        echo htmlspecialchars($base_units_by_type[$current_type]['abbreviation'] ?? '-');
                    ?>)</span>
                </div>

                <!-- وحدة أساسية + معامل التحويل -->
                <div class="base-conversion-row">
                    <div class="base-unit-checkbox-group">
                        <input type="checkbox" name="is_base_unit" id="isBaseUnitCheck" value="1" <?php echo ($editing['is_base_unit'] ?? 0) ? 'checked' : ''; ?>>
                        <label for="isBaseUnitCheck">⭐ وحدة أساسية</label>
                    </div>
                    <div class="conversion-field-group">
                        <label for="conversionToBase">معامل التحويل</label>
                        <div class="conversion-input-wrap">
                            <input type="number" name="conversion_to_base" id="conversionToBase" 
                                   step="0.000001" min="0.000001" 
                                   value="<?php echo $editing['conversion_to_base'] ?? ($_POST['conversion_to_base'] ?? 1); ?>"
                                   <?php echo ($editing['is_base_unit'] ?? 0) ? 'disabled' : ''; ?>>
                        </div>
                        <div class="conversion-hint-text" id="conversionHintText">
                            <?php 
                                $ct = $editing['unit_type'] ?? ($_POST['unit_type'] ?? 'quantity');
                                $buname = $base_units_by_type[$ct]['name'] ?? 'الوحدة الأساسية';
                                $buabbr = $base_units_by_type[$ct]['abbreviation'] ?? '';
                                $cv = $editing['conversion_to_base'] ?? ($_POST['conversion_to_base'] ?? 1);
                                $editing_name = $editing['name'] ?? $_POST['name'] ?? 'وحدة جديدة';
                                $editing_abbr = $editing['abbreviation'] ?? $_POST['abbreviation'] ?? '';
                                echo "1 " . htmlspecialchars($editing_name) . " (" . htmlspecialchars($editing_abbr) . ") = <strong>" . htmlspecialchars($cv) . "</strong> " . htmlspecialchars($buname) . " (" . htmlspecialchars($buabbr) . ")";
                            ?>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>الوصف</label>
                    <input type="text" name="description" class="form-control" placeholder="وصف مختصر للوحدة (اختياري)" value="<?php echo htmlspecialchars($editing['description'] ?? ($_POST['description'] ?? '')); ?>">
                </div>

                <div class="form-group grow-0">
                    <label>الترتيب</label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?php echo $editing['sort_order'] ?? ($_POST['sort_order'] ?? 0); ?>" style="max-width:100px;">
                </div>

                <div class="form-group grow-0 checkbox-group">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?php echo ($editing['is_active'] ?? 1) ? 'checked' : ''; ?>>
                        نشط
                    </label>
                </div>

                <div class="form-actions">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editing ? 'حفظ التعديل' : 'إضافة الوحدة'; ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="<?php echo $base_url; ?>/units/list-units.php" class="btn btn-outline">إلغاء</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة الوحدات -->
    <div class="card">
        <div class="card-header">
            <h2>وحدات القياس (<?php echo count($units); ?>)</h2>
        </div>
        <div class="card-body">
            <?php if (empty($units)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
                    </svg>
                    <p>لا توجد وحدات قياس بعد</p>
                    <span class="hint">أضف أول وحدة من النموذج أعلاه</span>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>النوع</th>
                            <th>اسم الوحدة</th>
                            <th>الرمز</th>
                            <th>التحويل</th>
                            <th>الوصف</th>
                            <th>الاستخدام</th>
                            <th>الترتيب</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($units as $unit): 
                            $total_usage = $unit['products_count'] + $unit['additional_count'];
                            $type_color = $unit_type_colors[$unit['unit_type']] ?? '#64748b';
                            $is_base = isset($unit['is_base_unit']) ? $unit['is_base_unit'] : 0;
                            $conv = isset($unit['conversion_to_base']) ? $unit['conversion_to_base'] : 1;
                        ?>
                        <tr class="<?php echo !$unit['is_active'] ? 'category-inactive' : ''; ?>">
                            <td><?php echo $i++; ?></td>
                            <td>
                                <span class="unit-type-tag" style="background: <?php echo $type_color; ?>15; color: <?php echo $type_color; ?>; border: 1px solid <?php echo $type_color; ?>30;">
                                    <?php echo htmlspecialchars($unit_types[$unit['unit_type']] ?? $unit['unit_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($is_base): ?>
                                    <span class="base-unit-star" title="وحدة أساسية">⭐</span>
                                <?php endif; ?>
                                <span class="category-name-text" style="color: <?php echo $type_color; ?>;"><?php echo htmlspecialchars($unit['name']); ?></span>
                            </td>
                            <td><span class="unit-abbreviation" style="background: <?php echo $type_color; ?>10; color: <?php echo $type_color; ?>;"><?php echo htmlspecialchars($unit['abbreviation']); ?></span></td>
                            <td>
                                <?php if (!$is_base): ?>
                                    <span class="conversion-factor-badge">
                                        1 <?php echo htmlspecialchars($unit['abbreviation']); ?> = <strong><?php echo htmlspecialchars($conv); ?></strong> <?php 
                                            $bu_name = $base_units_by_type[$unit['unit_type']]['abbreviation'] ?? '';
                                            echo htmlspecialchars($bu_name);
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 0.78rem; color: #94a3b8;">وحدة أساسية</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="category-description"><?php echo htmlspecialchars($unit['description'] ?? '-'); ?></span></td>
                            <td>
                                <?php if ($total_usage > 0): ?>
                                <span class="products-count-link" title="<?php echo $unit['products_count']; ?> منتج أساسي + <?php echo $unit['additional_count']; ?> وحدة إضافية">
                                    <?php echo $total_usage; ?> استخدام
                                </span>
                                <?php else: ?>
                                    <span class="products-count-zero">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $unit['sort_order']; ?></td>
                            <td>
                                <?php if ($unit['is_active']): ?>
                                    <span class="badge badge-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">معطّل</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="<?php echo $base_url; ?>/units/list-units.php?edit=<?php echo $unit['id']; ?>" class="btn btn-sm btn-primary">تعديل</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد؟')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_unit">
                                        <input type="hidden" name="unit_id" value="<?php echo $unit['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" title="<?php echo $unit['is_active'] ? 'تعطيل' : 'تفعيل'; ?>">
                                            <?php echo $unit['is_active'] ? 'تعطيل' : 'تفعيل'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذه الوحدة؟<?php echo $total_usage > 0 ? ' هذه الوحدة مستخدمة في منتجات!' : ''; ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_unit">
                                        <input type="hidden" name="unit_id" value="<?php echo $unit['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                                    </form>
                                </div>
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
// === بيانات الوحدات الأساسية (من PHP) ===
var baseUnitsByType = <?php echo json_encode($base_units_by_type); ?>;
var unitTypesLabels = <?php echo json_encode($unit_types); ?>;

// === تحديث صندوق معلومة الوحدة الأساسية عند تغيير النوع ===
var unitTypeSelect = document.getElementById('unitTypeSelect');
var baseUnitInfoName = document.getElementById('baseUnitInfoName');
var isBaseUnitCheck = document.getElementById('isBaseUnitCheck');
var conversionToBase = document.getElementById('conversionToBase');
var conversionHintText = document.getElementById('conversionHintText');

function updateBaseUnitInfo() {
    var type = unitTypeSelect.value;
    var bu = baseUnitsByType[type];
    
    if (bu) {
        // تحديث صندوق المعلومات
        baseUnitInfoName.textContent = bu.name;
        // تحديث نص التلميح
        updateConversionHint();
    }
}

function updateConversionHint() {
    var type = unitTypeSelect.value;
    var bu = baseUnitsByType[type];
    var val = parseFloat(conversionToBase.value) || 1;
    var nameInput = document.getElementById('unitNameInput');
    var abbrInput = document.getElementById('unitAbbrInput');
    var uName = nameInput ? nameInput.value : '';
    var uAbbr = abbrInput ? abbrInput.value : '';
    
    if (bu && uName) {
        conversionHintText.innerHTML = '1 ' + uName + ' (' + uAbbr + ') = <strong>' + val + '</strong> ' + bu.name + ' (' + bu.abbreviation + ')';
    } else if (bu) {
        conversionHintText.innerHTML = '1 وحدة جديدة = <strong>' + val + '</strong> ' + bu.name + ' (' + bu.abbreviation + ')';
    }
}

function toggleBaseUnitFields() {
    if (isBaseUnitCheck.checked) {
        conversionToBase.value = '1';
        conversionToBase.disabled = true;
        conversionToBase.style.opacity = '0.5';
        updateConversionHint();
    } else {
        conversionToBase.disabled = false;
        conversionToBase.style.opacity = '1';
    }
}

unitTypeSelect.addEventListener('change', function() {
    updateBaseUnitInfo();
});

isBaseUnitCheck.addEventListener('change', function() {
    toggleBaseUnitFields();
});

conversionToBase.addEventListener('input', function() {
    updateConversionHint();
});

var unitNameInput = document.getElementById('unitNameInput');
var unitAbbrInput = document.getElementById('unitAbbrInput');
if (unitNameInput) unitNameInput.addEventListener('input', updateConversionHint);
if (unitAbbrInput) unitAbbrInput.addEventListener('input', updateConversionHint);

// تهيئة عند التحميل
document.addEventListener('DOMContentLoaded', function() {
    updateBaseUnitInfo();
    toggleBaseUnitFields();
});
</script>

<?php require_once '../includes/footer.php'; ?>
