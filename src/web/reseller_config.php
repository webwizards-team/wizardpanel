<?php
// ==========================================
// فایل پیکربندی یکپارچه سیستم نمایندگی
// ==========================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = pdo();

$session_lifetime = 86400;

$superAdminUser = defined('RESELLER_ADMIN_USER') ? RESELLER_ADMIN_USER : '1234';
$superAdminPass = defined('RESELLER_ADMIN_PASS') ? RESELLER_ADMIN_PASS : '1234';
?>
