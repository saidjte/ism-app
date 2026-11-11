<?php
$page_title = 'تسجيل مصروف جديد';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
requireLogin();
require_once '../includes/header.php';

$errors = [];
$success = false;

// تصنيفات المصاريف الافتراضية
$expense_categories = [
    'مواد التغليف' => 'شراء أكياس، عبوات، أغطية، شريط لاصق...',
    'استخلاص أرباح' => 'سحب جزء من أرباح المتجر',
    'إيجار المحل' => 'أجرة المحل أو المستودع',
    'كهرباء وماء' => 'فواتير الكهرباء والماء والغاز',
    'نقل ونقلية' => 'تكاليف نقل البضائع',
    'صيانة' => 'صيانة المحل والمعدات',
    'رواتب' => 'رواتب الموظفين والعمال',
    'ضرائب ورسوم' => 'ضرائب ورسوم حكومية',
    'تسويق وإعلان' => 'إعلانات وتسويق',
    'أخرى' => 'مصاريف أخرى',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $category = trim($_POST['category'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $expense_date = trim($_POST['expense_date'] ?? date('Y-m-d'));

    // التحقق من البيانات
    if (empty($category)) $errors[] = 'تصنيف المصروف مطلوب';
    if ($amount <= 0) $errors[] = 'المبلغ يجب أن يكون أكبر من صفر';
    if (empty($expense_date)) $errors[] = 'تاريخ المصروف مطلوب';

    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("INSERT INTO expenses (category, amount, description, expense_date, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$category, $amount, $description, $expense_date, $_SESSION['user_id'] ?? null]);
            $success = true;
            $category = '';
            $amount = 0;
            $description = '';
        } catch (Exception $e) {
            ErrorHandler::logException($e, 'save_expense');
            $errors[] = 'حدث خطأ أثناء حفظ المصروف: ' . ErrorHandler::safeExceptionMessage($e, 'حدث خطأ غير متوقع أثناء حفظ المصروف');
        }
    }
}
?>

<div class="container">
    <div class="page-header">
        <h1>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
            تسجيل مصروف جديد
        </h1>
        <a href="list-expenses.php" class="btn btn-outline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            سجل المصاريف
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>يرجى تصحيح الأخطاء التالية:</strong>
            <ul style="margin: 5px 20px 0;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            تم تسجيل المصروف بنجاح! <a href="add-expense.php" style="font-weight:bold; margin-right:10px;">تسجيل مصروف آخر</a>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="card">
            <div class="card-header">
                <h2>بيانات المصروف</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>تصنيف المصروف <span class="required">*</span></label>
                        <select name="category" class="form-control" id="expenseCategory" required>
                            <option value="">-- اختر التصنيف --</option>
                            <?php foreach ($expense_categories as $cat => $desc): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo (isset($category) && $category == $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="__custom__" <?php echo (isset($category) && !isset($expense_categories[$category])) ? 'selected' : ''; ?>>+ تصنيف آخر...</option>
                        </select>
                        <div id="customCategoryWrap" style="display:none; margin-top:8px;">
                            <input type="text" name="custom_category" id="customCategoryInput" class="form-control" placeholder="اكتب اسم التصنيف">
                        </div>
                        <div class="form-text" id="categoryHint" style="margin-top:4px; font-size:0.78rem;"></div>
                    </div>

                    <div class="form-group">
                        <label>المبلغ <span class="required">*</span></label>
                        <div style="position:relative;">
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" value="<?php echo $amount ?? 0; ?>" required style="padding-left:65px;">
                            <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--gray-400); font-size:0.85rem; font-weight:600;"><?php echo getCurrencySymbol(); ?></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>التاريخ <span class="required">*</span></label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo htmlspecialchars($expense_date ?? date('Y-m-d')); ?>" required>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>الوصف / ملاحظات</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="تفاصيل إضافية عن المصروف (اختياري)"><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top: 1.5rem; display: flex; gap: 10px;">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn btn-primary btn-lg">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M5 13l4 4L19 7"/></svg>
                حفظ المصروف
            </button>
            <a href="list-expenses.php" class="btn btn-outline btn-lg">إلغاء</a>
        </div>
    </form>
</div>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
var categories = <?php echo json_encode($expense_categories); ?>;

document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('expenseCategory');
    var customWrap = document.getElementById('customCategoryWrap');
    var customInput = document.getElementById('customCategoryInput');
    var hint = document.getElementById('categoryHint');

    function updateHint() {
        var val = select.value;
        if (val && val !== '__custom__' && categories[val]) {
            hint.textContent = categories[val];
            hint.style.display = 'block';
            customWrap.style.display = 'none';
        } else if (val === '__custom__') {
            hint.style.display = 'none';
            customWrap.style.display = 'block';
            customInput.required = true;
            customInput.focus();
        } else {
            hint.style.display = 'none';
            customWrap.style.display = 'none';
        }
    }

    select.addEventListener('change', updateHint);
    updateHint();

    // عند الإرسال: إذا اختار تصنيف آخر، نسخ القيمة
    var form = select.closest('form');
    if (form) {
        form.addEventListener('submit', function() {
            if (select.value === '__custom__') {
                select.value = customInput.value.trim();
                if (!select.value) {
                    customInput.style.borderColor = 'var(--danger)';
                    customInput.focus();
                    event.preventDefault();
                }
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
