-- ===== إنشاء جدول رموز API للموبايل =====
-- v4.34.0: إضافة طبقة API للهاتف
-- يُنفَّذ يدوياً من قبل المدير أو عبر نظام التحديث

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE COMMENT 'SHA-256 hash of the Bearer token',
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
    
    -- مفتاح أجنبي
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رموز المصادقة لتطبيقات الهاتف';
