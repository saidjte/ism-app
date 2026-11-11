-- ============================================================
--  ISM POS — المخطط الرسمي لقاعدة البيانات (sql/schema.sql)
-- ============================================================
--  المصدر: أحدث نسخة إنتاجية backups/files/backup_2026-11-10_195322.sql
--  (inventory_db @ v6.3.1) — مُصدَّر في إطار المرحلة 1 من خطة الإصلاح
--
--  هذا الملف هو المرجع البنيوي بدل 31 دالة ترحيل متتابعة:
--    - تثبيت جديد:  mysql -u ism_app -p inventory_db < sql/schema.sql
--                   ثم حدّث system_settings.db_version إلى APP_VERSION
--    - للتركيبات القائمة: استخدم upgrade.php (الترحيلات تدريجية)
--
--  الجداول: 35 | مستثنى عمداً: user_notification_preferences
--    (جدول مهمل لا يشير إليه أي كود — يُسقط عبر ترحيل db_upgrade_6_3_3)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- additional_units
DROP TABLE IF EXISTS `additional_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `additional_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `unit_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversion_factor` decimal(12,4) NOT NULL DEFAULT '1.0000',
  `selling_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `additional_units_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- api_tokens
DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `is_revoked` tinyint(1) DEFAULT '0',
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Mobile App',
  `scopes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'read,write',
  `expires_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  UNIQUE KEY `idx_api_tokens_hash` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`),
  KEY `idx_api_tokens_expires` (`expires_at`),
  KEY `idx_api_tokens_revoked` (`is_revoked`),
  KEY `idx_api_tokens_auth` (`token`,`is_revoked`,`expires_at`),
  CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- archive_notifications
DROP TABLE IF EXISTS `archive_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `archive_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'معرف المستخدم',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع الإشعار',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'العنوان',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الرسالة',
  `data` json DEFAULT NULL COMMENT 'بيانات إضافية',
  `title_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مفتاح ترجمة العنوان',
  `message_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مفتاح ترجمة الرسالة',
  `message_params` json DEFAULT NULL COMMENT 'معاملات الترجمة',
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal' COMMENT 'مستوى الأولوية',
  `group_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مفتاح التجميع',
  `group_count` int NOT NULL DEFAULT '1' COMMENT 'عدد الإشعارات المجمّعة',
  `original_created_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ إنشاء الإشعار الأصلي',
  `archived_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الأرشفة',
  PRIMARY KEY (`id`),
  KEY `idx_arch_user` (`user_id`),
  KEY `idx_arch_type` (`type`),
  KEY `idx_arch_date` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أرشيف الإشعارات القديمة — يُحفظ قبل الحذف لأغراض التدقيق';
/*!40101 SET character_set_client = @saved_cs_client */;

-- audit_log
DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'product, sale, purchase, expense, user, etc.',
  `entity_id` int DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- caisse
