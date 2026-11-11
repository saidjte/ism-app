<?php
/**
 * ===== خدمة صور المنتجات (ProductImageService) =====
 *
 * إدارة رفع ومعالجة صور المنتجات في نظام إدارة المخزون
 *
 * المسؤوليات:
 * - رفع صور المنتجات مع إنشاء صور مصغرة ومتوسطة
 * - دعم تنسيق WebP مع تراجع تلقائي إلى JPEG
 * - إزالة خلفية الصور بخوارزمية Color Distance
 * - حذف/استعادة/ترتيب الصور
 * - تنظيف الصور المحذوفة ناعماً بعد فترة زمنية
 * - حماية مجلدات الصور بـ .htaccess (Apache 2.4 مع تراجع إلى 2.2)
 *
 * @since v5.9.0
 * @updated v5.10.1 — إضافة getWebPath() + إصلاح مسارات الصور لتكون نسبية + رفع الصور من صفحات الإضافة والتعديل
 */

class ProductImageService
{
    // ===== ثوابت الحجم والجودة =====
    const MAX_FILE_SIZE  = 5242880;  // 5 MB
    const ALLOWED_MIME   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const ALLOWED_EXT    = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const THUMB_WIDTH    = 150;
    const THUMB_HEIGHT   = 150;
    const MEDIUM_WIDTH   = 600;
    const MEDIUM_HEIGHT  = 600;
    const QUALITY_WEBP   = 82;
    const QUALITY_JPEG   = 85;
    const CLEANUP_DAYS   = 30;

    // ===== ثوابت إزالة الخلفية =====
    const BG_TOLERANCE_LOW    = 35;
    const BG_TOLERANCE_MEDIUM = 55;
    const BG_TOLERANCE_HIGH   = 80;
    const BG_FEATHER_RADIUS   = 3;
    const BG_MAX_WORK_SIZE    = 1200;
    const BG_BORDER_SAMPLES   = 40;

    /** @var bool|null ذاكرة مؤقتة لدعم WebP */
    private static ?bool $webpSupported = null;

    /** @var array ذاكرة مؤقتة لأعمدة الجدول */
    private static array $tableColumnsCache = [];

    /** @var string|null جذر التطبيق المحسوب */
    private static ?string $appRoot = null;

    // ========================================================================
    // ===== الطرق العامة (Public Methods) =====
    // ========================================================================

    /**
     * تحويل مسار الخادم المطلق إلى مسار ويب نسبي
     *
     * مثال: /var/www/html/ism-app/uploads/products/4/img_thumb.webp → uploads/products/4/img_thumb.webp
     * أو: C:\wamp64\www\ism-app\uploads/products/4/img_thumb.webp → uploads/products/4/img_thumb.webp
     *
     * @param string $serverPath المسار المطلق على الخادم
     * @return string المسار النسبي للويب
     */
    public static function getWebPath(string $serverPath): string
    {
        if (empty($serverPath)) {
            return '';
        }

        // محاولة الاستخراج من جذر التطبيق
        if (self::$appRoot === null) {
            self::$appRoot = realpath(__DIR__ . '/..');
        }

        $realServerPath = realpath($serverPath);
        if ($realServerPath !== false && self::$appRoot !== false) {
            $appRootNorm = str_replace('\\', '/', self::$appRoot);
            $pathNorm = str_replace('\\', '/', $realServerPath);
            if (str_starts_with($pathNorm, $appRootNorm)) {
                return ltrim(substr($pathNorm, strlen($appRootNorm)), '/');
            }
        }

        // طريقة بديلة: استخراج من uploads/
        $pos = strpos($serverPath, 'uploads/');
        if ($pos !== false) {
            return substr($serverPath, $pos);
        }
        $pos = strpos($serverPath, 'uploads\\');
        if ($pos !== false) {
            return str_replace('\\', '/', substr($serverPath, $pos));
        }

        return $serverPath;
    }

