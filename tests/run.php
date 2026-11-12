<?php
/**
 * ========================================================================
 *  غلاف تشغيل الاختبارات — يفوّض العمل إلى PHPUnit
 * ========================================================================
 *
 *  منذ v6.3.4 تعمل جميع الاختبارات عبر PHPUnit:
 *    vendor/bin/phpunit --testdox
 *
 *  هذا الغلاف يحافظ على عادة التشغيل القديمة:
 *    php tests/run.php [خيارات phpunit...]
 *
 *  سطر الأوامر فقط — لا يُشغَّل عبر الويب (حماية).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(__DIR__);
$phpunit = $root . '/vendor/bin/phpunit';

if (!is_file($phpunit)) {
    fwrite(STDERR, "خطأ: PHPUnit غير مثبت — شغّل أولاً:\n  composer install\n");
    exit(1);
}

$args = array_slice($argv, 1);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' -c ' . escapeshellarg($root . '/phpunit.xml.dist');
if ($args !== []) {
    $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $args));
}

passthru($cmd, $exitCode);
exit($exitCode);
