# 🛠️ خطة إصلاح مشروع ISM POS — وثيقة رسمية

> **الإصدار الحالي للتطبيق:** v6.3.1 · **المنصة:** PHP 8.1+ / MySQL (PDO) · **الحجم:** 289 ملف PHP · ~158,390 سطر
> **تاريخ الإعداد:** نوفمبر 2025 · **المستودع:** `saidjte/ism-app` (خاص)

---

## 📋 ملخص تنفيذي

نظام نقاط بيع ومخزون ناضج وظيفياً، مبني بـ PHP إجرائي مع طبقة OOP مضافة لاحقاً. **الأساسيات الأمنية سليمة** (استعلامات مُحضّرة، CSRF، جلسات مقوّاة، CSP بـ nonce، تحقق من الملفات المرفوعة)، لكن المشروع يعاني من **4 فئات مشاكل رئيسية**:

| الفئة | الخطورة | العدد |
|---|---|---|
| 🔴 أسرار مكشوفة وأسطح هجوم مفتوحة | حرجة | 8 مواطن |
| 🟠 فوضى المستودع والملفات المكررة | عالية | 17 ملف + 3 أشجار نسخ + fork كامل |
| 🟠 ملفات عملاقة (God Files) بدون فصل طبقات | عالية | 10 ملفات > 100KB |
| 🟡 غياب CI/CD والاختبارات المحدودة | متوسطة | إطار اختبار منزلي بدون تكامل مستمر |

---

## 🔴 المرحلة 0 — إجراءات طارئة (نفّذ فوراً — خلال 48 ساعة)

### 0.1 تدوير جميع الأسرار المكشوفة ⚡ الأعلى أولوية
السجل القديم في Git (الفرع المحلي `main` قبل اللقطة النظيفة) يحتوي **بصيغة نص صريح**:
- `DB_PASS=938685` — كلمة مرور **root** رقمية من 6 خانات!
- `DEPLOYER_SECRET=change_me_to_a_strong_secret_2026`
- `APP_ENCRYPTION_KEY` (مفتاح تشفير `.env` نفسه)
- ملاحظة إضافية: رمز الـ GitHub المستخدم في هذه الجلسة ظهر في المحادثة — **أعد توليده من Settings → Developer settings** بعد انتهاء العمل.