    /**
     * رفع صورة منتج جديدة
     *
     * الخطوات:
     * 1. التحقق من الملف والمنتج
     * 2. إنشاء مجلد المنتج وحمايته
     * 3. تحميل الصورة في GD
     * 4. معالجة الصورة (أصلية + إزالة خلفية + متوسطة + مصغرة)
     * 5. حفظ في قاعدة البيانات
     * 6. عند فشل قاعدة البيانات، حذف الملفات المنشأة
     *
     * @param int $productId معرف المنتج
     * @param array $file مصفوفة $_FILES entry
     * @param bool $removeBg إزالة الخلفية
     * @param string $bgTolerance مستوى تحمل إزالة الخلفية (low/medium/high)
     * @return array نتيجة العملية
     */
    public static function upload(int $productId, array $file, bool $removeBg = false, string $bgTolerance = 'medium'): array
    {
        // 1. التحقق من الملف
        $validation = self::validateFile($file);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        // 2. التحقق من وجود المنتج
        $product = fetchOne("SELECT id FROM products WHERE id = ?", [$productId]);
        if (!$product) {
            return ['success' => false, 'error' => 'المنتج غير موجود'];
        }

        // 3. إنشاء مجلد المنتج
        $productDir = self::getProductDir($productId);
        if (!is_dir($productDir)) {
            if (!@mkdir($productDir, 0755, true)) {
                return ['success' => false, 'error' => 'فشل في إنشاء مجلد الصور'];
            }
        }

        // إصلاح .htaccess القديم إذا وجد
        self::fixExistingHtaccess($productDir);
        // حماية المجلد
        self::protectDirectory($productDir);

        // 4. تحميل الصورة في GD
        $srcImage = self::loadImage($file['tmp_name'], $validation['mime']);
        if ($srcImage === null) {
            return ['success' => false, 'error' => 'فشل في تحميل الصورة. تأكد من أن الملف صورة صالحة'];
        }

        $srcW = imagesx($srcImage);
        $srcH = imagesy($srcImage);

        // التحقق من دعم WebP
        $useWebP = self::isWebPSupported();

        // إنشاء اسم ملف فريد
        $baseName = uniqid('img_', true);
        $origExt  = $useWebP ? 'webp' : 'jpg';
        $origFile = $productDir . '/' . $baseName . '_original.' . $origExt;
        $medFile  = $productDir . '/' . $baseName . '_medium.'  . $origExt;
        $thumbFile = $productDir . '/' . $baseName . '_thumb.'  . $origExt;

        // ملفات تم إنشاؤها — للحذف عند فشل قاعدة البيانات
        $createdFiles = [];

        try {
            // أ. حفظ النسخة الأصلية
            if ($useWebP) {
                imagewebp($srcImage, $origFile, self::QUALITY_WEBP);
            } else {
                imagejpeg($srcImage, $origFile, self::QUALITY_JPEG);
            }
            $createdFiles[] = $origFile;

            // ب. إزالة الخلفية إذا طُلب
            $processedImage = $srcImage;
            if ($removeBg) {
                $bgResult = self::removeBackground($srcImage, $srcW, $srcH, $bgTolerance);
                if ($bgResult !== null) {
                    $processedImage = $bgResult['image'];
                    // لا نحرر srcImage لأن processedImage قد يكون نفسه أو جديداً
                }
            }

            // ج. إنشاء نسخة متوسطة (600×600)
            $mediumImage = self::resizeImage($processedImage, $srcW, $srcH, self::MEDIUM_WIDTH, self::MEDIUM_HEIGHT);
            if ($mediumImage !== null) {
                if ($useWebP) {
                    imagewebp($mediumImage, $medFile, self::QUALITY_WEBP);
                } else {
                    // المتوسطة تحتاج خلفية بيضاء لـ JPEG (لا يدعم الشفافية)
                    $whiteBg = imagecreatetruecolor(imagesx($mediumImage), imagesy($mediumImage));
                    if ($whiteBg !== false) {
                        imagefill($whiteBg, 0, 0, imagecolorallocate($whiteBg, 255, 255, 255));
                        imagecopy($whiteBg, $mediumImage, 0, 0, 0, 0, imagesx($mediumImage), imagesy($mediumImage));
                        imagejpeg($whiteBg, $medFile, self::QUALITY_JPEG);
                        imagedestroy($whiteBg);
                    } else {
                        imagejpeg($mediumImage, $medFile, self::QUALITY_JPEG);
                    }
                }
                $createdFiles[] = $medFile;
                imagedestroy($mediumImage);
            }

            // د. إنشاء صورة مصغرة (150×150)
            $thumbImage = self::resizeImage($processedImage, $srcW, $srcH, self::THUMB_WIDTH, self::THUMB_HEIGHT);
            if ($thumbImage !== null) {
                if ($useWebP) {
                    imagewebp($thumbImage, $thumbFile, self::QUALITY_WEBP);
                } else {
                    $whiteBg = imagecreatetruecolor(imagesx($thumbImage), imagesy($thumbImage));
                    if ($whiteBg !== false) {
                        imagefill($whiteBg, 0, 0, imagecolorallocate($whiteBg, 255, 255, 255));
                        imagecopy($whiteBg, $thumbImage, 0, 0, 0, 0, imagesx($thumbImage), imagesy($thumbImage));
                        imagejpeg($whiteBg, $thumbFile, self::QUALITY_JPEG);
                        imagedestroy($whiteBg);
                    } else {
                        imagejpeg($thumbImage, $thumbFile, self::QUALITY_JPEG);
                    }
                }
                $createdFiles[] = $thumbFile;
                imagedestroy($thumbImage);
            }

            // هـ. تحرير الذاكرة
            if ($removeBg && $processedImage !== $srcImage && is_resource($processedImage)) {
                imagedestroy($processedImage);
            }
            imagedestroy($srcImage);

        } catch (\Throwable $e) {
            // تنظيف الصور المحملة في الذاكرة
            if (isset($srcImage) && is_resource($srcImage)) {
                imagedestroy($srcImage);
            }
            // حذف الملفات المنشأة
            foreach ($createdFiles as $f) {
                @unlink($f);
            }
            self::logError($e, 'upload(imageProcessing)');
            return ['success' => false, 'error' => 'حدث خطأ أثناء معالجة الصورة'];
        }

        // 5. حفظ في قاعدة البيانات
        try {
            $pdo = getDBConnection();
            self::ensureImageTableExists($pdo);

            // بناء استعلام INSERT ديناميكي حسب الأعمدة المتوفرة
            $columns = self::getTableColumns($pdo, 'product_images');

            $insertCols = ['product_id'];
            $insertVals = [$productId];
            $placeholders = ['?'];

            // original_path
            if (in_array('original_path', $columns)) {
                $insertCols[] = 'original_path';
                $insertVals[] = $origFile;
                $placeholders[] = '?';
            }

            // medium_path
            if (in_array('medium_path', $columns)) {
                $insertCols[] = 'medium_path';
                $insertVals[] = $medFile;
                $placeholders[] = '?';
            }

            // thumbnail_path
            if (in_array('thumbnail_path', $columns)) {
                $insertCols[] = 'thumbnail_path';
                $insertVals[] = $thumbFile;
                $placeholders[] = '?';
            }

            // mime_type
            if (in_array('mime_type', $columns)) {
                $insertCols[] = 'mime_type';
                $insertVals[] = $validation['mime'];
                $placeholders[] = '?';
            }

            // file_size
            if (in_array('file_size', $columns)) {
                $insertCols[] = 'file_size';
                $insertVals[] = intval($file['size']);
                $placeholders[] = '?';
            }

            // width / height
            if (in_array('width', $columns)) {
                $insertCols[] = 'width';
                $insertVals[] = $srcW;
                $placeholders[] = '?';
            }
            if (in_array('height', $columns)) {
                $insertCols[] = 'height';
                $insertVals[] = $srcH;
                $placeholders[] = '?';
            }

            // is_primary — أول صورة للمنتج تكون رئيسية تلقائياً
            if (in_array('is_primary', $columns)) {
                $existingCount = fetchOne("SELECT COUNT(*) as cnt FROM product_images WHERE product_id = ? AND deleted_at IS NULL", [$productId]);
                $isPrimary = ($existingCount && intval($existingCount['cnt']) === 0) ? 1 : 0;
                $insertCols[] = 'is_primary';
                $insertVals[] = $isPrimary;
                $placeholders[] = '?';
            }

            // bg_removed
            if (in_array('bg_removed', $columns)) {
                $insertCols[] = 'bg_removed';
                $insertVals[] = $removeBg ? 1 : 0;
                $placeholders[] = '?';
            }

            // sort_order
            if (in_array('sort_order', $columns)) {
                $maxSort = fetchOne("SELECT MAX(sort_order) as max_sort FROM product_images WHERE product_id = ?", [$productId]);
                $sortOrder = ($maxSort && $maxSort['max_sort'] !== null) ? intval($maxSort['max_sort']) + 1 : 0;
                $insertCols[] = 'sort_order';
                $insertVals[] = $sortOrder;
                $placeholders[] = '?';
            }

            $sql = "INSERT INTO product_images (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($insertVals);
            $imageId = $pdo->lastInsertId();

            // تحديث الصورة الرئيسية في جدول المنتجات
            if (isset($isPrimary) && $isPrimary === 1) {
                self::updateProductPrimaryImage($productId, $thumbFile);
            }

            return [
                'success' => true,
                'image_id' => intval($imageId),
                'original_path' => $origFile,
                'medium_path'   => $medFile,
                'thumbnail_path' => $thumbFile,
                'message' => 'تم رفع الصورة بنجاح'
            ];

        } catch (\Throwable $e) {
            // فشل في قاعدة البيانات — حذف الملفات المنشأة
            foreach ($createdFiles as $f) {
                @unlink($f);
            }
            self::logError($e, 'upload(database)');
            return ['success' => false, 'error' => 'حدث خطأ أثناء حفظ بيانات الصورة'];
        }
    }

