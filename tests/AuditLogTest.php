<?php
/**
 * اختبارات دوال سجل النشاطات (audit_log)
 * - getActionLabel: تسمية كل أنواع العمليات
 * - getAuditLogs: بناء الاستعلام مع الفلاتر
 */

class AuditLogTest extends TestCase
{
    // === اختبارات getActionLabel ===

    public function testLoginLabel(): void
    {
        $this->assertEqual('تسجيل دخول', getActionLabel('login'));
    }

    public function testLogoutLabel(): void
    {
        $this->assertEqual('تسجيل خروج', getActionLabel('logout'));
    }

    public function testLoginFailedLabel(): void
    {
        $this->assertEqual('محاولة دخول فاشلة', getActionLabel('login_failed'));
    }

    public function testProductAddLabel(): void
    {
        $this->assertEqual('إضافة منتج', getActionLabel('product_add'));
    }

    public function testProductEditLabel(): void
    {
        $this->assertEqual('تعديل منتج', getActionLabel('product_edit'));
    }

    public function testProductDeleteLabel(): void
    {
        $this->assertEqual('حذف منتج', getActionLabel('product_delete'));
    }

    public function testProductQuantityUpdateLabel(): void
    {
        $this->assertEqual('تعديل كمية منتج', getActionLabel('product_quantity_update'));
    }

    public function testSaleAddLabel(): void
    {
        $this->assertEqual('تسجيل بيع', getActionLabel('sale_add'));
    }

    public function testSaleDeleteLabel(): void
    {
        $this->assertEqual('حذف عملية بيع', getActionLabel('sale_delete'));
    }

    public function testPurchaseAddLabel(): void
    {
        $this->assertEqual('تسجيل شراء', getActionLabel('purchase_add'));
    }

    public function testPurchaseDeleteLabel(): void
    {
        $this->assertEqual('حذف عملية شراء', getActionLabel('purchase_delete'));
    }

    public function testExpenseAddLabel(): void
    {
        $this->assertEqual('تسجيل مصروف', getActionLabel('expense_add'));
    }

    public function testExpenseDeleteLabel(): void
    {
        $this->assertEqual('حذف مصروف', getActionLabel('expense_delete'));
    }

    public function testUserAddLabel(): void
    {
        $this->assertEqual('إضافة مستخدم', getActionLabel('user_add'));
    }

    public function testUserEditLabel(): void
    {
        $this->assertEqual('تعديل مستخدم', getActionLabel('user_edit'));
    }

    public function testUserDeleteLabel(): void
    {
        $this->assertEqual('حذف مستخدم', getActionLabel('user_delete'));
    }

    public function testBackupCreateLabel(): void
    {
        $this->assertEqual('إنشاء نسخة احتياطية', getActionLabel('backup_create'));
    }

    public function testBackupRestoreLabel(): void
    {
        $this->assertEqual('استعادة نسخة احتياطية', getActionLabel('backup_restore'));
    }

    public function testSettingsUpdateLabel(): void
    {
        $this->assertEqual('تعديل الإعدادات', getActionLabel('settings_update'));
    }

    public function testTransferStockLabel(): void
    {
        $this->assertEqual('تحويل مخزون', getActionLabel('transfer_stock'));
    }

    public function testExportDataLabel(): void
    {
        $this->assertEqual('تصدير بيانات', getActionLabel('export_data'));
    }

    public function testUnknownActionReturnsItself(): void
    {
        $unknown = 'custom_action_xyz';
        $this->assertEqual($unknown, getActionLabel($unknown));
    }

    public function testEmptyActionReturnsEmpty(): void
    {
        $this->assertEqual('', getActionLabel(''));
    }
}