**الخطوات:**
1. توليد كلمة مرور قوية لـ MySQL وإنشاء **مستخدم مخصص** بدلاً من root:
   ```sql
   CREATE USER 'ism_app'@'localhost' IDENTIFIED BY '<كلمة_مرور_قوية>';
   GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,LOCK TABLES ON inventory_db.* TO 'ism_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
2. تحديث `.env` وتشفير القيم: `php scripts/encrypt-env-helper.php`
3. توليد `DEPLOYER_SECRET` جديد (32+ حرفاً عشوائياً) وتشفيره.
4. تدوير `APP_ENCRYPTION_KEY` (يتطلب إعادة تشفير القيم المشفرة القديمة — راجع `includes/EnvEncryptor.php`).
5. مراجعة `logs/security.log`: سُجّلت محاولات وصول فاشلة إلى `deployer.php` من `192.168.1.17` — تحقق من مصدرها.
6. ⚠️ ملاحظة: اللقطة المرفوعة إلى GitHub **نظيفة** ولا تحتوي `.env`، لكن أي نسخ محلية قديمة تحتوي السجل الملوث — احذفها أو لا ترفعها أبداً.

### 0.2 إغلاق أسطح الهجوم غير المحمية 🔒
| الملف | المشكلة | الإجراء |
|---|---|---|
| `diag-all-mobile.php` | **بدون مصادقة** — يسمح بـ `shell_exec` و`copy()` و`file_put_contents` لأي زائر! (أسطر 70, 84, 108, 114, 151, 179) | **احذف فوراً** (المستودع يستبعده أصلاً) |
| `diag-index.php` | بدون مصادقة + `display_errors=1` يسرّب Stack traces | **احذف فوراً** |
| `install.php` | إذا فشل الاتصال بقاعدة البيانات يتجاوز فحص "مثبّت مسبقاً" (أسطر 26–29) ويكتب `DB_PASS` نصاً صريحاً (84–89) وينشئ `admin/admin123` | أضف حارساً: إن وُجد ملف `config/installed.lock` → `exit('Locked')` |
| `api/test-ultramsg.php` | `requirePost()` فقط — **لا جلسة ولا CSRF** | أضف `requireLogin()` + تحقق CSRF |
| `api/send-whatsapp-alert.php` | نفس المشكلة (12 سطراً بدون حماية) | أضف `requireLogin()` + تحقق CSRF |
| `api/pos-product-images.php` | يسرّب خريطة صور المنتجات بدون جلسة | أضف `requireLogin()` |
| `api/price-check-data.php` | عام عمداً (`Access-Control-Allow-Origin: *`) لكنه يُرجع **`purchase_price` و`quantity` و`min_quantity`** (أسطر 53–58) — كشف تكلفة الشراء والمخزون للعالم! | اقتصر الاستجابة على: الاسم، سعر البيع، الباركود فقط |
| `tests/run.php` | متاح عبر الويب ويشغّل مجموعة الاختبارات كاملة | أضف `tests/.htaccess` بـ `Require all denied` (الأصل متاح CLI فقط) |

### 0.3 إصلاح مفتاح cron المتوقع
`cron/whatsapp-daily.php:38` يستخدم:
```php
$expected_key = md5(DB_NAME . 'cron_wa_' . date('Y-m-d')); // قابل للتخمين!
```
**الإصلاح:** توليد سر عشوائي في `.env` (`WHATSAPP_CRON_KEY`) والمقارنة بـ `hash_equals()` — تماماً كما في `cron/auto-backup.php:37-48` (نموذج يُحتذى).

---

## 🟠 المرحلة 1 — نظافة المستودع (الأسبوع 1–2)

### 1.1 حذف الملفات القياسية والمكررة (17 ملفاً + 3 أشجار)
```bash
# نسخ قديمة متسربة (كلها منسحبة من التتبع بالفعل عبر .gitignore — احذفها من القرص):
rm pos.php.old caisse.php.old api-docs.php.bkp CaisseHelper.php.old
rm DashboardData0.php ApiResponse0.php
rm includes/upgrades/upgrade-constants{0.php,.88,-+-constants.php,--constants.php}
rm products/edit-product.php0003 products/add-product.php002
rm includes/Controllers/Mobile/MobileAuthController.php.backup.20260714_153941
rm -rf upgrade_file_backup/ upgrade_backups/ includes/upgrades/upgrade_backups/
```

### 1.2 قرار مصيري: مجلد `units/` (fork كامل قديم)
`units/` = **40 ملف PHP** تشكل نسخة منسوخة قديمة من التطبيق (login.php, caisse.php, install.php خاصة بها!) و`units/caisse.php` حتى يشغّل `CREATE TABLE` من كود الصفحة.
**التوصية:** انقله خارج webroot إلى أرشيف `legacy/` لمدة شهر، ثم احذفه. إن كانت وحدات القياس ميزة حية، فالكود الحي فيها موجود أصلاً في `measurement_units` و`additional_units`.

### 1.3 حذف الـ Controllers الميتة
جذر `Controllers/` (6 ملفات) **غير مُشار إليه إطلاقاً** — النسخ الحية في `includes/Controllers/`. احذفها.

### 1.4 توحيد عائلة مُحدّثات قاعدة البيانات (13 نسخة!)
- المصدر الوحيد الصحيح: `includes/upgrades/upgrade-constants.php` (306KB).
- استخرج بيانات `$DB_UPDATES` (سطر 799+) و`db_upgrade_*` (31 دالة) إلى بنية بيانات منفصلة `includes/upgrades/migrations/*.php` (ملف لكل إصدار).
- صدّر المخطط النهائي إلى **`sql/schema.sql`** من قاعدة حية (`mysqldump --no-data`) ليصبح المرجع الرسمي بدل 31 دالة ترحيل متتابعة.

### 1.5 توحيد جداول مكررة
`user_notification_prefs` و`user_notification_preferences` — جدولان لنفس الغرض. حدد الأحدث من الكود، اكتب سكربت ترحيل، وأسقط القديم.

---

## 🟡 المرحلة 2 — أتمتة الجودة (CI/CD) (الأسبوع 2–4)

### 2.1 GitHub Actions (`.github/workflows/ci.yml`)
```yaml
name: CI
on: [push, pull_request]
jobs:
  lint:        # php -l على كل ملف PHP
  tests:       # php tests/run.php
  static-analysis:  # PHPStan level 1 → ارفع تدريجياً
  secret-scan: # gitleaks — يمنع تكرار كارثة .env
```

### 2.2 ترحيل الاختبارات إلى PHPUnit
الإطار المنزلي في `tests/TestCase.php` جيد لكن معزول. 15 فئة اختبار موجودة (Csrf, Pagination, Cache, Database, ApiRouter, OrderByWhitelist...) — حوّلها إلى PHPUnit تدريجياً وأضف تغطية للـ Mobile API (27 controller بلا أي اختبار!).

### 2.3 حماية الفرع الرئيسي
Settings → Branches → Require PR + status checks. أضف `CODEOWNERS`.

---

## 🟠 المرحلة 3 — تفكيك الملفات العملاقة (الشهر 2–3)

| الملف | الحجم | خطة التقسيم |
|---|---|---|
| `pos.php` | 190KB / 5,547 سطر | استخراج CSS (أسطر 521–3479 ≈ 2,950 سطراً) → `assets/css/pages-pos.css`؛ JS (أسطر 3482–5544 ≈ 2,060 سطراً) → `assets/js/pages-pos.js`. يبقى PHP فقط (~50KB). *مكسب فوري: قابلية تخزين مؤقت + إزالة آلاف الأسطر من CSP nonce* |
| `api-docs.php` | 232KB | انقسما إلى `api-docs/index.php` + `assets/css/api-docs.css` + توليد المحتوى من JSON |
| `includes/upgrades/upgrade-constants.php` | 306KB | انظر 1.4 |
| `settings/system-settings.php` | 148KB | كل قسم إعدادات → ملف مستقل مع تبويب مشترك |
| `purchases/add-purchase.php` | 131KB | تطبيق نفس نمط POS: `PurchaseFormService` + JS مستقل |
| `products/edit-product.php` | 128KB | نفس النمط |
| `deployer.php` | 109KB | فصل الواجهة عن المنطق؛ فكّر في استبداله بـ GitHub Actions Deploy بعد استقرار CI |
| `index.php` | 56KB | انتقل إلى widgets مستقلة تستهلك `DashboardData` |

**قاعدة ذهبية أثناء التقسيم:** لا تخترق الوظيفة — كل ملف يُفكك في commit منفصل مع اختبار يدوي للصفحة.

---

## 🟡 المرحلة 4 — تحسين المعمارية (الشهر 3–6)

1. **Autoloader عبر Composer** (classmap أولاً لتجنب مشاكل NTFS المذكورة، ثم PSR-4 تدريجياً):
   ```json
   { "autoload": { "classmap": ["includes/", "includes/Controllers/", "includes/Services/"] } }
   ```
2. **إزالة الازدواجية الإجرائية/OOP:** الدوال `@deprecated` في `config/database.php` (10+) و`includes/auth.php` — استبدال استدعاءاتها بالفئات (`AppDatabase`, `AppAuth`) عبر بحث واستبدال منظم.
3. **مسارات includes مطلقة:** استبدال `require '../includes/header.php'` الهشّة بثابت `APP_ROOT`.
4. **نقل بيانات التشغيل خارج webroot:** `logs/`, `backups/`, `cache/` → مجلد شقيق للتطبيق (مثل `/var/ism-data/`) مع تعريف مسارات في config. (الـ `.htaccess` الحالي حماية جيدة لكن الدفاع في العمق أفضل).
5. **توحيد مكدسات PWA الثلاث** (main + pos + price-check): service worker مشترك مع استراتيجيات تخزين مؤقت موحدة.
6. **تفعيل راوتر `includes/Router/Router.php`** الموجود والمُعطّل — نقطة انطلاق طبيعية نحو روابط نظيفة بدل ملفات `.php` مباشرة.
7. **اختياري لاحقاً:** Redis فعلي للـ `RedisCache.php` الموجود مسبقاً (كاش الملفات الحالي كافٍ لغالبية الحالات).

---

## ✅ معايير القبول لكل مرحلة

| المرحلة | معيار الإنجاز |
|---|---|
| 0 | لا ملف diag/install متاح؛ كل endpoints تستجيب 401/403 بدون جلسة؛ `price-check-data` لا يُرجع تكلفة/كمية؛ سر cron عشوائي |
| 1 | `git ls-files` خالٍ من *.old/*.bkp/نسخ؛ `sql/schema.sql` موجود ومطابق؛ grep لا يجد `user_notification_preferences` |
| 2 | CI أخضر على كل push؛ gitleaks نشط؛ PHPUnit يعمل 15+ فئة |
| 3 | لا ملف PHP > 60KB؛ pos.php يُحمّل CSS/JS منفصلة مع كاش 304 |
| 4 | Composer autoload يعمل؛ صفر `require '../` في الكود؛ بيانات التشغيل خارج webroot |

---

## 🗓️ ملخص الجدول الزمني

```
أسبوع 1:  المرحلة 0 (طوارئ) + بدء 1 — ▓▓▓▓▓▓▓▓▓▓  حرِج
أسبوع 2:  إتمام المرحلة 1 + بدء CI — ▓▓▓▓▓▓
أسبوع 3–4: المرحلة 2 (CI/CD + PHPUnit) — ▓▓▓▓
شهر 2–3:  المرحلة 3 (تفكيك God Files) — ▓▓▓
شهر 3–6:  المرحلة 4 (معمارية) — ▓▓
```

## 📌 نقاط قوة يجب الحفاظ عليها
- ✅ صفر ثغرات SQL Injection مكتشفة (استعلامات مُحضّرة حقيقية `EMULATE_PREPARES=false`)
- ✅ `password_hash()` في كل مكان + قفل حساب ومعدل محاولات
- ✅ CSRF مركزي بـ `hash_equals` + جلسات مقوّاة + CSP بـ nonce ديناميكي
- ✅ تحقق مزدوج من الملفات المرفوعة (امتداد + finfo MIME)
- ✅ توكنات Mobile API مخزنة كـ SHA-256
- ✅ طبقة صلاحيات وaudit log موجودتان

---
*أُعدت هذه الوثيقة بناءً على تحليل تقني شامل للمشروع. راجع تاريخ المستودع لتتبع تنفيذ كل مرحلة.*