    /**
     * حذف ناعم لصورة منتج
     *
     * @param int $imageId معرف الصورة
     * @return array نتيجة العملية
     */
    public static function softDelete(int $imageId): array
    {
        try {
            $image = fetchOne("SELECT * FROM product_images WHERE id = ? AND deleted_at IS NULL", [$imageId]);
            if (!$image) {
                return ['success' => false, 'error' => 'الصورة غير موجودة أو محذوفة مسبقاً'];
            }

            $pdo = getDBConnection();
            $pdo->prepare("UPDATE product_images SET deleted_at = NOW() WHERE id = ?")->execute([$imageId]);

            // إذا كانت الصورة رئيسية، نعين صورة أخرى كرئيسية
            if (!empty($image['is_primary'])) {
                $nextImage = fetchOne(
                    "SELECT id, thumbnail_path FROM product_images WHERE product_id = ? AND deleted_at IS NULL AND id != ? ORDER BY sort_order ASC LIMIT 1",
                    [$image['product_id'], $imageId]
                );
                if ($nextImage) {
                    $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = ?")->execute([$nextImage['id']]);
                    self::updateProductPrimaryImage(intval($image['product_id']), $nextImage['thumbnail_path'] ?? null);
                } else {
                    self::updateProductPrimaryImage(intval($image['product_id']), null);
                }
            }

            return ['success' => true, 'message' => 'تم حذف الصورة بنجاح'];

        } catch (\Throwable $e) {
            self::logError($e, 'softDelete');
            return ['success' => false, 'error' => 'حدث خطأ أثناء حذف الصورة'];
        }
    }

    /**
     * استعادة صورة محذوفة ناعماً
     *
     * @param int $imageId معرف الصورة
     * @return array نتيجة العملية
     */
    public static function restore(int $imageId): array
    {
        try {
            $image = fetchOne("SELECT * FROM product_images WHERE id = ? AND deleted_at IS NOT NULL", [$imageId]);
            if (!$image) {
                return ['success' => false, 'error' => 'الصورة غير موجودة أو ليست محذوفة'];
            }

            $pdo = getDBConnection();
            $pdo->prepare("UPDATE product_images SET deleted_at = NULL WHERE id = ?")->execute([$imageId]);

            return ['success' => true, 'message' => 'تم استعادة الصورة بنجاح'];

        } catch (\Throwable $e) {
            self::logError($e, 'restore');
            return ['success' => false, 'error' => 'حدث خطأ أثناء استعادة الصورة'];
        }
    }

