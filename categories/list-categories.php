<?php
require_once '../includes/bootstrap.php';
$page_title = t('categories_manage');
require_once __DIR__ . '/../includes/permissions.php';
requireLogin();
requirePermission('categories.view');

// حساب base_url مبكراً لاستخدامه في التحويلات قبل header.php
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_url = in_array(basename($script_dir), ['products', 'purchases', 'sales', 'api', 'users', 'settings', 'expenses', 'units', 'categories']) 
    ? rtrim(dirname($script_dir), '/') 
    : rtrim($script_dir, '/');
if (empty($base_url)) $base_url = '';

$errors = [];
$success = false;
$editing = null;

// حذف تصنيف (POST فقط) - قبل إرسال أي محتوى HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category') {
    requireCSRF();
    $delete_id = intval($_POST['category_id'] ?? 0);
    try {
        $count = fetchOne("SELECT COUNT(*) as total FROM products WHERE category_id = ?", [$delete_id])['total'];
        if ($count > 0) {
            $error_msg = 'لا يمكن حذف هذا التصنيف لأنه مرتبط بـ ' . $count . ' منتج(ات). يمكنك تعطيله بدلاً من ذلك.';
        } else {
            executeQuery("DELETE FROM categories WHERE id = ?", [$delete_id]);
            header("Location: " . $base_url . "/categories/list-categories.php?msg=deleted");
            exit;
        }
    } catch (Exception $e) {
        $error_msg = t('categories_error_deleting');
    }
}

// تفعيل/تعطيل تصنيف (POST فقط) - قبل إرسال أي محتوى HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_category') {
    requireCSRF();
    $toggle_id = intval($_POST['category_id'] ?? 0);
    try {
        $cat = fetchOne("SELECT is_active FROM categories WHERE id = ?", [$toggle_id]);
        if ($cat) {
            $new_status = $cat['is_active'] ? 0 : 1;
            executeQuery("UPDATE categories SET is_active = ? WHERE id = ?", [$new_status, $toggle_id]);
            header("Location: " . $base_url . "/categories/list-categories.php?msg=toggled");
            exit;
        }
    } catch (Exception $e) {
        $error_msg = t('common_error_occurred');
    }
}

// تعديل تصنيف
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editing = fetchOne("SELECT * FROM categories WHERE id = ?", [intval($_GET['edit'])]);
}

// إضافة أو تعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $edit_id = intval($_POST['edit_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '#3b82f6');
    $sort_order = intval($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name)) $errors[] = t('categories_name_required');

    if (empty($errors)) {
        if ($edit_id > 0) {
            $existing = fetchOne("SELECT id FROM categories WHERE name = ? AND id != ?", [$name, $edit_id]);
        } else {
            $existing = fetchOne("SELECT id FROM categories WHERE name = ?", [$name]);
        }
        if ($existing) $errors[] = t('categories_name_exists');
    }

    if (empty($errors)) {
        try {
            if ($edit_id > 0) {
                executeQuery("UPDATE categories SET name=?, description=?, color=?, sort_order=?, is_active=? WHERE id=?",
                    [$name, $description ?: null, $color, $sort_order, $is_active, $edit_id]);
                $success_msg = 'تم تحديث التصنيف بنجاح';
            } else {
                insertData("INSERT INTO categories (name, description, color, sort_order, is_active) VALUES (?, ?, ?, ?, ?)",
                    [$name, $description ?: null, $color, $sort_order, $is_active]);
                $success_msg = 'تم إضافة التصنيف بنجاح';
            }
            $success = true;
            $editing = null;
        } catch (Exception $e) {
            error_log('Category Save Error: ' . $e->getMessage());
            $errors[] = t('categories_error_saving');
        }
    }
}

// رسائل
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $success_msg = t('categories_deleted');
    if ($_GET['msg'] == 'toggled') $success_msg = t('categories_status_changed');
}

// جلب التصنيفات
$categories = fetchAll("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) as products_count FROM categories c ORDER BY c.sort_order ASC, c.name ASC");

