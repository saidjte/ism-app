#!/usr/bin/env bash
# ============================================================
#  ISM POS — سكربت التنظيف الأمني (المرحلة 0)
#  يُشغَّل مرة واحدة على الخادم الإنتاجي:
#      bash scripts/security-cleanup.sh
#  معاينة بدون حذف:
#      bash scripts/security-cleanup.sh --dry-run
#
#  ماذا يفعل:
#   1) يحذف سكربتات التشخيص الخطيرة غير المحمية من جذر الويب
#   2) يحذف ملفات النسخ الاحتياطية القياسية (*.old *.bkp *.backup ...)
#   3) يحذف أشجار النسخ الاحتياطي القديمة داخل webroot
#   4) يضبط صلاحيات .env على 0640
#   5) ينشئ config/installed.lock إن لم يوجد (قفل معلّم التثبيت)
#
#  كل خطوة تُطبع قبل تنفيذها. لا يحذف أي شيء خارج مجلد التطبيق.
# ============================================================
set -u

DRY_RUN=false
[ "${1:-}" = "--dry-run" ] && DRY_RUN=true

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

log()  { echo "→ $*"; }
warn() { echo "⚠  $*"; }
run_rm() {
    if [ "$DRY_RUN" = true ]; then
        echo "   [dry-run] سسيُحذف: $*"
    else
        rm -rf -- "$@" && echo "   ✓ حُذف: $*"
    fi
}

echo "=============================================="
echo " تنظيف أمني لتطبيق ISM POS — $APP_DIR"
[ "$DRY_RUN" = true ] && echo " (وضع المعاينة — لن يُحذف شيء فعلياً)"
echo "=============================================="

# ---- 1) سكربتات التشخيص (خطيرة: تسمح بتنفيذ أوامر وكتابة ملفات بدون مصادقة) ----
log "1) سكربتات التشخيص غير المحمية"
for f in diag-all-mobile.php diag-index.php diag.php diag-db.php diag-mobile.php; do
    [ -f "$f" ] && run_rm "$f"
done

# ---- 2) ملفات النسخ الاحتياطية القياسية ----
log "2) ملفات النسخ الاحتياطية (*.old *.bkp *.bak *.backup *.pld)"
# استخدام find مع -print0 لسلامة الأسماء التي تحوي فراغات
while IFS= read -r -d '' f; do
    run_rm "$f"
done < <(find . -path ./.git -prune -o -type f \( -name "*.old" -o -name "*.old.*" -o -name "*.bkp" -o -name "*.bak" -o -name "*.backup" -o -name "*.backup.*" -o -name "*.pld" \) -print0 2>/dev/null)

# ملفات لاحقات رقمية مثل manage-batches.php003 / edit-product.php0003
while IFS= read -r -d '' f; do
    run_rm "$f"
done < <(find . -path ./.git -prune -o -type f -regextype posix-extended -regex '.*\.php[0-9]{3}$' -print0 2>/dev/null)

# ---- 3) أشجار النسخ الاحتياطي داخل webroot ----
log "3) أشجار النسخ الاحتياطي داخل جذر الويب"
for d in upgrade_file_backup upgrade_backups includes/upgrades/upgrade_backups includes/upgrades/upgrade_backups_old; do
    [ -e "$d" ] && run_rm "$d"
done

# ---- 4) صلاحيات .env ----
log "4) ضبط صلاحيات .env على 0640"
if [ -f .env ]; then
    if [ "$DRY_RUN" = true ]; then
        echo "   [dry-run] chmod 0640 .env"
    else
        chmod 0640 .env && echo "   ✓ .env: $(stat -c '%a' .env)"
    fi
else
    warn ".env غير موجود — تأكد أنك في المجلد الصحيح"
fi

# ---- 5) قفل معلّم التثبيت ----
log "5) قفل معلّم التثبيت (config/installed.lock)"
if [ -f config/installed.lock ]; then
    echo "   ✓ القفل موجود مسبقاً"
else
    if [ "$DRY_RUN" = true ]; then
        echo "   [dry-run] سيُنشأ config/installed.lock"
    else
        mkdir -p config
        printf 'installed:%s\n' "$(date -Iseconds)" > config/installed.lock
        echo "   ✓ أُنشئ القفل — install.php معطّل الآن"
    fi
fi

echo ""
echo "=============================================="
if [ "$DRY_RUN" = true ]; then
    echo " انتهت المعاينة. للتطبيق الفعلي: bash scripts/security-cleanup.sh"
else
    echo " اكتمل التنظيف."
    echo ""
    echo " خطوات يدوية متبقية (حرِجة — من خطة الإصلاح):"
    echo "  1) دوّر الأسرار:  php scripts/rotate-secrets.php --dry-run ثم بدونها"
    echo "  2) نفّذ SQL إنشاء مستخدم ism_app الذي يطبعه السكربت"
    echo "  3) عيّن \"مفتاح أمان cron\" من صفحة الإعدادات (يتطلب بعد إصلاح"
    echo "     cron/whatsapp-daily.php — المفتاح اليومي القديم لم يعمل بعد الآن)"
    echo "  4) حدّث رابط الـ cron في مجدول المهام بالمفتاح الجديد"
fi
echo "=============================================="
