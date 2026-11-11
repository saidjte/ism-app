# ISM POS — نظام نقاط البيع وإدارة المخزون

![Version](https://img.shields.io/badge/version-6.3.1-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple) ![License](https://img.shields.io/badge/license-Private-red)

نظام متكامل لنقاط البيع (POS) وإدارة المخزون مبني بـ PHP خام مع MySQL، يدعم ثلاث لغات (العربية، الإنجليزية، الفرنسية) بواجهة RTL كاملة.

## 🧩 الموديولات

| الموديول | المسار |
|---|---|
| نقطة البيع | `pos.php` · `caisse.php` |
| المنتجات | `products/` |
| المبيعات / المشتريات / المصاريف | `sales/` · `purchases/` · `expenses/` |
| التقارير | `reports/` (مبيعات، مشتريات، أرباح، مقارنة فترات) |
| API v1 (جلسات) | `api/index.php` → `includes/ApiRouter.php` |
| Mobile API (توكنات) | `api/mobile/index.php` → 27 متحكماً، 139 مساراً |
| الإشعارات | `notifications/` (SSE + Web Push + WhatsApp/UltraMsg) |
| المستخدمون والإعدادات | `users/` · `settings/` |
| النسخ الاحتياطي والترقية | `cron/auto-backup.php` · `upgrade.php` · `deployer.php` |

## 🚀 التشغيل

```bash
# 1) انسخ البيئة (بدون أسرار في Git!)
cp config/env.example .env   # ثم عدّل القيم وشغّل التشفير
php scripts/encrypt-env-helper.php

# 2) فعّل المخطط
mysql -u ism_app -p inventory_db < sql/schema.sql

# 3) شغّل الاختبارات
php tests/run.php
```

## 🔐 الأمان

- استعلامات مُحضّرة (Prepared Statements) في كل نقاط الوصول لقاعدة البيانات
- حماية CSRF مركزية (`includes/csrf.php`) + جداول معدل المحاولات
- كلمة مرور `password_hash()` دائماً — لا md5 للتخزين
- ترويسات أمنية صارمة: CSP بـ nonce ديناميكي، HSTS، X-Frame-Options
- تشفير `.env` بـ AES-256-GCM (`includes/EnvEncryptor.php`)

## 📁 بنية مهمة

```
includes/          المكتبة الأساسية (Database, Auth, CSRF, Services...)
includes/Controllers/   متحكمات API (جلسات + Mobile)
includes/Services/      منطق الأعمال (Product, Sale, Purchase)
sql/               مخطط قاعدة البيانات
tests/             اختبارات (15 فئة)
assets/            CSS/JS + مكتبات vendor
```

## 🛠️ خطة الإصلاح

راجع [REPAIR_PLAN.md](REPAIR_PLAN.md) — خطة مرحلية شاملة (طوارئ أمنية → نظافة مستودع → CI/CD → تفكيك الملفات الضخمة → معمارية).

## ⚠️ ملاحظات التشغيل

- لا ترفع أبداً: `.env` · `logs/` · `backups/` · `uploads/` · `cache/` (مغطاة بـ `.gitignore`)
- ملفات `*.old` / `*.bkp` مستبعدة من المستودع — تحديث دائم عبر `upgrade.php`
- بعد التثبيت الأول، قفل `install.php` فوراً
