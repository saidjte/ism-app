-- ===== إنشاء جدول رموز API للموبايل =====
-- v4.34.0: إضافة طبقة API للهاتف
-- v6.3.6: إضافة token_hash/token_name/scopes (مزامنة مع sql/schema.sql)
--         + فهرس UNIQUE على token_hash (يستخدمه TokenAuthMiddleware)
-- يُنفَّذ يدوياً من قبل المدير أو عبر نظام التحديث

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE COMMENT 'النص الخام للرمز (توافق قديم)',
    token_hash VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash للرمز — يستخدمه TokenAuthMiddleware',
    token_name VARCHAR(255) DEFAULT 'Mobile App' COMMENT 'اسم الرمز',
    scopes VARCHAR(255) DEFAULT 'read,write' COMMENT 'صلاحيات الرمز',
    device_name VARCHAR(255) DEFAULT '' COMMENT 'اسم الجهاز أو التطبيق',
    is_revoked TINYINT(1) DEFAULT 0 COMMENT 'هل تم إبطال الرمز؟',
    expires_at DATETIME DEFAULT NULL COMMENT 'تاريخ انتهاء الصلاحية',
    last_used_at DATETIME DEFAULT NULL COMMENT 'آخر استخدام للرمز',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- فهارس
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_token (token),
    INDEX idx_api_tokens_revoked (is_revoked),
    INDEX idx_api_tokens_expires (expires_at),
    UNIQUE INDEX idx_api_tokens_hash (token_hash),

    -- مفتاح أجنبي
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رموز المصادقة لتطبيقات الهاتف';

-- ===== ترقية جدول موجود (نفّذ الأسطر التالية فقط إذا كان الجدول قديماً) =====
-- إذا فشل سطر بـ "Duplicate column name" فهذا يعني أن العمود موجود — تجاهل الخطأ
-- ALTER TABLE api_tokens ADD COLUMN token_hash VARCHAR(64) DEFAULT NULL;
-- ALTER TABLE api_tokens ADD COLUMN token_name VARCHAR(255) DEFAULT 'Mobile App';
-- ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(255) DEFAULT 'read,write';
-- ALTER TABLE api_tokens ADD UNIQUE INDEX idx_api_tokens_hash (token_hash);
-- UPDATE api_tokens SET token_hash = SHA2(token, 256) WHERE token_hash IS NULL AND token IS NOT NULL AND token != '';
