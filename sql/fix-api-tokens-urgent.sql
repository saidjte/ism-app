-- ============================================================
-- إصلاح إسعافي فوري لجدول api_tokens — v6.3.6
-- المشكلة: تسجيل دخول الهاتف يفشل برسالة
--   "جدول api_tokens يحتاج إصلاح — أعمدة مفقودة"
-- السبب: جدول الخادم القديم لا يحتوي العمود token_hash
--   الذي يحتاجه كود تسجيل الدخول والوسيط TokenAuthMiddleware
--
-- التشغيل: phpMyAdmin → اختيار قاعدة البيانات → تبويب SQL → لصق الملف → تنفيذ
-- أو: mysql -u USER -p inventory_db < fix-api-tokens-urgent.sql
--
-- ملاحظة: إذا ظهر خطأ "Duplicate column name" لسطر من الأسطر
--   الأولى فهذا يعني أن العمود موجود مسبقاً — تجاهل الخطأ وانتقل للسطر التالي.
--   كل عبارة مستقلة عن الأخرى.
-- ============================================================

-- 1) إضافة الأعمدة المفقودة (كل عبارة مستقلة — تجاهل خطأ Duplicate)
ALTER TABLE api_tokens ADD COLUMN token_hash VARCHAR(64) DEFAULT NULL;
ALTER TABLE api_tokens ADD COLUMN token_name VARCHAR(255) DEFAULT 'Mobile App';
ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(255) DEFAULT 'read,write';

-- 2) تعبئة token_hash للرموز القديمة — تبقى صالحة بدون إعادة تسجيل دخول
UPDATE api_tokens
   SET token_hash = SHA2(token, 256)
 WHERE token_hash IS NULL
   AND token IS NOT NULL
   AND token != '';

-- 3) الفهارس (تجاهل خطأ Duplicate key name إن وُجد)
ALTER TABLE api_tokens ADD INDEX idx_api_tokens_user (user_id);
ALTER TABLE api_tokens ADD INDEX idx_api_tokens_revoked (is_revoked);
ALTER TABLE api_tokens ADD INDEX idx_api_tokens_expires (expires_at);
ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_hash (token_hash);

-- 4) التحقق النهائي — يجب أن يعرض أعمدة token_hash و token_name و scopes
SHOW COLUMNS FROM api_tokens;
