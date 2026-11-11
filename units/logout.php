<?php
// تسجيل الخروج
require_once __DIR__ . '/includes/auth.php';
logout();
header("Location: login.php?msg=logged_out");
exit;
?>
