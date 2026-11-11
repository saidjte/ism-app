<?php
/**
 * ===== متحكم صور المنتجات للموبايل (MobileProductImageController) =====
 * يوفر عمليات رفع وإدارة صور المنتجات عبر API الموبايل
 *
 * المسارات:
 *   POST   /api/mobile/products/{id}/images     — رفع صورة جديدة
 *   DELETE /api/mobile/images/{id}               — حذف صورة (ناعم)
 *   POST   /api/mobile/images/{id}/set-primary   — تعيين صورة كرئيسية
 *   GET    /api/mobile/products/{id}/images      — جلب صور المنتج
 *
 * v5.11.0: إنشاء متحكم صور المنتجات للموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileProductImageController extends ApiController
{
    /**
     * رفع صورة منتج جديدة
     *
     * POST /api/mobile/products/{id}/images
     * Content-Type: multipart/form-data
     * Fields: image (ملف), remove_bg (اختياري: true/false), bg_tolerance (اختياري: low/medium/high)
     */
    public function upload(array $params = []): void
    {
        try {
            $productId = intval($params['id'] ?? $this->input('product_id', 0));

            if ($productId <= 0) {
                ApiResponse::validationError(['معرف المنتج مطلوب']);
                return;
            }

            // التحقق من وجود ملف مرفوع
            if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                ApiResponse::validationError(['ملف الصورة مطلوب']);
                return;
            }

            $file = $_FILES['image'];
            $removeBg = filter_var($this->input('remove_bg', 'false'), FILTER_VALIDATE_BOOLEAN);
            $bgTolerance = trim($this->input('bg_tolerance', 'medium'));

            // استخدام ProductImageService إذا كان متاحاً
            if (class_exists('ProductImageService')) {
                $result = ProductImageService::upload($productId, $file, $removeBg, $bgTolerance);

                if (!$result['success']) {
                    ApiResponse::error($result['error'], 'upload_failed', 422);
                    return;
                }

                // تحويل المسارات إلى مسارات ويب نسبية
                $imageData = [
                    'id' => $result['image_id'],
                    'product_id' => $productId,
                    'original_path' => ProductImageService::getWebPath($result['original_path'] ?? ''),
                    'medium_path' => ProductImageService::getWebPath($result['medium_path'] ?? ''),
                    'thumbnail_path' => ProductImageService::getWebPath($result['thumbnail_path'] ?? ''),
                    'message' => $result['message'],
                ];

                ApiResponse::created($imageData, 'تم رفع الصورة بنجاح');
            } else {
                // fallback: رفع بسيط بدون معالجة
                $this->simpleUpload($productId, $file);
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileProductImageController::upload');
            }
            ApiResponse::serverError('خطأ في رفع الصورة');
        }
    }

    /**
     * حذف صورة (ناعم)
     *
     * DELETE /api/mobile/images/{id}
     */
    public function delete(array $params = []): void
    {
        try {
            $imageId = intval($params['id'] ?? $this->input('id', 0));

            if ($imageId <= 0) {
                ApiResponse::validationError(['معرف الصورة مطلوب']);
                return;
            }

            if (class_exists('ProductImageService')) {
                $result = ProductImageService::softDelete($imageId);

                if (!$result['success']) {
                    ApiResponse::error($result['error'], 'delete_failed', 422);
                    return;
                }

                ApiResponse::success(null, $result['message']);
            } else {
                // fallback: حذف مباشر
                $db = AppDatabase::getInstance();
                $pdo = $db->getConnection();

                $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$imageId]);
                $image = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$image) {
                    ApiResponse::notFound('الصورة غير موجودة');
                    return;
                }

                $pdo->prepare("UPDATE product_images SET deleted_at = NOW() WHERE id = ?")->execute([$imageId]);
                ApiResponse::success(null, 'تم حذف الصورة بنجاح');
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileProductImageController::delete');
            }
            ApiResponse::serverError('خطأ في حذف الصورة');
        }
    }

    /**
     * تعيين صورة كرئيسية
     *
     * POST /api/mobile/images/{id}/set-primary
     */
    public function setPrimary(array $params = []): void
    {
        try {
            $imageId = intval($params['id'] ?? $this->input('id', 0));

            if ($imageId <= 0) {
                ApiResponse::validationError(['معرف الصورة مطلوب']);
                return;
            }

            if (class_exists('ProductImageService')) {
                $result = ProductImageService::setPrimary($imageId);

                if (!$result['success']) {
                    ApiResponse::error($result['error'], 'set_primary_failed', 422);
                    return;
                }

                ApiResponse::success(null, $result['message']);
            } else {
                // fallback
                $db = AppDatabase::getInstance();
                $pdo = $db->getConnection();

                $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$imageId]);
                $image = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$image) {
                    ApiResponse::notFound('الصورة غير موجودة');
                    return;
                }

                $productId = intval($image['product_id']);

                // إزالة الرئيسية من كل صور المنتج
                $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?")->execute([$productId]);
                // تعيين الصورة الجديدة كرئيسية
                $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = ?")->execute([$imageId]);

                ApiResponse::success(null, 'تم تعيين الصورة كرئيسية');
            }

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileProductImageController::setPrimary');
            }
            ApiResponse::serverError('خطأ في تعيين الصورة الرئيسية');
        }
    }

    /**
     * جلب صور المنتج
     *
     * GET /api/mobile/products/{id}/images
     */
    public function list(array $params = []): void
    {
        try {
            $productId = intval($params['id'] ?? $this->input('product_id', 0));

            if ($productId <= 0) {
                ApiResponse::validationError(['معرف المنتج مطلوب']);
                return;
            }

            if (class_exists('ProductImageService')) {
                $images = ProductImageService::getActiveImages($productId);
            } else {
                $db = AppDatabase::getInstance();
                $pdo = $db->getConnection();

                $stmt = $pdo->prepare(
                    "SELECT * FROM product_images WHERE product_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC"
                );
                $stmt->execute([$productId]);
                $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // تنسيق البيانات مع تحويل المسارات
            $formattedImages = array_map(function ($img) {
                $originalPath = $img['original_path'] ?? '';
                $mediumPath = $img['medium_path'] ?? '';
                $thumbnailPath = $img['thumbnail_path'] ?? '';

                // محاولة تحويل المسارات
                if (class_exists('ProductImageService')) {
                    $originalPath = ProductImageService::getWebPath($originalPath);
                    $mediumPath = ProductImageService::getWebPath($mediumPath);
                    $thumbnailPath = ProductImageService::getWebPath($thumbnailPath);
                } else {
                    // استخراج المسار النسبي يدوياً
                    foreach (['originalPath', 'mediumPath', 'thumbnailPath'] as $pathVar) {
                        $pathVal = $$pathVar;
                        $pos = strpos($pathVal, 'uploads/');
                        if ($pos !== false) {
                            $$pathVar = substr($pathVal, $pos);
                        }
                    }
                }

                return [
                    'id' => intval($img['id']),
                    'product_id' => intval($img['product_id']),
                    'original_path' => $originalPath,
                    'medium_path' => $mediumPath,
                    'thumbnail_path' => $thumbnailPath,
                    'is_primary' => intval($img['is_primary'] ?? 0) === 1,
                    'sort_order' => intval($img['sort_order'] ?? 0),
                    'file_size' => intval($img['file_size'] ?? 0),
                ];
            }, $images);

            ApiResponse::success($formattedImages);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileProductImageController::list');
            }
            ApiResponse::serverError('خطأ في جلب صور المنتج');
        }
    }

    /**
     * رفع بسيط بدون ProductImageService
     */
    private function simpleUpload(int $productId, array $file): void
    {
        // التحقق من الملف
        if ($file['error'] !== UPLOAD_ERR_OK) {
            ApiResponse::error('خطأ في رفع الملف', 'upload_error', 422);
            return;
        }

        if ($file['size'] > 5242880) { // 5MB
            ApiResponse::validationError(['حجم الملف يتجاوز 5 ميغابايت']);
            return;
        }

        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!in_array($mime, $allowedMime)) {
            ApiResponse::validationError(['نوع الملف غير مسموح']);
            return;
        }

        // إنشاء مجلد المنتج
        $uploadDir = __DIR__ . '/../../../uploads/products/' . $productId;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        // إنشاء اسم فريد
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $fileName = uniqid('img_') . '.' . $ext;
        $filePath = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            ApiResponse::serverError('فشل في حفظ الملف');
            return;
        }

        // حفظ في قاعدة البيانات
        $db = AppDatabase::getInstance();
        $pdo = $db->getConnection();

        // أول صورة تكون رئيسية
        $existingCount = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND deleted_at IS NULL");
        $existingCount->execute([$productId]);
        $isPrimary = intval($existingCount->fetchColumn()) === 0 ? 1 : 0;

        $stmt = $pdo->prepare(
            "INSERT INTO product_images (product_id, original_path, mime_type, file_size, is_primary)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$productId, $filePath, $mime, intval($file['size']), $isPrimary]);

        $imageId = intval($pdo->lastInsertId());

        ApiResponse::created([
            'id' => $imageId,
            'product_id' => $productId,
            'original_path' => 'uploads/products/' . $productId . '/' . $fileName,
            'is_primary' => $isPrimary === 1,
        ], 'تم رفع الصورة بنجاح');
    }
}