DROP TABLE IF EXISTS `caisse`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `caisse` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_date` date NOT NULL,
  `start_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `current_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `actual_amount` decimal(12,2) DEFAULT NULL,
  `note` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date` (`record_date`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- caisse_transactions
DROP TABLE IF EXISTS `caisse_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `caisse_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'المبلغ',
  `description` varchar(255) DEFAULT NULL COMMENT 'وصف الحركة',
  `transaction_date` date NOT NULL COMMENT 'تاريخ الحركة',
  `caisse_id` int DEFAULT NULL COMMENT 'معرف سجل الصندوق المرتبط',
  `sale_id` int DEFAULT NULL COMMENT 'معرف عملية البيع المرتبطة',
  `purchase_id` int DEFAULT NULL COMMENT 'معرف عملية الشراء المرتبطة',
  `expense_id` int DEFAULT NULL COMMENT 'معرف المصروف المرتبط',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tx_type_date` (`type`,`transaction_date`),
  KEY `idx_tx_date` (`transaction_date`),
  KEY `fk_ct_caisse` (`caisse_id`),
  KEY `fk_ct_sale` (`sale_id`),
  KEY `fk_ct_purchase` (`purchase_id`),
  KEY `fk_ct_expense` (`expense_id`),
  CONSTRAINT `fk_ct_caisse` FOREIGN KEY (`caisse_id`) REFERENCES `caisse` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- categories
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#3b82f6' COMMENT 'لون التصنيف بالهيكس',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT 'ترتيب العرض',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=نشط, 0=معطّل',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- expenses
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'تصنيف المصروف',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'وصف المصروف',
  `expense_date` date NOT NULL COMMENT 'تاريخ المصروف',
  `created_by` int DEFAULT NULL COMMENT 'المستخدم الذي أضاف المصروف',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_category_date` (`category`,`expense_date`),
  KEY `idx_expenses_date_amount` (`expense_date`,`amount`),
  KEY `idx_expenses_date_category` (`expense_date`,`category`,`amount`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- held_carts
DROP TABLE IF EXISTS `held_carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `held_carts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `cart_id` int NOT NULL,
  `cart_data` json NOT NULL,
  `item_count` int DEFAULT '0',
  `total` decimal(12,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_held_cart` (`user_id`,`cart_id`),
  KEY `idx_held_carts_user` (`user_id`),
  CONSTRAINT `held_carts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- inventory_counts
DROP TABLE IF EXISTS `inventory_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_counts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `actual_quantity` decimal(12,2) NOT NULL,
  `system_quantity` decimal(12,2) NOT NULL,
  `difference` decimal(12,2) NOT NULL,
  `count_date` date NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_count_date` (`count_date`),
  KEY `idx_count_product_date` (`product_id`,`count_date`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `inventory_counts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- ip_rate_limits
DROP TABLE IF EXISTS `ip_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL COMMENT 'عنوان IP (يدعم IPv6)',
  `attempts` int NOT NULL DEFAULT '0' COMMENT 'عدد المحاولات الفاشلة',
  `last_attempt` datetime DEFAULT NULL COMMENT 'آخر محاولة',
  `blocked_until` int DEFAULT NULL COMMENT 'طابع زمني لحظة انتهاء الحظر',
  `username` varchar(50) DEFAULT NULL COMMENT 'اسم المستخدم المراد تسجيل الدخول به',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ip` (`ip`),
  KEY `idx_blocked` (`blocked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- measurement_units
DROP TABLE IF EXISTS `measurement_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `measurement_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'اسم الوحدة (قطعة، كيلوغرام...)',
  `abbreviation` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الرمز المختصر (قط، كلغ...)',
  `unit_type` enum('quantity','weight','volume','length','area','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quantity' COMMENT 'نوع الوحدة',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وصف إضافي',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT 'ترتيب العرض',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=نشط, 0=معطّل',
  `conversion_to_base` decimal(12,6) NOT NULL DEFAULT '1.000000' COMMENT 'معامل التحويل للوحدة الأساسية (كم وحدة أساسية = 1 من هذه الوحدة)',
  `is_base_unit` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'هل هذه هي الوحدة الأساسية لهذا النوع؟',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- notification_analytics
DROP TABLE IF EXISTS `notification_analytics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_analytics` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `notification_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_notif_user` (`notification_id`,`user_id`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- notifications
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'المستخدم المستلم',
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع الإشعار (sale, purchase, stock...)',
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal' COMMENT 'مستوى الأولوية: urgent/normal/info',
  `group_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مفتاح التجميع — الإشعارات بنفس المفتاح تُدمج',
  `group_count` int NOT NULL DEFAULT '1' COMMENT 'عدد الإشعارات المجمّعة',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عنوان الإشعار',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نص الإشعار',
  `data` json DEFAULT NULL COMMENT 'بيانات إضافية بصيغة JSON',
  `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=غير مقروء، 1=مقروء',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `title_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_params` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  KEY `idx_notif_user_created` (`user_id`,`created_at`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_title_key` (`title_key`),
  KEY `idx_notif_user_read_date` (`user_id`,`is_read`,`created_at`),
  KEY `idx_notif_group` (`user_id`,`group_key`,`is_read`),
  KEY `idx_notif_priority` (`priority`,`created_at`),
  FULLTEXT KEY `ft_notif_search` (`title`,`message`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- price_history
DROP TABLE IF EXISTS `price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `old_purchase_price` decimal(12,2) DEFAULT NULL,
  `new_purchase_price` decimal(12,2) DEFAULT NULL,
  `old_selling_price` decimal(12,2) DEFAULT NULL,
  `new_selling_price` decimal(12,2) DEFAULT NULL,
  `change_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `price_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- product_images
DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `original_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medium_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int unsigned DEFAULT '0',
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `bg_removed` tinyint(1) DEFAULT '0',
  `sort_order` int DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_is_primary` (`is_primary`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- products
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en_fr` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchase_unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وحدة القياس الأساسية للشراء',
  `purchase_unit_factor` decimal(12,4) NOT NULL DEFAULT '1.0000' COMMENT '1 وحدة شراء = X وحدة بيع أساسية',
  `category_id` int DEFAULT NULL COMMENT 'معرف التصنيف',
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `min_quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `has_expiry` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=يتطلب تاريخ انتهاء, 0=لا',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_expiry` (`has_expiry`),
  KEY `idx_products_stock_status` (`quantity`,`min_quantity`),
  FULLTEXT KEY `idx_ft_products_search` (`name`,`code`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- purchase_additional_units
DROP TABLE IF EXISTS `purchase_additional_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_additional_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `unit_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversion_factor` decimal(12,4) NOT NULL DEFAULT '1.0000' COMMENT '1 وحدة إضافية شراء = X وحدة شراء أساسية',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `purchase_additional_units_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- purchase_batches
DROP TABLE IF EXISTS `purchase_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_batches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `purchase_id` int DEFAULT NULL COMMENT 'رابط بسجل الشراء الأصلي',
  `original_quantity` decimal(12,2) NOT NULL COMMENT 'الكمية الأصلية للدفعة',
  `remaining_quantity` decimal(12,2) NOT NULL COMMENT 'الكمية المتبقية غير المباعة',
  `purchase_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `actual_purchase_price` decimal(12,6) DEFAULT NULL COMMENT 'التكلفة الحقيقية بالوحدة (بعد توزيع الخصم والضريبة)',
  `purchase_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL COMMENT 'تاريخ انتهاء الصلاحية',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_batch_date` (`purchase_date`),
  KEY `idx_batch_product_date` (`product_id`,`purchase_date`),
  KEY `idx_batches_product_remaining` (`product_id`,`remaining_quantity`,`purchase_price`),
  CONSTRAINT `purchase_batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- purchase_orders
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int DEFAULT NULL COMMENT 'رقم المورد',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'الإجمالي قبل الخصم والضريبة',
  `financial_discount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'إجمالي التخفيضات المالية',
  `net_total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'الصافي بعد الخصم',
  `tax_rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT 'نسبة الضريبة',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'مبلغ الضريبة',
  `tax_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'اسم الضريبة',
  `grand_total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'الإجمالي النهائي (صافي + ضريبة)',
  `purchase_date` date NOT NULL COMMENT 'تاريخ الشراء',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'ملاحظات',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_date` (`purchase_date`),
  KEY `idx_order_created` (`created_at`),
  KEY `idx_orders_supplier` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فواتير الشراء';
/*!40101 SET character_set_client = @saved_cs_client */;

-- purchase_remises
DROP TABLE IF EXISTS `purchase_remises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_remises` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `order_id` int DEFAULT NULL COMMENT 'رقم فاتورة الشراء',
  `type` varchar(20) NOT NULL,
  `product_id` int DEFAULT NULL COMMENT 'المنتج المعطى كتخفيض مادي',
  `quantity` decimal(12,2) DEFAULT NULL COMMENT 'كمية التخفيض المادي',
  `unit_price` decimal(12,2) DEFAULT NULL COMMENT 'سعر الشراء للمنتج المعطى (لتحديد قيمة المخزون)',
  `amount` decimal(12,2) DEFAULT NULL COMMENT 'مبلغ التخفيض المالي',
  `description` varchar(255) DEFAULT NULL COMMENT 'وصف التخفيض',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `idx_remises_order` (`order_id`),
  CONSTRAINT `purchase_remises_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- purchases
DROP TABLE IF EXISTS `purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int DEFAULT NULL COMMENT 'رقم المورد',
  `order_id` int DEFAULT NULL COMMENT 'رقم فاتورة الشراء',
  `product_id` int NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `purchase_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT 'نسبة الضريبة',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'مبلغ الضريبة',
  `tax_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'اسم الضريبة',
  `grand_total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'الإجمالي النهائي (صافي بعد الخصم + الضريبة)',
  `purchase_date` date NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_date` (`purchase_date`),
  KEY `idx_product_date` (`product_id`,`purchase_date`),
  KEY `idx_purchases_date_tax` (`purchase_date`,`tax_amount`),
  KEY `idx_purchases_date_product` (`purchase_date`,`product_id`,`total`),
  KEY `idx_purchases_order` (`order_id`),
  KEY `idx_purchases_supplier` (`supplier_id`),
  CONSTRAINT `fk_purchases_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- push_subscriptions
DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `endpoint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `p256dh` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `auth_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `browser` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_user_endpoint` (`user_id`,`endpoint`(255)),
  KEY `idx_push_user` (`user_id`),
  KEY `idx_push_endpoint` (`endpoint`(255)),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- remise_types
DROP TABLE IF EXISTS `remise_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `remise_types` (
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ar` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_fr` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع التخفيضات (بديل ENUM)';
/*!40101 SET character_set_client = @saved_cs_client */;

-- returns
DROP TABLE IF EXISTS `returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `returns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `original_sale_id` int NOT NULL COMMENT 'معرّف الصنف في جدول sales',
  `product_id` int NOT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL COMMENT 'سعر البيع الأصلي',
  `refund_total` decimal(12,2) NOT NULL COMMENT 'المبلغ المُرتجع',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_original_sale` (`original_sale_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- sale_batch_items
DROP TABLE IF EXISTS `sale_batch_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_batch_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL COMMENT 'رابط بسجل البيع',
  `batch_id` int NOT NULL COMMENT 'رابط بدفعة الشراء',
  `quantity_taken` decimal(12,2) NOT NULL COMMENT 'الكمية المأخوذة من هذه الدفعة',
  `purchase_price` decimal(12,2) NOT NULL COMMENT 'سعر شراء الوحدة من هذه الدفعة',
  `cost_total` decimal(12,2) NOT NULL COMMENT 'تكلفة الكمية المأخوذة = quantity_taken × purchase_price',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `idx_sbi_sale_batch` (`sale_id`,`batch_id`),
  CONSTRAINT `sale_batch_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_batch_items_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `purchase_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- sales
DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `purchase_price_at_sale` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cost_total` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'تكلفة البضاعة المباعة',
  `profit` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'ربح العملية',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'مبلغ الضريبة على الصنف',
  `sale_date` date NOT NULL,
  `receipt_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sales_receipt_no` (`receipt_no`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_product_date` (`product_id`,`sale_date`),
  KEY `idx_sales_date_profit` (`sale_date`,`product_id`,`total`,`cost_total`,`profit`),
  KEY `idx_sales_tax` (`sale_date`,`tax_amount`),
  CONSTRAINT `fk_sales_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- suppliers
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'اسم المورد',
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'الهاتف',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'البريد الإلكتروني',
  `address` text COLLATE utf8mb4_unicode_ci COMMENT 'العنوان',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'ملاحظات',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموردين';
/*!40101 SET character_set_client = @saved_cs_client */;

-- system_settings
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- transaction_types
DROP TABLE IF EXISTS `transaction_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_types` (
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_ar` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_fr` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع حركات الصندوق (بديل ENUM)';
/*!40101 SET character_set_client = @saved_cs_client */;

-- user_notification_prefs
DROP TABLE IF EXISTS `user_notification_prefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_notification_prefs` (
  `user_id` int NOT NULL,
  `prefs` json NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- user_settings
DROP TABLE IF EXISTS `user_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_setting` (`user_id`,`setting_key`),
  KEY `idx_user_settings_user` (`user_id`),
  CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- user_tokens
DROP TABLE IF EXISTS `user_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `selector` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_selector` (`selector`),
  KEY `idx_expires` (`expires_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- users
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','manager','user') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT 'admin=مدير النظام, manager=مدير, user=مستخدم عادي',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=نشط, 0=معطّل',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=يجب تغيير كلمة المرور عند تسجيل الدخول',
  `password_changed_at` datetime DEFAULT NULL COMMENT 'تاريخ آخر تغيير لكلمة المرور',
  `failed_attempts` int NOT NULL DEFAULT '0' COMMENT 'عدد محاولات تسجيل الدخول الفاشلة',
  `last_failed_attempt` datetime DEFAULT NULL COMMENT 'آخر محاولة فاشلة',
  `last_login` datetime DEFAULT NULL COMMENT 'آخر تسجيل دخول ناجح',
  `login_count` int NOT NULL DEFAULT '0' COMMENT 'عدد مرات تسجيل الدخول',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- whatsapp_logs
DROP TABLE IF EXISTS `whatsapp_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `msg_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'expiry_alert',
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_text` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT '0',
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `next_retry_at` timestamp NULL DEFAULT NULL,
  `response` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_logs_status` (`status`),
  KEY `idx_wa_logs_retry` (`status`,`next_retry_at`),
  KEY `idx_wa_logs_hash` (`message_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET FOREIGN_KEY_CHECKS = 1;