// === تضمين الرأس بعد انتهاء جميع الاستعلامات ===
require_once '../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                <path d="M4 7h16M4 12h16M4 17h10"/>
                <circle cx="20" cy="17" r="2"/>
            </svg>
            <?php echo t('categories_manage'); ?>
        </h1>
    </div>

    <!-- إحصائيات -->
    <div class="stats-grid">
        <?php
        $total_cats = count($categories);
        $active_cats = count(array_filter($categories, fn($c) => $c['is_active']));
        $products_with_cat = fetchOne("SELECT COUNT(*) as total FROM products WHERE category_id IS NOT NULL")['total'];
        $total_products = fetchOne("SELECT COUNT(*) as total FROM products")['total'];
        ?>
        <div class="stat-card cyan">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            </div>
            <div class="stat-info"><h3><?php echo t('categories_total_categories'); ?></h3><div class="stat-value"><?php echo $total_cats; ?></div></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M5 13l4 4L19 7"/></svg>
            </div>
            <div class="stat-info"><h3><?php echo t('categories_active_categories'); ?></h3><div class="stat-value"><?php echo $active_cats; ?></div></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div class="stat-info"><h3><?php echo t('categories_categorized_products'); ?></h3><div class="stat-value"><?php echo $products_with_cat; ?> / <?php echo $total_products; ?></div></div>
        </div>
    </div>

    <?php if (isset($success_msg) && $success): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <!-- نموذج الإضافة/التعديل -->
    <div class="card">
        <div class="card-header">
            <h2><?php echo $editing ? t('categories_edit') : t('categories_add_new'); ?></h2>
            <?php if ($editing): ?>
                <a href="<?php echo $base_url; ?>/categories/list-categories.php" class="btn btn-sm btn-outline"><?php echo t('cancel'); ?></a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul class="category-errors"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="category-form">
                <input type="hidden" name="edit_id" value="<?php echo $editing ? $editing['id'] : 0; ?>">

                <div class="form-group">
                    <label><?php echo t('categories_name'); ?> <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="مثال: أغذية" value="<?php echo htmlspecialchars($editing['name'] ?? ($_POST['name'] ?? '')); ?>" required>
                </div>

                <div class="form-group">
                    <label>الوصف</label>
                    <input type="text" name="description" class="form-control" placeholder="وصف مختصر للتصنيف (اختياري)" value="<?php echo htmlspecialchars($editing['description'] ?? ($_POST['description'] ?? '')); ?>">
                </div>

                <div class="form-group grow-0">
                    <label>اللون</label>
                    <div class="color-picker-group">
                        <input type="color" name="color" value="<?php echo htmlspecialchars($editing['color'] ?? ($_POST['color'] ?? '#3b82f6')); ?>">
                        <span class="color-preview-swatch" id="colorPreview" style="background: <?php echo $editing['color'] ?? ($_POST['color'] ?? '#3b82f6'); ?>;"></span>
                    </div>
                </div>

                <div class="form-group grow-0">
                    <label>الترتيب</label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?php echo $editing['sort_order'] ?? ($_POST['sort_order'] ?? 0); ?>">
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
                        <?php echo $editing ? t('save') : t('add'); ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="<?php echo $base_url; ?>/categories/list-categories.php" class="btn btn-outline"><?php echo t('cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- قائمة التصنيفات -->
    <div class="card">
        <div class="card-header">
            <h2>التصنيفات (<?php echo count($categories); ?>)</h2>
        </div>
        <div class="card-body">
            <?php if (empty($categories)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 7h16M4 12h16M4 17h10"/>
                        <circle cx="20" cy="17" r="2"/>
                    </svg>
                    <p>لا توجد تصنيفات بعد</p>
                    <span class="hint">أضف أول تصنيف من النموذج أعلاه</span>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اللون</th>
                            <th>اسم التصنيف</th>
                            <th>الوصف</th>
                            <th>عدد المنتجات</th>
                            <th>الترتيب</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($categories as $cat): ?>
                        <tr class="<?php echo !$cat['is_active'] ? 'category-inactive' : ''; ?>">
                            <td><?php echo $i++; ?></td>
                            <td>
                                <span class="category-color-dot" style="background: <?php echo $cat['color']; ?>;"></span>
                            </td>
                            <td><span class="category-name-text" style="color: <?php echo $cat['color']; ?>;"><?php echo htmlspecialchars($cat['name']); ?></span></td>
                            <td><span class="category-description"><?php echo htmlspecialchars($cat['description'] ?? '-'); ?></span></td>
                            <td>
                                <?php if ($cat['products_count'] > 0): ?>
                                    <a href="<?php echo $base_url; ?>/products/list-products.php?category=<?php echo $cat['id']; ?>" class="products-count-link">
                                        <?php echo $cat['products_count']; ?> منتج
                                    </a>
                                <?php else: ?>
                                    <span class="products-count-zero">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $cat['sort_order']; ?></td>
                            <td>
                                <?php if ($cat['is_active']): ?>
                                    <span class="badge badge-success">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">معطّل</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="<?php echo $base_url; ?>/categories/list-categories.php?edit=<?php echo $cat['id']; ?>" class="btn btn-sm btn-primary"><?php echo t('edit'); ?></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('categories_confirm_delete'); ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_category">
                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" title="<?php echo $cat['is_active'] ? t('common_deactivate') : t('common_activate'); ?>">
                                            <?php echo $cat['is_active'] ? t('common_deactivate') : t('common_activate'); ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا التصنيف؟<?php echo $cat['products_count'] > 0 ? ' هذا التصنيف مرتبط بمنتجات!' : ''; ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><?php echo t('delete'); ?></button>
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
// معاينة اللون مباشرة
document.querySelector('input[name="color"]').addEventListener('input', function() {
    document.getElementById('colorPreview').style.background = this.value;
});
</script>

<?php require_once '../includes/footer.php'; ?>
