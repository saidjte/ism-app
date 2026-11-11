<?php
/**
 * ===== مصنع الخدمات (ServiceFactory) =====
 * يوفر نقطة وصول موحدة لجميع خدمات التطبيق
 * يسهل حقن التبعيات والاختبار
 *
 * الاستخدام:
 *   $productService = ServiceFactory::productService();
 *   $result = $productService::create($data);
 *
 * أو عبر التسجيل المخصص (للاختبار أو حقن التبعيات):
 *   ServiceFactory::register('product', new ProductService($mockDb));
 *   $report = ServiceFactory::get('product');
 *
 * @since v4.16.9
 * @since v5.25.0 إضافة userService() + توثيق حقن التبعيات
 */

class ServiceFactory
{
    /** @var array<string, object> نسخ الخدمات المسجلة */
    private static array $instances = [];

    /**
     * الحصول على خدمة المنتجات
     * يستخدم نمط Singleton لضمان نسخة واحدة فقط
     *
     * @return ProductService
     */
    public static function productService(): ProductService
    {
        if (!isset(self::$instances['product'])) {
            self::$instances['product'] = new ProductService();
        }
        return self::$instances['product'];
    }

    /**
     * الحصول على خدمة المبيعات
     *
     * @return SaleService
     */
    public static function saleService(): SaleService
    {
        if (!isset(self::$instances['sale'])) {
            self::$instances['sale'] = new SaleService();
        }
        return self::$instances['sale'];
    }

    /**
     * الحصول على خدمة المشتريات
     *
     * @return PurchaseService
     */
    public static function purchaseService(): PurchaseService
    {
        if (!isset(self::$instances['purchase'])) {
            self::$instances['purchase'] = new PurchaseService();
        }
        return self::$instances['purchase'];
    }

    /**
     * الحصول على خدمة المستخدمين
     *
     * @return UserService
     * @since v5.25.0
     */
    public static function userService(): UserService
    {
        if (!isset(self::$instances['user'])) {
            self::$instances['user'] = new UserService();
        }
        return self::$instances['user'];
    }

    /**
     * تسجيل خدمة مخصصة
     * يسمح بإضافة خدمات جديدة أو استبدال خدمات موجودة (مفيد للاختبار وحقن التبعيات)
     *
     * مثال حقن تبعيات:
     *   $mockDb = new class { public function fetchOne(...){} public function fetchAll(...){} };
     *   ServiceFactory::register('product', new ProductService($mockDb));
     *
     * @param string $name اسم الخدمة
     * @param object $service كائن الخدمة
     */
    public static function register(string $name, object $service): void
    {
        self::$instances[$name] = $service;
    }

    /**
     * الحصول على خدمة مسجلة بالاسم
     *
     * @param string $name اسم الخدمة
     * @return object|null كائن الخدمة أو null إذا لم تكن مسجلة
     */
    public static function get(string $name): ?object
    {
        return self::$instances[$name] ?? null;
    }

    /**
     * التحقق من وجود خدمة مسجلة بالاسم
     *
     * @param string $name اسم الخدمة
     * @return bool هل الخدمة مسجلة
     */
    public static function has(string $name): bool
    {
        return isset(self::$instances[$name]);
    }

    /**
     * إزالة خدمة مسجلة
     * مفيد للاختبار لإعادة تعيين الحالة
     *
     * @param string $name اسم الخدمة
     */
    public static function remove(string $name): void
    {
        unset(self::$instances[$name]);
    }

    /**
     * إزالة جميع الخدمات المسجلة
     * مفيد للاختبار لإعادة تعيين الحالة بالكامل
     */
    public static function reset(): void
    {
        self::$instances = [];
    }
}