    /**
     * تعيين صورة كرئيسية للمنتج
     *
     * @param int $imageId معرف الصورة
     * @return array نتيجة العملية
     */
    public static function setPrimary(int $imageId): array
    {
        try {
            $image = fetchOne("SELECT * FROM product_images WHERE id = ? AND deleted_at IS NULL", [$imageId]);
            if (!$image) {
                return ['success' => false, 'error' => 'الصورة غير موجودة أو محذوفة'];
            }

            $pdo = getDBConnection();
            $productId = intval($image['product_id']);

            // إزالة الرئيسية من كل صور المنتج
            $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?")->execute([$productId]);

            // تعيين الصورة الجديدة كرئيسية
            $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = ?")->execute([$imageId]);

            // تحديث جدول المنتجات
            self::updateProductPrimaryImage($productId, $image['thumbnail_path'] ?? null);

            return ['success' => true, 'message' => 'تم تعيين الصورة كرئيسية'];

        } catch (\Throwable $e) {
            self::logError($e, 'setPrimary');
            return ['success' => false, 'error' => 'حدث خطأ أثناء تعيين الصورة الرئيسية'];
        }
    }

    /**
     * تحديث ترتيب الصور
     *
     * @param array $imageOrders مصفوفة من ['id' => int, 'sort_order' => int]
     * @return array نتيجة العملية
     */
    public static function updateSortOrder(array $imageOrders): array
    {
        if (empty($imageOrders)) {
            return ['success' => false, 'error' => 'لم يتم تقديم ترتيب'];
        }

        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("UPDATE product_images SET sort_order = ? WHERE id = ?");

            foreach ($imageOrders as $item) {
                $id = intval($item['id'] ?? 0);
                $order = intval($item['sort_order'] ?? 0);
                if ($id > 0) {
                    $stmt->execute([$order, $id]);
                }
            }

            return ['success' => true, 'message' => 'تم تحديث ترتيب الصور'];

        } catch (\Throwable $e) {
            self::logError($e, 'updateSortOrder');
            return ['success' => false, 'error' => 'حدث خطأ أثناء تحديث ترتيب الصور'];
        }
    }

    /**
     * جلب صور المنتج النشطة (غير المحذوفة)
     *
     * @param int $productId معرف المنتج
     * @return array قائمة الصور
     */
    public static function getActiveImages(int $productId): array
    {
        try {
            return fetchAll(
                "SELECT * FROM product_images WHERE product_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC",
                [$productId]
            );
        } catch (\Throwable $e) {
            self::logError($e, 'getActiveImages');
            return [];
        }
    }

    /**
     * جلب صور المنتج المحذوفة ناعماً
     *
     * @param int $productId معرف المنتج
     * @return array قائمة الصور المحذوفة
     */
    public static function getDeletedImages(int $productId): array
    {
        try {
            return fetchAll(
                "SELECT * FROM product_images WHERE product_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC",
                [$productId]
            );
        } catch (\Throwable $e) {
            self::logError($e, 'getDeletedImages');
            return [];
        }
    }

    /**
     * جلب الصورة الرئيسية للمنتج
     *
     * @param int $productId معرف المنتج
     * @return array|null بيانات الصورة الرئيسية أو null
     */
    public static function getPrimaryImage(int $productId): ?array
    {
        try {
            $image = fetchOne(
                "SELECT * FROM product_images WHERE product_id = ? AND is_primary = 1 AND deleted_at IS NULL LIMIT 1",
                [$productId]
            );
            if ($image) {
                return $image;
            }
            // إذا لم توجد صورة رئيسية، نعيد أول صورة نشطة
            $first = fetchOne(
                "SELECT * FROM product_images WHERE product_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC LIMIT 1",
                [$productId]
            );
            return $first ?: null;
        } catch (\Throwable $e) {
            self::logError($e, 'getPrimaryImage');
            return null;
        }
    }

    /**
     * تنظيف الصور المحذوفة ناعماً بعد عدد أيام محدد
     *
     * @param int $days عدد الأيام (0 = استخدام CLEANUP_DAYS)
     * @return int عدد الصور المحذوفة نهائياً
     */
    public static function cleanup(int $days = 0): int
    {
        $days = $days > 0 ? $days : self::CLEANUP_DAYS;
        $deletedCount = 0;

        try {
            $images = fetchAll(
                "SELECT * FROM product_images WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );

            foreach ($images as $image) {
                self::deleteImageFiles($image);
                try {
                    $pdo = getDBConnection();
                    $pdo->prepare("DELETE FROM product_images WHERE id = ?")->execute([$image['id']]);
                    $deletedCount++;
                } catch (\Throwable $e) {
                    self::logError($e, 'cleanup(deleteRecord)');
                }
            }

        } catch (\Throwable $e) {
            self::logError($e, 'cleanup');
        }

        return $deletedCount;
    }

    /**
     * جلب إحصائيات صور المنتجات
     *
     * @return array إحصائيات
     */
    public static function getStats(): array
    {
        try {
            $total       = fetchOne("SELECT COUNT(*) as cnt FROM product_images WHERE deleted_at IS NULL");
            $deleted     = fetchOne("SELECT COUNT(*) as cnt FROM product_images WHERE deleted_at IS NOT NULL");
            $primary     = fetchOne("SELECT COUNT(*) as cnt FROM product_images WHERE is_primary = 1 AND deleted_at IS NULL");
            $bgRemoved   = fetchOne("SELECT COUNT(*) as cnt FROM product_images WHERE bg_removed = 1 AND deleted_at IS NULL");
            $totalSize   = fetchOne("SELECT COALESCE(SUM(file_size), 0) as total FROM product_images WHERE deleted_at IS NULL");

            return [
                'total_active'   => intval($total['cnt'] ?? 0),
                'total_deleted'  => intval($deleted['cnt'] ?? 0),
                'total_primary'  => intval($primary['cnt'] ?? 0),
                'total_bg_removed' => intval($bgRemoved['cnt'] ?? 0),
                'total_size'     => intval($totalSize['total'] ?? 0),
                'total_size_formatted' => self::formatFileSize(intval($totalSize['total'] ?? 0)),
            ];
        } catch (\Throwable $e) {
            self::logError($e, 'getStats');
            return [
                'total_active'   => 0,
                'total_deleted'  => 0,
                'total_primary'  => 0,
                'total_bg_removed' => 0,
                'total_size'     => 0,
                'total_size_formatted' => '0 B',
            ];
        }
    }

    // ========================================================================
    // ===== الطرق الخاصة (Private Methods) =====
    // ========================================================================

    /**
     * التحقق من دعم WebP عبر اختبار فعلي
     *
     * يتحقق من وجود دالة imagewebp ثم يجرب إنشاء صورة WebP صغيرة
     * للتأكد من أن المكتبة تعمل فعلاً وليست موجودة اسمياً فقط
     *
     * @return bool هل WebP مدعوم
     */
    private static function isWebPSupported(): bool
    {
        if (self::$webpSupported !== null) {
            return self::$webpSupported;
        }

        if (!function_exists('imagewebp')) {
            self::$webpSupported = false;
            return false;
        }

        // اختبار فعلي — إنشاء صورة 1×1 WebP
        $testImg = @imagecreatetruecolor(1, 1);
        if ($testImg === false) {
            self::$webpSupported = false;
            return false;
        }

        ob_start();
        $result = @imagewebp($testImg);
        ob_end_clean();
        imagedestroy($testImg);

        self::$webpSupported = ($result !== false);
        return self::$webpSupported;
    }

    /**
     * إزالة خلفية الصورة بخوارزمية Color Distance
     *
     * الخوارزمية:
     * 1. تصغير الصورة إذا كانت كبيرة (لأداء أفضل)
     * 2. كشف لون الخلفية من حواف الصورة
     * 3. تطبيق إزالة الخلفية بحساب المسافة اللونية
     * 4. تنعيم الحواف (Feathering)
     *
     * @param \GdImage $srcImage صورة GD
     * @param int $width عرض الصورة
     * @param int $height ارتفاع الصورة
     * @param string $tolerance مستوى التحمل (low/medium/high)
     * @return array|null ['image' => \GdImage] أو null عند الفشل
     */
    private static function removeBackground($srcImage, int $width, int $height, string $tolerance = 'medium'): ?array
    {
        // التحقق من الذاكرة المتوفرة
        $requiredMemory = $width * $height * 4 * 3; // بايت تقريبي
        $memoryLimit = self::parseMemoryLimit(ini_get('memory_limit'));
        $usedMemory = memory_get_usage(true);
        $availableMemory = $memoryLimit - $usedMemory;

        if ($requiredMemory > $availableMemory) {
            // تصغير الصورة لتوفير الذاكرة
            $scale = sqrt($availableMemory / $requiredMemory) * 0.8;
            if ($scale < 0.3) {
                return null; // لا يمكن المعالجة
            }
            $newW = max(1, intval($width * $scale));
            $newH = max(1, intval($height * $scale));
            $resized = self::resizeImage($srcImage, $width, $height, $newW, $newH);
            if ($resized === null) {
                return null;
            }
            $workImage = $resized;
            $workW = $newW;
            $workH = $newH;
        } else {
            // نسخ الصورة للعمل عليها
            $workImage = imagecreatetruecolor($width, $height);
            if ($workImage === false) {
                return null;
            }
            imagecopy($workImage, $srcImage, 0, 0, 0, 0, $width, $height);
            $workW = $width;
            $workH = $height;
        }

        // الحد الأقصى لحجم العمل
        if ($workW > self::BG_MAX_WORK_SIZE || $workH > self::BG_MAX_WORK_SIZE) {
            $ratio = min(self::BG_MAX_WORK_SIZE / $workW, self::BG_MAX_WORK_SIZE / $workH);
            $newW = max(1, intval($workW * $ratio));
            $newH = max(1, intval($workH * $ratio));
            $resized = self::resizeImage($workImage, $workW, $workH, $newW, $newH);
            if ($resized !== null) {
                imagedestroy($workImage);
                $workImage = $resized;
                $workW = $newW;
                $workH = $newH;
            }
        }

        // كشف لون الخلفية
        $bgColor = self::detectBackgroundColor($workImage, $workW, $workH);
        if ($bgColor === null) {
            imagedestroy($workImage);
            return null;
        }

        // تحديد مستوى التحمل
        $toleranceValue = match (strtolower($tolerance)) {
            'low'    => self::BG_TOLERANCE_LOW,
            'high'   => self::BG_TOLERANCE_HIGH,
            default  => self::BG_TOLERANCE_MEDIUM,
        };

        // تفعيل الشفافية
        imagealphablending($workImage, false);
        imagesavealpha($workImage, true);

        // تطبيق إزالة الخلفية
        self::applyBackgroundRemoval($workImage, $workW, $workH, $bgColor['r'], $bgColor['g'], $bgColor['b'], $toleranceValue);

        return ['image' => $workImage];
    }

    /**
     * كشف لون الخلفية من حواف الصورة
     *
     * يأخذ عينات من حواف الصورة ويحسب الوسيط للون الخلفية
     *
     * @param \GdImage $image صورة GD
     * @param int $w عرض الصورة
     * @param int $h ارتفاع الصورة
     * @return array|null ['r' => int, 'g' => int, 'b' => int] أو null
     */
    private static function detectBackgroundColor($image, int $w, int $h): ?array
    {
        $samples = [];
        $step = max(1, intval(min($w, $h) / self::BG_BORDER_SAMPLES));

        // أخذ عينات من الحواف الأربعة
        for ($x = 0; $x < $w; $x += $step) {
            // الحافة العلوية
            $rgb = imagecolorat($image, $x, 0);
            $samples[] = ['r' => ($rgb >> 16) & 0xFF, 'g' => ($rgb >> 8) & 0xFF, 'b' => $rgb & 0xFF];
            // الحافة السفلية
            $rgb = imagecolorat($image, $x, max(0, $h - 1));
            $samples[] = ['r' => ($rgb >> 16) & 0xFF, 'g' => ($rgb >> 8) & 0xFF, 'b' => $rgb & 0xFF];
        }
        for ($y = 0; $y < $h; $y += $step) {
            // الحافة اليسرى
            $rgb = imagecolorat($image, 0, $y);
            $samples[] = ['r' => ($rgb >> 16) & 0xFF, 'g' => ($rgb >> 8) & 0xFF, 'b' => $rgb & 0xFF];
            // الحافة اليمنى
            $rgb = imagecolorat($image, max(0, $w - 1), $y);
            $samples[] = ['r' => ($rgb >> 16) & 0xFF, 'g' => ($rgb >> 8) & 0xFF, 'b' => $rgb & 0xFF];
        }

        if (empty($samples)) {
            return null;
        }

        // حساب الوسيط لكل قناة
        $rValues = array_column($samples, 'r');
        $gValues = array_column($samples, 'g');
        $bValues = array_column($samples, 'b');

        sort($rValues);
        sort($gValues);
        sort($bValues);

        $mid = intval(count($samples) / 2);

        return [
            'r' => $rValues[$mid],
            'g' => $gValues[$mid],
            'b' => $bValues[$mid],
        ];
    }

    /**
     * تطبيق إزالة الخلفية على الصورة
     *
     * خوارزمية Color Distance:
     * - تحسب المسافة الإقليدية بين كل بكسل ولون الخلفية
     * - إذا كانت المسافة أقل من التحمل → شفاف تماماً
     * - إذا كانت ضمن نطاق التنعيم → شفافية تدريجية (Feathering)
     *
     * @param \GdImage $image صورة GD (تُعدَّل مباشرة)
     * @param int $w عرض الصورة
     * @param int $h ارتفاع الصورة
     * @param int $bgR أحمر الخلفية
     * @param int $bgG أخضر الخلفية
     * @param int $bgB أزرق الخلفية
     * @param int $tolerance مستوى التحمل
     */
    private static function applyBackgroundRemoval(&$image, int $w, int $h, int $bgR, int $bgG, int $bgB, int $tolerance): void
    {
        $featherRadius = self::BG_FEATHER_RADIUS;
        $startTime = microtime(true);
        $maxExecutionTime = intval(ini_get('max_execution_time'));
        if ($maxExecutionTime <= 0) {
            $maxExecutionTime = 30;
        }
        $timeLimit = $maxExecutionTime * 0.8; // استخدام 80% من الوقت المسموح

        $toleranceSq = $tolerance * $tolerance;
        $featherSq = ($tolerance + $featherRadius) * ($tolerance + $featherRadius);
        $featherRange = $featherSq - $toleranceSq;

        $pixelCount = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $a = ($rgb >> 24) & 0x7F;

                // حساب المسافة الإقليدية RGB
                $distR = $r - $bgR;
                $distG = $g - $bgG;
                $distB = $b - $bgB;
                $distSq = $distR * $distR + $distG * $distG + $distB * $distB;

                if ($distSq <= $toleranceSq) {
                    // ضمن التحمل → شفاف تماماً
                    $newAlpha = 127;
                } elseif ($distSq <= $featherSq && $featherRange > 0) {
                    // منطقة التنعيم → شفافية تدريجية
                    $featherPos = ($distSq - $toleranceSq) / $featherRange;
                    $newAlpha = intval(127 * (1 - $featherPos));
                    $newAlpha = max(0, min(127, $newAlpha));
                } else {
                    // خارج نطاق الإزالة → يبقى كما هو (شفافية 0 = معتم)
                    $newAlpha = 0;
                }

                if ($newAlpha !== $a || $newAlpha > 0) {
                    $newColor = imagecolorallocatealpha($image, $r, $g, $b, $newAlpha);
                    if ($newColor !== false) {
                        imagesetpixel($image, $x, $y, $newColor);
                    }
                }

                // فحص وقت التنفيذ بشكل دوري (كل 50 ألف بكسل)
                $pixelCount++;
                if ($pixelCount % 50000 === 0) {
                    $elapsed = microtime(true) - $startTime;
                    if ($elapsed > $timeLimit) {
                        // تجاوزنا الوقت — نتوقف لتفادي خطأ timeout
                        return;
                    }
                }
            }
        }
    }

    /**
     * تحويل قيمة memory_limit إلى بايت
     *
     * @param string $limit قيمة مثل '256M' أو '1G'
     * @return int القيمة بالبايت
     */
    private static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || strtolower($limit) === '-1') {
            return PHP_INT_MAX;
        }

        $last = strtolower(substr($limit, -1));
        $value = intval(substr($limit, 0, -1));

        return match ($last) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => intval($limit),
        };
    }

    /**
     * التحقق من صحة الملف المرفوع
     *
     * @param array $file مصفوفة $_FILES entry
     * @return array ['valid' => bool, 'error' => string, 'mime' => string]
     */
    private static function validateFile(array $file): array
    {
        // التحقق من وجود الملف
        if (empty($file) || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'لم يتم اختيار ملف', 'mime' => ''];
        }

        // التحقق من أخطاء الرفع
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحجم المسموح به في إعدادات الخادم',
                UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحجم المسموح به في النموذج',
                UPLOAD_ERR_PARTIAL    => 'تم رفع جزء من الملف فقط',
                UPLOAD_ERR_NO_FILE    => 'لم يتم رفع أي ملف',
                UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود',
                UPLOAD_ERR_CANT_WRITE => 'فشل في كتابة الملف على القرص',
            ];
            $errMsg = $errors[$file['error']] ?? 'حدث خطأ أثناء رفع الملف';
            return ['valid' => false, 'error' => $errMsg, 'mime' => ''];
        }

        // التحقق من أن الملف رُفع فعلاً عبر HTTP POST
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'الملف غير صالح', 'mime' => ''];
        }

        // التحقق من حجم الملف
        if ($file['size'] > self::MAX_FILE_SIZE) {
            $maxMB = round(self::MAX_FILE_SIZE / 1048576, 1);
            return ['valid' => false, 'error' => "حجم الملف يتجاوز الحد المسموح ({$maxMB} ميغابايت)", 'mime' => ''];
        }

        // التحقق من الامتداد
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT)) {
            $allowed = implode('، ', self::ALLOWED_EXT);
            return ['valid' => false, 'error' => "نوع الملف غير مسموح. الأنواع المسموحة: {$allowed}", 'mime' => ''];
        }

        // التحقق من MIME type الحقيقي
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!in_array($mime, self::ALLOWED_MIME)) {
            return ['valid' => false, 'error' => 'نوع الملف الفعلي غير مسموح', 'mime' => ''];
        }

        return ['valid' => true, 'error' => '', 'mime' => $mime];
    }

    /**
     * تحميل صورة من مسار ملف
     *
     * @param string $path مسار الملف
     * @param string $mime نوع MIME
     * @return \GdImage|null صورة GD أو null عند الفشل
     */
    private static function loadImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => null,
        };
    }

    /**
     * تغيير حجم الصورة مع الحفاظ على نسبة العرض إلى الارتفاع
     *
     * يحافظ على أبعاد الصورة ويقيدها داخل الحد الأقصى المحدد
     * يتضمن فحوصات أمان لكل عملية GD
     *
     * @param \GdImage $srcImage صورة المصدر
     * @param int $srcW عرض المصدر
     * @param int $srcH ارتفاع المصدر
     * @param int $maxW العرض الأقصى
     * @param int $maxH الارتفاع الأقصى
     * @return \GdImage|null الصورة المُغيَّرة أو null عند الفشل
     */
    private static function resizeImage($srcImage, int $srcW, int $srcH, int $maxW, int $maxH)
    {
        // فحوصات أمان
        if ($srcW <= 0 || $srcH <= 0 || $maxW <= 0 || $maxH <= 0) {
            return null;
        }

        // حساب الأبعاد الجديدة مع الحفاظ على النسبة
        $ratio = min($maxW / $srcW, $maxH / $srcH);

        // إذا كانت الصورة أصغر من الحد الأقصى، لا نكبّرها
        if ($ratio >= 1) {
            $ratio = 1;
        }

        $newW = max(1, intval($srcW * $ratio));
        $newH = max(1, intval($srcH * $ratio));

        // إنشاء صورة جديدة
        $dstImage = @imagecreatetruecolor($newW, $newH);
        if ($dstImage === false) {
            return null;
        }

        // تفعيل الشفافية
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);

        // ملء بخلفية شفافة
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($dstImage, 0, 0, $transparent);
        }

        // نسخ مع تغيير الحجم
        $result = imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        if (!$result) {
            imagedestroy($dstImage);
            return null;
        }

        return $dstImage;
    }

    /**
     * التأكد من وجود جدول product_images وإنشائه إن لم يكن موجوداً
     *
     * @param \PDO $pdo اتصال قاعدة البيانات
     */
    private static function ensureImageTableExists(\PDO $pdo): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `product_images` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `original_path` VARCHAR(500) DEFAULT NULL,
            `medium_path` VARCHAR(500) DEFAULT NULL,
            `thumbnail_path` VARCHAR(500) DEFAULT NULL,
            `mime_type` VARCHAR(50) DEFAULT NULL,
            `file_size` INT UNSIGNED DEFAULT 0,
            `width` INT UNSIGNED DEFAULT NULL,
            `height` INT UNSIGNED DEFAULT NULL,
            `is_primary` TINYINT(1) DEFAULT 0,
            `bg_removed` TINYINT(1) DEFAULT 0,
            `sort_order` INT DEFAULT 0,
            `deleted_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_product_id` (`product_id`),
            INDEX `idx_deleted_at` (`deleted_at`),
            INDEX `idx_is_primary` (`is_primary`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $pdo->exec($sql);
    }

    /**
     * جلب أسماء أعمدة جدول معين مع تخزين مؤقت
     *
     * @param \PDO $pdo اتصال قاعدة البيانات
     * @param string $table اسم الجدول
     * @return array أسماء الأعمدة
     */
    private static function getTableColumns(\PDO $pdo, string $table): array
    {
        if (isset(self::$tableColumnsCache[$table])) {
            return self::$tableColumnsCache[$table];
        }

        try {
            $stmt = $pdo->query("DESCRIBE `{$table}`");
            $columns = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            self::$tableColumnsCache[$table] = $columns;
            return $columns;
        } catch (\Throwable $e) {
            // في حال فشل DESCRIBE (جدول غير موجود مثلاً)
            self::$tableColumnsCache[$table] = [];
            return [];
        }
    }

    /**
     * تحديث مسار الصورة الرئيسية في جدول المنتجات
     *
     * @param int $productId معرف المنتج
     * @param string|null $thumbnailPath مسار الصورة المصغرة أو null
     */
    private static function updateProductPrimaryImage(int $productId, ?string $thumbnailPath): void
    {
        try {
            $pdo = getDBConnection();

            // تحويل المسار إلى مسار ويب نسبي
            $webPath = $thumbnailPath ? self::getWebPath($thumbnailPath) : null;

            // التحقق من وجود عمود image في جدول المنتجات
            $columns = self::getTableColumns($pdo, 'products');
            if (in_array('image', $columns)) {
                $pdo->prepare("UPDATE products SET image = ? WHERE id = ?")->execute([$webPath, $productId]);
            } elseif (in_array('primary_image', $columns)) {
                $pdo->prepare("UPDATE products SET primary_image = ? WHERE id = ?")->execute([$webPath, $productId]);
            }
        } catch (\Throwable $e) {
            self::logError($e, 'updateProductPrimaryImage');
        }
    }

    /**
     * حذف ملفات الصورة من القرص
     *
     * @param array $image صف بيانات الصورة من قاعدة البيانات
     */
    private static function deleteImageFiles(array $image): void
    {
        $paths = ['original_path', 'medium_path', 'thumbnail_path'];
        foreach ($paths as $key) {
            if (!empty($image[$key])) {
                $path = $image[$key];
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }

        // محاولة حذف المجلد إذا أصبح فارغاً
        if (!empty($image['product_id'])) {
            $dir = self::getProductDir(intval($image['product_id']));
            if (is_dir($dir)) {
                $files = array_diff(scandir($dir), ['.', '..', '.htaccess']);
                if (empty($files)) {
                    @unlink($dir . '/.htaccess');
                    @rmdir($dir);
                }
            }
        }
    }

    /**
     * الحصول على مسار مجلد صور المنتج
     *
     * @param int $productId معرف المنتج
     * @return string مسار المجلد
     */
    private static function getProductDir(int $productId): string
    {
        return __DIR__ . '/../uploads/products/' . $productId;
    }

    /**
     * إصلاح ملف .htaccess القديم (Apache 2.2 → Apache 2.4)
     *
     * يتحقق من وجود 'Require all' — إذا لم يوجد، يعيد إنشاء الملف
     * بالصيغة المتوافقة مع Apache 2.4 مع تراجع إلى 2.2
     *
     * @param string $dir مسار المجلد
     */
    private static function fixExistingHtaccess(string $dir): void
    {
        $htaccessPath = $dir . '/.htaccess';

        if (!file_exists($htaccessPath)) {
            return;
        }

        $content = @file_get_contents($htaccessPath);
        if ($content === false) {
            return;
        }

        // إذا كان الملف يحتوي على 'Require all'، فهو بصيغة Apache 2.4 — لا حاجة للإصلاح
        if (str_contains($content, 'Require all')) {
            return;
        }

        // الملف بصيغة قديمة — نعيد إنشاؤه
        self::protectDirectory($dir);
    }

    /**
     * حماية مجلد بملف .htaccess متوافق مع Apache 2.4 مع تراجع إلى 2.2
     *
     * يتضمن:
     * - السماح بالوصول للصور فقط (Require all granted)
     * - إضافة نوع MIME لـ WebP
     * - ترويسات التخزين المؤقت للصور
     *
     * @param string $dir مسار المجلد
     */
    private static function protectDirectory(string $dir): void
    {
        $htaccessPath = $dir . '/.htaccess';

        $content = "# Auto-generated by ProductImageService — Apache 2.4+ compatible
<IfModule mod_authz_core.c>
    Require all granted
</IfModule>
<IfModule !mod_authz_core.c>
    Allow from all
</IfModule>

# WebP MIME type
<IfModule mod_mime.c>
    AddType image/webp .webp
</IfModule>

# Cache headers for images
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg \"access plus 1 month\"
    ExpiresByType image/png \"access plus 1 month\"
    ExpiresByType image/gif \"access plus 1 month\"
    ExpiresByType image/webp \"access plus 1 month\"
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">
        Header set Cache-Control \"public, max-age=2592000\"
    </FilesMatch>
</IfModule>
";

        @file_put_contents($htaccessPath, $content, LOCK_EX);
    }

    // ========================================================================
    // ===== طرق مساعدة =====
    // ========================================================================

    /**
     * تسجيل الخطأ — يستخدم ErrorHandler إن كان متوفراً، وإلا error_log
     *
     * @param \Throwable $e الاستثناء
     * @param string $context سياق إضافي
     */
    private static function logError(\Throwable $e, string $context = ''): void
    {
        if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
            ErrorHandler::logException($e, 'ProductImageService::' . $context);
        } else {
            $file = basename($e->getFile());
            error_log("[ISM] ProductImageService::{$context}: {$e->getMessage()} في {$file}:{$e->getLine()}");
        }
    }

    /**
     * تنسيق حجم الملف بشكل مقروء
     *
     * @param int $bytes حجم الملف بالبايت
     * @return string الحجم المنسق
     */
    private static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
