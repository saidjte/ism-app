<?php
/**
 * ===== متحكم تصنيفات الموبايل (MobileCategoryController) =====
 * يوفر عمليات التصنيفات عبر API الموبايل
 *
 * المسارات:
 *   GET    /api/mobile/categories          — قائمة التصنيفات
 *   POST   /api/mobile/categories          — إضافة تصنيف
 *   PUT    /api/mobile/categories/{id}     — تحديث تصنيف
 *   DELETE /api/mobile/categories/{id}     — حذف تصنيف
 *
 * v4.35.0: إنشاء متحكم تصنيفات الموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileCategoryController extends ApiController
{
    /**
     * قائمة التصنيفات مع عدد المنتجات لكل تصنيف
     *
     * GET /api/mobile/categories
     */
    public function index(array $params = []): void
    {
        try {
            $db = AppDatabase::getInstance();

            $categories = $db->fetchAll(
                "SELECT c.*, COUNT(p.id) as product_count
                 FROM categories c
                 LEFT JOIN products p ON c.id = p.category_id
                 GROUP BY c.id
                 ORDER BY c.name ASC"
            );

            ApiResponse::success($categories);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCategoryController::index');
            }
            ApiResponse::serverError('خطأ في جلب التصنيفات');
        }
    }

    /**
     * إضافة تصنيف جديد
     *
     * POST /api/mobile/categories
     * Body: { "name": "...", "description": "..." }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            $name = trim($this->input('name', ''));

            if (empty($name)) {
                ApiResponse::validationError(['اسم التصنيف مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();

            // التحقق من عدم التكرار
            $existing = $db->fetchOne("SELECT id FROM categories WHERE name = ?", [$name]);
            if ($existing) {
                ApiResponse::error('اسم التصنيف موجود مسبقاً', 'validation', 422);
                return;
            }

            $description = trim($this->input('description', ''));
            $color = trim($this->input('color', ''));

            $id = $db->insert(
                "INSERT INTO categories (name, description, color) VALUES (?, ?, ?)",
                [$name, $description ?: null, $color ?: null]
            );

            ApiResponse::created(['id' => intval($id), 'name' => $name], 'تم إضافة التصنيف بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCategoryController::store');
            }
            ApiResponse::serverError('خطأ في إضافة التصنيف');
        }
    }

    /**
     * تحديث تصنيف
     *
     * PUT /api/mobile/categories/{id}
     */
    public function update(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? $this->input('id', 0));

            if ($id <= 0) {
                ApiResponse::validationError(['معرف التصنيف مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $category = $db->fetchOne("SELECT * FROM categories WHERE id = ?", [$id]);

            if (!$category) {
                ApiResponse::notFound('التصنيف غير موجود');
                return;
            }

            $name = trim($this->input('name', $category['name']));
            $description = trim($this->input('description', $category['description'] ?? ''));
            $color = trim($this->input('color', $category['color'] ?? ''));

            if (empty($name)) {
                ApiResponse::validationError(['اسم التصنيف مطلوب']);
                return;
            }

            $db->query(
                "UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ?",
                [$name, $description ?: null, $color ?: null, $id]
            );

            ApiResponse::success(null, 'تم تحديث التصنيف بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCategoryController::update');
            }
            ApiResponse::serverError('خطأ في تحديث التصنيف');
        }
    }

    /**
     * حذف تصنيف
     *
     * DELETE /api/mobile/categories/{id}
     */
    public function delete(array $params = []): void
    {
        try {
            $id = intval($params['id'] ?? $this->input('id', 0));

            if ($id <= 0) {
                ApiResponse::validationError(['معرف التصنيف مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();

            // التحقق من عدم وجود منتجات مرتبطة
            $productCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM products WHERE category_id = ?", [$id]);

            if ($productCount && intval($productCount['cnt']) > 0) {
                ApiResponse::error('لا يمكن حذف التصنيف لوجود منتجات مرتبطة به (' . $productCount['cnt'] . ' منتج)', 'validation', 422);
                return;
            }

            $db->query("DELETE FROM categories WHERE id = ?", [$id]);

            ApiResponse::success(null, 'تم حذف التصنيف بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileCategoryController::delete');
            }
            ApiResponse::serverError('خطأ في حذف التصنيف');
        }
    }
}
