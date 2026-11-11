<?php
/**
 * API: Test UltraMsg Connection — غلاف رقيق
 * يفوّض إلى WhatsAppController عبر الموجه المركزي
 *
 * v5.21.0: تحويل إلى غلاف رقيق يفوّض إلى المتحكم المركزي
 */

// تشغيل output buffering لمنع أي تحذيرات من إفساد JSON
ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';

// تفويض إلى المتحكم المركزي
$controller = new WhatsAppController();
$controller->testUltraMsg();