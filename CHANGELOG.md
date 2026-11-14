# سجل التغييرات (CHANGELOG)

يتبع هذا الملف اتفاقية [Semantic Versioning](https://semver.org/lang/ar/) للترقيم.

---

## [6.3.6] - 2026-11-14

### إصلاح حرج: فشل تسجيل دخول الهاتف — "جدول api_tokens يحتاج إصلاح — أعمدة مفقودة"

**الجذر:** كود تسجيل الدخول (`MobileAuthController`) يُدرج عمود `token_hash` لكن جدول الخادم القديم لا يحتويه. الأسوأ: **جميع أدوات الإصلاح الثلاث لا تعرف هذا العمود** — فكان الإصلاح لا يحدث أبداً مهما أعد المستخدم المحاولة.

| الموضع | المشكلة | الإصلاح |
|---|---|---|
| `api/mobile/index.php` (الإصلاح التلقائي) | قائمة الأعمدة بدون `token_hash/token_name/scopes` | أضيفت الأعمدة + تعبئة `token_hash` للصفوف القديمة + لاحقة `v2` لملف العلامة اليومية لإجبار التشغيل الفوري بعد رفع الكود |
| `create-api-table.php` | نفس القائمة الناقصة في `$required_columns` و`ALTER` و`CREATE` | مزامنة كاملة + تعبئة `token_hash` |
| `api-test.php` | نفس القائمة الناقصة | مزامنة |
| `settings/api-tokens.php` | كان يخزّن الهاش في `token` بدون `token_hash` — رموز الواجهة لا تعمل مع الوسيط | توحيد: `token` = نص خام + `token_hash` = SHA-256 |
| `MobileApiTokenController` | كان يُغفل عمود `token` (NOT NULL) — فشل INSERT | أضيف |
| نظام الترقيات | لا هجرة للجدول | هجرة رسمية `db_upgrade_6_3_6()` (idempotent) |
| `sql/api_tokens.sql` | متخلف عن `sql/schema.sql` | مزامنة + قسم ترقية جدول قديم |
| سكربت إسعافي | — | `sql/fix-api-tokens-urgent.sql` — إصلاح فوري من phpMyAdmin بدون نشر كود |

**أثر على الرموز القديمة:** رموز الهاتف المُصدرة سابقاً (مخزّنة نصاً في `token`) تبقى صالحة — تُعبَّأ `token_hash` تلقائياً بـ`SHA2(token, 256)`.

---

## [6.3.5] - 2025-11-12

### تفكيك الملفات العملاقة — المرحلة 3 من خطة الإصلاح

فصل الأنماط والمنطق التفاعلي عن ملفات PHP العملاقة إلى أصول خارجية قابلة للتخزين المؤقت، كل ملف في commit مستقل دون أي تغيير وظيفي.

| الملف | قبل | بعد | الاستخراج |
|---|---|---|---|
| `pos.php` | 182KB | **39KB** | `assets/css/pages-pos.css` (63KB) + `assets/js/pages-pos.js` (84KB) + جسر مضمّن (16 ثابتاً + `window.PTXT` بـ64 نصاً مترجماً) |
| `api-docs.php` | 215KB | **6KB** | متحكم نظيف + `templates/api-docs/sections/` (29 قسماً) + CSS/JS خارجيان |
| `add-purchase.php` | 126KB | **46KB** | `assets/css/pages-add-purchase.css` + `assets/js/pages-add-purchase.js` + جسر `CURRENCY_SYMBOL` |
| `edit-product.php` | 122KB | **65KB** | `assets/css/pages-edit-product.css` + JS للوحدات/الأسعار + JS لنظام الصور + جسرا `var` |
| `deployer.php` | 96KB | **75KB** | `assets/css/pages-deployer.css` + `assets/js/pages-deployer.js` (الباقي منطق نشر PHP صرف) |
| `settings/system-settings.php` | 149KB | **133KB** | `assets/js/pages-system-settings.js` (دمج 3 كتل JS — الباقي نماذج إعدادات) |

مكاسب عرضية:
- صفحات POS وapi-docs أصبحت خالية من آلاف الأسطر داخل CSP nonce — الأنماط والسكربتات تُخدَّم بـ304 من الكاش.
- إصلاح `>` شاردة كانت تُطبع أعلى واجهة POS.
- سكربت api-docs المضمّن (بلا nonce) أصبح خارجياً — متوافق مع CSP الصارم.

منهجية التحقق (بدون PHP CLI في بيئة التطوير): فاحص بنيوي آلة-حالات لكل ملف معدّل، `node --check` للـJS الخارجي والجسور المصيَّرة، مطابقة 64/64 مفتاح PTXT، إعادة بناء بايت-ببايت لمحتوى api-docs (200,735 بايت مطابقة)، وصفر معرفات POS/THERMAL معلقة.

مؤجل موثق (يتطلب اختباراً تشغيلياً): التقسيم الهيكلي لأقسام `settings/system-settings.php` و`deployer.php` وفكّ `includes/upgrades/upgrade-constants.php` (مع المرحلة 4).

---

## [6.3.4] - 2025-11-12

### أتمتة الجودة — المرحلة 2 من خطة الإصلاح (CI/CD + PHPUnit)

أربع بوابات جودة تعمل على كل push و PR عبر GitHub Actions، وترحيل كامل للاختبارات إلى PHPUnit. التفاصيل في REPAIR_PLAN.md.

#### CI/CD (`.github/workflows/ci.yml`)

| البوابة | الوظيفة |
|---|---|
| **PHP Syntax Lint** | `php -l` لكل ملف PHP متتبع — كشف فوراً خلل صياغة كان موجوداً في `cron/cleanup-notifications.php` |
| **PHPUnit (PHP 8.1 + 8.3)** | 404 اختباراً / 798 تأكيداً على مصفوفتي PHP (SQLite في الذاكرة — بلا خادم MySQL) |
| **PHPStan (level 1)** | تحليل ساكن للكود كله؛ أخطاء الكود القديم (410) مسجلة في `phpstan-baseline.neon` وأي خطأ **جديد** يُفشل الـ CI |
| **Gitleaks** | فحص أسرار كامل تاريخ المستودع مع قائمة سماح موثقة `.gitleaks.toml` — يمنع تكرار كارثة `.env` |

إضافات مرافقة: `.github/dependabot.yml` (تحديثات أسبوعية لـ composer وactions)، `.github/PULL_REQUEST_TEMPLATE.md`، وإدخال `vendor/` في `.gitignore` مع تثبيت `composer.lock`.

#### ترحيل الاختبارات إلى PHPUnit

| # | التغيير |
|---|---|
| 1 | `composer.json` + `composer.lock` — PHPUnit 10.5 (متوافق مع PHP ≥ 8.1) وPHPStan 2.x كتبعيات تطوير |
| 2 | `phpunit.xml.dist` — تكوين الرسمي (bootstrap + مجموعة اختبارات مجلد `tests/`) |
| 3 | `tests/bootstrap.php` — نفس تهيئة المشغّل القديم (أصناف النظام + دوال مساعدة + i18n + إعدادات الجلسة) |
| 4 | `tests/TestCase.php` — طبقة توافق: أصناف الاختبار ترث `PHPUnit\Framework\TestCase` مع أسماء بديلة للتأكيدات القديمة (`assertEqual`, `assertStringContains`, `assertMatchesRegex`, `assertFloatEqual`, `assertThrows`...) |
| 5 | `tests/run.php` — غلاف خفيف يفوّض إلى PHPUnit (سطر أوامر فقط — أُزيل وضع الويب لأسباب أمنية) |
| 6 | `tests/SessionConfigTest.php` — أُعيدت كتابته على نمط PHPUnit (كان بلا تأكيدات فعلية ولم يشغّله المشغّل القديم قط) |
| 7 | `tests/OrderByWhitelistTest.php` — أُعيدت كتابته لتطابق واجهة `buildOrderBy()` الحالية (كان يختبر واجهة سياقات غير موجودة) |
| 8 | PHPUnit يكتشف **15** فئة اختبار الآن (المشغّل القديم كان يشغّل 13 فقط) |

#### إصلاحات كشفها تفعيل الاختبارات المتروكة

| الملف | المشكلة | الإصلاح |
|---|---|---|
| `cron/cleanup-notifications.php` | **Parse error** — قوس زائد من المرحلة 1 جعل الملف غير قابل للتنفيذ | حذف القوس الزائد |
| `includes/auth.php` | نسخة محلية مكررة من منطق مهلة الجلسة بدل الدالة المركزية | استخدام `getSessionTimeout()` من `config/session.php` |
| `includes/Router/Middleware/AuthMiddleware.php` | قيمة مشفرة `1800` (مهلة 30 دقيقة) تتجاهل إعداد `session_timeout_minutes` | استخدام `getSessionTimeout()` مع مرجع احتياطي |
| `cron/whatsapp-daily.php` | `ini_set` يدوي لإعدادات كوكيز الجلسة خارج التكوين المركزي | استخدام `startSecureSession()` المركزية |
| `tests/PurchaseServiceTest.php` | مخطط SQLite للاختبار أقدم من الخدمة (أعمدة `order_id`, `grand_total`, `actual_purchase_price` مفقودة) — 37 اختباراً كانت تفشل **قبل** هذا الإصدار أيضاً | تحديث المخطط؛ المجموعة كلها خضراء الآن |
| `tests/ProductServiceTest.php` وغيرها | `public setUp()/tearDown()` | `protected` وفق متطلبات PHPUnit |

#### ملاحظات الترقية

1. لا ترحيل قاعدة بيانات في هذا الإصدار (لا يوجد `db_upgrade_6_3_4`).
2. على الخادم المحلي شغّل `composer install` أولاً ثم `vendor/bin/phpunit` لتشغيل الاختبارات.
3. حماية الفرع الرئيسي: فعّل "Require status checks" من Settings → Branches واختر الفحوصات الخمسة (راجع REPAIR_PLAN.md — المرحلة 2.3).

---

## [6.3.3] - 2025-11-11

### تنظيف — المرحلة 1 من خطة الإصلاح (نظافة المستودع)

حذف ~50 ملفاً ميتاً/مكرراً وتصدير المخطط الرسمي. التفاصيل في REPAIR_PLAN.md.

#### المحذوف (بعد تحقق مرجعي شامل — كل الحذف آمن)

| العنصر | الحجم | السبب |
|---|---|---|
| مجلد `units/` (39 من 41 ملفاً) | fork كامل منسحب | الصفحة الحية الوحيدة `units/list-units.php` تستخدم بنية التطبيق الرئيسية (`../includes`) — احُتفظ بها مع `.htaccess`، وحُذفت بنية fork المكررة (login/caisse/config/includes/api ومنتجات ومبيعات...) |
| جذر `Controllers/` (6 ملفات) | كود ميت | لا مرجع واحد له — النسخ الحية في `includes/Controllers/` |
| جذر `upgrades/` (ملفان ~550KB) | نسخ مكررة منسحبة | الدوال الحية في `includes/upgrades/` — لا مرجع للمجلد الجذري |
| `tests/upgrade-constants.php` | 232KB نسخة طائشة | ليست اختباراً ولا يحمّلها run.php |
| `products/add-product.php002` | نسخة قياسية | تسربت من أنماط .gitignore |

#### المضاف/المعدل

| # | التغيير |
|---|---|
| 1 | **`sql/schema.sql`** — المخطط الرسمي (35 جدولاً) مُصدَّر من أحدث نسخة إنتاجية (2026-11-10)، مستثنى منه الجدول الميت — يصبح المرجع البنيوي بدل 31 دالة ترحيل |
| 2 | **ترحيل `db_upgrade_6_3_3()`** — إسقاط جدول `user_notification_preferences` المهمل (الحي `user_notification_prefs` منذ v6.2.1، القديم بلا مراجع وفارغ إنتاجياً؛ مع بيانات → أرشفة بإعادة تسمية بدل الإسقاط) |
| 3 | `APP_VERSION` ← **6.3.3** + إدخال `$DB_UPDATES` |
| 4 | تنظيف مراجع الملفات المحذوفة من `config/session.php` و`tests/SessionConfigTest.php` |
| 5 | `.gitignore` أضيفت أنماط اللواحق الرقمية المتبقية (مثل `*00[0-9]`) لمنع تكرار تسرب مثل `add-product.php002` |

#### ملاحظات الترقية

1. شغّل `upgrade.php` مرة واحدة — سيُطبق `db_upgrade_6_3_3` ويرفع إصدار القاعدة إلى 6.3.3.
2. إذا كانت لديك إشارة مرجعية قديمة لأي صفحة داخل `units/` غير `list-units.php` فهي كانت جزءاً من fork منسوخاً — استخدم الصفحات الرئيسية المقابلة.

---

## [6.3.2] - 2025-11-11

### أمان — المرحلة 0 من خطة الإصلاح (إجراءات طارئة)

إغلاق الثغرات الأمنية الحرجة المكتشفة في التحليل الشامل (راجع REPAIR_PLAN.md).

#### الإصلاحات

| # | الملف | الإصلاح |
|---|---|---|
| 1 | `install.php` | قفل تثبيت (`config/installed.lock`) + تحويل فشل التحقق من fail-open إلى **fail-closed** + فحص CSRF على POST + تشفير `DB_PASS` بـ AES-256-GCM عند الكتابة + كلمة مرور admin عشوائية تُعرض مرة واحدة بدل `admin123` |
| 2 | `api/test-ultramsg.php` | إضافة مصادقة جلسة + فحص CSRF (كان متاحاً لأي زائر) |
| 3 | `api/send-whatsapp-alert.php` | إضافة مصادقة جلسة + فحص CSRF (كان متاحاً لأي زائر) |
| 4 | `api/pos-product-images.php` | إضافة فحص الجلسة — استجابة 401 JSON (كانت تُكشف لأي زائر) |
| 5 | `api/price-check-data.php` | **حذف سعر الشراء والكمية الدقيقة وحد التنبيه من الاستجابة العامة** واستبدالها بحالة مخزون مبسطة (`stock: ok\|low\|out`) |
| 6 | `price-check.php` | تحديث الواجهة لاستهلاك حالة المخزون الجديدة من الخادم |
| 7 | `cron/whatsapp-daily.php` | استبدال المفتاح اليومي القابل للحساب `md5(DB_NAME.'cron_wa_'.date('Y-m-d'))` بسر ثابت من الإعدادات (`cron_secret_key`) مع مقارنة `hash_equals` |
| 8 | `.htaccess` | حجب `diag-*.php` وأي `*.lock` عن الويب كخط دفاع أخير |
| 9 | `tests/.htaccess` | جديد — منع تشغيل مجموعة الاختبارات من المتصفح |
| 10 | `scripts/security-cleanup.sh` | جديد — تنظيف الخادم من ملفات التشخيص والنسخ القياسية + قفل التثبيت + صلاحيات .env |
| 11 | `scripts/rotate-secrets.php` | جديد — تدوير كامل للأسرار (DB_PASS / DEPLOYER_SECRET / APP_ENCRYPTION_KEY) مع إعادة تشفير .env وطباعة SQL إنشاء مستخدم `ism_app` |
| 12 | `config/env.example` | جديد — قالب موثق لملف البيئة بدون أسرار |
| 13 | حذف | `api/manage-batches.php003` (ملف نسخة قياسية تسرب للمستودع) |

#### ملاحظات الترقية

1. **مطلوب إجراء يدوي فوري بعد النشر:**
   ```bash
   bash scripts/security-cleanup.sh --dry-run   # معاينة
   bash scripts/security-cleanup.sh             # تنفيذ
   php scripts/rotate-secrets.php --dry-run     # معاينة تدوير الأسرار
   php scripts/rotate-secrets.php               # تدوير + طباعة SQL — نفّذ SQL فوراً
   ```
2. **`cron/whatsapp-daily.php` لم يعد يقبل المفتاح اليومي القديم** — عيّن "مفتاح أمان cron" من صفحة الإعدادات وحدّث رابط مجدول المهام.
3. اللقطة الأولى في المستودع نظيفة من الأسرار، لكن **السجل المحلي القديم يحتوي أسراراً** — التدوير إلزامي (البند 1).

---

## [4.21.0] - 2026-05-19

### ميزة جديدة — إضافة 343 مفتاح ترجمة مفقود إلى ملفات اللغات الثلاث

هذا الإصدار يُضيف 343 مفتاح ترجمة كانت مستخدمة في الكود عبر دالة `t()` لكنها لم تكن موجودة في ملفات اللغات الثلاث (ar.php، en.php، fr.php). تمت إضافة الترجمات المناسبة لكل لغة مع تنظيم المفاتيح حسب المجموعة الوظيفية.

#### المشكلة
كانت هناك 343 مفتاح ترجمة مستخدمة في الكود عبر `t('key_name')` لكنها غير موجودة في ملفات اللغات، مما يعني أن هذه النصوص كانت تُعرض كمفاتيح إنجليزية بدلاً من نصوص مترجمة عند استخدام اللغة العربية أو الفرنسية.

#### المفاتيح المضافة حسب المجموعة

| المجموعة | عدد المفاتيح | أمثلة |
|---|---|---|
| مشرف / Admin | 17 | admin_action_type, admin_browser, admin_clear_filters |
| تدقيق / Audit | 18 | audit_action_login, audit_action_product_add |
| مصادقة / Auth | 11 | auth_account_locked, auth_invalid_credentials |
| صندوق / Caisse | 37 | caisse_actual_count, caisse_deposit, caisse_withdrawal |
| تصنيفات / Categories | 11 | categories_active_categories, categories_name_required |
| مشترك / Common | 25 | common_csrf_expired, common_error_occurred |
| لوحة تحكم / Dashboard | 1 | dashboard_home_title |
| مصاريف / Expenses | 9 | expenses_category_required, expenses_error_saving |
| صلاحية منبثق / Expiry Popup | 8 | expiry_popup_urgent_title, expiry_popup_got_it |
| تصدير / Export | 1 | export_invalid_type |
| ملصقات / Labels | 12 | labels_a4_pages, labels_save_layout |
| أمان تسجيل الدخول | 1 | login_security_error |
| أشهر / Months | 12 | month_january, month_december |
| تنقل / Navigation | 1 | nav_no_alerts |
| وقت الإشعار / Notif Time | 5 | notif_time_ago_day, notif_time_ago_year |
| أنواع الإشعارات / Notif Types | 9 | notif_type_backup, notif_type_sale |
| إشعارات / Notifications | 4 | notifications_load_more, notifications_unread |
| نقطة بيع / POS | 4 | pos_loading_products, pos_search_placeholder |
| سجل أسعار / Price History | 1 | pricehistory_title |
| منتجات / Products | 16 | products_add_new_product, products_error_updating |
| مشتريات / Purchases | 11 | purchases_confirm_delete, purchases_error_saving |
| تقارير / Reports | 9 | reports_gross_profit, reports_export_cogs |
| مبيعات / Sales | 10 | sales_confirm_full_delete, sales_product_not_found |
| ماسح / Scanner | 33 | scanner_barcode_not_recognized, scanner_title |
| إعدادات / Settings | 7 | settings_db_connected, settings_connection_error |
| تحويل / Transfer | 7 | transfer_available_stock, transfer_error |
| مستخدمين / Users | 32 | users_active_users, users_password_min_length |
| واتساب / WhatsApp | 29 | whatsapp_connected_successfully, whatsapp_send_success |

#### النتيجة
- **قبل**: 454 مفتاح في كل ملف لغة
- **بعد**: 797 مفتاح في كل ملف لغة (+343 مفتاح جديد)
- **المجموع**: 1029 ترجمة جديدة عبر الملفات الثلاثة
- **مفاتيح مفقودة متبقية**: 0

---

## [4.20.0] - 2026-05-16

### ميزة جديدة — إعدادات الاتصال بقاعدة البيانات من واجهة الويب

هذا الإصدار يضيف صفحة جديدة تتيح للمديرين تغيير معطيات الاتصال بقاعدة البيانات (المضيف، اسم القاعدة، المستخدم، كلمة المرور) مباشرة من واجهة الويب بدون الحاجة لتعديل ملف `.env` يدوياً. يتضمن هذا الإصدار عدداً من الميزات الأمنية والعملية لضمان سلامة العمليات.

#### المشكلة
كان تغيير إعدادات الاتصال بقاعدة البيانات يتطلب تعديل ملف `.env` يدوياً عبر محرر نصوص أو استخدام سكربت CLI (`encrypt-env-helper.php`)، مما يمثل صعوبة للمستخدمين غير التقنيين ويحمل مخاطر أخطاء إدخال قد تؤدي لفقدان الاتصال بالنظام بالكامل.

#### الميزات الجديدة

**1. صفحة إعدادات قاعدة البيانات — `settings/db-settings.php`**
- نموذج ويب لتغيير المضيف (DB_HOST) واسم القاعدة (DB_NAME) والمستخدم (DB_USER) وكلمة المرور (DB_PASS)
- واجهة عربية كاملة مع أيقونات وتنسيق متوافق مع بقية صفحات الإعدادات
- حقل كلمة المرور مع زر إظهار/إخفاء وقناع نجوم افتراضي (لا تُعرض كلمة المرور الحالية)
- عرض حالة الاتصال الحالية مع مؤشر مرئي (أخضر = متصل، أحمر = خطأ)
- عرض معلومات عن قاعدة البيانات الحالية (عدد الجداول، المنتجات، المضيف، اسم القاعدة، المستخدم)

**2. اختبار الاتصال قبل الحفظ**
- زر "اختبار الاتصال" يجرب الاتصال بالمعطيات الجديدة قبل الحفظ
- عند حفظ الإعدادات، يتم اختبار الاتصال تلقائياً أولاً — إذا فشل، لا تُحفظ التغييرات
- يعرض معلومات الاتصال عند النجاح (إصدار MySQL، اسم القاعدة، عدد الجداول)

**3. تشفير تلقائي لكلمة المرور**
- كلمة المرور الجديدة تُشفَّر تلقائياً بخوارزمية AES-256-GCM عبر `EnvEncryptor`
- القيم المشفرة تبدأ بالبادئة `ENC:` في ملف `.env`
- إذا فشل التشفير (مثلاً مفتاح غير متوفر)، تُحفظ كلمة المرور بدون تشفير مع تحذير

**4. نسخ احتياطي تلقائي لملف .env**
- قبل أي تعديل، يتم إنشاء نسخة احتياطية من ملف `.env` باللاحقة `.backup.Y-m-d_His`
- الكتابة تتم بطريقة آمنة (atomic write): ملف مؤقت ثم إعادة تسمية
- هذا يضمن عدم تلف ملف `.env` حتى لو انقطعت العملية في المنتصف

**5. روابط التنقل**
- رابط "قاعدة البيانات" في قائمة "المزيد" بشريط التنقل (يتطلب صلاحية `settings.manage`)
- زر سريع "إعدادات قاعدة البيانات" في صفحة إعدادات النظام (`system-settings.php`)
- زر "العودة إلى إعدادات النظام" في صفحة إعدادات قاعدة البيانات

**6. معلومات وملاحظات تعليمية**
- شرح كيفية عمل النظام (قراءة من .env، تشفير تلقائي، اختبار قبل الحفظ)
- تنبيهات مهمة (تسجيل خروج مطلوب بعد التغيير، صلاحيات المستخدم، تجنب التغيير أثناء العمليات النشطة)
- عرض مسار ملف `.env` الفعلي على الخادم

---

## [4.19.2] - 2026-05-16

### إصلاح — خطأ PDOException: no such column c.color ونتائج بحث فارغة في الاختبارات

هذا الإصدار يُصلح مشكلتين نتجتا عن الإصدار v4.19.0:

#### المشكلة الأولى: PDOException — no such column c.color
`SearchService::searchProducts()` كان يستخدم `c.color as category_color` في عبارة SELECT بشكل ثابت. عندما يكون عمود `color` غير موجود في جدول `categories`، يفشل الاستعلام بالكامل:

```
PDOException: SQLSTATE[HY000]: General error: 1 no such column: c.color
```

هذا أثر على اختبارين:
- `testSearchByName` — يبحث عن منتج بالاسم
- `testSearchByCode` — يبحث عن منتج بالكود

#### المشكلة الثانية: نتائج بحث فارغة (got 0)
عندما تفشل استعلامات البحث بسبب `c.color`، يلتقط `SearchService` الاستثناء لكنه يُرجع نتائج فارغة (`products: [], total: 0`) عندما لا يكون هناك مصطلح بحث. هذا أثر على:
- `testSearchByStockStatusOut` — تصفية المنتجات المنفدة (Expected 1, got 0)
- `testSearchPagination` — ترقيم صفحات المنتجات (Expected 2, got 0)

#### الإصلاحات

**1. كشف ديناميكي لعمود categories.color — SearchService**
- إضافة `hasCategoryColorColumn()` — يكشف ديناميكياً ما إذا كان عمود `color` موجوداً في جدول `categories` (يدعم MySQL و SQLite)
- إضافة `buildCategorySelect()` — يبني أعمدة SELECT للتصنيفات بشكل ديناميكي: يتضمن `c.color` فقط إذا كان موجوداً
- تحديث `searchProducts()` — يستخدم `buildCategorySelect()` كقيمة افتراضية بدلاً من أعمدة ثابتة
- تحديث `resetCache()` — يُعيد تعيين كشف `categoryColorAvailable` أيضاً

**2. ProductService::search() — استخدام الأعمدة الديناميكية**
- استبدال الأعمدة الثابتة `c.color as category_color` بـ `SearchService::buildCategorySelect()`

**3. اختبارات — إضافة عمود color لجدول categories**
- تحديث `ProductServiceTest::setUp()` — إضافة `color TEXT DEFAULT NULL` لجدول `categories` في SQLite ليتطابق مع قاعدة البيانات الحقيقية

---

## [4.19.1] - 2026-05-16

### إصلاح — خطأ Class "SearchService" not found في 10 اختبارات

هذا الإصدار يُصلح خطأً نتج عن الإصدار v4.19.0: مشغّل الاختبارات (`tests/run.php`) لم يكن يُحمّل صنف `SearchService` الذي أصبح مطلوباً من قِبل اختبارات البحث والبحث المتقدم في `ProductService`.

#### المشكلة
بعد إضافة `SearchService` في v4.19.0، كان `ProductService::search()` يُفوّض البحث إلى `SearchService::searchProducts()`، كما أُضيفت 6 اختبارات جديدة تستدعي `SearchService` مباشرة. لكن مشغّل الاختبارات `tests/run.php` كان يُحمّل فقط `ProductService` و `SaleService` و `PurchaseService` بدون `SearchService`، مما أدى إلى فشل 10 اختبارات بالخطأ:

```
Class "SearchService" not found
```

الاختبارات المتأثرة:
1. `testSearchByName` — يستدعي `ProductService::search()` الذي يُفوّض إلى `SearchService`
2. `testSearchByCode` — نفس السبب
3. `testSearchByStockStatusOut` — نفس السبب
4. `testSearchPagination` — نفس السبب
5. `testSearchServiceBuildSearchWhere` — يستدعي `SearchService::buildSearchWhere()` مباشرة
6. `testSearchServiceShortTermFallback` — يستدعي `SearchService::buildSearchWhere()` مباشرة
7. `testSearchServiceRelevanceOrderBy` — يستدعي `SearchService::buildRelevanceOrderBy()` مباشرة
8. `testSearchServiceWithBarcode` — يستدعي `SearchService::buildSearchWhere()` مباشرة
9. `testSearchServiceEmptyTerm` — يستدعي `SearchService::buildSearchWhere()` مباشرة
10. `testSearchServiceResetCache` — يستدعي `SearchService::resetCache()` مباشرة

#### الإصلاح
- **tests/run.php**: إضافة تحميل `SearchService.php` (مع فحص `file_exists` للتوافق مع الإصدارات السابقة) بعد تحميل باقي خدمات التطبيق

---

## [4.19.0] - 2026-05-16

### ميزة جديدة — تحسين استعلامات البحث وفهرسة قاعدة البيانات

هذا الإصدار يُحدث تحسيناً جذرياً في أداء البحث عن المنتجات من خلال إضافة فهارس FULLTEXT على جدول المنتجات، واستبدال استعلامات `LIKE '%term%'` (مسح كامل للجدول) بـ `MATCH...AGAINST` (بحث مفهرس)، وتوحيد جميع استعلامات البحث في خدمة مركزية `SearchService`.

#### المشكلة
كانت جميع عمليات البحث في التطبيق (10+ موقع) تستخدم `LIKE '%term%'` الذي يتطلب مسحاً كاملاً لجدول المنتجات عند كل بحث. هذا يعني:
- كل بحث يمسح **كل صفوف** جدول المنتجات بلا استفادة من أي فهرس
- الاستعلامات المنفصلة للعد والبيانات تضيف حمل إضافي على قاعدة البيانات
- لا يوجد ترتيب للنتائج حسب الصلة (Relevance)
- كود البحث مكرر في 10+ ملفات بنسخ مختلفة

#### الإصلاحات

**1. فهرس FULLTEXT على جدول المنتجات**
- إضافة `CREATE FULLTEXT INDEX idx_ft_products_search ON products(name, code)` في تحديث قاعدة البيانات
- إذا وُجد عمود `barcode`، يُعاد إنشاء الفهرس ليشمله: `products(name, code, barcode)`
- إضافة فهارس B-tree إضافية:
  - `idx_products_barcode` على `barcode` (للبحث الدقيق ومسح الباركود)
  - `idx_products_category` على `category_id` (للتصفية حسب التصنيف)
  - `idx_products_expiry` على `has_expiry` (لتقارير الانتهاء)
  - `idx_products_stock_status` على `(quantity, min_quantity)` (لتصفية حالة المخزون)

**2. خدمة البحث المركزية — `SearchService`**
- صنف جديد `includes/Services/SearchService.php` يوحّد كل منطق البحث
- `MATCH(name, code, barcode) AGAINST('*term*' IN BOOLEAN MODE)` عندما يكون فهرس FULLTEXT متاحاً
- تراجع تلقائي إلى `LIKE '%term%'` في الحالات التالية:
  - قاعدة بيانات SQLite (اختبارات)
  - مصطلح بحث أقل من 3 أحرف (أقل من الحد الأدنى لـ FULLTEXT)
  - فهرس FULLTEXT غير موجود بعد
- ترتيب النتائج بالصلة: الأكواد المطابقة أولاً، ثم الأسماء، ثم الباركود
- توحيد استعلام العد باستخدام `COUNT(*) OVER()` (MySQL 8.0+) أو استعلام منفصل (MySQL 5.7)

**3. تحديث جميع مواقع البحث (10+ ملف)**
- `ProductService::search()` — تفويض كامل إلى `SearchService`
- `api/search_products.php` — استخدام `SearchService`
- `includes/Controllers/ProductApiController.php` — استخدام `SearchService`
- `units/api/search_products.php` — استخدام `SearchService`
- `products/list-products.php` — استخدام `SearchService`
- `products/price-history.php` — استخدام `SearchService::buildSearchWhere()`
- `products/expiry-report.php` — استخدام `SearchService::buildSearchWhere()`
- `reports/sales-report.php` — استخدام `SearchService::buildSearchWhere()`
- `reports/purchases-report.php` — استخدام `SearchService::buildSearchWhere()`
- `units/products/list-products.php` — استخدام `SearchService`

**4. اختبارات جديدة — 6 اختبارات لـ SearchService**
- `testSearchServiceBuildSearchWhere` — يفحص بناء شرط WHERE
- `testSearchServiceShortTermFallback` — يفحص تراجع المصطلحات القصيرة إلى LIKE
- `testSearchServiceRelevanceOrderBy` — يفحص ترتيب النتائج بالصلة
- `testSearchServiceWithBarcode` — يفحص تضمين الباركود
- `testSearchServiceEmptyTerm` — يفحص مصطلح البحث الفارغ
- `testSearchServiceResetCache` — يفحص إعادة تعيين الكشف المخزن

**5. Elasticsearch/Meilisearch — توصية مؤجلة**
- محركات البحث الخارجية (Elasticsearch، Meilisearch) توفّر بحثاً أقوى (تصحيح الأخطاء الإملائية، المرادفات، البحث الضبابي)
- لكنها تتطلب بنية تحتية إضافية (خادم منفصل، مزامنة البيانات)
- التوصية: اعتمادها عند تجاوز 50,000 منتج أو عند الحاجة لميزات بحث متقدمة
- `SearchService` مُصمَّم كطبقة تجريد تسمح بإضافة محرك بحث خارجي مستقبلاً بدون تغيير الاستدعاءات

---

## [4.18.4] - 2026-05-16

### إصلاح حرج — النظام يقبل سعر بيع أقل من سعر الشراء بدون تحذير

هذا الإصدار يُصلح خللاً خطيراً في التحقق من صحة بيانات المنتج: كان النظام يسمح بتعيين سعر بيع أقل من سعر الشراء (مثلاً: سعر الشراء = 2222.00 وسعر البيع = 18.00) دون أي تحذير أو منع، مما قد يؤدي إلى خسائر مالية بسبب أخطاء إدخال البيانات.

#### المشكلة
عند إضافة أو تعديل منتج، كان التحقق من الأسعار يفحص فقط أن سعر الشراء > 0 وسعر البيع > 0، لكنه لا يتحقق من أن سعر البيع ليس أقل من سعر الشراء. هذا يعني أن خطأ إدخال بيانات (مثل عكس الأسعار) يمر بدون أي تنبيه.

#### الإصلاحات

**1. ProductService::validateProductData() — إضافة تحقق سعر البيع >= سعر الشراء**
- إضافة فحص جديد بعد التحقق من أن كلا السعرين > 0
- إذا كان سعر البيع أقل من سعر الشراء، يُضاف خطأ: `سعر البيع (XX.XX) لا يمكن أن يكون أقل من سعر الشراء (YY.YY)`
- يُسمح بأن يكون سعر البيع مساوياً لسعر الشراء (بدون ربح = حالة نادرة لكن مشروعة)
- هذا الإصلاح يُطبَّق تلقائياً على كل من `ProductService::create()` و `ProductService::update()`

**2. صفحات المنتجات المباشرة — إضافة نفس التحقق**
- `products/add-product.php` — إضافة تحقق سعر البيع >= سعر الشراء
- `products/edit-product.php` — إضافة تحقق سعر البيع >= سعر الشراء
- `units/products/add-product.php` — إضافة تحقق سعر البيع >= سعر الشراء
- `units/products/edit-product.php` — إضافة تحقق سعر البيع >= سعر الشراء

**3. اختبارات جديدة — 6 اختبارات للتحقق من التسعير**
- `testValidateProductDataSellingBelowPurchase` — يفحص أن سعر البيع < سعر الشراء يُرجع خطأ
- `testValidateProductDataSellingEqualPurchaseAllowed` — يفحص أن سعر البيع = سعر الشراء مسموح
- `testValidateProductDataSellingAbovePurchaseAllowed` — يفحص أن سعر البيع > سعر الشراء مسموح
- `testCreateProductSellingBelowPurchaseFails` — يفحص أن الإنشاء بسعر بيع < سعر شراء يرمي AppException
- `testUpdateProductSellingBelowPurchaseFails` — يفحص أن التحديث بسعر بيع < سعر شراء يرمي AppException

---

## [4.18.3] - 2026-05-16

### إصلاح حرج — خطأ ErrorHandler::$logDir يؤدي لفشل 75 اختبار

هذا الإصدار يُصلح خطأً حرجاً في صنف `ErrorHandler` كان يؤدي إلى فشل 75 من 92 اختبار في مجموعات ProductServiceTest و SaleServiceTest و PurchaseServiceTest. كما يُضيف تهيئة صحيحة لمعالج الأخطاء في مشغّل الاختبارات.

#### المشكلة
عند تشغيل الاختبارات، كان الخطأ التالي يظهر في 75 اختباراً:
```
Error: Typed static property ErrorHandler::$logDir must not be accessed before initialization
```

السبب: الخاصية `$logDir` مُعرّفة كـ `private static string $logDir;` بدون قيمة افتراضية، ومشغّل الاختبارات (`tests/run.php`) يُضمّن `ErrorHandler.php` لكنه لا يستدعي `ErrorHandler::init()`، مما يعني أن `$logDir` لا تُعيَّن أبداً قبل استخدامها في `log()`.

#### الإصلاحات

**1. ErrorHandler.php — تهيئة تلقائية وقيمة افتراضية**
- إضافة قيمة افتراضية `$logDir = ''` بدلاً من تركها بدون تهيئة
- إضافة تهيئة تلقائية (lazy-init) في دالة `log()`: إذا كانت `$logDir` فارغة، يتم تعيينها تلقائياً إلى `__DIR__ . '/../logs'` مع إنشاء المجلد إن لزم
- هذا يضمن عمل معالج الأخطاء حتى لو نُسي استدعاء `init()` (مثلاً في بيئة الاختبارات أو السكربتات المستقلة)

**2. tests/run.php — إضافة ErrorHandler::init()**
- إضافة `ErrorHandler::init();` مباشرة بعد `require_once ErrorHandler.php`
- هذا يُسجّل معالجات الأخطاء والاستثناءات بشكل صحيح أثناء الاختبارات
- يضمن تسجيل أي أخطاء تحدث أثناء تشغيل الاختبارات في ملفات السجلات

**3. اختبارات الخدمات — تحديث 21 اختبار لاستخدام assertThrows(AppException)**
- منذ v4.16.9، الخدمات (ProductService, SaleService, PurchaseService) ترمي `AppException` بدلاً من إرجاع `['success' => false]`
- لكن الاختبارات كانت لا تزال تستخدم النمط القديم `$this->assertFalse($result['success'])`
- تم تحديث 21 اختبار فشل متوقع لاستخدام `$this->assertThrows(AppException::class, function() { ... })`
- ProductServiceTest: 10 اختبارات (validationFailure, updateNonExistent, deleteWithSales, deleteWithPurchases, deleteNonExistent, transferStock*4, inventoryCountEmpty)
- SaleServiceTest: 7 اختبارات (insufficientStock, nonExistentProduct, zeroQuantity, zeroSellingPrice, duplicateProduct, emptyItems, deleteNonExistent)
- PurchaseServiceTest: 4 اختبارات (nonExistentProduct, consumedBatches, updateNonExistent, deleteNonExistent)

---

## [4.16.9] - 2026-05-16

### توحيد معالجة الأخطاء — نظام استثناءات موحد ومركزي

هذا التحديث يُعيد هيكلة نظام معالجة الأخطاء بالكامل ليتبع نمطاً موحداً: جميع الخدمات ترمي استثناءات `AppException` بدلاً من إرجاع مصفوفات خطأ، مع معالجة مركزية تحوّلها تلقائياً إلى `ApiResponse`. كما تم استبدال **105+** كتلة `catch` فارغة بتسجيل مناسب في ملفات السجلات.

#### 1. الخدمات ترمي AppException بدلاً من إرجاع مصفوفات خطأ
- **SaleService**: `create()` و `delete()` ترميان `AppException::validation()` / `AppException::notFound()` / `AppException::database()` بدلاً من إرجاع `['success' => false, 'message' => ...]`
- **PurchaseService**: نفس النمط — رمي `AppException` لكل أنواع الأخطاء (تحقق، عدم وجود، قاعدة بيانات)
- **ProductService**: نفس النمط — بما فيها `transferStock()` و `inventoryCount()` و `delete()`
- **الميزة**: الآن يمكن للمنادي (Controllers/API) التقاط استثناءات موحدة بدلاً من فحص `if (!$result['success'])`، مع رموز HTTP صحيحة (422, 404, 500) تلقائياً

#### 2. معالجة استثناءات مركزية مع تحويل تلقائي إلى ApiResponse
- **`ErrorHandler::handleApiException()`**: دالة جديدة تحوّل `AppException` تلقائياً إلى `ApiResponse::error()` — تستخدم من `ApiRouter` و Controllers
- **توحيد تنسيق JSON**: استجابة الخطأ في `ErrorHandler::jsonResponse()` أصبحت بنفس هيكل `ApiResponse` — `{success: false, error: {type, message, details}}` — بدلاً من الهيكل القديم الذي كان يحتوي حقول `products` و `data` و `message` إضافية
- **`ApiRouter`**: يستخدم الآن `ErrorHandler::handleApiException()` و `ErrorHandler::logException()` بدلاً من كود معالجة مكرر
- **`ApiResponse::custom()`**: طريقة جديدة للتوافق مع الأنماط القديمة التي تحتاج بنية حرة

#### 3. استبدال 105+ كتلة catch فارغة بتسجيل مناسب
كل كتلة `catch (Exception $e) {}` أو `catch (Throwable $e) {}` فارغة تم استبدالها بـ `ErrorHandler::logException($e, 'Context');` عبر **29 ملف**:

| المجموعة | الملفات | عدد كتل catch المُصلحة |
|---|---|---|
| المنتجات | list-products, inventory-count, print-labels, price-list, edit-product, add-product, view-product, expiry-report | 36 |
| الإعدادات والـ cron | config/backup, cron/whatsapp-daily | 8 |
| الأنظمة الأساسية | includes/whatsapp_alert, includes/header, includes/db-schema, includes/notifications, includes/auth, includes/Controllers/ProductApiController | 29 |
| API | price-check-data, search_products, export, pos-process-sale | 10 |
| الترقيات | upgrade-helpers, upgrade-db, upgrade-rollback | 16 |
| صفحات أخرى | admin/audit-log, expenses/add-expense, settings/system-settings, backups/list-backups, sales/edit-sale, purchases/edit-purchase | 6 |

**المجموع**: 105+ كتلة catch فارغة ← أصبحت تسجّل الأخطاء في `logs/error-YYYY-MM-DD.log`

#### 4. إهمال `setAppErrorHandler()`
- الدالة `setAppErrorHandler()` في `includes/logger.php` أصبحت **مهملة** — ستُصدر تحذير `E_USER_DEPRECATED` عند استدعائها
- السبب: كانت تُسجّل معالج استثناءات مكرر يتعارض مع `ErrorHandler::init()` المركزي
- الآن `ErrorHandler::init()` فقط هو المسؤول عن معالجة الأخطاء والاستثناءات (يُستدعى من bootstrap.php)

#### 5. أنواع إرجاع (Return Types)
- إضافة أنواع إرجاع لجميع الدوال في `SaleService` و `PurchaseService` و `ProductService`

---

## [4.16.4] - 2026-05-15

### تصحيح أمني — منع تسريب رسائل الاستثناء الداخلية

تم إصلاح **26 نقطة تسريب** لرسائل الاستثناء الداخلية (مثل أخطاء SQL، مسارات الملفات، تفاصيل الخادم) التي كانت تُعرض للمستخدمين في بيئة الإنتاج. هذه المعلومات تُمكّن المهاجمين من استغلال الثغرات بشكل أسهل.

#### نظام مركزي جديد
- **`ErrorHandler::safeExceptionMessage()`**: دالة مركزية جديدة تُعيد رسالة آمنة للمستخدم في الإنتاج، ورسالة مفصّلة في وضع التطوير. رسائل `AppException` تُعتبر آمنة دائماً لأنها مُصمّمة للمستخدم.
- **`ErrorHandler::logException()`**: دالة مركزية جديدة لتسجيل الاستثناءات بشكل موحد في ملف السجلات و`error_log` مع السياق الكامل.
- **تحسين `jsonResponse()`**: منع تسريب **جميع** أنواع الاستثناءات في الإنتاج (كان يخفي فقط `PDOException` و `RuntimeException`، الآن يخفي الكل ما عدا `AppException`).
- **تحسين `showErrorPage()`**: منع تسريب **جميع** أنواع الاستثناءات في صفحة خطأ HTML (كان يخفي فقط نوع `database`).

#### الملفات المُصلحة — التطبيق الرئيسي
| الملف | التغيير |
|---|---|
| `includes/Services/SaleService.php` | إخفاء `$e->getMessage()` في `create()` و `delete()` |
| `products/inventory-count.php` | إخفاء رسائل الاستثناء في استجابة AJAX |
| `products/list-products.php` | إخفاء رسائل الاستثناء في استجابة AJAX |
| `api/pos-process-sale.php` | إخفاء رسائل الاستثناء في استجابة JSON |
| `backups/list-backups.php` | إخفاء رسائل الاستثناء في 5 مواقع (إنشاء، حذف، استعادة، رفع، تحميل) |
| `config/backup.php` | إخفاء رسائل الاستثناء في صفيف الأخطاء |
| `cron/auto-backup.php` | إخفاء رسائل الاستثناء في استجابة الويب (CLI يحتفظ بالتفاصيل) |
| `deployer.php` | إخفاء رسائل الاستثناء في 2 موقع |
| `uninstall.php` | إخفاء رسائل الاستثناء في رسالة HTML |
| `upgrade.php` | إخفاء رسائل الاستثناء في 7 مواقع |
| `includes/upgrades/upgrade-deploy.php` | إخفاء رسائل الاستثناء |
| `includes/upgrades/upgrade-rollback.php` | إخفاء رسائل الاستثناء في 3 مواقع |
| `includes/upgrades/upgrade-fixes.php` | إخفاء رسائل الاستثناء |
| `includes/upgrades/upgrade-helpers.php` | إخفاء رسائل الاستثناء |

#### الملفات المُصلحة — تطبيق الوحدات الفرعية (units/)
16 ملف في مجلد `units/` تم إصلاحها بنفس النمط: `install.php`, `settings/system-settings.php`, `expenses/add-expense.php`, `purchases/add-purchase.php`, `units/list-units.php`, `sales/add-sale.php`, `list-units.php`, `users/add-user.php`, `users/edit-user.php`, `categories/list-categories.php`, `products/add-product.php`, `products/edit-product.php`, `uninstall.php`, `upgrade.php`, `backups/list-backups.php`, `config/backup.php`.

### كيفية عمل النظام الجديد
- **الإنتاج** (`APP_DEBUG=false`): يرى المستخدم رسالة عامة مثل "حدث خطأ أثناء المعالجة" بينما تُسجّل التفاصيل الكاملة في ملفات السجلات
- **التطوير** (`APP_DEBUG=true`): يرى المطور رسالة الاستثناء الأصلية للمساعدة في التشخيص
- **AppException**: رسائله آمنة دائماً (مثل "المنتج غير موجود") وتُعرض للمستخدم في كل الأوضاع

---

## [4.16.3] - 2026-05-15

### تصحيح أمني
- **إعادة تشفير كلمة مرور قاعدة البيانات**: تصحيح قيمة `DB_PASS` المشفرة في ملف `.env` — كان التشفير السابق يحتوي على كلمة المرور الجديدة القوية (`r3(C!N@ikAvqdjqyK=hP`) التي لم تُطبّق بعد في MySQL، مما كان سيؤدي إلى فشل الاتصال بقاعدة البيانات. تم إعادة التشفير بكلمة المرور الحالية (`938685`) لضمان عمل التطبيق بدون تغيير كلمة مرور MySQL.
- **إضافة تنبيه أمني**: تحديث تعليقات ملف `.env` مع تحذير واضح بأن كلمة المرور الحالية ضعيفة وتعليمات خطوة بخطوة لتغييرها مستقبلاً (تغيير في MySQL + إعادة التشفير عبر سكربت المساعد).

### ملاحظة مهمة
- كلمة مرور قاعدة البيانات **لا تزال ضعيفة** (`938685`) — التشفير يحميها من القراءة المباشرة في ملف `.env` لكنها تحتاج إلى تغيير في أقرب فرصة
- لتغيير كلمة المرور لاحقاً: شغّل `php scripts/encrypt-env-helper.php` ثم نفّذ `ALTER USER` في MySQL

---

## [4.11.0] - 2026-05-12

### ميزة جديدة — تطبيق "أسعاري" PWA مستقل
- **صفحة البحث عن الأسعار (price-check.php)**: تطبيق ويب تقدمي (PWA) مستقل للبحث عن أسعار المنتجات يعمل بدون اتصال بالإنترنت — يمكن الوصول إليه مباشرة بدون تسجيل دخول
- **دعم مسح الباركود**: التعرف التلقائي على الباركود عبر كاميرا الهاتف باستخدام مكتبة html5-qrcode — مسح واحد يكفي للعثور على المنتج فوراً
- **قابل للتثبيت على الهاتف**: يظهر كتطبيق مستقل على الشاشة الرئيسية — شاشة كاملة بدون شريط عنوان المتصفح + أيقونة مخصصة بجميع الأحجام
- **تحديث تلقائي عند الاتصال**: Service Worker يخزّن الصفحة والبيانات محلياً — عند استعادة الاتصال يتم تحديث البيانات تلقائياً من الخادم
- **IndexedDB**: تخزين آلاف المنتجات محلياً في قاعدة بيانات المتصفح — البيانات تبقى حتى بعد إغلاق المتصفح وإعادة تشغيل الهاتف

### ميزة جديدة — API بيانات المنتجات
- **api/price-check-data.php**: نقطة نهاية API جديدة تُرجع بيانات المنتجات بصيغة JSON — تشمل الأسماء والأسعار والوحدات الإضافية والتصنيفات
- **دعم ETag**: تقليل استهلاك البيانات — إذا لم تتغير المنتجات يُرجع HTTP 304 بدون إعادة إرسال كل البيانات
- **واجهة عامة**: لا تتطلب مصادقة — بيانات الأسعار متاحة للعموم (أسماء + أسعار فقط، لا معلومات حساسة)

### تحسينات واجهة المستخدم
- **بحث متعدد الكلمات**: اكتب أكثر من كلمة للبحث المتقدم (مثلاً "حليب طازج")
- **تصفية حسب التصنيف**: أزرار تصفية سريعة أعلى قائمة النتائج
- **حالة المخزون**: عرض ملون (متوفر / منخفض / نفذ) لكل منتج
- **الوحدات الإضافية**: عرض وحدات البيع البديلة وأسعارها مباشرة في بطاقة المنتج
- **اختصار مسح الباركود**: إجراء سريع على أيقونة التطبيق في شاشة الهاتف الرئيسية

### بنية تحتية
- **price-check-sw.js**: Service Worker مخصص — استراتيجية Network First لبيانات API + Cache First للملفات الثابتة
- **price-check-manifest.json**: ملف تعريف PWA كامل مع أيقونات واختصارات ودعم RTL عربي
- **أيقونات PWA**: 8 أحجام PNG (72–512 بكسل) + SVG للشاشات عالية الدقة

---

## [4.10.2] - 2026-05-12

### إصلاح حرج
- **إضافة دالة `db_upgrade_4_10_1` المفقودة**: الدالة لم تكن معرّفة في ملف `upgrade-db.php`، مما كان يوقف الترقية بالكامل عند الوصول للإصدار v4.10.1 مع فشل التراجع التلقائي أيضاً. الخطأ: `دالة غير موجودة: db_upgrade_4_10_1`
- **تحسين مرونة نظام الترقية**: بدلاً من إيقاف الترقية بالكامل عند عدم وجود دالة `db_upgrade_*`، يقوم النظام الآن بتسجيل تحذير وتخطي الإصدار مع تحديث رقم النسخة. هذا يمنع فشل الترقية والتراجع التلقائي في المستقبل إذا نُسي إضافة دالة فارغة لأي إصدار جديد.

### ملاحظة مهمة للترقية
- إذا كانت قاعدة بياناتك عالقة في إصدار أقدم من v4.10.1 بسبب هذا الخطأ، ما عليك سوى تشغيل صفحة الترقية مرة أخرى بعد استبدال الملفات الثلاثة المعدّلة — ستعمل الترقية الآن بنجاح

---

## [4.10.1] - 2026-05-12

### إصلاح حرج
- **إصلاح خطأ "خطأ في الاتصال بالخادم"**: سياسة CSP الصارمة كانت تحظر كل السكربتات المضمّنة (inline scripts) بدون سمة nonce، مما يمنع `window.BASE_URL` من التعيين ويفسد كل استدعاءات AJAX في التطبيق (الإشعارات، البحث، نقطة البيع، التقارير)
- **إضافة nonce لـ 48 سكربت مضمّن في 44 ملف**: كل علامات `<script>` المضمّنة الآن تحمل السمة `nonce="..."` الديناميكية التي تسمح لها بالعمل مع سياسة CSP

### تحسين أمان
- **وضع CSP Report-Only**: تبديل سياسة أمان المحتوى إلى وضع "التقرير فقط" افتراضياً — يراقب المخالفات بدون حظرها، يمكن التبديل لوضع التنفيذ القسري عبر `CSP_ENFORCE=true` في ملف .env
- **متغير CSP_ENFORCE**: تحكم جديد في ملف .env لتبديل وضع CSP — `false` (افتراضي) = تقرير فقط، `true` = تنفيذ قسري
- **متغير APP_DEBUG**: تحكم جديد في ملف .env لعرض/إخفاء أخطاء التطوير — `false` (افتراضي) = إخفاء الأخطاء، `true` = عرض تفصيلي
- **unsafe-inline مؤقت**: في وضع Report-Only، يُضاف `unsafe-inline` مؤقتاً لضمان عمل معالجات onclick أثناء الانتقال التدريجي إلى نمط addEventListener

### تحسين بنية
- **تحسين bootstrap.php**: قراءة APP_DEBUG من متغيرات البيئة + تحميل RedisCache تلقائياً عند توفره + تحميل طبقة الخدمات (Services) تلقائياً
- **SecurityHeaders::getMode()**: دالة جديدة لمعرفة وضع CSP الحالي (enforce أو report-only) — مفيدة لواجهة الإدارة

---

## [4.10.0] - 2026-05-12

### ميزة جديدة — طبقة الخدمات (Service Layer)
- **ProductService**: تجميع كل المنطق التجاري للمنتجات — إضافة، تحديث، حذف، بحث مع ترقيم صفحات، تحويل مخزون، جرد، تسجيل تغيير الأسعار، توليد أكواد تلقائية
- **SaleService**: تجميع المنطق التجاري للمبيعات — تسجيل مبيعات مع خصم FIFO/FEFO من الدفعات، حذف مع إعادة الكميات، حساب الأرباح الصافية (مبيعات - تكلفة - مصاريف - ضرائب)
- **PurchaseService**: تجميع المنطق التجاري للمشتريات — تسجيل مشتريات مع دفعات وضرائب وتخفيضات، تحديث كميات وأسعار، حذف مع إعادة تعديل المخزون
- **ServiceFactory**: نقطة وصول موحدة لجميع خدمات التطبيق مع دعم تسجيل خدمات مخصصة واختبار سهل

### ميزة جديدة — نظام التخزين المؤقت المتقدم
- **CacheInterface**: عقد موحد يسمح بالتبديل بين محولات التخزين المؤقت (ملفات، Redis، Memcached) بدون تغيير الكود
- **RedisCache**: محول تخزين مؤقت مبني على Redis مع تراجع تلقائي إلى التخزين بالملفات عند عدم توفر Redis — دعم TTL، بادئات المفاتيح، إحصائيات مدمجة، ومعالجة ذكية لأخطاء الاتصال
- **جلسات Redis**: تحسين أداء الجلسات باستخدام Redis بدلاً من الملفات عند توفره — تكوين تلقائي من إعدادات .env

### ميزة جديدة — نظام التوجيه المركزي (Router)
- **Router**: نظام توجيه موحد يحدد المسارات في مكان واحد بدلاً من ملفات PHP المتفرقة — دعم GET/POST/PUT/DELETE، مجموعات مسارات مع بادئات ووسطاء مشتركين، معاملات ديناميكية (`{id}`, `{id:\d+}`)
- **AuthMiddleware**: وسيط المصادقة — التحقق من تسجيل الدخول، صلاحية المدير، صلاحيات محددة — يدعم طلبات AJAX و HTML
- **CsrfMiddleware**: وسيط حماية CSRF — التحقق التلقائي من رمز CSRF في طلبات POST — يدعم Header و POST token
- **routes.php**: ملف تعريفات المسارات — اختياري، يمكن تفعيله تدريجياً بنقل مسار واحد في كل مرة

---

## [4.9.112] - 2026-05-12

### أمان
- **تقوية CSP**: نظام CSP ديناميكي مبني على Nonce بدلاً من `unsafe-inline` و `unsafe-eval` — كل طلب يولّد nonce فريد يُحقن في سكربتات HTML، مما يمنع تنفيذ أي سكربت غير مصرح به (حماية قوية من XSS)
- **إضافة HSTS**: رأس `Strict-Transport-Security` بمدة سنة مع includeSubDomains و preload — يجبر المتصفح على استخدام HTTPS دائماً
- **SecurityHeaders.php**: فئة جديدة تولّد رؤوس الأمان ديناميكياً بما في ذلك CSP مع nonce وتتولى إرسالها في كل طلب
- **تشفير .env**: نظام `EnvEncryptor` لتشفير القيم الحساسة في ملف .env (كلمات المرور، الأسرار) باستخدام `APP_ENCRYPTION_KEY` — القيم المشفرة تبدأ بالبادئة `ENC:`
- **كشف بيانات اعتماد ضعيفة**: تحذيرات عند استخدام كلمات مرور افتراضية أو ضعيفة في ملف .env
- **حماية مفتاح التشفير**: فحص صلاحية مفتاح التشفير عند التحميل ومنع التشغيل بدون مفتاح صالح في الإنتاج

---

## [4.9.111] - 2026-05-12

### أمان
- حماية deployer.php: فرض المصادقة دائماً — لم يعد الوصول متاحاً بدون رمز سري حتى لو لم يُعيّن DEPLOYER_SECRET
- تقييد محاولات المصادقة في deployer.php: 5 محاولات فاشلة ← حظر 15 دقيقة (تتبع عبر الجلسة)
- كشف القيم الافتراضية الضعيفة لـ DEPLOYER_SECRET — تحذير بصري أحمر عند استخدام قيم مثل `change_me_to_a_strong_secret_2026`
- إضافة إعدادات أمنية لملفات تعريف الارتباط (كوكيز الجلسة) في جميع نقاط session_start: HttpOnly + SameSite=Lax + Strict Mode + Secure (عند HTTPS)
- نقل إعدادات الكوكيز الأمنية من auth.php إلى bootstrap.php (قبل session_start) لضمان تطبيقها فعلياً
- إخفاء رسائل أخطاء PDOException في install.php — استبدال برسالة عامة + تسجيل الخطأ الحقيقي
- إخفاء رسائل أخطاء قاعدة البيانات في استجابات API للإنتاج — عرضها فقط في وضع APP_DEBUG
- إضافة نظام APP_DEBUG في bootstrap.php — تعطيل display_errors افتراضياً في الإنتاج
- إخفاء تفاصيل الاستثناءات في deployer.php — عرض رسالة عامة بدلاً من `$e->getMessage()` في الإنتاج
- إخفاء رسائل خطأ SQL في ملفات API الفرعية (units/api/)

### إصلاح
- إضافة إعدادات أمنية للجلسة في 11 ملف يبدأ الجلسة بشكل مستقل

---

## [4.9.110] - 2026-05-10

### إصلاح
- إصلاح حرج في جرس التنبيهات: الخلفية الشفافة (overlay) كانت تغطي الصفحة بالكامل وتحجب التفاعل
- إزالة overlay بالكامل — القائمة تُغلق الآن بالنقر خارجها أو زر الإغلاق أو Escape

---

## [4.9.107] - 2026-05-10

### ميزة
- الناشر الذري المستقل (deployer.php) — ملف PHP مستقل تماماً لا يعتمد على أي ملف آخر
- نشر ذري بتقنية Write-Then-Rename — كل ملف يُستبدل ذرياً
- تراجع عن النشر — استعادة الملفات القديمة تلقائياً
