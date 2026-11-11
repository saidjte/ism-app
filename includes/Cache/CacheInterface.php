<?php
/**
 * ===== واجهة التخزين المؤقت (CacheInterface) =====
 * تحدد العقد الذي يجب أن تلتزم به كل محولات التخزين المؤقت
 * يسمح بالتبديل بين محولات مختلفة (ملفات، Redis، Memcached)
 * 
 * الاستخدام:
 *   function doSomething(CacheInterface $cache) {
 *       $cache->set('key', 'value', 60);
 *       return $cache->get('key');
 *   }
 */

// منع التحميل المزدوج
if (interface_exists('CacheInterface')) {
    return;
}

interface CacheInterface
{
    /**
     * جلب قيمة من التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @param mixed $default القيمة الافتراضية في حال عدم وجود البيانات
     * @return mixed القيمة المخزنة أو القيمة الافتراضية
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * تخزين قيمة في التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @param mixed $value القيمة المراد تخزينها
     * @param int $ttl مدة الصلاحية بالثواني (0 = بلا انتهاء أو القيمة الافتراضية)
     * @return bool نجاح العملية
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * حذف قيمة من التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @return bool تم الحذف بنجاح
     */
    public function delete(string $key): bool;

    /**
     * التحقق من وجود مفتاح صالح في التخزين المؤقت
     *
     * @param string $key مفتاح التخزين
     * @return bool هل المفتاح موجود ولم تنتهِ صلاحيته
     */
    public function has(string $key): bool;

    /**
     * حذف جميع القيم المخزنة مؤقتاً
     *
     * @return int عدد العناصر المحذوفة
     */
    public function clear(): int;

    /**
     * تنظيف العناصر المنتهية الصلاحية
     * يمكن استدعاؤها دورياً لتحرير الموارد
     *
     * @return int عدد العناصر المنظفة
     */
    public function cleanup(): int;

    /**
     * الحصول على معلومات إحصائية عن التخزين المؤقت
     *
     * @return array معلومات التخزين المؤقت
     */
    public function getStats(): array;
}
