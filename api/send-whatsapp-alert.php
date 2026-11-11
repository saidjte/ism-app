<?php
/**
 * API: Send WhatsApp Expiry Alert — غلاف رقيق
 * يفوّض إلى WhatsAppController عبر الموجه المركزي
 *
 * v5.21.0: تحويل إلى غلاف رقيق يفوّض إلى المتحكم المركزي
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// تفويض إلى المتحكم المركزي
$controller = new WhatsAppController();
$controller->sendAlert();